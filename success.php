<?php
// success.php?id=31&t=TOKEN — экран успешной оплаты (билет + конфетти)
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/pay_lib.php';
ensurePaySchema($pdo);

$id = (int)($_GET['id'] ?? 0); $t = (string)($_GET['t'] ?? '');
$o  = payTokenOk($id, $t) ? payGetOrder($pdo, $id) : null;
if (!$o) { header('Location: index.php'); exit; }
if (!payIsPaid($o)) { header('Location: pay.php?id=' . $id . '&t=' . $t); exit; }

$order_id             = $id;
$service_title        = ''; try { $service_title = getOrderServiceTitle($pdo, $o); } catch (Throwable $e) {}
$amount               = (float)$o['pay_amount'];
$currency             = (string)($o['pay_currency'] ?: 'USD');
$amount_formatted     = payFormat($amount, $currency);
$payment_method       = (string)$o['pay_method'];
$payment_method_title = payMethodTitle($payment_method);
$paid_ts              = strtotime((string)($o['paid_at'] ?: 'now')) ?: time();
$months               = ['янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'];
$created_at           = date('j', $paid_ts) . ' ' . $months[(int)date('n', $paid_ts) - 1] . ' ' . date('Y', $paid_ts) . ' • ' . date('H:i', $paid_ts);
$barcode_value        = str_pad((string)$order_id, 6, '0', STR_PAD_LEFT) . date('dmHi', $paid_ts);
$client               = trim((string)($o['username'] ?? ''));
$tgName               = trim((string)($o['telegram'] ?? ''));
$invoice              = trim((string)($o['pay_invoice_id'] ?? ''));
// скидка по промокоду (если был)
$price = []; try { $price = computeOrderPriceWithPromo($pdo, $o); } catch (Throwable $e) {}
$discPct   = (int)($price['discount_percent'] ?? 0);
$promoCode = (string)($price['promo_code'] ?? '');
// сумма во всех валютах сайта (по текущему курсу); оплаченная валюта — точная сумма из платежа
$allCur = []; try { $allCur = payAmounts($pdo, $o); } catch (Throwable $e) {}
$allCur[$currency] = $amount;
$curMeta = payCurrencies();
$LOGO = ['monobank' => 'mono', 'donationalerts' => 'DA', 'cryptobot' => '₮'];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="ru"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Оплата прошла | Kostlim Design</title>
<link rel="stylesheet" href="assets/pay.css?v=4">
    <?php @include __DIR__ . '/includes/icons_head.php'; ?>
</head><body class="receipt">
<div class="ticket" id="ticket">
  <span class="cut l" id="cutL"></span><span class="cut r" id="cutR"></span>
  <div class="t-head">
    <div class="t-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
    <h1>Спасибо за оплату!</h1>
    <p>Заказ оплачен, дизайнер приступает к работе</p>
  </div>
  <div class="t-body">
    <div class="dash"></div>
    <div class="row2">
      <div><p class="lbl">Заказ</p><p class="val mono">#<?= $order_id ?></p></div>
      <div class="right"><p class="lbl">Оплачено</p><p class="val amt"><?= $h($amount_formatted) ?></p></div>
    </div>
    <div class="row2">
      <div><p class="lbl">Статус</p><span class="status">Оплачен</span></div>
      <div class="right"><p class="lbl">Валюта</p><p class="val"><?= $h(($curMeta[$currency]['flag'] ?? '') . ' ' . $currency) ?></p></div>
    </div>
    <div><p class="lbl">Услуга</p><p class="val"><?= $h($service_title) ?></p></div>
    <?php if ($client !== '' || $tgName !== ''): ?>
    <div class="row2">
      <?php if ($client !== ''): ?><div><p class="lbl">Клиент</p><p class="val"><?= $h($client) ?></p></div><?php endif; ?>
      <?php if ($tgName !== ''): ?><div class="<?= $client !== '' ? 'right' : '' ?>"><p class="lbl">Telegram</p><p class="val"><?= $h($tgName) ?></p></div><?php endif; ?>
    </div>
    <?php endif; ?>
    <div><p class="lbl">Дата и время оплаты</p><p class="val"><?= $h($created_at) ?></p></div>
    <div class="pm">
      <?php $__img = payMethods()[$payment_method]['img'] ?? ''; $__has = $__img !== '' && is_file(__DIR__ . '/' . $__img); ?>
      <div class="logo <?= $__has ? 'has-img ' : '' ?><?= $h($payment_method) ?>"><?php if ($__has): ?><img src="<?= $h($__img) ?>" alt="" draggable="false"><?php else: ?><?= $h($LOGO[$payment_method] ?? '💳') ?><?php endif; ?></div>
      <div><p class="val"><?= $h($payment_method_title) ?></p><p class="lbl" style="margin:2px 0 0">Способ оплаты<?= $invoice !== '' ? ' · счёт ' . $h(mb_substr($invoice, 0, 14)) : '' ?></p></div>
    </div>
    <?php if ($discPct > 0): ?>
    <div class="sum">
      <div><span>Цена без скидки</span><span><?= $h(payFormat((float)($price['base_uan'] ?? 0), 'UAH')) ?> / <?= $h(payFormat((float)($price['base_rub'] ?? 0), 'RUB')) ?></span></div>
      <div class="disc"><span>Промокод <?= $h($promoCode) ?></span><span>−<?= $discPct ?>%</span></div>
    </div>
    <?php endif; ?>
    <?php if ($allCur): ?>
    <div><p class="lbl">Сумма заказа в валютах</p>
      <div class="eq"><?php foreach ($curMeta as $c => $m): if (!isset($allCur[$c])) continue; ?><span class="<?= $c === $currency ? 'cur' : '' ?>"><?= $h(payFormat((float)$allCur[$c], $c)) ?></span><?php endforeach; ?></div>
    </div>
    <?php endif; ?>
    <div class="dash" id="cutLine"></div>
    <div class="bar"><svg id="barcode" height="70"></svg><p><?= $h($barcode_value) ?></p></div>
  </div>
