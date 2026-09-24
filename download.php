<?php
/**
 * download.php — гарантированное скачивание материалов закрытого раздела
 * (Блок 2.2 ТЗ): отдаёт файл с Google Drive напрямую с заголовком
 * Content-Disposition: attachment, вместо того чтобы открывать
 * webViewLink-превью Google Drive в пустой вкладке.
 *
 * Использование: download.php?rid=<id пункта pack_resources>
 * Доступ — те же правила, что и у resources.php (Admin или Designer PPK).
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once 'includes/session.php';
require_once 'config/db.php';
require_once 'includes/order_flow.php';
require_once 'includes/pack_role.php';
require_once 'includes/resources_lib.php';
require_once __DIR__ . '/admin/google_drive_helper.php';

ensureOrderFlowSchema($pdo);
ensurePackRoleSchema($pdo);
ensureResourcesSchema($pdo);

$sid = session_id();
$tgProfile = [];
try {
    $stmt = $pdo->prepare("SELECT tg_id FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1");
    $stmt->execute([$sid]);
    $tgProfile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$adminTgEnv = getenv('ADMIN_ID') ?: '1710365896';
$isAdmin = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
if (!$isAdmin && !empty($tgProfile['tg_id']) && (string)$tgProfile['tg_id'] === $adminTgEnv) {
    $isAdmin = true;
}
$isPackDesigner = false;
if ($isAdmin || !empty($tgProfile['tg_id'])) {
    $botTokenForRoleCheck        = getSiteSetting($pdo, 'BOT_TOKEN') ?: (getenv('TELEGRAM_BOT_TOKEN') ?: getenv('BOT_TOKEN') ?: '');
    $packGroupChatIdForRoleCheck = getSiteSetting($pdo, 'PRIVATE_CHAT_ID') ?: (getenv('PRIVATE_CHAT_ID') ?: '');
    $isPackDesigner = isPackDesigner($pdo, $botTokenForRoleCheck, $packGroupChatIdForRoleCheck, (string)($tgProfile['tg_id'] ?? ''), $isAdmin);
}
if (!$isPackDesigner) { http_response_code(403); exit('Доступ закрыт'); }

$rid = (int)($_GET['rid'] ?? 0);
if ($rid <= 0) { http_response_code(400); exit('Некорректный запрос'); }

$stmt = $pdo->prepare("SELECT * FROM pack_resources WHERE id = ? LIMIT 1");
$stmt->execute([$rid]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$r) { http_response_code(404); exit('Материал не найден'); }

$sourceUrl = $r['type'] === 'sd_video' ? (string)($r['video_url'] ?? '') : (string)($r['file_url'] ?? '');
$fileId    = (string)($r['file_id'] ?? '');
$fileName  = (string)($r['file_name'] ?? '') ?: ($r['title'] . '');

if ($fileId !== '') {
    $file = downloadFromGoogleDrive($fileId);
    if ($file !== null) {
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $file['mime']);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fileName) . '"');
        header('Content-Length: ' . strlen($file['data']));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $file['data'];
        exit;
    }
    // Не удалось скачать через API (ключ протух/файл удалён и т.п.) — ниже
    // fallback на прямую ссылку, чтобы пользователь хотя бы не упёрся в 500.
}

if ($sourceUrl === '') { http_response_code(404); exit('Файл недоступен'); }

// Локально загруженный файл (запасной вариант, когда Google Drive не
// настроен, — см. uploadPackResourceFileLocal()) — отдаём напрямую с
// заголовком attachment, это надёжнее, чем редирект.
if (str_starts_with($sourceUrl, '/uploads/')) {
    $localPath = __DIR__ . $sourceUrl;
    if (is_file($localPath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fileName ?: basename($localPath)) . '"');
        header('Content-Length: ' . filesize($localPath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        readfile($localPath);
        exit;
    }
    http_response_code(404); exit('Файл недоступен');
}

// Старые записи с внешней ссылкой без сохранённого file_id (добавлены до
// этого обновления) — отдаём как есть; для них принудительный
// Content-Disposition недоступен.
header('Location: ' . $sourceUrl);
exit;
