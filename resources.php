<?php
// Закрытый раздел ресурсов (Блок 3 ТЗ) — доступен Admin и Designer PPK.
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once 'includes/session.php';
require_once __DIR__ . '/includes/ppk_icons.php';
require_once 'config/db.php';
require_once 'includes/order_flow.php';
require_once 'includes/pack_role.php';
require_once 'includes/resources_lib.php';
require_once 'includes/ppk_access.php';
require_once 'includes/notifications_lib.php';
require_once 'includes/notifications_bell.php';
require_once __DIR__ . '/admin/google_drive_helper.php';
require_once __DIR__ . '/includes/rich_editor.php';

ensureOrderFlowSchema($pdo);
ensurePackRoleSchema($pdo);
ensureResourcesSchema($pdo);
ensureNotificationsSchema($pdo);

// Единая точка доступа ADMIN/PPK (тот же resolvePpkAccess, что и в
// planner.php/ai_trainer.php) — учитывает в т.ч. ручную выдачу роли в
// админке, чего не было в прежней локальной проверке этого файла.
$access = resolvePpkAccess($pdo);
$isAdmin = $access['isAdmin'];
$isPackDesigner = $access['isPackDesigner'];
$tgProfile = $access['tgProfile'];

if (!$isPackDesigner) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Доступ закрыт | Kostlim Design</title>
    <link rel="icon" type="image/png" href="/assets/img/logo-64.png" sizes="16x16">
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

$myTgId = $access['tgId'];

// ── Все изменения (добавить / править / удалить материалы и разделы) — ТОЛЬКО админ ──
$flashMsg = '';
$flashType = 'ok';
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = (string)($_POST['action'] ?? '');
    $backTab = preg_replace('/[^a-z0-9_]/i', '', (string)($_POST['tab_back'] ?? ''));
    $secList = getPackSections($pdo);
    $secSlugs = array_column($secList, 'slug');
    $secTitle = [];
    foreach ($secList as $sx) { $secTitle[$sx['slug']] = $sx['title']; }
    $localDir = __DIR__ . '/uploads/pack_resources/';
    $imgWarn = '';

    try {
        if ($action === 'add_resource') {
            $type = (string)($_POST['type'] ?? '');
            $title = trim((string)($_POST['title'] ?? ''));
            if (!in_array($type, $secSlugs, true)) {
                $flashMsg = 'Неизвестный раздел.'; $flashType = 'err';
            } elseif ($title === '') {
                $flashMsg = 'Укажи название.'; $flashType = 'err';
            } else {
                $err = null;
                $src = packResourceSourceFromPost($type, $_POST, $localDir, $err);
                if ($err !== null) {
                    $flashMsg = $err; $flashType = 'err';
                } else {
                    $data = array_merge([
                        'type' => $type, 'title' => $title,
                        'description' => trim((string)($_POST['description'] ?? '')),
                    ], $src);
                    // Превью — для ЛЮБОГО раздела (необязательно)
                    if (!empty($_FILES['resource_image']['name'])) {
                        $data['preview_image'] = uploadPackResourcePreview($pdo, 'resource_image', $localDir);
                        if ($data['preview_image'] === '') { $imgWarn = $GLOBALS['kuiImgWarn'] ?? 'Превью не загрузилось.'; }
                    }
                    createPackResource($pdo, $data);
                    $flashMsg = $imgWarn !== '' ? 'Добавлено, но ' . $imgWarn : 'Добавлено.';
                    if ($imgWarn !== '') $flashType = 'err';
                    broadcastNotification($pdo, 'new_resource', '📁 Новый материал: ' . ($secTitle[$type] ?? $type),
                        $title, 'resources.php', $myTgId);
                }
                $backTab = $type;
            }
        } elseif ($action === 'update_resource') {
            $rid = (int)($_POST['id'] ?? 0);
            $cur = $rid > 0 ? getPackResource($pdo, $rid) : null;
            $title = trim((string)($_POST['title'] ?? ''));
            if (!$cur) {
                $flashMsg = 'Материал не найден.'; $flashType = 'err';
            } elseif ($title === '') {
                $flashMsg = 'Название не может быть пустым.'; $flashType = 'err';
                $backTab = $cur['type'];
            } else {
                $backTab = $cur['type'];
                $err = null;
                $src = packResourceSourceFromPost($cur['type'], $_POST, $localDir, $err);
                if ($err !== null) {
                    $flashMsg = $err; $flashType = 'err';
                } else {
                    $fields = array_merge(['title' => $title, 'description' => trim((string)($_POST['description'] ?? ''))], $src);
                    if (!empty($_POST['remove_preview'])) {
                        $fields['preview_image'] = '';
                    }
                    if (!empty($_FILES['resource_image']['name'])) {
                        $newPrev = uploadPackResourcePreview($pdo, 'resource_image', $localDir);
                        if ($newPrev !== '') { $fields['preview_image'] = $newPrev; }
                        else { $imgWarn = $GLOBALS['kuiImgWarn'] ?? 'Превью не загрузилось.'; }
                    }
                    updatePackResource($pdo, $rid, $fields);
                    $flashMsg = $imgWarn !== '' ? 'Сохранено, но ' . $imgWarn : 'Изменения сохранены.';
                    if ($imgWarn !== '') $flashType = 'err';
                }
            }
        } elseif ($action === 'delete_resource') {
            $rid = (int)($_POST['id'] ?? 0);
            $cur = $rid > 0 ? getPackResource($pdo, $rid) : null;
            if ($cur) { $backTab = $cur['type']; }
            deletePackResource($pdo, $rid);
            $flashMsg = 'Удалено.';
        } elseif ($action === 'save_sd_guide') {
            setResSetting($pdo, 'SD_INSTALL_GUIDE', (string)($_POST['sd_guide'] ?? ''));
            $flashMsg = 'Гайд сохранён.';
            $backTab = 'sd_video';
        } elseif ($action === 'save_section') {
            $slug = preg_replace('/[^a-z0-9_]/i', '', (string)($_POST['slug'] ?? ''));
            $sTitle = (string)($_POST['sec_title'] ?? '');
            $sIcon  = (string)($_POST['sec_icon'] ?? '');
            if ($slug === '') {
                $newSlug = createPackSection($pdo, $sTitle, $sIcon);
                if ($newSlug === '') { $flashMsg = 'Укажи название раздела.'; $flashType = 'err'; }
                else { $flashMsg = 'Раздел создан.'; $backTab = $newSlug; }
            } elseif (in_array($slug, $secSlugs, true)) {
                if (updatePackSection($pdo, $slug, $sTitle, $sIcon)) { $flashMsg = 'Раздел обновлён.'; $backTab = $slug; }
                else { $flashMsg = 'Укажи название раздела.'; $flashType = 'err'; $backTab = $slug; }
            }
        } elseif ($action === 'delete_section') {
            $slug = preg_replace('/[^a-z0-9_]/i', '', (string)($_POST['slug'] ?? ''));
            if (deletePackSection($pdo, $slug)) { $flashMsg = 'Раздел удалён.'; $backTab = ''; }
            else { $flashMsg = 'Встроенный раздел удалить нельзя — его можно переименовать.'; $flashType = 'err'; }
        }
    } catch (Throwable $e) {
        error_log('resources.php action error: ' . $e->getMessage());
        $flashMsg = 'Ошибка сервера. Попробуй ещё раз.'; $flashType = 'err';
    }
    // PRG, чтобы не задваивалась отправка формы по F5
    $q = ['m' => $flashMsg, 't' => $flashType];
    if ($backTab !== '') $q['tab'] = $backTab;
    header('Location: resources.php?' . http_build_query($q));
    exit;
}
if (isset($_GET['m'])) {
    $flashMsg = mb_substr(trim((string)$_GET['m']), 0, 220);
    $flashType = (($_GET['t'] ?? '') === 'err') ? 'err' : 'ok';
}

