/* kostlim-settings.js — «Ключи и API» в стиле iOS: менеджер нескольких ключей, маскирование, копирование,
   статусы сервисов, тумблеры/сегменты, отправка только изменённых полей. Без зависимостей. */
(function () {
  'use strict';
  var form = document.getElementById('api-keys-form');
  if (!form) return;
  var saveBtn = document.getElementById('keys-submit-btn'), dirtyLbl = document.getElementById('ios-dirty');
  var LABEL = { online: 'Онлайн', invalid: 'Ключ неверный', error: 'Ошибка подключения', unset: 'Не настроено', unknown: 'Не проверено', busy: 'Проверяю…' };

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(m, ok) { if (typeof window.showToast === 'function') window.showToast(m, ok ? 'success' : 'error', 5000); }
  function setDot(dot, st) { dot.className = 'ios-dot ' + st; dot.title = LABEL[st] || ''; }
  function copy(text, btn) {
    var done = function () { var t = btn.textContent; btn.textContent = '✓'; setTimeout(function () { btn.textContent = t; }, 900); };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, done);
    else { var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (e) {} ta.remove(); done(); }
  }
  function post(svc, data) {
    var fd = new FormData(); fd.append('service', svc);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch('api_status.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).catch(function () { return { state: 'error', msg: 'Нет связи с сервером' }; });
  }

  /* ── менеджер нескольких ключей ── */
  function Keyset(box) {
    var st = JSON.parse(box.getAttribute('data-state') || '{"active":0,"keys":[]}');
    var list = box.querySelector('.ios-keylist'), svc = box.getAttribute('data-svc'), setKey = box.getAttribute('data-keyset');
    var hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = setKey; box.appendChild(hidden);
    if (!st.keys.length) st.keys.push({ v: '', on: true, label: '' });
    function sync() {
      var clean = { active: st.active, keys: st.keys.map(function (k) { return { v: k.v, on: k.on, label: k.label || '' }; }) };
      hidden.value = JSON.stringify(clean); markDirty();
    }
    function draw() {
      list.innerHTML = '';
      st.keys.forEach(function (k, i) {
        var row = document.createElement('div'); row.className = 'ios-key' + (i === st.active ? ' active' : '') + (k.on === false ? ' off' : '');
        row.innerHTML =
          '<button type="button" class="ios-radio" title="Сделать активным" aria-label="Активный"></button>' +
          '<div class="ios-key-main"><input class="ios-in ios-mask" type="text" value="' + esc(k.v) + '" placeholder="Вставь ключ" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">' +
          '<div class="ios-key-meta"><span class="ios-badge">' + (i === st.active ? 'Активный' : 'Резерв') + '</span>' +
          (k.src === 'env' ? '<span class="ios-badge env">из окружения</span>' : '') +
          '<span class="ios-st-msg"></span></div></div>' +
          '<span class="ios-dot ' + (k.v ? 'unknown' : 'unset') + '" data-dot></span>' +
          '<button type="button" class="ios-mini" data-eye title="Показать / скрыть">👁</button>' +
          '<button type="button" class="ios-mini" data-copy title="Копировать">⧉</button>' +
          '<button type="button" class="ios-mini" data-test title="Проверить">⟳</button>' +
          '<button type="button" class="ios-mini danger" data-del title="Удалить">🗑</button>';
        var inp = row.querySelector('input');
        inp.addEventListener('input', function () { k.v = inp.value.trim(); delete k.src; setDot(row.querySelector('[data-dot]'), k.v ? 'unknown' : 'unset'); sync(); });
        row.querySelector('.ios-radio').onclick = function () { st.active = i; k.on = true; draw(); sync(); };
        row.querySelector('[data-eye]').onclick = function () { inp.classList.toggle('ios-unmask'); };
        row.querySelector('[data-copy]').onclick = function (e) { if (k.v) copy(k.v, e.currentTarget); };
        row.querySelector('[data-test]').onclick = function () { test(row, k); };
        row.querySelector('[data-del]').onclick = function () {
          if (st.keys.length === 1) { k.v = ''; } else { st.keys.splice(i, 1); if (st.active >= st.keys.length) st.active = 0; else if (i < st.active) st.active--; }
          draw(); sync();
        };
        list.appendChild(row);
      });
    }
    function test(row, k) {
      var dot = row.querySelector('[data-dot]'), msg = row.querySelector('.ios-st-msg');
      if (!k.v) { setDot(dot, 'unset'); msg.textContent = LABEL.unset; return Promise.resolve(); }
      setDot(dot, 'busy'); msg.textContent = LABEL.busy;
      return post(svc, { key: k.v }).then(function (r) { setDot(dot, r.state); msg.textContent = r.msg || LABEL[r.state] || ''; msg.className = 'ios-st-msg ' + r.state; });
    }
    box.querySelector('[data-addkey]').onclick = function () { st.keys.push({ v: '', on: true, label: '' }); draw(); sync(); var ins = list.querySelectorAll('input'); ins[ins.length - 1].focus(); };
    box.querySelector('[data-checkall]').onclick = function () {
      var rows = list.querySelectorAll('.ios-key'), p = Promise.resolve();
      st.keys.forEach(function (k, i) { p = p.then(function () { return test(rows[i], k); }); });
    };
    box._reset = function () { st = JSON.parse(box.getAttribute('data-orig')); if (!st.keys.length) st.keys.push({ v: '', on: true, label: '' }); draw(); hidden.value = ''; };
    box._dirty = function () { return hidden.value !== '' && hidden.value !== JSON.stringify(normalize(JSON.parse(box.getAttribute('data-orig')))); };
    function normalize(d) { return { active: d.active || 0, keys: (d.keys || []).map(function (k) { return { v: k.v, on: k.on !== false, label: k.label || '' }; }) }; }
    draw(); // hidden остаётся пустым, пока ключи не меняли → в БД не пишем значения из окружения
  }
  Array.prototype.forEach.call(form.querySelectorAll('.ios-keys'), Keyset);

  /* ── обычные поля: маска, копирование ── */
  form.addEventListener('click', function (e) {
    var eye = e.target.closest('.ios-ctl > [data-eye]'), cp = e.target.closest('.ios-ctl > [data-copy]');
    if (eye) { var i = eye.parentNode.querySelector('input'); if (i) i.classList.toggle('ios-unmask'); }
    if (cp) { var f = cp.parentNode.querySelector('input'); if (f && f.value) copy(f.value, cp); }
    var sg = e.target.closest('.ios-seg button');
    if (sg) {
      var seg = sg.parentNode; Array.prototype.forEach.call(seg.children, function (b) { b.classList.toggle('on', b === sg); });
      var hid = seg.parentNode.querySelector('input[type=hidden]'); hid.value = sg.getAttribute('data-v'); markDirty();
    }
    var chk = e.target.closest('[data-check]');
    if (chk) {
      var row = chk.closest('.ios-row'), dot = row.querySelector('[data-dot]'), msg = row.querySelector('.ios-st-msg');
      var map = JSON.parse(chk.getAttribute('data-fields')), data = {};
      Object.keys(map).forEach(function (k) { var inp = form.querySelector('[name="' + map[k] + '"]'); data[k] = inp ? inp.value : ''; });
      setDot(dot, 'busy'); msg.textContent = LABEL.busy; msg.className = 'ios-st-msg';
      post(chk.getAttribute('data-check'), data).then(function (r) { setDot(dot, r.state); msg.textContent = r.msg || LABEL[r.state]; msg.className = 'ios-st-msg ' + r.state; });
    }
  });
  form.addEventListener('input', markDirty); form.addEventListener('change', markDirty);

  /* ── изменения и сохранение (отправляем ТОЛЬКО изменённое) ── */
  function changed() {
    var out = {};
    Array.prototype.forEach.call(form.querySelectorAll('input[name],select[name]'), function (el) {
      if (el.name.indexOf('KEYSET_') === 0) return;
      if ((el.getAttribute('data-orig') || '') !== el.value) out[el.name] = el.value;
    });
    Array.prototype.forEach.call(form.querySelectorAll('[data-bool]'), function (el) {
      var now = el.checked ? '1' : '0'; if (el.getAttribute('data-orig') !== now) out[el.getAttribute('data-bool')] = now;
    });
    Array.prototype.forEach.call(form.querySelectorAll('.ios-keys'), function (b) { if (b._dirty && b._dirty()) out[b.getAttribute('data-keyset')] = b.querySelector('input[type=hidden]').value; });
    return out;
  }
  function markDirty() {
    var n = Object.keys(changed()).length;
    dirtyLbl.textContent = n ? 'Изменено: ' + n : 'Изменений нет'; saveBtn.disabled = !n; saveBtn.classList.toggle('ready', !!n);
  }
  window.saveApiKeys = function () {
    var ch = changed(); if (!Object.keys(ch).length) return;
    var fd = new FormData(); fd.append('save_api_keys', '1');
    Object.keys(ch).forEach(function (k) { fd.append(k, ch[k]); });
    saveBtn.disabled = true; saveBtn.textContent = 'Сохраняю…';
    fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        toast(d.msg || 'Готово', !!d.ok);
        if (d.ok) {
          Array.prototype.forEach.call(form.querySelectorAll('input[name],select[name]'), function (el) { if (el.name.indexOf('KEYSET_') !== 0) el.setAttribute('data-orig', el.value); });
          Array.prototype.forEach.call(form.querySelectorAll('[data-bool]'), function (el) { el.setAttribute('data-orig', el.checked ? '1' : '0'); });
          Array.prototype.forEach.call(form.querySelectorAll('.ios-keys'), function (b) { var h = b.querySelector('input[type=hidden]'); if (h.value) b.setAttribute('data-orig', h.value); });
        }
      })
      .catch(function () { toast('❌ Ошибка соединения.', false); })
      .then(function () { saveBtn.textContent = 'Сохранить'; markDirty(); });
  };
  saveBtn.addEventListener('click', window.saveApiKeys);
  markDirty();
})();
