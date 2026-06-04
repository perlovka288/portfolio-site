<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'auth.php';
require_once '../config/db.php';

$message = '';
$uploadDir = '../uploads/';
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg');
define('PORTFOLIO_CHANNEL_CHAT', getenv('PORTFOLIO_CHANNEL_CHAT') ?: '@gfasasdasasd');
define('PUBLIC_SITE_URL', 'https://portfolio-site-boo5.onrender.com/');
define('ADMIN_EMAIL', 'jeffkostlim@gmail.com');
define('ADMIN_TELEGRAM_ID', '1710365896');
$telegramLastError = '';

ensureDefaultPortfolioCategories($pdo);

// ── AJAX endpoint: добавить портфолио ────────────────────────────
if (isset($_POST['add_portfolio']) && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    ob_start();

    $title        = trim($_POST['title'] ?? '');
    $category_key = $_POST['category_key'] ?? 'preview';
    $price_rub    = !empty($_POST['price_rub']) ? (int)$_POST['price_rub'] : 0;
    $price_uan    = !empty($_POST['price_uan']) ? (int)$_POST['price_uan'] : 0;
    $publish_tg   = !empty($_POST['publish_tg']);

    $filename_main   = uploadImage('image', 'main', $uploadDir);
    $filename_avatar = uploadImage('avatar_image', 'ava', $uploadDir);

    if ($title === '') {
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => '❌ Укажи название проекта.']);
        exit;
    }
    if ($filename_main === '') {
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => '❌ Не удалось загрузить изображение. Проверь IMGBB_API_KEY в Render.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO portfolio (title, category_key, price_rub, price_uan, image, avatar_image) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$title, $category_key, $price_rub, $price_uan, $filename_main, $filename_avatar]);

    $postedToChannel = false;
    if ($publish_tg) {
        $postedToChannel = publishPortfolioToChannel($pdo, $uploadDir, [
            'title'        => $title,
            'category_key' => $category_key,
            'price_rub'    => $price_rub,
            'price_uan'    => $price_uan,
            'image'        => $filename_main,
            'avatar_image' => $filename_avatar,
        ]);
    }

    ob_end_clean();
    $msg = '✅ Портфолио сохранено!';
    if ($publish_tg) {
        $msg .= $postedToChannel
            ? ' Пост отправлен в Telegram-канал.'
            : ' (Telegram-канал: ' . ($telegramLastError ?: 'проверь настройки бота') . ')';
    } else {
        $msg .= ' Без публикации в Telegram.';
    }
    echo json_encode(['ok' => true, 'msg' => $msg]);
    exit;
}

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
    $response  = curl_exec($ch);
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

