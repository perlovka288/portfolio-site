<?php
/**
 * track.php — приём просмотров и «пульса» от assets/kostlim-track.js (sendBeacon).
 * Ничего не отвечает (204). Не считает: ботов, администратора, служебные адреса.
 */
ignore_user_abort(true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(204); exit; }
header('Cache-Control: no-store');

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|uptime|curl|wget|python|headless|lighthouse|pingdom/i', $ua)) { http_response_code(204); exit; }

// администратор свои просмотры не накручивает (читаем сессию без блокировки)
if (!empty($_COOKIE[session_name()])) {
    @session_start(['read_and_close' => true]);
    if (!empty($_SESSION['admin_logged'])) { http_response_code(204); exit; }
}

$raw  = (string)file_get_contents('php://input', false, null, 0, 2048);
$data = json_decode($raw, true);
if (!is_array($data)) { $data = $_POST; }
$path = (string)parse_url((string)($data['p'] ?? '/'), PHP_URL_PATH);
$path = ($path === '' || $path[0] !== '/') ? '/' : mb_substr($path, 0, 160);
if (preg_match('#^/(admin|track\.php|upload|assets|api)#i', $path)) { http_response_code(204); exit; }
$hb = !empty($data['hb']);

$vid = (string)($_COOKIE['kui_vid'] ?? '');
if (!preg_match('/^[a-f0-9]{16}$/', $vid)) {
    $vid = bin2hex(random_bytes(8));
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie('kui_vid', $vid, ['expires' => time() + 31536000, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
}

try {
    require_once __DIR__ . '/config/db.php';
    require_once __DIR__ . '/includes/analytics_lib.php';
    ensureAnalyticsSchema($pdo);
    kuiAnalyticsRecord($pdo, $vid, $path, $hb);
} catch (Throwable $e) {
    error_log('[track.php] ' . $e->getMessage());
}
http_response_code(204);
