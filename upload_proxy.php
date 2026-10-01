<?php
// Прокси для загрузки фото из JS (архивирование заказа).
// KUI: основное хранилище — ImgBB (ключи через запятую поддерживаются); Cloudinary — только запасной вариант.
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

// Запасной вариант — Cloudinary (если настроен)
$cloudName = getenv('CLOUDINARY_CLOUD_NAME') ?: '';
$apiKey    = getenv('CLOUDINARY_API_KEY')    ?: '';
$apiSecret = getenv('CLOUDINARY_API_SECRET') ?: '';
if ($cloudName === '' || $apiKey === '' || $apiSecret === '') {
    echo json_encode(['ok' => false, 'error' => 'ImgBB: ' . ($err ?: 'не удалось загрузить')]);
    exit;
}
$folder    = 'orders/archive';
$timestamp = time();
$sig = sha1("folder={$folder}&timestamp={$timestamp}{$apiSecret}");
$ch = curl_init("https://api.cloudinary.com/v1_1/{$cloudName}/image/upload");
curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_POSTFIELDS => ['file' => new CURLFile($fileTmp), 'api_key' => $apiKey, 'timestamp' => $timestamp, 'signature' => $sig, 'folder' => $folder],
]);
$resp = curl_exec($ch);
curl_close($ch);
$data = $resp ? json_decode($resp, true) : null;
echo json_encode(!empty($data['secure_url'])
    ? ['ok' => true, 'url' => $data['secure_url']]
    : ['ok' => false, 'error' => 'ImgBB: ' . ($err ?: '—') . '; Cloudinary error']);
