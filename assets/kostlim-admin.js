/* kostlim-admin.js — нижнее меню/шторка админки из существующих вкладок .admin-tab. Без зависимостей. */
(function () {
  'use strict';
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.admin-tab'));
  var nav = document.getElementById('kuiAdminNav'), more = document.getElementById('kuiMore'), sheet = document.getElementById('kuiAdminMore');
  if (!tabs.length || !nav) return;

  function info(el) {
    var svg = el.querySelector('svg');
    return { el: el, key: el.dataset.tab || '', href: el.getAttribute('href') || '', icon: svg ? svg.outerHTML : '',
             label: (el.textContent || '').replace(/\s+/g, ' ').trim() };
  }
  var all = tabs.map(info);
  var by = function (k) { return all.filter(function (t) { return t.key === k; })[0]; };
  var main = ['overview', 'orders', null, 'portfolio'].map(function (k) { return k && by(k); });
  var used = main.filter(Boolean).map(function (t) { return t.key; });

  function open(t) {
    if (t.key && typeof window.activateAdminTab === 'function') window.activateAdminTab(t.key);
    else if (t.href) { location.href = t.href; return; }
    window.scrollTo({ top: 0, behavior: 'smooth' });
    sync();
  }
  function btn(t) {
    var b = document.createElement('button'); b.type = 'button'; b.dataset.k = t.key;
    b.innerHTML = t.icon + '<span>' + t.label + '</span>'; b.onclick = function () { open(t); }; return b;
  }
  // нижнее меню: Обзор · Заказы · [профиль] · Портфолио · Ещё
  main.forEach(function (t) {
    if (t) { nav.appendChild(btn(t)); return; }
    var me = document.querySelector('.kui-admin-badge img');
    var a = document.createElement('a'); a.className = 'kui-me'; a.href = 'profile.php';
    a.innerHTML = '<img src="' + (me ? me.src : '/assets/img/logo.png') + '" alt=""><em>ADMIN</em>'; nav.appendChild(a);
  });
  var mb = document.createElement('button'); mb.type = 'button'; mb.dataset.k = '__more';
  mb.innerHTML = '<svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg><span>Ещё</span>';
  mb.onclick = function () { more.classList.add('open'); }; nav.appendChild(mb);

  // шторка «Ещё»: остальные вкладки с подписями групп
  var grp = '';
  Array.prototype.slice.call(document.querySelectorAll('.admin-tabs > *')).forEach(function (n) {
    if (n.classList.contains('admin-tab-group-label')) { grp = n.textContent.trim(); var h = document.createElement('div'); h.className = 'kui-more-group'; h.textContent = grp; h.dataset.g = grp; sheet.appendChild(h); return; }
    var t = info(n); if (used.indexOf(t.key) > -1 && t.key) return;
    var r = document.createElement('button'); r.type = 'button'; r.className = 'kui-more-row'; r.dataset.k = t.key;
    r.innerHTML = '<span>' + t.icon + t.label + '</span><small>›</small>';
    r.onclick = function () { more.classList.remove('open'); open(t); }; sheet.appendChild(r);
  });
  // убираем пустые заголовки групп
  Array.prototype.slice.call(sheet.querySelectorAll('.kui-more-group')).forEach(function (g) {
    if (!g.nextElementSibling || g.nextElementSibling.classList.contains('kui-more-group')) g.remove();
  });
  var back = document.createElement('a'); back.className = 'kui-more-row'; back.href = '../index.php';
  back.innerHTML = '<span>← Вернуться на сайт</span><small>›</small>'; sheet.appendChild(back);

  more.addEventListener('click', function (e) { if (e.target === more) more.classList.remove('open'); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') more.classList.remove('open'); });

  function sync() {
    var cur = (tabs.filter(function (b) { return b.classList.contains('active'); })[0] || {}).dataset;
    cur = cur ? cur.tab : '';
    Array.prototype.slice.call(nav.querySelectorAll('button')).forEach(function (b) {
      var on = b.dataset.k === cur || (b.dataset.k === '__more' && cur && used.indexOf(cur) < 0);
      b.classList.toggle('on', on);
    });
  }
  var mo = new MutationObserver(sync);
  tabs.forEach(function (b) { mo.observe(b, { attributes: true, attributeFilter: ['class'] }); });
  sync();
})();
