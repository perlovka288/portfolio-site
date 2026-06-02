<?php

require_once __DIR__ . '/config/db.php';

$token    = getenv('BOT_TOKEN') ?: "8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg";
$admin_id = getenv('ADMIN_ID')  ?: "1710365896";
$site_url = "https://portfolio-site-boo5.onrender.com/";
$support_tg = "@Perlo_ovka";

$input  = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) { exit; }

// ── CALLBACK QUERY ──────────────────────────────────────────────
if (isset($update['callback_query'])) {
    $callback_id   = $update['callback_query']['id'];
    $cal_chat_id   = $update['callback_query']['message']['chat']['id'];
    $msg_id        = $update['callback_query']['message']['message_id'];
    $callback_data = $update['callback_query']['data'] ?? '';

    if ((string)$cal_chat_id !== $admin_id) {
        sendTelegram($token, 'answerCallbackQuery', [
            'callback_query_id' => $callback_id,
            'text'              => '⛔ Доступ закрыт',
            'show_alert'        => true,
        ]);
        exit;
    }

    if ($callback_data === 'adm_show_queue') {
        showAdminQueue($pdo, $token, $admin_id, $site_url);
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id]);
        exit;
    }

    if ($callback_data === 'adm_urgent') {
        showUrgentQueue($pdo, $token, $admin_id, $site_url);
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id]);
        exit;
    }

    if (strpos($callback_data, 'adm_view_') === 0) {
        $order_id = (int)str_replace('adm_view_', '', $callback_data);
        showAdminOrderDetails($pdo, $token, $admin_id, $site_url, $order_id);
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "Открываю заказ #{$order_id}"]);
        exit;
    }

    if ($callback_data === 'adm_stats') {
        sendAdminStats($pdo, $token, $admin_id);
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id]);
        exit;
    }

    // ── Взять в работу ────────────────────────────────────────────
    if (strpos($callback_data, 'adm_work_') === 0) {
        $order_id = (int)str_replace('adm_work_', '', $callback_data);
        $pdo->prepare("UPDATE orders SET status = 'in_progress' WHERE id = ?")->execute([$order_id]);
        sendTelegram($token, 'editMessageText', [
            'chat_id'      => $cal_chat_id,
            'message_id'   => $msg_id,
            'text'         => "🚀 *Заказ \#{$order_id} взят в работу\.*",
            'parse_mode'   => 'MarkdownV2',
            'reply_markup' => orderKeyboard($order_id, 'in_progress', getOrderTelegram($pdo, $order_id)),
        ]);
        notifyClient($pdo, $token, $order_id,
            "⏳ Ваш заказ *\#{$order_id}* принят в работу\\!\n\nДизайнер уже начал выполнение\\. Ожидайте готовности\\.\n\nПо вопросам: {$support_tg}"
        );
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Заказ взят в работу']);
        exit;
    }

    // ── Готов ─────────────────────────────────────────────────────
    if (strpos($callback_data, 'adm_ready_') === 0) {
        $order_id = (int)str_replace('adm_ready_', '', $callback_data);
        $pdo->prepare("UPDATE orders SET status = 'ready' WHERE id = ?")->execute([$order_id]);
        sendTelegram($token, 'editMessageText', [
            'chat_id'    => $cal_chat_id,
            'message_id' => $msg_id,
            'text'       => "✅ *Заказ \#{$order_id} выполнен\.*",
            'parse_mode' => 'MarkdownV2',
        ]);
        notifyClient($pdo, $token, $order_id,
            "✅ Ваш заказ *\#{$order_id}* готов\\!\n\nДизайнер скоро свяжется с вами для передачи финальных файлов\\.\n\nПо вопросам: {$support_tg}"
        );
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Заказ выполнен']);
        exit;
    }

    // ── Отклонить ─────────────────────────────────────────────────
    if (strpos($callback_data, 'adm_dec_') === 0) {
        $order_id = (int)str_replace('adm_dec_', '', $callback_data);
        $pdo->prepare("UPDATE orders SET status = 'declined' WHERE id = ?")->execute([$order_id]);
        sendTelegram($token, 'editMessageText', [
            'chat_id'    => $cal_chat_id,
            'message_id' => $msg_id,
            'text'       => "❌ *Заказ \#{$order_id} отклонён\.*",
            'parse_mode' => 'MarkdownV2',
        ]);
        notifyClient($pdo, $token, $order_id,
            "❌ К сожалению, ваш заказ *\#{$order_id}* был отклонён дизайнером\\.\n\nПо вопросам: {$support_tg}"
        );
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Заказ отклонён']);
        exit;
    }

    // ── Сделать срочным ───────────────────────────────────────────
    if (strpos($callback_data, 'adm_urgent_set_') === 0) {
        $order_id = (int)str_replace('adm_urgent_set_', '', $callback_data);
        $pdo->prepare("UPDATE orders SET is_urgent = 1 WHERE id = ?")->execute([$order_id]);
        sendTelegram($token, 'editMessageText', [
            'chat_id'      => $cal_chat_id,
            'message_id'   => $msg_id,
            'text'         => "🔴 *Заказ \#{$order_id} переведён в СРОЧНЫЕ* \\(24 часа\\)\\.",
            'parse_mode'   => 'MarkdownV2',
            'reply_markup' => orderKeyboard($order_id, 'in_progress', getOrderTelegram($pdo, $order_id)),
        ]);
        notifyClient($pdo, $token, $order_id,
            "🔴 Ваш заказ *\#{$order_id}* принят в срочное выполнение\\!\n\nСрок выполнения: *24 часа*\\.\n\nПо вопросам: {$support_tg}"
        );
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '🔴 Заказ переведён в срочные']);
        exit;
    }

    // ── Бан клиента ───────────────────────────────────────────────
    if (strpos($callback_data, 'adm_ban_') === 0) {
        $order_id = (int)str_replace('adm_ban_', '', $callback_data);
        $order = $pdo->prepare("SELECT telegram, client_ip FROM orders WHERE id = ? LIMIT 1");
        $order->execute([$order_id]);
        $o = $order->fetch(PDO::FETCH_ASSOC);

        if ($o) {
            $tg_clean = ltrim(str_replace(['https://t.me/', 'http://t.me/', '@'], '', $o['telegram'] ?? ''), '@');
            try {
                $pdo->prepare("
                    INSERT INTO blacklist (telegram, ip, order_id, reason, created_at)
                    VALUES (?, ?, ?, 'Бан из Telegram-панели', NOW())
                    ON CONFLICT DO NOTHING
                ")->execute([$tg_clean ?: null, $o['client_ip'] ?: null, $order_id]);

                sendTelegram($token, 'editMessageText', [
                    'chat_id'    => $cal_chat_id,
                    'message_id' => $msg_id,
                    'text'       => "🚫 *Клиент заблокирован\!*\nЗаказ \#{$order_id} — @{$tg_clean}",
                    'parse_mode' => 'MarkdownV2',
                ]);
                sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '🚫 Добавлен в чёрный список']);
            } catch (PDOException $e) {
                sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Ошибка: ' . $e->getMessage(), 'show_alert' => true]);
            }
        }
        exit;
    }

    sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id]);
    exit;
}