// ── Уведомление админу о новом обращении ────────────────────────
function notifyAdminNewAppeal(array $ap): void
{
    if (TELEGRAM_BOT_TOKEN === '' || ADMIN_TELEGRAM_ID === '') return;
    $adminUrl = PUBLIC_SITE_URL . 'admin/index.php';
    $text = "📩 <b>Новое обращение!</b>\n\n"
        . "👤 Клиент: <b>" . htmlspecialchars($ap['username'] ?? '') . "</b>\n"
        . "📋 Заказ: <b>#" . (int)($ap['order_id'] ?? 0) . "</b>\n"
        . "📌 Тема: <b>" . htmlspecialchars($ap['subject'] ?? '') . "</b>\n\n"
        . "💬 <i>" . htmlspecialchars(mb_substr($ap['message'] ?? '', 0, 300)) . (mb_strlen($ap['message'] ?? '') > 300 ? '...' : '') . "</i>\n\n"
        . "🔗 <a href=\"" . $adminUrl . "\">Открыть админ-панель</a>";

    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT       => 10,
        CURLOPT_POSTFIELDS    => [
            'chat_id'    => ADMIN_TELEGRAM_ID,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function defaultPortfolioCategories(): array
{
    return [
        ['preview', 'Превью', 1920, 1080, 0, 10],
        ['youtube_design', 'Оформление для YouTube', 1920, 768, 1, 20],
        ['vk_design', 'Оформление для VK', 1920, 768, 1, 30],
        ['banner', 'Баннеры', 1000, 1200, 0, 40],
        ['avatar', 'Аватарки', 1000, 1000, 0, 50],
    ];
}

function ensureDefaultPortfolioCategories(PDO $pdo): void
{
    $stmt = $pdo->prepare("
        INSERT INTO portfolio_categories (category_key, title, width_px, height_px, is_design, sort_order)
        VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT (category_key) DO UPDATE SET
            title = EXCLUDED.title,
            width_px = EXCLUDED.width_px,
            height_px = EXCLUDED.height_px,
            is_design = EXCLUDED.is_design,
            sort_order = EXCLUDED.sort_order
    ");

    foreach (defaultPortfolioCategories() as $category) {
        $stmt->execute($category);
    }
}

function imageFromFile(string $path)
{
    $info = @getimagesize($path);
    if (!$info) return null;
    return match ($info[2]) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => imagecreatefrompng($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : null,
        IMAGETYPE_GIF  => imagecreatefromgif($path),
        default        => null,
    };
}

function gdFontPath(bool $regular = false): string
{
    $paths = $regular
        ? [
            __DIR__ . '/../assets/fonts/GoogleSans-Regular.ttf',
            __DIR__ . '/../assets/fonts/GoogleSansText-Regular.ttf',
            __DIR__ . '/../assets/fonts/ProductSans-Regular.ttf',
            __DIR__ . '/../assets/fonts/Montserrat-Regular.ttf',
            __DIR__ . '/../assets/fonts/Vera.ttf',
            'C:/Windows/Fonts/arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        ]
        : [
            __DIR__ . '/../assets/fonts/GoogleSans-Bold.ttf',
            __DIR__ . '/../assets/fonts/GoogleSansText-Bold.ttf',
            __DIR__ . '/../assets/fonts/ProductSans-Bold.ttf',
            __DIR__ . '/../assets/fonts/Montserrat-Bold.ttf',
            __DIR__ . '/../assets/fonts/VeraBd.ttf',
            'C:/Windows/Fonts/arialbd.ttf',
            'C:/Windows/Fonts/arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ];
    foreach ($paths as $path) {
        if (is_file($path)) return $path;
    }
    return '';
}

function channelTemplatePath(): string
{
    $paths = [
        __DIR__ . '/../uploads/channel_template.png',
        __DIR__ . '/../uploads/channel-template.png',
        __DIR__ . '/../uploads/cover_template.png',
        __DIR__ . '/../uploads/cover-template.png',
        __DIR__ . '/channel_template.png',
        __DIR__ . '/channel-template.png',
        __DIR__ . '/../assets/channel_template.png',
        __DIR__ . '/../assets/channel-template.png',
    ];
    foreach ($paths as $path) {
        if (is_file($path)) return $path;
    }
    return '';
}

function drawFilledRoundedRect($img, int $x, int $y, int $w, int $h, int $radius, int $color): void
{
    imagefilledrectangle($img, $x + $radius, $y, $x + $w - $radius, $y + $h, $color);
    imagefilledrectangle($img, $x, $y + $radius, $x + $w, $y + $h - $radius, $color);
    imagefilledellipse($img, $x + $radius, $y + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x + $w - $radius, $y + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x + $radius, $y + $h - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x + $w - $radius, $y + $h - $radius, $radius * 2, $radius * 2, $color);
}

function drawTextFit($img, string $text, int $x, int $y, int $maxW, int $size, int $color, string $font, int $minSize = 20): void
{
    $text = trim($text);
    if ($text === '') return;
    if ($font !== '' && function_exists('imagettftext')) {
        while ($size > $minSize) {
            $box = imagettfbbox($size, 0, $font, $text);
            if (($box[2] - $box[0]) <= $maxW) break;
            $size -= 2;
        }
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
        return;
    }
    $fontId = 5;
    $sourceW = max(1, imagefontwidth($fontId) * strlen($text));
    $sourceH = max(1, imagefontheight($fontId));
    $targetH = max(18, $size);
    $targetW = (int)round($sourceW * ($targetH / $sourceH));
    if ($targetW > $maxW) { $targetW = $maxW; $targetH = (int)round($sourceH * ($targetW / $sourceW)); }
    $tmp = imagecreatetruecolor($sourceW, $sourceH);
    imagealphablending($tmp, false); imagesavealpha($tmp, true);
    $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
    imagefill($tmp, 0, 0, $transparent);
    imagestring($tmp, $fontId, 0, 0, $text, $color);
    imagecopyresampled($img, $tmp, $x, $y - $targetH, 0, 0, $targetW, $targetH, $sourceW, $sourceH);
    imagedestroy($tmp);
}

function drawTextCenteredFit($img, string $text, int $centerX, int $y, int $maxW, int $size, int $color, string $font, int $minSize = 20): void
{
    $text = trim($text);
    if ($text === '') return;
    if ($font !== '' && function_exists('imagettftext')) {
        while ($size > $minSize) {
            $box = imagettfbbox($size, 0, $font, $text);
            if (($box[2] - $box[0]) <= $maxW) break;
            $size -= 2;
        }
        $box = imagettfbbox($size, 0, $font, $text);
        $textW = $box[2] - $box[0];
        imagettftext($img, $size, 0, (int)round($centerX - ($textW / 2)), $y, $color, $font, $text);
        return;
    }
    $fontId = 5;
    $sourceW = max(1, imagefontwidth($fontId) * strlen($text));
    $sourceH = max(1, imagefontheight($fontId));
    $targetH = max(18, $size);
    $targetW = (int)round($sourceW * ($targetH / $sourceH));
    if ($targetW > $maxW) { $targetW = $maxW; $targetH = (int)round($sourceH * ($targetW / $sourceW)); }
    $tmp = imagecreatetruecolor($sourceW, $sourceH);
    imagealphablending($tmp, false); imagesavealpha($tmp, true);
    $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
    imagefill($tmp, 0, 0, $transparent);
    imagestring($tmp, $fontId, 0, 0, $text, $color);
    imagecopyresampled($img, $tmp, (int)round($centerX - ($targetW / 2)), $y - $targetH, 0, 0, $targetW, $targetH, $sourceW, $sourceH);
    imagedestroy($tmp);
}

function copyImageCover($dst, $src, int $dx, int $dy, int $dw, int $dh): void
{
    $sw = imagesx($src); $sh = imagesy($src);
    if ($sw <= 0 || $sh <= 0 || $dw <= 0 || $dh <= 0) return;
    $srcRatio = $sw / $sh; $dstRatio = $dw / $dh;
    if ($srcRatio > $dstRatio) { $cropH = $sh; $cropW = (int)round($sh * $dstRatio); $sx = (int)round(($sw - $cropW) / 2); $sy = 0; }
    else { $cropW = $sw; $cropH = (int)round($sw / $dstRatio); $sx = 0; $sy = (int)round(($sh - $cropH) / 2); }
    imagecopyresampled($dst, $src, $dx, $dy, $sx, $sy, $dw, $dh, $cropW, $cropH);
}

function copyImageContain($dst, $src, int $dx, int $dy, int $dw, int $dh): void
{
    $sw = imagesx($src); $sh = imagesy($src);
    if ($sw <= 0 || $sh <= 0 || $dw <= 0 || $dh <= 0) return;
    $scale = min($dw / $sw, $dh / $sh);
    $drawW = (int)round($sw * $scale); $drawH = (int)round($sh * $scale);
    $drawX = $dx + (int)round(($dw - $drawW) / 2); $drawY = $dy + (int)round(($dh - $drawH) / 2);
    imagecopyresampled($dst, $src, $drawX, $drawY, 0, 0, $drawW, $drawH, $sw, $sh);
}

function applyRoundedCorners($img, int $radius): void
{
    $w = imagesx($img); $h = imagesy($img);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $inCorner = false; $cx = $x; $cy = $y;
            if ($x < $radius && $y < $radius) { $cx = $radius; $cy = $radius; $inCorner = true; }
            elseif ($x >= $w - $radius && $y < $radius) { $cx = $w - $radius - 1; $cy = $radius; $inCorner = true; }
            elseif ($x < $radius && $y >= $h - $radius) { $cx = $radius; $cy = $h - $radius - 1; $inCorner = true; }
            elseif ($x >= $w - $radius && $y >= $h - $radius) { $cx = $w - $radius - 1; $cy = $h - $radius - 1; $inCorner = true; }
            if ($inCorner) { $dx = $x - $cx; $dy = $y - $cy; if (($dx * $dx + $dy * $dy) > ($radius * $radius)) imagesetpixel($img, $x, $y, $transparent); }
        }
    }
}

function drawCircularImage($dst, $src, int $x, int $y, int $size): void
{
    $avatar = imagecreatetruecolor($size, $size);
    imagealphablending($avatar, false); imagesavealpha($avatar, true);
    $transparent = imagecolorallocatealpha($avatar, 0, 0, 0, 127);
    imagefill($avatar, 0, 0, $transparent);
    imagecopyresampled($avatar, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
    $radius = $size / 2;
    for ($py = 0; $py < $size; $py++) {
        for ($px = 0; $px < $size; $px++) {
            $dx = $px - $radius; $dy = $py - $radius;
            if (($dx * $dx + $dy * $dy) <= ($radius * $radius)) imagesetpixel($dst, $x + $px, $y + $py, imagecolorat($avatar, $px, $py));
        }
    }
    imagedestroy($avatar);
}

function createWatermarkedImage(string $mainPath, string $avatarPath, string $title = '', int $priceRub = 0, int $priceUan = 0, array $category = []): string
{
    if (!extension_loaded('gd') || !is_file($mainPath)) return $mainPath;
    $main = imageFromFile($mainPath);
    if (!$main) return $mainPath;
    $avatar = (is_file($avatarPath)) ? imageFromFile($avatarPath) : null;
    $mainW = max(1, imagesx($main)); $mainH = max(1, imagesy($main));
    $catW = (int)($category['width_px'] ?? 0); $catH = (int)($category['height_px'] ?? 0);
    $isDesign = !empty($category['is_design']);
    if ($catW <= 0 || $catH <= 0) { $catW = $mainW; $catH = $mainH; }
    $outW = $catW; $outH = $catH;
    $scale = 2; $canvasW = $outW * $scale; $canvasH = $outH * $scale;
    $canvas = imagecreatetruecolor($canvasW, $canvasH);
    imagealphablending($canvas, true); imagesavealpha($canvas, true);
    $template = channelTemplatePath();
    $templateImg = $template !== '' ? imageFromFile($template) : null;
    if ($templateImg) { copyImageCover($canvas, $templateImg, 0, 0, $canvasW, $canvasH); imagedestroy($templateImg); }
    else {
        for ($y = 0; $y < $canvasH; $y++) {
            $mix = $y / $canvasH; $r = (int)(10 + 34 * $mix); $g = (int)(10 + 12 * $mix); $b = (int)(14 + 4 * $mix);
            imageline($canvas, 0, $y, $canvasW, $y, imagecolorallocate($canvas, $r, $g, $b));
        }
        $glow = imagecolorallocatealpha($canvas, 249, 115, 22, 105);
        imagefilledellipse($canvas, (int)($canvasW * .86), (int)($canvasH * .18), (int)($canvasW * .70), (int)($canvasH * .45), $glow);
        imagefilledellipse($canvas, (int)($canvasW * .15), (int)($canvasH * .92), (int)($canvasW * .55), (int)($canvasH * .35), $glow);
    }
    $padding = (int)round(min($canvasW, $canvasH) * 0.055);
    $avatarSize = $avatar ? (int)round(min($canvasW, $canvasH) * 0.12) : 0;
    $brandH = $avatar ? (int)round($avatarSize * 1.45) : 0;
    $gap = $avatar ? (int)round(min($canvasW, $canvasH) * 0.026) : 0;
    $availableW = $canvasW - ($padding * 2);
    $availableH = $canvasH - ($padding * 2) - $brandH - $gap;
    if ($availableH < (int)round($canvasH * .48)) $availableH = (int)round($canvasH * .48);
    $frameScale = min($availableW / $catW, $availableH / $catH);
    $panelW = (int)round($catW * $frameScale); $panelH = (int)round($catH * $frameScale);
    $panelX = (int)round(($canvasW - $panelW) / 2); $panelY = $padding + (int)round(($availableH - $panelH) / 2);
    $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 78);
    drawFilledRoundedRect($canvas, $panelX + (8 * $scale), $panelY + (10 * $scale), $panelW, $panelH, 34 * $scale, $shadow);
    $panel = imagecreatetruecolor($panelW, $panelH);
    imagealphablending($panel, true); imagesavealpha($panel, true);
    $transparent = imagecolorallocatealpha($panel, 0, 0, 0, 127);
    imagefill($panel, 0, 0, $transparent);
    copyImageContain($panel, $main, 0, 0, $panelW, $panelH);
    applyRoundedCorners($panel, 26 * $scale);
    imagecopy($canvas, $panel, $panelX, $panelY, 0, 0, $panelW, $panelH);
    imagedestroy($panel);
    $line = imagecolorallocatealpha($canvas, 255, 255, 255, 34);
    imagesetthickness($canvas, max(1, 2 * $scale));
    imagerectangle($canvas, $panelX, $panelY, $panelX + $panelW, $panelY + $panelH, $line);
    if ($avatar && $isDesign) {
        $avatarSize = (int)round(min($panelW, $panelH) * 0.26);
        $avatarSize = max(80 * $scale, min($avatarSize, 190 * $scale));
        $avatarPad = (int)round($avatarSize * 0.18);
        $blockW = $avatarSize + ($avatarPad * 2); $blockH = $avatarSize + ($avatarPad * 2);
        $blockX = $panelX + $panelW - $blockW - (int)round($panelW * 0.035);
        $blockY = $panelY + $panelH - $blockH - (int)round($panelH * 0.055);
        $blockBg = imagecolorallocatealpha($canvas, 0, 0, 0, 24);
        drawFilledRoundedRect($canvas, $blockX, $blockY, $blockW, $blockH, 24 * $scale, $blockBg);
        drawCircularImage($canvas, $avatar, $blockX + $avatarPad, $blockY + $avatarPad, $avatarSize);
        imagedestroy($avatar);
    } elseif ($avatar) {
        $blockW = (int)round($avatarSize * 1.6); $blockH = (int)round($avatarSize * 1.25);
        $blockX = (int)round(($canvasW - $blockW) / 2); $blockY = $panelY + $panelH + $gap;
        $blockBg = imagecolorallocatealpha($canvas, 0, 0, 0, 22);
        drawFilledRoundedRect($canvas, $blockX, $blockY, $blockW, $blockH, 24 * $scale, $blockBg);
        drawCircularImage($canvas, $avatar, (int)round(($canvasW - $avatarSize) / 2), $blockY + (int)round(($blockH - $avatarSize) / 2), $avatarSize);
        imagedestroy($avatar);
    }
    $final = imagecreatetruecolor($outW, $outH);
    imagecopyresampled($final, $canvas, 0, 0, 0, 0, $outW, $outH, $canvasW, $canvasH);
    $output = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'portfolio_channel_' . uniqid('', true) . '.jpg';
    imagejpeg($final, $output, 100);
    imagedestroy($main); imagedestroy($canvas); imagedestroy($final);
    return $output;
}

function downloadToTemp(string $url): string
{
    if ($url === '') return '';
    $tmp = tempnam(sys_get_temp_dir(), 'imgdl_') . '.jpg';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20]);
    $data = curl_exec($ch); curl_close($ch);
    if ($data && file_put_contents($tmp, $data)) return $tmp;
    return '';
}

function publishPortfolioToChannel(PDO $pdo, string $uploadDir, array $case): bool
{
    global $telegramLastError;
    $imgVal = (string)($case['image'] ?? '');
    if (str_starts_with($imgVal, 'http://') || str_starts_with($imgVal, 'https://')) { $mainPath = downloadToTemp($imgVal); $downloaded = true; }
    else { $mainPath = $uploadDir . basename($imgVal); $downloaded = false; }
    if (!is_file($mainPath)) return false;
    $rub = (int)($case['price_rub'] ?? 0); $uan = (int)($case['price_uan'] ?? 0);
    $category = [];
    try {
        $catKey = (string)($case['category_key'] ?? '');
        if ($catKey !== '') { $stmt = $pdo->prepare('SELECT width_px, height_px, is_design FROM portfolio_categories WHERE category_key = ? LIMIT 1'); $stmt->execute([$catKey]); $category = $stmt->fetch(PDO::FETCH_ASSOC) ?: []; }
    } catch (Throwable $e) {}
    $avatarVal = (string)($case['avatar_image'] ?? '');
    try {
        if ($avatarVal === '' && empty($category['is_design'])) { $stmt = $pdo->query('SELECT avatar FROM users LIMIT 1'); $avatarVal = (string)($stmt->fetchColumn() ?: ''); }
    } catch (Throwable $e) {}
    if (str_starts_with($avatarVal, 'http://') || str_starts_with($avatarVal, 'https://')) { $avatarPath = downloadToTemp($avatarVal); $avatarDownloaded = true; }
    else { $avatarPath = $avatarVal !== '' ? $uploadDir . basename($avatarVal) : ''; $avatarDownloaded = false; }
    $photoPath = createWatermarkedImage($mainPath, $avatarPath, (string)($case['title'] ?? ''), $rub, $uan, $category);
    $caption = "💰 Цена работы: {$rub}₽ | {$uan}₴\n\n💬 Оценить данную работу можно в комментариях.\n\n🚀 Заказать дизайн можно тут - <a href=\"" . htmlspecialchars(PUBLIC_SITE_URL, ENT_QUOTES, 'UTF-8') . "\">Kostlim Design</a>";
    $result = sendTelegramRequest('sendPhoto', ['chat_id' => PORTFOLIO_CHANNEL_CHAT, 'caption' => $caption, 'parse_mode' => 'HTML'], ['photo' => new CURLFile($photoPath)]);
    if ($photoPath !== $mainPath && is_file($photoPath)) unlink($photoPath);
    if ($downloaded && is_file($mainPath)) unlink($mainPath);
    if ($avatarDownloaded && $avatarPath !== '' && is_file($avatarPath)) unlink($avatarPath);
    return (bool)($result['ok'] ?? false);
}

function uploadToImgBB(string $tmpPath, string $name = 'image'): string
{
    if (!is_file($tmpPath)) { error_log("ImgBB: file not found ($tmpPath)"); return ''; }
    $keys = array_filter([getenv('IMGBB_API_KEY') ?: '', getenv('IMGBB_API_KEY2') ?: '', getenv('IMGBB_API_KEY3') ?: '']);
    if (empty($keys)) { error_log("ImgBB: no API keys set"); return ''; }
    $b64 = base64_encode(file_get_contents($tmpPath));
    foreach ($keys as $index => $apiKey) {
        $ch = curl_init('https://api.imgbb.com/1/upload');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POSTFIELDS => ['key' => $apiKey, 'image' => $b64, 'name' => $name]]);
        $res = curl_exec($ch); $cerr = curl_error($ch); curl_close($ch);
        if ($res === false || $res === '') { error_log("ImgBB key#".($index+1)." curl error: $cerr"); continue; }
        $data = json_decode($res, true); $url = $data['data']['url'] ?? '';
        if ($url !== '') { error_log("ImgBB key#".($index+1)." OK => $url"); return $url; }
        error_log("ImgBB key#".($index+1)." failed: " . ($data['error']['message'] ?? ''));
    }
    error_log("ImgBB: all keys failed for $name");
    return '';
}

