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
require_once 'includes/ppk_access.php';
require_once __DIR__ . '/admin/google_drive_helper.php';

ensureOrderFlowSchema($pdo);
ensurePackRoleSchema($pdo);
ensureResourcesSchema($pdo);

$access = resolvePpkAccess($pdo);
if (!$access['isPackDesigner']) { http_response_code(403); exit('Доступ закрыт'); }

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

// Файл в Cloudinary — стримим через сервер с Content-Disposition: attachment,
// чтобы браузер именно скачивал файл (шрифт, архив), а не открывал его во вкладке.
if (isCloudinaryUrl($sourceUrl)) {
    $dlName = str_replace(['"', "\r", "\n", '/', '\\'], '', $fileName ?: basename(parse_url($sourceUrl, PHP_URL_PATH) ?: 'file'));
    $started = false;
    $ch = curl_init($sourceUrl);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$started, $dlName) {
            if (stripos($line, 'HTTP/') === 0) { $started = false; }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => function ($c, $chunk) use (&$started, $dlName) {
            if (!$started) {
                if ((int)curl_getinfo($c, CURLINFO_HTTP_CODE) !== 200) { return 0; } // не 200 — прерываем, уйдём на редирект
                $started = true;
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . preg_replace('/[^\x20-\x7E]/', '_', $dlName) . '"; filename*=UTF-8\'\'' . rawurlencode($dlName));
                header('Cache-Control: private, max-age=0, must-revalidate');
                while (ob_get_level()) { @ob_end_clean(); }
            }
            echo $chunk;
            return strlen($chunk);
        },
    ]);
    @set_time_limit(0);
    curl_exec($ch);
    curl_close($ch);
    if ($started) { exit; }
    // Cloudinary не ответил 200 — отдаём прямую ссылку
    header('Location: ' . $sourceUrl);
    exit;
}

// Старые записи с внешней ссылкой без сохранённого file_id (добавлены до
// этого обновления) — отдаём как есть; для них принудительный
// Content-Disposition недоступен.
header('Location: ' . $sourceUrl);
exit;
