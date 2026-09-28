<?php
/**
 * notifications_api.php — список уведомлений и отметка "прочитано" для
 * колокольчика 🔔 закрытого раздела (Блок 5.2 ТЗ).
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once 'includes/session.php';
require_once 'config/db.php';
require_once 'includes/order_flow.php';
require_once 'includes/pack_role.php';
require_once 'includes/notifications_lib.php';
require_once 'includes/ppk_access.php';

ensureOrderFlowSchema($pdo);
ensurePackRoleSchema($pdo);
ensureNotificationsSchema($pdo);

$access = resolvePpkAccess($pdo);
if (!$access['isPackDesigner']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Доступ закрыт']);
    exit;
}
$tgId = (string)($access['tgId'] ?? '');
if ($tgId === '') { echo json_encode(['ok' => true, 'notifications' => [], 'unread' => 0]); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = (string)($input['action'] ?? 'list');

if ($action === 'mark_read') {
    markNotificationsRead($pdo, $tgId);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode([
    'ok' => true,
    'notifications' => listNotifications($pdo, $tgId),
    'unread' => getUnreadNotificationCount($pdo, $tgId),
]);
