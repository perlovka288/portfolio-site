/* kostlim-nav.js — «мгновенные» переходы между разделами.
   1) При входе на сайт ВСЕ разделы из меню прогреваются в фоне (по 2 параллельно) и ложатся в кэш
      браузера (серверная часть — includes/ui_cache.php). Клик по разделу открывает уже готовую страницу.
   2) Пока человек активен, кэш освежается сам (раз в ~40 сек, только устаревшее), на скрытой вкладке — пауза.
   3) Клик → сразу полоса загрузки + нажатие пункта; анимацию «стекла» делают View Transitions (CSS).
   Прогревающие запросы идут с заголовком X-Kui-Warm — сервер читает сессию без блокировки,
   поэтому они не задерживают настоящий переход. */
(function () {
  'use strict';
  if (window.__kuiNav) return; window.__kuiNav = true;
  var conn = navigator.connection || {};
  if (conn.saveData || /2g/.test(conn.effectiveType || '')) return;          // экономия трафика — не прогреваем

  var LONG = /(^|\/)(index|price|useful|support)(\.php)?$/i;                  // кэшируются на 60 сек
  function ttl(u) { return (u.pathname === '/' || LONG.test(u.pathname)) ? 55 : 25; }
  var last = {}, busy = {}, queue = [], running = 0, lastAct = Date.now();

  function sameOrigin(href) { try { var u = new URL(href, location.href); return u.origin === location.origin ? u : null; } catch (e) { return null; } }
  function targets() {
    var seen = {}, out = [];
    Array.prototype.forEach.call(document.querySelectorAll('.kui-top a[href],.kui-nav a[href],.kui-side a[href],.kui-more a[href],.kui-hero-btns a[href],.kui-promo a[href]'), function (a) {
      var u = sameOrigin(a.getAttribute('href')); if (!u || u.hash && !u.pathname) return;
      if (/^\/admin\//.test(u.pathname) || /\.(png|jpe?g|webp|gif|svg|css|js|zip)$/i.test(u.pathname)) return;
      u.hash = '';
      if (u.pathname === location.pathname && u.search === location.search) return;     // текущую не грузим
      var k = u.pathname + u.search; if (seen[k]) return; seen[k] = 1; out.push(u);
    });
    return out;
  }
  function fetchOne(u, done) {
    var k = u.pathname + u.search; busy[k] = 1;
    fetch(u.href, { credentials: 'same-origin', headers: { 'X-Kui-Warm': '1' }, priority: 'low' })
      .then(function (r) { return r.ok ? r.text() : null; })
      .then(function (t) { if (t !== null) last[k] = Date.now(); })
      .catch(function () {})
      .then(function () { busy[k] = 0; done(); });
  }
  function pump() {
    while (running < 2 && queue.length) { running++; fetchOne(queue.shift(), function () { running--; pump(); }); }
  }
  function warm(u, force) {
    var k = u.pathname + u.search, age = Date.now() - (last[k] || 0);
    if (busy[k] || (!force && age < (ttl(u) - 8) * 1000)) return;
    if (queue.some(function (q) { return q.pathname + q.search === k; })) return;
    queue.push(u); pump();
  }
  function warmAll() { if (document.visibilityState === 'visible') targets().forEach(function (u) { warm(u); }); }

  // первый прогрев — сразу после загрузки страницы (не мешает первому показу)
  function start() { setTimeout(warmAll, 250); }
  if (document.readyState === 'complete') start(); else addEventListener('load', start);

  // поддерживаем свежесть, пока человек активен
  ['touchstart', 'pointerdown', 'scroll', 'mousemove', 'keydown'].forEach(function (ev) {
    addEventListener(ev, function () { lastAct = Date.now(); }, { passive: true, capture: true });
  });
  setInterval(function () { if (Date.now() - lastAct < 180000) warmAll(); }, 20000);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') setTimeout(warmAll, 150); });

  // точечный прогрев под пальцем/курсором — если кэш всё-таки успел протухнуть
  function hint(e) { var a = e.target.closest && e.target.closest('a[href]'); if (!a) return; var u = sameOrigin(a.getAttribute('href')); if (u && !/^\/admin\//.test(u.pathname)) { u.hash = ''; warm(u); } }
  addEventListener('touchstart', hint, { passive: true, capture: true });
  addEventListener('mouseover', hint, { passive: true, capture: true });

  // реакция на клик: полоса загрузки (снимается при показе страницы/возврате назад)
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || a.target === '_blank' || a.hasAttribute('download')) return;
    var x = sameOrigin(a.getAttribute('href')); if (!x || (x.pathname === location.pathname && x.search === location.search)) return;
    document.documentElement.classList.add('kui-loading');
  }, true);
  addEventListener('pageshow', function () { document.documentElement.classList.remove('kui-loading'); });
})();
