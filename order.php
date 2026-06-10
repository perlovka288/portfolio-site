<?php
require_once 'includes/session.php';
require_once 'config/db.php';

// AUTO-LINK: Если клиент перешёл с TG по нашей ссылке — привязываем его TG автоматически
processTgAutoLink($pdo);

// ── Гарантируем существование таблицы правил ────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_rules (
        id SERIAL PRIMARY KEY,
        rule_key VARCHAR(100) UNIQUE NOT NULL,
        rule_text TEXT NOT NULL DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

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

$selected_service = $_POST['service'] ?? $_GET['service'] ?? '';
$services = $pdo->query("SELECT title, category_key, price_uan, price_rub FROM prices ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// ── TG: статус привязки для текущей сессии ──────────────────────
$linkCode = null;
$isLinked = false;
try {
    $sid  = session_id();
    $stmt_lnk = $pdo->prepare("SELECT site_code, linked FROM tg_links WHERE session_id = ? ORDER BY id DESC LIMIT 1");
    $stmt_lnk->execute([$sid]);
    $linkRow = $stmt_lnk->fetch(PDO::FETCH_ASSOC);
    if ($linkRow && $linkRow['linked']) {
        $isLinked = true;
    } elseif ($linkRow) {
        $linkCode = $linkRow['site_code'];
    } else {
        $code = strtoupper(substr(md5(uniqid($sid, true)), 0, 6));
        $pdo->prepare("INSERT INTO tg_links (site_code, session_id, linked, created_at) VALUES (?, ?, FALSE, NOW())")->execute([$code, $sid]);
        $linkCode = $code;
    }
} catch (Throwable $e) {
    $linkCode = null;
}

// ── AJAX: проверить статус привязки (polling с order.php) ────────
if (isset($_GET['check_linked'])) {
    header('Content-Type: application/json');
    $sid_chk = session_id();
    try {
        $stmt_chk = $pdo->prepare("SELECT linked FROM tg_links WHERE session_id = ? ORDER BY id DESC LIMIT 1");
        $stmt_chk->execute([$sid_chk]);
        $row_chk = $stmt_chk->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['linked' => !empty($row_chk['linked'])]);
    } catch (Throwable $e) {
        echo json_encode(['linked' => false]);
    }
    exit;
}

$success_msg = '';
$error_msg   = '';

$skip_rules = isset($_COOKIE['rules_skip']) && $_COOKIE['rules_skip'] === '1';
$rules_accepted = $skip_rules || (($_POST['rules_accepted'] ?? '') === '1');

// ── Загружаем правила из БД (сохранённые через админку) ──────────────────
$orderRulesHtml = '';
try {
    $rulesRow = $pdo->query("SELECT rule_text FROM site_rules WHERE rule_key = 'order_terms' LIMIT 1")->fetch();
    if ($rulesRow && !empty(trim($rulesRow['rule_text']))) {
        // Фильтруем разрешённые теги: <b>,<i>,<br>,<a>
        $orderRulesHtml = strip_tags($rulesRow['rule_text'], '<b><i><br><a>');
    }
} catch (Throwable $e) {}
// Если в БД пусто — используем дефолтный текст
if ($orderRulesHtml === '') {
    $orderRulesHtml = '<ul style="padding-left:20px;margin:0;line-height:1.7;font-size:13px;color:#e0e0ec;">'
        . '<li>Стандартный срок сдачи — <b>5 дней</b>.</li>'
        . '<li>Срочный заказ (24 часа): <b>+50%</b> к цене.</li>'
        . '<li>ТЗ должно быть <b>максимально подробным</b>.</li>'
        . '<li>По вопросам: <a href="https://t.me/Perlo_ovka" target="_blank" style="color:#f97316;font-weight:700;">@Perlo_ovka</a></li>'
        . '<li>Деньги не возвращаются.</li>'
        . '</ul>';
}

