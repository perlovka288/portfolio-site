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

/** Отдаёт один файл как скачивание: Google Drive (API) → локальный → Cloudinary (стрим) → редирект. */
function dlServe(string $sourceUrl, string $fileId, string $fileName): void
{
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
}

/** Скачивает файл во временный путь для упаковки в zip. Возвращает путь или '' */
function dlFetchToTemp(string $url, string $fileId): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'dlz_');
    if ($fileId !== '') {
        $f = downloadFromGoogleDrive($fileId);
        if ($f !== null) { file_put_contents($tmp, $f['data']); return $tmp; }
    }
    if (!isCloudinaryUrl($url)) { @unlink($tmp); return ''; }   // чужие хосты в архив не тянем
    $fp = fopen($tmp, 'wb');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120, CURLOPT_FAILONERROR => true]);
    $ok = curl_exec($ch) !== false;
    curl_close($ch); fclose($fp);
    if (!$ok || filesize($tmp) === 0) { @unlink($tmp); return ''; }
    return $tmp;
}

$files = packResourceFiles($r);
$baseName = preg_replace('/[^\p{L}\p{N}_\- ]+/u', '', (string)$r['title']) ?: 'files';

// ── Все файлы одним zip ──
if (!empty($_GET['all'])) {
    if (!class_exists('ZipArchive') || count($files) < 1) { http_response_code(404); exit('Файлы недоступны'); }
    @set_time_limit(0);
    $zipPath = tempnam(sys_get_temp_dir(), 'dlzip_');
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) { http_response_code(500); exit('Не удалось собрать архив'); }
    $tmpFiles = []; $used = []; $added = 0;
    foreach (array_slice($files, 0, 40) as $i => $f) {
        $path = dlFetchToTemp($f['url'], $f['id']);
        if ($path === '') continue;
        $tmpFiles[] = $path;
        $nm = $f['name'] !== '' ? $f['name'] : basename((string)parse_url($f['url'], PHP_URL_PATH));
        $nm = str_replace(['/', '\\'], '_', $nm);
        if (isset($used[$nm])) { $nm = ($i + 1) . '_' . $nm; }   // одинаковые имена не затирают друг друга
        $used[$nm] = true;
        $zip->addFile($path, $nm); $added++;
    }
    $zip->close();
    if ($added === 0) { foreach ($tmpFiles as $t) @unlink($t); @unlink($zipPath); http_response_code(502); exit('Не удалось получить файлы'); }
    while (ob_get_level()) { @ob_end_clean(); }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^\x20-\x7E]/', '_', $baseName) . '.zip"; filename*=UTF-8\'\'' . rawurlencode($baseName . '.zip'));
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($zipPath);
    foreach ($tmpFiles as $t) @unlink($t);
    @unlink($zipPath);
    exit;
}

// ── Один файл: по номеру ?i= (если у материала несколько файлов) ──
if (isset($_GET['i']) && $files) {
    $f = $files[(int)$_GET['i']] ?? null;
    if (!$f) { http_response_code(404); exit('Файл не найден'); }
    dlServe($f['url'], $f['id'], $f['name'] !== '' ? $f['name'] : basename((string)parse_url($f['url'], PHP_URL_PATH)));
    exit;
}

// ── Обычный путь (один файл / видео / старые записи) ──
$sourceUrl = $r['type'] === 'sd_video' ? (string)($r['video_url'] ?? '') : (string)($r['file_url'] ?? '');
$fileId    = (string)($r['file_id'] ?? '');
$fileName  = (string)($r['file_name'] ?? '') ?: ($r['title'] . '');
dlServe($sourceUrl, $fileId, $fileName);