// ── MESSAGE ──────────────────────────────────────────────────────
if (isset($update['message'])) {
    $chat_id  = $update['message']['chat']['id'];
    $text     = trim($update['message']['text'] ?? '');
    $text_key = normalizeBotText($text);

    botLog("message chat={$chat_id} text={$text} key={$text_key}");

    try {

    // ── /start — привязка через deep link + поддержка /start@botname ──
    $text_cmd_only = explode('@', $text)[0];
    if ($text_cmd_only === '/start' || strpos($text, '/start ') === 0 || $text === '/start') {
        $parts = explode(' ', $text, 2);
        $param = $parts[1] ?? '';

        // deep link: /start link_XXXXXX
        if (strpos($param, 'link_') === 0) {
            $code = strtoupper(substr($param, 5));
            $stmt = $pdo->prepare("SELECT id, linked FROM tg_links WHERE site_code = ? LIMIT 1");
            $stmt->execute([$code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && !$row['linked']) {
                $pdo->prepare("UPDATE tg_links SET tg_chat_id = ?, linked = TRUE WHERE site_code = ?")->execute([$chat_id, $code]);
                sendTelegram($token, 'sendMessage', [
                    'chat_id'      => $chat_id,
                    'text'         => "✅ *Аккаунт успешно привязан\!*\n\nТеперь вы будете получать уведомления о статусе ваших заказов прямо здесь\\.\n\nКнопка *«Личный кабинет»* появилась в меню\\.",
                    'parse_mode'   => 'MarkdownV2',
                    'reply_markup' => mainKeyboard(false, true),
                ]);
                exit;
            } elseif ($row && $row['linked']) {
                sendTelegram($token, 'sendMessage', [
                    'chat_id' => $chat_id,
                    'text'    => "ℹ️ Этот код уже был использован. Если нужна новая привязка — зайдите на сайт и получите новый код.",
                ]);
                exit;
            } else {
                sendTelegram($token, 'sendMessage', [
                    'chat_id' => $chat_id,
                    'text'    => "❌ Код не найден. Зайдите на сайт и скопируйте актуальный код.\n\nНажмите кнопку ниже чтобы перейти на сайт и получить код.",
                    'reply_markup' => ['inline_keyboard' => [[['text' => '🌐 Открыть сайт', 'url' => $site_url]]]],
                ]);
                exit;
            }
        }

        // обычный /start
        sendTelegram($token, 'sendMessage', [
            'chat_id'      => $chat_id,
            'text'         => "👋 Привет! Добро пожаловать в *Kostlim Design*\\!\n\nЗдесь можно посмотреть портфолио, узнать прайс, отправить ТЗ и следить за статусом заказа\\.",
            'parse_mode'   => 'MarkdownV2',
            'reply_markup' => mainKeyboard((string)$chat_id === $admin_id, isLinked($pdo, $chat_id)),
        ]);
        exit;
    }

    if ($text === '/menu' || explode('@', $text)[0] === '/menu') {
        sendTelegram($token, 'sendMessage', [
            'chat_id'      => $chat_id,
            'text'         => "👋 Главное меню *Kostlim Design*",
            'parse_mode'   => 'MarkdownV2',
            'reply_markup' => mainKeyboard((string)$chat_id === $admin_id, isLinked($pdo, $chat_id)),
        ]);
        exit;
    }

    // ── Ввод кода привязки (команда /customer_XXXXXX) ──────────────
    if (preg_match('/^\/customer_([A-Z0-9]{6})$/i', $text, $m)) {
        $code = strtoupper($m[1]);
        $stmt = $pdo->prepare("SELECT id, linked FROM tg_links WHERE site_code = ? LIMIT 1");
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !$row['linked']) {
            $pdo->prepare("UPDATE tg_links SET tg_chat_id = ?, linked = TRUE WHERE site_code = ?")->execute([$chat_id, $code]);
            sendTelegram($token, 'sendMessage', [
                'chat_id'      => $chat_id,
                'text'         => "✅ *Отлично\\! Аккаунт привязан\!*\n\nТеперь вы будете получать уведомления о заказах\\.\nКнопка *«Личный кабинет»* доступна в меню\\.",
                'parse_mode'   => 'MarkdownV2',
                'reply_markup' => mainKeyboard(false, true),
            ]);
        } elseif ($row && $row['linked']) {
            sendTelegram($token, 'sendMessage', [
                'chat_id' => $chat_id,
                'text'    => "ℹ️ Этот код уже использован. Зайдите на сайт за новым кодом.",
            ]);
        } else {
            sendTelegram($token, 'sendMessage', [
                'chat_id' => $chat_id,
                'text'    => "❌ Код не найден. Проверь правильность кода на сайте.",
            ]);
        }
        exit;
    }

    // ── Портфолио ──────────────────────────────────────────────────
    if ($text_key === 'смотреть portfolio' || $text_key === 'portfolio' || $text_key === 'портфолио') {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chat_id,
            'text'    => "🎨 Портфолио Kostlim Design:\n{$site_url}",
        ]);
        exit;
    }

    // ── Прайс-лист ─────────────────────────────────────────────────
    if ($text_key === 'прайс-лист' || $text_key === 'прайс лист' || $text_key === 'прайс') {
        $prices = $pdo->query("SELECT title, description, features, price_uan, price_rub, image FROM prices ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $price_msg = "📋 Актуальный прайс-лист:\n\n";
        foreach ($prices as $p) {
            $price_msg .= "▪️ {$p['title']}: {$p['price_rub']} ₽ / {$p['price_uan']} ₴\n";
            if (!empty($p['description'])) $price_msg .= "  {$p['description']}\n";
            foreach (explode('|', (string)($p['features'] ?? '')) as $f) {
                $f = trim($f);
                if ($f !== '') $price_msg .= "  • {$f}\n";
            }
            $price_msg .= "\n";
        }
        sendTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => $price_msg]);
        foreach ($prices as $p) {
            if (empty($p['image'])) continue;
            $path = __DIR__ . '/uploads/' . basename($p['image']);
            if (!is_file($path)) continue;
            sendTelegramFile($token, 'sendPhoto', [
                'chat_id' => $chat_id,
                'photo'   => new CURLFile($path),
                'caption' => "{$p['title']}: {$p['price_rub']} ₽ / {$p['price_uan']} ₴",
            ]);
        }
        exit;
    }

    // ── Сделать заказ ──────────────────────────────────────────────
    if ($text_key === 'сделать заказ' || $text_key === 'заказ') {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chat_id,
            'text'    => "🤖 Форма отправки ТЗ:\n{$site_url}order.php",
        ]);
        exit;
    }

    // ── Личный кабинет ─────────────────────────────────────────────
    if ($text_key === 'личный кабинет' || $text_key === 'мои заказы') {
        $linked = isLinked($pdo, $chat_id);
        if (!$linked) {
            sendTelegram($token, 'sendMessage', [
                'chat_id' => $chat_id,
                'text'    => "🔗 Сначала привяжите аккаунт на сайте.\n\nЗайдите на {$site_url} — там появится кнопка *«Подвязать Telegram»* с вашим кодом.",
                'parse_mode' => 'Markdown',
            ]);
            exit;
        }
        showCabinet($pdo, $token, $chat_id);
        exit;
    }

    // ── Статус заказа через команду ────────────────────────────────
    $text_cmd = explode('@', $text)[0];
    if (strpos($text_cmd, '/status_') === 0) {
        $order_id = (int)str_replace('/status_', '', $text_cmd);
        try {
            $pdo->prepare("UPDATE orders SET client_chat_id = ? WHERE id = ?")->execute([$chat_id, $order_id]);
        } catch (Throwable $e) {}

        $o_stmt = $pdo->prepare("
            SELECT o.id, o.status, o.created_at, o.details, o.username, o.telegram,
                   o.service_key, o.screenshot, o.example_photo,
                   p.title AS service_title, p.price_rub, p.price_uan
            FROM orders o LEFT JOIN prices p ON p.category_key = o.service_key
            WHERE o.id = ? LIMIT 1
        ");
        $o_stmt->execute([$order_id]);
        $order = $o_stmt->fetch(PDO::FETCH_ASSOC);

        if ($order) {
            $order['is_urgent'] = safeGetIsUrgent($pdo, $order_id);
            $status_icons = ['pending' => '⏳', 'in_progress' => '⌛', 'ready' => '✅', 'declined' => '❌'];
            $status_text  = ['pending' => 'Ожидает подтверждения', 'in_progress' => 'В работе', 'ready' => 'Готов', 'declined' => 'Отклонён'];
            $icon = $status_icons[$order['status']] ?? '❓';
            $stxt = $status_text[$order['status']] ?? $order['status'];

            $msg  = "📦 Заказ #{$order['id']}\n";
            $msg .= "Статус: {$icon} {$stxt}\n";
            $msg .= "Услуга: " . ($order['service_title'] ?? $order['service_key']) . "\n";
            $msg .= "Создан: {$order['created_at']}\n";
            if (!empty($order['is_urgent'])) $msg .= "🔴 СРОЧНЫЙ заказ\n";
            sendTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => $msg]);
        } else {
            sendTelegram($token, 'sendMessage', [
                'chat_id' => $chat_id,
                'text'    => "❌ Заказ #{$order_id} не найден.",
            ]);
        }
        exit;
    }

    // ── Админ панель ───────────────────────────────────────────────
    if ($text === '/admin' || $text_key === 'admin panel' || $text_key === 'admin' || $text_key === '💻 admin panel') {
        if ((string)$chat_id !== $admin_id) {
            sendTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => '⛔ Доступ закрыт.']);
            exit;
        }
        sendTelegram($token, 'sendMessage', [
            'chat_id'      => $admin_id,
            'text'         => "⚙️ Режим администратора\n\nВыбери действие:",
            'reply_markup' => adminReplyKeyboard(),
        ]);
        exit;
    }

    if ($text_key === 'главное меню' || $text_key === '🔙 главное меню') {
        if ((string)$chat_id !== $admin_id) { exit; }
        sendTelegram($token, 'sendMessage', [
            'chat_id'      => $admin_id,
            'text'         => "🏠 Главное меню",
            'reply_markup' => mainKeyboard(true, false),
        ]);
        exit;
    }

    if ($text_key === 'очередь заказов' || $text_key === '🗂️ очередь заказов') {
        if ((string)$chat_id !== $admin_id) { exit; }
        showAdminQueue($pdo, $token, $admin_id, $site_url);
        exit;
    }

    if ($text_key === 'срочные заказы' || $text_key === '🔴 срочные заказы') {
        if ((string)$chat_id !== $admin_id) { exit; }
        showUrgentQueue($pdo, $token, $admin_id, $site_url);
        exit;
    }

    if ($text_key === 'статистика' || $text_key === '📊 статистика') {
        if ((string)$chat_id !== $admin_id) { exit; }
        sendAdminStats($pdo, $token, $admin_id);
        exit;
    }

    if ($text_key === 'открыть сайт' || $text_key === '🌐 открыть сайт') {
        if ((string)$chat_id !== $admin_id) { exit; }
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $admin_id,
            'text'    => "🌐 Ссылка на сайт:\n{$site_url}admin/index.php",
        ]);
        exit;
    }

    // ── Fallback ───────────────────────────────────────────────────
    sendTelegram($token, 'sendMessage', [
        'chat_id'      => $chat_id,
        'text'         => "Не понял команду. Нажми /menu, чтобы открыть кнопки.",
        'reply_markup' => mainKeyboard((string)$chat_id === $admin_id, isLinked($pdo, $chat_id)),
    ]);

    } catch (Throwable $e) {
        botLog("MESSAGE HANDLER ERROR: " . $e->getMessage() . " | text=" . ($text ?? ''));
    }
}