if (isset($_POST['accept_rules'])) {
    if (!empty($_POST['dont_ask'])) {
        setcookie('rules_skip', '1', time() + (3600 * 24 * 365), '/');
    }
    $rules_accepted = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['accept_rules']) && !$rules_accepted) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['accept_rules'])) {

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
    $cooperation = !empty($_POST['cooperation']) ? 1 : 0;

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
     (username, telegram, service_key, details, screenshot, example_photo, status, cooperation, client_ip, session_id, created_at)
     VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW()) RETURNING id");
     $stmt->execute([$username, $telegram_raw, $service_key, $details, $pay_screenshot, $example_img_json, $cooperation, $user_ip, session_id()]);
        $order_id = (int)$stmt->fetchColumn();
        if ($order_id <= 0) {
            $order_id = (int)$pdo->lastInsertId();
        }

        $success_msg = "🚀 Заказ #{$order_id} отправлен! Чтобы отслеживать его статус, перейдите в нашего бота и отправьте команду: /status_{$order_id}. Для оплаты перейдите на DonationAlerts и в поле 'Сообщение' обязательно укажите номер заказа: #{$order_id}.";

        // Уведомить клиента в TG — ищем chat_id тремя способами
        $client_chat_id = null;
        try {
            $sid_now = session_id();

            // Метод 0 (приоритет): tg_chat_id из сессии (автопривязка через ?tg_token=...)
            if (!empty($_SESSION['tg_chat_id'])) {
                $client_chat_id = (int)$_SESSION['tg_chat_id'];
                // Сразу пишем в orders
                if ($client_chat_id) {
                    $pdo->prepare("UPDATE orders SET client_chat_id=? WHERE id=?")->execute([$client_chat_id, $order_id]);
                }
            }

            // Метод 1: по session_id текущей сессии
            if ($sid_now !== '') {
                $lnk = $pdo->prepare("
                    SELECT COALESCE(NULLIF(tg_chat_id,''), NULLIF(CAST(tg_id AS VARCHAR),'')) AS chat_id
                    FROM tg_links WHERE session_id = ? AND linked = TRUE
                    ORDER BY id DESC LIMIT 1
                ");
                $lnk->execute([$sid_now]);
                $lnk_row = $lnk->fetch(PDO::FETCH_ASSOC);
                if (!empty($lnk_row['chat_id']) && is_numeric($lnk_row['chat_id'])) {
                    $client_chat_id = (int)$lnk_row['chat_id'];
                }
            }

            // Метод 2: по telegram username из формы
            if (!$client_chat_id && !empty($telegram_raw)) {
                $tg_clean = ltrim(trim(str_replace(['https://t.me/', 'http://t.me/', 't.me/'], '', $telegram_raw)), '@');
                if ($tg_clean !== '') {
                    $lnk2 = $pdo->prepare("
                        SELECT COALESCE(NULLIF(tg_chat_id,''), NULLIF(CAST(tg_id AS VARCHAR),'')) AS chat_id
                        FROM tg_links WHERE (tg_username = ? OR tg_username = ?) AND linked = TRUE
                        ORDER BY id DESC LIMIT 1
                    ");
                    $lnk2->execute([$tg_clean, '@' . $tg_clean]);
                    $lnk2_row = $lnk2->fetch(PDO::FETCH_ASSOC);
                    if (!empty($lnk2_row['chat_id']) && is_numeric($lnk2_row['chat_id'])) {
                        $client_chat_id = (int)$lnk2_row['chat_id'];
                    }
                }
            }

            // Метод 3: telegram поле выглядит как числовой ID
            if (!$client_chat_id && !empty($telegram_raw) && is_numeric(trim($telegram_raw))) {
                $client_chat_id = (int)trim($telegram_raw);
            }

            // Метод 4: getChat через Telegram API (работает если пользователь писал боту)
            if (!$client_chat_id && !empty($telegram_raw)) {
                $tg_clean = ltrim(trim(str_replace(['https://t.me/', 'http://t.me/', 't.me/'], '', $telegram_raw)), '@');
                if ($tg_clean !== '') {
                    $ch = curl_init("https://api.telegram.org/bot{$bot_token}/getChat");
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 5,
                        CURLOPT_POSTFIELDS => ['chat_id' => '@' . $tg_clean],
                    ]);
                    $resp = curl_exec($ch);
                    curl_close($ch);
                    $data = json_decode((string)$resp, true);
                    if (!empty($data['ok']) && !empty($data['result']['id'])) {
                        $client_chat_id = (int)$data['result']['id'];
                        // Сохраняем в tg_links для будущих заказов
                        try {
                            $pdo->prepare("
                                UPDATE tg_links SET tg_id = CAST(? AS VARCHAR)
                                WHERE tg_username = ? AND (tg_id IS NULL OR tg_id = '')
                            ")->execute([$client_chat_id, $tg_clean]);
                        } catch (Throwable $e) {}
                    }
                }
            }

            if ($client_chat_id) {
                $pdo->prepare("UPDATE orders SET client_chat_id = ? WHERE id = ?")->execute([$client_chat_id, $order_id]);
            }
        } catch (Throwable $e) {}

        if ($client_chat_id) {
            $pr = $pdo->prepare("SELECT title FROM prices WHERE category_key = ? LIMIT 1");
            $pr->execute([$service_key]);
            $srv_title = (string)($pr->fetchColumn() ?: $service_key);
            tgEscapeSend($bot_token, $client_chat_id,
                "✅ *Заказ \#{$order_id} создан\!*\n\n🎨 Услуга: " . tgEsc($srv_title) . "\n📋 Статус: ожидает рассмотрения\n\nКак только дизайнер примет заказ — придёт уведомление сюда\."
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
            if ($cooperation) {
                $msg_text .= "💼 Сотрудничество: да (при принятии цена 0₽ / 0₴)\n";
                $msg_text .= "💰 Стоимость: 0₽ / 0₴\n";
            } else {
                $msg_text .= "💰 Стоимость: {$p_rub}₽ / {$p_uan}₴\n";
            }
            $msg_text .= "📝 ТЗ: "     . htmlspecialchars($details) . "\n";
            $msg_text .= "🌐 IP: {$user_ip}";

            $clean_tg = str_replace(['@', 'https://t.me/'], '', $telegram_raw);
            $keyboard = ['inline_keyboard' => [
                [
                    ['text' => '🚀 Взять в работу', 'callback_data' => "adm_work_{$order_id}"],
                    ['text' => '❌ Отклонить',       'callback_data' => "adm_dec_{$order_id}"],
                ],
                [
                    ['text' => '⚡️ Срочный (24ч)', 'callback_data' => "adm_urgent_{$order_id}"],
                ],
                [
                    ['text' => '🚫 В чёрный список', 'callback_data' => "adm_ban_{$order_id}"],
                    ['text' => '💬 Написать клиенту', 'url' => "https://t.me/{$clean_tg}"],
                ],
            ]];

            // Сначала отправляем основное сообщение с текстом и кнопками
            $ch = curl_init("https://api.telegram.org/bot{$bot_token}/sendMessage");
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'chat_id'      => $my_chat_id,
                    'text'         => $msg_text,
                    'parse_mode'   => 'Markdown',
                    'reply_markup' => json_encode($keyboard),
                ])]);
            curl_exec($ch); curl_close($ch);

            // Потом отдельно отправляем фото (через upload, не по URL — надёжнее на Render)
            $photos_to_send = [];
            if (!empty($pay_screenshot) && file_exists($target_dir . $pay_screenshot)) {
                $photos_to_send[] = $target_dir . $pay_screenshot;
            }
            foreach ($example_imgs as $ref) {
                if ($ref !== '' && file_exists($target_dir . $ref)) {
                    $photos_to_send[] = $target_dir . $ref;
                }
            }
            foreach ($photos_to_send as $photo_path) {
                $ch = curl_init("https://api.telegram.org/bot{$bot_token}/sendPhoto");
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POSTFIELDS => [
                        'chat_id' => $my_chat_id,
                        'photo'   => new CURLFile(realpath($photo_path)),
                        'caption' => '📎 Файл к заказу #' . $order_id,
                    ]]);
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
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer>
// ── TG Banner polling ──
var bannerPollInterval = null;

