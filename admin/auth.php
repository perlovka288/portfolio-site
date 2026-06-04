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

// Try Telegram-based recognition: if this session is linked to ADMIN_TELEGRAM_ID in tg_links, promote to admin
try {
    require_once __DIR__ . '/../config/db.php';
    $sid = session_id();
    $stmt = $pdo->prepare("SELECT tg_id, linked FROM tg_links WHERE session_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$sid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $adminTg = getenv('ADMIN_TELEGRAM_ID') ?: (getenv('ADMIN_ID') ?: '');
    if ($row && (int)($row['linked'] ?? 0) === 1) {
        $tg_id = (string)($row['tg_id'] ?? '');
        if ($tg_id !== '' && $adminTg !== '' && (string)$tg_id === (string)$adminTg) {
            $_SESSION['admin_logged'] = true;
            return;
        }
    }
} catch (Throwable $e) {
    // ignore DB errors and fall through to normal login
}

// Not an admin — redirect to login
header('Location: login.php');
exit;