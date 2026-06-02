<?php
session_start();
require_once 'config/db.php';

define('ADMIN_TG_ID', '1710365896');
if (!empty($_GET['tg_id']) && $_GET['tg_id'] === ADMIN_TG_ID) {
    $_SESSION['admin_logged'] = true;
}

$bot_token   = getenv('BOT_TOKEN') ?: "8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg";
$my_chat_id  = getenv('ADMIN_ID')  ?: "1710365896";
$bot_link    = 'https://t.me/kostlimdznbot';
$support_tg  = 'https://t.me/Perlo_ovka';

$turnstile_site_key   = getenv('TURNSTILE_SITE_KEY')   ?: 'ТВОЙ_ПУБЛИЧНЫЙ_КЛЮЧ';
$turnstile_secret_key = getenv('TURNSTILE_SECRET_KEY') ?: 'ТВОЙ_СЕКРЕТНЫЙ_КЛЮЧ';

define('COOLDOWN_SECONDS', 300);

$selected_service = $_GET['service'] ?? '';
$services = $pdo->query("SELECT title, category_key, price_uan, price_rub FROM prices ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$success_msg = '';
$error_msg   = '';

$rules_accepted = (($_GET['accepted'] ?? '') === '1') || (($_POST['rules_accepted'] ?? '') === '1');

$accept_params = [];
if ($selected_service !== '') $accept_params['service'] = $selected_service;
$accept_params['accepted'] = '1';
$accept_url = 'order.php?' . http_build_query($accept_params);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$rules_accepted) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $user_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $user_ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $user_ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }

    try {
        $stmt = $pdo->prepare("SELECT created_at FROM orders WHERE client_ip = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user_ip]);
        $last_order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($last_order) {
            $seconds_passed = time() - strtotime($last_order['created_at']);
            if ($seconds_passed < COOLDOWN_SECONDS) {
                $minutes_left = ceil((COOLDOWN_SECONDS - $seconds_passed) / 60);
                $error_msg = "⏳ Слишком много заявок. Подождите ещё {$minutes_left} мин. перед новым заказом.";
                goto render_page;
            }
        }
    } catch (PDOException $e) {}

    $captcha_token = $_POST['cf-turnstile-response'] ?? '';
    if (empty($captcha_token)) {
        $error_msg = '⚠️ Пройдите проверку (Turnstile). Обновите страницу и попробуйте снова.';
        goto render_page;
    }
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['secret' => $turnstile_secret_key, 'response' => $captcha_token, 'remoteip' => $user_ip]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $cf_result = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (empty($cf_result['success'])) {
        $error_msg = '⚠️ Капча не прошла. Обновите страницу и попробуйте ещё раз.';
        goto render_page;
    }

    $telegram_raw = trim($_POST['telegram'] ?? '');
    $tg_clean = ltrim(str_replace(['https://t.me/', 'http://t.me/', '@'], '', $telegram_raw), '@');
    try {
        $bl_stmt = $pdo->prepare("SELECT reason FROM blacklist WHERE telegram = ? OR ip = ? LIMIT 1");
        $bl_stmt->execute([$tg_clean, $user_ip]);
        $bl = $bl_stmt->fetch(PDO::FETCH_ASSOC);
        if ($bl) {
            $error_msg = '🚫 Оформление заказов с вашего аккаунта или адреса недоступно.';
            goto render_page;
        }
    } catch (PDOException $e) {}

    $username    = $_POST['username'] ?? '';
    $service_key = $_POST['service']  ?? '';
    $details     = $_POST['details']  ?? '';

    $pay_screenshot = '';
    $example_imgs   = [];
    $target_dir = 'uploads/orders/';
    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

    if (!empty($_FILES['screenshot']['name'])) {
        $ext = pathinfo($_FILES['screenshot']['name'], PATHINFO_EXTENSION);
        $pay_screenshot = 'pay_' . time() . '_' . uniqid() . '.' . $ext;
        move_uploaded_file($_FILES['screenshot']['tmp_name'], $target_dir . $pay_screenshot);
    }
    if (!empty($_FILES['example_photos']['name'][0])) {
        foreach ($_FILES['example_photos']['tmp_name'] as $i => $tmp) {
            if (!empty($tmp) && $_FILES['example_photos']['error'][$i] === 0) {
                $ext = pathinfo($_FILES['example_photos']['name'][$i], PATHINFO_EXTENSION);
                $fname = 'ref_' . time() . '_' . uniqid() . '.' . $ext;
                move_uploaded_file($tmp, $target_dir . $fname);
                $example_imgs[] = $fname;
            }
        }
    }

    $example_img_json = json_encode($example_imgs);

    try {
        $stmt = $pdo->prepare("INSERT INTO orders
            (username, telegram, service_key, details, screenshot, example_photo, status, client_ip, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW())");
        $stmt->execute([$username, $telegram_raw, $service_key, $details, $pay_screenshot, $example_img_json, $user_ip]);
        $order_id = $pdo->lastInsertId();

        $success_msg = "🚀 Заказ #{$order_id} отправлен! Чтобы отслеживать его статус, перейдите в нашего бота и отправьте команду: /status_{$order_id}";

        // Уведомить клиента в TG если аккаунт привязан
        $client_chat_id = null;
        try {
            $lnk = $pdo->prepare("SELECT tg_chat_id FROM tg_links WHERE session_id = ? AND linked = TRUE LIMIT 1");
            $lnk->execute([session_id()]);
            $lnk_row = $lnk->fetch(PDO::FETCH_ASSOC);
            if ($lnk_row && $lnk_row['tg_chat_id']) {
                $client_chat_id = (int)$lnk_row['tg_chat_id'];
                $pdo->prepare("UPDATE orders SET client_chat_id = ? WHERE id = ?")->execute([$client_chat_id, $order_id]);
            }
        } catch (Throwable $e) {}

        if ($client_chat_id) {
            $pr = $pdo->prepare("SELECT title FROM prices WHERE category_key = ? LIMIT 1");
            $pr->execute([$service_key]);
            $srv_title = (string)($pr->fetchColumn() ?: $service_key);
            tgEscapeSend($bot_token, $client_chat_id,
                "⏳ *Ваш заказ \#{$order_id} создан\!*\n\nУслуга: " . tgEsc($srv_title) . "\nСтатус: ожидает рассмотрения \\(\\~5 мин\\)\\.\n\nКак только дизайнер примет — придёт уведомление\\."
            );
        }

        // Уведомить администратора
        if (!empty($my_chat_id)) {
            $price_stmt = $pdo->prepare("SELECT title, price_rub, price_uan FROM prices WHERE category_key = ? LIMIT 1");
            $price_stmt->execute([$service_key]);
            $price_info    = $price_stmt->fetch(PDO::FETCH_ASSOC);
            $service_title = $price_info['title'] ?? $service_key;
            $p_rub         = $price_info['price_rub'] ?? 0;
            $p_uan         = $price_info['price_uan'] ?? 0;

            $msg_text  = "⚡️ НОВЫЙ ЗАКАЗ #{$order_id} ⚡️\n\n";
            $msg_text .= "👤 Клиент: " . htmlspecialchars($username) . "\n";
            $msg_text .= "📞 Связь: "  . htmlspecialchars($telegram_raw) . "\n";
            $msg_text .= "🎨 Услуга: " . htmlspecialchars($service_title) . "\n";
            $msg_text .= "💰 Стоимость: {$p_rub}₽ / {$p_uan}₴\n";
            $msg_text .= "📝 ТЗ: "     . htmlspecialchars($details) . "\n";
            $msg_text .= "🌐 IP: {$user_ip}";

            $clean_tg = str_replace(['@', 'https://t.me/'], '', $telegram_raw);
            $keyboard = ['inline_keyboard' => [
                [
                    ['text' => '⏳ Взять в работу', 'callback_data' => "adm_work_{$order_id}"],
                    ['text' => '❌ Отклонить',       'callback_data' => "adm_dec_{$order_id}"],
                ],
                [
                    ['text' => '🔴 Срочный (24ч)', 'callback_data' => "adm_urgent_set_{$order_id}"],
                ],
                [
                    ['text' => '🚫 В чёрный список', 'callback_data' => "adm_ban_{$order_id}"],
                    ['text' => '💬 Написать клиенту', 'url' => "https://t.me/{$clean_tg}"],
                ],
            ]];

            $media    = [];
            $host_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                        . '://' . $_SERVER['HTTP_HOST']
                        . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';

            if (!empty($pay_screenshot) && file_exists($target_dir . $pay_screenshot)) {
                $media[] = ['type' => 'photo', 'media' => $host_url . $target_dir . $pay_screenshot];
            }
            foreach ($example_imgs as $ref) {
                if (file_exists($target_dir . $ref)) {
                    $media[] = ['type' => 'photo', 'media' => $host_url . $target_dir . $ref];
                }
            }

            if (!empty($media)) {
                $media[0]['caption']    = $msg_text;
                $media[0]['parse_mode'] = 'Markdown';
                $ch = curl_init("https://api.telegram.org/bot{$bot_token}/sendMediaGroup");
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $my_chat_id, 'media' => json_encode($media)])]);
                curl_exec($ch); curl_close($ch);
                $ch = curl_init("https://api.telegram.org/bot{$bot_token}/sendMessage");
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $my_chat_id,
                        'text' => "🎛 Управление заказом #{$order_id}:",
                        'parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)])]);
                curl_exec($ch); curl_close($ch);
            } else {
                $ch = curl_init("https://api.telegram.org/bot{$bot_token}/sendMessage");
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $my_chat_id,
                        'text' => $msg_text, 'parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)])]);
                curl_exec($ch); curl_close($ch);
            }
        }

    } catch (PDOException $e) {
        $error_msg = "❌ Ошибка БД: " . $e->getMessage();
    }
}

