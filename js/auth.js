// =========================================================
// Autenticação na interface:
// - envia os formulários de login e cadastro para a API (a validação de campos fica em forms.js)
// - mostra no cabeçalho quem está logado, com os botões "Painel admin" e "Sair"
// =========================================================
(function () {
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // Para onde ir depois de entrar: ?next=admin ou o mapa
  function destino() {
    var next = new URLSearchParams(location.search).get('next') || '';
    return Api.paginaSegura(next, 'mapa');   // só páginas do próprio site (sem redirecionamento aberto)
  }

  function showAlert(box, msg, kind) {
    if (!box) return;
    box.textContent = msg;
    box.className = 'alert-message ' + (kind || 'error');
  }

  function fieldError(form, name, msg) {
    var input = form.querySelector('[name="' + name + '"]');
    if (!input) return false;
    var box = document.getElementById(input.id + '-error');
    if (box) box.textContent = msg;
    input.setAttribute('aria-invalid', 'true');
    return input;
  }

  function resetButton(form) {
    var btn = form.querySelector('[type="submit"]');
    if (!btn) return;
    btn.removeAttribute('aria-busy');
    btn.disabled = false;
    var label = btn.querySelector('.btn-label');
    if (label && btn.dataset.label) label.textContent = btn.dataset.label;
  }

  function wireForm(form, send) {
    if (!form) return;
    var btn = form.querySelector('[type="submit"]');
    var label = btn && btn.querySelector('.btn-label');
    if (label) btn.dataset.label = label.textContent;
    var alertBox = document.getElementById('auth-alert');

    form.addEventListener('submit', async function (e) {
      e.preventDefault();               // forms.js já barrou o envio se havia campo inválido
      if (alertBox) alertBox.className = 'alert-message';
      if (btn) btn.disabled = true;
      try {
        await send(form);
        location.href = form.dataset.next || destino();
      } catch (err) {
        resetButton(form);
        var first = null;
        Object.keys(err.campos || {}).forEach(function (k) {
          var el = fieldError(form, k, err.campos[k]);
          if (el && !first) first = el;
        });
        showAlert(alertBox, err.message || 'Não foi possível concluir. Tente de novo.');
        (first || form.querySelector('input:not([type=hidden])')).focus();
      }
    });
  }

  wireForm(document.getElementById('login-form'), function (f) {
    return Api.login(f.email.value.trim(), f.password.value);
  });

  // depois do cadastro, a pessoa completa CPF e endereço (exigidos pelo banco para criar alertas)
  var regForm = document.getElementById('register-form');
  if (regForm) regForm.dataset.next = 'perfil?next=mapa&novo=1';

  wireForm(document.getElementById('register-form'), function (f) {
    return Api.register({
      name: f.name.value.trim(),
      email: f.email.value.trim(),
      password: f.password.value,
      confirm_password: f.confirm_password.value
    });
  });

  // Já logado e abriu a tela de login ou cadastro: vai direto para o mapa
  if (document.getElementById('login-form') || document.getElementById('register-form')) {
    Api.me().then(function (u) { if (u) location.replace(destino()); });
  }

  async function sair() {
    try { await Api.logout(); } catch (e) { /* sessão já encerrada */ }
    location.href = document.body.dataset.page === 'home' ? './' : 'mapa';
  }

  // Links só para quem não entrou (Criar conta, Entrar no rodapé) e o "Entrar" do menu do celular
  Api.me().then(function (u) {
    if (!u) return;
    document.querySelectorAll('[data-auth-guest]').forEach(function (el) { el.hidden = true; });
    document.querySelectorAll('[data-auth-login]').forEach(function (el) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = el.className + ' menu-logout';
      b.textContent = 'Sair (' + String(u.nome).split(' ')[0] + ')';
      b.addEventListener('click', sair);
      el.replaceWith(b);
    });
  });

  // Cabeçalho: troca "Entrar" por nome + ações
  var slot = document.getElementById('auth-slot');
  if (slot) {
    Api.me().then(function (u) {
      if (!u) return;
      var primeiro = esc(String(u.nome).split(' ')[0]);
      slot.innerHTML =
        '<a class="user-chip" href="perfil" title="Minha conta (' + esc(u.email) + ')"><i class="bi bi-person-circle" aria-hidden="true"></i><span class="user-name">' + primeiro + '</span>' +
        (u.perfil === 'ADMINISTRADOR' && !slot.hasAttribute('data-compact') ? '<span class="tag tag-admin-mini">admin</span>' : '') + '</a>' +
        (u.perfil === 'ADMINISTRADOR' && !slot.hasAttribute('data-compact') && document.body.dataset.page !== 'admin'
          ? '<a class="btn btn-ghost" href="admin"><i class="bi bi-shield-lock" aria-hidden="true"></i> <span class="btn-text">Painel admin</span></a>' : '') +
        '<button type="button" class="btn btn-ghost" id="logout-btn"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> <span class="btn-text">Sair</span></button>';
      document.getElementById('logout-btn').addEventListener('click', sair);
    });
  }
})();
