<?php
session_start();
require_once 'auth.php';
require_once '../config/db.php';

$message = '';
$uploadDir = '../uploads/';
define('TELEGRAM_BOT_TOKEN', '8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg');
define('PORTFOLIO_CHANNEL_CHAT', '@gfasasdasasd');
define('PUBLIC_SITE_URL', 'http://localhost/portfolio-site/');
define('ADMIN_EMAIL', 'jeffkostlim@gmail.com');
define('ADMIN_TELEGRAM_ID', '1710365896');
$telegramLastError = '';

function sendTelegramRequest(string $method, array $params, array $files = []): ?array
{
    global $telegramLastError;
    $telegramLastError = '';

    if (TELEGRAM_BOT_TOKEN === '' || PORTFOLIO_CHANNEL_CHAT === '') {
        $telegramLastError = 'не задан токен бота или канал';
        return null;
    }

    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, empty($files) ? http_build_query($params) : array_merge($params, $files));
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $response === '') {
        $telegramLastError = $curlError !== '' ? $curlError : 'пустой ответ Telegram API';
        return null;
    }

    $data = json_decode($response, true);
    if (!($data['ok'] ?? false)) {
        $telegramLastError = (string)($data['description'] ?? 'Telegram API вернул ошибку');
    }

    return $data;
}

function imageFromFile(string $path)
{
    $info = @getimagesize($path);
    if (!$info) {
        return null;
    }

    return match ($info[2]) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => imagecreatefrompng($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : null,
        IMAGETYPE_GIF  => imagecreatefromgif($path),
        default        => null,
    };
}

function getCurrentAvatarPath(PDO $pdo, string $uploadDir): string
{
    $avatar = '';

    try {
        $stmt   = $pdo->query('SELECT avatar FROM users LIMIT 1');
        $avatar = (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $avatar = '';
    }

    $candidates = array_filter([
        $avatar !== '' ? $uploadDir . basename($avatar) : '',
        $uploadDir . 'avatar.jpg',
        $uploadDir . 'default_avatar.png',
    ]);

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return '';
}

function createWatermarkedImage(string $mainPath, string $avatarPath): string
{
    if (!extension_loaded('gd') || !is_file($mainPath) || !is_file($avatarPath)) {
        return $mainPath;
    }

    $main   = imageFromFile($mainPath);
    $avatar = imageFromFile($avatarPath);
    if (!$main || !$avatar) {
        return $mainPath;
    }

    imagealphablending($main, true);
    imagesavealpha($main, true);

    $mainW    = imagesx($main);
    $mainH    = imagesy($main);
    $markSize = max(72, (int)round(min($mainW, $mainH) * 0.18));
    $pad      = max(18, (int)round($markSize * 0.22));
    $x        = $pad;
    $y        = $pad;

    $resized = imagecreatetruecolor($markSize, $markSize);
    imagealphablending($resized, false);
    imagesavealpha($resized, true);
    $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
    imagefill($resized, 0, 0, $transparent);
    imagecopyresampled($resized, $avatar, 0, 0, 0, 0, $markSize, $markSize, imagesx($avatar), imagesy($avatar));

    $circle = imagecreatetruecolor($markSize, $markSize);
    imagealphablending($circle, false);
    imagesavealpha($circle, true);
    imagefill($circle, 0, 0, $transparent);
    $center = $markSize / 2;
    $radius = $markSize / 2;
    for ($i = 0; $i < $markSize; $i++) {
        for ($j = 0; $j < $markSize; $j++) {
            $dx = $i - $center;
            $dy = $j - $center;
            if (($dx * $dx + $dy * $dy) <= ($radius * $radius)) {
                imagesetpixel($circle, $i, $j, imagecolorat($resized, $i, $j));
            }
        }
    }

    $shadow = imagecolorallocatealpha($main, 0, 0, 0, 55);
    imagefilledellipse($main, $x + (int)($markSize / 2), $y + (int)($markSize / 2), $markSize + 14, $markSize + 14, $shadow);
    imagecopy($main, $circle, $x, $y, 0, 0, $markSize, $markSize);

    $output = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'portfolio_channel_' . uniqid('', true) . '.jpg';
    imagejpeg($main, $output, 92);

    imagedestroy($main);
    imagedestroy($avatar);
    imagedestroy($resized);
    imagedestroy($circle);

    return $output;
}

function publishPortfolioToChannel(PDO $pdo, string $uploadDir, array $case): bool
{
    $imgVal   = (string)($case['image'] ?? '');
    // Підтримуємо і старий формат (ім'я файлу) і новий (повний URL)
    if (str_starts_with($imgVal, 'http://') || str_starts_with($imgVal, 'https://')) {
        $mainPath = $uploadDir . basename(parse_url($imgVal, PHP_URL_PATH));
    } else {
        $mainPath = $uploadDir . basename($imgVal);
    }
    if (!is_file($mainPath)) {
        return false;
    }

    $avatarPath = getCurrentAvatarPath($pdo, $uploadDir);
    $photoPath  = $avatarPath !== '' ? createWatermarkedImage($mainPath, $avatarPath) : $mainPath;

    $rub     = (int)($case['price_rub'] ?? 0);
    $uan     = (int)($case['price_uan'] ?? 0);
    $caption = "Цена работы: {$rub}₽ | {$uan}₴\n\n";
    $caption .= "Оценить данную работу можно в комментариях.\n\n";
    $caption .= 'Заказать дизайн можно тут - <a href="' . htmlspecialchars(PUBLIC_SITE_URL, ENT_QUOTES, 'UTF-8') . '">сайт</a>';

    $result = sendTelegramRequest('sendPhoto', [
        'chat_id'    => PORTFOLIO_CHANNEL_CHAT,
        'caption'    => $caption,
        'parse_mode' => 'HTML',
    ], [
        'photo' => new CURLFile($photoPath),
    ]);

    if ($photoPath !== $mainPath && is_file($photoPath)) {
        unlink($photoPath);
    }

    return (bool)($result['ok'] ?? false);
}

// ── ImgBB upload helper ──────────────────────────────────────
function uploadToImgBB(string $tmpPath, string $name = 'image'): string
{
    $apiKey = getenv('IMGBB_API_KEY') ?: '';
    if ($apiKey === '' || !is_file($tmpPath)) return '';

    $b64 = base64_encode(file_get_contents($tmpPath));
    $ch  = curl_init('https://api.imgbb.com/1/upload');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POSTFIELDS     => ['key' => $apiKey, 'image' => $b64, 'name' => $name],
    ]);
    $res  = curl_exec($ch);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($res === false || $res === '') { error_log("ImgBB curl error: $cerr"); return ''; }
    $data = json_decode($res, true);
    return $data['data']['url'] ?? '';
}

