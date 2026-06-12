<?php
// Прокси для загрузки фото на Cloudinary из JS (архивирование заказа)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
    echo json_encode(['ok' => false, 'error' => 'No file']);
    exit;
}

$cloudName = getenv('CLOUDINARY_CLOUD_NAME') ?: 'ds6buwmpj';
$apiKey    = getenv('CLOUDINARY_API_KEY')    ?: '146292462848227';
$apiSecret = getenv('CLOUDINARY_API_SECRET') ?: 'Kx5xzQOIbjzLa4bWUUl11IBx0Ok';
$folder    = 'orders/archive';

$fileTmp  = $_FILES['file']['tmp_name'];
$fileSize = $_FILES['file']['size'];

// Максимум 10MB
if ($fileSize > 10 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'File too large']);
    exit;
}

$timestamp = time();
$sig = sha1("folder={$folder}&timestamp={$timestamp}{$apiSecret}");

$ch = curl_init("https://api.cloudinary.com/v1_1/{$cloudName}/image/upload");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_POSTFIELDS     => [
        'file'      => new CURLFile($fileTmp),
        'api_key'   => $apiKey,
        'timestamp' => $timestamp,
        'signature' => $sig,
        'folder'    => $folder,
    ],
]);
$resp = curl_exec($ch);
curl_close($ch);

$data = $resp ? json_decode($resp, true) : null;
if (!empty($data['secure_url'])) {
    echo json_encode(['ok' => true, 'url' => $data['secure_url']]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Cloudinary error', 'raw' => $resp]);
}
