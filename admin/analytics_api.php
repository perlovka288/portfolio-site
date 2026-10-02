<?php
/** Данные для виджетов аналитики. GET: ?online=1 (только онлайн) или ?range=7d|30d|12m. Только админ. */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['admin_logged'])) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }
session_write_close();   // дальше сессия не нужна — не держим блокировку

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/analytics_lib.php';
try {
    ensureAnalyticsSchema($pdo);
    if (!empty($_GET['online'])) { echo json_encode(['online' => kuiAnalyticsOnline($pdo)]); exit; }
    $range = in_array($_GET['range'] ?? '7d', ['7d', '30d', '12m'], true) ? $_GET['range'] : '7d';
    echo json_encode(['online' => kuiAnalyticsOnline($pdo)] + kuiAnalyticsReport($pdo, $range), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[analytics_api] ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'db']);
}