</div>
<div class="acts">
  <a class="btn" href="profile.php">Мои заказы</a>
  <button class="btn ghost" type="button" onclick="window.print()">Скачать / распечатать чек</button>
  <a class="btn ghost" href="https://t.me/Perlo_ovka" target="_blank">Написать дизайнеру</a>
  <a class="btn ghost" href="index.php">На главную</a>
</div>
<canvas id="conf"></canvas>

<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>
<script>
// штрихкод (как в компоненте ticket-confirmation-card, детерминированный от значения)
(function(){
  var v = <?= json_encode($barcode_value) ?>, svg = document.getElementById('barcode');
  var seed = v.split('').reduce(function(a,b){a=((a<<5)-a)+b.charCodeAt(0);return a&a},0);
  function rnd(s){var x=Math.sin(s)*10000;return x-Math.floor(x)}
  var bars=[],total=0,sp=1.5;
  for(var i=0;i<60;i++){var w=rnd(seed+i)>0.7?2.5:1.5;bars.push(w);total+=w+sp}
  total-=sp; var W=250,x=(W-total)/2,out='';
  bars.forEach(function(w){out+='<rect x="'+x+'" y="10" width="'+w+'" height="50"/>';x+=w+sp});
  svg.setAttribute('width',W);svg.setAttribute('viewBox','0 0 '+W+' 70');svg.innerHTML=out;
})();
// вырезы билета — на уровне нижней пунктирной линии
(function(){
  function place(){var t=document.getElementById('ticket'),l=document.getElementById('cutLine');if(!t||!l)return;
    var y=l.getBoundingClientRect().top-t.getBoundingClientRect().top;
    ['cutL','cutR'].forEach(function(i){document.getElementById(i).style.top=y+'px'})}
  place();addEventListener('resize',place);addEventListener('load',place);
})();
// салют
(function(){
  if(typeof confetti!=='function')return;
  var c=document.getElementById('conf'), fire=confetti.create(c,{resize:true,useWorker:true});
  var colors=['#f97316','#fb923c','#fdba74','#22c55e','#3b82f6','#eab308'], end=Date.now()+3500;
  (function f(){
    fire({particleCount:5,angle:60,spread:60,origin:{x:0,y:.7},colors:colors});
    fire({particleCount:5,angle:120,spread:60,origin:{x:1,y:.7},colors:colors});
    if(Date.now()<end)requestAnimationFrame(f);
  })();
  fire({particleCount:120,spread:100,origin:{y:.35},colors:colors});
})();
</script>
</body></html>