// ── Base URL helper ──────────────────────────────────────────
function siteBaseUrl(): string
{
    $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // admin/ → поднимаемся на уровень выше
    $base  = rtrim(str_replace('/admin', '', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $proto . '://' . $host . $base;
}

// ── Try local save (returns FULL URL), fallback to ImgBB ─────
function uploadImage(string $field, string $prefix, string $uploadDir): string
{
    $err = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE || empty($_FILES[$field]['name'])) return '';
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        global $message;
        $message = '❌ Файл слишком большой для прямой загрузки. Уменьши размер файла до 8 МБ.';
        return '';
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        error_log("UPLOAD[$field]: err=$err"); return '';
    }

    $allowed = ['jpg','jpeg','png','webp','gif'];
    $ext     = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) { error_log("UPLOAD[$field]: bad ext=$ext"); return ''; }

    $tmp = $_FILES[$field]['tmp_name'];

    // 1. Try local save — теперь возвращаем ПОЛНЫЙ URL, а не просто имя файла.
    //    Так картинка не потеряется при git push / деплое — в БД хранится
    //    абсолютный URL, который работает независимо от окружения.
    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }
    if (is_writable($uploadDir)) {
        $filename = $prefix . '_' . time() . '_' . uniqid() . '.' . $ext;
        $dest     = $uploadDir . $filename;
        if (move_uploaded_file($tmp, $dest)) {
            // Строим URL относительно корня сайта
            // $uploadDir вида '../uploads/' → нормализуем в 'uploads/'
            $relDir = ltrim(str_replace('../', '', $uploadDir), '/');
            $url    = siteBaseUrl() . '/' . $relDir . $filename;
            error_log("UPLOAD[$field]: local OK => $url");
            return $url;
        }
    }

    // 2. Fallback: upload to ImgBB and return full URL
    error_log("UPLOAD[$field]: local failed, trying ImgBB");
    $url = uploadToImgBB($tmp, $prefix . '_' . time());
    if ($url !== '') {
        error_log("UPLOAD[$field]: ImgBB OK => $url");
        return $url;
    }

    error_log("UPLOAD[$field]: both methods failed");
    return '';
}

function uploadNestedImage(string $field, int $id, string $prefix, string $uploadDir): string
{
    if (empty($_FILES[$field]['name'][$id]) || empty($_FILES[$field]['tmp_name'][$id])) return '';
    $err = $_FILES[$field]['error'][$id] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) { error_log("UPLOAD_NESTED[$field][$id]: err=$err"); return ''; }

    $allowed = ['jpg','jpeg','png','webp','gif'];
    $ext     = strtolower(pathinfo($_FILES[$field]['name'][$id], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return '';

    $tmp = $_FILES[$field]['tmp_name'][$id];

    // 1. Try local — возвращаем ПОЛНЫЙ URL
    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }
    if (is_writable($uploadDir)) {
        $filename = $prefix . '_' . time() . '_' . $id . '_' . uniqid() . '.' . $ext;
        $dest = $uploadDir . $filename;
        if (move_uploaded_file($tmp, $dest)) {
            $relDir = ltrim(str_replace('../', '', $uploadDir), '/');
            return siteBaseUrl() . '/' . $relDir . $filename;
        }
    }

    // 2. ImgBB fallback
    $url = uploadToImgBB($tmp, $prefix . '_' . time() . '_' . $id);
    return $url;
}

function money(int|float $value): string
{
    return number_format((float)$value, 0, '.', ' ');
}
// ── Resolve image src (filename or full URL) ─────────────────
function imgSrc(string $val, string $baseUrl = '../uploads/'): string
{
    if ($val === '') return '';
    if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) return $val;
    return $baseUrl . $val;
}



// ===================== UPLOAD SITE AVATAR =====================
if (isset($_POST['upload_site_avatar'])) {
    $newAvatar = uploadImage('site_avatar', 'avatar', $uploadDir);
    if ($newAvatar !== '') {
        $pdo->prepare("UPDATE users SET avatar = ? WHERE username = 'Kostlim'")->execute([$newAvatar]);
        // Если локальный файл — копируем как avatar.jpg для совместимости
        if (!str_starts_with($newAvatar, 'http')) {
            $srcPath = $uploadDir . $newAvatar;
            if (is_file($srcPath)) { @copy($srcPath, $uploadDir . 'avatar.jpg'); }
        }
        $message = '✅ Аватарка сайта обновлена.';
    } else {
        $message = '❌ Не удалось загрузить аватарку. Проверь формат (jpg, png, webp, gif) и размер.';
    }
}

// ===================== PORTFOLIO =====================
if (isset($_POST['add_portfolio'])) {
    $title        = trim($_POST['title'] ?? '');
    $category_key = $_POST['category_key'] ?? 'preview';
    $price_rub    = !empty($_POST['price_rub']) ? (int)$_POST['price_rub'] : 0;
    $price_uan    = !empty($_POST['price_uan']) ? (int)$_POST['price_uan'] : 0;

    $filename_main   = uploadImage('image', 'main', $uploadDir);
    $filename_avatar = uploadImage('avatar_image', 'ava', $uploadDir);

    if ($title === '') {
        $message = '❌ Укажи название проекта.';
    } elseif ($filename_main === '') {
        $message = '❌ Загрузи главное изображение или шапку.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO portfolio (title, category_key, price_rub, price_uan, image, avatar_image)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$title, $category_key, $price_rub, $price_uan, $filename_main, $filename_avatar]);
        $postedToChannel = publishPortfolioToChannel($pdo, $uploadDir, [
            'title'     => $title,
            'price_rub' => $price_rub,
            'price_uan' => $price_uan,
            'image'     => $filename_main,
        ]);
        $message = '✅ Кейс добавлен в портфолио.' . ($postedToChannel ? ' Пост отправлен в Telegram-канал.' : ' Но пост в Telegram-канал не отправился: ' . ($telegramLastError !== '' ? $telegramLastError : 'проверь, что бот добавлен админом в @gfasasdasasd.'));
    }
}

