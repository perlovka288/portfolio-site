<?php
/**
 * Приём данных Telegram Mini App (Web App) и привязка их к сессии сайта.
 *
 * ВАЖНО: данные приходят сюда через тело POST-запроса (не через GET/URL),
 * поэтому в адресной строке браузера (в т.ч. в Chrome) они никак не
 * отображаются и не попадают в историю браузера/логи сервера так, как
 * попадали бы GET-параметры.
 *
 * Сами данные (initData) при этом ещё и подписаны Telegram — подделать
 * их без секретного токена бота невозможно (см. verifyTelegramWebAppInitData
 * в includes/session.php).
 */

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');

$initData = trim((string)file_get_contents('php://input'));
if ($initData === '') {
    // Фолбэк на обычное POST-поле, если фронт вдруг отправил как form-data
    $initData = trim((string)($_POST['init_data'] ?? ''));
}

$botToken = getenv('BOT_TOKEN') ?: getenv('TELEGRAM_BOT_TOKEN') ?: '';
$user = verifyTelegramWebAppInitData($initData, $botToken);

if ($user === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_signature']);
    exit;
}

try {
    _saveTgToSession($pdo, session_id(), $user['id'], $user['username'], $user['first_name']);
    $_SESSION['_tg_linked'] = true;
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('tg_webapp_auth error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}