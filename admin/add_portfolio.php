<?php
/**
 * admin/add_portfolio.php
 * Форма добавления новой работы в портфолио.
 * При успешном сохранении — накладывает водяной знак и публикует в Telegram-канал.
 */

session_start();
require_once __DIR__ . '/../config/db.php';

// ── Настройки ────────────────────────────────────────────────────
$bot_token  = getenv('BOT_TOKEN')    ?: "8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg";
$channel_id = getenv('CHANNEL_ID')   ?: "@designkostlim";
$admin_id   = getenv('ADMIN_ID')     ?: "1710365896";
$imgbb_key  = getenv('IMGBB_KEY')    ?: "";
$site_url   = "https://portfolio-site-boo5.onrender.com/";
$avatar_url = getenv('AVATAR_URL')   ?: "https://i.ibb.co/twWTVGHn/avatar-1780311261.jpg";

// Защита
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    if (($_GET['key'] ?? '') !== $admin_id) {
        http_response_code(403);
        echo '<p style="color:red;font-family:monospace;">403 Доступ закрыт.</p>';
        exit;
    }
}

$success_msg = '';
$error_msg   = '';

ensureDefaultPortfolioCategories($pdo);
$portfolio_categories = $pdo->query("SELECT * FROM portfolio_categories ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title     = trim($_POST['title']     ?? '');
    $price_rub = (int)($_POST['price_rub'] ?? 0);
    $price_uah = (int)($_POST['price_uah'] ?? 0);
    $category  = trim($_POST['category']  ?? 'preview');
    $category_frame = getPortfolioCategoryFrame($pdo, $category);
    $image_url = '';

    if (empty($title)) {
        $error_msg = 'Введи название работы.';
        goto render;
    }

    // ── Получаем исходное изображение ─────────────────────────────
    $img_data = null;
    $img_ext  = 'jpg';

    if (!empty($_FILES['image']['tmp_name'])) {
        $img_data = file_get_contents($_FILES['image']['tmp_name']);
        $img_ext  = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    } elseif (!empty($_POST['image_url'])) {
        $img_data = @file_get_contents($_POST['image_url']);
        $img_ext  = 'jpg';
    } else {
        $error_msg = 'Загрузи изображение или укажи прямую ссылку.';
        goto render;
    }

    if (empty($img_data)) {
        $error_msg = '❌ Не удалось получить изображение.';
        goto render;
    }

    // ── Накладываем водяной знак ───────────────────────────────────
    $watermarked = applyWatermark($img_data, $avatar_url, $title, $price_rub, $price_uah, $category_frame);
    $final_data  = $watermarked ?: $img_data; // если GD не сработал — оригинал

    // ── Загружаем на ImgBB ─────────────────────────────────────────
    $ch = curl_init('https://api.imgbb.com/1/upload');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'key'   => $imgbb_key,
        'image' => base64_encode($final_data),
        'name'  => 'kostlim_' . time(),
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $imgbb_res  = curl_exec($ch);
    curl_close($ch);
    $imgbb_data = json_decode($imgbb_res, true);

    if (!empty($imgbb_data['data']['url'])) {
        $image_url = $imgbb_data['data']['url'];
    } else {
        $error_msg = '❌ Ошибка загрузки на ImgBB: ' . ($imgbb_data['error']['message'] ?? 'неизвестная ошибка');
        goto render;
    }

    // ── Сохраняем в БД ─────────────────────────────────────────────
    try {
        $stmt = $pdo->prepare("
            INSERT INTO portfolio (title, price_rub, price_uah, category, image_url, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$title, $price_rub, $price_uah, $category, $image_url]);
        $portfolio_id = $pdo->lastInsertId();

        // ── Публикуем в Telegram-канал ─────────────────────────────
        $channel_result = postToChannel($bot_token, $channel_id, $title, $price_rub, $price_uah, $image_url, $site_url);

        $success_msg = "✅ Работа «{$title}» добавлена (ID #{$portfolio_id})!";
        $success_msg .= !empty($channel_result['ok'])
            ? " 🚀 Пост опубликован в канале!"
            : " ⚠️ Пост в канал не отправился (проверь CHANNEL_ID и права бота).";

    } catch (PDOException $e) {
        $error_msg = '❌ Ошибка БД: ' . $e->getMessage();
    }
}

