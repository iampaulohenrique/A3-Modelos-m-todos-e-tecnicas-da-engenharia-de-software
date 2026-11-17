// Validação dos formulários de login e cadastro.
// Só impede o envio quando há erro; com tudo certo, o formulário segue para a API
// (ou para o script de autenticação, se ele interceptar o envio).
(function () {
  function fieldError(input, msg) {
    var box = document.getElementById(input.id + '-error');
    if (box) box.textContent = msg || '';
    if (msg) input.setAttribute('aria-invalid', 'true');
    else input.removeAttribute('aria-invalid');
  }

  function validate(form, rules) {
    var first = null;
    rules.forEach(function (r) {
      var input = form.querySelector('#' + r.id);
      if (!input) return;
      var msg = r.check(input.value, form);
      fieldError(input, msg);
      if (msg && !first) first = input;
    });
    return first;
  }

  function wire(form, rules) {
    if (!form) return;
    form.addEventListener('submit', function (e) {
      var first = validate(form, rules);
      if (first) {
        e.preventDefault();
        e.stopImmediatePropagation();
        first.focus();
        return;
      }
      var btn = form.querySelector('[type="submit"]');
      if (btn) {
        btn.setAttribute('aria-busy', 'true');
        var label = btn.querySelector('.btn-label');
        if (label && btn.dataset.loading) label.textContent = btn.dataset.loading;
      }
    }, true);
    // limpa o erro do campo assim que a pessoa corrige
    form.addEventListener('input', function (e) {
      if (e.target.getAttribute('aria-invalid') === 'true') fieldError(e.target, '');
    });
  }

  var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  wire(document.getElementById('login-form'), [
    { id: 'email', check: function (v) { return !v.trim() ? 'Informe seu e-mail.' : !emailRe.test(v.trim()) ? 'Confira o e-mail: falta o @ ou o domínio.' : ''; } },
    { id: 'password', check: function (v) { return !v ? 'Informe sua senha.' : ''; } }
  ]);

  wire(document.getElementById('register-form'), [
    { id: 'name', check: function (v) { return v.trim().length < 3 ? 'Informe seu nome completo.' : ''; } },
    { id: 'email', check: function (v) { return !v.trim() ? 'Informe seu e-mail.' : !emailRe.test(v.trim()) ? 'Confira o e-mail: falta o @ ou o domínio.' : ''; } },
    { id: 'password', check: function (v) { return v.length < 8 ? 'Use pelo menos 8 caracteres.' : ''; } },
    { id: 'confirm_password', check: function (v, f) { return !v ? 'Repita a senha.' : v !== f.querySelector('#password').value ? 'As senhas não são iguais.' : ''; } }
  ]);

  // Mostrar/ocultar senha
  document.querySelectorAll('.toggle-password').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('aria-controls'));
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', String(show));
      btn.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
      btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
  });

  // Força da senha (cadastro)
  var pwd = document.querySelector('#register-form #password');
  var bar = document.getElementById('pwd-bar');
  var txt = document.getElementById('pwd-text');
  if (pwd && bar) {
    pwd.addEventListener('input', function () {
      var v = pwd.value, score = 0;
      if (v.length >= 8) score++;
      if (/[A-Z]/.test(v)) score++;
      if (/[0-9]/.test(v)) score++;
      if (/[^A-Za-z0-9]/.test(v)) score++;
      bar.style.width = (score / 4 * 100) + '%';
      bar.style.backgroundColor = score <= 1 ? 'var(--talude)' : score <= 3 ? 'var(--erosao)' : 'var(--ok)';
      if (txt) txt.textContent = !v ? '—' : score <= 1 ? 'Fraca' : score <= 3 ? 'Média' : 'Forte';
      var meter = bar.parentElement;
      meter.setAttribute('aria-valuenow', String(score));
    });
  }
})();
