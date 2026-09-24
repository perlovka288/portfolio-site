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
require_once __DIR__ . '/admin/google_drive_helper.php';

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

$myTgId = (string)($tgProfile['tg_id'] ?? '');

// ── Добавление/удаление ресурсов прямо с этой страницы — ТОЛЬКО админ ──
$message = '';
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_resource') {
        $type = $_POST['type'] ?? '';
        if (in_array($type, ['psd', 'font', 'brush', 'sd_video'], true)) {
            $data = [
                'type'        => $type,
                'title'       => trim((string)($_POST['title'] ?? '')),
                'description' => trim((string)($_POST['description'] ?? '')),
            ];
            if ($type === 'psd') {
                $data['preview_image'] = uploadPackResourcePreview('resource_image', __DIR__ . '/uploads/pack_resources/');
                $data['telegram_url']  = trim((string)($_POST['telegram_url'] ?? ''));
            } elseif ($type === 'sd_video') {
                $link = trim((string)($_POST['resource_link'] ?? ''));
                if ($link !== '') {
                    // Готовая ссылка (Блок «большой файл» — form-upload на
                    // бесплатном хостинге ограничен размером POST-запроса и
                    // временем выполнения, поэтому для файлов от ~15-20 МБ
                    // надёжнее вставить прямую ссылку, а не грузить через форму).
                    $data['video_url'] = $link;
                } elseif (!empty($_FILES['resource_file']['name'])) {
                    // FIX (Блок 2.2 ТЗ): раньше при неудачной загрузке на Google
                    // Drive (например, если admin/gdrive_key.json не настроен)
                    // ресурс всё равно создавался с пустой ссылкой, и страница
                    // молча писала "✅ Добавлено" — а при попытке скачать было
                    // "Файл недоступен". Теперь: сначала Google Drive (нужен
                    // для больших видео), при неудаче — на сервер локально.
                    $gd = uploadToGoogleDriveDetailed($_FILES['resource_file']['tmp_name'], basename((string)$_FILES['resource_file']['name']));
                    if ($gd) {
                        $data['video_url'] = $gd['url']; $data['file_id'] = $gd['id']; $data['file_name'] = $gd['name'];
                    } else {
                        $local = uploadPackResourceFileLocal('resource_file', __DIR__ . '/uploads/pack_resources/');
                        if ($local) { $data['video_url'] = $local['url']; $data['file_name'] = $local['file_name']; }
                        else { $message = '❌ Не удалось загрузить видео (ни на Google Drive, ни локально). Проверь admin/gdrive_key.json или права на папку uploads/.'; }
                    }
                } else {
                    $message = '❌ Прикрепи файл или вставь ссылку.';
                }
            } else { // font | brush
                $link = trim((string)($_POST['resource_link'] ?? ''));
                if ($link !== '') {
                    $data['file_url'] = $link;
                } elseif (!empty($_FILES['resource_file']['name'])) {
                    $gd = uploadToGoogleDriveDetailed($_FILES['resource_file']['tmp_name'], basename((string)$_FILES['resource_file']['name']));
                    if ($gd) {
                        $data['file_url'] = $gd['url']; $data['file_id'] = $gd['id']; $data['file_name'] = $gd['name'];
                    } else {
                        $local = uploadPackResourceFileLocal('resource_file', __DIR__ . '/uploads/pack_resources/');
                        if ($local) { $data['file_url'] = $local['url']; $data['file_name'] = $local['file_name']; }
                        else { $message = '❌ Не удалось загрузить файл (ни на Google Drive, ни локально). Проверь admin/gdrive_key.json или права на папку uploads/.'; }
                    }
                } else {
                    $message = '❌ Прикрепи файл или вставь ссылку.';
                }
            }
            if ($message === '') {
                createPackResource($pdo, $data);
                $message = '✅ Добавлено.';
            }
        }
    } elseif ($action === 'delete_resource') {
        deletePackResource($pdo, (int)($_POST['id'] ?? 0));
        $message = '🗑 Удалено.';
    } elseif ($action === 'save_sd_guide') {
        setResSetting($pdo, 'SD_INSTALL_GUIDE', (string)($_POST['sd_guide'] ?? ''));
        $message = '✅ Гайд сохранён.';
    }
    // PRG, чтобы не задваивалась отправка формы по F5
    header('Location: resources.php?ok=1');
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
$favorites = listFavoriteResources($pdo, $myTgId);