function uploadImage(string $field, string $prefix, string $uploadDir): string
{
    global $message;
    $err = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE || empty($_FILES[$field]['name'])) return '';
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { $message = '❌ Файл слишком большой.'; return ''; }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES[$field]['tmp_name'])) { error_log("UPLOAD[$field]: err=$err"); return ''; }
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) { error_log("UPLOAD[$field]: bad ext=$ext"); return ''; }
    $tmp = $_FILES[$field]['tmp_name'];
    $url = uploadToImgBB($tmp, $prefix . '_' . time());
    if ($url !== '') { error_log("UPLOAD[$field]: imgbb OK => $url"); return $url; }
    error_log("UPLOAD[$field]: imgbb failed, falling back to local");
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
    if (is_writable($uploadDir)) {
        $filename = $prefix . '_' . time() . '_' . uniqid() . '.' . $ext;
        $dest = $uploadDir . $filename;
        if (move_uploaded_file($tmp, $dest)) { error_log("UPLOAD[$field]: local fallback OK => $filename"); return $filename; }
    }
    error_log("UPLOAD[$field]: both methods failed");
    $message = '❌ Не удалось загрузить изображение. Проверь IMGBB_API_KEY.';
    return '';
}

function uploadNestedImage(string $field, int $id, string $prefix, string $uploadDir): string
{
    if (empty($_FILES[$field]['name'][$id]) || empty($_FILES[$field]['tmp_name'][$id])) return '';
    $err = $_FILES[$field]['error'][$id] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) { error_log("UPLOAD_NESTED[$field][$id]: err=$err"); return ''; }
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'][$id], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return '';
    $tmp = $_FILES[$field]['tmp_name'][$id];
    $url = uploadToImgBB($tmp, $prefix . '_' . time() . '_' . $id);
    if ($url !== '') return $url;
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
    if (is_writable($uploadDir)) {
        $filename = $prefix . '_' . time() . '_' . $id . '_' . uniqid() . '.' . $ext;
        $dest = $uploadDir . $filename;
        if (move_uploaded_file($tmp, $dest)) return $filename;
    }
    return '';
}

function money(int|float $value): string { return number_format((float)$value, 0, '.', ' '); }

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
        $message = '✅ Аватарка сайта обновлена.';
    } else {
        if ($message === '') $message = '❌ Не удалось загрузить аватарку.';
    }
}

