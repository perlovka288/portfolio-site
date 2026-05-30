<?php
session_start();
require_once 'config/db.php';

$bot_token = "8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg";
$my_chat_id = "1710365896"; 
$bot_link = 'https://t.me/kostlimdznbot';
$support_tg = 'https://t.me/Perlo_ovka';

$selected_service = $_GET['service'] ?? '';
$services = $pdo->query("SELECT title, category_key, price_uan, price_rub FROM prices ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$success_msg = '';
$error_msg = '';
$rules_accepted = (($_GET['accepted'] ?? '') === '1') || (($_POST['rules_accepted'] ?? '') === '1');
$accept_params = [];
if ($selected_service !== '') {
    $accept_params['service'] = $selected_service;
}
$accept_params['accepted'] = '1';
$accept_url = 'order.php?' . http_build_query($accept_params);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$rules_accepted) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $telegram = $_POST['telegram'] ?? '';
    $service_key = $_POST['service'] ?? '';
    $details = $_POST['details'] ?? '';
    
    $pay_screenshot = '';
    $example_img = '';
    $target_dir = 'uploads/orders/';
    
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    if (!empty($_FILES['screenshot']['name'])) {
        $ext = pathinfo($_FILES['screenshot']['name'], PATHINFO_EXTENSION);
        $pay_screenshot = 'pay_' . time() . '_' . uniqid() . '.' . $ext;
        move_uploaded_file($_FILES['screenshot']['tmp_name'], $target_dir . $pay_screenshot);
    }
    
    if (!empty($_FILES['example_photo']['name'])) {
        $ext = pathinfo($_FILES['example_photo']['name'], PATHINFO_EXTENSION);
        $example_img = 'ref_' . time() . '_' . uniqid() . '.' . $ext;
        move_uploaded_file($_FILES['example_photo']['tmp_name'], $target_dir . $example_img);
    }

    try {
        // Запись в базу со статусом 'pending' (Ожидает)
        $stmt = $pdo->prepare("INSERT INTO orders (username, telegram, service_key, details, screenshot, example_photo, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->execute([$username, $telegram, $service_key, $details, $pay_screenshot, $example_img]);
        $order_id = $pdo->lastInsertId();
        
        // Инструкция для отслеживания
        $success_msg = "🚀 Заказ #{$order_id} отправлен! Чтобы отслеживать его статус, перейдите в нашего бота и отправьте команду: /status_{$order_id}";

        if (!empty($my_chat_id)) {
            $price_stmt = $pdo->prepare("SELECT title, price_rub, price_uan FROM prices WHERE category_key = ? LIMIT 1");
            $price_stmt->execute([$service_key]);
            $price_info = $price_stmt->fetch(PDO::FETCH_ASSOC);
            
            $service_title = $price_info['title'] ?? $service_key;
            $p_rub = $price_info['price_rub'] ?? 0;
            $p_uan = $price_info['price_uan'] ?? 0;

            $msg_text = "⚡️ **ПОСТУПИЛ НОВЫЙ ЗАКАЗ #{$order_id}** ⚡️\n\n";
            $msg_text .= "👤 **Клиент:** " . htmlspecialchars($username) . "\n";
            $msg_text .= "📞 **Связь:** " . htmlspecialchars($telegram) . "\n";
            $msg_text .= "🎨 **Услуга:** " . htmlspecialchars($service_title) . "\n";
            $msg_text .= "💰 **Стоимость:** {$p_rub}₽ / {$p_uan}₴\n";
            $msg_text .= "⏳ **Дедлайн:** 5 дней\n";
            $msg_text .= "📝 **ТЗ:** " . htmlspecialchars($details) . "\n";

            $clean_tg = str_replace(['@', 'https://t.me/'], '', $telegram);
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '⏳ Взять в работу', 'callback_data' => "adm_work_{$order_id}"],
                        ['text' => '❌ Отклонить', 'callback_data' => "adm_dec_{$order_id}"]
                    ],
                    [['text' => '💬 Написать клиенту', 'url' => "https://t.me/{$clean_tg}"]]
                ]
            ];

            $media = [];
            $host_url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . "/";

            if (!empty($pay_screenshot) && file_exists($target_dir . $pay_screenshot)) {
                $media[] = ['type' => 'photo', 'media' => $host_url . $target_dir . $pay_screenshot];
            }
            if (!empty($example_img) && file_exists($target_dir . $example_img)) {
                $media[] = ['type' => 'photo', 'media' => $host_url . $target_dir . $example_img];
            }

            if (!empty($media)) {
                $media[0]['caption'] = $msg_text;
                $media[0]['parse_mode'] = 'Markdown';
                
                $ch = curl_init("https://api.telegram.org/bot{$bot_token}/sendMediaGroup");
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['chat_id' => $my_chat_id, 'media' => json_encode($media)]));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_exec($ch);
                curl_close($ch);

                $ch = curl_init("https://api.telegram.org/bot{$bot_token}/sendMessage");
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                    'chat_id' => $my_chat_id, 
                    'text' => "🎛 **Управление заказом #{$order_id}:**", 
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode($keyboard)
                ]));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_exec($ch);
                curl_close($ch);
            } else {
                $ch = curl_init("https://api.telegram.org/bot{$bot_token}/sendMessage");
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                    'chat_id' => $my_chat_id,
                    'text' => $msg_text,
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode($keyboard)
                ]));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_exec($ch);
                curl_close($ch);
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
    <title>Заполнить ТЗ для работы | Kostlim Design</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container" style="max-width: 550px; margin: 60px auto; padding: 0 20px;">
    
    <?php if (!empty($success_msg)) { ?>
        <div style="background: rgba(0,255,163,0.1); border: 1px solid #00ffa3; color: #00ffa3; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 20px; font-weight: bold;">
            <?php echo $success_msg; ?>
        </div>
    <?php } ?>

    <?php if (!empty($error_msg)) { ?>
        <div style="background: rgba(239,68,68,0.1); border: 1px solid #ef4444; color: #ef4444; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 20px;">
            <?php echo $error_msg; ?>
        </div>
    <?php } ?>

    <?php if (!$rules_accepted && $_SERVER['REQUEST_METHOD'] !== 'POST') { ?>
    <div class="rules-card" style="background: #111116; border: 1px solid #2a2a38; padding: 30px; border-radius: 16px; box-shadow: 0 18px 45px rgba(0,0,0,.28);">
        <div style="text-align: center; margin-bottom: 22px;">
            <a href="index.php" style="color: #a95851; text-decoration: none; font-size: 13px;">← На главную к портфолио</a>
            <h2 style="color:#fff; margin: 14px 0 8px; text-transform: uppercase; letter-spacing: 1px;">Правила заказа</h2>
            <p style="color:#8a8a93; margin:0; line-height:1.55;">Перед заполнением ТЗ подтвердите, что вы ознакомились с условиями.</p>
        </div>
        <ul style="display:grid; gap:12px; color:#e8e8f0; padding-left:20px; line-height:1.55; margin:0 0 24px;">
            <li>Заказ выполняется в течение 5 дней. При предоплате 50% от цены заказ выполняется вне очереди: сегодня или на следующий день.</li>
            <li>Деньги не возвращаются.</li>
            <li>Отслеживать статус заказа можно в боте: <a href="<?php echo htmlspecialchars($bot_link); ?>" target="_blank" style="color:#c76860;">@kostlimdznbot</a>.</li>
            <li>По личным вопросам: <a href="<?php echo htmlspecialchars($support_tg); ?>" target="_blank" style="color:#c76860;">@Perlo_ovka</a>.</li>
        </ul>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
            <a href="<?php echo htmlspecialchars($accept_url); ?>" style="background:#a95851; color:#fff; text-align:center; text-decoration:none; padding:14px 16px; border-radius:8px; font-weight:900; text-transform:uppercase;">Согласиться</a>
            <a href="index.php" style="background:#171720; color:#fff; text-align:center; text-decoration:none; padding:14px 16px; border-radius:8px; font-weight:900; text-transform:uppercase; border:1px solid #2a2a38;">Отказаться</a>
        </div>
    </div>
    <?php } else { ?>
    <div style="background: #111116; border: 1px solid #1f1f2a; padding: 30px; border-radius: 16px;">
        <div style="text-align: center; margin-bottom: 25px;">
            <a href="index.php" style="color: #a95851; text-decoration: none; font-size: 13px;">← На главную к портфолио</a>
            <h2 style="color: #fff; margin-top: 10px; text-transform: uppercase; letter-spacing: 1px;">📋 Заполнить ТЗ для работы</h2>
        </div>

        <form action="" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="rules_accepted" value="1">
            <div style="margin-bottom: 16px;">
                <label style="color: #8a8a93; font-size: 12px; font-weight: bold; text-transform: uppercase;">Ваше имя / никнейм</label>
                <input type="text" name="username" required placeholder="Например: Влад" style="background: #16161f; border: 1px solid #262633; color: #fff; padding: 12px; border-radius: 8px; width: 100%; box-sizing: border-box; margin-top: 6px;">
            </div>

            <div style="margin-bottom: 16px;">
                <label style="color: #8a8a93; font-size: 12px; font-weight: bold; text-transform: uppercase;">Контакт для связи (Telegram `@username` обязательно)</label>
                <input type="text" name="telegram" required placeholder="@username" style="background: #16161f; border: 1px solid #262633; color: #fff; padding: 12px; border-radius: 8px; width: 100%; box-sizing: border-box; margin-top: 6px;">
            </div>

            <div style="margin-bottom: 16px;">
                <label style="color: #8a8a93; font-size: 12px; font-weight: bold; text-transform: uppercase;">Что вас интересует?</label>
                <select name="service" style="background: #16161f; border: 1px solid #262633; color: #fff; padding: 12px; border-radius: 8px; width: 100%; box-sizing: border-box; margin-top: 6px;">
                    <?php foreach($services as $s) { ?>
                        <option value="<?php echo $s['category_key']; ?>" <?php echo ($selected_service === $s['category_key']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['title']); ?> (<?php echo $s['price_uan']; ?>₴ / <?php echo $s['price_rub']; ?>₽)
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="color: #8a8a93; font-size: 12px; font-weight: bold; text-transform: uppercase;">Детали заказа (ТЗ, пожелания)</label>
                <textarea name="details" required placeholder="Опиши цвета, персонажей, текст, стиль..." style="background: #16161f; border: 1px solid #262633; color: #fff; padding: 12px; border-radius: 8px; width: 100%; box-sizing: border-box; height: 100px; resize: none; margin-top: 6px;"></textarea>
            </div>

            <div style="margin-bottom: 16px; border: 1px dashed #262633; padding: 12px; border-radius: 8px;">
                <label style="color: #fff; font-size: 12px; font-weight: bold;">📸 Доказательство оплаты (скриншот):</label>
                <input type="file" name="screenshot" accept="image/*" style="margin-top: 5px; color: #8a8a93; font-size: 12px;">
            </div>

            <div style="margin-bottom: 24px; border: 1px dashed #262633; padding: 12px; border-radius: 8px;">
                <label style="color: #fff; font-size: 12px; font-weight: bold;">🖼️ Фото примеров / референс:</label>
                <input type="file" name="example_photo" accept="image/*" style="margin-top: 5px; color: #8a8a93; font-size: 12px;">
            </div>

            <button type="submit" style="background: #a95851; color: #fff; border: none; padding: 15px; border-radius: 8px; width: 100%; font-weight: bold; cursor: pointer; text-transform: uppercase; font-size: 14px; letter-spacing: 0.5px;">
                Отправить заказ Kostlim'у
            </button>
        </form>
    </div>
    <?php } ?>
</div>
</body>
</html>
