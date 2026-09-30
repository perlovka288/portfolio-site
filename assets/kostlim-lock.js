/* kostlim-lock.js — пока открыто любое окно/шторка: страница «замирает» (не скроллится и не двигается),
   двигается только содержимое самого окна. Работает для всех модалок сайта и админки без правки их кода:
   следит за появлением полноэкранных fixed-оверлеев. */
(function () {
  'use strict';
  if (window.__kuiLock) return; window.__kuiLock = true;
  var html = document.documentElement, locked = false, y = 0, raf = 0;
  var CAND = '[id*="modal" i],[class*="modal" i],[class*="overlay" i],[class*="drawer" i],[class~="ai"],.kui-more';
  var SKIP = '.kui-legacy,.kui-nav,.kui-side,.kui-top,.knav-loader,#knav-loader,.kui-glass';

  function isOverlayOpen() {
    var list = document.querySelectorAll(CAND), vw = innerWidth, vh = innerHeight;
    for (var i = 0; i < list.length; i++) {
      var el = list[i]; if (el.closest(SKIP)) continue;
      var cs = getComputedStyle(el);
      if (cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden') continue;
      if (parseFloat(cs.opacity) < 0.05 || cs.pointerEvents === 'none') continue;
      var r = el.getBoundingClientRect();
      if (r.width >= vw * 0.6 && r.height >= vh * 0.6) return true;   // полноэкранный оверлей
    }
    return false;
  }
  function lock() {
    if (locked) return; locked = true; y = window.pageYOffset || html.scrollTop || 0;
    html.classList.add('kui-lock'); document.body.style.top = (-y) + 'px';
  }
  function unlock() {
    if (!locked) return; locked = false;
    html.classList.remove('kui-lock'); document.body.style.top = '';
    window.scrollTo(0, y);
  }
  function check() { raf = 0; (isOverlayOpen() ? lock : unlock)(); }
  function schedule() { if (!raf) raf = requestAnimationFrame(check); }

  function start() {
    new MutationObserver(schedule).observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'style', 'hidden'] });
    document.addEventListener('transitionend', schedule, true);
    window.addEventListener('resize', schedule); window.addEventListener('pageshow', function () { unlock(); schedule(); });
    schedule();
  }
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
})();