$allIds = array_map(fn($r) => (int)$r['id'], array_merge($psdPosts, $fonts, $brushes, $videos, $favorites));
$engagement = getResourceEngagement($pdo, array_unique($allIds), $myTgId);

/**
 * Единая карточка материала — работает и в режиме «Плитка», и в режиме
 * «Список» (раскладку переключает CSS через класс .res-view-list на
 * <main>, разметка одна и та же — см. Блок 2.1 ТЗ).
 */
function resCard(array $r, array $eng, bool $isAdmin): string {
    $id = (int)$r['id'];
    $e = $eng[$id] ?? ['likes' => 0, 'liked' => false, 'favorited' => false];
    $type = $r['type'];

    // Медиа + основная ссылка зависят от типа материала.
    if ($type === 'psd') {
        $img = resImg((string)$r['preview_image']);
        $media = $img
            ? '<img src="' . htmlspecialchars($img) . '" alt="" onerror="this.parentElement.classList.add(\'media-broken\')">'
            : '<div class="service-cover-placeholder"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>';
        $sub = 'Открыть в Telegram';
        // Блок 2.2 ТЗ: у PSD вместо кнопки «Заказать» — круглая оранжевая
        // кнопка-иконка Telegram.
        $dlBtn = '<a class="res-tg-btn" href="' . htmlspecialchars($r['telegram_url']) . '" target="_blank" title="Открыть пост в Telegram" onclick="event.stopPropagation()">✈️</a>';
    } elseif ($type === 'sd_video') {
        $media = '<div class="service-cover-placeholder"><span style="font-size:26px;">▶️</span></div>';
        $sub = 'Видео-инструкция';
        $dlBtn = '<a class="res-dl-btn" href="download.php?rid=' . $id . '" title="Скачать" onclick="event.stopPropagation()">📥</a>';
    } else { // font | brush
        $icon = $type === 'font' ? '🔤' : '🎨';
        $media = '<div class="service-cover-placeholder"><span style="font-size:26px;">' . $icon . '</span></div>';
        $sub = $type === 'font' ? 'Шрифт' : (string)($r['description'] ?: 'Стили и кисти');
        $dlBtn = '<a class="res-dl-btn" href="download.php?rid=' . $id . '" title="Скачать" onclick="event.stopPropagation()">📥</a>';
    }

    $delBtn = '';
    if ($isAdmin) {
        $delBtn = '<form class="res-del-form" method="post" onsubmit="return confirm(\'Удалить?\')"><input type="hidden" name="action" value="delete_resource"><input type="hidden" name="id" value="' . $id . '"><button class="res-del-btn" type="submit">✕</button></form>';
    }

    $likedClass = $e['liked'] ? ' is-active' : '';
    $favClass   = $e['favorited'] ? ' is-active' : '';

    return '
    <div class="res-card-wrap" data-rid="' . $id . '">
        ' . $delBtn . '
        <div class="res-card-media">' . $media . '</div>
        <div class="res-card-body">
            <h3>' . htmlspecialchars($r['title']) . '</h3>
            <span class="res-card-sub">' . htmlspecialchars($sub) . '</span>
        </div>
        <div class="res-card-actions">
            <button type="button" class="res-like-btn' . $likedClass . '" data-rid="' . $id . '" title="Нравится">❤️ <span class="res-like-count">' . (int)$e['likes'] . '</span></button>
            <button type="button" class="res-fav-btn' . $favClass . '" data-rid="' . $id . '" title="В избранное">⭐</button>
            ' . $dlBtn . '
        </div>
    </div>';
}

