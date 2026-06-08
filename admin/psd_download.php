<?php
/**
 * Защищённая выдача PSD (только для админа по сессии или ?key=ADMIN_ID)
 */
session_start();
require_once __DIR__ . '/../config/db.php';

$adminKey = getenv('ADMIN_ID') ?: '1710365896';
$allowed = !empty($_SESSION['admin_logged']) || (string)($_GET['key'] ?? '') === (string)$adminKey;
if (!$allowed) {
    http_response_code(403);
    exit('Forbidden');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit('Not found');
}

$stmt = $pdo->prepare("SELECT psd_file, original_name FROM portfolio_psd WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Not found');
}

$path = __DIR__ . '/../uploads/psd/' . basename((string)$row['psd_file']);
if (!is_file($path)) {
    http_response_code(404);
    exit('File missing');
}

$name = $row['original_name'] ?: basename($path);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
