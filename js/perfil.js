// =========================================================
// Minha conta: nome, CPF e endereço (conta validada para criar alertas).
// O navegador valida para ajudar quem digita; o servidor e o banco validam de novo.
// =========================================================
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var form = $('perfil-form');
  var statusBox = $('perfil-status');
  var alertBox = $('auth-alert');
  var btn = $('submit-btn');
  var cpfFixo = false;

  var digits = function (v) { return String(v || '').replace(/\D+/g, ''); };

  function cpfValido(c) {
    if (!/^\d{11}$/.test(c) || /^(\d)\1{10}$/.test(c)) return false;
    for (var t = 9; t < 11; t++) {
      var s = 0;
      for (var i = 0; i < t; i++) s += Number(c[i]) * (t + 1 - i);
      if (Number(c[t]) !== ((10 * s) % 11) % 10) return false;
    }
    return true;
  }

  function maskCpf(v) {
    var d = digits(v).slice(0, 11);
    return d.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
  }
  function maskCep(v) {
    var d = digits(v).slice(0, 8);
    return d.length > 5 ? d.slice(0, 5) + '-' + d.slice(5) : d;
  }

  function setError(name, msg) {
    var input = form.elements[name];
    var box = $(name + '-error');
    if (box) box.textContent = msg || '';
    if (!input) return null;
    if (msg) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
    return input;
  }

  function showStatus(completo) {
    statusBox.className = 'alert-message ' + (completo ? 'ok' : 'pend');
    statusBox.textContent = completo
      ? 'Conta validada: você pode registrar alertas no mapa.'
      : 'Falta completar o cadastro: sem CPF e endereço o sistema não aceita novos alertas.';
  }

  function fill(u) {
    $('perfil-email').textContent = u.email;
    form.nome.value = u.nome || '';
    var e = u.endereco || {};
    form.cep.value = maskCep(e.cep || '');
    form.logradouro.value = e.logradouro || '';
    form.numero.value = e.numero || '';
    form.complemento.value = e.complemento || '';
    form.bairro.value = e.bairro || '';
    form.cidade.value = e.cidade || '';
    form.uf.value = e.uf || '';
    cpfFixo = !!u.cpf;
    if (cpfFixo) {
      form.cpf.value = u.cpf;            // vem mascarado do servidor (***.***.***-12)
      form.cpf.readOnly = true;
      $('cpf-help').textContent = 'CPF cadastrado. Ele não pode ser alterado.';
    }
    showStatus(u.perfilCompleto);
  }

  // máscaras
  form.cpf.addEventListener('input', function () { if (!cpfFixo) this.value = maskCpf(this.value); });
  form.cep.addEventListener('input', function () { this.value = maskCep(this.value); });

  // CEP → endereço (ViaCEP, serviço público e gratuito)
  form.cep.addEventListener('change', async function () {
    var cep = digits(this.value);
    var help = $('cep-help');
    if (cep.length !== 8) return;
    help.textContent = 'Buscando endereço…';
    try {
      var r = await fetch('https://viacep.com.br/ws/' + cep + '/json/');
      var d = await r.json();
      if (d.erro) { help.textContent = 'CEP não encontrado. Preencha o endereço à mão.'; return; }
      if (d.logradouro) form.logradouro.value = d.logradouro;
      if (d.bairro) form.bairro.value = d.bairro;
      if (d.localidade) form.cidade.value = d.localidade;
      if (d.uf) form.uf.value = d.uf;
      help.textContent = 'Endereço preenchido pelo CEP. Confira e informe o número.';
      form.numero.focus();
    } catch (e) {
      help.textContent = 'Não foi possível buscar o CEP. Preencha o endereço à mão.';
    }
  });

  form.addEventListener('input', function (e) {
    if (e.target.getAttribute('aria-invalid') === 'true') setError(e.target.name, '');
  });

  function validar() {
    var erros = {};
    if (form.nome.value.trim().length < 3) erros.nome = 'Informe seu nome completo.';
    if (!cpfFixo && !cpfValido(digits(form.cpf.value))) erros.cpf = 'CPF inválido. Confira os números.';
    if (digits(form.cep.value).length !== 8) erros.cep = 'CEP com 8 números.';
    if (!form.uf.value) erros.uf = 'Escolha o estado.';
    if (form.logradouro.value.trim().length < 3) erros.logradouro = 'Informe a rua.';
    if (!form.numero.value.trim()) erros.numero = 'Informe o número (ou "s/n").';
    if (form.bairro.value.trim().length < 2) erros.bairro = 'Informe o bairro.';
    if (form.cidade.value.trim().length < 2) erros.cidade = 'Informe a cidade.';
    return erros;
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    alertBox.className = 'alert-message';
    ['nome', 'cpf', 'cep', 'uf', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade'].forEach(function (n) { setError(n, ''); });

    var erros = validar();
    var first = null;
    Object.keys(erros).forEach(function (k) { var el = setError(k, erros[k]); if (el && !first) first = el; });
    if (first) { first.focus(); return; }

    btn.disabled = true;
    try {
      var body = {
        nome: form.nome.value.trim(),
        cep: digits(form.cep.value),
        logradouro: form.logradouro.value.trim(),
        numero: form.numero.value.trim(),
        complemento: form.complemento.value.trim(),
        bairro: form.bairro.value.trim(),
        cidade: form.cidade.value.trim(),
        uf: form.uf.value
      };
      if (!cpfFixo) body.cpf = digits(form.cpf.value);
      var d = await Api.put('/auth/profile.php', body);
      fill(d.usuario);
      Api.me(true);
      var next = new URLSearchParams(location.search).get('next') || '';
      if (d.usuario.perfilCompleto && /^[a-z]+\.html$/.test(next)) {
        location.href = next;
        return;
      }
      statusBox.focus && statusBox.setAttribute('tabindex', '-1');
      statusBox.focus();
    } catch (err) {
      Object.keys(err.campos || {}).forEach(function (k) { var el = setError(k, err.campos[k]); if (el && !first) first = el; });
      alertBox.textContent = err.message;
      alertBox.className = 'alert-message error';
      (first || form.nome).focus();
    } finally {
      btn.disabled = false;
    }
  });

  // carrega o perfil; sem login vai para a tela de entrar
  Api.me().then(function () { return Api.get('/auth/profile.php'); }).then(function (d) {   // me() traz o token CSRF
    fill(d.usuario);
    if (new URLSearchParams(location.search).get('novo')) {
      statusBox.textContent = 'Conta criada. Agora complete CPF e endereço para poder registrar alertas.';
    }
  }).catch(function (err) {
    if (err.status === 401) location.replace('login.html?next=perfil.html');
    else { alertBox.textContent = err.message; alertBox.className = 'alert-message error'; }
  });
})();