function resImg(string $val): string {
    if ($val === '') return '';
    if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) return $val;
    return '/uploads/' . ltrim($val, '/');
}

$sections = getPackSections($pdo);
$secBySlug = [];
foreach ($sections as $sx) { $secBySlug[$sx['slug']] = $sx; }

$itemsBySlug = [];
$allRes = [];
foreach ($sections as $sx) {
    $itemsBySlug[$sx['slug']] = listPackResources($pdo, $sx['slug']);
    $allRes = array_merge($allRes, $itemsBySlug[$sx['slug']]);
}
$sdGuide   = getResSetting($pdo, 'SD_INSTALL_GUIDE', '');
$favorites = listFavoriteResources($pdo, $myTgId);

$allIds = array_map(fn($r) => (int)$r['id'], array_merge($allRes, $favorites));
$engagement = getResourceEngagement($pdo, array_unique($allIds), $myTgId);

/** Допустимые расширения файла в зависимости от раздела (пусто = любые) */
function resAccept(string $slug): string {
    $map = [
        'psd'      => '.psd,.psb,.zip,.rar,.7z',
        'font'     => '.ttf,.otf,.woff,.woff2,.zip,.rar,.7z',
        'brush'    => '.abr,.asl,.atn,.grd,.pat,.zip,.rar,.7z',
        'sd_video' => 'video/*,.zip,.rar,.7z',
    ];
    return $map[$slug] ?? '';
}

/**
 * Единая карточка материала — работает и в режиме «Плитка», и в режиме
 * «Список» (раскладку переключает CSS через класс .res-view-list на <main>).
 * Превью есть у материала ЛЮБОГО раздела; нет превью — плашка с иконкой раздела.
 */