// ════════════════════════════════════════════════════════════════
//  FUNCTIONS
// ════════════════════════════════════════════════════════════════

/** Проверить, привязан ли tg_chat_id */
function isLinked(PDO $pdo, $chat_id): bool {
    try {
        $stmt = $pdo->prepare("SELECT id FROM tg_links WHERE tg_chat_id = ? AND linked = TRUE LIMIT 1");
        $stmt->execute([$chat_id]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

// ══ БАГ 2 ИСПРАВЛЕН ══════════════════════════════════════════════
// showCabinet теперь ищет заказы через tg_links по tg_chat_id,
// а НЕ только по client_chat_id (который NULL у большинства клиентов)
function showCabinet(PDO $pdo, $token, $chat_id): void {
    $status_icons = ['pending' => '⏳', 'in_progress' => '⌛', 'ready' => '✅', 'declined' => '❌'];
    $status_text  = ['pending' => 'Ожидает', 'in_progress' => 'В работе', 'ready' => 'Готов', 'declined' => 'Отклонён'];

    try {
        // Ищем заказы через tg_links по tg_chat_id (основной путь для привязанных аккаунтов)
        // + fallback: client_chat_id (для тех кто пользовался /status_X)
        $stmt = $pdo->prepare("
            SELECT DISTINCT o.id, o.status, o.created_at, o.service_key,
                   p.title AS service_title, p.price_rub, p.price_uan
            FROM orders o
            LEFT JOIN prices p ON p.category_key = o.service_key
            LEFT JOIN tg_links tl ON tl.session_id = o.session_id
            WHERE tl.tg_chat_id = ?
               OR o.client_chat_id = ?
            ORDER BY o.id DESC
            LIMIT 10
        ");
        $stmt->execute([$chat_id, $chat_id]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        botLog("showCabinet error: " . $e->getMessage());
        $orders = [];
    }

    if (empty($orders)) {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chat_id,
            'text'    => "👤 *Личный кабинет*\n\nУ вас пока нет заказов\\. Чтобы они появились здесь — после создания заказа отправьте боту команду /status\\_НОМЕР\\_ЗАКАЗА\n\nСделать заказ: https://portfolio\\-site\\-boo5\\.onrender\\.com/order\\.php",
            'parse_mode' => 'MarkdownV2',
        ]);
        return;
    }

    $msg = "👤 *История ваших заказов:*\n\n";
    foreach ($orders as $o) {
        $icon = $status_icons[$o['status']] ?? '❓';
        $stxt = $status_text[$o['status']]  ?? $o['status'];
        $srv  = $o['service_title'] ?? $o['service_key'];
        $msg .= "{$icon} Заказ \#{$o['id']} — " . mdEscape($srv) . "\n";
        $msg .= "   Статус: *{$stxt}*  |  {$o['price_rub']} ₽\n";
        $msg .= "   Создан: " . mdEscape($o['created_at']) . "\n\n";
    }

    sendTelegram($token, 'sendMessage', [
        'chat_id'    => $chat_id,
        'text'       => $msg,
        'parse_mode' => 'MarkdownV2',
    ]);
}

function mainKeyboard(bool $isAdmin, bool $isLinked = false): array {
    $buttons = [
        [['text' => '🎨 Смотреть portfolio'], ['text' => '📋 Прайс-лист']],
        [['text' => '🤖 Сделать заказ']],
    ];
    if ($isLinked) {
        $buttons[] = [['text' => '👤 Личный кабинет']];
    }
    if ($isAdmin) {
        $buttons[] = [['text' => '💻 Admin Panel']];
    }
    return ['keyboard' => $buttons, 'resize_keyboard' => true];
}

function adminReplyKeyboard(): array {
    return [
        'keyboard' => [
            [['text' => '🔴 Срочные заказы'], ['text' => '🗂️ Очередь заказов']],
            [['text' => '📊 Статистика'],      ['text' => '🌐 Открыть сайт']],
            [['text' => '🔙 Главное меню']],
        ],
        'resize_keyboard' => true,
    ];
}

/** Клавиатура управления заказом для админа */
function orderKeyboard(int $order_id, string $status, string $telegram): array {
    $keyboard = ['inline_keyboard' => []];

    if ($status === 'pending') {
        $keyboard['inline_keyboard'][] = [
            ['text' => '🚀 Взять в работу',  'callback_data' => "adm_work_{$order_id}"],
            ['text' => '❌ Отклонить',        'callback_data' => "adm_dec_{$order_id}"],
        ];
        $keyboard['inline_keyboard'][] = [
            ['text' => '🔴 Срочный (24ч)',    'callback_data' => "adm_urgent_set_{$order_id}"],
        ];
    }

    if ($status === 'in_progress') {
        $keyboard['inline_keyboard'][] = [
            ['text' => '✅ Выполнен',          'callback_data' => "adm_ready_{$order_id}"],
            ['text' => '❌ Отклонить',         'callback_data' => "adm_dec_{$order_id}"],
        ];
        $keyboard['inline_keyboard'][] = [
            ['text' => '🔴 Срочный (24ч)',     'callback_data' => "adm_urgent_set_{$order_id}"],
        ];
    }

    $clean_tg = cleanTelegramUsername($telegram);
    if ($clean_tg !== '') {
        $keyboard['inline_keyboard'][] = [
            ['text' => '💬 Написать клиенту', 'url' => "https://t.me/{$clean_tg}"],
        ];
    }

    return $keyboard;
}

function showAdminQueue(PDO $pdo, string $token, string $admin_id, string $site_url): void {
    $queue = safeQueryOrders($pdo, "
        SELECT id, username, telegram, service_key, status, created_at,
               COALESCE(is_urgent, 0) AS is_urgent
        FROM orders
        WHERE status IN ('pending', 'in_progress')
        ORDER BY COALESCE(is_urgent, 0) DESC, created_at ASC
    ");

    if (empty($queue)) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $admin_id, 'text' => '🎉 Очередь пуста. Активных заказов нет.']);
        return;
    }

    $message  = "📁 *Активная очередь:*\n\n";
    $keyboard = ['inline_keyboard' => []];

    foreach ($queue as $item) {
        $deadline    = getDeadlineInfo($item['created_at']);
        $status_icon = ($item['status'] === 'in_progress') ? '⌛' : '⏳';
        $urgent_dot  = !empty($item['is_urgent']) ? ' 🔴' : '';
        $message    .= "{$status_icon}{$urgent_dot} Заказ \#{$item['id']} — " . mdEscape($deadline['text']) . "\n";
        $keyboard['inline_keyboard'][] = [[
            'text'          => ($item['is_urgent'] ? '🔴 ' : '') . "Заказ #{$item['id']} • {$deadline['button']}",
            'callback_data' => "adm_view_{$item['id']}",
        ]];
    }

    sendTelegram($token, 'sendMessage', [
        'chat_id'      => $admin_id,
        'text'         => $message,
        'parse_mode'   => 'MarkdownV2',
        'reply_markup' => $keyboard,
    ]);
}

function showUrgentQueue(PDO $pdo, string $token, string $admin_id, string $site_url): void {
    $queue = safeQueryOrders($pdo, "
        SELECT id, username, telegram, service_key, status, created_at,
               COALESCE(is_urgent, 0) AS is_urgent
        FROM orders
        WHERE status IN ('pending', 'in_progress')
          AND (COALESCE(is_urgent, 0) = 1 OR created_at <= NOW() - INTERVAL '5 days')
        ORDER BY COALESCE(is_urgent, 0) DESC, created_at ASC
    ");

    if (empty($queue)) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $admin_id, 'text' => '✅ Срочных заказов нет.']);
        return;
    }

    $message  = "🔴 *Срочные заказы:*\n\n";
    $keyboard = ['inline_keyboard' => []];

    foreach ($queue as $item) {
        $deadline    = getDeadlineInfo($item['created_at']);
        $urgent_mark = !empty($item['is_urgent']) ? '🔴 СРОЧНЫЙ' : '🚨 ПРОСРОЧЕН';
        $message    .= "{$urgent_mark} Заказ \#{$item['id']} — " . mdEscape($deadline['text']) . "\n";
        $keyboard['inline_keyboard'][] = [[
            'text'          => "🔴 Заказ #{$item['id']} • {$deadline['button']}",
            'callback_data' => "adm_view_{$item['id']}",
        ]];
    }

    sendTelegram($token, 'sendMessage', [
        'chat_id'      => $admin_id,
        'text'         => $message,
        'parse_mode'   => 'MarkdownV2',
        'reply_markup' => $keyboard,
    ]);
}