// ===================== PORTFOLIO (обычная форма, не AJAX) =====================
if (isset($_POST['add_portfolio']) && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $title        = trim($_POST['title'] ?? '');
    $category_key = $_POST['category_key'] ?? 'preview';
    $price_rub    = !empty($_POST['price_rub']) ? (int)$_POST['price_rub'] : 0;
    $price_uan    = !empty($_POST['price_uan']) ? (int)$_POST['price_uan'] : 0;
    $publish_tg   = !empty($_POST['publish_tg']);
    $filename_main   = uploadImage('image', 'main', $uploadDir);
    $filename_avatar = uploadImage('avatar_image', 'ava', $uploadDir);
    if ($title === '') { $message = '❌ Укажи название проекта.'; }
    elseif ($filename_main === '') { if ($message === '') $message = '❌ Загрузи главное изображение.'; }
    else {
        $stmt = $pdo->prepare("INSERT INTO portfolio (title, category_key, price_rub, price_uan, image, avatar_image) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $category_key, $price_rub, $price_uan, $filename_main, $filename_avatar]);
        $postedToChannel = false;
        if ($publish_tg) {
            $postedToChannel = publishPortfolioToChannel($pdo, $uploadDir, ['title' => $title, 'category_key' => $category_key, 'price_rub' => $price_rub, 'price_uan' => $price_uan, 'image' => $filename_main, 'avatar_image' => $filename_avatar]);
        }
        $message = '✅ Портфолио сохранено!';
        if ($publish_tg) { $message .= $postedToChannel ? ' Пост в Telegram-канал отправлен.' : ' Telegram-канал: ' . ($telegramLastError ?: 'проверь настройки.'); }
        else { $message .= ' Без публикации в Telegram.'; }
    }
}

// ===================== APPEALS =====================
if (isset($_POST['reply_appeal'])) {
    $appealId = (int)($_POST['appeal_id'] ?? 0);
    $reply    = trim($_POST['reply_text'] ?? '');
    if ($appealId > 0 && $reply !== '') {
        // Сохраняем сообщение в потоке
        try {
            $mstmt = $pdo->prepare("INSERT INTO appeals_messages (appeal_id, author, message, created_at) VALUES (?, 'admin', ?, NOW())");
            $mstmt->execute([$appealId, $reply]);
            $pdo->prepare("UPDATE appeals SET status = 'answered', replied_at = NOW() WHERE id = ?")->execute([$appealId]);
        } catch (Throwable $e) { /* ignore */ }

        $ap = $pdo->prepare("SELECT a.*, COALESCE(NULLIF(a.telegram, ''), NULLIF(o.telegram, ''), '') AS client_telegram FROM appeals a LEFT JOIN orders o ON o.id = a.order_id WHERE a.id = ? LIMIT 1");
        $ap->execute([$appealId]);
        $ap = $ap->fetch(PDO::FETCH_ASSOC);

        if ($ap && !empty($ap['client_telegram']) && TELEGRAM_BOT_TOKEN !== '') {
            $link = PUBLIC_SITE_URL . 'includes/profile.php?order=' . (int)$ap['order_id'];
            $text = "✅ По вашему обращению <b>«" . htmlspecialchars($ap['subject']) . "»</b> по заказу <b>#" . (int)$ap['order_id'] . "</b> пришел ответ!\n\n" .
                    "💬 <i>" . htmlspecialchars(mb_substr($reply, 0, 200)) . (mb_strlen($reply) > 200 ? '...' : '') . "</i>\n\n" .
                    "🔗 <a href=\"" . $link . "\">Посмотреть в профиле</a>";
            $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_POSTFIELDS     => ['chat_id' => $ap['client_telegram'], 'text' => $text, 'parse_mode' => 'HTML'],
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
        $message = '✅ Ответ на обращение #' . $appealId . ' отправлен.';
    }
}

if (isset($_GET['delete_portfolio_id'])) {
    $del_id    = (int)$_GET['delete_portfolio_id'];
    $img_stmt  = $pdo->prepare("SELECT image, avatar_image FROM portfolio WHERE id = ?");
    $img_stmt->execute([$del_id]);
    $work_files = $img_stmt->fetch(PDO::FETCH_ASSOC);
    foreach (['image', 'avatar_image'] as $field) {
        $val = $work_files[$field] ?? '';
        if ($val === '' || str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) continue;
        $path = $uploadDir . basename($val);
        if (file_exists($path)) unlink($path);
    }
    $pdo->prepare("DELETE FROM portfolio WHERE id = ?")->execute([$del_id]);
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
        if ($newMain   !== '') $pdo->prepare("UPDATE portfolio SET image = ? WHERE id = ?")->execute([$newMain, $caseId]);
        if ($newAvatar !== '') $pdo->prepare("UPDATE portfolio SET avatar_image = ? WHERE id = ?")->execute([$newAvatar, $caseId]);
        $message = '✅ Медиа кейса обновлены.';
    }
}

// ===================== PRICES =====================
if (isset($_POST['save_all_prices'])) {
    foreach (($_POST['prices'] ?? []) as $id => $data) {
        $id       = (int)$id;
        $newImage = uploadNestedImage('price_images', $id, 'price', $uploadDir);
        if ($newImage !== '') {
            $stmt = $pdo->prepare("UPDATE prices SET title=?,description=?,features=?,price_uan=?,price_rub=?,image=? WHERE id=?");
            $stmt->execute([$data['title']??'', $data['description']??'', $data['features']??'', $data['price_uan']??0, $data['price_rub']??0, $newImage, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE prices SET title=?,description=?,features=?,price_uan=?,price_rub=? WHERE id=?");
            $stmt->execute([$data['title']??'', $data['description']??'', $data['features']??'', $data['price_uan']??0, $data['price_rub']??0, $id]);
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
    if ($category_key === '') $category_key = 'service_' . time();
    $category_key = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $category_key));
    if ($title === '') { $message = '❌ Укажи название услуги.'; }
    else {
        $stmt = $pdo->prepare("INSERT INTO prices (category_key,title,description,price_rub,price_uan,features,image) VALUES (?,?,?,?,?,?,?)");
        try {
            $stmt->execute([$category_key, $title, $description, $price_rub, $price_uan, $features, $image]);
            $message = '✅ Новая услуга добавлена в прайс.';
        } catch (PDOException $e) { $message = '❌ Такой ключ услуги уже существует.'; }
    }
}

if (isset($_GET['delete_price_id'])) {
    $pdo->prepare("DELETE FROM prices WHERE id = ?")->execute([(int)$_GET['delete_price_id']]);
    $message = '🗑️ Услуга удалена из прайса.';
}

// ===================== CATEGORIES =====================
if (isset($_POST['add_portfolio_category'])) {
    $catTitle    = trim($_POST['cat_title'] ?? '');
    $catKey      = trim($_POST['cat_key'] ?? '');
    $catWidth    = !empty($_POST['cat_width']) ? (int)$_POST['cat_width'] : 1920;
    $catHeight   = !empty($_POST['cat_height']) ? (int)$_POST['cat_height'] : 1080;
    $catIsDesign = !empty($_POST['cat_is_design']) ? 1 : 0;
    if ($catKey === '') $catKey = 'cat_' . time();
    $catKey = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $catKey));
    if ($catTitle === '') { $message = '❌ Укажи название категории.'; }
    else {
        try {
            $pdo->prepare("INSERT INTO portfolio_categories (category_key,title,width_px,height_px,is_design,sort_order) VALUES (?,?,?,?,?,100)")
                ->execute([$catKey, $catTitle, $catWidth, $catHeight, $catIsDesign]);
            $message = '✅ Категория добавлена.';
        } catch (PDOException $e) { $message = '❌ Такая категория уже существует.'; }
    }
}

if (isset($_GET['delete_portfolio_category_id'])) {
    $pdo->prepare("DELETE FROM portfolio_categories WHERE id = ?")->execute([(int)$_GET['delete_portfolio_category_id']]);
    $message = '🗑️ Категория удалена.';
}

// ===================== FETCH DATA =====================
$services   = $pdo->query("SELECT * FROM prices ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT * FROM portfolio_categories ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$categoryMap = [];
foreach ($categories as $category) { $categoryMap[$category['category_key']] = $category; }
$works = $pdo->query("SELECT * FROM portfolio ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$orderStats = $pdo->query("
    SELECT COUNT(*) AS total,
        COUNT(*) FILTER (WHERE status='pending') AS pending,
        COUNT(*) FILTER (WHERE status='in_progress') AS in_progress,
        COUNT(*) FILTER (WHERE status='ready') AS ready,
        COUNT(*) FILTER (WHERE status='declined') AS declined
    FROM orders
")->fetch(PDO::FETCH_ASSOC) ?: [];

$revenue = $pdo->query("
    SELECT COALESCE(SUM(p.price_rub),0) AS rub, COALESCE(SUM(p.price_uan),0) AS uan
    FROM orders o LEFT JOIN prices p ON p.category_key=o.service_key WHERE o.status='ready'
")->fetch(PDO::FETCH_ASSOC) ?: ['rub'=>0,'uan'=>0];

$activeValue = $pdo->query("
    SELECT COALESCE(SUM(p.price_rub),0) AS rub, COALESCE(SUM(p.price_uan),0) AS uan
    FROM orders o LEFT JOIN prices p ON p.category_key=o.service_key WHERE o.status IN ('pending','in_progress')
")->fetch(PDO::FETCH_ASSOC) ?: ['rub'=>0,'uan'=>0];

$recentOrders = $pdo->query("
    SELECT o.id,o.username,o.telegram,o.service_key,o.status,o.created_at,p.title,p.price_rub,p.price_uan
    FROM orders o LEFT JOIN prices p ON p.category_key=o.service_key ORDER BY o.id DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$categoryLabels = [];
foreach ($categories as $category) {
    $size = ((int)$category['width_px']>0 && (int)$category['height_px']>0) ? " ({$category['width_px']}x{$category['height_px']})" : '';
    $categoryLabels[$category['category_key']] = $category['title'] . $size;
}

$statusLabels = ['pending'=>'Ожидает','in_progress'=>'В процессе','ready'=>'Готов','declined'=>'Отклонен'];

// ── Загрузка обращений ──────────────────────────────────────────
$appeals = [];
$openAppealsCount = 0;
try {
    $appeals = $pdo->query("
        SELECT a.id, a.order_id, a.username, a.subject, a.message, a.reply, a.status, a.created_at, a.replied_at, a.telegram
        FROM appeals a
        ORDER BY a.status ASC, a.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $openAppealsCount = count(array_filter($appeals, fn($a) => $a['status'] === 'open'));
} catch (Throwable $e) { /* таблица ещё не создана */ }

// ── Просмотр заказа в админке (детальная форма) ─────────────────
$viewOrder = null;
if (isset($_GET['view_order'])) {
    $vid = (int)$_GET['view_order'];
    if ($vid > 0) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([$vid]);
        $viewOrder = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($viewOrder) {
            try {
                $ast = $pdo->prepare("SELECT * FROM appeals WHERE order_id = ? ORDER BY id ASC");
                $ast->execute([$vid]);
                $orderAppeals = $ast->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) { $orderAppeals = []; }
        } else {
            $orderAppeals = [];
        }
    }
}

// ── Отправить сообщение клиенту по заказу из админки ────────────
if (isset($_POST['send_order_message'])) {
    $oid     = (int)($_POST['order_id'] ?? 0);
    $subj    = trim($_POST['msg_subject'] ?? 'Сообщение от администрации');
    $body    = trim($_POST['msg_text'] ?? '');
    if ($oid > 0 && $body !== '') {
        // получаем данные заказа
        $ost = $pdo->prepare("SELECT id, username, telegram, client_chat_id FROM orders WHERE id = ? LIMIT 1");
        $ost->execute([$oid]);
        $orow = $ost->fetch(PDO::FETCH_ASSOC);

        $sendTo = '';
        if ($orow) {
            $sendTo = trim((string)($orow['client_chat_id'] ?? '')) ?: trim((string)($orow['telegram'] ?? ''));
        }

        // создаём поток обращений (appeal) и добавляем сообщение от администратора
        try {
            $adminName = 'Администратор';
            $ins = $pdo->prepare("INSERT INTO appeals (order_id, username, telegram, subject, status, created_at) VALUES (?, ?, ?, ?, 'open', NOW()) RETURNING id");
            $ins->execute([$oid, $adminName, $sendTo, $subj]);
            $aid = (int)$ins->fetchColumn();
            if ($aid > 0) {
                $m = $pdo->prepare("INSERT INTO appeals_messages (appeal_id, author, message, created_at) VALUES (?, 'admin', ?, NOW())");
                $m->execute([$aid, $body]);
            }
        } catch (Throwable $e) { /* ignore */ }

        // уведомляем клиента через Telegram, если есть chat_id
        if ($sendTo !== '' && TELEGRAM_BOT_TOKEN !== '') {
            $text = "📨 <b>Сообщение по вашему заказу #{$oid}</b>\n\n" . htmlspecialchars($body);
            // используем sendTelegramRequest helper
            sendTelegramRequest('sendMessage', ['chat_id' => $sendTo, 'text' => $text, 'parse_mode' => 'HTML']);
        }

        header('Location: ' . $_SERVER['PHP_SELF'] . '?view_order=' . $oid . '&msg=sent');
        exit;
    }
}

$currentAvatarRow  = $pdo->query("SELECT avatar FROM users LIMIT 1")->fetch();
$currentAvatarFile = $currentAvatarRow['avatar'] ?? '';
$imgbbKeys         = array_filter([getenv('IMGBB_API_KEY')?: '', getenv('IMGBB_API_KEY2')?: '', getenv('IMGBB_API_KEY3')?: '']);
$imgbbKeyCount     = count($imgbbKeys);
$imgbbKeySet       = $imgbbKeyCount > 0;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kostlim Admin</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        * { scrollbar-width: thin; scrollbar-color: #f97316 #111116; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #111116; border-radius: 99px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(180deg,#fb923c,#f97316); border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: #fb923c; }
        body { background: #08080b; color: #fff; font-family: Montserrat, Arial, sans-serif; }
        .admin-shell { max-width: 1480px; margin: 0 auto; padding: 24px; }
        .admin-top { display: flex; justify-content: space-between; align-items: center; gap: 18px; margin-bottom: 22px; }
        .admin-title h1 { font-size: 28px; line-height: 1.1; margin: 0 0 6px; }
        .admin-title p { color: #8a8a96; margin: 0; }
        .admin-meta { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
        .admin-meta span { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #242432; background: #111116; color: #d8d8e8; border-radius: 999px; padding: 7px 11px; font-size: 12px; font-weight: 800; }
        .admin-meta span.ok   { border-color: rgba(34,197,94,.5);  background: rgba(34,197,94,.1);  color: #86efac; }
        .admin-meta span.warn { border-color: rgba(249,115,22,.5); background: rgba(249,115,22,.1); color: #fdba74; }
        .admin-link-top { color: #fff; text-decoration: none; border: 1px solid #242432; border-radius: 10px; padding: 11px 18px; background: #111116; font-size: 13px; font-weight: 700; transition: .2s; }
        .admin-link-top:hover { border-color: #f97316; background: rgba(249,115,22,.1); }
        .notice { border: 1px solid rgba(249,115,22,.45); background: rgba(249,115,22,.10); border-radius: 12px; padding: 14px 16px; margin-bottom: 18px; font-weight: 700; }
        .notice.success { border-color: rgba(34,197,94,.45); background: rgba(34,197,94,.10); color: #86efac; }
        .notice.error   { border-color: rgba(239,68,68,.45);  background: rgba(239,68,68,.10);  color: #fca5a5; }
        .admin-board { display: grid; grid-template-columns: 230px minmax(0,1fr); gap: 18px; align-items: start; }
        .admin-tabs { position: sticky; top: 18px; display: grid; gap: 9px; background: #111116; border: 1px solid #20202c; border-radius: 14px; padding: 12px; }
        .admin-tab { display: flex; align-items: center; gap: 10px; width: 100%; border: 1px solid transparent; border-radius: 10px; padding: 12px 13px; background: transparent; color: #d8d8e8; font-weight: 900; text-align: left; cursor: pointer; font-family: Montserrat,sans-serif; font-size: 13px; transition: .2s; }
        .admin-tab:hover { background: #171720; border-color: #2a2a38; }
        .admin-tab.active { color: #fff; background: linear-gradient(135deg,#f97316,#ea580c); box-shadow: 0 12px 28px rgba(249,115,22,.28); border-color: transparent; }
        .admin-content { min-width: 0; }
        .stats-grid { display: grid; grid-template-columns: repeat(6, minmax(150px,1fr)); gap: 12px; margin-bottom: 18px; }
        .stat-card { background: #111116; border: 1px solid #20202c; border-radius: 12px; padding: 16px; min-height: 92px; }
        .stat-card span { color: #8a8a96; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .stat-card strong { display: block; font-size: 25px; margin-top: 10px; }
        .stat-card.accent { border-color: rgba(249,115,22,.6); background: linear-gradient(145deg,rgba(249,115,22,.18),#111116); }
        .admin-layout { display: grid; grid-template-columns: 380px minmax(0,1fr); gap: 18px; align-items: start; }
        .admin-layout.single-column { grid-template-columns: 1fr; }
        .panel { background: #111116; border: 1px solid #20202c; border-radius: 14px; padding: 18px; margin-bottom: 18px; }
        .panel h2 { font-size: 16px; margin-bottom: 14px; }
        .avatar-preview-wrap { display: flex; align-items: center; gap: 18px; margin-bottom: 16px; }
        .avatar-preview-img { width: 72px; height: 72px; border-radius: 50%; object-fit: cover; border: 3px solid #f97316; background: #0b0b10; flex-shrink: 0; }
        .avatar-preview-info { color: #8a8a96; font-size: 12px; line-height: 1.6; }
        .avatar-preview-info strong { display: block; color: #d8d8e8; margin-bottom: 2px; font-size: 13px; }
        label { display: block; color: #d9d9e4; font-size: 12px; font-weight: 800; margin: 12px 0 6px; text-transform: uppercase; letter-spacing: .5px; }
        input:not([type="file"]):not([type="checkbox"]), select, textarea { width: 100%; background: #171720; color: #fff; border: 1px solid #2a2a38; border-radius: 9px; padding: 11px 12px; outline: none; font-family: Montserrat,sans-serif; font-size: 13px; transition: .2s; }
        input:not([type="file"]):not([type="checkbox"]):focus, select:focus, textarea:focus { border-color: #f97316; box-shadow: 0 0 0 3px rgba(249,115,22,.18), 0 0 14px rgba(249,115,22,.22); }
        textarea { min-height: 64px; resize: vertical; }
        select { cursor: pointer; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238a8a96' stroke-width='2.5'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; }
        input[type="file"].styled-hidden { display: none; }
        .file-upload-wrap { display: flex; align-items: center; gap: 10px; width: 100%; }
        .file-upload-btn { display: inline-flex; align-items: center; gap: 7px; cursor: pointer; background: #1e1e2a; border: 1px solid #2a2a38; border-radius: 9px; padding: 9px 16px; color: #d8d8e8; font-size: 12px; font-weight: 700; white-space: nowrap; transition: .2s; font-family: Montserrat,sans-serif; flex-shrink: 0; user-select: none; }
        .file-upload-btn:hover { background: rgba(249,115,22,.15); border-color: #f97316; color: #fff; }
        .file-upload-btn svg { flex-shrink: 0; }
        .file-upload-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #8a8a96; font-size: 12px; font-style: italic; }
        .file-upload-name.has-file { color: #86efac; font-style: normal; font-weight: 700; }
        .mini-file-wrap { display: flex; flex-direction: column; gap: 6px; }
        .mini-file-btn { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; background: #1a1a24; border: 1px solid #2a2a38; border-radius: 7px; padding: 7px 12px; color: #c8c8d8; font-size: 11px; font-weight: 700; white-space: nowrap; transition: .2s; font-family: Montserrat,sans-serif; user-select: none; }
        .mini-file-btn:hover { background: rgba(249,115,22,.15); border-color: #f97316; color: #fff; }
        .mini-file-name { font-size: 10px; color: #8a8a96; font-style: italic; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px; }
        .mini-file-name.has-file { color: #86efac; font-style: normal; }
        .btn-panel { width: 100%; margin-top: 14px; border: none; border-radius: 10px; padding: 13px 16px; background: linear-gradient(135deg,#fb923c,#f97316); color: #fff; font-weight: 900; cursor: pointer; text-transform: uppercase; font-family: Montserrat,sans-serif; letter-spacing: 1px; font-size: 13px; box-shadow: 0 8px 24px rgba(249,115,22,.30); transition: .2s; position: relative; }
        .btn-panel:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 0 28px rgba(249,115,22,.55), 0 12px 30px rgba(249,115,22,.25); }
        .btn-panel:disabled { opacity: .6; cursor: not-allowed; transform: none; }
        .btn-panel .btn-spinner { display: none; }
        .btn-panel.loading .btn-text { display: none; }
        .btn-panel.loading .btn-spinner { display: inline-flex; align-items: center; gap: 8px; }
        .admin-table-wrap { overflow-x: auto; border: 1px solid #20202c; border-radius: 12px; }
        table { width: 100%; border-collapse: collapse; min-width: 760px; }
        th, td { padding: 12px; border-bottom: 1px solid #20202c; text-align: left; vertical-align: middle; }
        th { color: #8a8a96; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
        td { color: #efeff7; font-size: 13px; }
        tr:last-child td { border-bottom: 0; }
        tr:hover td { background: rgba(255,255,255,.015); }
        .thumb-pair { display: flex; align-items: center; gap: 8px; }
        .case-thumb-wrap { position: relative; display: inline-block; user-select: none; }
        .case-thumb-wrap::after { content: ''; position: absolute; inset: 0; z-index: 2; cursor: default; }
        .case-thumb { width: 98px; height: 55px; object-fit: cover; border-radius: 8px; background: #0b0b10; pointer-events: none; user-select: none; -webkit-user-drag: none; display: block; }
        .case-ava { width: 38px; height: 38px; object-fit: cover; border-radius: 50%; border: 2px solid #f97316; margin-left: -22px; background: #111116; pointer-events: none; user-select: none; -webkit-user-drag: none; }
        .price-thumb { width: 70px; height: 44px; object-fit: cover; border-radius: 8px; background: #0b0b10; border: 1px solid #272735; pointer-events: none; }
        .status { display: inline-flex; border-radius: 999px; padding: 6px 10px; background: #191924; color: #d8d8e8; font-weight: 800; font-size: 12px; }
        .delete-link { color: #ff6b76; text-decoration: none; font-weight: 800; font-size: 12px; padding: 6px 12px; border: 1px solid rgba(255,107,118,.25); border-radius: 7px; transition: .2s; display: inline-block; }
        .delete-link:hover { background: rgba(255,107,118,.12); border-color: #ff6b76; }
        .mini-media-form { display: grid; gap: 7px; min-width: 190px; }
        .mini-media-form button { border: 0; border-radius: 8px; padding: 8px 12px; background: linear-gradient(135deg,#fb923c,#f97316); color: #fff; font-weight: 800; cursor: pointer; font-family: Montserrat,sans-serif; font-size: 11px; letter-spacing: .5px; text-transform: uppercase; transition: .2s; }
        .mini-media-form button:hover { opacity: .85; }
        .two-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .tg-checkbox { display:flex; gap:10px; align-items:center; color:#d8d8e8; font-size:13px; text-transform:none; letter-spacing:0; margin:4px 0 18px; }
        .tg-checkbox input { width:auto; margin:0; accent-color:#f97316; }
        .avatar-hint { color: #8a8a96; font-size: 12px; line-height: 1.5; margin-top: 8px; background: rgba(255,255,255,.03); border-radius: 7px; padding: 8px 10px; border-left: 2px solid #f97316; }
        .tab-hidden { display: none !important; }
        #admin-toast { position: fixed; bottom: 28px; right: 28px; z-index: 9999; min-width: 280px; max-width: 420px; border-radius: 14px; padding: 16px 20px; font-weight: 700; font-size: 14px; font-family: Montserrat,sans-serif; box-shadow: 0 8px 32px rgba(0,0,0,.5); opacity: 0; transform: translateY(20px); transition: opacity .3s, transform .3s; pointer-events: none; }
        #admin-toast.show { opacity: 1; transform: translateY(0); pointer-events: auto; }
        #admin-toast.success { background: #0f2b1a; border: 1px solid rgba(34,197,94,.5); color: #86efac; }
        #admin-toast.error   { background: #2b0f0f; border: 1px solid rgba(239,68,68,.5);  color: #fca5a5; }
        #admin-toast.loading { background: #1a1a24; border: 1px solid rgba(249,115,22,.5); color: #fdba74; }
        @media (max-width: 1100px) { .admin-board { grid-template-columns: 1fr; } .admin-tabs { position: static; grid-template-columns: repeat(2,1fr); } .stats-grid { grid-template-columns: repeat(2,1fr); } .admin-layout { grid-template-columns: 1fr; } }
        @media (max-width: 640px) { .admin-shell { padding: 16px; } .admin-top { align-items: flex-start; flex-direction: column; } .admin-tabs { grid-template-columns: 1fr; } .stats-grid { grid-template-columns: 1fr; } .two-cols { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<div id="admin-toast"></div>

<main class="admin-shell">
    <div class="admin-top">
        <div class="admin-title">
            <h1>⚙️ Админ-панель Kostlim Design</h1>
            <p>Портфолио, прайс, заказы и деньги в одном месте.</p>
            <div class="admin-meta">
                <span>Kostlim</span>
                <span><?= htmlspecialchars(ADMIN_EMAIL) ?></span>
                <span>TG ID: <?= htmlspecialchars(ADMIN_TELEGRAM_ID) ?></span>
                <?php if ($imgbbKeySet): ?>
                    <span class="ok">✅ ImgBB: <?= $imgbbKeyCount ?> <?= $imgbbKeyCount === 1 ? 'ключ' : ($imgbbKeyCount < 5 ? 'ключа' : 'ключей') ?></span>
                <?php else: ?>
                    <span class="warn">⚠️ IMGBB_API_KEY не задан!</span>
                <?php endif; ?>
            </div>
        </div>
        <a href="../index.php" class="admin-link-top">← На сайт</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice <?= str_starts_with($message,'✅')||str_starts_with($message,'💾') ? 'success' : (str_starts_with($message,'❌') ? 'error' : '') ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="admin-board">
        <nav class="admin-tabs" aria-label="Разделы">
            <button type="button" class="admin-tab active" data-tab="overview"   onclick="activateAdminTab('overview')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg> Обзор</button>
            <button type="button" class="admin-tab"        data-tab="portfolio"  onclick="activateAdminTab('portfolio')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="15" rx="2"/><path d="M16 2l-4 5-4-5"/></svg> Портфолио</button>
            <button type="button" class="admin-tab"        data-tab="price"      onclick="activateAdminTab('price')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> Прайс</button>
            <button type="button" class="admin-tab"        data-tab="orders"     onclick="activateAdminTab('orders')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg> Заказы</button>
            <button type="button" class="admin-tab"        data-tab="categories" onclick="activateAdminTab('categories')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg> Категории</button>
            <button type="button" class="admin-tab"        data-tab="appeals"    onclick="activateAdminTab('appeals')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                Обращения<?php if (!empty($openAppealsCount)): ?> <span style="background:#f97316;color:#fff;border-radius:999px;padding:1px 7px;font-size:10px;margin-left:4px;"><?= $openAppealsCount ?></span><?php endif; ?>
            </button>
            <button type="button" class="admin-tab"        data-tab="avatar"     onclick="activateAdminTab('avatar')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Аватарка</button>
        </nav>

        <div class="admin-content">
            <section class="stats-grid">
                <div class="stat-card accent"><span>Заработано</span><strong><?= money($revenue['rub']??0) ?> ₽</strong><span><?= money($revenue['uan']??0) ?> ₴</span></div>
                <div class="stat-card"><span>В активе</span><strong><?= money($activeValue['rub']??0) ?> ₽</strong><span><?= money($activeValue['uan']??0) ?> ₴</span></div>
                <div class="stat-card"><span>Всего заказов</span><strong><?= (int)($orderStats['total']??0) ?></strong></div>
                <div class="stat-card"><span>Ожидают</span><strong><?= (int)($orderStats['pending']??0) ?></strong></div>
                <div class="stat-card"><span>В процессе</span><strong><?= (int)($orderStats['in_progress']??0) ?></strong></div>
                <div class="stat-card"><span>Готово</span><strong><?= (int)($orderStats['ready']??0) ?></strong></div>
            </section>

            <div class="admin-layout">
                <aside>
                    <!-- Добавить в портфолио -->
                    <section class="panel" data-panel="portfolio-add">
                        <h2>📁 Добавить в портфолио</h2>
                        <form id="portfolio-form" enctype="multipart/form-data">
                            <label>Название проекта</label>
                            <input type="text" name="title" required placeholder="Например: сет Naruto">
                            <label>Категория графики</label>
                            <select name="category_key" id="category_select" onchange="toggleAvatarField()">
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= htmlspecialchars($category['category_key']) ?>">
                                        <?= htmlspecialchars($category['title']) ?>
                                        <?php if ((int)$category['width_px']>0 && (int)$category['height_px']>0): ?> (<?= (int)$category['width_px'] ?>x<?= (int)$category['height_px'] ?>)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="two-cols">
                                <div><label>Цена в рублях</label><input type="number" name="price_rub" value="0" min="0"></div>
                                <div><label>Цена в гривнах</label><input type="number" name="price_uan" value="0" min="0"></div>
                            </div>
                            <label>Главное изображение / шапка</label>
                            <input type="file" name="image" accept="image/*" required>
                            <label class="tg-checkbox"><input type="checkbox" name="publish_tg" value="1" checked> Публиковать в Telegram-канал</label>
                            <div id="avatar_upload_block" style="display:none;">
                                <label>Аватарка к оформлению</label>
                                <input type="file" name="avatar_image" accept="image/*">
                                <div class="avatar-hint">Для категории "Оформление" шапка широкая, аватарка — круглое превью.</div>
                            </div>
                            <button type="submit" class="btn-panel" id="portfolio-submit-btn">
                                <span class="btn-text">Загрузить в кейсы</span>
                                <span class="btn-spinner"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin 1s linear infinite"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Загружаем на ImgBB...</span>
                            </button>
                        </form>
                        <style>@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}</style>
                    </section>

                    <!-- Категории -->
                    <section class="panel" data-panel="categories">
                        <h2>🧩 Создать категорию</h2>
                        <form action="" method="POST">
                            <label>Название категории</label>
                            <input type="text" name="cat_title" required placeholder="Например: Пост VK">
                            <label>Ключ категории</label>
                            <input type="text" name="cat_key" placeholder="vk_post">
                            <div class="avatar-hint">Латиница без пробелов.</div>
                            <div class="two-cols">
                                <div><label>Ширина рамки, px</label><input type="number" name="cat_width" min="0" placeholder="1920"></div>
                                <div><label>Высота рамки, px</label><input type="number" name="cat_height" min="0" placeholder="1080"></div>
                            </div>
                            <div class="avatar-hint">Размер задает пропорцию рамки работы внутри Telegram-постера.</div>
                            <label style="display:flex;gap:8px;align-items:center;margin-top:14px;"><input type="checkbox" name="cat_is_design" value="1" style="width:auto;margin:0;"> Это оформление с аватаркой</label>
                            <button type="submit" name="add_portfolio_category" class="btn-panel">Добавить категорию</button>
                        </form>
                        <div style="margin-top:14px;display:grid;gap:8px;">
                            <?php foreach ($categories as $category): ?>
                                <div style="display:flex;justify-content:space-between;gap:10px;color:#d8d8e8;font-size:12px;border-top:1px solid #242432;padding-top:8px;">
                                    <span><?= htmlspecialchars($category['title']) ?><?php if ((int)$category['width_px']>0 && (int)$category['height_px']>0): ?> · <?= (int)$category['width_px'] ?>x<?= (int)$category['height_px'] ?><?php endif; ?></span>
                                    <a class="delete-link" href="?delete_portfolio_category_id=<?= (int)$category['id'] ?>" onclick="return confirm('Удалить категорию?')">Удалить</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <!-- Последние заказы -->
                    <section class="panel" data-panel="orders">
                        <h2>🧾 Последние заказы</h2>
                        <div class="admin-table-wrap">
                            <table style="min-width:520px;">
                                <thead><tr><th>ID</th><th>Клиент</th><th>Статус</th><th>Сумма</th><th>Действие</th></tr></thead>
                                <tbody>
                                    <?php foreach ($recentOrders as $order): ?>
                                        <tr>
                                            <td>#<?= (int)$order['id'] ?></td>
                                            <td><?= htmlspecialchars($order['username']??'Клиент') ?><br><span style="color:#8a8a96;"><?= htmlspecialchars($order['telegram']??'') ?></span></td>
                                            <td><span class="status"><?= htmlspecialchars($statusLabels[$order['status']]??$order['status']) ?></span></td>
                                            <td><?= (int)($order['price_rub']??0) ?> ₽<br><span style="color:#8a8a96;"><?= (int)($order['price_uan']??0) ?> ₴</span></td>
                                            <td><a class="btn-panel" href="<?= $_SERVER['PHP_SELF'] . '?view_order=' . (int)$order['id'] ?>">Открыть</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- Добавить услугу в прайс -->
                    <section class="panel" data-panel="price-add">
                        <h2>➕ Добавить услугу в прайс</h2>
                        <form action="" method="POST" enctype="multipart/form-data">
                            <label>Название услуги</label>
                            <input type="text" name="service_title" required placeholder="Например: Баннер для постов">
                            <label>Ключ услуги</label>
                            <input type="text" name="service_key" placeholder="post_banner">
                            <div class="avatar-hint">Латиницей, без пробелов.</div>
                            <div class="two-cols">
                                <div><label>Цена в рублях</label><input type="number" name="service_price_rub" value="0" min="0"></div>
                                <div><label>Цена в гривнах</label><input type="number" name="service_price_uan" value="0" min="0"></div>
                            </div>
                            <label>Описание</label>
                            <textarea name="service_description" placeholder="Коротко, что входит в услугу"></textarea>
                            <label>Фичи</label>
                            <input type="text" name="service_features" placeholder="Через | например: PSD-файл|2 правки|быстрая сдача">
                            <label>Обложка услуги</label>
                            <input type="file" name="service_image" accept="image/*">
                            <div class="avatar-hint">Рекомендуется 16:9.</div>
                            <button type="submit" name="add_price_service" class="btn-panel">Добавить услугу</button>
                        </form>
                    </section>

                    <!-- Аватарка сайта -->
                    <section class="panel" data-panel="avatar">
                        <h2>🖼️ Аватарка сайта</h2>
                        <div class="avatar-preview-wrap">
                            <?php $avatarSrc = imgSrc($currentAvatarFile, '../uploads/'); ?>
                            <img src="<?= htmlspecialchars($avatarSrc ?: 'https://i.imgur.com/w9NThbA.png') ?>" class="avatar-preview-img" alt="Аватарка" onerror="this.src='https://i.imgur.com/w9NThbA.png'">
                            <div class="avatar-preview-info">
                                <strong>Текущая аватарка</strong>
                                <?= htmlspecialchars($currentAvatarFile ?: 'не задана') ?><br>
                                Водяной знак на постах в Telegram.
                            </div>
                        </div>
                        <form action="" method="POST" enctype="multipart/form-data">
                            <label>Новая аватарка сайта</label>
                            <input type="file" name="site_avatar" accept="image/*" required>
                            <div class="avatar-hint">Форматы: jpg, png, webp, gif. Рекомендуется 512×512.</div>
                            <button type="submit" name="upload_site_avatar" class="btn-panel">Загрузить аватарку</button>
                        </form>
                    </section>
                </aside>

                <section>
                    <!-- Менеджер цен -->
                    <div class="panel" data-panel="price-manager">
                        <h2>💲 Менеджер цен и прайс-листа</h2>
                        <form action="" method="POST" enctype="multipart/form-data">
                            <div class="admin-table-wrap">
                                <table>
                                    <thead><tr><th>Обложка</th><th>Услуга</th><th>Описание и фичи</th><th>Цены</th><th></th></tr></thead>
                                    <tbody>
                                        <?php foreach ($services as $service): $id = (int)$service['id']; ?>
                                            <tr>
                                                <td>
                                                    <?php if (!empty($service['image'])): ?><div class="case-thumb-wrap"><img src="<?= htmlspecialchars(imgSrc($service['image']??'')) ?>" class="price-thumb" alt=""></div><?php else: ?><span style="color:#666674;font-size:11px;">Нет обложки</span><?php endif; ?>
                                                    <div style="margin-top:8px;"><input type="file" name="price_images[<?= $id ?>]" accept="image/*"></div>
                                                </td>
                                                <td>
                                                    <input type="text" name="prices[<?= $id ?>][title]" value="<?= htmlspecialchars($service['title']??'') ?>">
                                                    <div style="color:#8a8a96;margin-top:6px;">key: <?= htmlspecialchars($service['category_key']??'') ?></div>
                                                </td>
                                                <td>
                                                    <textarea name="prices[<?= $id ?>][description]"><?= htmlspecialchars($service['description']??'') ?></textarea>
                                                    <input type="text" name="prices[<?= $id ?>][features]" value="<?= htmlspecialchars($service['features']??'') ?>" placeholder="Фичи через |">
                                                </td>
                                                <td>
                                                    <input type="number" name="prices[<?= $id ?>][price_rub]" value="<?= htmlspecialchars($service['price_rub']??'0') ?>">
                                                    <input type="number" name="prices[<?= $id ?>][price_uan]" value="<?= htmlspecialchars($service['price_uan']??'0') ?>" style="margin-top:8px;">
                                                </td>
                                                <td><a class="delete-link" href="?delete_price_id=<?= $id ?>" onclick="return confirm('Удалить услугу?')">Удалить</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button type="submit" name="save_all_prices" class="btn-panel" style="background:#22c55e;">Сохранить весь прайс</button>
                        </form>
                    </div>

                    <!-- Управление кейсами -->
                    <div class="panel" data-panel="portfolio-list">
                        <h2>🎬 Управление кейсами</h2>
                        <div class="admin-table-wrap">
                            <table>
                                <thead><tr><th>Превью</th><th>Название</th><th>Категория</th><th>Цена</th><th>Действие</th></tr></thead>
                                <tbody>
                                    <?php foreach ($works as $work): ?>
                                        <?php $img = $work['image']??''; $ava = $work['avatar_image']??''; $cat = $work['category_key']??'preview'; ?>
                                        <tr>
                                            <td>
                                                <div class="thumb-pair">
                                                    <?php if ($img !== ''): ?><div class="case-thumb-wrap"><img src="<?= htmlspecialchars(imgSrc($img)) ?>" class="case-thumb" alt="" draggable="false"></div><?php endif; ?>
                                                    <?php if ($ava !== ''): ?><img src="<?= htmlspecialchars(imgSrc($ava)) ?>" class="case-ava" alt="" draggable="false"><?php endif; ?>
                                                </div>
                                            </td>
                                            <td><strong><?= htmlspecialchars($work['title']??'Без названия') ?></strong></td>
                                            <td><?= htmlspecialchars($categoryLabels[$cat]??$cat) ?></td>
                                            <td><?= (int)($work['price_rub']??0) ?> ₽ / <?= (int)($work['price_uan']??0) ?> ₴</td>
                                            <td>
                                                <form action="" method="POST" enctype="multipart/form-data" class="mini-media-form">
                                                    <input type="hidden" name="portfolio_id" value="<?= (int)$work['id'] ?>">
                                                    <input type="file" name="portfolio_image" accept="image/*" title="Заменить главное изображение">
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

            <!-- ════ ПАНЕЛЬ ОБРАЩЕНИЙ ════ -->
            <div id="appeals-panel" style="display:none;">
                <div class="panel" data-panel="appeals" style="max-width:960px;margin:0 auto;">
                    <h2>📩 Обращения клиентов
                        <?php if ($openAppealsCount > 0): ?>
                            <span style="background:#f97316;color:#fff;border-radius:999px;padding:2px 10px;font-size:12px;margin-left:8px;"><?= $openAppealsCount ?> открытых</span>
                        <?php endif; ?>
                    </h2>
                    <?php if (empty($appeals)): ?>
                        <p style="color:#8a8a96;">Обращений пока нет.</p>
                    <?php else: ?>
                    <div style="display:grid;gap:14px;">
                    <?php foreach ($appeals as $ap): ?>
                        <?php $isOpen = $ap['status'] === 'open'; ?>
                        <div style="border-radius:12px;padding:16px 18px;background:<?= $isOpen ? 'rgba(249,115,22,.06)' : '#111116' ?>;border:1px solid <?= $isOpen ? 'rgba(249,115,22,.35)' : '#20202c' ?>;">
                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
                                <span style="font-size:12px;color:#8a8a96;font-weight:800;">Обращение #<?= (int)$ap['id'] ?></span>
                                <span style="font-size:12px;color:#8a8a96;">→ Заказ #<?= (int)$ap['order_id'] ?></span>
                                <strong style="flex:1;font-size:14px;"><?= htmlspecialchars($ap['subject']) ?></strong>
                                <span style="border-radius:999px;padding:4px 10px;font-size:11px;font-weight:800;<?= $isOpen ? 'background:rgba(249,115,22,.2);color:#fdba74;' : 'background:rgba(34,197,94,.15);color:#86efac;' ?>">
                                    <?= $isOpen ? '⏳ Ожидает ответа' : '✅ Отвечено' ?>
                                </span>
                                <span style="color:#8a8a96;font-size:11px;font-weight:700;"><?= htmlspecialchars($ap['username']) ?></span>
                                <span style="color:#666674;font-size:11px;"><?= date('d.m.Y H:i', strtotime($ap['created_at'])) ?></span>
                            </div>
                            <?php
                                $mstmt = $pdo->prepare("SELECT author, message, created_at FROM appeals_messages WHERE appeal_id = ? ORDER BY id ASC");
                                $mstmt->execute([(int)$ap['id']]);
                                $msgs = $mstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                            ?>
                            <?php if (!empty($msgs)): ?>
                                <?php foreach ($msgs as $m): ?>
                                    <?php if (($m['author'] ?? '') === 'admin'): ?>
                                        <div style="background:rgba(34,197,94,.07);border-left:3px solid #22c55e;border-radius:0 8px 8px 0;padding:10px 13px;margin-bottom:12px;color:#d8d8e8;">
                                            <div style="font-size:11px;font-weight:800;color:#86efac;margin-bottom:5px;">Ответ администратора · <?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></div>
                                            <div style="font-size:13px;white-space:pre-wrap;word-break:break-word;"><?= htmlspecialchars($m['message']) ?></div>
                                        </div>
                                    <?php else: ?>
                                        <div style="background:#0e0e14;border-radius:8px;padding:12px;font-size:13px;color:#d8d8e8;line-height:1.6;white-space:pre-wrap;margin-bottom:12px;word-break:break-word;"><?= htmlspecialchars($m['message']) ?></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="background:#0e0e14;border-radius:8px;padding:12px;font-size:13px;color:#d8d8e8;line-height:1.6;white-space:pre-wrap;margin-bottom:12px;word-break:break-word;"><?= htmlspecialchars($ap['message'] ?? '') ?></div>
                            <?php endif; ?>
                            <form action="" method="POST" style="display:grid;gap:8px;">
                                <input type="hidden" name="appeal_id" value="<?= (int)$ap['id'] ?>">
                                <textarea name="reply_text" required rows="3" placeholder="Напиши ответ клиенту..." style="background:#171720;color:#fff;border:1px solid #2a2a38;border-radius:8px;padding:10px 12px;font-family:Montserrat,sans-serif;font-size:13px;outline:none;width:100%;box-sizing:border-box;resize:vertical;transition:.2s;" onfocus="this.style.borderColor='#f97316';" onblur="this.style.borderColor='#2a2a38';"></textarea>
                                <div>
                                    <button type="submit" name="reply_appeal" style="border:none;border-radius:9px;padding:10px 20px;background:linear-gradient(135deg,#fb923c,#f97316);color:#fff;font-weight:800;cursor:pointer;font-family:Montserrat,sans-serif;font-size:13px;box-shadow:0 6px 18px rgba(249,115,22,.3);transition:.2s;">
                                        📤 Отправить ответ
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ════ ДЕТАЛЬ ЗАКАЗА ════ -->
            <div id="order-detail-panel" style="display:none;">
                <div class="panel" data-panel="order-detail" style="max-width:960px;margin:0 auto;">
                    <?php if (!empty($viewOrder)): ?>
                        <h2>📦 Заказ #<?= (int)$viewOrder['id'] ?> — <?= htmlspecialchars($viewOrder['username'] ?? 'Клиент') ?></h2>
                        <div style="margin-bottom:12px;color:#8a8a96;font-size:13px;">Статус: <strong><?= htmlspecialchars($statusLabels[$viewOrder['status']] ?? $viewOrder['status']) ?></strong> · <?= date('d.m.Y H:i', strtotime($viewOrder['created_at'])) ?></div>
                        <div style="background:#0e0e14;border-radius:8px;padding:12px;font-size:13px;color:#d8d8e8;line-height:1.6;white-space:pre-wrap;margin-bottom:12px;word-break:break-word;"><?= htmlspecialchars($viewOrder['details'] ?? '') ?></div>

                        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
                            <?php $sSrc = imgSrc($viewOrder['screenshot'] ?? '', '../uploads/orders/'); ?>
                            <?php if ($sSrc !== ''): ?>
                                <div style="max-width:320px;">Чек оплаты:<br><a href="<?= htmlspecialchars($sSrc) ?>" target="_blank"><img src="<?= htmlspecialchars($sSrc) ?>" style="max-width:320px;border-radius:8px;" onerror="this.style.display='none'"></a></div>
                            <?php else: ?>
                                <div style="color:#8a8a96;">Чек оплаты: не прикреплён</div>
                            <?php endif; ?>
                            <?php $eSrc = imgSrc($viewOrder['example_photo'] ?? '', '../uploads/orders/'); ?>
                            <?php if ($eSrc !== ''): ?>
                                <div style="max-width:320px;">Референс:<br><a href="<?= htmlspecialchars($eSrc) ?>" target="_blank"><img src="<?= htmlspecialchars($eSrc) ?>" style="max-width:320px;border-radius:8px;" onerror="this.style.display='none'"></a></div>
                            <?php else: ?>
                                <div style="color:#8a8a96;">Референс: не прикреплён</div>
                            <?php endif; ?>
                        </div>

                        <h3 style="margin-top:6px;margin-bottom:8px;">💬 Переписка и обращения</h3>
                        <?php if (!empty($orderAppeals)): ?>
                            <?php foreach ($orderAppeals as $oap): ?>
                                <?php
                                    $mstmt = $pdo->prepare("SELECT author, message, created_at FROM appeals_messages WHERE appeal_id = ? ORDER BY id ASC");
                                    $mstmt->execute([(int)$oap['id']]);
                                    $msgs = $mstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                                ?>
                                <div style="background:#0b0b0f;padding:10px;border-radius:6px;margin-bottom:8px;">
                                    <div style="font-size:12px;color:#8a8a96;margin-bottom:8px;"><strong><?= htmlspecialchars($oap['subject']?:'Обращение') ?></strong></div>
                                    <?php if (empty($msgs)): ?>
                                        <div style="color:#8a8a96;">Сообщений пока нет.</div>
                                    <?php else: ?>
                                        <?php foreach ($msgs as $m): ?>
                                            <?php if (($m['author'] ?? '') === 'admin'): ?>
                                                <div style="background:rgba(34,197,94,.06);padding:8px;border-radius:6px;color:#d8d8e8;margin-bottom:6px;"><strong>Админ</strong> · <?= date('d.m.Y H:i', strtotime($m['created_at'])) ?><div style="margin-top:6px;white-space:pre-wrap;"><?= nl2br(htmlspecialchars($m['message'])) ?></div></div>
                                            <?php else: ?>
                                                <div style="background:rgba(249,115,22,.04);padding:8px;border-radius:6px;color:#d8d8e8;margin-bottom:6px;"><strong>Клиент</strong> · <?= date('d.m.Y H:i', strtotime($m['created_at'])) ?><div style="margin-top:6px;white-space:pre-wrap;"><?= nl2br(htmlspecialchars($m['message'])) ?></div></div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="color:#8a8a96;margin-bottom:12px;">Переписка отсутствует.</div>
                        <?php endif; ?>

                        <form action="" method="POST" style="display:grid;gap:8px;max-width:720px;">
                            <input type="hidden" name="order_id" value="<?= (int)$viewOrder['id'] ?>">
                            <input type="text" name="msg_subject" placeholder="Тема (необязательно)" value="Уточнение по заказу #<?= (int)$viewOrder['id'] ?>">
                            <textarea name="msg_text" required rows="4" placeholder="Напиши сообщение клиенту (он получит уведомление в Telegram)" style="background:#171720;color:#fff;border:1px solid #2a2a38;border-radius:8px;padding:10px 12px;"> </textarea>
                            <div>
                                <button type="submit" name="send_order_message" class="btn-panel">📤 Отправить клиенту</button>
                                <a href="<?= $_SERVER['PHP_SELF'] ?>" class="delete-link" style="margin-left:10px;">Назад к панели</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <h2>📦 Заказ не найден</h2>
                        <div style="color:#8a8a96;">Выберите заказ из списка, чтобы открыть детальную карточку.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
function showToast(msg, type = 'success', duration = 5000) {
    const t = document.getElementById('admin-toast');
    t.textContent = msg;
    t.className   = 'show ' + type;
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.classList.remove('show'); }, duration);
}

document.getElementById('portfolio-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn  = document.getElementById('portfolio-submit-btn');
    const form = this;
    btn.disabled = true;
    btn.classList.add('loading');
    showToast('⏳ Загружаем на ImgBB... Это может занять 10–30 сек.', 'loading', 60000);
    const fd = new FormData(form);
    fd.append('add_portfolio', '1');
    try {
        const resp = await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
        const data = await resp.json();
        showToast(data.msg, data.ok ? 'success' : 'error', 7000);
        if (data.ok) { form.reset(); document.querySelectorAll('.file-upload-name, .mini-file-name').forEach(el => { el.textContent = 'Файл не выбран'; el.classList.remove('has-file'); }); }
    } catch (err) {
        showToast('❌ Ошибка соединения. Попробуй ещё раз.', 'error', 7000);
    } finally {
        btn.disabled = false;
        btn.classList.remove('loading');
    }
});

function toggleAvatarField() {
    const category = document.getElementById('category_select').value;
    const block    = document.getElementById('avatar_upload_block');
    const designCategories = <?= json_encode(array_values(array_filter(array_map(fn($c) => !empty($c['is_design']) ? $c['category_key'] : null, $categories))), JSON_UNESCAPED_UNICODE) ?>;
    block.style.display = designCategories.includes(category) ? 'block' : 'none';
}

function activateAdminTab(tab) {
    const apPanel = document.getElementById('appeals-panel');
    if (apPanel) apPanel.style.display = 'none';
    document.querySelectorAll('.admin-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    const stats  = document.querySelector('.stats-grid');
    const layout = document.querySelector('.admin-layout');
    if (stats)  stats.classList.add('tab-hidden');
    if (layout) layout.classList.add('single-column');
    document.querySelectorAll('.panel').forEach(p => p.classList.add('tab-hidden'));

    const show = (...names) => names.forEach(n => {
        const el = document.querySelector(`.panel[data-panel="${n}"]`);
        if (el) el.classList.remove('tab-hidden');
    });

    if (tab === 'overview')    { if (stats) stats.classList.remove('tab-hidden'); show('orders'); }
    else if (tab === 'portfolio') { show('portfolio-add','portfolio-list'); if (layout) layout.classList.remove('single-column'); }
    else if (tab === 'price')     { show('price-add','price-manager');      if (layout) layout.classList.remove('single-column'); }
    else if (tab === 'orders')    { if (stats) stats.classList.remove('tab-hidden'); show('orders'); }
    else if (tab === 'categories'){ show('categories'); }
    else if (tab === 'avatar')    { show('avatar'); }
    else if (tab === 'appeals')   {
        if (apPanel) {
            apPanel.style.display = 'block';
            const innerAppeals = apPanel.querySelector('.panel[data-panel="appeals"]');
            if (innerAppeals) innerAppeals.classList.remove('tab-hidden');
        }
    }
}

function initFileInputs() {
    document.querySelectorAll('input[type="file"]').forEach(input => {
        if (input.dataset.styled) return;
        input.dataset.styled = '1';
        input.classList.add('styled-hidden');
        const isMini = input.closest('.mini-media-form') !== null;
        const wrap   = document.createElement('div');
        wrap.className = isMini ? 'mini-file-wrap' : 'file-upload-wrap';
        const label  = document.createElement('label');
        label.htmlFor = input.id || (input.id = 'fi_' + Math.random().toString(36).slice(2));
        label.className = isMini ? 'mini-file-btn' : 'file-upload-btn';
        label.style.margin = '0';
        label.innerHTML = `<svg width="${isMini?12:14}" height="${isMini?12:14}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Выбрать файл`;
        const nameSpan = document.createElement('span');
        nameSpan.className = isMini ? 'mini-file-name' : 'file-upload-name';
        nameSpan.textContent = 'Файл не выбран';
        input.addEventListener('change', () => {
            const name = input.files[0]?.name || 'Файл не выбран';
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

function initAntiTheft() {
    document.addEventListener('contextmenu', function(e) {
        const target = e.target;
        if (target.tagName === 'IMG' && (target.classList.contains('case-thumb') || target.classList.contains('case-ava') || target.classList.contains('price-thumb'))) { e.preventDefault(); return false; }
    }, true);
    document.addEventListener('dragstart', function(e) {
        if (e.target.tagName === 'IMG') { const c = e.target.classList; if (c.contains('case-thumb') || c.contains('case-ava') || c.contains('price-thumb')) e.preventDefault(); }
    }, true);
}

document.addEventListener('DOMContentLoaded', () => {
    toggleAvatarField();
    activateAdminTab('overview');
    const params = new URLSearchParams(window.location.search);
    if (params.has('view_order')) {
        activateAdminTab('orders');
        // show order-detail panel if present
        setTimeout(() => {
            const det = document.querySelector('.panel[data-panel="order-detail"]');
            if (det) {
                document.querySelectorAll('.panel').forEach(p => p.classList.add('tab-hidden'));
                det.classList.remove('tab-hidden');
                document.querySelectorAll('.admin-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === 'orders'));
            }
        }, 40);
    }
    initFileInputs();
    initAntiTheft();
    document.querySelectorAll('.admin-tab').forEach(btn => {
        btn.addEventListener('click', () => setTimeout(initFileInputs, 50));
    });
});
</script>
</body>
</html>