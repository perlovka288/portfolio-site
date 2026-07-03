<?php
// ensure output buffering to prevent headers already sent errors
if (ob_get_level() === 0) {
    ob_start();
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If already logged in via session, allow
if (isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true) {
    return;
}

$adminTg = getenv('ADMIN_TELEGRAM_ID') ?: (getenv('ADMIN_ID') ?: '');

// ── Единственный Telegram-путь входа в админку — через ПРОВЕРЕННЫЙ
// Telegram Mini App (см. tg_webapp_auth.php). $_SESSION['_tg_verified_id']
// выставляется ТОЛЬКО после того, как сервер пересчитал HMAC-подпись
// initData секретным токеном бота — подделать эту сессию невозможно,
// не зная сам токен бота.
//
// Раньше здесь была проверка через общую таблицу tg_links (куда сессия
// попадала по ?tg_token=... из обычной ссылки) — эта ссылка была
// многоразовой в течение 72ч и давала admin_logged=true любому, кто её
// просто открыл (например, если она засветилась на демонстрации экрана).
// Такой путь для входа в АДМИНКУ больше не используется — только для
// обычной привязки заказов клиентам, где цена ошибки на порядки ниже.
if (!empty($_SESSION['_tg_verified_id']) && $adminTg !== '' && (string)$_SESSION['_tg_verified_id'] === (string)$adminTg) {
    $_SESSION['admin_logged'] = true;
    return;
}

// Not an admin — redirect to login
header('Location: login.php');
exit;