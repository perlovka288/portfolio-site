/* kostlim-analytics.js — виджеты аналитики в админке: «Онлайн» (живой), метрики с динамикой и график
   (плавная линия + область, свой тултип). Без библиотек, SVG. */
(function () {
  'use strict';
  var root = document.getElementById('kuiAn');
  if (!root) return;
  var $ = function (id) { return document.getElementById(id); };
  var NS = 'http://www.w3.org/2000/svg', range = '7d', data = null, tipEl = null;
  var PAGES = { '/': 'Главная', '/index.php': 'Главная', '/price.php': 'Прайс', '/order.php': 'Заказ', '/profile.php': 'Профиль', '/privat_pak.php': 'Приват Пак', '/support.php': 'Поддержка', '/useful.php': 'Полезное' };
  var fmt = function (n) { return Number(n || 0).toLocaleString('ru-RU'); };

  function get(q) { return fetch('analytics_api.php?' + q, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { if (!r.ok) throw 0; return r.json(); }); }
  function delta(el, v, unit) {
    if (v === null || v === undefined) { el.className = 'kui-delta'; el.textContent = '—'; el.title = 'Нет данных за прошлый период'; return; }
    var up = v >= 0; el.className = 'kui-delta ' + (v === 0 ? '' : up ? 'up' : 'down');
    el.textContent = (v === 0 ? '' : up ? '▲ +' : '▼ ') + String(v).replace('.', ',') + (unit || '%'); el.title = 'К предыдущему периоду';
  }
  function renderOnline(o) {
    $('anOnline').textContent = fmt(o.count);
    $('anOnlineSub').textContent = o.count === 1 ? 'человек сейчас на сайте' : 'сейчас на сайте';
    $('anTop').innerHTML = (o.top || []).map(function (t) { return '<span>' + (PAGES[t.path] || t.path) + ' · ' + t.n + '</span>'; }).join('');
  }
  function renderTotals(d) {
    $('anVisits').textContent = fmt(d.totals.visits); $('anUnique').textContent = fmt(d.totals.unique);
    $('anConv').textContent = String(d.totals.conversion).replace('.', ',') + '%';
    $('anOrders').textContent = fmt(d.totals.orders) + ' заказ(ов) за период';
    delta($('anVisitsD'), d.delta.visits); delta($('anUniqueD'), d.delta.unique); delta($('anConvD'), d.delta.conversion, ' п.п.');
  }

  /* ── график ── */
  function nice(max) {                       // верх оси кратен 4 и «круглый» → подписи 0, 10, 20, 30, 40
    var v = Math.max(1, max) / 4, p = Math.pow(10, Math.floor(Math.log10(v))), m = v / p;
    return Math.max(1, Math.ceil((m <= 1 ? 1 : m <= 2 ? 2 : m <= 5 ? 5 : 10) * p)) * 4;
  }
  function path(pts) {                       // плавная кривая с горизонтальными касательными — без «перелётов» ниже нуля
    var d = 'M' + pts[0][0] + ',' + pts[0][1];
    for (var i = 1; i < pts.length; i++) { var a = pts[i - 1], b = pts[i], xm = (a[0] + b[0]) / 2; d += ' C' + xm + ',' + a[1] + ' ' + xm + ',' + b[1] + ' ' + b[0] + ',' + b[1]; }
    return d;
  }
  function el(name, attrs, parent) { var e = document.createElementNS(NS, name); for (var k in attrs) e.setAttribute(k, attrs[k]); if (parent) parent.appendChild(e); return e; }

  function drawChart() {
    var box = $('anChart'); if (!data) return;
    var s = data.series, W = Math.max(box.clientWidth, 280), H = W < 480 ? 220 : 280, L = 34, R = 10, T = 12, B = 26;
    box.innerHTML = ''; box.style.height = H + 'px';
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, role: 'img', 'aria-label': 'График посещаемости' }, box);
    var max = nice(Math.max.apply(null, s.map(function (p) { return Math.max(p.visits, p.unique); }).concat([1])));
    var X = function (i) { return L + (s.length === 1 ? (W - L - R) / 2 : i * (W - L - R) / (s.length - 1)); };
    var Y = function (v) { return T + (1 - v / max) * (H - T - B); };

    var defs = el('defs', {}, svg), g = el('linearGradient', { id: 'anGrad', x1: 0, y1: 0, x2: 0, y2: 1 }, defs);
    el('stop', { offset: '0%', 'stop-color': '#f97316', 'stop-opacity': .38 }, g); el('stop', { offset: '100%', 'stop-color': '#f97316', 'stop-opacity': 0 }, g);
    for (var t = 0; t <= 4; t++) {            // сетка и подписи оси Y
      var yy = Y(max * t / 4);
      el('line', { x1: L, x2: W - R, y1: yy, y2: yy, class: 'an-grid' }, svg);
      var tx = el('text', { x: L - 6, y: yy + 4, class: 'an-axis', 'text-anchor': 'end' }, svg); tx.textContent = Math.round(max * t / 4);
    }
    var every = Math.max(1, Math.ceil(s.length / (W < 480 ? 5 : 8)));
    s.forEach(function (p, i) { if (i % every === 0 || i === s.length - 1) { var lt = el('text', { x: X(i), y: H - 7, class: 'an-axis', 'text-anchor': i === 0 ? 'start' : i === s.length - 1 ? 'end' : 'middle' }, svg); lt.textContent = p.label; } });

    var vp = s.map(function (p, i) { return [X(i), Y(p.visits)]; }), up = s.map(function (p, i) { return [X(i), Y(p.unique)]; });
    el('path', { d: path(vp) + ' L' + X(s.length - 1) + ',' + Y(0) + ' L' + X(0) + ',' + Y(0) + ' Z', fill: 'url(#anGrad)' }, svg);
    el('path', { d: path(vp), class: 'an-line' }, svg);
    el('path', { d: path(up), class: 'an-line2' }, svg);

    var guide = el('line', { class: 'an-guide', y1: T, y2: H - B, x1: 0, x2: 0, visibility: 'hidden' }, svg);
    var d1 = el('circle', { r: 4.5, class: 'an-dot', visibility: 'hidden' }, svg), d2 = el('circle', { r: 4, class: 'an-dot2', visibility: 'hidden' }, svg);
    if (!tipEl) { tipEl = document.createElement('div'); tipEl.className = 'kui-an-tip'; }
    box.appendChild(tipEl); tipEl.style.display = 'none';

    function show(ev) {
      var r = svg.getBoundingClientRect(), x = (ev.clientX - r.left) * (W / r.width);
      var i = Math.max(0, Math.min(s.length - 1, Math.round((x - L) / ((W - L - R) / Math.max(1, s.length - 1)))));
      var p = s[i], px = X(i);
      guide.setAttribute('x1', px); guide.setAttribute('x2', px); d1.setAttribute('cx', px); d1.setAttribute('cy', Y(p.visits)); d2.setAttribute('cx', px); d2.setAttribute('cy', Y(p.unique));
      [guide, d1, d2].forEach(function (n) { n.setAttribute('visibility', 'visible'); });
      tipEl.innerHTML = '<b>' + p.label + '</b><span><i style="background:var(--accent)"></i>Визиты <em>' + fmt(p.visits) + '</em></span><span><i style="background:#34c759"></i>Уникальные <em>' + fmt(p.unique) + '</em></span>';
      tipEl.style.display = 'block';
      var left = px * (r.width / W) + 12, tw = tipEl.offsetWidth; if (left + tw > r.width) left = px * (r.width / W) - tw - 12;
      tipEl.style.left = Math.max(0, left) + 'px'; tipEl.style.top = '8px';
    }
    function hide() { [guide, d1, d2].forEach(function (n) { n.setAttribute('visibility', 'hidden'); }); tipEl.style.display = 'none'; }
    svg.addEventListener('pointermove', show); svg.addEventListener('pointerdown', show); svg.addEventListener('pointerleave', hide);
  }

  function load() {
    return get('range=' + range).then(function (d) { data = d; renderOnline(d.online); renderTotals(d); drawChart(); })
      .catch(function () { $('anChart').innerHTML = '<div class="kui-an-empty">Не удалось загрузить статистику</div>'; });
  }
  $('anRange').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-r]'); if (!b) return; range = b.getAttribute('data-r');
    Array.prototype.forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); load();
  });
  var visible = function () { return document.visibilityState === 'visible' && root.offsetParent !== null; };
  setInterval(function () { if (visible()) get('online=1').then(function (d) { renderOnline(d.online); }).catch(function () {}); }, 15000);   // «онлайн» — живой
  setInterval(function () { if (visible()) load(); }, 120000);
  document.addEventListener('visibilitychange', function () { if (visible()) load(); });
  if (window.ResizeObserver) new ResizeObserver(function () { if (data && root.offsetParent) drawChart(); }).observe($('anChart'));
  new MutationObserver(function () { if (root.offsetParent) load(); }).observe(root.closest('.panel') || root, { attributes: true, attributeFilter: ['class'] });
  load();
})();
