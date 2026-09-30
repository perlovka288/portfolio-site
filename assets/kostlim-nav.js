/* kostlim-nav.js — «мгновенные» переходы между разделами:
   1) прогрев: страницы разделов подгружаются в фоне и ложатся в кэш браузера (см. includes/ui_cache.php);
      пока человек активен на сайте, кэш обновляется — клик открывает страницу сразу;
   2) реакция на клик: тонкая полоса загрузки сверху + нажатие пункта меню;
   3) анимацию «стекла» делают cross-document View Transitions (CSS в kostlim-ui.css). */
(function () {
  'use strict';
  if (window.__kuiNav) return; window.__kuiNav = true;
  var conn = navigator.connection || {};
  if (conn.saveData) return;

  var WARM = ['index.php', 'price.php', 'useful.php', 'support.php'];       // кэшируются на сервере (ui_cache.php)
  var TTL = { 'index.php': 20, 'price.php': 60, 'useful.php': 60, 'support.php': 60 };
  var last = {}, inflight = {};
  var here = location.pathname.replace(/^\/+/, '') || 'index.php';
  if (/^admin\//.test(here)) WARM = ['index.php'];                            // из админки прогреваем «На сайт»

  function norm(u) { try { var x = new URL(u, location.href); return x.origin === location.origin ? x : null; } catch (e) { return null; } }
  function file(x) { return (x.pathname.replace(/^\/+/, '') || 'index.php'); }

  function warm(name) {
    var t = TTL[name] || 20, now = Date.now();
    if (inflight[name] || (last[name] && now - last[name] < (t - 5) * 1000)) return;
    if (name === here) return;
    inflight[name] = true;
    var url = (/^admin\//.test(here) ? '../' : '') + name;
    fetch(url, { credentials: 'same-origin', priority: 'low' })
      .then(function (r) { return r.text(); })
      .then(function () { last[name] = Date.now(); })
      .catch(function () {})
      .then(function () { inflight[name] = false; });
  }
  function warmAll() { if (document.visibilityState !== 'visible') return; WARM.forEach(function (n, i) { setTimeout(function () { warm(n); }, i * 350); }); }

  // первый прогрев — когда браузер свободен; дальше — при активности (не чаще раза в 6 сек)
  (window.requestIdleCallback || function (f) { setTimeout(f, 1200); })(warmAll, { timeout: 2500 });
  var thr = 0;
  ['touchstart', 'pointerdown', 'scroll', 'mousemove', 'keydown'].forEach(function (ev) {
    addEventListener(ev, function () { var n = Date.now(); if (n - thr > 6000) { thr = n; warmAll(); } }, { passive: true, capture: true });
  });
  // точечный прогрев под пальцем/курсором (на случай, если кэш успел протухнуть)
  function hint(e) { var a = e.target.closest && e.target.closest('a[href]'); if (!a) return; var x = norm(a.href); if (x && WARM.indexOf(file(x)) > -1) warm(file(x)); }
  addEventListener('touchstart', hint, { passive: true, capture: true });
  addEventListener('mouseover', hint, { passive: true, capture: true });

  // реакция на клик: полоса загрузки (снимается при показе страницы/возврате назад)
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || a.target === '_blank' || a.hasAttribute('download')) return;
    var x = norm(a.href); if (!x || (x.pathname === location.pathname && x.search === location.search)) return;
    document.documentElement.classList.add('kui-loading');
  }, true);
  addEventListener('pageshow', function () { document.documentElement.classList.remove('kui-loading'); });
})();