function tgEsc(string $text): string {
    return str_replace(
        ['_','*','[',']','(',')', '~','`','>','#','+','-','=','|','{','}','.','!'],
        ['\_','\*','\[','\]','\(','\)','\~','\`','\>','\#','\+','\-','\=','\|','\{','\}','\.', '\!'],
        $text
    );
}
function tgEscapeSend(string $token, int $chat_id, string $text): void {
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'chat_id'    => $chat_id,
        'text'       => $text,
        'parse_mode' => 'MarkdownV2',
    ]));
    curl_exec($ch);
    curl_close($ch);
}

render_page:
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Заполнить ТЗ для работы | Kostlim Design</title>
<link rel="stylesheet" href="style.css">
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<style>
:root {
    --or: #f97316;
    --or2: #fb923c;
    --or-glow: 0 0 18px rgba(249,115,22,0.45), 0 0 40px rgba(249,115,22,0.18);
    --or-glow-sm: 0 0 10px rgba(249,115,22,0.5);
}
body::before {
    content: '';
    position: fixed;
    top: -80px; left: 50%;
    transform: translateX(-50%);
    width: 600px; height: 320px;
    background: radial-gradient(ellipse, rgba(249,115,22,0.12) 0%, transparent 70%);
    pointer-events: none; z-index: 0;
}
.order-wrap { max-width: 560px; margin: 50px auto; padding: 0 20px; position: relative; z-index: 1; }
.order-card { background: #111116; border: 1px solid #1f1f2a; padding: 32px; border-radius: 18px; box-shadow: 0 20px 60px rgba(0,0,0,.5); }
.order-back { color: var(--or); text-decoration: none; font-size: 13px; font-weight: 700; display:inline-flex; align-items:center; gap:6px; margin-bottom: 18px; transition: opacity .2s; }
.order-back:hover { opacity: .75; }
.order-back svg { width:13px; height:13px; }
.order-title { text-align: center; font-size: 19px; font-weight: 900; text-transform: uppercase; letter-spacing: 1.5px; color: #fff; margin-bottom: 26px; }
.order-label { display: block; color: #8a8a96; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
.order-input, .order-select, .order-textarea {
    width: 100%; background: #16161f; border: 1px solid #262633; color: #fff;
    padding: 12px 14px; border-radius: 9px; font-size: 13px; font-family: inherit;
    transition: border-color .2s, box-shadow .2s; outline: none; box-sizing: border-box;
}
.order-input:focus, .order-select:focus, .order-textarea:focus {
    border-color: var(--or);
    box-shadow: 0 0 0 3px rgba(249,115,22,.14), var(--or-glow-sm);
}
.order-textarea { height: 110px; resize: vertical; }
.order-select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238a8a96' stroke-width='2.5'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; cursor: pointer;
}
.file-upload-block { border: 1.5px dashed #2a2a3a; border-radius: 12px; padding: 16px 18px; transition: border-color .2s, box-shadow .2s; background: rgba(249,115,22,0.025); }
.file-upload-block:hover { border-color: rgba(249,115,22,0.45); box-shadow: 0 0 14px rgba(249,115,22,0.12); }
.file-upload-block input[type="file"] { display: none; }
.file-label-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.file-label-title { color: #d8d8e0; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; flex: 1; }
.file-choose-btn {
    display: inline-flex; align-items: center; gap: 7px;
    background: linear-gradient(135deg, var(--or2), var(--or));
    border: none; border-radius: 8px; padding: 9px 16px; color: #fff;
    font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .7px;
    cursor: pointer; transition: all .2s; box-shadow: 0 4px 14px rgba(249,115,22,0.3);
    font-family: inherit; white-space: nowrap;
}
.file-choose-btn:hover { transform: translateY(-1px); box-shadow: var(--or-glow); }
.file-choose-btn svg { width: 13px; height: 13px; flex-shrink: 0; }
.file-name-display { font-size: 11px; color: #666678; font-style: italic; margin-top: 8px; min-height: 16px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.file-name-display.has-file { color: #86efac; font-style: normal; font-weight: 700; }
.file-hint { color: #555568; font-size: 10px; margin-top: 6px; line-height: 1.5; }
.turnstile-wrap { display: flex; justify-content: center; margin-bottom: 18px; }
.order-submit {
    width: 100%; background: linear-gradient(135deg, var(--or2), var(--or));
    color: #fff; border: none; padding: 15px; border-radius: 10px; font-weight: 900; cursor: pointer;
    text-transform: uppercase; font-size: 13px; letter-spacing: 1.5px;
    box-shadow: var(--or-glow); transition: opacity .2s, transform .2s, box-shadow .2s;
    font-family: inherit; margin-top: 6px;
}
.order-submit:hover { opacity: .92; transform: translateY(-2px); box-shadow: 0 0 30px rgba(249,115,22,.65), 0 8px 28px rgba(249,115,22,.3); }
.req-block { background: #0e0e16; border: 1px solid #1e1e2c; border-radius: 14px; padding: 20px; margin-bottom: 22px; }
.req-block h3 { color: var(--or); font-size: 12px; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 14px; text-shadow: var(--or-glow-sm); }
.req-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.req-row:last-child { margin-bottom: 0; }
.req-icon { width: 34px; height: 34px; border-radius: 9px; background: #1a1a28; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; font-weight: 900; color: var(--or); border: 1px solid rgba(249,115,22,0.2); }
.req-info { flex: 1; min-width: 0; }
.req-info span { display: block; font-size: 9px; color: #666678; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; }
.req-val { color: #e0e0ec; font-size: 12px; font-family: monospace; word-break: break-all; }
.req-link { color: var(--or); text-decoration: none; font-size: 12px; font-weight: 700; }
.req-link:hover { text-shadow: var(--or-glow-sm); }
.copy-btn { background: #1a1a28; border: 1px solid #2a2a3a; color: #8a8a96; padding: 5px 10px; border-radius: 7px; cursor: pointer; font-size: 10px; font-weight: 800; transition: color .2s, border-color .2s, box-shadow .2s; white-space: nowrap; font-family: inherit; flex-shrink: 0; }
.copy-btn:hover { color: var(--or); border-color: var(--or); box-shadow: var(--or-glow-sm); }
.rules-card { background: #111116; border: 1px solid #1f1f2a; padding: 32px; border-radius: 18px; box-shadow: 0 20px 60px rgba(0,0,0,.5); }
.rules-agree-btn { display: block; background: linear-gradient(135deg, var(--or2), var(--or)); color: #fff; text-align: center; text-decoration: none; padding: 14px 16px; border-radius: 9px; font-weight: 900; text-transform: uppercase; font-size: 12px; letter-spacing: 1px; box-shadow: var(--or-glow); transition: opacity .2s, transform .2s; }
.rules-agree-btn:hover { opacity: .9; transform: translateY(-1px); }
.msg-success { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.35); color: #86efac; padding: 14px 16px; border-radius: 10px; text-align: center; margin-bottom: 20px; font-weight: 700; font-size: 13px; }
.msg-error { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.35); color: #fca5a5; padding: 14px 16px; border-radius: 10px; text-align: center; margin-bottom: 20px; font-size: 13px; }
.mb16 { margin-bottom: 16px; }
.mb22 { margin-bottom: 22px; }
</style>
</head>
<body>

<div class="order-wrap">

<?php if (!empty($success_msg)): ?>
<div class="msg-success">
    <?= htmlspecialchars($success_msg) ?>
    <div style="margin-top:10px;">
        <a href="<?= htmlspecialchars($bot_link) ?>" target="_blank"
           style="display:inline-block;background:linear-gradient(135deg,#fb923c,#f97316);color:#fff;padding:9px 20px;border-radius:8px;text-decoration:none;font-weight:800;font-size:12px;">
            🤖 Открыть бота
        </a>
    </div>
</div>
<?php endif; ?>
<?php if (!empty($error_msg)): ?>
<div class="msg-error"><?= htmlspecialchars($error_msg) ?></div>
<?php endif; ?>

<?php if (!$rules_accepted && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>

<div class="rules-card">
    <div style="text-align:center; margin-bottom:22px;">
        <a href="index.php" class="order-back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            На главную к портфолио
        </a>
        <h2 style="color:#fff; margin:14px 0 8px; text-transform:uppercase; letter-spacing:1px; font-size:20px;">Правила заказа</h2>
        <p style="color:#8a8a96; margin:0; line-height:1.55; font-size:13px;">Перед заполнением ТЗ подтвердите, что ознакомились с условиями.</p>
    </div>
    <ul style="display:grid; gap:12px; color:#e0e0ec; padding-left:20px; line-height:1.6; margin:0 0 26px; font-size:13px;">
        <li>Заказ выполняется в течение 5 дней. При предоплате 50% — вне очереди: сегодня или на следующий день.</li>
        <li>Деньги не возвращаются.</li>
        <li>Отслеживать статус заказа можно в боте: <a href="<?= htmlspecialchars($bot_link) ?>" target="_blank" style="color:var(--or); font-weight:700;">@kostlimdznbot</a>.</li>
        <li>По личным вопросам: <a href="<?= htmlspecialchars($support_tg) ?>" target="_blank" style="color:var(--or); font-weight:700;">@Perlo_ovka</a>.</li>
    </ul>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
        <a href="<?= htmlspecialchars($accept_url) ?>" class="rules-agree-btn">Согласиться</a>
        <a href="index.php" style="background:#171720; color:#fff; text-align:center; text-decoration:none; padding:14px 16px; border-radius:9px; font-weight:900; text-transform:uppercase; border:1px solid #2a2a38; font-size:12px; letter-spacing:.8px; display:block;">Отказаться</a>
    </div>
</div>

<?php else: ?>

<div class="order-card">
    <a href="index.php" class="order-back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        На главную к портфолио
    </a>
    <div class="order-title">📋 Заполнить ТЗ для работы</div>

    <div class="req-block">
        <h3>💳 Куда оплачивать</h3>
        <div class="req-row">
            <div class="req-icon">₽</div>
            <div class="req-info">
                <span>Рубли (DonationAlerts)</span>
                <a href="https://www.donationalerts.com/r/andrewkostdzn" target="_blank" class="req-link">donationalerts.com/r/andrewkostdzn</a>
            </div>
        </div>
        <div class="req-row">
            <div class="req-icon">₴</div>
            <div class="req-info">
                <span>Гривны (карта)</span>
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:2px;">
                    <span class="req-val">4874 0700 1036 9708</span>
                    <button class="copy-btn" onclick="copyText('4874070010369708','Номер карты скопирован!')">Копировать</button>
                </div>
            </div>
        </div>
        <div class="req-row">
            <div class="req-icon">₿</div>
            <div class="req-info">
                <span>Крипта (USDT TRC-20)</span>
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:2px;">
                    <span class="req-val" style="font-size:10px;">THMpgSQAPwEB9brstbD12EKPPTwnGoPxC2</span>
                    <button class="copy-btn" onclick="copyText('THMpgSQAPwEB9brstbD12EKPPTwnGoPxC2','Адрес скопирован!')">Копировать</button>
                </div>
            </div>
        </div>
    </div>

    <form action="" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="rules_accepted" value="1">

        <div class="mb16">
            <label class="order-label">Ваше имя / никнейм</label>
            <input type="text" name="username" required placeholder="Например: Влад" class="order-input">
        </div>

        <div class="mb16">
            <label class="order-label">Контакт для связи (Telegram @username — обязательно)</label>
            <input type="text" name="telegram" required placeholder="@username" class="order-input">
        </div>

        <div class="mb16">
            <label class="order-label">Что вас интересует?</label>
            <select name="service" class="order-select">
                <?php foreach ($services as $s): ?>
                <option value="<?= htmlspecialchars($s['category_key']) ?>"
                    <?= ($selected_service === $s['category_key']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s['title']) ?> (<?= $s['price_uan'] ?>₴ / <?= $s['price_rub'] ?>₽)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb16">
            <label class="order-label">Детали заказа (ТЗ, пожелания)</label>
            <textarea name="details" required placeholder="Опиши цвета, персонажей, текст, стиль..." class="order-textarea"></textarea>
        </div>

        <div class="file-upload-block mb16">
            <input type="file" name="screenshot" accept="image/*" id="file_screenshot">
            <div class="file-label-row">
                <span class="file-label-title">📸 Доказательство оплаты</span>
                <label for="file_screenshot" class="file-choose-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    Выбрать файл
                </label>
            </div>
            <div class="file-name-display" id="name_screenshot">Файл не выбран</div>
        </div>

        <div class="file-upload-block mb22">
            <input type="file" name="example_photos[]" accept="image/*" multiple id="file_refs">
            <div class="file-label-row">
                <span class="file-label-title">🖼️ Референсы (до 5 фото)</span>
                <label for="file_refs" class="file-choose-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    Выбрать файлы
                </label>
            </div>
            <div class="file-name-display" id="name_refs">Файлы не выбраны</div>
            <div class="file-hint">Зажми Ctrl (Win) или Cmd (Mac) чтобы выбрать несколько файлов</div>
        </div>

        <div class="turnstile-wrap">
            <div class="cf-turnstile"
                 data-sitekey="<?= htmlspecialchars($turnstile_site_key) ?>"
                 data-theme="dark"
                 data-size="normal">
            </div>
        </div>

        <button type="submit" class="order-submit">Отправить заказ Kostlim'у</button>
    </form>
</div>

<?php endif; ?>

</div>

<script>
document.getElementById('file_screenshot')?.addEventListener('change', function() {
    const el = document.getElementById('name_screenshot');
    if (this.files[0]) { el.textContent = '✅ ' + this.files[0].name; el.classList.add('has-file'); }
    else { el.textContent = 'Файл не выбран'; el.classList.remove('has-file'); }
});
document.getElementById('file_refs')?.addEventListener('change', function() {
    const el = document.getElementById('name_refs');
    if (this.files.length > 0) {
        el.textContent = '✅ ' + this.files.length + ' файл(а): ' + Array.from(this.files).map(f => f.name).join(', ');
        el.classList.add('has-file');
    } else { el.textContent = 'Файлы не выбраны'; el.classList.remove('has-file'); }
});
function copyText(text, msg) {
    navigator.clipboard.writeText(text).then(() => {
        const toast = document.createElement('div');
        toast.textContent = '✅ ' + msg;
        Object.assign(toast.style, { position:'fixed', bottom:'30px', left:'50%', transform:'translateX(-50%)', background:'linear-gradient(135deg,#fb923c,#f97316)', color:'#fff', padding:'10px 22px', borderRadius:'9px', fontWeight:'800', fontSize:'13px', boxShadow:'0 0 20px rgba(249,115,22,.6)', zIndex:'9999', transition:'opacity .4s', fontFamily:'inherit' });
        document.body.appendChild(toast);
        setTimeout(() => { toast.style.opacity='0'; setTimeout(()=>toast.remove(),400); }, 2000);
    });
}
</script>
</body>
</html>