render:
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Добавить работу | Kostlim Admin</title>
    <style>
        * { box-sizing: border-box; }
        body { background: #0d0d12; color: #e8e8f0; font-family: 'Segoe UI', sans-serif; margin: 0; }
        .wrap { max-width: 600px; margin: 50px auto; padding: 0 20px; }
        h1 { color: #fff; font-size: 22px; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 30px; }
        .card { background: #111116; border: 1px solid #1f1f2a; border-radius: 16px; padding: 30px; }
        label { display: block; color: #8a8a93; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; margin-bottom: 6px; }
        input[type=text], input[type=number], select, input[type=url] {
            width: 100%; padding: 12px; border-radius: 8px;
            background: #16161f; border: 1px solid #262633; color: #fff;
            font-size: 14px; margin-bottom: 18px;
        }
        input[type=file] { color: #8a8a93; font-size: 13px; margin-bottom: 18px; }
        .price-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .btn-submit {
            width: 100%; padding: 15px; border: none; border-radius: 8px;
            background: #a95851; color: #fff; font-size: 14px; font-weight: 900;
            text-transform: uppercase; letter-spacing: .5px; cursor: pointer; transition: background .2s;
        }
        .btn-submit:hover { background: #c76860; }
        .msg-ok  { background: rgba(0,255,163,.1); border: 1px solid #00ffa3; color: #00ffa3; padding: 14px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; }
        .msg-err { background: rgba(239,68,68,.1); border: 1px solid #ef4444; color: #ef4444; padding: 14px; border-radius: 8px; margin-bottom: 20px; }
        .separator { color: #4a4a5a; margin: 16px 0; text-align: center; font-size: 12px; }
        a { color: #a95851; }
        .wm-preview { background: #0a0a10; border: 1px solid #1f1f2a; border-radius: 10px; padding: 14px; margin-bottom: 20px; font-size: 12px; color: #666; }
        .wm-preview strong { color: #a95851; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>➕ Добавить работу в портфолио</h1>

    <?php if ($success_msg): ?>
    <div class="msg-ok"><?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
    <div class="msg-err"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <div class="wm-preview">
        🖼 <strong>Водяной знак</strong> накладывается автоматически — плашка снизу с аватаркой, <strong>KOSTLIM DESIGN</strong> и <strong>T.ME/DESIGNKOSTLIM</strong>. Аватарка подтягивается каждый раз свежая по ссылке из <code>AVATAR_URL</code>.
    </div>

    <div class="card">
        <form method="POST" enctype="multipart/form-data">
            <label>Название работы *</label>
            <input type="text" name="title" required placeholder="Например: Баннер для YouTube-канала"
                   value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">

            <div class="price-row">
                <div>
                    <label>Цена (₽)</label>
                    <input type="number" name="price_rub" min="0" placeholder="0"
                           value="<?= (int)($_POST['price_rub'] ?? 0) ?>">
                </div>
                <div>
                    <label>Цена (грн)</label>
                    <input type="number" name="price_uah" min="0" placeholder="0"
                           value="<?= (int)($_POST['price_uah'] ?? 0) ?>">
                </div>
            </div>

            <label>Категория</label>
            <select name="category">
                <?php foreach ($portfolio_categories as $cat): ?>
                    <?php
                        $catKey = (string)($cat['category_key'] ?? '');
                        $selected = (($_POST['category'] ?? 'preview') === $catKey) ? 'selected' : '';
                    ?>
                    <option value="<?= htmlspecialchars($catKey) ?>" <?= $selected ?>>
                        <?= htmlspecialchars($cat['title'] ?? $catKey) ?>
                        <?php if ((int)($cat['width_px'] ?? 0) > 0 && (int)($cat['height_px'] ?? 0) > 0): ?>
                            (<?= (int)$cat['width_px'] ?>x<?= (int)$cat['height_px'] ?>)
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div style="color:#666;font-size:12px;margin:-10px 0 18px;">
                Размер категории задает рамку работы внутри Telegram-постера. Постер и фон остаются отдельно, работа не режется.
            </div>

            <label>Изображение (загрузи файл)</label>
            <input type="file" name="image" accept="image/*">

            <div class="separator">— или укажи прямую ссылку —</div>

            <label>Прямая ссылка на картинку</label>
            <input type="url" name="image_url" placeholder="https://i.ibb.co/example.jpg"
                   value="<?= htmlspecialchars($_POST['image_url'] ?? '') ?>">

            <button type="submit" class="btn-submit">
                💾 Сохранить и опубликовать в канал
            </button>
        </form>
    </div>

    <p style="text-align:center; margin-top:20px; font-size:13px; color:#4a4a5a;">
        <a href="../index.php">← На сайт</a>
    </p>
</div>
</body>
</html>

<?php
// ═══════════════════════════════════════════════════════════════
// ФУНКЦИЯ: Водяной знак
// ═══════════════════════════════════════════════════════════════
function applyWatermark(string $img_data, string $avatar_url, string $title = '', int $price_rub = 0, int $price_uah = 0, array $category_frame = []): ?string
{
    if (!extension_loaded('gd')) return null;

    $main = @imagecreatefromstring($img_data);
    if (!$main) return null;

    $avatar = null;
    $avatar_data = @file_get_contents($avatar_url);
    if ($avatar_data) {
        $avatar = @imagecreatefromstring($avatar_data) ?: null;
    }

    $copyCover = function ($dst, $src, int $dx, int $dy, int $dw, int $dh): void {
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw <= 0 || $sh <= 0 || $dw <= 0 || $dh <= 0) return;
        $srcRatio = $sw / $sh;
        $dstRatio = $dw / $dh;
        if ($srcRatio > $dstRatio) {
            $cropH = $sh;
            $cropW = (int)round($sh * $dstRatio);
            $sx = (int)round(($sw - $cropW) / 2);
            $sy = 0;
        } else {
            $cropW = $sw;
            $cropH = (int)round($sw / $dstRatio);
            $sx = 0;
            $sy = (int)round(($sh - $cropH) / 2);
        }
        imagecopyresampled($dst, $src, $dx, $dy, $sx, $sy, $dw, $dh, $cropW, $cropH);
    };

    $copyContain = function ($dst, $src, int $dx, int $dy, int $dw, int $dh): void {
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw <= 0 || $sh <= 0 || $dw <= 0 || $dh <= 0) return;

        $scale = min($dw / $sw, $dh / $sh);
        $drawW = (int)round($sw * $scale);
        $drawH = (int)round($sh * $scale);
        $drawX = $dx + (int)round(($dw - $drawW) / 2);
        $drawY = $dy + (int)round(($dh - $drawH) / 2);

        imagecopyresampled($dst, $src, $drawX, $drawY, 0, 0, $drawW, $drawH, $sw, $sh);
    };

    $roundCorners = function ($img, int $radius): void {
        $w = imagesx($img);
        $h = imagesy($img);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $inCorner = false;
                $cx = $x;
                $cy = $y;
                if ($x < $radius && $y < $radius) {
                    $cx = $radius; $cy = $radius; $inCorner = true;
                } elseif ($x >= $w - $radius && $y < $radius) {
                    $cx = $w - $radius - 1; $cy = $radius; $inCorner = true;
                } elseif ($x < $radius && $y >= $h - $radius) {
                    $cx = $radius; $cy = $h - $radius - 1; $inCorner = true;
                } elseif ($x >= $w - $radius && $y >= $h - $radius) {
                    $cx = $w - $radius - 1; $cy = $h - $radius - 1; $inCorner = true;
                }
                if ($inCorner) {
                    $dx = $x - $cx;
                    $dy = $y - $cy;
                    if (($dx * $dx + $dy * $dy) > ($radius * $radius)) {
                        imagesetpixel($img, $x, $y, $transparent);
                    }
                }
            }
        }
    };

    $drawCircle = function ($dst, $src, int $x, int $y, int $size): void {
        $avatar = imagecreatetruecolor($size, $size);
        imagealphablending($avatar, false);
        imagesavealpha($avatar, true);
        $transparent = imagecolorallocatealpha($avatar, 0, 0, 0, 127);
        imagefill($avatar, 0, 0, $transparent);
        imagecopyresampled($avatar, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
        $radius = $size / 2;
        for ($py = 0; $py < $size; $py++) {
            for ($px = 0; $px < $size; $px++) {
                $dx = $px - $radius;
                $dy = $py - $radius;
                if (($dx * $dx + $dy * $dy) <= ($radius * $radius)) {
                    imagesetpixel($dst, $x + $px, $y + $py, imagecolorat($avatar, $px, $py));
                }
            }
        }
        imagedestroy($avatar);
    };

    $catW = (int)($category_frame['width_px'] ?? 0);
    $catH = (int)($category_frame['height_px'] ?? 0);
    if ($catW <= 0 || $catH <= 0) {
        $catW = max(1, imagesx($main));
        $catH = max(1, imagesy($main));
    }

    $outW = $catW;
    $outH = $catH;

    $scale = 2;
    $canvasW = $outW * $scale;
    $canvasH = $outH * $scale;
    $canvas = imagecreatetruecolor($canvasW, $canvasH);
    imagealphablending($canvas, true);
    imagesavealpha($canvas, true);

    $template = '';
    foreach ([
        __DIR__ . '/../uploads/channel_template.png',
        __DIR__ . '/../uploads/channel-template.png',
        __DIR__ . '/../uploads/cover_template.png',
        __DIR__ . '/../uploads/cover-template.png',
        __DIR__ . '/channel_template.png',
        __DIR__ . '/channel-template.png',
    ] as $templatePath) {
        if (is_file($templatePath)) { $template = $templatePath; break; }
    }

    $templateImg = $template !== '' ? @imagecreatefromstring((string)file_get_contents($template)) : null;
    if ($templateImg) {
        $copyCover($canvas, $templateImg, 0, 0, $canvasW, $canvasH);
        imagedestroy($templateImg);
    } else {
        for ($y = 0; $y < $canvasH; $y++) {
            $mix = $y / $canvasH;
            $r = (int)(10 + 34 * $mix);
            $g = (int)(10 + 12 * $mix);
            $b = (int)(14 + 4 * $mix);
            imageline($canvas, 0, $y, $canvasW, $y, imagecolorallocate($canvas, $r, $g, $b));
        }
    }

    $padding = (int)round(min($canvasW, $canvasH) * 0.055);
    $brandH = (int)round(min($canvasW, $canvasH) * 0.18);
    $gap = (int)round(min($canvasW, $canvasH) * 0.026);
    $frameW = $canvasW - ($padding * 2);
    $frameH = $canvasH - ($padding * 2) - $brandH - $gap;
    if ($frameH < (int)round($canvasH * .48)) $frameH = (int)round($canvasH * .48);
    $frameX = $padding;
    $frameY = $padding;
    $frameScale = min($frameW / $catW, $frameH / $catH);
    $panelW = (int)round($catW * $frameScale);
    $panelH = (int)round($catH * $frameScale);
    $panelX = $frameX + (int)round(($frameW - $panelW) / 2);
    $panelY = $frameY + (int)round(($frameH - $panelH) / 2);
    $panel = imagecreatetruecolor($panelW, $panelH);
    imagealphablending($panel, true);
    imagesavealpha($panel, true);
    $transparent = imagecolorallocatealpha($panel, 0, 0, 0, 127);
    imagefill($panel, 0, 0, $transparent);

    $copyContain($panel, $main, 0, 0, $panelW, $panelH);
    $roundCorners($panel, 58 * $scale);
    imagecopy($canvas, $panel, $panelX, $panelY, 0, 0, $panelW, $panelH);
    imagedestroy($panel);

    $fontPaths = [
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
    $font = '';
    foreach ($fontPaths as $fontPath) {
        if (is_file($fontPath)) { $font = $fontPath; break; }
    }
    $white = imagecolorallocate($canvas, 255, 255, 255);
    $accent = imagecolorallocate($canvas, 249, 115, 22);
    $muted = imagecolorallocate($canvas, 214, 214, 222);
    $brandY = min($canvasH - $padding - $brandH, $panelY + $panelH + $gap);
    $avatarSize = $avatar ? (int)round(min($canvasW, $canvasH) * 0.125) : 0;
    $avatarX = $padding;
    $avatarY = $brandY + (int)round(($brandH - $avatarSize) / 2);
    $textX = $avatar ? ($avatarX + $avatarSize + (int)round($gap * 1.25)) : $padding;
    $brandTopBaseline = $brandY + (int)round($brandH * 0.38);
    $brandBottomBaseline = $brandY + (int)round($brandH * 0.66);
    $priceX = max($textX, (int)round($canvasW * 0.56));
    $priceBaseline = $brandY + (int)round($brandH * 0.58);

    if ($avatar) {
        $drawCircle($canvas, $avatar, $avatarX, $avatarY, $avatarSize);
        imagedestroy($avatar);
    }

    $drawFallback = function ($text, int $x, int $y, int $targetH, int $maxW, int $color) use ($canvas): void {
        $text = trim((string)$text);
        if ($text === '') return;
        $fontId = 5;
        $sourceW = max(1, imagefontwidth($fontId) * strlen($text));
        $sourceH = max(1, imagefontheight($fontId));
        $targetW = (int)round($sourceW * ($targetH / $sourceH));
        if ($targetW > $maxW) {
            $targetW = $maxW;
            $targetH = (int)round($sourceH * ($targetW / $sourceW));
        }
        $tmp = imagecreatetruecolor($sourceW, $sourceH);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
        imagefill($tmp, 0, 0, $transparent);
        imagestring($tmp, $fontId, 0, 0, $text, $color);
        imagecopyresampled($canvas, $tmp, $x, $y - $targetH, 0, 0, $targetW, $targetH, $sourceW, $sourceH);
        imagedestroy($tmp);
    };
    if ($font !== '' && function_exists('imagettftext')) {
        imagettftext($canvas, max(16, (int)round($brandH * 0.17)), 0, $textX, $brandTopBaseline, $white, $font, 'KOSTLIM');
        imagettftext($canvas, max(14, (int)round($brandH * 0.145)), 0, $textX, $brandBottomBaseline, $muted, $font, 'DESIGN');
        imagettftext($canvas, max(18, (int)round($brandH * 0.20)), 0, $priceX, $priceBaseline, $accent, $font, $price_rub . ' RUB | ' . $price_uah . ' UAH');
    } else {
        $drawFallback('KOSTLIM', $textX, $brandTopBaseline, max(16, (int)round($brandH * 0.17)), (int)round($canvasW * 0.34), $white);
        $drawFallback('DESIGN', $textX, $brandBottomBaseline, max(14, (int)round($brandH * 0.145)), (int)round($canvasW * 0.34), $muted);
        $drawFallback($price_rub . ' RUB | ' . $price_uah . ' UAH', $priceX, $priceBaseline, max(18, (int)round($brandH * 0.20)), (int)round($canvasW * 0.40), $accent);
    }

    $final = imagecreatetruecolor($outW, $outH);
    imagecopyresampled($final, $canvas, 0, 0, 0, 0, $outW, $outH, $canvasW, $canvasH);

    ob_start();
    imagejpeg($final, null, 100);
    $result = ob_get_clean();
    imagedestroy($main);
    imagedestroy($canvas);
    imagedestroy($final);

    return $result ?: null;
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

function getPortfolioCategoryFrame(PDO $pdo, string $category): array
{
    $stmt = $pdo->prepare("SELECT width_px, height_px FROM portfolio_categories WHERE category_key = ? LIMIT 1");
    $stmt->execute([$category]);
    $frame = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    if (!empty($frame)) {
        return $frame;
    }

    if (preg_match('/(\d+)\s*x\s*(\d+)/i', $category, $match)) {
        return [
            'width_px' => (int)$match[1],
            'height_px' => (int)$match[2],
        ];
    }

    return [
        'width_px' => 1920,
        'height_px' => 1080,
    ];
}
// ═══════════════════════════════════════════════════════════════
// ФУНКЦИЯ: Публикация в Telegram-канал
// ═══════════════════════════════════════════════════════════════
function postToChannel($token, $channel_id, $title, $price_rub, $price_uah, $image_url, $site_url) {
    try {
        $caption = "💰 Цена работы: {$price_rub}₽ | {$price_uah}₴\n\n"
            . "💬 Оценить данную работу можно в комментариях.\n\n"
            . '🚀 Заказать дизайн можно тут - <a href="' . htmlspecialchars($site_url, ENT_QUOTES, 'UTF-8') . '">Kostlim Design</a>';

        $ch = curl_init("https://api.telegram.org/bot{$token}/sendPhoto");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'chat_id'    => $channel_id,
            'photo'      => $image_url,
            'caption'    => $caption,
            'parse_mode' => 'HTML',
        ], JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $res  = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($res, true);
        if (!($data['ok'] ?? false)) {
            error_log('[Kostlim] postToChannel error: ' . $res);
        }
        return $data;
    } catch (Exception $e) {
        error_log('[Kostlim] postToChannel exception: ' . $e->getMessage());
        return null;
    }
}


