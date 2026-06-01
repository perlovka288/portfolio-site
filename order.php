<?php
session_start();
require_once 'config/db.php';

$bot_token   = "8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg";
$my_chat_id  = "1710365896";
$bot_link    = 'https://t.me/kostlimdznbot';
$support_tg  = 'https://t.me/Perlo_ovka';

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
    $username    = $_POST['username']    ?? '';
    $telegram    = $_POST['telegram']    ?? '';
    $service_key = $_POST['service']     ?? '';
    $details     = $_POST['details']     ?? '';

    $pay_screenshot = '';
    $example_imgs   = [];

    $target_dir = 'uploads/orders/';
    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

    // Скриншот оплаты
    if (!empty($_FILES['screenshot']['name'])) {
        $ext = pathinfo($_FILES['screenshot']['name'], PATHINFO_EXTENSION);
        $pay_screenshot = 'pay_' . time() . '_' . uniqid() . '.' . $ext;
        move_uploaded_file($_FILES['screenshot']['tmp_name'], $target_dir . $pay_screenshot);
    }

    // Референсы (до 5 файлов)
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
            (username, telegram, service_key, details, screenshot, example_photo, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->execute([$username, $telegram, $service_key, $details, $pay_screenshot, $example_img_json]);
        $order_id = $pdo->lastInsertId();

        $success_msg = "🚀 Заказ #{$order_id} отправлен! Чтобы отслеживать его статус, перейдите в нашего бота и отправьте команду: /status_{$order_id}";

        // Отправка в Telegram
        if (!empty($my_chat_id)) {
            $price_stmt = $pdo->prepare("SELECT title, price_rub, price_uan FROM prices WHERE category_key = ? LIMIT 1");
            $price_stmt->execute([$service_key]);
            $price_info    = $price_stmt->fetch(PDO::FETCH_ASSOC);
            $service_title = $price_info['title'] ?? $service_key;
            $p_rub         = $price_info['price_rub'] ?? 0;
            $p_uan         = $price_info['price_uan'] ?? 0;

            $msg_text  = "⚡️ **НОВЫЙ ЗАКАЗ #{$order_id}** ⚡️\n\n";
            $msg_text .= "👤 **Клиент:** " . htmlspecialchars($username) . "\n";
            $msg_text .= "📞 **Связь:** " . htmlspecialchars($telegram) . "\n";
            $msg_text .= "🎨 **Услуга:** " . htmlspecialchars($service_title) . "\n";
            $msg_text .= "💰 **Стоимость:** {$p_rub}₽ / {$p_uan}₴\n";
            $msg_text .= "📝 **ТЗ:** " . htmlspecialchars($details) . "\n";

            $clean_tg = str_replace(['@', 'https://t.me/'], '', $telegram);
            $keyboard = ['inline_keyboard' => [
                [
                    ['text' => '⏳ Взять в работу', 'callback_data' => "adm_work_{$order_id}"],
                    ['text' => '❌ Отклонить',       'callback_data' => "adm_dec_{$order_id}"]
                ],
                [['text' => '💬 Написать клиенту', 'url' => "https://t.me/{$clean_tg}"]]
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
                        'text' => "🎛 **Управление заказом #{$order_id}:**",
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
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Заполнить ТЗ для работы | Kostlim Design</title>
<link rel="stylesheet" href="style.css">
<style>
/* ─── Оранжевое свечение для формы ─── */
:root {
    --orange: #ff6a1a;
    --orange-glow: 0 0 12px rgba(255, 106, 26, 0.55), 0 0 28px rgba(255, 106, 26, 0.22);
    --orange-glow-sm: 0 0 8px rgba(255, 106, 26, 0.45);
}

.order-input,
.order-select,
.order-textarea {
    background: #16161f;
    border: 1px solid #262633;
    color: #fff;
    padding: 12px 14px;
    border-radius: 8px;
    width: 100%;
    box-sizing: border-box;
    margin-top: 6px;
    font-size: 14px;
    transition: border-color .2s, box-shadow .2s;
    outline: none;
}
.order-input:focus,
.order-select:focus,
.order-textarea:focus {
    border-color: var(--orange);
    box-shadow: var(--orange-glow);
}
.order-textarea { height: 110px; resize: none; }

.order-label {
    color: #8a8a93;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .8px;
    display: block;
}

.order-submit {
    background: var(--orange);
    color: #fff;
    border: none;
    padding: 15px;
    border-radius: 8px;
    width: 100%;
    font-weight: 900;
    cursor: pointer;
    text-transform: uppercase;
    font-size: 14px;
    letter-spacing: 1px;
    box-shadow: var(--orange-glow);
    transition: opacity .2s, box-shadow .2s;
}
.order-submit:hover {
    opacity: .9;
    box-shadow: 0 0 20px rgba(255,106,26,.75), 0 0 40px rgba(255,106,26,.3);
}

.file-drop {
    border: 1.5px dashed #2e2e3e;
    padding: 14px;
    border-radius: 10px;
    transition: border-color .2s;
    cursor: pointer;
}
.file-drop:hover { border-color: var(--orange); }
.file-drop input[type=file] { color: #8a8a93; font-size: 12px; width: 100%; }

/* ─── Блок реквизитов ─── */
.req-block {
    background: #0e0e16;
    border: 1px solid #1f1f2e;
    border-radius: 14px;
    padding: 22px;
    margin-bottom: 22px;
}
.req-block h3 {
    color: var(--orange);
    font-size: 13px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin: 0 0 16px;
    text-shadow: var(--orange-glow-sm);
}
.req-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
}
.req-icon {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: #1a1a28;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 16px;
}
.req-info { flex: 1; }
.req-info span {
    display: block;
    font-size: 10px;
    color: #6a6a7a;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .6px;
}
.req-val {
    color: #e8e8f0;
    font-size: 13px;
    font-family: monospace;
    word-break: break-all;
}
.req-link {
    color: var(--orange);
    text-decoration: none;
    font-size: 13px;
    font-weight: 700;
}
.req-link:hover { text-shadow: var(--orange-glow-sm); }

.copy-btn {
    background: #1a1a28;
    border: 1px solid #2a2a38;
    color: #8a8a93;
    padding: 6px 10px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 11px;
    transition: color .2s, border-color .2s, box-shadow .2s;
    white-space: nowrap;
}
.copy-btn:hover {
    color: var(--orange);
    border-color: var(--orange);
    box-shadow: var(--orange-glow-sm);
}

/* ─── Правила ─── */
.rules-agree-btn {
    display: block;
    background: var(--orange);
    color: #fff;
    text-align: center;
    text-decoration: none;
    padding: 14px 16px;
    border-radius: 8px;
    font-weight: 900;
    text-transform: uppercase;
    box-shadow: var(--orange-glow);
    transition: opacity .2s;
}
.rules-agree-btn:hover { opacity: .88; }
</style>
</head>
<body>
<div class="container" style="max-width:560px;margin:60px auto;padding:0 20px;">

<?php if (!empty($success_msg)): ?>
<div style="background:rgba(0,255,163,.1);border:1px solid #00ffa3;color:#00ffa3;padding:16px;border-radius:10px;text-align:center;margin-bottom:22px;font-weight:700;">
    <?= htmlspecialchars($success_msg) ?>
</div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
<div style="background:rgba(239,68,68,.1);border:1px solid #ef4444;color:#ef4444;padding:16px;border-radius:10px;text-align:center;margin-bottom:22px;">
    <?= htmlspecialchars($error_msg) ?>
</div>
<?php endif; ?>

<?php if (!$rules_accepted && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
<!-- ──────────────── Правила ──────────────── -->
<div style="background:#111116;border:1px solid #2a2a38;padding:30px;border-radius:16px;box-shadow:0 18px 45px rgba(0,0,0,.28);">
    <div style="text-align:center;margin-bottom:22px;">
        <a href="index.php" style="color:#ff6a1a;text-decoration:none;font-size:13px;">← На главную к портфолио</a>
        <h2 style="color:#fff;margin:14px 0 8px;text-transform:uppercase;letter-spacing:1px;">Правила заказа</h2>
        <p style="color:#8a8a93;margin:0;line-height:1.55;">Перед заполнением ТЗ подтвердите, что ознакомились с условиями.</p>
    </div>
    <ul style="display:grid;gap:12px;color:#e8e8f0;padding-left:20px;line-height:1.55;margin:0 0 24px;">
        <li>Заказ выполняется в течение 5 дней. При предоплате 50% — вне очереди: сегодня или на следующий день.</li>
        <li>Деньги не возвращаются.</li>
        <li>Отслеживать статус заказа можно в боте: <a href="<?= htmlspecialchars($bot_link) ?>" target="_blank" style="color:var(--orange);">@kostlimdznbot</a>.</li>
        <li>По личным вопросам: <a href="<?= htmlspecialchars($support_tg) ?>" target="_blank" style="color:var(--orange);">@Perlo_ovka</a>.</li>
    </ul>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <a href="<?= htmlspecialchars($accept_url) ?>" class="rules-agree-btn">Согласиться</a>
        <a href="index.php" style="background:#171720;color:#fff;text-align:center;text-decoration:none;padding:14px 16px;border-radius:8px;font-weight:900;text-transform:uppercase;border:1px solid #2a2a38;">Отказаться</a>
    </div>
</div>

<?php else: ?>
<!-- ──────────────── Форма ──────────────── -->
<div style="background:#111116;border:1px solid #1f1f2a;padding:30px;border-radius:16px;">
    <div style="text-align:center;margin-bottom:26px;">
        <a href="index.php" style="color:var(--orange);text-decoration:none;font-size:13px;">← На главную к портфолио</a>
        <h2 style="color:#fff;margin:10px 0 0;text-transform:uppercase;letter-spacing:1px;">📋 Заполнить ТЗ для работы</h2>
    </div>

    <!-- ─── РЕКВИЗИТЫ ─── -->
    <div class="req-block">
        <h3>💳 Куда оплачивать</h3>

        <!-- Рубли -->
        <div class="req-row">
            <div class="req-icon">₽</div>
            <div class="req-info">
                <span>Рубли (DonationAlerts)</span>
                <a href="https://www.donationalerts.com/r/andrewkostdzn" target="_blank" class="req-link">
                    donationalerts.com/r/andrewkostdzn
                </a>
            </div>
        </div>

        <!-- Гривны -->
        <div class="req-row">
            <div class="req-icon">₴</div>
            <div class="req-info">
                <span>Гривны (карта)</span>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <span class="req-val" id="card-num">4874 0700 1036 9708</span>
                    <button class="copy-btn" onclick="copyText('4874070010369708','Номер карты скопирован!')">Копировать</button>
                </div>
            </div>
        </div>

        <!-- Крипта -->
        <div class="req-row">
            <div class="req-icon">₿</div>
            <div class="req-info">
                <span>Крипта (USDT TRC-20)</span>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <span class="req-val" id="crypto-addr" style="font-size:11px;">THMpgSQAPwEB9brstbD12EKPPTwnGoPxC2</span>
                    <button class="copy-btn" onclick="copyText('THMpgSQAPwEB9brstbD12EKPPTwnGoPxC2','Адрес скопирован!')">Копировать</button>
                </div>
            </div>
        </div>
    </div>
    <!-- /РЕКВИЗИТЫ -->

    <form action="" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="rules_accepted" value="1">

        <div style="margin-bottom:16px;">
            <label class="order-label">Ваше имя / никнейм</label>
            <input type="text" name="username" required placeholder="Например: Влад" class="order-input">
        </div>

        <div style="margin-bottom:16px;">
            <label class="order-label">Контакт для связи (Telegram @username — обязательно)</label>
            <input type="text" name="telegram" required placeholder="@username" class="order-input">
        </div>

        <div style="margin-bottom:16px;">
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

        <div style="margin-bottom:16px;">
            <label class="order-label">Детали заказа (ТЗ, пожелания)</label>
            <textarea name="details" required placeholder="Опиши цвета, персонажей, текст, стиль..." class="order-textarea"></textarea>
        </div>

        <div class="file-drop" style="margin-bottom:16px;">
            <label class="order-label" style="margin-bottom:6px;display:block;">📸 Доказательство оплаты (скриншот)</label>
            <input type="file" name="screenshot" accept="image/*">
        </div>

        <div class="file-drop" style="margin-bottom:24px;">
            <label class="order-label" style="margin-bottom:6px;display:block;">🖼️ Референсы (до 5 фото)</label>
            <input type="file" name="example_photos[]" accept="image/*" multiple>
            <span style="color:#5a5a6a;font-size:11px;display:block;margin-top:5px;">Зажми Ctrl (Win) или Cmd (Mac) чтобы выбрать несколько файлов</span>
        </div>

        <button type="submit" class="order-submit">Отправить заказ Kostlim'у</button>
    </form>
</div>
<?php endif; ?>

</div>

<script>
function copyText(text, msg) {
    navigator.clipboard.writeText(text).then(() => {
        const toast = document.createElement('div');
        toast.textContent = '✅ ' + msg;
        Object.assign(toast.style, {
            position:'fixed', bottom:'30px', left:'50%', transform:'translateX(-50%)',
            background:'#ff6a1a', color:'#fff', padding:'10px 20px',
            borderRadius:'8px', fontWeight:'700', fontSize:'13px',
            boxShadow:'0 0 18px rgba(255,106,26,.6)', zIndex:'9999',
            transition:'opacity .4s'
        });
        document.body.appendChild(toast);
        setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 400); }, 2000);
    });
}
</script>
</body>
</html>