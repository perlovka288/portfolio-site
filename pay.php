<?php
// pay.php?id=31&t=TOKEN — страница оплаты (кнопка «Оплатить на сайте» из Telegram)
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/pay_lib.php';
require_once __DIR__ . '/includes/geo.php';
ensurePaySchema($pdo);

$id = (int)($_GET['id'] ?? 0); $t = (string)($_GET['t'] ?? '');
$o  = payTokenOk($id, $t) ? payGetOrder($pdo, $id) : null;
if ($o && payIsPaid($o)) { header('Location: success.php?id=' . $id . '&t=' . $t); exit; }

$state = !$o ? 'bad' : (!payIsPayable($o) ? 'wait_accept' : 'ok');
$amounts = []; $title = ''; $initial = 'USD';
if ($state === 'ok') {
    $amounts = payAmounts($pdo, $o);
    try { $title = getOrderServiceTitle($pdo, $o); } catch (Throwable $e) {}
    $initial = currencyForCountry(detectCountry());
}
$methods = [];
foreach (payMethods() as $k => $m) { $m['ready'] = payMethodConfigured($k); $methods[$k] = $m; }
$cfg = ['id' => $id, 't' => $t, 'amounts' => $amounts, 'initial' => $initial, 'cur' => payCurrencies(), 'methods' => $methods,
        'title' => $title];
$botLink = 'https://t.me/kostlimdznbot';
$plus = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
?><!DOCTYPE html>
<html lang="ru"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Оплата заказа #<?= $id ?> | Kostlim Design</title>
<link rel="stylesheet" href="assets/pay.css?v=3">
</head><body>
<div class="pc">
<?php if ($state === 'bad'): ?>
  <div class="pc-head"><h3 class="pc-title">Ссылка недействительна</h3></div>
  <p class="note" style="text-align:left;margin:0 0 16px">Откройте оплату заново из сообщения в Telegram-боте.</p>
  <a class="btn" href="<?= $botLink ?>">Открыть бота</a>
<?php elseif ($state === 'wait_accept'): ?>
  <div class="pc-head"><h3 class="pc-title">Заказ #<?= $id ?> на проверке</h3></div>
  <p class="note" style="text-align:left;margin:0 0 16px">Дизайнер пока не принял ТЗ. Как только примет — вам придёт сообщение с кнопкой оплаты.</p>
  <a class="btn" href="profile.php">В профиль</a>
<?php else: ?>
  <div id="pickView">
    <div class="pc-head">
      <h3 class="pc-title">Выберите способ оплаты</h3>
      <a class="pc-action" href="<?= $botLink ?>" target="_blank" rel="noopener"><?= $plus ?>Отправить чек</a>
    </div>
    <div class="order-sum">
      <div><small>Заказ #<?= $id ?></small><div class="svc"><?= htmlspecialchars($title) ?></div></div>
      <div style="text-align:right"><small>К оплате</small><div class="tot" id="total">—</div></div>
    </div>
    <p class="sec-label">Валюта</p>
    <div class="cur-row" id="curRow"></div>
    <p class="sec-label">Способ</p>
    <div class="m-list" id="mList" role="radiogroup"></div>
    <p class="err" id="err"></p>
    <button class="btn" id="payBtn" type="button">Оплатить</button>
    <p class="note">Оплата подтверждается автоматически — чек прикреплять не нужно.</p>
  </div>

  <div class="wait" id="waitView">
    <div class="spin"></div>
    <h2>Ждём оплату…</h2>
    <p class="sub" id="waitHint">Оплатите в открывшемся окне. Эта страница обновится сама.</p>
    <div class="timer" id="timer">00:00</div>
    <ol class="steps"><li>Оплатите в открывшейся вкладке</li><li>Вернитесь на эту страницу</li><li>Мы сами увидим платёж и покажем чек</li></ol>
    <div class="copybox" id="copyBox">Сумма: <b id="wAmount"></b><br>Комментарий к платежу: <code id="wComment" title="Нажмите, чтобы скопировать"></code><br><small style="color:#9a9aa6">Комментарий нужен, чтобы платёж привязался к заказу.</small></div>
    <button class="btn ghost" id="reopen" type="button" style="margin-top:0">Открыть оплату ещё раз</button>
    <button class="btn ghost" id="checkNow" type="button">Проверить сейчас</button>
    <button class="btn ghost" id="back" type="button">← Выбрать другой способ</button>
    <p class="slow" id="slow">Платёж идёт долго? <a href="<?= $botLink ?>" target="_blank" rel="noopener">Отправьте чек в ТГ →</a></p>
  </div>
<?php endif; ?>
</div>

<?php if ($state === 'ok'): ?>
<script>
var D = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var LOGO = { monobank: 'mono', donationalerts: 'DA', cryptobot: '₮' };
var HINT = {
  monobank: 'Monobank отдаёт платежи по банке с задержкой — обычно 1–2 минуты. Ничего не закрывайте.',
  donationalerts: 'Проверяем платёж в DonationAlerts автоматически.',
  cryptobot: 'Проверяем счёт в Crypto Bot автоматически.'
};
var cur = D.initial, method = null, lastUrl = '', poll = null, firstRender = true, t0 = 0, tick = null;
try { var sv = localStorage.getItem('pay_cur'); if (sv && D.cur[sv]) cur = sv; } catch (e) {}
if (!D.cur[cur]) cur = 'USD';
function $(id) { return document.getElementById(id); }
function fmt(a, c) {
  var m = D.cur[c];
  if (c === 'USD' || c === 'EUR') return m.symbol + Number(a).toFixed(2);
  return Math.round(a).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ' + m.symbol;
}
function supports(k, c) { return D.methods[k].currencies.indexOf(c) !== -1; }
function firstReady(c) { var r = null; Object.keys(D.methods).forEach(function (k) { if (!r && D.methods[k].ready && supports(k, c)) r = k; }); return r; }

