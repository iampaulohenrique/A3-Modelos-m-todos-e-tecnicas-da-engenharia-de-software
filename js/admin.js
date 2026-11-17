// =========================================================
// Painel administrativo (ligado à API)
// - GET /admin/users: usuários, contagem de ocorrências e indicadores
// - GET /admin/logs: registros de auditoria
// - POST /admin/purge: limpar ocorrências antigas (com confirmação)
// =========================================================
(function () {
  var $ = function (id) { return document.getElementById(id); };

  // relógio do cabeçalho (fora do HTML para a CSP não precisar liberar script inline)
  var clock = $('clock');
  if (clock) {
    var clockFmt = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    var tick = function () { var now = new Date(); clock.textContent = clockFmt.format(now); clock.dateTime = now.toISOString(); };
    tick();
    setInterval(tick, 1000);
  }
  var tbody = $('user-table-body');
  var terminal = $('log-terminal');
  var filterUser = $('filter-user');
  var filterRole = $('filter-role');
  var usuarios = [];
  var logs = [];
  var numFmt = new Intl.NumberFormat('pt-BR');
  var dtFmt = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'medium' });

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fmtDate(s) {
    var d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? s : dtFmt.format(d);
  }

  function blocked(icon, title, text, link) {
    document.querySelector('.layout').innerHTML =
      '<main class="admin-main" id="conteudo"><div class="admin-blocked"><i class="bi ' + icon + '" aria-hidden="true"></i>' +
      '<h1>' + esc(title) + '</h1><p>' + esc(text) + '</p>' + link + '</div></main>';
  }

  function renderUsers() {
    var q = (filterUser.value || '').trim().toLowerCase();
    var role = filterRole.value;
    var lista = usuarios.filter(function (u) {
      var okQ = !q || u.nome.toLowerCase().indexOf(q) !== -1 || u.email.toLowerCase().indexOf(q) !== -1;
      var okR = role === 'all' || (role === 'admin' ? u.perfil === 'ADMINISTRADOR' : u.perfil === 'USUARIO_COMUM');
      return okQ && okR;
    });
    if (!lista.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="table-msg">Nenhum usuário encontrado com esse filtro.</td></tr>';
      return;
    }
    tbody.innerHTML = lista.map(function (u) {
      var admin = u.perfil === 'ADMINISTRADOR';
      return '<tr>' +
        '<td class="mono">#' + u.id + '</td>' +
        '<td><strong>' + esc(u.nome) + '</strong>' + (u.perfilCompleto ? ' <span class="badge-role role-ok" title="CPF e endereço completos">validada</span>' : ' <span class="badge-role role-pend" title="Sem CPF ou endereço: não cria alertas">pendente</span>') + '</td>' +
        '<td>' + esc(u.email) + '</td>' +
        '<td><span class="badge-role ' + (admin ? 'role-admin' : 'role-user') + '">' + (admin ? 'Admin' : 'Usuário') + '</span></td>' +
        '<td class="num">' + numFmt.format(u.ocorrencias) + '</td>' +
        '<td class="row-actions mono">' + esc(fmtDate(u.criadoEm)) + '</td>' +
        '</tr>';
    }).join('');
  }

  function renderLogs() {
    if (!logs.length) {
      terminal.innerHTML = '<div class="log-line"><span class="msg">Nenhum registro ainda.</span></div>';
      return;
    }
    terminal.innerHTML = logs.map(function (l) {
      var detalhe = '';
      if (l.campoAlterado && l.campoAlterado !== 'ocorrencia') {
        detalhe = ' · ' + l.campoAlterado + (l.valorAnterior != null ? ': ' + l.valorAnterior : '') + (l.valorNovo != null ? ' → ' + l.valorNovo : '');
      }
      return '<div class="log-line">' +
        '<span class="timestamp">[' + esc(l.dataHora) + ']</span>' +
        '<span class="level-' + esc(l.nivel) + '">' + esc(l.nivel) + '</span>' +
        '<span class="msg">' + esc(l.acao) + (l.usuario ? ' <span class="who">por ' + esc(l.usuario) + '</span>' : '') +
        (l.ocorrenciaId ? ' (ocorrência #' + l.ocorrenciaId + ')' : '') + esc(detalhe) + '</span>' +
        '</div>';
    }).join('');
  }

  async function load() {
    try {
      var data = await Api.get('/admin/users.php');
      usuarios = data.usuarios;
      $('kpi-users').textContent = numFmt.format(data.totais.usuarios);
      $('kpi-active').textContent = numFmt.format(data.totais.ocorrencias);
      $('kpi-critical').textContent = numFmt.format(data.totais.criticas);
      renderUsers();
    } catch (err) {
      tbody.innerHTML = '<tr><td colspan="6" class="table-msg">' + esc(err.message) + '</td></tr>';
    }
    try {
      logs = (await Api.get('/admin/logs.php?limite=200')).logs;
      renderLogs();
    } catch (err) {
      terminal.innerHTML = '<div class="log-line"><span class="level-ERROR">ERROR</span><span class="msg">' + esc(err.message) + '</span></div>';
    }
  }

  filterUser.addEventListener('input', renderUsers);
  filterRole.addEventListener('change', renderUsers);

  // Exportar logs (JSON)
  $('export-logs-btn').addEventListener('click', function () {
    var blob = new Blob([JSON.stringify(logs, null, 2)], { type: 'application/json' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'logs-auditoria-' + new Date().toISOString().slice(0, 10) + '.json';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
  });

  // Limpar alertas antigos (com confirmação, porque não dá para desfazer)
  $('purge-alerts-btn').addEventListener('click', async function () {
    var btn = this;
    if (!window.confirm('Excluir todas as ocorrências com mais de 90 dias? Essa ação não pode ser desfeita.')) return;
    btn.disabled = true;
    try {
      var r = await Api.post('/admin/purge.php', { dias: 90 });
      btn.nextElementSibling.textContent = r.removidas
        ? numFmt.format(r.removidas) + ' ocorrência(s) antiga(s) excluída(s).'
        : 'Nenhuma ocorrência com mais de 90 dias.';
      load();
    } catch (err) {
      btn.nextElementSibling.textContent = err.message;
    } finally {
      btn.disabled = false;
    }
  });

  // Só administradores: sem login vai para a tela de entrar; usuário comum vê o aviso
  Api.me().then(function (u) {
    if (!u) { location.replace('entrar?next=admin'); return; }
    if (u.perfil !== 'ADMINISTRADOR') {
      blocked('bi-shield-lock', 'Área só para administradores', 'Sua conta não tem permissão para ver o painel.', '<a class="btn btn-primary" href="mapa">Voltar ao mapa</a>');
      return;
    }
    load();
  });
})();
