// =========================================================
// Mapa de ocorrências (ligado à API)
// - GET /incidents com filtro de categoria e período (RF01, RF02)
// - POST /incidents para registrar (RF05), só com login
// - administrador: editar (PUT) e excluir (DELETE) com 5 s para desfazer (RF06, RF07)
// - Google Maps com tema claro/escuro (js/maps.js + js/theme.js)
// =========================================================

// ---------- Relógio ----------
const clockEl = document.getElementById('clock');
const timeFmt = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
const whenFmt = new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
function tick() {
  if (!clockEl) return;
  const now = new Date();
  clockEl.textContent = timeFmt.format(now);
  clockEl.dateTime = now.toISOString();
}
tick();
setInterval(tick, 1000);

// ---------- Utilidades ----------
const coordFmt = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 5, maximumFractionDigits: 5 });
function escapeHTML(str) {
  return String(str ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function fmtCoords(p) { return `${coordFmt.format(p.lat)}, ${coordFmt.format(p.lng)}`; }
function fmtWhen(iso) { const d = new Date(iso); return isNaN(d) ? '' : whenFmt.format(d); }

// ---------- Tipos ----------
const TYPE_CONFIG = {
  alagamento: { label: 'Alagamento', icon: 'bi-water' },
  erosao:     { label: 'Erosão', icon: 'bi-cone-striped' },
  talude:     { label: 'Queda de talude', icon: 'bi-exclamation-octagon' }
};

// ---------- Estado ----------
let map = null, infoWindow = null, geocoder = null;
let user = null;            // usuário logado (ou null)
let alerts = [];            // { id, tipo, local, comentario, lat, lng, dataHora, marker }
let pickedLatLng = null;
let tempMarker = null;
let editingId = null;
let pendingRemoval = null;  // { alert, index, timer }

const $ = id => document.getElementById(id);
const localInput = $('local'), comentarioInput = $('comentario'), tipoSelect = $('tipo');
const addBtn = $('add-alert-btn'), pickedCoordsEl = $('picked-coords'), formErrorEl = $('form-error');
const alertListEl = $('alert-list'), emptyListEl = $('empty-list'), countEl = $('alert-count');
const hintEl = $('pick-hint'), listStatusEl = $('list-status');
const fTipo = $('f-tipo'), fDe = $('f-de'), fAte = $('f-ate'), fClear = $('f-clear');

const isAdmin = () => !!user && user.perfil === 'ADMINISTRADOR';

function setError(msg, field) {
  formErrorEl.textContent = msg || '';
  localInput.removeAttribute('aria-invalid');
  if (field) { field.setAttribute('aria-invalid', 'true'); field.focus(); }
}

// ---------- Marcadores ----------
function popupHTML(a) {
  const config = TYPE_CONFIG[a.tipo];
  return `<div class="iw t-${a.tipo}">
    <b>${escapeHTML(config.label)}</b><br>
    ${escapeHTML(a.local)}<br>
    ${a.comentario ? escapeHTML(a.comentario) + '<br>' : ''}
    <small>Registrado em ${escapeHTML(fmtWhen(a.dataHora))}</small>
  </div>`;
}
function openInfo(a) {
  if (!map || !a.marker) return;
  infoWindow.setContent(popupHTML(a));
  infoWindow.open({ map, anchor: a.marker });
}
function addMarker(a) {
  if (!map) return;
  a.marker = AppMaps.marker(map, { lat: a.lat, lng: a.lng }, a.tipo, { title: a.local });
  a.marker.addListener('click', () => openInfo(a));
}
function dropMarker(a) {
  if (a.marker) { a.marker.setMap(null); AppMaps.forget(a.marker); a.marker = null; }
}

// ---------- Carregar da API ----------
function filterQuery() {
  const p = new URLSearchParams();
  if (fTipo && fTipo.value !== 'todas') p.set('categoria', fTipo.value);
  if (fDe && fDe.value) p.set('de', fDe.value);
  if (fAte && fAte.value) p.set('ate', fAte.value);
  const q = p.toString();
  return q ? '?' + q : '';
}

async function loadAlerts() {
  listStatusEl.textContent = 'Carregando ocorrências…';
  try {
    const data = await Api.get('/incidents/' + filterQuery());
    alerts.forEach(dropMarker);
    alerts = data.ocorrencias.filter(a => TYPE_CONFIG[a.tipo]);
    alerts.forEach(addMarker);
    listStatusEl.textContent = '';
    renderAlertList();
  } catch (err) {
    listStatusEl.textContent = err.message;
    renderAlertList();
  }
}

// ---------- Busca do nome da via ----------
async function reverseGeocode(latlng) {
  try {
    const { results } = await geocoder.geocode({ location: latlng });
    if (results && results.length) {
      const comps = results[0].address_components;
      const get = t => (comps.find(c => c.types.includes(t)) || {}).long_name || '';
      const via = get('route');
      const bairro = get('sublocality_level_1') || get('sublocality') || get('neighborhood');
      const nome = via && bairro ? `${via}, ${bairro}` : (via || bairro);
      return nome || results[0].formatted_address;
    }
  } catch (e) { /* tenta o Nominatim */ }

  const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${latlng.lat}&lon=${latlng.lng}&zoom=17&addressdetails=1`;
  const resp = await fetch(url, { headers: { 'Accept-Language': 'pt-BR' } });
  if (!resp.ok) throw new Error('HTTP ' + resp.status);
  const data = await resp.json();
  if (data && data.address) {
    const ad = data.address;
    const via = ad.road || ad.pedestrian || ad.footway || ad.residential || '';
    const bairro = ad.suburb || ad.neighbourhood || ad.city_district || '';
    return via && bairro ? `${via}, ${bairro}` : (via || bairro || data.display_name || '');
  }
  return (data && data.display_name) || '';
}

let lookupId = 0;
async function onMapClick(e) {
  if (!user) {
    setError('Entre na sua conta para registrar uma ocorrência.');
    return;
  }
  if (!user.perfilCompleto) {
    setError('Complete seu cadastro (CPF e endereço) para registrar alertas.');
    return;
  }
  pickedLatLng = { lat: e.latLng.lat(), lng: e.latLng.lng() };
  setError('');
  infoWindow.close();

  if (tempMarker) tempMarker.setMap(null);
  const brand = AppMaps.cssVar('--brand', '#0d6b63');
  tempMarker = new google.maps.Marker({
    map, position: pickedLatLng, clickable: false,
    icon: { path: google.maps.SymbolPath.CIRCLE, scale: 10, fillColor: brand, fillOpacity: 0.3, strokeColor: brand, strokeWeight: 2 }
  });

  pickedCoordsEl.textContent = `Buscando endereço para ${fmtCoords(pickedLatLng)}…`;
  addBtn.disabled = true;
  const myId = ++lookupId;
  try {
    const nome = await reverseGeocode(pickedLatLng);
    if (myId !== lookupId) return;
    localInput.value = nome || `Local sem identificação (${fmtCoords(pickedLatLng)})`;
    pickedCoordsEl.textContent = `Ponto marcado: ${fmtCoords(pickedLatLng)}. Confira o nome da via acima.`;
  } catch (err) {
    if (myId !== lookupId) return;
    localInput.value = '';
    pickedCoordsEl.textContent = `Ponto marcado: ${fmtCoords(pickedLatLng)}. Não foi possível buscar o nome da via; digite-o no campo acima.`;
    localInput.focus();
  } finally {
    if (myId === lookupId) addBtn.disabled = !pickedLatLng;
  }
}

// ---------- Registrar (POST /incidents) ----------
addBtn.addEventListener('click', async () => {
  if (!pickedLatLng) { setError('Clique em um ponto do mapa antes de adicionar o alerta.'); return; }
  if (!localInput.value.trim()) { setError('Informe a rodovia ou rua do alerta.', localInput); return; }
  setError('');
  addBtn.disabled = true;
  addBtn.setAttribute('aria-busy', 'true');
  try {
    const { ocorrencia } = await Api.post('/incidents/', {
      tipo: tipoSelect.value,
      local: localInput.value.trim(),
      comentario: comentarioInput.value.trim(),
      lat: pickedLatLng.lat,
      lng: pickedLatLng.lng
    });
    alerts.unshift(ocorrencia);
    addMarker(ocorrencia);
    renderAlertList();

    if (tempMarker) { tempMarker.setMap(null); tempMarker = null; }
    pickedLatLng = null;
    localInput.value = '';
    comentarioInput.value = '';
    pickedCoordsEl.textContent = 'Alerta adicionado. Clique no mapa para marcar outro ponto.';
  } catch (err) {
    const campo = err.campos && err.campos.local ? localInput : null;
    setError(err.message, campo);
    if (err.codigo === 'PERFIL_INCOMPLETO') { user.perfilCompleto = false; applyAuthState(); }
    else if (err.codigo === 'SESSAO_INVALIDA') { user = null; applyAuthState(); }
    else addBtn.disabled = false;
  } finally {
    addBtn.removeAttribute('aria-busy');
  }
});

// ---------- Lista ----------
function itemActions(a) {
  let html = `<button type="button" class="locate-btn" aria-label="Ver no mapa: ${escapeHTML(a.local)}" title="Ver no mapa"><i class="bi bi-crosshair" aria-hidden="true"></i></button>`;
  if (isAdmin()) {
    html += `<button type="button" class="edit-btn" aria-label="Editar alerta: ${escapeHTML(a.local)}" title="Editar"><i class="bi bi-pencil" aria-hidden="true"></i></button>`;
    html += `<button type="button" class="remove-btn" aria-label="Excluir alerta: ${escapeHTML(a.local)}" title="Excluir"><i class="bi bi-trash3" aria-hidden="true"></i></button>`;
  }
  return html;
}

function editFormHTML(a) {
  const opts = Object.entries(TYPE_CONFIG).map(([k, c]) => `<option value="${k}"${k === a.tipo ? ' selected' : ''}>${c.label}</option>`).join('');
  return `<form class="edit-form" novalidate>
    <label class="visually-hidden" for="edit-tipo-${a.id}">Tipo</label>
    <select id="edit-tipo-${a.id}" name="tipo" class="input">${opts}</select>
    <label class="visually-hidden" for="edit-com-${a.id}">Comentário</label>
    <textarea id="edit-com-${a.id}" name="comentario" class="input" maxlength="500" placeholder="Comentário…">${escapeHTML(a.comentario)}</textarea>
    <p class="form-error" role="alert"></p>
    <div class="edit-actions">
      <button type="submit" class="btn btn-primary">Salvar</button>
      <button type="button" class="btn btn-ghost cancel-btn">Cancelar</button>
    </div>
  </form>`;
}

function renderAlertList() {
  alertListEl.innerHTML = '';
  countEl.textContent = String(alerts.length);

  if (pendingRemoval) {
    const undo = document.createElement('div');
    undo.className = 'alert-item undo';
    undo.setAttribute('role', 'listitem');
    undo.innerHTML = `<span>Alerta será excluído.</span><button type="button">Desfazer</button>`;
    undo.querySelector('button').addEventListener('click', undoRemove);
    alertListEl.appendChild(undo);
  }

  if (alerts.length === 0 && !pendingRemoval) {
    const filtered = filterQuery() !== '';
    emptyListEl.querySelector('p').textContent = filtered ? 'Nenhuma ocorrência com esse filtro.' : 'Nenhum alerta cadastrado.';
    emptyListEl.querySelector('small').textContent = filtered ? 'Mude o tipo ou o período, ou limpe o filtro.' : (user ? 'Clique em um ponto do mapa para criar o primeiro.' : 'Entre na sua conta para criar o primeiro.');
    alertListEl.appendChild(emptyListEl);
    return;
  }

  alerts.forEach(a => {
    const config = TYPE_CONFIG[a.tipo];
    const item = document.createElement('div');
    item.className = `alert-item ${a.tipo}`;
    item.setAttribute('role', 'listitem');
    const editing = editingId === a.id;
    item.innerHTML = `
      <div class="top-row">
        <span class="icon-type"><i class="bi ${config.icon}" aria-hidden="true"></i>${escapeHTML(config.label)}</span>
        <div class="item-actions">${editing ? '' : itemActions(a)}</div>
      </div>
      <div class="location">${escapeHTML(a.local)}</div>
      ${editing ? editFormHTML(a) : (a.comentario ? `<div class="comment">${escapeHTML(a.comentario)}</div>` : '')}
      <div class="time"><i class="bi bi-clock" aria-hidden="true"></i> ${escapeHTML(fmtWhen(a.dataHora))}${a.minha ? ' · <span class="mine">seu alerta</span>' : ''}</div>
    `;
    const q = s => item.querySelector(s);
    if (q('.locate-btn')) q('.locate-btn').addEventListener('click', () => {
      if (!map) return;
      map.panTo({ lat: a.lat, lng: a.lng });
      if (map.getZoom() < 15) map.setZoom(15);
      openInfo(a);
    });
    if (q('.remove-btn')) q('.remove-btn').addEventListener('click', () => removeAlert(a.id));
    if (q('.edit-btn')) q('.edit-btn').addEventListener('click', () => { editingId = a.id; renderAlertList(); });
    if (editing) wireEditForm(item, a);
    alertListEl.appendChild(item);
  });

  if (editingId) {
    const sel = alertListEl.querySelector('.edit-form select');
    if (sel) sel.focus();
  }
}

// ---------- Editar (PUT /admin/incidents/{id}) ----------
function wireEditForm(item, a) {
  const form = item.querySelector('.edit-form');
  const err = form.querySelector('.form-error');
  form.querySelector('.cancel-btn').addEventListener('click', () => { editingId = null; renderAlertList(); });
  form.addEventListener('keydown', e => { if (e.key === 'Escape') { editingId = null; renderAlertList(); } });
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const btn = form.querySelector('[type=submit]');
    btn.disabled = true;
    try {
      const { ocorrencia } = await Api.put('/admin/incidents.php?id=' + a.id, {
        tipo: form.tipo.value,
        comentario: form.comentario.value.trim()
      });
      const tipoMudou = ocorrencia.tipo !== a.tipo;
      Object.assign(a, { tipo: ocorrencia.tipo, comentario: ocorrencia.comentario });
      if (tipoMudou) { dropMarker(a); addMarker(a); }
      editingId = null;
      renderAlertList();
    } catch (ex) {
      err.textContent = ex.message;
      btn.disabled = false;
    }
  });
}

// ---------- Excluir (DELETE /admin/incidents/{id}) com 5 s para desfazer ----------
function removeAlert(id) {
  const idx = alerts.findIndex(a => a.id === id);
  if (idx === -1) return;
  if (pendingRemoval) commitRemoval();
  const [removed] = alerts.splice(idx, 1);
  if (removed.marker) removed.marker.setMap(null);
  if (infoWindow) infoWindow.close();
  pendingRemoval = { alert: removed, index: idx, timer: setTimeout(commitRemoval, 5000) };
  renderAlertList();
  const undoBtn = alertListEl.querySelector('.undo button');
  if (undoBtn) undoBtn.focus();
}
function undoRemove() {
  if (!pendingRemoval) return;
  clearTimeout(pendingRemoval.timer);
  const { alert, index } = pendingRemoval;
  if (alert.marker) alert.marker.setMap(map);
  alerts.splice(index, 0, alert);
  pendingRemoval = null;
  renderAlertList();
}
async function commitRemoval() {
  if (!pendingRemoval) return;
  clearTimeout(pendingRemoval.timer);
  const { alert, index } = pendingRemoval;
  pendingRemoval = null;
  renderAlertList();
  try {
    await Api.del('/admin/incidents.php?id=' + alert.id);
    dropMarker(alert);
  } catch (err) {
    // não excluiu: volta para a lista e avisa
    if (alert.marker) alert.marker.setMap(map);
    alerts.splice(Math.min(index, alerts.length), 0, alert);
    renderAlertList();
    listStatusEl.textContent = 'Não foi possível excluir: ' + err.message;
  }
}
// excluir antes de sair da página também conta
window.addEventListener('pagehide', () => { if (pendingRemoval) commitRemoval(); });

// ---------- Filtro (RF02) ----------
[fTipo, fDe, fAte].forEach(el => el && el.addEventListener('change', () => {
  if (fDe.value && fAte.value && fDe.value > fAte.value) {
    listStatusEl.textContent = 'A data inicial é depois da final.';
    return;
  }
  loadAlerts();
}));
if (fClear) fClear.addEventListener('click', () => {
  fTipo.value = 'todas'; fDe.value = ''; fAte.value = '';
  loadAlerts();
});

// ---------- Estado do formulário conforme o login ----------
const podeRegistrar = () => !!user && !!user.perfilCompleto;

function applyAuthState() {
  const ok = podeRegistrar();
  [tipoSelect, localInput, comentarioInput].forEach(el => { el.disabled = !ok; });
  if (!user) {
    addBtn.disabled = true;
    hintEl.innerHTML = '<i class="bi bi-person-lock" aria-hidden="true"></i><span><a class="link" href="login.html?next=index.html">Entre na sua conta</a> para registrar uma ocorrência. Ver e filtrar o mapa não precisa de login.</span>';
  } else if (!user.perfilCompleto) {
    addBtn.disabled = true;
    hintEl.innerHTML = '<i class="bi bi-person-vcard" aria-hidden="true"></i><span>Para evitar alertas falsos, só contas validadas registram ocorrências. <a class="link" href="perfil.html?next=index.html">Complete seu CPF e endereço</a>.</span>';
  }
}

// ---------- Início ----------
(async function init() {
  user = await Api.me();
  applyAuthState();
  loadAlerts();
})();

AppMaps.ready.then(() => {
  map = AppMaps.create($('map'), {
    zoom: 10,
    gestureHandling: 'greedy',
    zoomControl: true,
    minZoom: 3,
    draggableCursor: 'crosshair'
  });
  infoWindow = new google.maps.InfoWindow();
  geocoder = new google.maps.Geocoder();
  map.addListener('click', onMapClick);
  alerts.forEach(a => { if (!a.marker) addMarker(a); });
}).catch(() => {
  if (user || !hintEl.querySelector('a')) {
    hintEl.innerHTML = '<i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>O mapa não carregou. A lista de alertas continua funcionando ao lado.</span>';
  }
});