function resSection(array $items, array $eng, bool $isAdmin, string $emptyText): string {
    if (empty($items)) {
        return '<p style="text-align:center;color:var(--text2);padding:30px 0;">' . htmlspecialchars($emptyText) . '</p>';
    }
    $html = '<section class="price-grid-local">';
    foreach ($items as $r) { $html .= resCard($r, $eng, $isAdmin); }
    return $html . '</section>';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Kostlim Design | Закрытый раздел</title>
    <link rel="icon" type="image/png" href="/assets/img/logo.png" sizes="16x16">
    <link rel="apple-touch-icon" href="/assets/img/logo.png">
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
    <link rel="stylesheet" href="assets/kostlim-upgrade.css?v=<?= @filemtime(__DIR__ . '/assets/kostlim-upgrade.css') ?: time() ?>">
    <style>
        .res-tabs { display:flex; gap:10px; flex-wrap:wrap; justify-content:center; margin-bottom:22px; }
        .res-tab-btn {
            background: var(--card); border: 1px solid var(--border); color: var(--text2);
            padding: 9px 18px; border-radius: 999px; cursor: pointer; font-size: 12.5px;
            font-weight: 700; font-family: inherit; transition: all .2s;
        }
        .res-tab-btn:hover { border-color: rgba(249,115,22,.35); color: var(--accent); }
        .res-tab-btn.active { background: linear-gradient(135deg, var(--accent2), var(--accent)); color:#fff; border-color: transparent; box-shadow: 0 0 16px rgba(249,115,22,.3); }
        .res-panel { display:none; } .res-panel.active { display:block; }
        .res-panel-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .res-panel-head h2 { margin:0; font-size:16px; }
        .res-add-btn {
            width:38px; height:38px; border-radius:50%; border:none; flex-shrink:0;
            background: linear-gradient(135deg, var(--accent2), var(--accent)); color:#fff;
            font-size:22px; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center;
            box-shadow: 0 4px 14px rgba(249,115,22,.35); transition: transform .15s;
        }
        .res-add-btn:hover { transform: scale(1.08); }
        .res-add-form { display:none; background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 18px; margin-bottom: 22px; }
        .res-add-form.show { display:block; }
        .res-add-form input[type=text], .res-add-form textarea, .res-add-form input[type=file] {
            width:100%; box-sizing:border-box; background: rgba(0,0,0,.15); border:1px solid var(--border); color: var(--text);
            padding:9px 11px; border-radius:8px; font-family:inherit; margin-bottom:10px; font-size:13px;
        }
        .res-add-form textarea { min-height:70px; resize:vertical; }
        .res-del-form { display:inline; }
        .res-del-btn { position:absolute; top:8px; right:8px; z-index:2; background:rgba(0,0,0,.55); color:#fff; border:none; border-radius:6px; width:26px; height:26px; cursor:pointer; }
        .res-guide { white-space:pre-wrap; line-height:1.7; background: var(--card); border:1px solid var(--border); border-radius:12px; padding:20px; color: var(--text2); }

        /* ── Переключатель Плитка/Список (Блок 2.1 ТЗ), сохраняется в localStorage ── */
        .res-view-switch { display:flex; gap:4px; justify-content:center; margin-bottom:18px; }
        .res-view-btn {
            background: var(--card); border:1px solid var(--border); color:var(--text2);
            padding:7px 14px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit;
        }
        .res-view-btn:first-child { border-radius:8px 0 0 8px; }
        .res-view-btn:last-child { border-radius:0 8px 8px 0; }
        .res-view-btn.active { background: linear-gradient(135deg, var(--accent2), var(--accent)); color:#fff; border-color:transparent; }

        /* ── Карточка (единая разметка для плитки и списка) ── */
        .res-card-wrap { position:relative; background: var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; display:flex; flex-direction:column; }
        .res-card-media { aspect-ratio:16/9; max-height:220px; overflow:hidden; background: rgba(0,0,0,.2); display:flex; align-items:center; justify-content:center; }
        .res-card-media img { width:100%; height:100%; object-fit:cover; }
        /* FIX: если превью-картинка не загрузилась (404/удалена) — вместо
           битой иконки браузера показываем плашку-заглушку, а не голый
           чёрный блок на всю ширину плитки (см. media-broken на img onerror). */
        .res-card-media.media-broken img { display:none; }
        .res-card-media.media-broken::after { content:'🖼'; font-size:26px; opacity:.5; }
        .res-card-body { padding:12px 14px 4px; flex:1; }
        .res-card-body h3 { margin:0 0 4px; font-size:14px; }
        .res-card-sub { color: var(--text2); font-size:12px; }
        .res-card-actions { display:flex; align-items:center; gap:8px; padding:10px 14px 14px; }
        .res-like-btn, .res-fav-btn, .res-dl-btn, .res-tg-btn {
            background: rgba(255,255,255,.06); border:1px solid var(--border); color: var(--text2);
            border-radius:8px; padding:7px 10px; font-size:12px; cursor:pointer; font-family:inherit;
            display:flex; align-items:center; gap:5px; text-decoration:none; line-height:1;
        }
        .res-like-btn.is-active { color:#ff5a7a; border-color:rgba(255,90,122,.4); background:rgba(255,90,122,.08); }
        .res-fav-btn.is-active { color: var(--accent); border-color: rgba(249,115,22,.4); background: rgba(249,115,22,.08); }
        .res-dl-btn, .res-tg-btn {
            margin-left:auto; width:32px; height:32px; padding:0; justify-content:center;
            background: linear-gradient(135deg, var(--accent2), var(--accent)); color:#fff; border:none;
            box-shadow: 0 4px 12px rgba(249,115,22,.3);
        }

        /* ── Режим «Список»: вытянутые строки [превью] название --- [действия] ── */
        .res-view-list .price-grid-local { grid-template-columns: 1fr; gap:8px; }
        .res-view-list .res-card-wrap { flex-direction:row; align-items:center; border-radius:10px; }
        .res-view-list .res-card-media { width:56px; height:56px; flex:0 0 56px; aspect-ratio:auto; border-radius:8px; margin:8px 0 8px 10px; }
        .res-view-list .res-card-body { padding:8px 10px; }
        .res-view-list .res-card-actions { padding:8px 12px 8px 0; }
        .res-view-list .res-del-btn { top:6px; right:6px; }

        @media (max-width:520px) {
            .res-view-list .res-card-body h3 { font-size:12.5px; }
            .res-view-list .res-card-sub { display:none; }
        }
    </style>
</head>
<body>

<header>
    <div class="header-left">
        <a href="index.php" class="nav-link">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            На главную
        </a>
    </div>
    <div class="brand-title"><a href="index.php"><img src="/assets/img/logo.png" class="brand-logo-img" alt="Kostlim Design" style="height:40px;width:auto;max-width:160px;display:block;"></a></div>
    <div class="header-right">
        <?php if ($isAdmin): ?>
        <a href="admin/resources.php" class="nav-link">⚙️ Управление</a>
        <?php endif; ?>
    </div>
</header>

<main class="container price-page" id="resMain">
    <div class="price-head">
        <h1>🔒 Закрытый раздел</h1>
        <p>Материалы и инструменты для дизайнеров пака<?= $isAdmin ? ' · режим администратора' : '' ?></p>
    </div>

    <?php if ($message): ?><p style="text-align:center;color:var(--accent);margin-bottom:20px;"><?= htmlspecialchars($message) ?></p><?php endif; ?>

    <div class="res-view-switch">
        <button type="button" class="res-view-btn active" data-view="tile" onclick="resSetView('tile')">▦ Плитка</button>
        <button type="button" class="res-view-btn" data-view="list" onclick="resSetView('list')">☰ Список</button>
    </div>

    <div class="res-tabs">
        <button class="res-tab-btn active" data-panel="psd" onclick="resTab('psd')">📁 PSD (<?= count($psdPosts) ?>)</button>
        <button class="res-tab-btn" data-panel="fonts" onclick="resTab('fonts')">🔤 Шрифты (<?= count($fonts) ?>)</button>
        <button class="res-tab-btn" data-panel="brushes" onclick="resTab('brushes')">🎨 Стили и кисти (<?= count($brushes) ?>)</button>
        <button class="res-tab-btn" data-panel="sd" onclick="resTab('sd')">🖥 Stable Diffusion</button>
        <button class="res-tab-btn" data-panel="fav" onclick="resTab('fav')">⭐ Избранное (<?= count($favorites) ?>)</button>
    </div>

    <!-- PSD -->
    <div class="res-panel active" id="panel-psd">
        <div class="res-panel-head"><h2>📁 PSD-паки</h2><?php if ($isAdmin): ?><button type="button" class="res-add-btn" title="Добавить PSD-пост" onclick="document.getElementById('form-psd').classList.toggle('show')">+</button><?php endif; ?></div>
        <?php if ($isAdmin): ?>
        <form class="res-add-form" id="form-psd" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_resource"><input type="hidden" name="type" value="psd">
            <input type="text" name="title" placeholder="Название поста" required>
            <input type="text" name="telegram_url" placeholder="Ссылка на сообщение в TG (t.me/c/.../ID)" required>
            <input type="file" name="resource_image" accept="image/*">
            <button type="submit" class="save-all-btn">Добавить</button>
        </form>
        <?php endif; ?>
        <?= resSection($psdPosts, $engagement, $isAdmin, 'Пока пусто — посты появляются автоматически при публикации новых работ в приват-пак.') ?>
    </div>

    <!-- Fonts -->
    <div class="res-panel" id="panel-fonts">
        <div class="res-panel-head"><h2>🔤 Шрифты</h2><?php if ($isAdmin): ?><button type="button" class="res-add-btn" title="Добавить шрифт" onclick="document.getElementById('form-fonts').classList.toggle('show')">+</button><?php endif; ?></div>
        <?php if ($isAdmin): ?>
        <form class="res-add-form" id="form-fonts" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_resource"><input type="hidden" name="type" value="font">
            <input type="text" name="title" placeholder="Название шрифта" required>
            <input type="file" name="resource_file" accept=".ttf,.otf">
            <input type="text" name="resource_link" placeholder="...или вставь готовую ссылку на файл (если он большой)">
            <button type="submit" class="save-all-btn">Добавить</button>
        </form>
        <?php endif; ?>
        <?= resSection($fonts, $engagement, $isAdmin, 'Шрифтов пока нет.') ?>
    </div>

    <!-- Brushes -->
    <div class="res-panel" id="panel-brushes">
        <div class="res-panel-head"><h2>🎨 Стили и кисти</h2><?php if ($isAdmin): ?><button type="button" class="res-add-btn" title="Добавить набор" onclick="document.getElementById('form-brushes').classList.toggle('show')">+</button><?php endif; ?></div>
        <?php if ($isAdmin): ?>
        <form class="res-add-form" id="form-brushes" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_resource"><input type="hidden" name="type" value="brush">
            <input type="text" name="title" placeholder="Название набора" required>
            <textarea name="description" placeholder="Описание (необязательно)"></textarea>
            <input type="file" name="resource_file" accept=".abr,.asl,.zip,.rar,.7z">
            <input type="text" name="resource_link" placeholder="...или вставь готовую ссылку на файл (если он большой — загрузка через форму ограничена хостингом)">
            <button type="submit" class="save-all-btn">Добавить</button>
        </form>
        <?php endif; ?>
        <?= resSection($brushes, $engagement, $isAdmin, 'Стилей и кистей пока нет.') ?>
    </div>

    <!-- SD -->
    <div class="res-panel" id="panel-sd">
        <div class="res-panel-head"><h2>🖥 Гайд по установке</h2><?php if ($isAdmin): ?><button type="button" class="res-add-btn" title="Изменить гайд" onclick="document.getElementById('form-sd-guide').classList.toggle('show')">✏️</button><?php endif; ?></div>
        <?php if ($isAdmin): ?>
        <form class="res-add-form" id="form-sd-guide" method="post">
            <input type="hidden" name="action" value="save_sd_guide">
            <textarea name="sd_guide" style="min-height:180px;" placeholder="Текст гайда, полезные ссылки..."><?= htmlspecialchars($sdGuide) ?></textarea>
            <button type="submit" class="save-all-btn">Сохранить гайд</button>
        </form>
        <?php endif; ?>
        <?php if ($sdGuide !== ''): ?>
            <div class="res-guide"><?= htmlspecialchars($sdGuide) ?></div>
        <?php else: ?>
            <p style="text-align:center;color:var(--text2);padding:20px 0;">Гайд ещё не добавлен.</p>
        <?php endif; ?>

        <div class="res-panel-head" style="margin-top:36px;"><h2>🎬 Видео установки</h2><?php if ($isAdmin): ?><button type="button" class="res-add-btn" title="Добавить видео" onclick="document.getElementById('form-sdvideo').classList.toggle('show')">+</button><?php endif; ?></div>
        <?php if ($isAdmin): ?>
        <form class="res-add-form" id="form-sdvideo" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_resource"><input type="hidden" name="type" value="sd_video">
            <input type="text" name="title" placeholder="Название видео" required>
            <input type="file" name="resource_file" accept="video/*">
            <input type="text" name="resource_link" placeholder="...или вставь готовую ссылку на видео (если оно большое)">
            <button type="submit" class="save-all-btn">Загрузить</button>
        </form>
        <?php endif; ?>
        <?= resSection($videos, $engagement, $isAdmin, 'Видео пока нет.') ?>
    </div>

    <!-- Избранное -->
    <div class="res-panel" id="panel-fav">
        <div class="res-panel-head"><h2>⭐ Избранное</h2></div>
        <?= resSection($favorites, $engagement, false, 'Пока ничего не добавлено — нажимай 🔖 на понравившихся материалах.') ?>
    </div>
</main>

<script>
function resTab(name) {
    document.querySelectorAll('.res-tab-btn').forEach(function(b){ b.classList.toggle('active', b.dataset.panel === name); });
    document.querySelectorAll('.res-panel').forEach(function(p){ p.classList.toggle('active', p.id === 'panel-' + name); });
}

// Переключатель Плитка/Список — режим сохраняется в localStorage, чтобы
// не сбрасывался при перезагрузке страницы (Блок 2.1 ТЗ).
function resSetView(mode) {
    document.getElementById('resMain').classList.toggle('res-view-list', mode === 'list');
    document.querySelectorAll('.res-view-btn').forEach(function(b){ b.classList.toggle('active', b.dataset.view === mode); });
    try { localStorage.setItem('res_view_mode', mode); } catch (e) {}
}
(function(){
    var saved = 'tile';
    try { saved = localStorage.getItem('res_view_mode') || 'tile'; } catch (e) {}
    if (saved === 'list') resSetView('list');
})();

// Лайки/избранное — оптимистичное обновление UI + запрос в resources_api.php.
document.addEventListener('click', async function(ev){
    var likeBtn = ev.target.closest('.res-like-btn');
    var favBtn = ev.target.closest('.res-fav-btn');
    if (!likeBtn && !favBtn) return;
    var btn = likeBtn || favBtn;
    var rid = btn.dataset.rid;
    var action = likeBtn ? 'toggle_like' : 'toggle_favorite';
    btn.disabled = true;
    try {
        var res = await fetch('resources_api.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: action, resource_id: rid })
        });
        var r = await res.json();
        if (r.ok) {
            if (likeBtn) {
                likeBtn.classList.toggle('is-active', r.liked);
                var countEl = likeBtn.querySelector('.res-like-count');
                if (countEl) countEl.textContent = r.likes;
                // Тот же материал может быть виден и в других вкладках/списке —
                // синхронизируем все его карточки на странице.
                document.querySelectorAll('.res-like-btn[data-rid="' + rid + '"]').forEach(function(b){
                    b.classList.toggle('is-active', r.liked);
                    var c = b.querySelector('.res-like-count'); if (c) c.textContent = r.likes;
                });
            } else {
                document.querySelectorAll('.res-fav-btn[data-rid="' + rid + '"]').forEach(function(b){
                    b.classList.toggle('is-active', r.favorited);
                });
            }
        } else if (r.error) {
            alert(r.error);
        }
    } catch (e) {}
    btn.disabled = false;
});
</script>
</body>
</html>
