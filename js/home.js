// Página inicial: mapas de prévia, passo a passo que acompanha a rolagem e menu do celular.
(function () {
  // Prévias com Google Maps (js/maps.js). Sem chave, maps.js mostra um aviso no lugar.
  if (window.AppMaps) {
    AppMaps.ready.then(function () {
      var still = {
        disableDefaultUI: true,
        gestureHandling: 'none',
        keyboardShortcuts: false,
        clickableIcons: false
      };

      var heroEl = document.getElementById('hero-map');
      if (heroEl) {
        var hero = AppMaps.create(heroEl, Object.assign({ center: { lat: -23.6, lng: -46.63 }, zoom: 11 }, still));
        AppMaps.marker(hero, { lat: -23.565, lng: -46.615 }, 'alagamento', { clickable: false });
        AppMaps.marker(hero, { lat: -23.59, lng: -46.69 }, 'erosao', { clickable: false });
        var talude = AppMaps.marker(hero, { lat: -23.66, lng: -46.58 }, 'talude', { clickable: false });
        var iw = new google.maps.InfoWindow({
          content: '<div class="iw t-talude"><b>Queda de talude</b><br>Rodovia Anchieta, km 12</div>',
          headerDisabled: true,
          disableAutoPan: true
        });
        google.maps.event.addListenerOnce(hero, 'idle', function () { iw.open({ map: hero, anchor: talude }); });
      }

      var stepsEl = document.getElementById('steps-map');
      if (stepsEl) AppMaps.create(stepsEl, Object.assign({ center: { lat: -23.66, lng: -46.58 }, zoom: 15 }, still));
    }).catch(function () {});
  }

  // Passo a passo: o passo ativo segue a posição da rolagem dentro da seção
  var sec = document.getElementById('como-funciona');
  var visual = document.getElementById('steps-visual');
  var items = document.querySelectorAll('#steps li');
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
  var narrow = window.matchMedia('(max-width: 900px)');

  function setStep(n) {
    visual.className = 'steps-visual step-' + n;
    items.forEach(function (li) { li.classList.toggle('is-active', Number(li.dataset.step) === n); });
  }

  var ticking = false;
  function onScroll() {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(function () {
      ticking = false;
      if (reduce.matches || narrow.matches) { setStep(3); return; }
      var r = sec.getBoundingClientRect();
      var total = sec.offsetHeight - window.innerHeight;
      var p = total > 0 ? Math.min(1, Math.max(0, -r.top / total)) : 1;
      setStep(p < 0.33 ? 1 : p < 0.66 ? 2 : 3);
    });
  }
  if (sec && visual) {
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    onScroll();
  }

  // Menu do celular
  var btn = document.querySelector('.menu-btn');
  var menu = document.getElementById('menu-mobile');
  function closeMenu() {
    menu.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
    btn.setAttribute('aria-label', 'Abrir menu');
    btn.querySelector('i').className = 'bi bi-list';
    document.body.style.overflow = '';
  }
  if (btn && menu) {
    btn.addEventListener('click', function () {
      var open = menu.hidden;
      if (!open) { closeMenu(); return; }
      menu.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
      btn.setAttribute('aria-label', 'Fechar menu');
      btn.querySelector('i').className = 'bi bi-x-lg';
      document.body.style.overflow = 'hidden';
    });
    menu.addEventListener('click', function (e) { if (e.target.closest('a')) closeMenu(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !menu.hidden) { closeMenu(); btn.focus(); } });
    narrow.addEventListener('change', function (e) { if (!e.matches) closeMenu(); });
  }
})();
