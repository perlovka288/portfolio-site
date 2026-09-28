<?php
/**
 * upload_editor_image.php — загрузка картинки, вставленной через кнопку
 * "🖼" в редакторе текста (rich_editor.php), на ImgBB. Используется и
 * админом (гайд по SD), и авторами статей в «Полезностях» — доступ даём
 * тем же, кто может писать в закрытом разделе (Admin/Designer PPK),
 * т.к. сам редактор используется только внутри resources.php/полезностей.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once 'includes/session.php';
require_once 'config/db.php';
require_once 'includes/order_flow.php';
require_once 'includes/pack_role.php';
require_once 'includes/resources_lib.php';
require_once 'includes/ppk_access.php';

ensureOrderFlowSchema($pdo);
ensurePackRoleSchema($pdo);
ensureResourcesSchema($pdo);

$access = resolvePpkAccess($pdo);
if (!$access['isPackDesigner']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Доступ закрыт']);
    exit;
}

$err = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['image']['tmp_name'])) {
    echo json_encode(['ok' => false, 'error' => 'Файл не получен']);
    exit;
}
$ext = strtolower(pathinfo((string)$_FILES['image']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Разрешены только jpg/png/webp/gif']);
    exit;
}

$imgbbError = null;
$url = uploadPackImageToImgBB($pdo, $_FILES['image']['tmp_name'], 'editor_' . time(), $imgbbError);
if ($url !== '') {
    echo json_encode(['ok' => true, 'url' => $url]);
    exit;
}

// Запасной вариант, если ImgBB не сработал: сохраняем на сервер, чтобы
// редактор всё равно работал. Минус — такие файлы не переживают деплой,
// если папка uploads/ не сохраняется (поэтому в ответе есть предупреждение).
$dir = __DIR__ . '/uploads/editor_images/';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
if (is_dir($dir) && is_writable($dir)) {
    $fname = 'ed_' . time() . '_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($_FILES['image']['tmp_name'], $dir . $fname)) {
        echo json_encode(['ok' => true, 'url' => '/uploads/editor_images/' . $fname,
            'warning' => 'ImgBB не сработал (' . $imgbbError . ') — картинка сохранена на сервере']);
        exit;
    }
}
echo json_encode(['ok' => false, 'error' => 'Не удалось загрузить картинку. Причина ImgBB: ' . $imgbbError]);
