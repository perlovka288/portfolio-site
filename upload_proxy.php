<?php
// Прокси для загрузки фото из JS (архивирование заказа).
// KUI: картинки уходят в единое хранилище (Cloudinary, при его отсутствии ImgBB) — includes/image_store.php.
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
    echo json_encode(['ok' => false, 'error' => 'No file']);
    exit;
}

$fileTmp  = $_FILES['file']['tmp_name'];
$fileSize = (int)$_FILES['file']['size'];
if ($fileSize > 10 * 1024 * 1024) {             // максимум 10MB
    echo json_encode(['ok' => false, 'error' => 'File too large']);
    exit;
}
if (!is_uploaded_file($fileTmp) || @getimagesize($fileTmp) === false) {
    echo json_encode(['ok' => false, 'error' => 'Not an image']);
    exit;
}

require_once __DIR__ . '/includes/imgbb.php';
$pdo = null;
try { require_once __DIR__ . '/config/db.php'; } catch (Throwable $e) { $pdo = null; }
$err = null;
$url = imgbbUpload($fileTmp, 'archive_' . time(), ($pdo instanceof PDO) ? $pdo : null, $err, 30, 1);
if ($url !== '') {
    echo json_encode(['ok' => true, 'url' => $url]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Не удалось загрузить: ' . ($err ?: 'хранилище картинок не настроено')]);
exit;
