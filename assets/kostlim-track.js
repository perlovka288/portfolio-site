/* kostlim-track.js — анонимная аналитика: просмотр страницы + пульс «я на сайте» раз в 30 сек. Без внешних сервисов. */
(function () {
  'use strict';
  if (window.__kuiTrack) return; window.__kuiTrack = true;
  var p = location.pathname;
  if (/^\/admin\//.test(p) || navigator.doNotTrack === '1') return;
  function send(hb) {
    var body = JSON.stringify({ p: p, hb: hb ? 1 : 0 });
    if (navigator.sendBeacon) { try { if (navigator.sendBeacon('/track.php', new Blob([body], { type: 'application/json' }))) return; } catch (e) {} }
    fetch('/track.php', { method: 'POST', body: body, keepalive: true, credentials: 'same-origin' }).catch(function () {});
  }
  send(false);
  setInterval(function () { if (document.visibilityState === 'visible') send(true); }, 30000);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') send(true); });
  addEventListener('pageshow', function (e) { if (e.persisted) send(false); });
})();
