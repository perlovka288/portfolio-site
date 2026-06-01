<?php
/**
 * admin/add_portfolio.php
 * Форма добавления новой работы в портфолио.
 * При успешном сохранении — автоматически публикует пост в Telegram-канал.
 */

session_start();
require_once __DIR__ . '/../config/db.php';

// ── Настройки бота и канала ──────────────────────────────────────
$bot_token  = getenv('BOT_TOKEN')   ?: "8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg";
$channel_id = getenv('CHANNEL_ID')  ?: "@gfasasdasasd"; // <-- замени на юзернейм или ID своего канала
$admin_id   = getenv('ADMIN_ID')    ?: "1710365896";
$imgbb_key  = getenv('IMGBB_KEY')   ?: "";               // <-- вставь свой API-ключ ImgBB
$site_url   = getenv('SITE_URL')    ?: "https://portfolio-site-boo5.onrender.com/";

// Простая защита: только для авторизованного администратора
// Если у тебя уже есть своя сессионная авторизация — замени эту проверку на свою
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    // Пробуем базовую авторизацию через GET-параметр как fallback (только для теста)
    // В продакшн убери это и используй нормальный логин
    if (($_GET['key'] ?? '') !== $admin_id) {
        http_response_code(403);
        echo '<p style="color:red;font-family:monospace;">403 Доступ закрыт. Авторизуйся в админке.</p>';
        exit;
    }
}

$success_msg = '';
$error_msg   = '';

