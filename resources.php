<?php
// Закрытый раздел ресурсов (Блок 3 ТЗ) — доступен Admin и Designer PPK.
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once 'includes/session.php';
require_once 'config/db.php';
require_once 'includes/order_flow.php';
require_once 'includes/pack_role.php';
require_once 'includes/resources_lib.php';

ensureOrderFlowSchema($pdo);
ensurePackRoleSchema($pdo);
ensureResourcesSchema($pdo);

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
if ($isAdmin || !empty($tgProfile['tg_id'])) {
    $botTokenForRoleCheck        = getSiteSetting($pdo, 'BOT_TOKEN') ?: (getenv('TELEGRAM_BOT_TOKEN') ?: getenv('BOT_TOKEN') ?: '');
    $packGroupChatIdForRoleCheck = getSiteSetting($pdo, 'PRIVATE_CHAT_ID') ?: (getenv('PRIVATE_CHAT_ID') ?: '');
    $isPackDesigner = isPackDesigner($pdo, $botTokenForRoleCheck, $packGroupChatIdForRoleCheck, (string)($tgProfile['tg_id'] ?? ''), $isAdmin);
}

if (!$isPackDesigner) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Доступ закрыт | Kostlim Design</title>
    <link rel="icon" type="image/png" href="/assets/img/logo.png" sizes="16x16">
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

function resImg(string $val): string {
    if ($val === '') return '';
    if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) return $val;
    return '/uploads/' . ltrim($val, '/');
}

$psdPosts = listPackResources($pdo, 'psd');
$fonts    = listPackResources($pdo, 'font');
$brushes  = listPackResources($pdo, 'brush');
$videos   = listPackResources($pdo, 'sd_video');
$sdGuide  = getResSetting($pdo, 'SD_INSTALL_GUIDE', '');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Закрытый раздел | Kostlim Design</title>
    <link rel="icon" type="image/png" href="/assets/img/logo.png" sizes="16x16">
    <link rel="stylesheet" href="style.css">
    <style>
        .res-wrap{max-width:920px;margin:24px auto;padding:0 16px;}
        .res-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0;}
        .res-tab-btn{background:#16161d;border:1px solid #2a2a35;color:#e8e8ee;padding:8px 14px;border-radius:999px;cursor:pointer;font-size:13px;}
        .res-tab-btn.active{background:#5b5bd6;border-color:#5b5bd6;}
        .res-panel{display:none;} .res-panel.active{display:block;}
        .res-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;}
        .res-card{background:#16161d;border:1px solid #2a2a35;border-radius:10px;overflow:hidden;text-decoration:none;color:#e8e8ee;display:block;}
        .res-card img,.res-card .ph{width:100%;aspect-ratio:4/3;object-fit:cover;background:#0e0e14;}
        .res-card .ph{display:flex;align-items:center;justify-content:center;font-size:32px;}
        .res-card .cap{padding:10px 12px;font-size:13px;}
        .res-empty{opacity:.6;padding:20px 0;}
        .res-guide{white-space:pre-wrap;line-height:1.6;background:#16161d;border:1px solid #2a2a35;border-radius:10px;padding:16px;}
    </style>
</head>
<body>
<header class="header-compact">
    <div class="brand-title"><a href="index.php"><img src="/assets/img/logo.png" class="brand-logo-img" alt="Kostlim Design" style="height:34px;width:auto;max-width:140px;display:block;margin:0 auto;"></a></div>
</header>

<main class="res-wrap">
    <h1>🔒 Закрытый раздел<?= $isAdmin ? ' <span style="opacity:.5;font-size:14px;">(admin)</span>' : '' ?></h1>

    <div class="res-tabs">
        <button class="res-tab-btn active" data-panel="psd" onclick="resTab('psd')">📁 PSD (<?= count($psdPosts) ?>)</button>
        <button class="res-tab-btn" data-panel="fonts" onclick="resTab('fonts')">🔤 Шрифты (<?= count($fonts) ?>)</button>
        <button class="res-tab-btn" data-panel="brushes" onclick="resTab('brushes')">🎨 Стили и кисти (<?= count($brushes) ?>)</button>
        <button class="res-tab-btn" data-panel="sd" onclick="resTab('sd')">🖥 Stable Diffusion</button>
    </div>

    <div class="res-panel active" id="panel-psd">
        <?php if (empty($psdPosts)): ?>
            <p class="res-empty">Пока пусто — посты появляются автоматически при публикации новых работ в приват-пак.</p>
        <?php else: ?>
        <div class="res-grid">
            <?php foreach ($psdPosts as $r): ?>
                <a class="res-card" href="<?= htmlspecialchars($r['telegram_url']) ?>" target="_blank">
                    <?php $img = resImg((string)$r['preview_image']); ?>
                    <?php if ($img): ?><img src="<?= htmlspecialchars($img) ?>" alt=""><?php else: ?><div class="ph">📁</div><?php endif; ?>
                    <div class="cap"><?= htmlspecialchars($r['title']) ?><br><span style="opacity:.5;">Открыть в Telegram →</span></div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="res-panel" id="panel-fonts">
        <?php if (empty($fonts)): ?>
            <p class="res-empty">Шрифтов пока нет.</p>
        <?php else: ?>
        <div class="res-grid">
            <?php foreach ($fonts as $r): ?>
                <a class="res-card" href="<?= htmlspecialchars($r['file_url']) ?>" target="_blank">
                    <div class="ph">🔤</div>
                    <div class="cap"><?= htmlspecialchars($r['title']) ?><br><span style="opacity:.5;">Скачать →</span></div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="res-panel" id="panel-brushes">
        <?php if (empty($brushes)): ?>
            <p class="res-empty">Стилей и кистей пока нет.</p>
        <?php else: ?>
        <div class="res-grid">
            <?php foreach ($brushes as $r): ?>
                <a class="res-card" href="<?= htmlspecialchars($r['file_url']) ?>" target="_blank">
                    <div class="ph">🎨</div>
                    <div class="cap"><?= htmlspecialchars($r['title']) ?><?php if ($r['description']): ?><br><span style="opacity:.5;"><?= htmlspecialchars($r['description']) ?></span><?php endif; ?></div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="res-panel" id="panel-sd">
        <?php if ($sdGuide !== ''): ?>
            <div class="res-guide"><?= htmlspecialchars($sdGuide) ?></div>
        <?php else: ?>
            <p class="res-empty">Гайд ещё не добавлен.</p>
        <?php endif; ?>

        <h3 style="margin-top:24px;">🎬 Видео установки</h3>
        <?php if (empty($videos)): ?>
            <p class="res-empty">Видео пока нет.</p>
        <?php else: ?>
        <div class="res-grid">
            <?php foreach ($videos as $r): ?>
                <a class="res-card" href="<?= htmlspecialchars($r['video_url']) ?>" target="_blank">
                    <div class="ph">▶️</div>
                    <div class="cap"><?= htmlspecialchars($r['title']) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</main>

<script>
function resTab(name) {
    document.querySelectorAll('.res-tab-btn').forEach(function(b){ b.classList.toggle('active', b.dataset.panel === name); });
    document.querySelectorAll('.res-panel').forEach(function(p){ p.classList.toggle('active', p.id === 'panel-' + name); });
}
</script>
</body>
</html>
