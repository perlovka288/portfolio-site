<?php
/**
 * AJAX для buy_pack.php (покупка Приват Пака).
 *   POST action=pay    & m=monobank|donationalerts|cryptobot → создаёт покупку, отдаёт ссылку на оплату
 *   POST action=claim                                       → «Я оплатил(а)» → админу уходит «клиент купил пак»
 *   POST action=status                                      → статус + автопроверка платежа
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/ppk_purchase.php';
require_once __DIR__ . '/includes/pay_lib.php';

function bpOut(array $a): void { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$access = resolvePpkAccess($pdo);
$tgId = ($access['tgProfile']['tg_id'] ?? '') !== '' ? (string)$access['tgProfile']['tg_id'] : '';
if ($tgId === '') bpOut(['ok' => false, 'error' => 'Сначала войди на сайт через Telegram.']);
if ($access['isPackDesigner']) bpOut(['ok' => true, 'status' => 'approved', 'has_access' => true]);

ensurePpkPurchaseSchema($pdo);
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$action = (string)($in['action'] ?? $_POST['action'] ?? '');

if ($action === 'status') {
    $p = ppkOpenPurchase($pdo, $tgId) ?: ppkApprovedPurchase($pdo, $tgId);
    if (!$p) bpOut(['ok' => true, 'status' => 'none']);
    if (in_array($p['status'], ['created', 'claimed'], true) && payThrottle('ppk_auto_' . $p['id'], 8)) {
        $hit = ppkAutoDetectPayment($pdo, $p);
        if ($hit) { ppkMarkPaid($pdo, (int)$p['id'], 'paid', $hit[0], $hit[1], (float)$hit[2]); $p = ppkGetPurchase($pdo, (int)$p['id']); }
    }
    bpOut(['ok' => true, 'status' => $p['status'], 'id' => (int)$p['id']]);
}

if ($action === 'claim') {
    // покупка могла ещё не существовать (оплата «вручную», без выбора способа) — создаём
    $p = ppkOpenPurchase($pdo, $tgId) ?: ppkCreatePurchase($pdo, $tgId, $access['tgProfile']);
    if ($p['status'] === 'created') { ppkMarkPaid($pdo, (int)$p['id'], 'claimed', $p['method'] !== '' ? '' : 'manual'); }
    bpOut(['ok' => true, 'status' => 'claimed']);
}

if ($action === 'pay') {
    $m = (string)($in['m'] ?? '');
    $prices = ppkPrices($pdo);
    $cfg = [
        'monobank'       => ['UAH', payEnv('MONO_JAR_URL') !== ''],
        'donationalerts' => ['RUB', true],
        'cryptobot'      => ['USD', payEnv('CRYPTO_KEY') !== ''],
    ];
    if (!isset($cfg[$m])) bpOut(['ok' => false, 'error' => 'Неизвестный способ оплаты']);
    [$cur, $ready] = $cfg[$m];
    if (!$ready)            bpOut(['ok' => false, 'error' => 'Этот способ временно недоступен']);
    if (!isset($prices[$cur])) bpOut(['ok' => false, 'error' => 'Для этого способа цена пока не задана']);
    $amount = (float)$prices[$cur];

    $p = ppkCreatePurchase($pdo, $tgId, $access['tgProfile']);
    $id = (int)$p['id'];
    if (in_array($p['status'], ['paid', 'claimed'], true)) bpOut(['ok' => true, 'already' => true, 'status' => $p['status']]);
    $pdo->prepare("UPDATE ppk_purchases SET method = ?, currency = ?, amount = ?, invoice_id = '' WHERE id = ?")
        ->execute([$m, $cur, number_format($amount, 2, '.', ''), $id]);

    $sep = fn(string $u) => str_contains($u, '?') ? '&' : '?';
    if ($m === 'monobank') {
        $comment = "PPK #{$id}";
        $u = payEnv('MONO_JAR_URL');
        $url = $u . $sep($u) . http_build_query(['a' => (int)$amount, 't' => $comment], '', '&', PHP_QUERY_RFC3986);
    } elseif ($m === 'donationalerts') {
        $comment = "PPK_{$id}";
        $u = payEnv('DA_DONATION_URL', 'https://www.donationalerts.com/r/andrewkostdzn');
        $url = $u . $sep($u) . http_build_query(['amount' => (int)$amount, 'currency' => $cur, 'message' => $comment], '', '&', PHP_QUERY_RFC3986);
    } else {
        $comment = "PPK #{$id}";
        $r = payHttp(cryptoBase() . 'createInvoice', ['Crypto-Pay-API-Token: ' . payEnv('CRYPTO_KEY'), 'Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'currency_type' => 'fiat', 'fiat' => 'USD', 'amount' => number_format($amount, 2, '.', ''),
                'accepted_assets' => 'USDT,TON,BTC,ETH,USDC', 'description' => $comment, 'payload' => "ppk{$id}",
                'allow_comments' => 'false', 'expires_in' => 3600,
            ]));
        $inv = (!empty($r['ok']) && !empty($r['result'])) ? $r['result'] : null;
        if (!$inv) bpOut(['ok' => false, 'error' => 'Не удалось создать счёт в Crypto Bot, попробуй ещё раз']);
        $pdo->prepare("UPDATE ppk_purchases SET invoice_id = ? WHERE id = ?")->execute([(string)$inv['invoice_id'], $id]);
        $url = (string)($inv['bot_invoice_url'] ?? ($inv['pay_url'] ?? ''));
    }
    bpOut(['ok' => true, 'url' => $url, 'amount' => ppkFormatMoney($amount, $cur), 'comment' => $comment, 'method' => $m, 'id' => $id]);
}

bpOut(['ok' => false, 'error' => 'Неизвестное действие']);