function sendAdminStats(PDO $pdo, string $token, string $admin_id): void {
    try {
        $total    = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        $ready    = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='ready'")->fetchColumn();
        $active   = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','in_progress')")->fetchColumn();
        $declined = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='declined'")->fetchColumn();
        $urgent   = 0;
        try {
            $urgent = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','in_progress') AND COALESCE(is_urgent,0)=1")->fetchColumn();
        } catch (Throwable $e) {}
        $overdue    = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','in_progress') AND created_at <= NOW() - INTERVAL '5 days'")->fetchColumn();
        $income_rub = (float)$pdo->query("SELECT COALESCE(SUM(p.price_rub),0) FROM orders o LEFT JOIN prices p ON p.category_key=o.service_key WHERE o.status='ready'")->fetchColumn();
        $income_uan = (float)$pdo->query("SELECT COALESCE(SUM(p.price_uan),0) FROM orders o LEFT JOIN prices p ON p.category_key=o.service_key WHERE o.status='ready'")->fetchColumn();

        $msg  = "📊 Статистика Kostlim Design\n\n";
        $msg .= "Всего заказов: {$total}\n";
        $msg .= "Активных: {$active}\n";
        $msg .= "🔴 Срочных: {$urgent}\n";
        $msg .= "🚨 Просроченных: {$overdue}\n";
        $msg .= "✅ Выполненных: {$ready}\n";
        $msg .= "❌ Отклонённых: {$declined}\n\n";
        $msg .= "💰 Заработано: " . number_format($income_rub, 0, '.', ' ') . " ₽ / " . number_format($income_uan, 0, '.', ' ') . " ₴";

        sendTelegram($token, 'sendMessage', ['chat_id' => $admin_id, 'text' => $msg]);
    } catch (Throwable $e) {
        botLog("sendAdminStats error: " . $e->getMessage());
    }
}

