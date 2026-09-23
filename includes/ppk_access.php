<?php
/**
 * Единая точка вычисления доступа ADMIN/PPK — используется всеми новыми
 * страницами (privat_pak.php, ai_trainer.php, planner.php, *_api.php),
 * чтобы логика не расходилась по копиям и не плодила новые баги.
 *
 * Использование:
 *   require_once __DIR__ . '/ppk_access.php';
 *   ['isAdmin' => $isAdmin, 'isPackDesigner' => $isPackDesigner, 'tgId' => $tgId, 'tgProfile' => $tgProfile]
 *       = resolvePpkAccess($pdo);
 */
require_once __DIR__ . '/pack_role.php';
require_once __DIR__ . '/badges.php';

function ppkSiteSetting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $stmt = $pdo->prepare("SELECT value FROM site_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false && $val !== null && $val !== '' ? (string)$val : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

/**
 * @return array{isAdmin:bool, isPackDesigner:bool, tgId:string, tgProfile:array, sid:string}
 */
function resolvePpkAccess(PDO $pdo): array
{
    $sid = session_id();
    $tgProfile = [];
    try {
        $stmt = $pdo->prepare("SELECT tg_id, tg_username, tg_first_name, tg_photo_url FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1");
        $stmt->execute([$sid]);
        $tgProfile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    $tgId = (string)($tgProfile['tg_id'] ?? '');
    $adminTgEnv = getenv('ADMIN_ID') ?: '1710365896';
    $isAdmin = (isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true)
        || ($tgId !== '' && $tgId === $adminTgEnv);

    ensurePpkManualSchema($pdo);
    $botToken  = ppkSiteSetting($pdo, 'BOT_TOKEN') ?: (getenv('TELEGRAM_BOT_TOKEN') ?: getenv('BOT_TOKEN') ?: '');
    $groupChat = ppkSiteSetting($pdo, 'PRIVATE_CHAT_ID') ?: (getenv('PRIVATE_CHAT_ID') ?: '');

    $isPackDesigner = $isAdmin
        || hasManualPpkGrant($pdo, $tgId)
        || ($tgId !== '' && isPackDesigner($pdo, $botToken, $groupChat, $tgId, $isAdmin));

    return [
        'isAdmin'        => $isAdmin,
        'isPackDesigner' => $isPackDesigner,
        'tgId'           => $tgId !== '' ? $tgId : ($isAdmin ? ('admin_local_' . $sid) : ''),
        'tgProfile'      => $tgProfile,
        'sid'            => $sid,
    ];
}
