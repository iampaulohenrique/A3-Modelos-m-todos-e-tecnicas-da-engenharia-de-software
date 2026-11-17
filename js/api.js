// =========================================================
// Comunicação com a API em PHP.
// - caminhos relativos (api/...): as páginas ficam na raiz do site (/, /mapa, /entrar…),
//   então funciona no domínio e em subpasta (http://localhost/a3/ no XAMPP)
//   e em http://localhost:8000/public/ (php -S)
// - guarda o token CSRF em memória e o envia em todo POST, PUT e DELETE
// =========================================================
(function () {
  var BASE = 'api';
  var csrf = null;
  var mePromise = null;

  function ApiError(message, status, campos, codigo) {
    this.name = 'ApiError';
    this.message = message;
    this.status = status;
    this.campos = campos || {};
    this.codigo = codigo || null;
  }
  ApiError.prototype = Object.create(Error.prototype);

  async function request(method, path, body) {
    var headers = { 'Accept': 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (method !== 'GET' && csrf) headers['X-CSRF-Token'] = csrf;

    var resp;
    try {
      resp = await fetch(BASE + path, {
        method: method,
        headers: headers,
        credentials: 'same-origin',
        body: body !== undefined ? JSON.stringify(body) : undefined
      });
    } catch (e) {
      throw new ApiError('Sem conexão com o servidor. Confira se o Apache está ligado no XAMPP.', 0);
    }

    if (resp.status === 204) return null;
    var data = null;
    var text = await resp.text();
    try { data = text ? JSON.parse(text) : null; } catch (e) {
      // resposta que não é JSON: quase sempre o PHP não está rodando (arquivo aberto sem servidor)
      throw new ApiError('A API não respondeu em JSON. Abra o site pelo servidor (XAMPP ou php -S), não pelo arquivo.', resp.status);
    }
    if (!resp.ok) {
      throw new ApiError((data && data.erro) || 'Erro ' + resp.status, resp.status, data && data.campos, data && data.codigo);
    }
    if (data && data.csrf) csrf = data.csrf;
    return data;
  }

  // Páginas do site com endereço limpo. Usado para validar o ?next= (sem redirecionamento aberto).
  var PAGINAS = { './': 1, mapa: 1, admin: 1, perfil: 1 };
  var ANTIGAS = { 'home.html': './', 'index.html': 'mapa', 'admin.html': 'admin', 'perfil.html': 'perfil' };
  function paginaSegura(next, padrao) {
    next = ANTIGAS[next] || next;
    return PAGINAS[next] ? next : padrao;
  }

  window.Api = {
    paginaSegura: paginaSegura,
    Error: ApiError,
    get: function (p) { return request('GET', p); },
    post: function (p, b) { return request('POST', p, b || {}); },
    put: function (p, b) { return request('PUT', p, b || {}); },
    del: function (p) { return request('DELETE', p); },

    // Usuário logado (ou null); a resposta fica guardada para a página toda
    me: function (force) {
      if (!mePromise || force) {
        mePromise = request('GET', '/auth/me.php')
          .then(function (d) { return d.usuario; })
          .catch(function () { return null; });
      }
      return mePromise;
    },
    login: async function (email, password) {
      var d = await request('POST', '/auth/login.php', { email: email, password: password });
      mePromise = Promise.resolve(d.usuario);
      return d.usuario;
    },
    register: async function (dados) {
      var d = await request('POST', '/auth/register.php', dados);
      mePromise = Promise.resolve(d.usuario);
      return d.usuario;
    },
    logout: async function () {
      await request('POST', '/auth/logout.php', {});
      csrf = null;
      mePromise = Promise.resolve(null);
    }
  };
})();