function showAdminOrderDetails(PDO $pdo, string $token, string $admin_id, string $site_url, int $order_id): void {
    $o_stmt = $pdo->prepare("
        SELECT id, username, telegram, service_key, details, screenshot, example_photo, status, created_at
        FROM orders WHERE id = ? AND status IN ('pending', 'in_progress') LIMIT 1
    ");
    $o_stmt->execute([$order_id]);
    $item = $o_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $admin_id, 'text' => "Заказ #{$order_id} не найден в активной очереди."]);
        return;
    }

    $item['is_urgent'] = safeGetIsUrgent($pdo, $order_id);

    $price_stmt = $pdo->prepare("SELECT title, price_rub, price_uan FROM prices WHERE category_key = ? LIMIT 1");
    $price_stmt->execute([$item['service_key']]);
    $price_info = $price_stmt->fetch(PDO::FETCH_ASSOC);

    sendOrderPhotos($token, $admin_id, $item);

    sendTelegram($token, 'sendMessage', [
        'chat_id'                  => $admin_id,
        'text'                     => buildOrderCard($item, $price_info ?: [], $site_url),
        'parse_mode'               => 'MarkdownV2',
        'disable_web_page_preview' => true,
        'reply_markup'             => orderKeyboard($item['id'], $item['status'], $item['telegram']),
    ]);
}

