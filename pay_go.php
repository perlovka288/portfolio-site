<?php
// pay_go.php?id=31&t=TOKEN&m=monobank&c=UAH — создаёт платёж и отдаёт JSON {ok,url,amount,comment}
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/pay_lib.php';
header('Content-Type: application/json; charset=utf-8');
ensurePaySchema($pdo);

$id = (int)($_GET['id'] ?? 0); $t = (string)($_GET['t'] ?? '');
$m = (string)($_GET['m'] ?? ''); $c = strtoupper((string)($_GET['c'] ?? ''));
$fail = function (string $e) { echo json_encode(['ok' => false, 'error' => $e]); exit; };

if (!payTokenOk($id, $t)) $fail('Неверная ссылка');
$o = payGetOrder($pdo, $id); if (!$o) $fail('Заказ не найден');
if (payIsPaid($o)) { echo json_encode(['ok' => true, 'url' => paySuccessLink($id), 'paid' => true]); exit; }
if (!payIsPayable($o)) $fail('Заказ ещё не принят дизайнером');

$methods = payMethods();
if (!isset($methods[$m]) || !in_array($c, $methods[$m]['currencies'], true)) $fail('Эта система не принимает выбранную валюту');
if (!payMethodConfigured($m)) $fail('Способ временно недоступен');

$amount = (float)(payAmounts($pdo, $o)[$c] ?? 0);
if ($amount <= 0) $fail('Сумма заказа равна нулю — оплата не требуется');

$pdo->prepare("UPDATE orders SET pay_method=?, pay_currency=?, pay_amount=?, pay_invoice_id=NULL WHERE id=?")->execute([$m, $c, $amount, $id]);
$comment = ($m === 'donationalerts') ? "Order_{$id}" : "Order #{$id}";
$sep = fn(string $u) => str_contains($u, '?') ? '&' : '?';

if ($m === 'monobank') {
    $u = payEnv('MONO_JAR_URL');
    $url = $u . $sep($u) . http_build_query(['a' => (int)$amount, 't' => $comment]);
} elseif ($m === 'donationalerts') {
    $u = payEnv('DA_DONATION_URL', 'https://www.donationalerts.com/r/andrewkostdzn');
    $url = $u . $sep($u) . http_build_query(['amount' => (int)$amount, 'currency' => $c, 'message' => $comment]);
} else { // cryptobot
    $inv = cryptoCreateInvoice($id, $c, $amount);
    if (!$inv) $fail('Не удалось создать счёт в Crypto Bot, попробуйте ещё раз');
    $pdo->prepare("UPDATE orders SET pay_invoice_id=? WHERE id=?")->execute([(string)$inv['invoice_id'], $id]);
    $url = $inv['bot_invoice_url'] ?? ($inv['pay_url'] ?? '');
}
echo json_encode(['ok' => true, 'url' => $url, 'amount' => payFormat($amount, $c), 'comment' => $comment, 'method' => $m]);
