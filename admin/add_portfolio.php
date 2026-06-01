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
$site_url   = getenv('SITE_URL')     ?: "https://portfolio-site-boo5.onrender.com/";
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title     = trim($_POST['title']     ?? '');
    $price_rub = (int)($_POST['price_rub'] ?? 0);
    $price_uah = (int)($_POST['price_uah'] ?? 0);
    $category  = trim($_POST['category']  ?? '');
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
    $watermarked = applyWatermark($img_data, $avatar_url, 'KOSTLIM DESIGN', 'T.ME/DESIGNKOSTLIM');
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
            <input type="text" name="category" placeholder="Например: banner, avatar, logo"
                   value="<?= htmlspecialchars($_POST['category'] ?? '') ?>">

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
function applyWatermark(string $img_data, string $avatar_url, string $line1, string $line2): ?string
{
    if (!extension_loaded('gd')) return null;

    // Загружаем основное изображение
    $src = @imagecreatefromstring($img_data);
    if (!$src) return null;

    $src_w = imagesx($src);
    $src_h = imagesy($src);

    // ── Параметры плашки ──────────────────────────────────────
    $bar_h    = (int)($src_h * 0.10); // 10% высоты изображения
    $bar_h    = max($bar_h, 48);       // минимум 48px
    $pad      = (int)($bar_h * 0.18);
    $font_size_big  = max(14, (int)($bar_h * 0.35));
    $font_size_small = max(10, (int)($bar_h * 0.25));

    // Создаём финальный холст (оригинал + плашка снизу)
    $out = imagecreatetruecolor($src_w, $src_h + $bar_h);

    // Копируем оригинал
    imagecopy($out, $src, 0, 0, 0, 0, $src_w, $src_h);
    imagedestroy($src);

    // ── Фон плашки — тёмный с небольшой прозрачностью ────────
    $bar_bg = imagecolorallocate($out, 13, 13, 18); // #0d0d12
    imagefilledrectangle($out, 0, $src_h, $src_w, $src_h + $bar_h, $bar_bg);

    // ── Цвета текста ──────────────────────────────────────────
    $white  = imagecolorallocate($out, 255, 255, 255);
    $orange = imagecolorallocate($out, 249, 115, 22);   // #f97316
    $grey   = imagecolorallocate($out, 160, 160, 170);

    // ── Аватарка ──────────────────────────────────────────────
    $avatar_data = @file_get_contents($avatar_url);
    $avatar_size = $bar_h - $pad * 2;
    $avatar_x    = $pad;
    $avatar_y    = $src_h + $pad;

    if ($avatar_data) {
        $av_src = @imagecreatefromstring($avatar_data);
        if ($av_src) {
            // Масштабируем аватарку
            $av_scaled = imagecreatetruecolor($avatar_size, $avatar_size);
            imagecopyresampled($av_scaled, $av_src, 0, 0, 0, 0,
                $avatar_size, $avatar_size, imagesx($av_src), imagesy($av_src));
            imagedestroy($av_src);

            // Круглая маска для аватарки
            $mask = imagecreatetruecolor($avatar_size, $avatar_size);
            $black = imagecolorallocate($mask, 0, 0, 0);
            $mask_white = imagecolorallocate($mask, 255, 255, 255);
            imagefill($mask, 0, 0, $black);
            imagefilledellipse($mask, $avatar_size/2, $avatar_size/2, $avatar_size, $avatar_size, $mask_white);

            // Накладываем аватарку с маской
            for ($y = 0; $y < $avatar_size; $y++) {
                for ($x = 0; $x < $avatar_size; $x++) {
                    $m = imagecolorat($mask, $x, $y);
                    if ($m > 0) {
                        $c = imagecolorat($av_scaled, $x, $y);
                        imagesetpixel($out, $avatar_x + $x, $avatar_y + $y, $c);
                    }
                }
            }
            imagedestroy($av_scaled);
            imagedestroy($mask);
        }
    }

    // ── Текст ─────────────────────────────────────────────────
    $text_x  = $avatar_x + $avatar_size + $pad;
    $text_y1 = $src_h + $pad + (int)($font_size_big * 1.1);
    $text_y2 = $text_y1 + (int)($font_size_small * 1.4);

    // Пробуем системный шрифт если есть, иначе встроенный GD
    $font_path = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    if (function_exists('imagettftext') && file_exists($font_path)) {
        imagettftext($out, $font_size_big,   0, $text_x, $text_y1, $white,  $font_path, $line1);
        imagettftext($out, $font_size_small, 0, $text_x, $text_y2, $orange, $font_path, $line2);
    } else {
        // Встроенный шрифт GD (без TTF)
        $gd_font = 4;
        $char_w  = imagefontwidth($gd_font);
        $char_h  = imagefontheight($gd_font);
        imagestring($out, $gd_font, $text_x, $src_h + $pad,                   $line1, $white);
        imagestring($out, $gd_font, $text_x, $src_h + $pad + $char_h + 4,     $line2, $orange);
    }

    // ── Разделитель (тонкая линия между фото и плашкой) ──────
    $sep_color = imagecolorallocate($out, 169, 88, 81); // #a95851
    imageline($out, 0, $src_h, $src_w, $src_h, $sep_color);

    // ── Экспортируем в PNG ────────────────────────────────────
    ob_start();
    imagepng($out);
    $result = ob_get_clean();
    imagedestroy($out);

    return $result ?: null;
}

// ═══════════════════════════════════════════════════════════════
// ФУНКЦИЯ: Публикация в Telegram-канал
// ═══════════════════════════════════════════════════════════════
function postToChannel($token, $channel_id, $title, $price_rub, $price_uah, $image_url, $site_url) {
    try {
        $caption = "🔥 Новая работа в портфолио!\n\n"
            . "📌 Название: {$title}\n"
            . "💵 Цена работы: {$price_rub} ₽ / {$price_uah} грн\n\n"
            . "💬 Оценить данную работу можно в комментариях.\n"
            . "🚀 Заказать дизайн можно тут — {$site_url}";

        $ch = curl_init("https://api.telegram.org/bot{$token}/sendPhoto");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'chat_id'  => $channel_id,
            'photo'    => $image_url,
            'caption'  => $caption,
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