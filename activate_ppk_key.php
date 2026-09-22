<?php
/**
 * AJAX-эндпоинт активации одноразового ключа PPK (см. includes/badges.php
 * -> redeemPpkKey()). Требует, чтобы пользователь уже был авторизован
 * через Telegram на сайте (tg_links.session_id), иначе некому выдавать роль.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/badges.php';

$sid = session_id();
$tgId = '';
try {
    $stmt = $pdo->prepare("SELECT tg_id FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1");
    $stmt->execute([$sid]);
    $tgId = (string)($stmt->fetchColumn() ?: '');
} catch (Throwable $e) {}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$code = (string)($input['code'] ?? '');

if ($tgId === '') {
    echo json_encode(['ok' => false, 'error' => 'Сначала войдите на сайт через Telegram, потом активируйте ключ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = redeemPpkKey($pdo, $code, $tgId);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
