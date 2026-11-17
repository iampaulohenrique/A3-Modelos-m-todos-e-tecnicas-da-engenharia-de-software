// =========================================================
// Menu lateral do mapa e do painel admin:
// - mostra quem está logado, "Painel admin" (só administrador), "Minha conta" e "Sair"
// - recolhe/expande no computador (a escolha fica salva neste navegador)
// - vira gaveta no celular, aberta pelo botão ☰ do cabeçalho
// =========================================================
(function () {
  var nav = document.getElementById('side-nav');
  if (!nav) return;

  var KEY = 'side_nav_collapsed';
  var openBtn = document.getElementById('nav-open-btn');
  var backdrop = document.getElementById('nav-backdrop');
  var collapseBtn = document.getElementById('nav-collapse-btn');
  var mobile = window.matchMedia('(max-width: 900px)');

  function guardar(v) { try { localStorage.setItem(KEY, v ? '1' : '0'); } catch (e) {} }
  function lido() { try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; } }

  // ---------- recolher / expandir (computador) ----------
  function aplicarRecolhido(v) {
    nav.classList.toggle('is-collapsed', v);
    if (collapseBtn) {
      collapseBtn.setAttribute('aria-expanded', String(!v));
      collapseBtn.setAttribute('aria-label', v ? 'Expandir menu' : 'Recolher menu');
      collapseBtn.title = collapseBtn.getAttribute('aria-label');
    }
  }
  aplicarRecolhido(lido());
  if (collapseBtn) {
    collapseBtn.addEventListener('click', function () {
      var v = !nav.classList.contains('is-collapsed');
      aplicarRecolhido(v);
      guardar(v);
    });
  }

  // nome de cada item como dica quando só o ícone aparece
  nav.querySelectorAll('.nav-item').forEach(function (el) {
    var label = el.querySelector('.nav-label');
    if (label && !el.title) el.title = label.textContent.trim();
  });

  // ---------- gaveta (celular) ----------
  function abrir() {
    nav.classList.add('is-open');
    if (backdrop) backdrop.classList.add('is-open');
    document.body.classList.add('nav-locked');
    if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
    var primeiro = nav.querySelector('a, button');
    if (primeiro) primeiro.focus();
  }
  function fechar(devolverFoco) {
    if (!nav.classList.contains('is-open')) return;
    nav.classList.remove('is-open');
    if (backdrop) backdrop.classList.remove('is-open');
    document.body.classList.remove('nav-locked');
    if (openBtn) {
      openBtn.setAttribute('aria-expanded', 'false');
      if (devolverFoco) openBtn.focus();
    }
  }
  if (openBtn) openBtn.addEventListener('click', abrir);
  if (backdrop) backdrop.addEventListener('click', function () { fechar(true); });
  // link para uma seção da mesma página: fecha a gaveta e deixa a rolagem acontecer
  nav.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href^="#"]');
    if (a) fechar(false);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') fechar(true);
  });
  // passou para tela grande com a gaveta aberta: fecha
  (mobile.addEventListener ? mobile.addEventListener.bind(mobile, 'change') : mobile.addListener.bind(mobile))(function (m) {
    if (!m.matches) fechar(false);
  });

  // ---------- rótulo do botão de tema ----------
  function rotuloTema() {
    var escuro = (window.AppTheme ? window.AppTheme.get() : document.documentElement.getAttribute('data-theme')) === 'dark';
    nav.querySelectorAll('[data-theme-label]').forEach(function (el) {
      el.textContent = escuro ? 'Tema claro' : 'Tema escuro';
    });
  }
  rotuloTema();
  window.addEventListener('themechange', rotuloTema);

  // ---------- sessão ----------
  function iniciais(nome) {
    var p = String(nome || '').trim().split(/\s+/);
    return ((p[0] || '?').charAt(0) + (p.length > 1 ? p[p.length - 1].charAt(0) : '')).toUpperCase();
  }

  async function sair() {
    try { await Api.logout(); } catch (e) { /* sessão já encerrada */ }
    location.href = /home\.html$/.test(location.pathname) ? 'home.html' : 'index.html';
  }

  var visitante = nav.querySelectorAll('[data-nav-guest]');
  var logado = nav.querySelectorAll('[data-nav-user]');
  var soAdmin = nav.querySelectorAll('[data-nav-admin]');

  Api.me().then(function (u) {
    if (!u) return;                                   // visitante: fica o "Entrar"
    var admin = u.perfil === 'ADMINISTRADOR';
    visitante.forEach(function (el) { el.hidden = true; });
    logado.forEach(function (el) { el.hidden = false; });
    soAdmin.forEach(function (el) { el.hidden = !admin; });

    var primeiro = String(u.nome).split(' ')[0];
    var nomeEl = document.getElementById('nav-user-name');
    var papelEl = document.getElementById('nav-user-role');
    var avatar = document.getElementById('nav-avatar');
    var link = document.getElementById('nav-user');
    if (nomeEl) nomeEl.textContent = primeiro;              // textContent: sem risco de injeção de HTML
    if (avatar) avatar.textContent = iniciais(u.nome);
    if (papelEl) {
      papelEl.textContent = admin ? 'Administrador' : (u.perfilCompleto ? 'Conta validada' : 'Complete seu perfil');
      papelEl.classList.toggle('is-admin', admin);
    }
    if (link) {
      link.title = 'Minha conta (' + u.email + ')';
      link.setAttribute('aria-label', 'Minha conta: ' + primeiro + (admin ? ', administrador' : ''));
    }
  }).catch(function () { /* API fora do ar: menu continua com os links públicos */ });

  var logoutBtn = document.getElementById('nav-logout');
  if (logoutBtn) logoutBtn.addEventListener('click', sair);
})();