function buildOrderCard(array $item, array $price_info, string $site_url): string {
    $created   = new DateTime($item['created_at']);
    $now       = new DateTime();
    $days_left = 5 - $created->diff($now)->days;
    $days_str  = ($days_left < 0) ? '🚨 ДЕДЛАЙН ПРОСРОЧЕН' : "{$days_left} дн\\.";

    $status_text   = ['pending' => '⏳ Ожидает', 'in_progress' => '⌛ В работе'][$item['status']] ?? $item['status'];
    $service_title = $price_info['title'] ?? $item['service_key'];
    $p_rub         = (int)($price_info['price_rub'] ?? 0);
    $p_uan         = (int)($price_info['price_uan'] ?? 0);
    $urgent        = !empty($item['is_urgent']) ? '🔴 Да' : 'Нет';
    $order_id      = (int)$item['id'];

    $msg  = "📦 *ЗАКАЗ \#{$order_id}*";
    if (!empty($item['is_urgent'])) $msg .= " 🔴 *СРОЧНЫЙ*";
    $msg .= "\n\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\n";
    $msg .= "👤 *Имя:* "    . mdEscape($item['username'] ?? '') . "\n";
    $msg .= "📞 *Связь:* "  . mdEscape($item['telegram'] ?? '') . "\n";
    $msg .= "🎨 *Услуга:* " . mdEscape($service_title) . "\n";
    $msg .= "💰 *Цена:* "   . mdEscape((string)$p_rub) . " ₽ / " . mdEscape((string)$p_uan) . " ₴\n";
    $msg .= "🚀 *Срочный:* " . mdEscape($urgent) . "\n";
    $msg .= "📝 *ТЗ:* "     . mdEscape(mb_substr($item['details'] ?? '', 0, 300)) . "\n";
    $msg .= "\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\n";
    $msg .= "🔹 *Статус:* " . mdEscape($status_text) . "\n";
    $msg .= "⏱ *Осталось:* {$days_str}\n";
    if (!empty($item['screenshot'])) {
        $msg .= "📸 *Чек:* прикреплён выше\n";
    } else {
        $msg .= "📸 *Чек:* _не прикреплён_\n";
    }
    if (!empty($item['example_photo'])) {
        $msg .= "🖼 *Референс:* прикреплён выше\n";
    } else {
        $msg .= "🖼 *Референс:* _не прикреплён_\n";
    }
    return $msg;
}