function resCard(array $r, array $eng, bool $isAdmin, array $sec): string {
    $id = (int)$r['id'];
    $e = $eng[$id] ?? ['likes' => 0, 'liked' => false, 'favorited' => false];
    $type = (string)$r['type'];
    $secIcon = packIconKey((string)($sec['icon'] ?? ''));
    $secTitle = $sec['title'] !== '' ? $sec['title'] : $type;

    $img = resImg((string)$r['preview_image']);
    $media = $img
        ? '<img src="' . htmlspecialchars($img) . '" alt="" loading="lazy" onerror="this.parentElement.classList.add(\'media-broken\')">'
        : '<div class="service-cover-placeholder">' . ppkIcon($secIcon) . '</div>';

    $tg = trim((string)$r['telegram_url']);
    $fileUrl = $type === 'sd_video' ? (string)$r['video_url'] : (string)$r['file_url'];
    $desc = trim((string)$r['description']);
    if ($tg !== '') {
        $href = htmlspecialchars($tg);
        $ctaLabel = 'Открыть в Telegram'; $ctaIcon = 'send'; $ctaAttr = ' target="_blank" rel="noopener"'; $ctaTitle = 'Открыть пост в Telegram';
        $sub = $desc !== '' ? $desc : 'Открыть пост в Telegram';
    } else {
        $href = 'download.php?rid=' . $id;
        $ctaLabel = 'Скачать'; $ctaIcon = 'download'; $ctaAttr = ''; $ctaTitle = 'Скачать';
        $sub = $desc !== '' ? $desc : 'Скачать материал';
    }

    $adminCtl = '';
    if ($isAdmin) {
        $isExternal = $fileUrl !== '' && !str_starts_with($fileUrl, '/uploads/');
        $payload = [
            'id' => $id, 'type' => $type, 'title' => (string)$r['title'], 'description' => $desc,
            'preview' => $img, 'telegram_url' => $tg, 'link' => $isExternal ? $fileUrl : '',
            'has_file' => $fileUrl !== '', 'accept' => resAccept($type), 'section' => $secTitle,
        ];
        $json = htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS), ENT_QUOTES);
        $adminCtl = '<div class="res-admin-ctl">'
            . '<button type="button" class="rib" title="Редактировать" data-edit="' . $json . '">' . ppkIcon('edit') . '</button>'
            . '<button type="button" class="rib rib--danger" title="Удалить" data-del="' . $id . '" data-del-title="' . htmlspecialchars((string)$r['title'], ENT_QUOTES) . '">' . ppkIcon('trash') . '</button>'
            . '</div>';
    }

    $likedClass = $e['liked'] ? ' is-active' : '';
    $favClass   = $e['favorited'] ? ' is-active' : '';
    $tagsHtml = '<span class="hc hc--sm hc--secondary hc--accent">' . htmlspecialchars($secTitle) . '</span>';
    $cta = '<a class="rd-cta" href="' . $href . '"' . $ctaAttr . ' onclick="event.stopPropagation()">' . ppkIcon($ctaIcon) . $ctaLabel . '</a>';
    $act = '<a class="res-act ' . ($ctaIcon === 'send' ? 'res-tg-btn' : 'res-dl-btn') . '" href="' . $href . '"' . $ctaAttr . ' title="' . $ctaTitle . '" onclick="event.stopPropagation()">' . ppkIcon($ctaIcon) . '</a>';

    return '
    <div class="res-card-wrap" data-rid="' . $id . '">
        ' . $adminCtl . '
        <div class="res-card-media">' . $media . '<span class="rd-shade"></span>
            <div class="rd-tags">' . $tagsHtml . '</div>
            <div class="rd-hover">' . $cta . '</div>
        </div>
        <div class="res-card-body">
            <h3>' . htmlspecialchars((string)$r['title']) . '</h3>
            <span class="res-card-sub">' . htmlspecialchars($sub) . '</span>
        </div>
        <div class="res-card-actions">
            <div class="res-card-act-l">
                <button type="button" class="res-like-btn' . $likedClass . '" data-rid="' . $id . '" title="Нравится">' . ppkIcon('heart') . '<span class="res-like-count">' . (int)$e['likes'] . '</span></button>
            </div>
            <div class="res-card-act-l">
                ' . $act . '
                <button type="button" class="res-fav-btn' . $favClass . '" data-rid="' . $id . '" title="В избранное">' . ppkIcon('bookmark') . '</button>
            </div>
        </div>
    </div>';
}

function resSection(array $items, array $eng, bool $isAdmin, string $emptyText, array $secBySlug): string {
    if (empty($items)) {
        return '<p class="res-empty">' . htmlspecialchars($emptyText) . '</p>';
    }
    $html = '<section class="price-grid-local">';
    foreach ($items as $r) {
        $sec = $secBySlug[$r['type']] ?? ['title' => (string)$r['type'], 'icon' => 'box'];
        $html .= resCard($r, $eng, $isAdmin, $sec);
    }
    return $html . '</section>';
}

/**
 * Форма материала (общая для «Добавить» и «Редактировать»).
 * Поля: название, описание, превью (картинка — для любого раздела),
 * источник — переключатель «Файл / Ссылка / Telegram» (+ «Оставить» при правке).
 */
