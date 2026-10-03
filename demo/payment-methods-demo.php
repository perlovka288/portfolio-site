<?php require_once __DIR__ . '/../includes/payment_methods.php'; ?>
<!DOCTYPE html>
<html lang="ru"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Демо — способы оплаты</title>
<link rel="stylesheet" href="../assets/payment-methods.css">
<style>body{background:#0a0a0f;margin:0;padding:24px}.w{max-width:560px;margin:auto}
.sw{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap}
.sw button{background:#16161d;color:#fff;border:1px solid #2a2a36;padding:8px 14px;border-radius:8px;cursor:pointer;font-weight:700}</style>
</head><body><div class="w">
<div class="sw">
<?php foreach (['UAH','RUB','USD','KZT','BYN','EUR'] as $c): ?>
  <button onclick="switchCurrency('<?= $c ?>')"><?= $c ?></button>
<?php endforeach; ?>
</div>
<?= renderPaymentMethodsInfo('UAH') ?>
</div>
<script src="../assets/payment-methods.js"></script>
</body></html>
