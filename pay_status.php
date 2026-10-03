<?php
// pay_status.php?id=31&t=TOKEN — опрос статуса (JS зовёт каждые 3 сек), заодно сам проверяет платёж у провайдера
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/pay_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ensurePaySchema($pdo);

$id = (int)($_GET['id'] ?? 0);
if (!payTokenOk($id, (string)($_GET['t'] ?? ''))) { echo json_encode(['paid' => false, 'error' => 'bad token']); exit; }
$o = payGetOrder($pdo, $id);
if ($o && !payIsPaid($o)) {
    session_write_close();   // не блокируем другие запросы пользователя на время проверки
    $m = (string)($o['pay_method'] ?? '');
    try {
        if ($m === 'cryptobot')                                    checkCryptoInvoice($pdo, $o);
        elseif ($m === 'monobank' && payThrottle('mono', 60))      checkMonobankAll($pdo);
        elseif ($m === 'donationalerts' && payThrottle('da', 8))   checkDonationAlerts($pdo);
    } catch (Throwable $e) { error_log('[pay_status] ' . $e->getMessage()); }
    $o = payGetOrder($pdo, $id);
}
$paid = $o && payIsPaid($o);
echo json_encode(['paid' => $paid, 'redirect' => $paid ? 'success.php?id=' . $id . '&t=' . payToken($id) : null]);