if (isset($_GET['delete_portfolio_id'])) {
    $del_id = (int)$_GET['delete_portfolio_id'];

    $img_stmt = $pdo->prepare("SELECT image, avatar_image FROM portfolio WHERE id = ?");
    $img_stmt->execute([$del_id]);
    $work_files = $img_stmt->fetch(PDO::FETCH_ASSOC);

    foreach (['image', 'avatar_image'] as $field) {
        $val = $work_files[$field] ?? '';
        if ($val === '') continue;
        // Якщо в БД повний URL — витягуємо лише ім'я файлу
        if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) {
            $val = basename(parse_url($val, PHP_URL_PATH));
        }
        $path = $uploadDir . $val;
        if ($val !== '' && file_exists($path)) unlink($path);
    }

    $stmt = $pdo->prepare("DELETE FROM portfolio WHERE id = ?");
    $stmt->execute([$del_id]);
    $message = '🗑️ Кейс удален из портфолио.';
}

if (isset($_POST['update_portfolio_media'])) {
    $caseId   = (int)($_POST['portfolio_id'] ?? 0);
    $img_stmt = $pdo->prepare("SELECT image, avatar_image FROM portfolio WHERE id = ?");
    $img_stmt->execute([$caseId]);
    $currentFiles = $img_stmt->fetch(PDO::FETCH_ASSOC);

    if ($currentFiles) {
        $newMain   = uploadImage('portfolio_image', 'main', $uploadDir);
        $newAvatar = uploadImage('portfolio_avatar', 'ava', $uploadDir);

        if ($newMain !== '') {
            $oldVal = $currentFiles['image'] ?? '';
            if ($oldVal !== '') {
                $oldFile = (str_starts_with($oldVal,'http://') || str_starts_with($oldVal,'https://'))
                    ? basename(parse_url($oldVal, PHP_URL_PATH)) : $oldVal;
                if ($oldFile !== '' && file_exists($uploadDir . $oldFile)) unlink($uploadDir . $oldFile);
            }
            $stmt = $pdo->prepare("UPDATE portfolio SET image = ? WHERE id = ?");
            $stmt->execute([$newMain, $caseId]);
        }

        if ($newAvatar !== '') {
            $oldVal = $currentFiles['avatar_image'] ?? '';
            if ($oldVal !== '') {
                $oldFile = (str_starts_with($oldVal,'http://') || str_starts_with($oldVal,'https://'))
                    ? basename(parse_url($oldVal, PHP_URL_PATH)) : $oldVal;
                if ($oldFile !== '' && file_exists($uploadDir . $oldFile)) unlink($uploadDir . $oldFile);
            }
            $stmt = $pdo->prepare("UPDATE portfolio SET avatar_image = ? WHERE id = ?");
            $stmt->execute([$newAvatar, $caseId]);
        }

        $message = '✅ Медиа кейса обновлены.';
    }
}

// ===================== PRICES =====================
if (isset($_POST['save_all_prices'])) {
    foreach (($_POST['prices'] ?? []) as $id => $data) {
        $id       = (int)$id;
        $newImage = uploadNestedImage('price_images', $id, 'price', $uploadDir);

        if ($newImage !== '') {
            $old_stmt = $pdo->prepare("SELECT image FROM prices WHERE id = ?");
            $old_stmt->execute([$id]);
            $oldImage = (string)($old_stmt->fetchColumn() ?? '');
            if ($oldImage !== '') {
                $oldFile = (str_starts_with($oldImage,'http://') || str_starts_with($oldImage,'https://'))
                    ? basename(parse_url($oldImage, PHP_URL_PATH)) : $oldImage;
                if ($oldFile !== '' && file_exists($uploadDir . $oldFile)) unlink($uploadDir . $oldFile);
            }

            $stmt = $pdo->prepare("
                UPDATE prices
                SET title = ?, description = ?, features = ?, price_uan = ?, price_rub = ?, image = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['title']       ?? '',
                $data['description'] ?? '',
                $data['features']    ?? '',
                $data['price_uan']   ?? 0,
                $data['price_rub']   ?? 0,
                $newImage,
                $id,
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE prices
                SET title = ?, description = ?, features = ?, price_uan = ?, price_rub = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['title']       ?? '',
                $data['description'] ?? '',
                $data['features']    ?? '',
                $data['price_uan']   ?? 0,
                $data['price_rub']   ?? 0,
                $id,
            ]);
        }
    }
    $message = '💾 Прайс-лист обновлен.';
}

if (isset($_POST['add_price_service'])) {
    $title        = trim($_POST['service_title'] ?? '');
    $category_key = trim($_POST['service_key'] ?? '');
    $description  = trim($_POST['service_description'] ?? '');
    $features     = trim($_POST['service_features'] ?? '');
    $price_rub    = !empty($_POST['service_price_rub']) ? (int)$_POST['service_price_rub'] : 0;
    $price_uan    = !empty($_POST['service_price_uan']) ? (int)$_POST['service_price_uan'] : 0;
    $image        = uploadImage('service_image', 'price', $uploadDir);

    if ($category_key === '') {
        $category_key = 'service_' . time();
    }

    if ($title === '' || $category_key === '') {
        $message = '❌ Укажи название и ключ услуги.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO prices (category_key, title, description, price_rub, price_uan, features, image)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        try {
            $stmt->execute([$category_key, $title, $description, $price_rub, $price_uan, $features, $image]);
            $message = '✅ Новая услуга добавлена в прайс и уже доступна в боте.';
        } catch (PDOException $e) {
            $message = '❌ Такой ключ услуги уже существует.';
        }
    }
}

if (isset($_GET['delete_price_id'])) {
    $del_id   = (int)$_GET['delete_price_id'];
    $img_stmt = $pdo->prepare("SELECT image FROM prices WHERE id = ?");
    $img_stmt->execute([$del_id]);
    $priceImage = $img_stmt->fetchColumn();

    if (!empty($priceImage)) {
        $val = (str_starts_with($priceImage, 'http://') || str_starts_with($priceImage, 'https://'))
            ? basename(parse_url($priceImage, PHP_URL_PATH))
            : $priceImage;
        if ($val !== '' && file_exists($uploadDir . $val)) unlink($uploadDir . $val);
    }

    $stmt = $pdo->prepare("DELETE FROM prices WHERE id = ?");
    $stmt->execute([$del_id]);
    $message = '🗑️ Услуга удалена из прайса.';
}

