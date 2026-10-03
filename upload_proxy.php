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
if ($fileSize > 100 * 1024 * 1024) {            // максимум 100MB
    echo json_encode(['ok' => false, 'error' => 'File too large']);
    exit;
}
if (!is_uploaded_file($fileTmp)) {
    echo json_encode(['ok' => false, 'error' => 'Bad upload']);
    exit;
}

require_once __DIR__ . '/includes/image_store.php';
$pdo = null;
try { require_once __DIR__ . '/config/db.php'; } catch (Throwable $e) { $pdo = null; }
$err = null;
$url = imageStoreUpload($fileTmp, 'archive_' . time(), ($pdo instanceof PDO) ? $pdo : null, $err, 300, 1, 'orders/archive', (string)($_FILES['file']['name'] ?? ''));
if ($url !== '') {
    echo json_encode(['ok' => true, 'url' => $url]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Не удалось загрузить: ' . ($err ?: 'хранилище картинок не настроено')]);
exit;
