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

/** Загружает файл на Google Drive и возвращает публичную ссылку либо '' */
function uploadResourceFile(string $field): string
{
    global $message;
    $err = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return '';
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        $message = '❌ Ошибка загрузки файла.';
        return '';
    }
    $tmp  = $_FILES[$field]['tmp_name'];
    $name = basename((string)$_FILES[$field]['name']);
    $url  = uploadToGoogleDrive($tmp, $name);
    if ($url === null || $url === '') {
        $message = '❌ Не удалось загрузить файл на Google Drive — проверь admin/gdrive_key.json и GDRIVE_FOLDER_ID.';
        return '';
    }
    return $url;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_resource') {
        $type = $_POST['type'] ?? '';
        if (!in_array($type, ['font', 'brush', 'sd_video'], true)) {
            $message = '❌ Неизвестный тип ресурса.';
        } else {
            $fileUrl = '';
            if ($type === 'sd_video') {
                $fileUrl = uploadResourceFile('resource_file');
            } else {
                $fileUrl = uploadResourceFile('resource_file');
            }
            if ($fileUrl === '' && $message === '') {
                $message = '❌ Прикрепи файл.';
            } else {
                createPackResource($pdo, [
                    'type'        => $type,
                    'title'       => trim((string)($_POST['title'] ?? '')),
                    'description' => trim((string)($_POST['description'] ?? '')),
                    'file_url'    => $type !== 'sd_video' ? $fileUrl : '',
                    'video_url'   => $type === 'sd_video' ? $fileUrl : '',
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
    body{background:#0e0e14;color:#e8e8ee;font-family:system-ui,sans-serif;margin:0;padding:24px;}
    a{color:#8b8bff;}
    h1{font-size:20px;} h2{font-size:16px;margin-top:36px;border-bottom:1px solid #26262f;padding-bottom:8px;}
    .msg{background:#1a2e1a;border:1px solid #2e5a2e;padding:10px 14px;border-radius:8px;margin-bottom:16px;}
    form.inline{display:flex;flex-direction:column;gap:8px;max-width:480px;background:#16161d;padding:14px;border-radius:10px;margin-bottom:16px;}
    input[type=text],textarea{background:#0e0e14;border:1px solid #2a2a35;color:#e8e8ee;padding:8px 10px;border-radius:6px;font-family:inherit;}
    textarea{min-height:160px;}
    button{background:#5b5bd6;color:#fff;border:none;padding:9px 14px;border-radius:6px;cursor:pointer;font-weight:600;}
    button.danger{background:#c0392b;}
    ul{list-style:none;padding:0;} li{display:flex;justify-content:space-between;align-items:center;background:#16161d;padding:10px 14px;border-radius:8px;margin-bottom:6px;}
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
    <input type="file" name="resource_file" accept=".ttf,.otf" required>
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
    <input type="file" name="resource_file" accept=".abr,.asl,.zip,.rar,.7z" required>
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
    <input type="file" name="resource_file" accept="video/*" required>
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