// ===================== CATEGORIES =====================
if (isset($_POST['add_portfolio_category'])) {
    $catTitle    = trim($_POST['cat_title'] ?? '');
    $catKey      = trim($_POST['cat_key'] ?? '');
    $catWidth    = !empty($_POST['cat_width']) ? (int)$_POST['cat_width'] : 0;
    $catHeight   = !empty($_POST['cat_height']) ? (int)$_POST['cat_height'] : 0;
    $catIsDesign = !empty($_POST['cat_is_design']) ? 1 : 0;

    if ($catKey === '') {
        $catKey = 'cat_' . time();
    }

    $catKey = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $catKey));

    if ($catTitle === '') {
        $message = '❌ Укажи название категории.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO portfolio_categories (category_key, title, width_px, height_px, is_design, sort_order)
                VALUES (?, ?, ?, ?, ?, 100)
            ");
            $stmt->execute([$catKey, $catTitle, $catWidth, $catHeight, $catIsDesign]);
            $message = '✅ Категория добавлена и появилась на главной.';
        } catch (PDOException $e) {
            $message = '❌ Такая категория уже существует.';
        }
    }
}

if (isset($_GET['delete_portfolio_category_id'])) {
    $catId = (int)$_GET['delete_portfolio_category_id'];
    $stmt  = $pdo->prepare("DELETE FROM portfolio_categories WHERE id = ?");
    $stmt->execute([$catId]);
    $message = '🗑️ Категория удалена из меню.';
}

