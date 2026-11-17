// Tema claro/escuro compartilhado por todas as páginas.
// Carregado no <head> (sem defer) para aplicar o tema antes da primeira pintura.
// Usa a mesma chave que o mapa já usava, então o tema da interface e o do mapa andam juntos.
(function () {
  var KEY = 'dashboard_map_theme';
  var root = document.documentElement;

  function stored() {
    try { return localStorage.getItem(KEY); } catch (e) { return null; }
  }
  function systemTheme() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function current() {
    return root.getAttribute('data-theme') || stored() || systemTheme();
  }
  function paintButtons(theme) {
    var dark = theme === 'dark';
    var buttons = document.querySelectorAll('[data-theme-toggle]');
    for (var i = 0; i < buttons.length; i++) {
      var b = buttons[i];
      b.setAttribute('aria-pressed', String(dark));
      b.setAttribute('aria-label', dark ? 'Mudar para o tema claro' : 'Mudar para o tema escuro');
      b.title = b.getAttribute('aria-label');
      var icon = b.querySelector('i');
      if (icon) icon.className = dark ? 'bi bi-sun' : 'bi bi-moon-stars';
    }
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', dark ? '#0c1210' : '#f5f6f2');
  }
  function apply(theme, save) {
    root.setAttribute('data-theme', theme);
    if (save) { try { localStorage.setItem(KEY, theme); } catch (e) {} }
    paintButtons(theme);
    window.dispatchEvent(new CustomEvent('themechange', { detail: { theme: theme } }));
  }

  root.setAttribute('data-theme', current());

  window.AppTheme = {
    get: current,
    set: function (t) { apply(t, true); },
    toggle: function () { apply(current() === 'dark' ? 'light' : 'dark', true); }
  };

  document.addEventListener('DOMContentLoaded', function () {
    paintButtons(current());
    document.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('[data-theme-toggle]');
      if (btn) window.AppTheme.toggle();
    });
  });
})();