function copyBannerCode() {
    var el = document.getElementById('bannerCode');
    if (!el) return;
    var text = el.textContent.trim();
    navigator.clipboard.writeText(text).then(function() {
        var btn = document.querySelector('.tg-banner-copy');
        if (btn) { btn.textContent = '✅ Скопировано'; setTimeout(function(){ btn.textContent = 'Копировать'; }, 2000); }
    }).catch(function() {
        var tmp = document.createElement('textarea');
        tmp.value = text; document.body.appendChild(tmp);
        tmp.select(); document.execCommand('copy'); document.body.removeChild(tmp);
        var btn = document.querySelector('.tg-banner-copy');
        if (btn) { btn.textContent = '✅ Скопировано'; setTimeout(function(){ btn.textContent = 'Копировать'; }, 2000); }
    });
}

function startBannerPolling() {
    var w = document.getElementById('bannerWaiting');
    if (w) w.classList.add('show');
    if (bannerPollInterval) clearInterval(bannerPollInterval);
    bannerPollInterval = setInterval(checkBannerLinked, 3000);
}

function checkBannerLinked() {
    fetch('order.php?check_linked=1')
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.linked) {
                clearInterval(bannerPollInterval);
                var banner = document.getElementById('tgBanner');
                if (banner) {
                    banner.innerHTML = '<div class="tg-banner-icon" style="background:rgba(34,197,94,0.15);border-color:rgba(34,197,94,0.4);"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div><div class="tg-banner-body"><div class="tg-banner-title" style="color:#86efac;">✅ Telegram привязан! Уведомления придут в бот</div></div>';
                    banner.classList.add('tg-banner-linked');
                    banner.style.borderColor = 'rgba(34,197,94,0.4)';
                }
            }
        })
        .catch(function(){});
}
</script>
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
.rules-agree-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
    box-shadow: none;
    filter: grayscale(0.5);
    transform: none;
}
.msg-success { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.35); color: #86efac; padding: 14px 16px; border-radius: 10px; text-align: center; margin-bottom: 20px; font-weight: 700; font-size: 13px; }
.msg-error { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.35); color: #fca5a5; padding: 14px 16px; border-radius: 10px; text-align: center; margin-bottom: 20px; font-size: 13px; }
.mb16 { margin-bottom: 16px; }
.mb22 { margin-bottom: 22px; }