function render() {
  if (!method || !D.methods[method].ready || !supports(method, cur)) method = firstReady(cur);

  var row = $('curRow'); row.innerHTML = '';
  Object.keys(D.cur).forEach(function (c) {
    var b = document.createElement('button'); b.type = 'button';
    b.className = 'cur-chip' + (c === cur ? ' on' : '');
    b.innerHTML = '<span class="fl">' + D.cur[c].flag + '</span><b>' + c + '</b><span>' + D.cur[c].symbol + '</span>';
    b.onclick = function () { cur = c; try { localStorage.setItem('pay_cur', c); } catch (e) {} render(); };
    row.appendChild(b);
  });
  $('total').textContent = fmt(D.amounts[cur], cur);

  var list = $('mList'); list.innerHTML = ''; var i = 0;
  Object.keys(D.methods).forEach(function (k) {
    var m = D.methods[k], ok = supports(k, cur) && m.ready, sel = (k === method);
    var el = document.createElement('div');
    el.className = 'mc' + (sel ? ' on' : '') + (!m.ready ? ' off' : (!supports(k, cur) ? ' dim' : ''));
    el.setAttribute('role', 'radio'); el.setAttribute('aria-checked', sel ? 'true' : 'false'); el.tabIndex = 0;
    if (firstRender) el.style.animationDelay = (i * 80) + 'ms'; else el.style.animation = 'none';
    var tags = m.currencies.map(function (c) { return '<span class="tag' + (c === cur ? ' on' : '') + '">' + D.cur[c].symbol + ' ' + c + '</span>'; }).join('');
    el.innerHTML = '<div class="logo ' + k + '">' + LOGO[k] + '</div><div class="mc-body"><p class="mc-name">' + m.title + '</p><p class="mc-desc">' +
      (m.ready ? m.desc : 'Временно недоступно') + '</p><div class="tags">' + tags + '</div></div><div class="radio"></div>';
    var pick = function () {
      if (!m.ready) return;
      if (!supports(k, cur)) cur = m.currencies[0];   // нажали на способ, который не принимает текущую валюту — переключаем валюту
      method = k; render();
    };
    el.onclick = pick;
    el.onkeydown = function (e) { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); pick(); } };
    list.appendChild(el); i++;
  });
  firstRender = false;
  $('payBtn').disabled = !method;
  $('payBtn').textContent = method ? 'Оплатить ' + fmt(D.amounts[cur], cur) : 'Для этой валюты нет способа оплаты';
}
function showErr(t) { var e = $('err'); e.textContent = t || ''; e.style.display = t ? 'block' : 'none'; }
function pad(n) { return (n < 10 ? '0' : '') + n; }

function startWait(j) {
  $('pickView').style.display = 'none'; $('waitView').style.display = 'block';
  $('wAmount').textContent = j.amount || ''; $('wComment').textContent = j.comment || '';
  $('copyBox').style.display = (method === 'cryptobot') ? 'none' : 'block';
  $('waitHint').textContent = HINT[method] || '';
  $('slow').style.display = 'none';
  t0 = Date.now();
  clearInterval(tick); tick = setInterval(function () {
    var s = Math.floor((Date.now() - t0) / 1000);
    $('timer').textContent = pad(Math.floor(s / 60)) + ':' + pad(s % 60);
    if (s > 150) $('slow').style.display = 'block';
  }, 1000);
  clearInterval(poll); poll = setInterval(check, 3000); check();
}
function check() {
  fetch('pay_status.php?id=' + D.id + '&t=' + D.t, { cache: 'no-store' }).then(function (r) { return r.json(); })
    .then(function (j) { if (j.paid && j.redirect) { clearInterval(poll); clearInterval(tick); location.href = j.redirect; } }).catch(function () {});
}
document.addEventListener('visibilitychange', function () { if (!document.hidden && poll) check(); });  // вернулись с оплаты — проверяем сразу

$('payBtn').onclick = function () {
  if (!method) return; showErr('');
  var w = window.open('about:blank', '_blank');
  $('payBtn').disabled = true;
  fetch('pay_go.php?id=' + D.id + '&t=' + D.t + '&m=' + method + '&c=' + cur, { cache: 'no-store' })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (!j.ok) { if (w) w.close(); showErr(j.error || 'Ошибка'); render(); return; }
      if (j.paid) { location.href = j.url; return; }
      lastUrl = j.url;
      if (w) { w.location = j.url; } else if (!window.open(j.url, '_blank')) { location.href = j.url; }
      startWait(j);
    }).catch(function () { if (w) w.close(); showErr('Нет связи с сервером, попробуйте ещё раз'); render(); });
};
$('reopen').onclick = function () { if (lastUrl) window.open(lastUrl, '_blank'); };
$('checkNow').onclick = function () { check(); };
$('back').onclick = function () { clearInterval(poll); clearInterval(tick); poll = null; $('waitView').style.display = 'none'; $('pickView').style.display = 'block'; render(); };
$('wComment').onclick = function () { try { navigator.clipboard.writeText(this.textContent); this.style.opacity = .5; } catch (e) {} };
render();
</script>
<?php endif; ?>
</body></html>
