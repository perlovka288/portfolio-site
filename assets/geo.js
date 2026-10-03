/* assets/geo.js — при первом заходе ставит валюту по стране. Подключать на всех страницах с ценами. */
(function () {
  var SITE = { UAH: 'UAH', RUB: 'RUB', USD: 'USD', KZT: 'RUB', EUR: 'USD' }; // что умеет switchCurrency на сайте
  function apply(cur) {
    try { localStorage.setItem('currency', cur); } catch (e) {}
    if (typeof window.switchCurrency === 'function') window.switchCurrency(SITE[cur] || 'USD');
  }
  var saved = null;
  try { saved = localStorage.getItem('currency'); } catch (e) {}
  if (saved) { document.addEventListener('DOMContentLoaded', function () { apply(saved); }); return; }
  fetch('/geoip.php', { cache: 'no-store' }).then(function (r) { return r.json(); })
    .then(function (j) { document.addEventListener('DOMContentLoaded', function () { apply(j.currency || 'USD'); });
                         if (document.readyState !== 'loading') apply(j.currency || 'USD'); })
    .catch(function () {});
})();