/* ══ TG BANNER ══ */
.tg-banner {
    display: flex;
    align-items: center;
    gap: 14px;
    background: linear-gradient(135deg, rgba(249,115,22,0.1), rgba(249,115,22,0.04));
    border: 1px solid rgba(249,115,22,0.45);
    border-radius: 13px;
    padding: 14px 16px;
    margin-bottom: 22px;
    flex-wrap: wrap;
    position: relative;
}
.tg-banner-linked {
    background: linear-gradient(135deg, rgba(34,197,94,0.08), rgba(34,197,94,0.03));
    border-color: rgba(34,197,94,0.35);
}
.tg-banner-icon {
    width: 42px; height: 42px; flex-shrink: 0;
    background: rgba(249,115,22,0.15);
    border: 1px solid rgba(249,115,22,0.4);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
}
.tg-banner-body { flex: 1; min-width: 0; }
.tg-banner-title {
    color: #fdba74;
    font-size: 12px;
    font-weight: 800;
    margin-bottom: 6px;
    line-height: 1.4;
}
.tg-banner-code-row {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    margin-bottom: 4px;
}
.tg-banner-code {
    font-family: monospace;
    font-size: 14px;
    font-weight: 900;
    color: #fb923c;
    letter-spacing: 2px;
    background: rgba(0,0,0,0.3);
    padding: 3px 10px;
    border-radius: 6px;
    border: 1px solid rgba(249,115,22,0.25);
    user-select: all;
}
.tg-banner-copy {
    background: rgba(249,115,22,0.18);
    border: 1px solid rgba(249,115,22,0.4);
    border-radius: 6px;
    padding: 4px 10px;
    color: #fdba74;
    font-size: 11px;
    font-weight: 800;
    cursor: pointer;
    transition: .2s;
    font-family: inherit;
}
.tg-banner-copy:hover { background: rgba(249,115,22,0.35); color: #fff; }
.tg-banner-hint {
    color: #666678;
    font-size: 10px;
    margin-top: 2px;
}
.tg-banner-btn {
    display: inline-flex; align-items: center; gap: 6px;
    background: linear-gradient(135deg, #fb923c, #f97316);
    color: #fff;
    text-decoration: none;
    padding: 9px 16px;
    border-radius: 8px;
    font-weight: 900;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    box-shadow: 0 0 14px rgba(249,115,22,0.4);
    transition: opacity .2s, transform .2s;
    white-space: nowrap;
    flex-shrink: 0;
}
.tg-banner-btn:hover { opacity: .9; transform: translateY(-1px); }
.tg-banner-waiting {
    display: none;
    align-items: center;
    gap: 6px;
    color: #fdba74;
    font-size: 11px;
    font-weight: 700;
    width: 100%;
    margin-top: 6px;
}
.tg-banner-waiting.show { display: flex; }
.tg-spinner-sm {
    width: 12px; height: 12px;
    border: 2px solid rgba(249,115,22,0.3);
    border-top-color: #f97316;
    border-radius: 50%;
    animation: spin .8s linear infinite;
    flex-shrink: 0;
}
@keyframes spin { to { transform: rotate(360deg); } }
@media(max-width:520px) {
    .tg-banner { flex-direction: column; align-items: flex-start; gap: 10px; }
    .tg-banner-btn { width: 100%; justify-content: center; }
    .tg-banner-code { font-size: 12px; letter-spacing: 1px; }
}
</style>
</head>
<body>

<div class="order-wrap">

<?php if (!empty($success_msg)): ?>
<!-- Модальное окно подписки на уведомления -->
<div id="notify-modal" style="
    position:fixed;inset:0;z-index:9999;
    display:flex;align-items:center;justify-content:center;
    background:rgba(0,0,0,.75);backdrop-filter:blur(6px);
    animation:fadeInBg .3s ease;
">
    <div style="
        background:linear-gradient(145deg,#13131a,#1a1a24);
        border:1px solid rgba(249,115,22,.35);
        border-radius:20px;padding:32px 28px;
        max-width:400px;width:calc(100% - 32px);
        box-shadow:0 24px 80px rgba(0,0,0,.6),0 0 60px rgba(249,115,22,.1);
        text-align:center;
        animation:slideUp .35s cubic-bezier(.34,1.56,.64,1);
        position:relative;
    ">
        <!-- Закрыть -->
        <button onclick="closeNotifyModal()" style="
            position:absolute;top:14px;right:14px;
            background:rgba(255,255,255,.07);border:none;border-radius:50%;
            width:30px;height:30px;cursor:pointer;color:#8a8a96;font-size:16px;
            display:flex;align-items:center;justify-content:center;
        ">✕</button>

        <!-- Конфетти эмодзи -->
        <div style="font-size:48px;margin-bottom:12px;line-height:1;">🎉</div>

        <div style="font-size:18px;font-weight:900;color:#fff;margin-bottom:6px;">
            Заказ #<?= (int)$order_id ?> отправлен!
        </div>
        <div style="font-size:13px;color:#8a8a96;margin-bottom:24px;line-height:1.6;">
            Дизайнер уже получил уведомление и скоро приступит к работе.
        </div>

        <!-- Блок подписки -->
        <div style="
            background:rgba(34,158,217,.08);
            border:1px solid rgba(34,158,217,.25);
            border-radius:14px;padding:18px;margin-bottom:20px;
        ">
            <div style="font-size:13px;font-weight:800;color:#60a5fa;margin-bottom:8px;">
                🔔 Подпишись на уведомления
            </div>
            <div style="font-size:12px;color:#a0a0b8;margin-bottom:16px;line-height:1.6;">
                Нажми кнопку — бот сразу сообщит когда дизайнер возьмёт заказ в работу и когда он будет готов.
            </div>
            <a href="https://t.me/kostlimdznbot?start=order_<?= (int)$order_id ?>" target="_blank"
               onclick="closeNotifyModal()"
               style="
                display:flex;align-items:center;justify-content:center;gap:10px;
                background:linear-gradient(135deg,#229ED9,#1a7fc1);
                color:#fff;padding:14px 20px;border-radius:10px;
                text-decoration:none;font-weight:900;font-size:14px;
                box-shadow:0 8px 24px rgba(34,158,217,.4);
                transition:transform .15s,box-shadow .15s;
               "
               onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 12px 30px rgba(34,158,217,.5)'"
               onmouseout="this.style.transform='';this.style.boxShadow='0 8px 24px rgba(34,158,217,.4)'"
            >
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.562 8.248l-2.04 9.61c-.152.678-.554.843-1.122.524l-3.1-2.284-1.496 1.44c-.165.165-.304.304-.624.304l.223-3.164 5.754-5.196c.25-.222-.054-.345-.387-.123L7.06 14.4l-3.056-.955c-.664-.207-.677-.664.138-.983l11.927-4.598c.553-.2 1.037.135.493 2.384z"/></svg>
                Получать уведомления в Telegram
            </a>
        </div>

        <div style="font-size:11px;color:#555568;line-height:1.5;">
            💰 Для оплаты укажи <strong style="color:#8a8a96;">#<?= (int)$order_id ?></strong> в сообщении на DonationAlerts
        </div>

        <button onclick="closeNotifyModal()" style="
            margin-top:16px;background:transparent;border:1px solid rgba(255,255,255,.1);
            border-radius:8px;padding:8px 20px;color:#555568;font-size:12px;
            cursor:pointer;font-family:inherit;transition:.2s;
        "
        onmouseover="this.style.borderColor='rgba(255,255,255,.25)';this.style.color='#8a8a96'"
        onmouseout="this.style.borderColor='rgba(255,255,255,.1)';this.style.color='#555568'"
        >Закрыть</button>
    </div>
</div>

<style>
@keyframes fadeInBg  { from{opacity:0} to{opacity:1} }
@keyframes slideUp   { from{opacity:0;transform:translateY(30px) scale(.95)} to{opacity:1;transform:translateY(0) scale(1)} }
</style>

<script>
function closeNotifyModal() {
    var m = document.getElementById('notify-modal');
    if (m) { m.style.opacity='0'; m.style.transition='opacity .2s'; setTimeout(function(){ m.remove(); }, 200); }
}
// Закрытие по клику на фон
document.getElementById('notify-modal').addEventListener('click', function(e) {
    if (e.target === this) closeNotifyModal();
});
</script>
<?php endif; ?>
<?php if (!empty($error_msg)): ?>
<div class="msg-error"><?= htmlspecialchars($error_msg) ?></div>
<?php endif; ?>

<?php if (!$rules_accepted): ?>

<form method="POST" class="rules-card">
    <?php if ($selected_service): ?>
        <input type="hidden" name="service" value="<?= htmlspecialchars($selected_service) ?>">
    <?php endif; ?>
    <div style="text-align:center; margin-bottom:22px;">
        <a href="index.php" class="order-back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            На главную к портфолио
        </a>
        <h2 style="color:#fff; margin:14px 0 8px; text-transform:uppercase; letter-spacing:1px; font-size:20px;">Правила заказа</h2>
        <p style="color:#8a8a96; margin:0; line-height:1.55; font-size:13px;">Пожалуйста, прокрутите правила до конца, чтобы кнопка стала активной.</p>
    </div>
    <div id="rules-scroll" style="max-height: 160px; overflow-y: auto; margin-bottom: 20px; padding-right: 10px; border-bottom: 1px solid #1f1f2a; scrollbar-width: thin;">
        <!-- Правила из БД (редактируются в Админке → Правила) -->
        <div style="color:#e0e0ec; font-size:13px; line-height:1.65;">
            <?= $orderRulesHtml ?>
        </div>
    </div>
    <div style="margin-bottom: 20px;">
        <label style="display:flex; align-items:center; gap:10px; color:#8a8a96; font-size:13px; cursor:pointer; user-select:none;">
            <input type="checkbox" name="dont_ask" value="1" style="width:auto; margin:0; accent-color:var(--or);">
            Больше не спрашивать
        </label>
    </div>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
        <button type="submit" id="agree-btn" name="accept_rules" class="rules-agree-btn" style="border:none; cursor:pointer; font-family:inherit;" disabled>Согласиться</button>
        <a href="index.php" style="background:#171720; color:#fff; text-align:center; text-decoration:none; padding:14px 16px; border-radius:9px; font-weight:900; text-transform:uppercase; border:1px solid #2a2a38; font-size:12px; letter-spacing:.8px; display:block;">Отказаться</a>
    </div>
</form>

<?php else: ?>

<div class="order-card">
    <!-- ══ TG ПЛАШКА — ОБЯЗАТЕЛЬНАЯ ══ -->
    <?php if (!$isLinked): ?>
    <div class="tg-banner" id="tgBanner">
        <div class="tg-banner-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fb923c" stroke-width="2.2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        </div>
        <div class="tg-banner-body">
            <div class="tg-banner-title">Привяжи Telegram — получай уведомления о заказе</div>
            <?php if ($linkCode): ?>
            <div class="tg-banner-code-row">
                <span class="tg-banner-code" id="bannerCode">/customer_<?= htmlspecialchars($linkCode) ?></span>
                <button class="tg-banner-copy" onclick="copyBannerCode()">Копировать</button>
            </div>
            <div class="tg-banner-hint">1. Скопируй код &nbsp;→&nbsp; 2. Открой бот &nbsp;→&nbsp; 3. Отправь код в чат</div>
            <?php endif; ?>
        </div>
        <a href="https://t.me/kostlimdznbot?start=link_<?= htmlspecialchars($linkCode ?? '') ?>"
           target="_blank" class="tg-banner-btn" id="bannerOpenBtn" onclick="startBannerPolling()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
            Открыть бот
        </a>
        <div class="tg-banner-waiting" id="bannerWaiting">
            <span class="tg-spinner-sm"></span> Ожидаю…
        </div>
    </div>
    <?php else: ?>
    <div class="tg-banner tg-banner-linked">
        <div class="tg-banner-icon" style="background:rgba(34,197,94,0.15);border-color:rgba(34,197,94,0.4);">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="tg-banner-body">
            <div class="tg-banner-title" style="color:#86efac;">✅ Telegram привязан — уведомления придут в бот</div>
        </div>
    </div>
    <?php endif; ?>


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
            <label class="order-label" style="display:flex;align-items:center;gap:12px;cursor:pointer;">
                <input type="checkbox" name="cooperation" value="1" style="width:auto;margin:0;">
                <span>Сотрудничество — если приму такой заказ, то цена будет 0 ₽ / 0 ₴</span>
            </label>
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
document.addEventListener('DOMContentLoaded', function() {
    const rulesScroll = document.getElementById('rules-scroll');
    const agreeBtn = document.getElementById('agree-btn');
    
    if (rulesScroll && agreeBtn) {
        const checkScroll = () => {
            // Если контент влезает без прокрутки или прокручен до конца (с погрешностью 10px)
            if (rulesScroll.scrollHeight <= rulesScroll.clientHeight || 
                Math.abs(rulesScroll.scrollHeight - rulesScroll.clientHeight - rulesScroll.scrollTop) < 10) {
                agreeBtn.disabled = false;
            }
        };

        rulesScroll.addEventListener('scroll', checkScroll);
        checkScroll(); // Проверка при загрузке (на случай если текста мало)
    }
});

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