function resItemFields(string $slug, bool $edit): string {
    $defMode = $edit ? 'keep' : ($slug === 'psd' ? 'tg' : 'file');
    $modes = [];
    if ($edit) $modes['keep'] = ['check', 'Оставить'];
    $modes['file'] = ['upload', 'Файл'];
    $modes['link'] = ['link', 'Ссылка'];
    $modes['tg']   = ['send', 'Telegram'];

    $seg = '<div class="rf-seg" role="radiogroup" aria-label="Источник материала">';
    foreach ($modes as $val => [$ic, $lab]) {
        $seg .= '<label class="rf-seg-opt"><input type="radio" name="src_mode" value="' . $val . '"' . ($val === $defMode ? ' checked' : '') . '><span>' . ppkIcon($ic) . $lab . '</span></label>';
    }
    $seg .= '</div>';

    $accept = resAccept($slug);
    $on = function (string $m) use ($defMode): string { return $m === $defMode ? ' is-on' : ''; };

    $h  = '<div class="rf-field"><label class="rf-lab">Название</label>'
        . '<input class="rf-input" type="text" name="title" placeholder="Например: Neon Pack Vol.2" required maxlength="200"></div>';
    $h .= '<div class="rf-field"><label class="rf-lab">Описание <em>необязательно</em></label>'
        . '<textarea class="rf-input rf-area" name="description" placeholder="Коротко: что внутри, для чего подходит" maxlength="600"></textarea></div>';

    // превью
    $h .= '<div class="rf-field"><label class="rf-lab">Превью <em>картинка — для любого раздела</em></label>'
        . '<div class="rf-prev-row">'
        . '<label class="rf-drop rf-drop--img" data-kind="image">'
        . '<input type="file" name="resource_image" accept="image/*">'
        . '<span class="rf-drop-ico">' . ppkIcon('image') . '</span>'
        . '<span class="rf-drop-t"><b>Выбрать картинку</b><small>или перетащи сюда · JPG, PNG, WEBP</small></span>'
        . '<img class="rf-drop-thumb" alt="">'
        . '</label>'
        . ($edit ? '<label class="rf-switch"><input type="checkbox" name="remove_preview" value="1"><span class="rf-switch-track"><i></i></span><span class="rf-switch-t">Убрать превью</span></label>' : '')
        . '</div></div>';

    // источник
    $h .= '<div class="rf-field"><label class="rf-lab">Источник</label>' . $seg;
    if ($edit) {
        $h .= '<div class="rf-src is-on" data-src="keep"><p class="rf-hint">Текущий файл или ссылка останутся без изменений.</p></div>';
    }
    $h .= '<div class="rf-src' . $on('file') . '" data-src="file">'
        . '<label class="rf-drop" data-kind="file"><input type="file" name="resource_file"' . ($accept !== '' ? ' accept="' . $accept . '"' : '') . '>'
        . '<span class="rf-drop-ico">' . ppkIcon('upload') . '</span>'
        . '<span class="rf-drop-t"><b>Выбрать файл</b><small>или перетащи сюда</small></span></label>'
        . '<p class="rf-hint">Большие файлы (от ~20 МБ) надёжнее добавлять через «Ссылка» — загрузка через форму ограничена хостингом.</p></div>';
    $h .= '<div class="rf-src' . $on('link') . '" data-src="link">'
        . '<input class="rf-input" type="text" name="resource_link" placeholder="https://drive.google.com/file/d/…" autocomplete="off">'
        . '<p class="rf-hint">Google Drive определяется автоматически — скачивание пойдёт напрямую.</p></div>';
    $h .= '<div class="rf-src' . $on('tg') . '" data-src="tg">'
        . '<input class="rf-input" type="text" name="telegram_url" placeholder="https://t.me/c/…/123" autocomplete="off">'
        . '<p class="rf-hint">Карточка будет открывать этот пост в Telegram.</p></div>';
    $h .= '</div>';
    return $h;
}

/** Блок «+»: раскрывающаяся форма добавления материала в раздел */
function resAddBlock(string $slug, string $secTitle): string {
    $id = 'add-' . $slug;
    return '<div class="rf-wrap" id="' . $id . '"><div class="rf-inner">'
        . '<form class="rf-form" method="post" enctype="multipart/form-data">'
        . '<input type="hidden" name="action" value="add_resource"><input type="hidden" name="type" value="' . htmlspecialchars($slug) . '">'
        . '<input type="hidden" name="tab_back" value="' . htmlspecialchars($slug) . '">'
        . '<div class="rf-form-head"><h4>Новый материал · ' . htmlspecialchars($secTitle) . '</h4></div>'
        . resItemFields($slug, false)
        . '<div class="rf-actions"><button type="submit" class="rf-btn rf-btn--primary">' . ppkIcon('check') . '<span>Добавить</span></button>'
        . '<button type="button" class="rf-btn rf-btn--ghost" data-toggle="' . $id . '">Отмена</button></div>'
        . '</form></div></div>';
}