function getDeadlineInfo(string $created_at): array {
    $created   = new DateTime($created_at);
    $now       = new DateTime();
    $days_left = 5 - $created->diff($now)->days;
    if ($days_left < 0) return ['text' => 'срок просрочен', 'button' => 'просрочен'];
    return ['text' => "осталось {$days_left} дн.", 'button' => "{$days_left} дн."];
}

function getOrderTelegram(PDO $pdo, int $order_id): string {
    $stmt = $pdo->prepare("SELECT telegram FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$order_id]);
    return (string)$stmt->fetchColumn();
}

// ══ БАГ 1 ИСПРАВЛЕН ══════════════════════════════════════════════
// notifyClient теперь fallback через tg_links если client_chat_id = NULL
function notifyClient(PDO $pdo, string $token, int $order_id, string $text): void {
    $stmt = $pdo->prepare("SELECT client_chat_id, session_id FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) return;

    $chat_id = $order['client_chat_id'];

    // Fallback: client_chat_id пустой — ищем через tg_links по session_id
    if (empty($chat_id) && !empty($order['session_id'])) {
        try {
            $tg_stmt = $pdo->prepare("SELECT tg_chat_id FROM tg_links WHERE session_id = ? AND linked = TRUE LIMIT 1");
            $tg_stmt->execute([$order['session_id']]);
            $chat_id = $tg_stmt->fetchColumn();
        } catch (Throwable $e) {
            botLog("notifyClient fallback error: " . $e->getMessage());
        }
    }

    if (!empty($chat_id)) {
        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $chat_id,
            'text'       => $text,
            'parse_mode' => 'MarkdownV2',
        ]);
    }
}

