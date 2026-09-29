/* kostlim-ui.js — шторка «Ещё», свайп между фильтрами, анимация смены. Без зависимостей. */
(function () {
  'use strict';
  var more = document.getElementById('kuiMore');
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-kui-more]')) { if (more) more.classList.add('open'); return; }
    if (more && (e.target === more || e.target.closest('.kui-more-row'))) more.classList.remove('open');
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && more) more.classList.remove('open'); });

  var tabsBox = document.getElementById('kuiTabs');
  if (!tabsBox) return;                       // остальное — только на странице с фильтрами
  var tabs = Array.prototype.slice.call(tabsBox.querySelectorAll('.tab-btn'));
  var grid = document.querySelector('.portfolio-grid');
  function cur() { for (var i = 0; i < tabs.length; i++) if (tabs[i].classList.contains('active')) return i; return 0; }
  var prev = cur();

  function animate(dir) {
    if (!grid) return;
    grid.classList.remove('kui-sl', 'kui-sr'); void grid.offsetWidth;
    grid.classList.add(dir > 0 ? 'kui-sl' : 'kui-sr');
  }
  // клик по названию: старый onclick (filterPortfolio) отработает сам, мы только анимируем
  tabsBox.addEventListener('click', function () {
    setTimeout(function () {
      var n = cur();
      if (n !== prev) { animate(n - prev); prev = n; }
      try { tabs[n].scrollIntoView({ inline: 'center', block: 'nearest' }); } catch (e) {}
    }, 0);
  });
  function go(d) { var n = cur() + d; if (n >= 0 && n < tabs.length) tabs[n].click(); }

  var sx = 0, sy = 0, ok = false, skip = '.kui-tabs,.kui-nav,.kui-more,#reviews,input,textarea,select,.modal-overlay,#ai-widget-root';
  document.addEventListener('touchstart', function (e) {
    ok = !e.target.closest(skip); sx = e.touches[0].clientX; sy = e.touches[0].clientY;
  }, { passive: true });
  document.addEventListener('touchend', function (e) {
    if (!ok) return;
    var dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy;
    if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) go(dx < 0 ? 1 : -1);
  }, { passive: true });
  document.addEventListener('keydown', function (e) {
    if (/INPUT|TEXTAREA|SELECT/.test(e.target.tagName)) return;
    if (e.key === 'ArrowRight') go(1); else if (e.key === 'ArrowLeft') go(-1);
  });
})();
