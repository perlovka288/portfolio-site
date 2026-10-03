/* assets/geo.js — если сервер не смог определить страну сам (нет Cloudflare), узнаём по IP через /geoip.php и ставим цены региона. */
(function () {
  if (window.__geoKnown) return;
  fetch('/geoip.php', { cache: 'no-store' }).then(function (r) { return r.json(); })
    .then(function (j) { if (typeof window.switchCurrency === 'function') window.switchCurrency(j.currency || 'USD'); })
    .catch(function () {});
})();