function sendOrderPhotos(string $token, string $chat_id, array $item): void {
    foreach (['screenshot' => 'Чек оплаты', 'example_photo' => 'Референс'] as $field => $label) {
        if (empty($item[$field])) continue;
        $path = __DIR__ . '/uploads/orders/' . basename($item[$field]);
        if (!is_file($path)) continue;
        sendTelegramFile($token, 'sendPhoto', [
            'chat_id' => $chat_id,
            'photo'   => new CURLFile($path),
            'caption' => "{$label} к заказу #{$item['id']}",
        ]);
    }
}

function safeGetIsUrgent(PDO $pdo, int $order_id): int {
    try {
        $stmt = $pdo->prepare("SELECT is_urgent FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([$order_id]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function safeQueryOrders(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        botLog("safeQueryOrders fallback: " . $e->getMessage());
        $sql2 = preg_replace('/COALESCE\((?:o\.)?is_urgent,\s*0\)\s+AS\s+is_urgent/i', '0 AS is_urgent', $sql);
        $sql2 = preg_replace('/COALESCE\((?:o\.)?is_urgent,\s*0\)\s*=\s*1/i', '1=0', $sql2);
        $sql2 = preg_replace('/COALESCE\((?:o\.)?is_urgent,\s*0\)\s+DESC/i', '1 DESC', $sql2);
        try {
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute($params);
            return $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e2) {
            return [];
        }
    }
}

function cleanTelegramUsername(string $value): string {
    $value = str_replace(['https://t.me/', 'http://t.me/', 't.me/', '@'], '', trim($value));
    return preg_replace('/[^A-Za-z0-9_]/', '', $value) ?? '';
}

function normalizeBotText(string $text): string {
    $text = trim($text);
    $text = preg_replace('/[^\p{L}\p{N}\s\-_\/]/u', '', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return mb_strtolower(trim($text), 'UTF-8');
}

function mdEscape(string $text): string {
    return str_replace(
        ['_','*','[',']','(',')',  '~','`','>','#','+','-','=','|','{','}','.','!'],
        ['\_','\*','\[','\]','\(','\)','\~','\`','\>','\#','\+','\-','\=','\|','\{','\}','\.', '\!'],
        $text
    );
}

function botLog(string $message): void {
    file_put_contents(__DIR__ . '/bot_debug.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

function sendTelegram(string $token, string $method, array $params = []): ?array {
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));
    $res   = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    $data = json_decode((string)$res, true);
    if ($error !== '' || !($data['ok'] ?? false)) {
        botLog("tg error method={$method} error={$error} response={$res}");
    }
    return $data;
}

function sendTelegramFile(string $token, string $method, array $params = []): void {
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res   = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    $data = json_decode((string)$res, true);
    if ($error !== '' || !($data['ok'] ?? false)) {
        botLog("tg file error method={$method} error={$error} response={$res}");
    }
}