// ── Обработка POST-запроса ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title      = trim($_POST['title'] ?? '');
    $price_rub  = (int)($_POST['price_rub'] ?? 0);
    $price_uah  = (int)($_POST['price_uah'] ?? 0);
    $category   = trim($_POST['category'] ?? '');
    $image_url  = '';

    if (empty($title)) {
        $error_msg = 'Введи название работы.';
        goto render;
    }

    // ── Загрузка картинки на ImgBB ─────────────────────────────
    if (!empty($_FILES['image']['tmp_name'])) {
        $image_data = base64_encode(file_get_contents($_FILES['image']['tmp_name']));
        $ch = curl_init('https://api.imgbb.com/1/upload');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'key'   => $imgbb_key,
            'image' => $image_data,
            'name'  => pathinfo($_FILES['image']['name'], PATHINFO_FILENAME),
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
    } elseif (!empty($_POST['image_url'])) {
        // Альтернатива: прямая ссылка на картинку
        $image_url = trim($_POST['image_url']);
    } else {
        $error_msg = 'Загрузи изображение или укажи прямую ссылку.';
        goto render;
    }

    // ── Сохраняем в базу данных ────────────────────────────────
    try {
        $stmt = $pdo->prepare("
            INSERT INTO portfolio (title, price_rub, price_uah, category, image_url, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$title, $price_rub, $price_uah, $category, $image_url]);
        $portfolio_id = $pdo->lastInsertId();

        // ── Публикуем в Telegram-канал ─────────────────────────
        // (только после успешной записи в БД)
        $channel_result = postNewWorkToChannel($bot_token, $channel_id, $title, $price_rub, $price_uah, $image_url, $site_url);

        $success_msg = "✅ Работа «{$title}» добавлена в портфолио (ID #{$portfolio_id})!";
        if (!empty($channel_result['ok'])) {
            $success_msg .= " 🚀 Пост опубликован в канале!";
        } else {
            $success_msg .= " ⚠️ Пост в канал не отправился (проверь CHANNEL_ID и права бота).";
        }

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
            text-transform: uppercase; letter-spacing: .5px; cursor: pointer;
            transition: background .2s;
        }
        .btn-submit:hover { background: #c76860; }
        .msg-ok  { background: rgba(0,255,163,.1); border: 1px solid #00ffa3; color: #00ffa3; padding: 14px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; }
        .msg-err { background: rgba(239,68,68,.1);  border: 1px solid #ef4444; color: #ef4444; padding: 14px; border-radius: 8px; margin-bottom: 20px; }
        .separator { color: #4a4a5a; margin: 16px 0; text-align: center; font-size: 12px; }
        a { color: #a95851; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>➕ Добавить работу в портфолио</h1>

    <?php if ($success_msg): ?>
    <div class="msg-ok"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
    <div class="msg-err"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" enctype="multipart/form-data">
            <label>Название работы *</label>
            <input type="text" name="title" required placeholder="Например: Баннер для YouTube-канала"
                   value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">

            <div class="price-row">
                <div>
                    <label>Цена (₽)</label>
                    <input type="number" name="price_rub" min="0" placeholder="0"
                           value="<?php echo (int)($_POST['price_rub'] ?? 0); ?>">
                </div>
                <div>
                    <label>Цена (грн)</label>
                    <input type="number" name="price_uah" min="0" placeholder="0"
                           value="<?php echo (int)($_POST['price_uah'] ?? 0); ?>">
                </div>
            </div>

            <label>Категория</label>
            <input type="text" name="category" placeholder="Например: banner, avatar, logo"
                   value="<?php echo htmlspecialchars($_POST['category'] ?? ''); ?>">

            <label>Изображение (загрузи файл)</label>
            <input type="file" name="image" accept="image/*">

            <div class="separator">— или укажи прямую ссылку —</div>

            <label>Прямая ссылка на картинку (ImgBB или другой хостинг)</label>
            <input type="url" name="image_url" placeholder="https://i.ibb.co/example.jpg"
                   value="<?php echo htmlspecialchars($_POST['image_url'] ?? ''); ?>">

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
// ФУНКЦИЯ: Публикация в Telegram-канал
// ═══════════════════════════════════════════════════════════════
/**
 * Отправляет фото новой работы в Telegram-канал с красиво оформленным текстом.
 *
 * @param string $token       Токен бота
 * @param string $channel_id  ID или @username канала
 * @param string $title       Название работы
 * @param int    $price_rub   Цена в рублях
 * @param int    $price_uah   Цена в гривнах
 * @param string $image_url   Прямая ссылка на изображение
 * @param string $site_url    URL сайта для кнопки
 * @return array|null         Ответ Telegram API
 */
function postNewWorkToChannel($token, $channel_id, $title, $price_rub, $price_uah, $image_url, $site_url) {
    try {
        // Экранируем для MarkdownV2
        $title_esc     = escapeMarkdownV2($title);
        $price_rub_esc = escapeMarkdownV2((string)$price_rub);
        $price_uah_esc = escapeMarkdownV2((string)$price_uah);

        $caption = "🔥 *Новая работа в портфолио\\!*\n\n"
            . "📌 *Название:* {$title_esc}\n"
            . "💵 *Цена работы:* {$price_rub_esc} ₽ / {$price_uah_esc} грн\n\n"
            . "💬 Оценить данную работу можно в комментариях\\.\n"
            . "🚀 Заказать дизайн можно тут — [Kostlim Design](" . escapeMarkdownV2($site_url) . ")";

        $ch = curl_init("https://api.telegram.org/bot{$token}/sendPhoto");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'chat_id'    => $channel_id,
            'photo'      => $image_url,
            'caption'    => $caption,
            'parse_mode' => 'MarkdownV2',
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $res = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($res, true);
        if (!($data['ok'] ?? false)) {
            error_log('[Kostlim] postNewWorkToChannel error: ' . $res);
        }
        return $data;
    } catch (Exception $e) {
        error_log('[Kostlim] postNewWorkToChannel exception: ' . $e->getMessage());
        return null;
    }
}

function escapeMarkdownV2($text) {
    $chars = ['_','*','[',']','(',')','-','.','!','~','`','>','#','+','=','|','{','}'];
    foreach ($chars as $ch) {
        $text = str_replace($ch, '\\' . $ch, $text);
    }
    return $text;
}