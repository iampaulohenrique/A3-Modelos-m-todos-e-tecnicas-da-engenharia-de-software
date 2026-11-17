// =========================================================
// Google Maps: carregamento, estilos claro/escuro e marcadores.
// Usado por index.html (mapa) e home.html (prévias).
// A chave fica em js/config.js.
// =========================================================
(function () {
  var cfg = window.APP_CONFIG || {};
  var key = (cfg.GOOGLE_MAPS_API_KEY || '').trim();

  // Estilo claro: base neutra para os pinos coloridos se destacarem
  var LIGHT = [
    { elementType: 'geometry', stylers: [{ color: '#f2f3ef' }] },
    { elementType: 'labels.text.fill', stylers: [{ color: '#525f58' }] },
    { elementType: 'labels.text.stroke', stylers: [{ color: '#ffffff' }] },
    { featureType: 'poi', elementType: 'labels', stylers: [{ visibility: 'off' }] },
    { featureType: 'poi.park', elementType: 'geometry', stylers: [{ color: '#dcebd9' }] },
    { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#ffffff' }] },
    { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#e6e1d3' }] },
    { featureType: 'road.highway', elementType: 'geometry.stroke', stylers: [{ color: '#d6cfbd' }] },
    { featureType: 'transit', stylers: [{ visibility: 'simplified' }] },
    { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#c4dceb' }] }
  ];
  // Estilo escuro
  var DARK = [
    { elementType: 'geometry', stylers: [{ color: '#151d1a' }] },
    { elementType: 'labels.text.fill', stylers: [{ color: '#98a69f' }] },
    { elementType: 'labels.text.stroke', stylers: [{ color: '#0c1210' }] },
    { featureType: 'administrative', elementType: 'geometry', stylers: [{ color: '#34433c' }] },
    { featureType: 'poi', elementType: 'labels', stylers: [{ visibility: 'off' }] },
    { featureType: 'poi', elementType: 'geometry', stylers: [{ color: '#18221e' }] },
    { featureType: 'poi.park', elementType: 'geometry', stylers: [{ color: '#16271f' }] },
    { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#24302b' }] },
    { featureType: 'road', elementType: 'geometry.stroke', stylers: [{ color: '#1a2420' }] },
    { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#33423b' }] },
    { featureType: 'road.highway', elementType: 'labels.text.fill', stylers: [{ color: '#c3cfc8' }] },
    { featureType: 'transit', elementType: 'geometry', stylers: [{ color: '#1f2925' }] },
    { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#0e2230' }] },
    { featureType: 'water', elementType: 'labels.text.fill', stylers: [{ color: '#4f6d80' }] }
  ];

  function theme() { return window.AppTheme ? window.AppTheme.get() : 'light'; }
  function cssVar(name, fallback) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
  }
  function typeColor(tipo) {
    return cssVar('--' + tipo, { alagamento: '#0b6fb0', erosao: '#a35a00', talude: '#c42b2b' }[tipo] || '#0d6b63');
  }

  // Pino circular na cor do tipo (borda na cor da superfície do tema)
  function pinIcon(tipo, scale) {
    return {
      path: google.maps.SymbolPath.CIRCLE,
      scale: scale || 8,
      fillColor: typeColor(tipo),
      fillOpacity: 1,
      strokeColor: cssVar('--surface', '#ffffff'),
      strokeWeight: 3
    };
  }

  var maps = [];      // mapas criados, para trocar o estilo junto com o tema
  var markers = [];   // { marker, tipo, scale } para recolorir no tema

  function showMessage(el, html) {
    if (!el) return;
    var box = document.createElement('div');
    box.className = 'map-message';
    box.setAttribute('role', 'status');
    box.innerHTML = html;
    el.appendChild(box);
  }

  var resolveReady, rejectReady;
  var ready = new Promise(function (res, rej) { resolveReady = res; rejectReady = rej; });

  window.AppMaps = {
    ready: ready,
    hasKey: !!key,
    // cria um mapa no elemento; opts extras vão direto para google.maps.Map
    create: function (el, opts) {
      var map = new google.maps.Map(el, Object.assign({
        center: cfg.MAP_CENTER || { lat: -23.5505, lng: -46.6333 },
        zoom: 10,
        styles: theme() === 'dark' ? DARK : LIGHT,
        backgroundColor: cssVar('--surface-2', '#eceee8'),
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: false,
        clickableIcons: false
      }, opts || {}));
      maps.push(map);
      return map;
    },
    marker: function (map, position, tipo, extra) {
      var scale = (extra && extra.scale) || 8;
      var m = new google.maps.Marker(Object.assign({
        map: map,
        position: position,
        icon: pinIcon(tipo, scale)
      }, extra || {}));
      markers.push({ marker: m, tipo: tipo, scale: scale });
      return m;
    },
    forget: function (m) {
      markers = markers.filter(function (x) { return x.marker !== m; });
    },
    pinIcon: pinIcon,
    cssVar: cssVar,
    showMessage: showMessage
  };

  // Troca de tema: estilo do mapa e cor dos pinos (as cores mudam entre os temas)
  window.addEventListener('themechange', function (e) {
    if (!window.google || !google.maps) return;
    var styles = e.detail.theme === 'dark' ? DARK : LIGHT;
    // espera o CSS aplicar as novas variáveis antes de ler as cores
    requestAnimationFrame(function () {
      maps.forEach(function (m) { m.setOptions({ styles: styles, backgroundColor: cssVar('--surface-2', '#eceee8') }); });
      markers.forEach(function (x) { x.marker.setIcon(pinIcon(x.tipo, x.scale)); });
    });
  });

  // Sem chave: avisa no lugar do mapa, sem quebrar o resto da página
  if (!key) {
    document.addEventListener('DOMContentLoaded', function () {
      document.querySelectorAll('[data-map]').forEach(function (el) {
        showMessage(el, '<i class="bi bi-key" aria-hidden="true"></i><strong>Mapa sem chave da Google</strong><span>Cole a chave da Maps JavaScript API em <code>js/config.js</code>.</span>');
      });
    });
    rejectReady(new Error('GOOGLE_MAPS_API_KEY vazia em js/config.js'));
    ready.catch(function () {});
    return;
  }

  // Chave recusada (inválida, sem faturamento ou domínio não liberado)
  window.gm_authFailure = function () {
    document.querySelectorAll('[data-map]').forEach(function (el) {
      showMessage(el, '<i class="bi bi-exclamation-triangle" aria-hidden="true"></i><strong>A Google recusou a chave do mapa</strong><span>Confira no Google Cloud se a Maps JavaScript API está ativada, se o faturamento está ligado e se este endereço está liberado na chave.</span>');
    });
  };

  window.__appMapsLoaded = function () {
    Promise.all([
      google.maps.importLibrary('maps'),
      google.maps.importLibrary('marker'),
      google.maps.importLibrary('geocoding')
    ]).then(function () { resolveReady(google.maps); }, rejectReady);
  };

  var s = document.createElement('script');
  s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) +
    '&loading=async&callback=__appMapsLoaded&language=pt-BR&region=BR';
  s.async = true;
  s.onerror = function () {
    document.querySelectorAll('[data-map]').forEach(function (el) {
      showMessage(el, '<i class="bi bi-wifi-off" aria-hidden="true"></i><strong>Não foi possível carregar o Google Maps</strong><span>Verifique a conexão com a internet.</span>');
    });
    rejectReady(new Error('falha ao carregar o script do Google Maps'));
  };
  document.head.appendChild(s);
  ready.catch(function () {});
})();
