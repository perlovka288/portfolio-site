<?php
// pay_webhook.php — вебхук Crypto Bot. В @CryptoBot → Crypto Pay → My Apps → Webhooks укажите https://ВАШ-САЙТ/pay_webhook.php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/pay_lib.php';
$body = file_get_contents('php://input');
$sig  = $_SERVER['HTTP_CRYPTO_PAY_API_SIGNATURE'] ?? '';
$calc = hash_hmac('sha256', $body, hash('sha256', payEnv('CRYPTO_KEY'), true));
if ($sig === '' || !hash_equals($calc, $sig)) { http_response_code(403); exit('bad signature'); }
$j = json_decode($body, true);
if (($j['update_type'] ?? '') === 'invoice_paid') {
    $inv = $j['payload'] ?? [];
    $id = (int)($inv['payload'] ?? 0);
    if ($id > 0) {
        $o = payGetOrder($pdo, $id);
        if ($o) markOrderPaid($pdo, $id, 'cryptobot', (string)($o['pay_currency'] ?: 'USD'), (float)($o['pay_amount'] ?: ($inv['amount'] ?? 0)));
    }
}
echo 'ok';
