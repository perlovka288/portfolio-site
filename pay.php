<?php
// pay.php?id=31&t=TOKEN — страница оплаты (сюда ведёт кнопка «Оплатить на сайте» из Telegram)
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
$cfg = ['id' => $id, 't' => $t, 'amounts' => $amounts, 'initial' => $initial, 'cur' => payCurrencies(), 'methods' => $methods];
$botLink = 'https://t.me/kostlimdznbot';
?><!DOCTYPE html>
<html lang="ru"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Оплата заказа #<?= $id ?> | Kostlim Design</title>
<link rel="stylesheet" href="assets/pay.css?v=2">
</head><body>
<div class="pay-card">
<?php if ($state === 'bad'): ?>
  <h1>Ссылка недействительна</h1><p class="pay-sub">Откройте оплату заново из сообщения в Telegram-боте.</p>
  <a class="btn" style="display:block;text-align:center;text-decoration:none" href="<?= $botLink ?>">Открыть бота</a>
<?php elseif ($state === 'wait_accept'): ?>
  <h1>Заказ #<?= $id ?> ещё на проверке</h1><p class="pay-sub">Дизайнер пока не принял ТЗ. Как только примет — вам придёт сообщение с кнопкой оплаты.</p>
  <a class="btn" style="display:block;text-align:center;text-decoration:none" href="profile.php">В профиль</a>
<?php else: ?>
  <div id="pickView">
    <h1>Оплата заказа #<?= $id ?></h1>
    <p class="pay-sub"><?= htmlspecialchars($title) ?></p>
    <div class="pay-total"><div><small>К оплате</small><br><b id="total">—</b></div><div id="curName" class="pay-sub" style="margin:0"></div></div>
    <div class="cur-tabs" id="curTabs"></div>
    <div class="m-list" id="mList"></div>
    <p class="err" id="err"></p>
    <button class="btn" id="payBtn">Перейти к оплате</button>
    <p class="note">После оплаты страница сама определит платёж и покажет чек. Ничего прикреплять не нужно.</p>
  </div>

  <div class="wait" id="waitView">
    <div class="spin"></div>
    <h1>Ждём оплату…</h1>
    <p class="pay-sub" id="waitSub">Оплатите в открывшемся окне. Эта страница обновится сама.</p>
    <div class="copybox" id="copyBox">Сумма: <b id="wAmount"></b><br>Комментарий к платежу: <code id="wComment" title="Нажмите, чтобы скопировать"></code><br><small style="color:#9a9aa6">Комментарий нужен, чтобы платёж сам привязался к заказу.</small></div>
    <button class="btn ghost" id="reopen">Открыть оплату ещё раз</button>
    <button class="btn ghost" id="back">← Выбрать другой способ</button>
    <a class="tg-link" href="<?= $botLink ?>" target="_blank">Оплатил, но долго нет подтверждения? Скинуть чек в ТГ →</a>
  </div>
<?php endif; ?>
</div>

<?php if ($state === 'ok'): ?>
<script>
var D = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var cur = D.initial, method = null, lastUrl = '', poll = null;
try { var sv = localStorage.getItem('pay_cur'); if (sv && D.cur[sv]) cur = sv; } catch (e) {}
if (!D.cur[cur]) cur = 'USD';

function fmt(a, c) {
  var m = D.cur[c];
  if (c === 'USD' || c === 'EUR') return m.symbol + Number(a).toFixed(2);
  return Math.round(a).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ' + m.symbol;
}
function $(id) { return document.getElementById(id); }

function render() {
  var tabs = $('curTabs'); tabs.innerHTML = '';
  Object.keys(D.cur).forEach(function (c) {
    var b = document.createElement('button'); b.type = 'button';
    b.className = 'cur-tab' + (c === cur ? ' on' : ''); b.textContent = D.cur[c].flag + ' ' + c;
    b.onclick = function () { cur = c; try { localStorage.setItem('pay_cur', c); } catch (e) {} method = null; render(); };
    tabs.appendChild(b);
  });
  $('total').textContent = fmt(D.amounts[cur], cur);
  $('curName').textContent = D.cur[cur].name;

  var list = $('mList'); list.innerHTML = '';
  var avail = [];
  Object.keys(D.methods).forEach(function (k) {
    var m = D.methods[k], ok = m.currencies.indexOf(cur) !== -1 && m.ready;
    if (ok) avail.push(k);
  });
  if (!method || avail.indexOf(method) === -1) method = avail[0] || null;

  Object.keys(D.methods).forEach(function (k) {
    var m = D.methods[k], supports = m.currencies.indexOf(cur) !== -1, ok = supports && m.ready;
    var el = document.createElement('div');
    el.className = 'm-item' + (k === method ? ' on' : '') + (ok ? '' : ' off');
    var accepts = m.currencies.map(function (c) { return D.cur[c].symbol + ' ' + c; }).join(' · ');
    el.innerHTML = '<div class="m-ico">' + m.icon + '</div><div class="m-body"><b>' + m.title + '</b><span>' + m.desc +
      '</span><em>' + (supports ? (m.ready ? 'Принимает: ' + accepts : 'Временно недоступно') : 'Не для ' + cur + ' · принимает: ' + accepts) +
      '</em></div><div class="m-radio"></div>';
    if (ok) el.onclick = function () { method = k; render(); };
    list.appendChild(el);
  });
  $('payBtn').disabled = !method;
  $('payBtn').textContent = method ? 'Перейти к оплате ' + fmt(D.amounts[cur], cur) : 'Для этой валюты нет доступных способов';
}
function showErr(t) { var e = $('err'); e.textContent = t; e.style.display = t ? 'block' : 'none'; }

function startWait(j) {
  $('pickView').style.display = 'none'; $('waitView').style.display = 'block';
  $('wAmount').textContent = j.amount || ''; $('wComment').textContent = j.comment || '';
  $('copyBox').style.display = (method === 'cryptobot') ? 'none' : 'block';
  if (poll) clearInterval(poll);
  poll = setInterval(check, 3000); check();
}
function check() {
  fetch('pay_status.php?id=' + D.id + '&t=' + D.t, { cache: 'no-store' }).then(function (r) { return r.json(); })
    .then(function (j) { if (j.paid && j.redirect) { clearInterval(poll); location.href = j.redirect; } }).catch(function () {});
}
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
      if (w) { w.location = j.url; } else { window.open(j.url, '_blank') || (location.href = j.url); }
      startWait(j);
    }).catch(function () { if (w) w.close(); showErr('Нет связи с сервером, попробуйте ещё раз'); render(); });
};
$('reopen').onclick = function () { if (lastUrl) window.open(lastUrl, '_blank'); };
$('back').onclick = function () { clearInterval(poll); $('waitView').style.display = 'none'; $('pickView').style.display = 'block'; render(); };
$('wComment').onclick = function () { try { navigator.clipboard.writeText(this.textContent); this.style.opacity = .5; } catch (e) {} };
render();
</script>
<?php endif; ?>
</body></html>