// ===================== FETCH DATA =====================
$services   = $pdo->query("SELECT * FROM prices ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT * FROM portfolio_categories ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$categoryMap = [];
foreach ($categories as $category) {
    $categoryMap[$category['category_key']] = $category;
}
$works = $pdo->query("SELECT * FROM portfolio ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$orderStats = $pdo->query("
SELECT
    COUNT(*) AS total,
    COUNT(*) FILTER (WHERE status = 'pending') AS pending,
    COUNT(*) FILTER (WHERE status = 'in_progress') AS in_progress,
    COUNT(*) FILTER (WHERE status = 'ready') AS ready,
    COUNT(*) FILTER (WHERE status = 'declined') AS declined
FROM orders
")->fetch(PDO::FETCH_ASSOC) ?: [];

$revenue = $pdo->query("
    SELECT
        COALESCE(SUM(p.price_rub), 0) AS rub,
        COALESCE(SUM(p.price_uan), 0) AS uan
    FROM orders o
    LEFT JOIN prices p ON p.category_key = o.service_key
    WHERE o.status = 'ready'
")->fetch(PDO::FETCH_ASSOC) ?: ['rub' => 0, 'uan' => 0];

$activeValue = $pdo->query("
    SELECT
        COALESCE(SUM(p.price_rub), 0) AS rub,
        COALESCE(SUM(p.price_uan), 0) AS uan
    FROM orders o
    LEFT JOIN prices p ON p.category_key = o.service_key
    WHERE o.status IN ('pending', 'in_progress')
")->fetch(PDO::FETCH_ASSOC) ?: ['rub' => 0, 'uan' => 0];

$recentOrders = $pdo->query("
    SELECT o.id, o.username, o.telegram, o.service_key, o.status, o.created_at, p.title, p.price_rub, p.price_uan
    FROM orders o
    LEFT JOIN prices p ON p.category_key = o.service_key
    ORDER BY o.id DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$categoryLabels = [];
foreach ($categories as $category) {
    $size = ((int)$category['width_px'] > 0 && (int)$category['height_px'] > 0)
        ? " ({$category['width_px']}x{$category['height_px']})"
        : '';
    $categoryLabels[$category['category_key']] = $category['title'] . $size;
}

$statusLabels = [
    'pending'     => 'Ожидает',
    'in_progress' => 'В процессе',
    'ready'       => 'Готов',
    'declined'    => 'Отклонен',
];

// Текущая аватарка сайта
$currentAvatarRow  = $pdo->query("SELECT avatar FROM users LIMIT 1")->fetch();
$currentAvatarFile = $currentAvatarRow['avatar'] ?? 'default_avatar.png';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kostlim Admin</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        /* ===== SCROLLBAR ===== */
        * { scrollbar-width: thin; scrollbar-color: #f97316 #111116; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #111116; border-radius: 99px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #fb923c, #f97316); border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: #fb923c; }

        /* ===== BASE ===== */
        body { background: #08080b; color: #fff; font-family: Montserrat, Arial, sans-serif; }
        .admin-shell { max-width: 1480px; margin: 0 auto; padding: 24px; }
        .admin-top { display: flex; justify-content: space-between; align-items: center; gap: 18px; margin-bottom: 22px; }
        .admin-title h1 { font-size: 28px; line-height: 1.1; margin: 0 0 6px; }
        .admin-title p { color: #8a8a96; margin: 0; }
        .admin-meta { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
        .admin-meta span { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #242432; background: #111116; color: #d8d8e8; border-radius: 999px; padding: 7px 11px; font-size: 12px; font-weight: 800; }
        .admin-link-top { color: #fff; text-decoration: none; border: 1px solid #242432; border-radius: 10px; padding: 11px 18px; background: #111116; font-size: 13px; font-weight: 700; transition: .2s; }
        .admin-link-top:hover { border-color: #f97316; background: rgba(249,115,22,.1); }
        .notice { border: 1px solid rgba(249,115,22,.45); background: rgba(249,115,22,.10); border-radius: 12px; padding: 14px 16px; margin-bottom: 18px; font-weight: 700; }

        /* ===== LAYOUT ===== */
        .admin-board { display: grid; grid-template-columns: 230px minmax(0, 1fr); gap: 18px; align-items: start; }
        .admin-tabs { position: sticky; top: 18px; display: grid; gap: 9px; background: #111116; border: 1px solid #20202c; border-radius: 14px; padding: 12px; }
        .admin-tab { display: flex; align-items: center; gap: 10px; width: 100%; border: 1px solid transparent; border-radius: 10px; padding: 12px 13px; background: transparent; color: #d8d8e8; font-weight: 900; text-align: left; cursor: pointer; font-family: Montserrat, sans-serif; font-size: 13px; transition: .2s; }
        .admin-tab:hover { background: #171720; border-color: #2a2a38; }
        .admin-tab.active { color: #fff; background: linear-gradient(135deg, #f97316, #ea580c); box-shadow: 0 12px 28px rgba(249,115,22,.28); border-color: transparent; }
        .admin-content { min-width: 0; }
        .stats-grid { display: grid; grid-template-columns: repeat(6, minmax(150px, 1fr)); gap: 12px; margin-bottom: 18px; }
        .stat-card { background: #111116; border: 1px solid #20202c; border-radius: 12px; padding: 16px; min-height: 92px; }
        .stat-card span { color: #8a8a96; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .stat-card strong { display: block; font-size: 25px; margin-top: 10px; }
        .stat-card.accent { border-color: rgba(249,115,22,.6); background: linear-gradient(145deg, rgba(249,115,22,.18), #111116); }
        .admin-layout { display: grid; grid-template-columns: 380px minmax(0, 1fr); gap: 18px; align-items: start; }
        .admin-layout.single-column { grid-template-columns: 1fr; }
        .panel { background: #111116; border: 1px solid #20202c; border-radius: 14px; padding: 18px; margin-bottom: 18px; }
        .panel h2 { font-size: 16px; margin-bottom: 14px; }

        /* ===== AVATAR PANEL ===== */
        .avatar-preview-wrap { display: flex; align-items: center; gap: 18px; margin-bottom: 16px; }
        .avatar-preview-img { width: 72px; height: 72px; border-radius: 50%; object-fit: cover; border: 3px solid #f97316; background: #0b0b10; flex-shrink: 0; }
        .avatar-preview-info { color: #8a8a96; font-size: 12px; line-height: 1.6; }
        .avatar-preview-info strong { display: block; color: #d8d8e8; margin-bottom: 2px; font-size: 13px; }

        /* ===== FORMS ===== */
        label { display: block; color: #d9d9e4; font-size: 12px; font-weight: 800; margin: 12px 0 6px; text-transform: uppercase; letter-spacing: .5px; }
        input:not([type="file"]):not([type="checkbox"]), select, textarea {
            width: 100%; background: #171720; color: #fff; border: 1px solid #2a2a38;
            border-radius: 9px; padding: 11px 12px; outline: none;
            font-family: Montserrat, sans-serif; font-size: 13px; transition: .2s;
        }
        input:not([type="file"]):not([type="checkbox"]):focus,
        select:focus, textarea:focus {
            border-color: #f97316; box-shadow: 0 0 0 3px rgba(249,115,22,.18), 0 0 14px rgba(249,115,22,.22);
        }
        textarea { min-height: 64px; resize: vertical; }
        select { cursor: pointer; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238a8a96' stroke-width='2.5'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; }

        /* ===== FILE INPUT ===== */
        input[type="file"].styled-hidden { display: none; }

        .file-upload-wrap {
            display: flex; align-items: center; gap: 10px; width: 100%;
        }
        .file-upload-btn {
            display: inline-flex; align-items: center; gap: 7px; cursor: pointer;
            background: #1e1e2a; border: 1px solid #2a2a38; border-radius: 9px;
            padding: 9px 16px; color: #d8d8e8; font-size: 12px; font-weight: 700;
            white-space: nowrap; transition: .2s; font-family: Montserrat, sans-serif;
            flex-shrink: 0; user-select: none;
        }
        .file-upload-btn:hover { background: rgba(249,115,22,.15); border-color: #f97316; color: #fff; }
        .file-upload-btn svg { flex-shrink: 0; }
        .file-upload-name {
            flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis;
            white-space: nowrap; color: #8a8a96; font-size: 12px; font-style: italic;
        }
        .file-upload-name.has-file { color: #86efac; font-style: normal; font-weight: 700; }

        /* Mini file upload inside table cells */
        .mini-file-wrap { display: flex; flex-direction: column; gap: 6px; }
        .mini-file-btn {
            display: inline-flex; align-items: center; gap: 6px; cursor: pointer;
            background: #1a1a24; border: 1px solid #2a2a38; border-radius: 7px;
            padding: 7px 12px; color: #c8c8d8; font-size: 11px; font-weight: 700;
            white-space: nowrap; transition: .2s; font-family: Montserrat, sans-serif;
            user-select: none;
        }
        .mini-file-btn:hover { background: rgba(249,115,22,.15); border-color: #f97316; color: #fff; }
        .mini-file-name { font-size: 10px; color: #8a8a96; font-style: italic; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px; }
        .mini-file-name.has-file { color: #86efac; font-style: normal; }

        /* ===== BUTTONS ===== */
        .btn-panel {
            width: 100%; margin-top: 14px; border: none; border-radius: 10px;
            padding: 13px 16px; background: linear-gradient(135deg, #fb923c, #f97316);
            color: #fff; font-weight: 900; cursor: pointer; text-transform: uppercase;
            font-family: Montserrat, sans-serif; letter-spacing: 1px; font-size: 13px;
            box-shadow: 0 8px 24px rgba(249,115,22,.30); transition: .2s;
        }
        .btn-panel:hover { transform: translateY(-1px); box-shadow: 0 0 28px rgba(249,115,22,.55), 0 12px 30px rgba(249,115,22,.25); }

        /* ===== TABLE ===== */
        .admin-table-wrap { overflow-x: auto; border: 1px solid #20202c; border-radius: 12px; }
        table { width: 100%; border-collapse: collapse; min-width: 760px; }
        th, td { padding: 12px; border-bottom: 1px solid #20202c; text-align: left; vertical-align: middle; }
        th { color: #8a8a96; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
        td { color: #efeff7; font-size: 13px; }
        tr:last-child td { border-bottom: 0; }
        tr:hover td { background: rgba(255,255,255,.015); }
        .thumb-pair { display: flex; align-items: center; gap: 8px; }
        .case-thumb { width: 98px; height: 55px; object-fit: cover; border-radius: 8px; background: #0b0b10; }
        .case-ava { width: 38px; height: 38px; object-fit: cover; border-radius: 50%; border: 2px solid #f97316; margin-left: -22px; background: #111116; }
        .price-thumb { width: 70px; height: 44px; object-fit: cover; border-radius: 8px; background: #0b0b10; border: 1px solid #272735; }
        .status { display: inline-flex; border-radius: 999px; padding: 6px 10px; background: #191924; color: #d8d8e8; font-weight: 800; font-size: 12px; }
        .delete-link { color: #ff6b76; text-decoration: none; font-weight: 800; font-size: 12px; padding: 6px 12px; border: 1px solid rgba(255,107,118,.25); border-radius: 7px; transition: .2s; display: inline-block; }
        .delete-link:hover { background: rgba(255,107,118,.12); border-color: #ff6b76; }

        /* ===== MINI FORM IN TABLE ===== */
        .mini-media-form { display: grid; gap: 7px; min-width: 190px; }
        .mini-media-form button {
            border: 0; border-radius: 8px; padding: 8px 12px;
            background: linear-gradient(135deg, #fb923c, #f97316);
            color: #fff; font-weight: 800; cursor: pointer;
            font-family: Montserrat, sans-serif; font-size: 11px;
            letter-spacing: .5px; text-transform: uppercase; transition: .2s;
        }
        .mini-media-form button:hover { opacity: .85; }

        /* ===== MISC ===== */
        .two-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .avatar-hint { color: #8a8a96; font-size: 12px; line-height: 1.5; margin-top: 8px; background: rgba(255,255,255,.03); border-radius: 7px; padding: 8px 10px; border-left: 2px solid #f97316; }
        .tab-hidden { display: none !important; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1100px) {
            .admin-board { grid-template-columns: 1fr; }
            .admin-tabs { position: static; grid-template-columns: repeat(2, 1fr); }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .admin-layout { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .admin-shell { padding: 16px; }
            .admin-top { align-items: flex-start; flex-direction: column; }
            .admin-tabs { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr; }
            .two-cols { grid-template-columns: 1fr; }
        }
    </style>
    <script>
        function toggleAvatarField() {
            const category = document.getElementById('category_select').value;
            const block = document.getElementById('avatar_upload_block');
            const designCategories = <?= json_encode(array_values(array_filter(array_map(fn($category) => !empty($category['is_design']) ? $category['category_key'] : null, $categories))), JSON_UNESCAPED_UNICODE) ?>;
            block.style.display = designCategories.includes(category) ? 'block' : 'none';
        }
        function activateAdminTab(tab) {
            document.querySelectorAll('.admin-tab').forEach(b => {
                b.classList.toggle('active', b.dataset.tab === tab);
            });

            const stats  = document.querySelector('.stats-grid');
            const layout = document.querySelector('.admin-layout');

            // Скрыть всё
            if (stats)  stats.classList.add('tab-hidden');
            if (layout) layout.classList.add('single-column');
            document.querySelectorAll('.panel').forEach(p => p.classList.add('tab-hidden'));

            const show = (...names) => names.forEach(name => {
                const el = document.querySelector(`.panel[data-panel="${name}"]`);
                if (el) el.classList.remove('tab-hidden');
            });

            if (tab === 'overview') {
                if (stats) stats.classList.remove('tab-hidden');
                show('orders');
            } else if (tab === 'portfolio') {
                show('portfolio-add', 'portfolio-list');
                if (layout) layout.classList.remove('single-column');
            } else if (tab === 'price') {
                show('price-add', 'price-manager');
                if (layout) layout.classList.remove('single-column');
            } else if (tab === 'orders') {
                if (stats) stats.classList.remove('tab-hidden');
                show('orders');
            } else if (tab === 'categories') {
                show('categories');
            } else if (tab === 'avatar') {
                show('avatar');
            }
        }

        // Beautiful file inputs
        function initFileInputs() {
            document.querySelectorAll('input[type="file"]').forEach(input => {
                if (input.dataset.styled) return;
                input.dataset.styled = '1';
                input.classList.add('styled-hidden');

                const isMini = input.closest('.mini-media-form') !== null;
                const wrap   = document.createElement('div');
                wrap.className = isMini ? 'mini-file-wrap' : 'file-upload-wrap';

                const label = document.createElement('label');
                label.htmlFor = input.id || (input.id = 'fi_' + Math.random().toString(36).slice(2));
                label.className = isMini ? 'mini-file-btn' : 'file-upload-btn';
                label.style.margin = '0';
                label.innerHTML = isMini
                    ? `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Выбрать файл`
                    : `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Выбрать файл`;

                const nameSpan = document.createElement('span');
                nameSpan.className = isMini ? 'mini-file-name' : 'file-upload-name';
                nameSpan.textContent = 'Файл не выбран';

                input.addEventListener('change', () => {
                    const name    = input.files[0]?.name || 'Файл не выбран';
                    const hasFile = !!input.files[0];
                    nameSpan.textContent = hasFile ? name : 'Файл не выбран';
                    nameSpan.classList.toggle('has-file', hasFile);
                });

                input.parentNode.insertBefore(wrap, input);
                wrap.appendChild(input);
                wrap.appendChild(label);
                wrap.appendChild(nameSpan);
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            toggleAvatarField();
            activateAdminTab('overview');
            initFileInputs();

            // Re-init on tab switch (panels become visible)
            document.querySelectorAll('.admin-tab').forEach(btn => {
                btn.addEventListener('click', () => setTimeout(initFileInputs, 50));
            });
        });
    </script>
</head>
<body>
<main class="admin-shell">
    <div class="admin-top">
        <div class="admin-title">
            <h1>⚙️ Админ-панель Kostlim Design</h1>
            <p>Портфолио, прайс, заказы и деньги в одном месте.</p>
            <div class="admin-meta">
                <span>Kostlim</span>
                <span><?= htmlspecialchars(ADMIN_EMAIL) ?></span>
                <span>TG ID: <?= htmlspecialchars(ADMIN_TELEGRAM_ID) ?></span>
            </div>
        </div>
        <a href="../index.php" class="admin-link-top">← На сайт</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="admin-board">
        <nav class="admin-tabs" aria-label="Разделы админ-панели">
            <button type="button" class="admin-tab active" data-tab="overview"    onclick="activateAdminTab('overview')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg> Обзор</button>
            <button type="button" class="admin-tab"        data-tab="portfolio"   onclick="activateAdminTab('portfolio')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="15" rx="2"/><path d="M16 2l-4 5-4-5"/></svg> Портфолио</button>
            <button type="button" class="admin-tab"        data-tab="price"       onclick="activateAdminTab('price')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> Прайс</button>
            <button type="button" class="admin-tab"        data-tab="orders"      onclick="activateAdminTab('orders')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg> Заказы</button>
            <button type="button" class="admin-tab"        data-tab="categories"  onclick="activateAdminTab('categories')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg> Категории</button>
            <button type="button" class="admin-tab"        data-tab="avatar"      onclick="activateAdminTab('avatar')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Аватарка</button>
        </nav>

        <div class="admin-content">
    <section class="stats-grid">
        <div class="stat-card accent"><span>Заработано</span><strong><?= money($revenue['rub'] ?? 0) ?> ₽</strong><span><?= money($revenue['uan'] ?? 0) ?> ₴</span></div>
        <div class="stat-card"><span>В активе</span><strong><?= money($activeValue['rub'] ?? 0) ?> ₽</strong><span><?= money($activeValue['uan'] ?? 0) ?> ₴</span></div>
        <div class="stat-card"><span>Всего заказов</span><strong><?= (int)($orderStats['total'] ?? 0) ?></strong></div>
        <div class="stat-card"><span>Ожидают</span><strong><?= (int)($orderStats['pending'] ?? 0) ?></strong></div>
        <div class="stat-card"><span>В процессе</span><strong><?= (int)($orderStats['in_progress'] ?? 0) ?></strong></div>
        <div class="stat-card"><span>Готово</span><strong><?= (int)($orderStats['ready'] ?? 0) ?></strong></div>
    </section>

    <div class="admin-layout">
        <aside>
            <!-- PANEL 0: Добавить в портфолио -->
            <section class="panel" data-panel="portfolio-add">
                <h2>📁 Добавить в портфолио</h2>
                <form action="" method="POST" enctype="multipart/form-data">
                    <label>Название проекта</label>
                    <input type="text" name="title" required placeholder="Например: сет Naruto">

                    <label>Категория графики</label>
                    <select name="category_key" id="category_select" onchange="toggleAvatarField()">
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars($category['category_key']) ?>">
                                <?= htmlspecialchars($category['title']) ?>
                                <?php if ((int)$category['width_px'] > 0 && (int)$category['height_px'] > 0): ?>
                                    (<?= (int)$category['width_px'] ?>x<?= (int)$category['height_px'] ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="two-cols">
                        <div>
                            <label>Цена в рублях</label>
                            <input type="number" name="price_rub" value="0" min="0">
                        </div>
                        <div>
                            <label>Цена в гривнах</label>
                            <input type="number" name="price_uan" value="0" min="0">
                        </div>
                    </div>

                    <label>Главное изображение / шапка</label>
                    <input type="file" name="image" accept="image/*">

                    <div id="avatar_upload_block" style="display:none;">
                        <label>Аватарка к оформлению</label>
                        <input type="file" name="avatar_image" accept="image/*">
                        <div class="avatar-hint">Для категории "Оформление" шапка будет широкой, а аватарка появится круглым превью справа.</div>
                    </div>

                    <button type="submit" name="add_portfolio" class="btn-panel">Загрузить в кейсы</button>
                </form>
            </section>

            <!-- PANEL 1: Создать категорию -->
            <section class="panel" data-panel="categories">
                <h2>🧩 Создать категорию</h2>
                <form action="" method="POST">
                    <label>Название категории</label>
                    <input type="text" name="cat_title" required placeholder="Например: Пост VK">

                    <label>Ключ категории</label>
                    <input type="text" name="cat_key" placeholder="vk_post">
                    <div class="avatar-hint">Латиница без пробелов. По этому ключу фильтруется раздел на главной.</div>

                    <div class="two-cols">
                        <div>
                            <label>Ширина, px</label>
                            <input type="number" name="cat_width" min="0" placeholder="1080">
                        </div>
                        <div>
                            <label>Высота, px</label>
                            <input type="number" name="cat_height" min="0" placeholder="1080">
                        </div>
                    </div>

                    <label style="display:flex; gap:8px; align-items:center; margin-top:14px;">
                        <input type="checkbox" name="cat_is_design" value="1" style="width:auto; margin:0;">
                        Это оформление с аватаркой
                    </label>

                    <button type="submit" name="add_portfolio_category" class="btn-panel">Добавить категорию</button>
                </form>

                <div style="margin-top:14px; display:grid; gap:8px;">
                    <?php foreach ($categories as $category): ?>
                        <div style="display:flex; justify-content:space-between; gap:10px; color:#d8d8e8; font-size:12px; border-top:1px solid #242432; padding-top:8px;">
                            <span>
                                <?= htmlspecialchars($category['title']) ?>
                                <?php if ((int)$category['width_px'] > 0 && (int)$category['height_px'] > 0): ?>
                                    · <?= (int)$category['width_px'] ?>x<?= (int)$category['height_px'] ?>
                                <?php endif; ?>
                            </span>
                            <a class="delete-link" href="?delete_portfolio_category_id=<?= (int)$category['id'] ?>" onclick="return confirm('Удалить категорию из меню? Кейсы не удалятся.')">Удалить</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- PANEL 2: Последние заказы -->
            <section class="panel" data-panel="orders">
                <h2>🧾 Последние заказы</h2>
                <div class="admin-table-wrap">
                    <table style="min-width: 520px;">
                        <thead>
                            <tr><th>ID</th><th>Клиент</th><th>Статус</th><th>Сумма</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentOrders as $order): ?>
                                <tr>
                                    <td>#<?= (int)$order['id'] ?></td>
                                    <td><?= htmlspecialchars($order['username'] ?? 'Клиент') ?><br><span style="color:#8a8a96;"><?= htmlspecialchars($order['telegram'] ?? '') ?></span></td>
                                    <td><span class="status"><?= htmlspecialchars($statusLabels[$order['status']] ?? $order['status']) ?></span></td>
                                    <td><?= (int)($order['price_rub'] ?? 0) ?> ₽<br><span style="color:#8a8a96;"><?= (int)($order['price_uan'] ?? 0) ?> ₴</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- PANEL 4: Добавить услугу в прайс -->
            <section class="panel" data-panel="price-add">
                <h2>➕ Добавить услугу в прайс</h2>
                <form action="" method="POST" enctype="multipart/form-data">
                    <label>Название услуги</label>
                    <input type="text" name="service_title" required placeholder="Например: Баннер для постов">

                    <label>Ключ услуги</label>
                    <input type="text" name="service_key" placeholder="post_banner">
                    <div class="avatar-hint">Латиницей, без пробелов. Этот ключ попадет в форму заказа и Telegram-бот.</div>

                    <div class="two-cols">
                        <div>
                            <label>Цена в рублях</label>
                            <input type="number" name="service_price_rub" value="0" min="0">
                        </div>
                        <div>
                            <label>Цена в гривнах</label>
                            <input type="number" name="service_price_uan" value="0" min="0">
                        </div>
                    </div>

                    <label>Описание</label>
                    <textarea name="service_description" placeholder="Коротко, что входит в услугу"></textarea>

                    <label>Фичи</label>
                    <input type="text" name="service_features" placeholder="Через | например: PSD-файл|2 правки|быстрая сдача">

                    <label>Обложка услуги (фото для прайса)</label>
                    <input type="file" name="service_image" accept="image/*">
                    <div class="avatar-hint">Картинка отображается на карточке услуги в прайс-листе сайта. Рекомендуется 16:9.</div>

                    <button type="submit" name="add_price_service" class="btn-panel">Добавить услугу</button>
                </form>
            </section>

            <!-- PANEL 6: Аватарка сайта -->
            <section class="panel" data-panel="avatar">
                <h2>🖼️ Аватарка сайта</h2>
                <div class="avatar-preview-wrap">
                    <img
                        src="../uploads/<?= htmlspecialchars($currentAvatarFile) ?>"
                        class="avatar-preview-img"
                        alt="Текущая аватарка"
                        onerror="this.src='https://i.imgur.com/w9NThbA.png'"
                    >
                    <div class="avatar-preview-info">
                        <strong>Текущая аватарка</strong>
                        <?= htmlspecialchars($currentAvatarFile) ?><br>
                        Отображается в шапке сайта и на водяном знаке постов в Telegram.
                    </div>
                </div>
                <form action="" method="POST" enctype="multipart/form-data">
                    <label>Новая аватарка сайта</label>
                    <input type="file" name="site_avatar" accept="image/*" required>
                    <div class="avatar-hint">Форматы: jpg, png, webp, gif. Рекомендуется квадратное фото (например 512×512). После загрузки аватарка сразу появится в шапке сайта.</div>
                    <button type="submit" name="upload_site_avatar" class="btn-panel">Загрузить аватарку</button>
                </form>
            </section>
        </aside>

        <section>
            <!-- PANEL 3: Менеджер цен -->
            <div class="panel" data-panel="price-manager">
                <h2>💲 Менеджер цен и прайс-листа</h2>
                <form action="" method="POST" enctype="multipart/form-data">
                    <div class="admin-table-wrap">
                        <table>
                            <thead>
                                <tr><th>Обложка</th><th>Услуга</th><th>Описание и фичи</th><th>Цены</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($services as $service): $id = (int)$service['id']; ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($service['image'])): ?>
                                                <img src="<?= htmlspecialchars(imgSrc($service['image'] ?? '')) ?>" class="price-thumb" alt="">
                                            <?php else: ?>
                                                <span style="color:#666674; font-size:11px;">Нет обложки</span>
                                            <?php endif; ?>
                                            <div style="margin-top:8px;">
                                                <input type="file" name="price_images[<?= $id ?>]" accept="image/*">
                                            </div>
                                        </td>
                                        <td>
                                            <input type="text" name="prices[<?= $id ?>][title]" value="<?= htmlspecialchars($service['title'] ?? '') ?>">
                                            <div style="color:#8a8a96; margin-top:6px;">key: <?= htmlspecialchars($service['category_key'] ?? '') ?></div>
                                        </td>
                                        <td>
                                            <textarea name="prices[<?= $id ?>][description]"><?= htmlspecialchars($service['description'] ?? '') ?></textarea>
                                            <input type="text" name="prices[<?= $id ?>][features]" value="<?= htmlspecialchars($service['features'] ?? '') ?>" placeholder="Фичи через |">
                                        </td>
                                        <td>
                                            <input type="number" name="prices[<?= $id ?>][price_rub]" value="<?= htmlspecialchars($service['price_rub'] ?? '0') ?>">
                                            <input type="number" name="prices[<?= $id ?>][price_uan]" value="<?= htmlspecialchars($service['price_uan'] ?? '0') ?>" style="margin-top:8px;">
                                        </td>
                                        <td>
                                            <a class="delete-link" href="?delete_price_id=<?= $id ?>" onclick="return confirm('Удалить услугу из прайса?')">Удалить</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" name="save_all_prices" class="btn-panel" style="background:#22c55e;">Сохранить весь прайс</button>
                </form>
            </div>

            <!-- PANEL 5: Управление кейсами -->
            <div class="panel" data-panel="portfolio-list">
                <h2>🎬 Управление кейсами</h2>
                <div class="admin-table-wrap">
                    <table>
                        <thead>
                            <tr><th>Превью</th><th>Название</th><th>Категория</th><th>Цена</th><th>Действие</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($works as $work): ?>
                                <?php
                                    $img = $work['image'] ?? '';
                                    $ava = $work['avatar_image'] ?? '';
                                    $cat = $work['category_key'] ?? 'preview';
                                ?>
                                <tr>
                                    <td>
                                        <div class="thumb-pair">
                                            <?php if ($img !== ''): ?>
                                                <img src="<?= htmlspecialchars(imgSrc($img)) ?>" class="case-thumb" alt="">
                                            <?php endif; ?>
                                            <?php if ($ava !== ''): ?>
                                                <img src="<?= htmlspecialchars(imgSrc($ava)) ?>" class="case-ava" alt="">
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><strong><?= htmlspecialchars($work['title'] ?? 'Без названия') ?></strong></td>
                                    <td><?= htmlspecialchars($categoryLabels[$cat] ?? $cat) ?></td>
                                    <td><?= (int)($work['price_rub'] ?? 0) ?> ₽ / <?= (int)($work['price_uan'] ?? 0) ?> ₴</td>
                                    <td>
                                        <form action="" method="POST" enctype="multipart/form-data" class="mini-media-form">
                                            <input type="hidden" name="portfolio_id" value="<?= (int)$work['id'] ?>">
                                            <input type="file" name="portfolio_image" accept="image/*" title="Заменить шапку/главное изображение">
                                            <input type="file" name="portfolio_avatar" accept="image/*" title="Заменить аватарку">
                                            <button type="submit" name="update_portfolio_media">Обновить медиа</button>
                                            <a class="delete-link" href="?delete_portfolio_id=<?= (int)$work['id'] ?>" onclick="return confirm('Удалить кейс?')">Удалить</a>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
        </div>
    </div>
</main>
</body>
</html>