/** Шапка панели: заголовок + иконки управления разделом (только админ) */
function resPanelHead(array $sec, bool $isAdmin, string $titleOverride = '', bool $withAdd = true, bool $withGear = true, string $iconKey = ''): string {
    $title = $titleOverride !== '' ? $titleOverride : $sec['title'];
    $ik = $iconKey !== '' ? $iconKey : packIconKey((string)$sec['icon']);
    $h = '<div class="res-panel-head"><h2><span class="res-h-ico">' . ppkIcon($ik, 'ai--loop') . '</span>' . htmlspecialchars($title) . '</h2>';
    if ($isAdmin) {
        $secJson = htmlspecialchars(json_encode([
            'slug' => $sec['slug'], 'title' => $sec['title'], 'icon' => packIconKey((string)$sec['icon']), 'builtin' => (bool)$sec['is_builtin'],
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS), ENT_QUOTES);
        $h .= '<div class="res-head-ctl">';
        if ($withGear) $h .= '<button type="button" class="rib" title="Настроить раздел" data-sec="' . $secJson . '">' . ppkIcon('gear') . '</button>';
        if ($withAdd)  $h .= '<button type="button" class="rib rib--add" title="Добавить материал" data-toggle="add-' . htmlspecialchars($sec['slug']) . '">' . ppkIcon('plus') . '</button>';
        $h .= '</div>';
    }
    return $h . '</div>';
}

$emptyTexts = [
    'psd'      => 'Пока пусто — посты появляются автоматически при публикации новых работ в приват-пак.',
    'font'     => 'Шрифтов пока нет.',
    'brush'    => 'Стилей и кистей пока нет.',
    'sd_video' => 'Видео пока нет.',
];
$iconPresets = packIconLabels();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Kostlim Design | Закрытый раздел</title>
    <link rel="icon" type="image/png" href="/assets/img/logo-64.png" sizes="16x16">
    <link rel="apple-touch-icon" href="/assets/img/logo-180.png">
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
    <link rel="stylesheet" href="assets/kostlim-upgrade.css?v=<?= @filemtime(__DIR__ . '/assets/kostlim-upgrade.css') ?: time() ?>">
    <style>
        .res-panel { display:none; } .res-panel.active { display:block; }
        .res-panel-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .res-panel-head h2 { margin:0; font-size:16px; }
        .res-guide { line-height:1.7; background: var(--card); border:1px solid var(--border); border-radius:12px; padding:20px; color: var(--text2); }
        .res-guide p { margin: 0 0 12px; }
        .res-guide img { max-width:100%; border-radius:8px; }

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

        @media (max-width:520px) {
            .res-view-list .res-card-body h3 { font-size:12.5px; }
            .res-view-list .res-card-sub { display:none; }
        }
    </style>
    <link rel="stylesheet" href="assets/ppk-redesign.css?v=<?= @filemtime(__DIR__ . '/assets/ppk-redesign.css') ?: time() ?>">
    <link rel="stylesheet" href="assets/res-forms.css?v=<?= @filemtime(__DIR__ . '/assets/res-forms.css') ?: time() ?>">
    <?php if ($isAdmin) renderRichEditorAssets(); // редактор нужен только тому, кто пишет гайд ?>
</head>
<body>

<header>
    <div class="header-left">
        <a href="index.php" class="nav-link">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            На главную
        </a>
    </div>
    <div class="brand-title"><a href="index.php"><img src="/assets/img/logo.webp" class="brand-logo-img" alt="Kostlim Design" style="height:40px;width:auto;max-width:160px;display:block;"></a></div>
    <div class="header-right">
        <?php renderNotificationBell(); ?>
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

    <?php if ($flashMsg !== ''): ?>
    <div class="res-toast res-toast--<?= $flashType ?>" id="resToast" role="status">
        <?= ppkIcon($flashType === 'err' ? 'close' : 'check') ?><span><?= htmlspecialchars($flashMsg) ?></span>
    </div>
    <?php endif; ?>

    <div class="res-glass-zone"></div>
    <div class="rd-mhead">
        <h2>Материалы</h2>
        <div class="res-tabs">
            <?php foreach ($sections as $i => $sx): ?>
            <button type="button" class="res-tab-btn<?= $i === 0 ? ' active' : '' ?>" data-panel="<?= htmlspecialchars($sx['slug']) ?>" onclick="resTab('<?= htmlspecialchars($sx['slug']) ?>')"><?= htmlspecialchars($sx['title']) ?><?php if ($sx['slug'] !== 'sd_video'): ?> <span class="rd-cnt">(<?= count($itemsBySlug[$sx['slug']] ?? []) ?>)</span><?php endif; ?></button>
            <?php endforeach; ?>
            <button type="button" class="res-tab-btn" data-panel="fav" onclick="resTab('fav')">Избранное <span class="rd-cnt">(<?= count($favorites) ?>)</span></button>
            <?php if ($isAdmin): ?>
            <button type="button" class="res-tab-add" title="Новый раздел" data-sec='{"slug":"","title":"","icon":"box","builtin":false}'><?= ppkIcon('plus') ?><span>Раздел</span></button>
            <?php endif; ?>
        </div>
    </div>

    <div class="res-view-switch">
        <button type="button" class="res-view-btn active" data-view="tile" onclick="resSetView('tile')"><?= ppkIcon('tile') ?>Плитка</button>
        <button type="button" class="res-view-btn" data-view="list" onclick="resSetView('list')"><?= ppkIcon('list') ?>Список</button>
    </div>

    <?php foreach ($sections as $i => $sx):
        $slug = $sx['slug'];
        $items = $itemsBySlug[$slug] ?? [];
        $empty = $emptyTexts[$slug] ?? 'В этом разделе пока ничего нет.';
    ?>
    <div class="res-panel<?= $i === 0 ? ' active' : '' ?>" id="panel-<?= htmlspecialchars($slug) ?>">
    <?php if ($slug === 'sd_video'): ?>
        <?= resPanelHead($sx, $isAdmin, 'Гайд по установке', false, true, 'book') ?>
        <?php if ($isAdmin): ?>
        <div class="res-guide-edit">
            <button type="button" class="rf-btn rf-btn--ghost rf-btn--sm" data-toggle="form-sd-guide"><?= ppkIcon('edit') ?><span>Изменить гайд</span></button>
        </div>
        <div class="rf-wrap" id="form-sd-guide"><div class="rf-inner">
            <form class="rf-form" method="post">
                <input type="hidden" name="action" value="save_sd_guide">
                <input type="hidden" name="tab_back" value="sd_video">
                <?php renderRichEditor('sd_guide', $sdGuide); ?>
                <div class="rf-actions"><button type="submit" class="rf-btn rf-btn--primary"><?= ppkIcon('check') ?><span>Сохранить гайд</span></button>
                <button type="button" class="rf-btn rf-btn--ghost" data-toggle="form-sd-guide">Отмена</button></div>
            </form>
        </div></div>
        <?php endif; ?>
        <?php if ($sdGuide !== ''): ?>
            <div class="res-guide"><?php
                // Обратная совместимость: гайд без HTML-тегов — обычный текст.
                echo (strpos($sdGuide, '<') === false) ? nl2br(htmlspecialchars($sdGuide)) : $sdGuide;
            ?></div>
        <?php else: ?>
            <p class="res-empty">Гайд ещё не добавлен.</p>
        <?php endif; ?>

        <div style="margin-top:36px;"></div>
        <?= resPanelHead($sx, $isAdmin, 'Видео и материалы', true, false, 'video') ?>
    <?php else: ?>
        <?= resPanelHead($sx, $isAdmin) ?>
    <?php endif; ?>
        <?php if ($isAdmin) echo resAddBlock($slug, $sx['title']); ?>
        <?= resSection($items, $engagement, $isAdmin, $empty, $secBySlug) ?>
    </div>
    <?php endforeach; ?>

    <!-- Избранное -->
    <div class="res-panel" id="panel-fav">
        <div class="res-panel-head"><h2><span class="res-h-ico"><?= ppkIcon('bookmark', 'ai--loop') ?></span>Избранное</h2></div>
        <?= resSection($favorites, $engagement, false, 'Пока ничего не добавлено — нажимай 🔖 на понравившихся материалах.', $secBySlug) ?>
    </div>
</main>

<?php if ($isAdmin): ?>
<!-- Редактирование материала -->
<dialog class="rd-dlg" id="dlgEdit">
    <form class="rf-form rf-form--dlg" method="post" enctype="multipart/form-data" id="editForm">
        <div class="rf-form-head"><h4 id="editTitle">Редактировать материал</h4>
            <button type="button" class="rib" data-close title="Закрыть"><?= ppkIcon('close') ?></button></div>
        <input type="hidden" name="action" value="update_resource">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="tab_back" value="">
        <?= resItemFields('', true) ?>
        <div class="rf-actions"><button type="submit" class="rf-btn rf-btn--primary"><?= ppkIcon('check') ?><span>Сохранить</span></button>
        <button type="button" class="rf-btn rf-btn--ghost" data-close>Отмена</button></div>
    </form>
</dialog>

<!-- Удаление материала -->
<dialog class="rd-dlg rd-dlg--sm" id="dlgDel">
    <form class="rf-form rf-form--dlg" method="post">
        <div class="rf-del-ico"><?= ppkIcon('trash') ?></div>
        <h4 class="rf-del-t">Удалить материал?</h4>
        <p class="rf-hint" id="delText" style="text-align:center"></p>
        <input type="hidden" name="action" value="delete_resource">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="tab_back" value="">
        <div class="rf-actions rf-actions--center"><button type="submit" class="rf-btn rf-btn--danger"><?= ppkIcon('trash') ?><span>Удалить</span></button>
        <button type="button" class="rf-btn rf-btn--ghost" data-close>Отмена</button></div>
    </form>
</dialog>

<!-- Раздел: создать / переименовать / удалить -->
<dialog class="rd-dlg rd-dlg--sm" id="dlgSec">
    <form class="rf-form rf-form--dlg" method="post" id="secForm">
        <div class="rf-form-head"><h4 id="secHead">Новый раздел</h4>
            <button type="button" class="rib" data-close title="Закрыть"><?= ppkIcon('close') ?></button></div>
        <input type="hidden" name="slug" value="">
        <input type="hidden" name="tab_back" value="">
        <div class="rf-field"><label class="rf-lab">Название раздела</label>
            <input class="rf-input" type="text" name="sec_title" maxlength="40" placeholder="Например: Экшены" required></div>
        <div class="rf-field"><label class="rf-lab">Иконка</label>
            <div class="rf-emoji" role="radiogroup" aria-label="Иконка раздела">
                <?php foreach ($iconPresets as $ik => $lab): ?><button type="button" class="rf-emo" data-ico="<?= $ik ?>" title="<?= htmlspecialchars($lab) ?>" aria-label="<?= htmlspecialchars($lab) ?>"><?= ppkIcon($ik) ?></button><?php endforeach; ?>
            </div>
            <input type="hidden" name="sec_icon" value="box">
        </div>
        <div class="rf-actions">
            <button type="submit" name="action" value="save_section" class="rf-btn rf-btn--primary"><?= ppkIcon('check') ?><span>Сохранить</span></button>
            <button type="submit" name="action" value="delete_section" id="secDel" class="rf-btn rf-btn--danger" formnovalidate><?= ppkIcon('trash') ?><span>Удалить раздел</span></button>
        </div>
        <p class="rf-hint" id="secHint"></p>
    </form>
</dialog>
<?php endif; ?>

<script>
function resTab(name) {
    document.querySelectorAll('.res-tab-btn').forEach(function(b){ b.classList.toggle('active', b.dataset.panel === name); });
    document.querySelectorAll('.res-panel').forEach(function(p){ p.classList.toggle('active', p.id === 'panel-' + name); });
    try { history.replaceState(null, '', '?tab=' + encodeURIComponent(name)); } catch (e) {}
}
function resCurrentTab() {
    var a = document.querySelector('.res-tab-btn.active');
    return a ? a.dataset.panel : '';
}

// Переключатель Плитка/Список — режим сохраняется в localStorage.
function resSetView(mode) {
    document.getElementById('resMain').classList.toggle('res-view-list', mode === 'list');
    document.querySelectorAll('.res-view-btn').forEach(function(b){ b.classList.toggle('active', b.dataset.view === mode); });
    try { localStorage.setItem('res_view_mode', mode); } catch (e) {}
}
(function(){
    var saved = 'tile';
    try { saved = localStorage.getItem('res_view_mode') || 'tile'; } catch (e) {}
    if (saved === 'list') resSetView('list');
    // открыть вкладку из ?tab= (после добавления/правки остаёмся в том же разделе)
    var m = location.search.match(/[?&]tab=([^&]+)/);
    if (m) {
        var t = decodeURIComponent(m[1]);
        if (document.getElementById('panel-' + t)) resTab(t);
    }
    var toast = document.getElementById('resToast');
    if (toast) {
        setTimeout(function(){ toast.classList.add('is-out'); }, 4200);
        try { // убираем служебные параметры из адреса, чтобы тост не повторялся по F5
            var tab = resCurrentTab();
            history.replaceState(null, '', tab ? '?tab=' + encodeURIComponent(tab) : location.pathname);
        } catch (e) {}
    }
})();

// ── Формы: переключатель источника, дропзоны, диалоги ──
(function(){
    function fmtSize(n) { return n > 1048576 ? (n / 1048576).toFixed(1) + ' МБ' : Math.max(1, Math.round(n / 1024)) + ' КБ'; }

    function syncSeg(form) {
        var checked = form.querySelector('.rf-seg input:checked');
        if (!checked) return;
        form.querySelectorAll('.rf-src').forEach(function(p){ p.classList.toggle('is-on', p.dataset.src === checked.value); });
    }
    document.addEventListener('change', function(ev){
        var t = ev.target;
        if (t.matches('.rf-seg input')) { syncSeg(t.closest('form')); return; }
        if (t.matches('.rf-drop input[type=file]')) {
            var drop = t.closest('.rf-drop'), f = t.files && t.files[0];
            var title = drop.querySelector('.rf-drop-t');
            if (!drop.dataset.def) drop.dataset.def = title.innerHTML;
            var thumb = drop.querySelector('.rf-drop-thumb');
            if (!f) {
                drop.classList.remove('has-file'); title.innerHTML = drop.dataset.def;
                if (thumb && drop.dataset.orig) { thumb.src = drop.dataset.orig; drop.classList.add('has-thumb'); }
                else if (thumb) { thumb.removeAttribute('src'); drop.classList.remove('has-thumb'); }
                return;
            }
            drop.classList.add('has-file');
            title.innerHTML = '<b></b><small></small>';
            title.querySelector('b').textContent = f.name;
            title.querySelector('small').textContent = fmtSize(f.size) + ' · нажми, чтобы заменить';
            if (thumb && f.type.indexOf('image/') === 0) {
                thumb.src = URL.createObjectURL(f); drop.classList.add('has-thumb');
            }
        }
    });
    ['dragenter', 'dragover'].forEach(function(n){
        document.addEventListener(n, function(ev){
            var d = ev.target.closest && ev.target.closest('.rf-drop');
            if (d) { ev.preventDefault(); d.classList.add('is-drag'); }
        });
    });
    ['dragleave', 'drop'].forEach(function(n){
        document.addEventListener(n, function(ev){
            var d = ev.target.closest && ev.target.closest('.rf-drop');
            if (!d) return;
            d.classList.remove('is-drag');
            if (n === 'drop') {
                ev.preventDefault();
                var inp = d.querySelector('input[type=file]');
                if (ev.dataTransfer && ev.dataTransfer.files.length && inp) {
                    inp.files = ev.dataTransfer.files;
                    inp.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        });
    });
    // чтобы случайный дроп мимо зоны не открывал файл в браузере
    window.addEventListener('dragover', function(e){ e.preventDefault(); });
    window.addEventListener('drop', function(e){ if (!e.target.closest || !e.target.closest('.rf-drop')) e.preventDefault(); });

    // индикатор загрузки на кнопке отправки
    document.addEventListener('submit', function(ev){
        var form = ev.target;
        if (!form.classList || !form.classList.contains('rf-form')) return;
        var btn = form.querySelector('.rf-btn--primary[type=submit]');
        if (btn) { btn.classList.add('is-busy'); btn.querySelector('span').textContent = 'Подожди…'; setTimeout(function(){ btn.disabled = true; }, 0); }
    });

    var dlgEdit = document.getElementById('dlgEdit'), dlgDel = document.getElementById('dlgDel'), dlgSec = document.getElementById('dlgSec');

    function openEdit(d) {
        var f = document.getElementById('editForm');
        f.reset();
        f.querySelector('[name=id]').value = d.id;
        f.querySelector('[name=tab_back]').value = d.type;
        f.querySelector('[name=title]').value = d.title || '';
        f.querySelector('[name=description]').value = d.description || '';
        f.querySelector('[name=telegram_url]').value = d.telegram_url || '';
        f.querySelector('[name=resource_link]').value = d.link || '';
        var fileInp = f.querySelector('[name=resource_file]');
        if (d.accept) fileInp.setAttribute('accept', d.accept); else fileInp.removeAttribute('accept');
        f.querySelector('.rf-seg input[value=keep]').checked = true;
        // сброс дропзон и подстановка текущего превью
        f.querySelectorAll('.rf-drop').forEach(function(dr){
            dr.classList.remove('has-file', 'has-thumb'); delete dr.dataset.orig;
            if (dr.dataset.def) dr.querySelector('.rf-drop-t').innerHTML = dr.dataset.def;
        });
        var prevDrop = f.querySelector('.rf-drop--img'), thumb = prevDrop.querySelector('.rf-drop-thumb');
        if (d.preview) { thumb.src = d.preview; prevDrop.classList.add('has-thumb'); prevDrop.dataset.orig = d.preview; }
        else { thumb.removeAttribute('src'); }
        syncSeg(f);
        document.getElementById('editTitle').textContent = 'Редактировать · ' + (d.section || 'материал');
        dlgEdit.showModal();
    }
    var secIcon = null;
    function openSec(d) {
        var f = document.getElementById('secForm');
        f.reset();
        f.querySelector('[name=slug]').value = d.slug || '';
        f.querySelector('[name=tab_back]').value = d.slug || resCurrentTab();
        f.querySelector('[name=sec_title]').value = d.title || '';
        var ic = f.querySelector('[name=sec_icon]'); ic.value = d.icon || 'box';
        markEmo(ic.value);
        var del = document.getElementById('secDel'), hint = document.getElementById('secHint');
        del.classList.remove('armed'); del.querySelector('span').textContent = 'Удалить раздел';
        if (!d.slug) {
            document.getElementById('secHead').textContent = 'Новый раздел';
            del.style.display = 'none'; hint.textContent = 'После создания открой раздел и нажми «+», чтобы добавить материалы.';
        } else {
            document.getElementById('secHead').textContent = 'Настройки раздела';
            if (d.builtin) { del.style.display = 'none'; hint.textContent = 'Встроенный раздел можно переименовать и сменить иконку, но нельзя удалить.'; }
            else { del.style.display = ''; hint.textContent = 'При удалении раздела все его материалы тоже удаляются.'; }
        }
        dlgSec.showModal();
    }
    function markEmo(v) {
        document.querySelectorAll('.rf-emo').forEach(function(b){ b.classList.toggle('is-on', b.dataset.ico === v); });
    }

    document.addEventListener('click', function(ev){
        var t = ev.target;
        var tg = t.closest('[data-toggle]');
        if (tg) {
            var w = document.getElementById(tg.dataset.toggle);
            if (w) {
                var open = w.classList.toggle('is-open');
                document.querySelectorAll('[data-toggle="' + tg.dataset.toggle + '"]').forEach(function(b){ b.classList.toggle('is-open', open); });
                if (open) { var first = w.querySelector('input[type=text]'); if (first) setTimeout(function(){ first.focus({ preventScroll: true }); }, 250); }
            }
            return;
        }
        var ed = t.closest('[data-edit]');
        if (ed && dlgEdit) { try { openEdit(JSON.parse(ed.dataset.edit)); } catch (e) {} return; }
        var dl = t.closest('[data-del]');
        if (dl && dlgDel) {
            dlgDel.querySelector('[name=id]').value = dl.dataset.del;
            dlgDel.querySelector('[name=tab_back]').value = resCurrentTab();
            document.getElementById('delText').textContent = '«' + (dl.dataset.delTitle || '') + '» будет удалён без возможности восстановления.';
            dlgDel.showModal(); return;
        }
        var sc = t.closest('[data-sec]');
        if (sc && dlgSec) { try { openSec(JSON.parse(sc.dataset.sec)); } catch (e) {} return; }
        if (t.closest('[data-close]')) { var dlg = t.closest('dialog'); if (dlg) dlg.close(); return; }
        if (t.tagName === 'DIALOG') { t.close(); return; } // клик по подложке
        var emo = t.closest('.rf-emo');
        if (emo) { var inp = document.querySelector('#secForm [name=sec_icon]'); inp.value = emo.dataset.ico; markEmo(emo.dataset.ico); return; }
        var sd = t.closest('#secDel');
        if (sd && !sd.classList.contains('armed')) { // двойное подтверждение удаления раздела
            ev.preventDefault();
            sd.classList.add('armed'); sd.querySelector('span').textContent = 'Точно удалить?';
            setTimeout(function(){ sd.classList.remove('armed'); sd.querySelector('span').textContent = 'Удалить раздел'; }, 3500);
        }
    });
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
