<?php
// Закрытый раздел ресурсов (Блок 3 ТЗ) — пока заглушка с проверкой доступа.
// Наполнение категорий (PSD / шрифты / стили и кисти / гайд+видео по SD)
// добавляется следующим шагом.
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once 'includes/session.php';
require_once 'config/db.php';
require_once 'includes/order_flow.php';
require_once 'includes/pack_role.php';

ensureOrderFlowSchema($pdo);
ensurePackRoleSchema($pdo);

$sid = session_id();
$tgProfile = [];
try {
    $stmt = $pdo->prepare("SELECT tg_id, tg_username, tg_first_name, tg_photo_url FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1");
    $stmt->execute([$sid]);
    $tgProfile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

define('ADMIN_TG_ID', '1710365896');
$adminTgEnv = getenv('ADMIN_ID') ?: '1710365896';
$isAdmin = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
if (!$isAdmin && !empty($tgProfile['tg_id']) && (string)$tgProfile['tg_id'] === $adminTgEnv) {
    $isAdmin = true;
}

$isPackDesigner = false;
if (!empty($tgProfile['tg_id'])) {
    $botTokenForRoleCheck        = getSiteSetting($pdo, 'BOT_TOKEN') ?: (getenv('TELEGRAM_BOT_TOKEN') ?: getenv('BOT_TOKEN') ?: '');
    $packGroupChatIdForRoleCheck = getSiteSetting($pdo, 'PRIVATE_CHAT_ID') ?: (getenv('PRIVATE_CHAT_ID') ?: '');
    $isPackDesigner = isPackDesigner($pdo, $botTokenForRoleCheck, $packGroupChatIdForRoleCheck, (string)$tgProfile['tg_id'], $isAdmin);
}

if (!$isPackDesigner) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Доступ закрыт | Kostlim Design</title>
    <link rel="stylesheet" href="style.css">
    </head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center;padding:24px;">
        <div>
            <h1>🔒 Доступ закрыт</h1>
            <p>Этот раздел доступен только участникам приватной группы пака.</p>
            <p><a href="index.php">← На главную</a></p>
        </div>
    </body></html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Закрытый раздел | Kostlim Design</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="header-compact">
    <div class="brand-title"><a href="index.php"><img src="/assets/img/logo.png" class="brand-logo-img" alt="Kostlim Design" style="height:34px;width:auto;max-width:140px;display:block;margin:0 auto;"></a></div>
</header>

<main style="max-width:720px;margin:24px auto;padding:0 16px;">
    <h1>🔒 Закрытый раздел</h1>
    <p>Доступ подтверждён<?= $isAdmin ? ' (admin)' : ' (Designer PPK)' ?>. Здесь появятся:</p>
    <ul>
        <li>📁 PSD-посты (превью + ссылка на сообщение в канале)</li>
        <li>🔤 Шрифты (.ttf)</li>
        <li>🎨 Стили и кисти</li>
        <li>🖥 Установка Stable Diffusion — гайд + папка с видео-инструкциями</li>
        <li>🤖 ИИ-тренажёр общения с клиентом</li>
        <li>🗂 Личный планер клиентов</li>
    </ul>
    <p style="opacity:.7">Наполнение разделов — следующий шаг обновления.</p>
</main>
</body>
</html>
