<?php
/**
 * resources_api.php — лайки и избранное для закрытого раздела материалов
 * (Блок 2.3 ТЗ). Доступ — те же правила, что и у resources.php.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
header('Content-Type: application/json; charset=utf-8');

require_once 'includes/session.php';
require_once 'config/db.php';
require_once 'includes/order_flow.php';
require_once 'includes/pack_role.php';
require_once 'includes/resources_lib.php';

ensureOrderFlowSchema($pdo);
ensurePackRoleSchema($pdo);
ensureResourcesSchema($pdo);

function jexit(array $data): void { echo json_encode($data); exit; }

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
if (!$isPackDesigner) jexit(['ok' => false, 'error' => 'Доступ закрыт']);

// Лайки/избранное привязаны к tg_id — у чистого админа без привязанного
// Telegram (заходит только по паролю) своего личного набора избранного нет.
$tgId = (string)($tgProfile['tg_id'] ?? '');
if ($tgId === '') jexit(['ok' => false, 'error' => 'Нужен привязанный Telegram-аккаунт']);

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = (string)($input['action'] ?? '');
$resourceId = (int)($input['resource_id'] ?? 0);
if ($resourceId <= 0) jexit(['ok' => false, 'error' => 'Некорректный материал']);

switch ($action) {
    case 'toggle_like':
        $liked = toggleResourceLike($pdo, $resourceId, $tgId);
        $eng = getResourceEngagement($pdo, [$resourceId], $tgId)[$resourceId];
        jexit(['ok' => true, 'liked' => $liked, 'likes' => $eng['likes']]);
    case 'toggle_favorite':
        $fav = toggleResourceFavorite($pdo, $resourceId, $tgId);
        jexit(['ok' => true, 'favorited' => $fav]);
    default:
        jexit(['ok' => false, 'error' => 'Неизвестное действие']);
}
