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
$method_icon          = payMethods()[$payment_method]['icon'] ?? '💳';
$created_at           = date('j M Y • H:i', strtotime((string)($o['paid_at'] ?: 'now')));
$barcode_value        = str_pad((string)$order_id, 6, '0', STR_PAD_LEFT) . date('dmHi', strtotime((string)($o['paid_at'] ?: 'now')));
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="ru"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Оплата прошла | Kostlim Design</title>
<link rel="stylesheet" href="assets/pay.css?v=2">
<style>
body{flex-direction:column;gap:14px}
.ticket{position:relative;z-index:2;width:100%;max-width:384px;background:var(--card);border:1px solid var(--line);border-radius:24px;box-shadow:0 20px 60px rgba(0,0,0,.55);animation:pop .5s ease both}
@keyframes pop{from{opacity:0;transform:scale(.95)}to{opacity:1;transform:none}}
.cut{position:absolute;top:50%;width:32px;height:32px;border-radius:50%;background:var(--bg);transform:translateY(-50%);border:1px solid var(--line)}
.cut.l{left:-16px;clip-path:inset(0 0 0 50%)}.cut.r{right:-16px;clip-path:inset(0 50% 0 0)}
.t-head{padding:32px 32px 24px;text-align:center}
.t-ico{display:inline-flex;padding:12px;border-radius:50%;background:rgba(249,115,22,.12);animation:pop .5s .3s ease both}
.t-ico svg{width:40px;height:40px;color:var(--or)}
.t-head h1{font-size:24px;margin:14px 0 4px}.t-head p{margin:0;color:var(--mut);font-size:14px}
.t-body{padding:0 32px 28px;display:grid;gap:20px}
.dash{border-top:2px dashed var(--line)}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.lbl{font-size:11px;color:var(--mut);text-transform:uppercase;margin:0 0 3px}
.val{margin:0;font-weight:600}.mono{font-family:ui-monospace,Menlo,monospace}
.amt{font-size:20px;font-weight:800;color:#fdba74}
.pm{background:rgba(255,255,255,.04);border-radius:12px;padding:14px;display:flex;align-items:center;gap:14px}
.pm i{font-style:normal;font-size:28px}
.bar{display:flex;flex-direction:column;align-items:center;padding:4px 0}
.bar p{font-size:12px;color:var(--mut);letter-spacing:.3em;margin:8px 0 0}
.bar svg{fill:#fff}
.acts{position:relative;z-index:2;width:100%;max-width:384px;display:grid;gap:8px}
.acts a{text-decoration:none;text-align:center}
#conf{position:fixed;inset:0;pointer-events:none;z-index:1}
</style></head><body>
<!-- START DESIGN PROMPT PLACEHOLDER -->
<div class="ticket">
  <span class="cut l"></span><span class="cut r"></span>
  <div class="t-head">
    <div class="t-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
    <h1>Спасибо за оплату!</h1>
    <p>Заказ оплачен, дизайнер приступает к работе</p>
  </div>
  <div class="t-body">
    <div class="dash"></div>
    <div class="row2">
      <div><p class="lbl">Заказ</p><p class="val mono">#<?= $order_id ?></p></div>
      <div style="text-align:right"><p class="lbl">Сумма</p><p class="val amt"><?= $h($amount_formatted) ?></p></div>
    </div>
    <div><p class="lbl">Услуга</p><p class="val"><?= $h($service_title) ?></p></div>
    <div><p class="lbl">Дата и время оплаты</p><p class="val"><?= $h($created_at) ?></p></div>
    <div class="pm"><i><?= $method_icon ?></i><div><p class="val"><?= $h($payment_method_title) ?></p><p class="lbl" style="margin:2px 0 0">Валюта: <?= $h($currency) ?></p></div></div>
    <div class="dash"></div>
    <div class="bar"><svg id="barcode" height="70"></svg><p><?= $h($barcode_value) ?></p></div>
  </div>
</div>
<!-- END DESIGN PROMPT PLACEHOLDER -->
<div class="acts">
  <a class="btn" href="profile.php">Мои заказы</a>
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
