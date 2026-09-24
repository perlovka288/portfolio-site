<?php
/**
 * admin/resources.php — управление закрытым разделом ресурсов (Блок 3 ТЗ):
 * шрифты, стили/кисти, гайд + видео по установке Stable Diffusion.
 * Раздел PSD сюда не входит — он наполняется автоматически при публикации
 * портфолио в приват-пак (см. admin/psd_manager.php::publishPortfolioToPrivatePack).
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once 'auth.php'; // редиректит на login.php, если не админ
require_once '../config/db.php';
require_once __DIR__ . '/../includes/resources_lib.php';
require_once __DIR__ . '/google_drive_helper.php';

ensureResourcesSchema($pdo);

$message = '';

/** Загружает файл на Google Drive и возвращает [url, file_id, file_name] либо null */
function uploadResourceFileDetailed(string $field): ?array
{
    global $message;
    $err = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return null;
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        $message = '❌ Ошибка загрузки файла.';
        return null;
    }
    $tmp  = $_FILES[$field]['tmp_name'];
    $name = basename((string)$_FILES[$field]['name']);
    $gd   = uploadToGoogleDriveDetailed($tmp, $name);
    if ($gd !== null) return $gd;
    // FIX: Google Drive не настроен (нет admin/gdrive_key.json) или недоступен —
    // не проваливаем загрузку молча, а сохраняем файл прямо на сервер, чтобы
    // материал всё равно можно было скачать.
    $local = uploadPackResourceFileLocal($field, __DIR__ . '/../uploads/pack_resources/');
    if ($local !== null) return ['id' => '', 'url' => $local['url'], 'name' => $local['file_name']];
    $message = '❌ Не удалось загрузить файл ни на Google Drive, ни локально — проверь admin/gdrive_key.json или права на папку uploads/.';
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_resource') {
        $type = $_POST['type'] ?? '';
        if (!in_array($type, ['font', 'brush', 'sd_video'], true)) {
            $message = '❌ Неизвестный тип ресурса.';
        } else {
            $link = trim((string)($_POST['resource_link'] ?? ''));
            if ($link !== '') {
                // Готовая ссылка — обходит лимиты хостинга на размер
                // POST-запроса, для больших файлов (100+ МБ) это надёжнее.
                $fileGd = ['id' => '', 'url' => $link, 'name' => ''];
            } else {
                $fileGd = uploadResourceFileDetailed('resource_file');
                if ($fileGd === null && $message === '') $message = '❌ Прикрепи файл или вставь ссылку.';
            }
            if ($fileGd !== null) {
                createPackResource($pdo, [
                    'type'        => $type,
                    'title'       => trim((string)($_POST['title'] ?? '')),
                    'description' => trim((string)($_POST['description'] ?? '')),
                    'file_url'    => $type !== 'sd_video' ? $fileGd['url'] : '',
                    'video_url'   => $type === 'sd_video' ? $fileGd['url'] : '',
                    'file_id'     => $fileGd['id'],
                    'file_name'   => $fileGd['name'],
                ]);
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
}

$fonts   = listPackResources($pdo, 'font');
$brushes = listPackResources($pdo, 'brush');
$videos  = listPackResources($pdo, 'sd_video');
$sdGuide = getResSetting($pdo, 'SD_INSTALL_GUIDE', '');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ресурсы пака | Админ</title>
<link rel="icon" type="image/png" href="../assets/img/logo.png" sizes="16x16">
<style>
    /* FIX (Блок 1.3 ТЗ): раньше у этой страницы была своя отдельная
       фиолетовая мини-тема (#5b5bd6 и т.п.), полностью выбивавшаяся из
       общего тёмно-оранжевого стиля сайта/админки. Приводим к единой
       палитре: фон #0D0D0D, акцент #FF7A00, границы #222222. */
    body{background:#0D0D0D;color:#F4F4F4;font-family:system-ui,sans-serif;margin:0;padding:24px;}
    a{color:#FF7A00;text-decoration:none;}
    a:hover{color:#ff9433;text-decoration:underline;}
    h1{font-size:20px;} h2{font-size:16px;margin-top:36px;border-bottom:1px solid #222222;padding-bottom:8px;}
    .msg{background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.35);color:#4ade80;padding:10px 14px;border-radius:8px;margin-bottom:16px;}
    form.inline{display:flex;flex-direction:column;gap:8px;max-width:480px;background:#121212;border:1px solid #222222;padding:14px;border-radius:10px;margin-bottom:16px;}
    input[type=text],textarea,select{
        appearance:none;-webkit-appearance:none;-moz-appearance:none;
        background-color:#0D0D0D;border:1px solid #222222;color:#F4F4F4;padding:8px 10px;border-radius:6px;font-family:inherit;
    }
    input[type=text]:focus,textarea:focus,select:focus{outline:none;border-color:#FF7A00;box-shadow:0 0 0 3px rgba(255,122,0,.18);}
    textarea{min-height:160px;}
    input[type=file]{color:#F4F4F4;}
    button{background:#FF7A00;color:#0D0D0D;border:none;padding:9px 14px;border-radius:6px;cursor:pointer;font-weight:700;}
    button:hover{background:#ff9433;}
    button.danger{background:#c0392b;color:#fff;}
    button.danger:hover{background:#e0463a;}
    ul{list-style:none;padding:0;} li{display:flex;justify-content:space-between;align-items:center;background:#121212;border:1px solid #222222;padding:10px 14px;border-radius:8px;margin-bottom:6px;}
    li span{opacity:.7;font-size:12px;margin-left:8px;}
</style>
</head>
<body>
<p><a href="index.php">← Назад в админку</a></p>
<h1>🔒 Ресурсы закрытого раздела</h1>
<?php if ($message): ?><div class="msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<h2>🔤 Шрифты (.ttf)</h2>
<form class="inline" method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="add_resource">
    <input type="hidden" name="type" value="font">
    <input type="text" name="title" placeholder="Название шрифта" required>
    <input type="file" name="resource_file" accept=".ttf,.otf">
    <input type="text" name="resource_link" placeholder="...или вставь готовую ссылку на файл (для больших файлов)">
    <button type="submit">Добавить</button>
</form>
<ul>
<?php foreach ($fonts as $r): ?>
    <li><a href="<?= htmlspecialchars($r['file_url']) ?>" target="_blank"><?= htmlspecialchars($r['title']) ?></a>
        <form method="post" style="display:inline"><input type="hidden" name="action" value="delete_resource"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="danger" type="submit" onclick="return confirm('Удалить?')">✕</button></form>
    </li>
<?php endforeach; ?>
</ul>

<h2>🎨 Стили и кисти</h2>
<form class="inline" method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="add_resource">
    <input type="hidden" name="type" value="brush">
    <input type="text" name="title" placeholder="Название набора" required>
    <textarea name="description" placeholder="Описание (необязательно)"></textarea>
    <input type="file" name="resource_file" accept=".abr,.asl,.zip,.rar,.7z">
    <input type="text" name="resource_link" placeholder="...или вставь готовую ссылку на файл (для больших файлов)">
    <button type="submit">Добавить</button>
</form>
<ul>
<?php foreach ($brushes as $r): ?>
    <li><a href="<?= htmlspecialchars($r['file_url']) ?>" target="_blank"><?= htmlspecialchars($r['title']) ?></a>
        <form method="post" style="display:inline"><input type="hidden" name="action" value="delete_resource"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="danger" type="submit" onclick="return confirm('Удалить?')">✕</button></form>
    </li>
<?php endforeach; ?>
</ul>

<h2>🖥 Установка Stable Diffusion — гайд</h2>
<form class="inline" method="post">
    <input type="hidden" name="action" value="save_sd_guide">
    <textarea name="sd_guide" placeholder="Текст гайда, полезные ссылки..."><?= htmlspecialchars($sdGuide) ?></textarea>
    <button type="submit">Сохранить гайд</button>
</form>

<h2>🎬 Видео установки Stable Diffusion</h2>
<p style="opacity:.6;font-size:13px;">Видео загружаются на Google Drive (могут быть большими) и показываются на сайте плиткой.</p>
<form class="inline" method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="add_resource">
    <input type="hidden" name="type" value="sd_video">
    <input type="text" name="title" placeholder="Название видео" required>
    <input type="file" name="resource_file" accept="video/*">
    <input type="text" name="resource_link" placeholder="...или вставь готовую ссылку на видео (для больших файлов)">
    <button type="submit">Загрузить видео</button>
</form>
<ul>
<?php foreach ($videos as $r): ?>
    <li><a href="<?= htmlspecialchars($r['video_url']) ?>" target="_blank">▶️ <?= htmlspecialchars($r['title']) ?></a>
        <form method="post" style="display:inline"><input type="hidden" name="action" value="delete_resource"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="danger" type="submit" onclick="return confirm('Удалить?')">✕</button></form>
    </li>
<?php endforeach; ?>
</ul>

</body>
</html>
