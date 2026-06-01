<?php
require_once __DIR__ . '/config/db.php';

$token    = getenv('BOT_TOKEN')    ?: "8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg";
$admin_id = getenv('ADMIN_ID')     ?: "1710365896";
$site_url = getenv('SITE_URL')     ?: "https://kostlimdzn.kesug.com/";

// ── CRON-режим: напоминания (вызывать через ?cron=remind&secret=XXX) ─────────
if (isset($_GET['cron']) && $_GET['cron'] === 'remind') {
    $cron_secret = getenv('CRON_SECRET') ?: 'kostlim_cron_2024';
    if (($_GET['secret'] ?? '') !== $cron_secret) {
        http_response_code(403);
        exit('Forbidden');
    }
    sendReminders($pdo, $token, $admin_id);
    exit('ok');
}

$input  = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    exit;
}

// ── CALLBACK QUERY ──────────────────────────────────────────────
if (isset($update['callback_query'])) {
    $callback_id   = $update['callback_query']['id'];
    $cal_chat_id   = $update['callback_query']['message']['chat']['id'];
    $msg_id        = $update['callback_query']['message']['message_id'];
    $callback_data = $update['callback_query']['data'] ?? '';

    if ((string)$cal_chat_id !== $admin_id) {
        sendTelegram($token, 'answerCallbackQuery', [
            'callback_query_id' => $callback_id,
            'text'              => 'Доступ закрыт',
            'show_alert'        => true,
        ]);
        exit;
    }

    // ── Показать очередь
    if ($callback_data === 'adm_show_queue') {
        showAdminQueue($pdo, $token, $admin_id, $site_url);
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id]);
        exit;
    }

    // ── Показать срочные заказы
    if ($callback_data === 'adm_show_urgent') {
        showUrgentOrders($pdo, $token, $admin_id, $site_url);
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id]);
        exit;
    }

    // ── Статистика
    if ($callback_data === 'adm_stats') {
        $total   = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        $ready   = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='ready'")->fetchColumn();
        $active  = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending', 'in_progress')")->fetchColumn();
        $urgent  = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE is_urgent=1 AND status IN ('pending','in_progress')")->fetchColumn();
        $overdue = (int)$pdo->query("
            SELECT COUNT(*) FROM orders
            WHERE status IN ('pending','in_progress')
              AND (
                (is_urgent=1 AND created_at < NOW() - INTERVAL 24 HOUR)
                OR
                (is_urgent=0 AND created_at < NOW() - INTERVAL 5 DAY)
              )
        ")->fetchColumn();

        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $admin_id,
            'text'       => "📊 *Быстрая статистика*\n\n"
                          . "📥 Всего заказов: *{$total}*\n"
                          . "🔥 Активных: *{$active}*\n"
                          . "🚨 Срочных: *{$urgent}*\n"
                          . "🚫 Просроченных: *{$overdue}*\n"
                          . "✅ Выполненных: *{$ready}*",
            'parse_mode' => 'Markdown',
        ]);
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id]);
        exit;
    }

    // ── Вернуться в главное меню (из админки)
    if ($callback_data === 'adm_back_main') {
        sendTelegram($token, 'sendMessage', [
            'chat_id'      => $admin_id,
            'text'         => "👋 *Главное меню*\n\nВыбери раздел:",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(mainKeyboard(true), JSON_UNESCAPED_UNICODE),
        ]);
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id]);
        exit;
    }

    // ── Детали конкретного заказа
    if (strpos($callback_data, 'adm_view_') === 0) {
        $order_id = (int)str_replace('adm_view_', '', $callback_data);
        showAdminOrderDetails($pdo, $token, $admin_id, $site_url, $order_id);
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "Открываю заказ #{$order_id}"]);
        exit;
    }

    // ── Взять в работу
    if (strpos($callback_data, 'adm_work_') === 0) {
        $order_id = (int)str_replace('adm_work_', '', $callback_data);
        $pdo->prepare("UPDATE orders SET status = 'in_progress' WHERE id = ?")->execute([$order_id]);

        sendTelegram($token, 'editMessageText', [
            'chat_id'      => $cal_chat_id,
            'message_id'   => $msg_id,
            'text'         => "🚀 *Заказ #{$order_id} взят в работу.*",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(orderKeyboard($order_id, 'in_progress', getOrderTelegram($pdo, $order_id)), JSON_UNESCAPED_UNICODE),
        ]);
        notifyClient($pdo, $token, $order_id, "🎨 Ваш заказ #{$order_id} взят в работу. Дизайнер уже начал выполнение.");
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Заказ взят в работу']);
        exit;
    }

    // ── Выполнен
    if (strpos($callback_data, 'adm_ready_') === 0) {
        $order_id = (int)str_replace('adm_ready_', '', $callback_data);
        $pdo->prepare("UPDATE orders SET status = 'ready' WHERE id = ?")->execute([$order_id]);

        sendTelegram($token, 'editMessageText', [
            'chat_id'    => $cal_chat_id,
            'message_id' => $msg_id,
            'text'       => "✅ *Заказ #{$order_id} успешно выполнен.*",
            'parse_mode' => 'Markdown',
        ]);
        notifyClient($pdo, $token, $order_id, "🎉 Ваш заказ #{$order_id} готов! Дизайнер свяжется с вами для передачи финальных файлов.");
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Заказ выполнен']);
        exit;
    }

    // ── Отклонить
    if (strpos($callback_data, 'adm_dec_') === 0) {
        $order_id = (int)str_replace('adm_dec_', '', $callback_data);
        $pdo->prepare("UPDATE orders SET status = 'declined' WHERE id = ?")->execute([$order_id]);

        sendTelegram($token, 'editMessageText', [
            'chat_id'    => $cal_chat_id,
            'message_id' => $msg_id,
            'text'       => "❌ *Заказ #{$order_id} отклонен.*",
            'parse_mode' => 'Markdown',
        ]);
        notifyClient($pdo, $token, $order_id, "🔴 К сожалению, ваш заказ #{$order_id} был отклонен дизайнером.");
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Заказ отклонен']);
        exit;
    }

    // ── Сделать срочным
    if (strpos($callback_data, 'adm_urgent_') === 0) {
        $order_id = (int)str_replace('adm_urgent_', '', $callback_data);
        // Сбрасываем created_at на сейчас, ставим is_urgent=1 — дедлайн 24ч с момента пометки
        $pdo->prepare("UPDATE orders SET is_urgent = 1, urgent_at = NOW() WHERE id = ?")->execute([$order_id]);

        $o_stmt = $pdo->prepare("SELECT status, telegram FROM orders WHERE id = ? LIMIT 1");
        $o_stmt->execute([$order_id]);
        $row = $o_stmt->fetch(PDO::FETCH_ASSOC);

        sendTelegram($token, 'editMessageText', [
            'chat_id'      => $cal_chat_id,
            'message_id'   => $msg_id,
            'text'         => "🔴🚨 *Заказ #{$order_id} помечен как СРОЧНЫЙ!*\n⏰ Срок: 24 часа",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(orderKeyboard($order_id, $row['status'] ?? 'pending', $row['telegram'] ?? ''), JSON_UNESCAPED_UNICODE),
        ]);
        notifyClient($pdo, $token, $order_id, "⚡ Ваш заказ #{$order_id} переведён в *срочный режим*. Срок выполнения: 24 часа.");
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '🔴 Заказ помечен срочным!', 'show_alert' => true]);
        exit;
    }

    sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id]);
    exit;
}

// ── MESSAGE ──────────────────────────────────────────────────────
if (isset($update['message'])) {
    $chat_id  = $update['message']['chat']['id'];
    $text     = $update['message']['text'] ?? '';
    $text_key = normalizeBotText($text);
    $is_admin = (string)$chat_id === $admin_id;
    botLog("message chat={$chat_id} text={$text} key={$text_key}");

    // /start или /menu
    if ($text === '/start' || $text === '/menu') {
        sendTelegram($token, 'sendMessage', [
            'chat_id'      => $chat_id,
            'text'         => "👋 *Привет! Добро пожаловать в Kostlim Design!*\n\nЗдесь можно посмотреть портфолио, узнать актуальный прайс, отправить ТЗ и проверить статус заказа.",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(mainKeyboard($is_admin), JSON_UNESCAPED_UNICODE),
        ]);
        exit;
    }

    // Портфолио
    if ($text_key === 'смотреть portfolio' || $text_key === 'portfolio' || $text_key === 'портфолио') {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chat_id,
            'text'    => "🎨 Портфолио Kostlim Design:\n{$site_url}",
        ]);
        exit;
    }

    // Прайс
    if ($text_key === 'прайс-лист' || $text_key === 'прайс лист' || $text_key === 'прайс') {
        $p_stmt = $pdo->query("SELECT title, description, features, price_uan, price_rub, image FROM prices ORDER BY id ASC");
        $prices = $p_stmt->fetchAll(PDO::FETCH_ASSOC);

        $price_msg = "📋 *Актуальный прайс-лист:*\n\n";
        foreach ($prices as $p) {
            $title = mdEscape($p['title'] ?? 'Услуга');
            $rub   = mdEscape((string)($p['price_rub'] ?? 0));
            $uan   = mdEscape((string)($p['price_uan'] ?? 0));
            $desc  = trim((string)($p['description'] ?? ''));
            $price_msg .= "▪️ *{$title}:* {$rub} ₽ / {$uan} ₴\n";
            if ($desc !== '') {
                $price_msg .= "_" . mdEscape($desc) . "_\n";
            }
            foreach (explode('|', (string)($p['features'] ?? '')) as $feature) {
                $feature = trim($feature);
                if ($feature !== '') {
                    $price_msg .= "  • " . mdEscape($feature) . "\n";
                }
            }
            $price_msg .= "\n";
        }

        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $chat_id,
            'text'       => $price_msg,
            'parse_mode' => 'Markdown',
        ]);

        foreach ($prices as $p) {
            if (empty($p['image'])) continue;
            $path = __DIR__ . '/uploads/' . basename($p['image']);
            if (!is_file($path)) continue;
            sendTelegramFile($token, 'sendPhoto', [
                'chat_id' => $chat_id,
                'photo'   => new CURLFile($path),
                'caption' => ($p['title'] ?? 'Услуга') . ': ' . (int)($p['price_rub'] ?? 0) . ' ₽ / ' . (int)($p['price_uan'] ?? 0) . ' ₴',
            ]);
        }
        exit;
    }

    // Сделать заказ
    if ($text_key === 'сделать заказ' || $text_key === 'заказ') {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chat_id,
            'text'    => "🤖 Форма отправки ТЗ:\n{$site_url}order.php",
        ]);
        exit;
    }

    // ── ADMIN PANEL ──────────────────────────────────────────────
    if ($text === '/admin' || $text_key === 'admin panel' || $text_key === 'админ панель' || $text_key === '⚙️ admin panel') {
        if (!$is_admin) {
            sendTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => '⛔ Доступ закрыт.']);
            exit;
        }
        sendAdminPanel($token, $admin_id);
        exit;
    }

    // ── Кнопки ReplyKeyboard для админа ─────────────────────────

    // 📋 Очередь заказов
    if ($is_admin && ($text_key === 'очередь заказов' || $text_key === '📋 очередь заказов')) {
        showAdminQueue($pdo, $token, $admin_id, $site_url);
        exit;
    }

    // 🔴 Срочные заказы
    if ($is_admin && ($text_key === 'срочные заказы' || $text_key === '🔴 срочные заказы')) {
        showUrgentOrders($pdo, $token, $admin_id, $site_url);
        exit;
    }

    // 📊 Статистика
    if ($is_admin && ($text_key === 'статистика' || $text_key === '📊 статистика')) {
        $total   = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        $ready   = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='ready'")->fetchColumn();
        $active  = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending', 'in_progress')")->fetchColumn();
        $urgent  = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE is_urgent=1 AND status IN ('pending','in_progress')")->fetchColumn();

        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $admin_id,
            'text'       => "📊 *Статистика*\n\n📥 Всего: *{$total}*\n🔥 Активных: *{$active}*\n🚨 Срочных: *{$urgent}*\n✅ Готово: *{$ready}*",
            'parse_mode' => 'Markdown',
        ]);
        exit;
    }

    // 🌐 Открыть сайт
    if ($is_admin && ($text_key === 'открыть сайт' || $text_key === '🌐 открыть сайт')) {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $admin_id,
            'text'    => "🌐 Ссылка на сайт:\n{$site_url}",
        ]);
        exit;
    }

    // 🔙 Вернуться в обычный режим
    if ($is_admin && ($text_key === 'вернуться в обычный режим' || $text_key === '🔙 вернуться в обычный режим')) {
        sendTelegram($token, 'sendMessage', [
            'chat_id'      => $admin_id,
            'text'         => "👋 Вернулся в обычный режим.",
            'reply_markup' => json_encode(mainKeyboard(true), JSON_UNESCAPED_UNICODE),
        ]);
        exit;
    }

    // /status_X
    if (strpos($text, '/status_') === 0) {
        $order_id = (int)str_replace('/status_', '', $text);
        $pdo->prepare("UPDATE orders SET client_chat_id = ? WHERE id = ?")->execute([$chat_id, $order_id]);

        $o_stmt = $pdo->prepare("SELECT status, created_at FROM orders WHERE id = ?");
        $o_stmt->execute([$order_id]);
        $order = $o_stmt->fetch(PDO::FETCH_ASSOC);

        if ($order) {
            $status_translate = [
                'pending'     => '⏳ Ожидает подтверждения',
                'in_progress' => '🎨 В процессе',
                'ready'       => '🟢 Готов',
                'declined'    => '❌ Отклонен',
            ];
            $cur_status = $status_translate[$order['status']] ?? $order['status'];
            sendTelegram($token, 'sendMessage', [
                'chat_id'    => $chat_id,
                'text'       => "📦 *Заказ #{$order_id}*\n\n🔹 *Статус:* {$cur_status}\n📅 *Дата создания:* {$order['created_at']}",
                'parse_mode' => 'Markdown',
            ]);
        } else {
            sendTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => "Заказ #{$order_id} не найден."]);
        }
        exit;
    }

    sendTelegram($token, 'sendMessage', [
        'chat_id'      => $chat_id,
        'text'         => "Не понял команду. Нажми /menu, чтобы открыть кнопки.",
        'reply_markup' => json_encode(mainKeyboard($is_admin), JSON_UNESCAPED_UNICODE),
    ]);
}

// ── KEYBOARDS ────────────────────────────────────────────────────

function mainKeyboard($isAdmin) {
    $buttons = [
        [['text' => '🎨 Смотреть portfolio'], ['text' => '📋 Прайс-лист']],
        [['text' => '🤖 Сделать заказ']],
    ];
    if ($isAdmin) {
        $buttons[] = [['text' => '⚙️ Admin Panel']];
    }
    return ['keyboard' => $buttons, 'resize_keyboard' => true];
}

function adminReplyKeyboard() {
    return [
        'keyboard' => [
            [['text' => '📋 Очередь заказов'], ['text' => '🔴 Срочные заказы']],
            [['text' => '📊 Статистика'],       ['text' => '🌐 Открыть сайт']],
            [['text' => '🔙 Вернуться в обычный режим']],
        ],
        'resize_keyboard' => true,
    ];
}

function adminInlineKeyboard() {
    return [
        'inline_keyboard' => [
            [['text' => '🗂️ Показать очередь заказов', 'callback_data' => 'adm_show_queue']],
            [['text' => '🔴 Срочные заказы',            'callback_data' => 'adm_show_urgent']],
            [['text' => '📊 Быстрая статистика',        'callback_data' => 'adm_stats']],
            [['text' => '🔙 Вернуться в главное меню',  'callback_data' => 'adm_back_main']],
        ],
    ];
}

function sendAdminPanel($token, $admin_id) {
    sendTelegram($token, 'sendMessage', [
        'chat_id'      => $admin_id,
        'text'         => "⚙️ *Админ-панель Kostlim Design*\n\nВыбери действие:",
        'parse_mode'   => 'Markdown',
        'reply_markup' => json_encode(adminReplyKeyboard(), JSON_UNESCAPED_UNICODE),
    ]);
}

function orderKeyboard($order_id, $status, $telegram) {
    $keyboard = ['inline_keyboard' => []];

    if ($status === 'pending') {
        $keyboard['inline_keyboard'][] = [
            ['text' => '🚀 Взять в работу', 'callback_data' => "adm_work_{$order_id}"],
            ['text' => '❌ Отклонить',       'callback_data' => "adm_dec_{$order_id}"],
        ];
    }
    if ($status === 'in_progress') {
        $keyboard['inline_keyboard'][] = [
            ['text' => '✅ Выполнен (Готов)', 'callback_data' => "adm_ready_{$order_id}"],
        ];
    }
    if (in_array($status, ['pending', 'in_progress'])) {
        $keyboard['inline_keyboard'][] = [
            ['text' => '🔴 Сделать СРОЧНЫМ', 'callback_data' => "adm_urgent_{$order_id}"],
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

// ── QUEUE / ORDERS ───────────────────────────────────────────────

function showAdminQueue($pdo, $token, $admin_id, $site_url) {
    $q_stmt = $pdo->query("
        SELECT id, username, telegram, service_key, details, screenshot, example_photo, status, created_at, is_urgent, urgent_at
        FROM orders
        WHERE status IN ('pending', 'in_progress')
        ORDER BY is_urgent DESC, created_at ASC, id ASC
    ");
    $queue = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($queue)) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $admin_id, 'text' => '🎉 Очередь пустая. Активных заказов нет.']);
        return;
    }

    $message  = "📁 *Активная очередь заказов:*\n\n";
    $keyboard = ['inline_keyboard' => []];

    foreach ($queue as $item) {
        $deadline    = getDeadlineInfo($item['created_at'], (bool)$item['is_urgent'], $item['urgent_at'] ?? null);
        $status_icon = ($item['status'] === 'in_progress') ? '🎨' : '⏳';
        $urgent_mark = $item['is_urgent'] ? ' 🔴' : '';
        $message    .= "{$status_icon}{$urgent_mark} *Заказ #{$item['id']}* — {$deadline['text']}\n";
        $keyboard['inline_keyboard'][] = [[
            'text'          => ($item['is_urgent'] ? '🔴 ' : '') . "Заказ #{$item['id']} • {$deadline['button']}",
            'callback_data' => "adm_view_{$item['id']}",
        ]];
    }

    sendTelegram($token, 'sendMessage', [
        'chat_id'      => $admin_id,
        'text'         => $message,
        'parse_mode'   => 'Markdown',
        'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
    ]);
}

function showUrgentOrders($pdo, $token, $admin_id, $site_url) {
    $q_stmt = $pdo->query("
        SELECT id, username, telegram, service_key, status, created_at, urgent_at
        FROM orders
        WHERE is_urgent = 1 AND status IN ('pending', 'in_progress')
        ORDER BY urgent_at ASC, created_at ASC
    ");
    $queue = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($queue)) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $admin_id, 'text' => '✅ Срочных заказов нет.']);
        return;
    }

    $message  = "🔴 *СРОЧНЫЕ ЗАКАЗЫ:*\n\n";
    $keyboard = ['inline_keyboard' => []];

    foreach ($queue as $item) {
        $deadline = getDeadlineInfo($item['created_at'], true, $item['urgent_at'] ?? null);
        $status_icon = ($item['status'] === 'in_progress') ? '🎨' : '⏳';
        $message .= "🔴{$status_icon} *Заказ #{$item['id']}* — {$deadline['text']}\n";
        $keyboard['inline_keyboard'][] = [[
            'text'          => "🔴 Заказ #{$item['id']} • {$deadline['button']}",
            'callback_data' => "adm_view_{$item['id']}",
        ]];
    }

    sendTelegram($token, 'sendMessage', [
        'chat_id'      => $admin_id,
        'text'         => $message,
        'parse_mode'   => 'Markdown',
        'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
    ]);
}

function showAdminOrderDetails($pdo, $token, $admin_id, $site_url, $order_id) {
    $o_stmt = $pdo->prepare("
        SELECT id, username, telegram, service_key, details, screenshot, example_photo, status, created_at, is_urgent, urgent_at
        FROM orders WHERE id = ? AND status IN ('pending', 'in_progress') LIMIT 1
    ");
    $o_stmt->execute([$order_id]);
    $item = $o_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $admin_id, 'text' => "Заказ #{$order_id} не найден в активной очереди."]);
        return;
    }

    $price_stmt = $pdo->prepare("SELECT title, price_rub, price_uan FROM prices WHERE category_key = ? LIMIT 1");
    $price_stmt->execute([$item['service_key']]);
    $price_info = $price_stmt->fetch(PDO::FETCH_ASSOC);

    sendOrderPhotos($token, $admin_id, $item);

    sendTelegram($token, 'sendMessage', [
        'chat_id'                  => $admin_id,
        'text'                     => buildOrderCard($item, $price_info ?: [], $site_url),
        'parse_mode'               => 'Markdown',
        'disable_web_page_preview' => true,
        'reply_markup'             => json_encode(orderKeyboard($item['id'], $item['status'], $item['telegram']), JSON_UNESCAPED_UNICODE),
    ]);
}

function buildOrderCard($item, $price_info, $site_url) {
    $is_urgent = !empty($item['is_urgent']);
    $deadline  = getDeadlineInfo($item['created_at'], $is_urgent, $item['urgent_at'] ?? null);
    $days_str  = $deadline['text'];

    $status_text   = ['pending' => '⏳ Ожидает подтверждения', 'in_progress' => '🎨 В процессе'][$item['status']] ?? $item['status'];
    $urgent_text   = $is_urgent ? "\n🔴 *СРОЧНЫЙ ЗАКАЗ* — срок 24 часа" : '';
    $service_title = $price_info['title'] ?? $item['service_key'];
    $p_rub         = $price_info['price_rub'] ?? 0;
    $p_uan         = $price_info['price_uan'] ?? 0;

    $msg  = ($is_urgent ? "🔴 " : "") . "*ЗАКАЗ #{$item['id']}*{$urgent_text}\n";
    $msg .= "-------------------------\n";
    $msg .= "👤 *Имя:* "    . mdEscape($item['username'] ?? '') . "\n";
    $msg .= "📞 *Связь:* "  . mdEscape($item['telegram'] ?? '') . "\n";
    $msg .= "🎨 *Услуга:* " . mdEscape($service_title) . "\n";
    $msg .= "💰 *Цена:* "   . mdEscape((string)$p_rub) . " ₽ / " . mdEscape((string)$p_uan) . " ₴\n";
    $msg .= "📝 *ТЗ:* "     . mdEscape($item['details'] ?? '') . "\n";
    $msg .= "-------------------------\n";
    $msg .= "🔹 *Статус:* {$status_text}\n";
    $msg .= "⏱ *Осталось:* {$days_str}\n";

    if (!empty($item['screenshot'])) {
        $msg .= "📸 *Чек:* [Открыть файл]({$site_url}uploads/orders/" . rawurlencode($item['screenshot']) . ")\n";
    } else {
        $msg .= "📸 *Чек:* _не прикреплен_\n";
    }
    if (!empty($item['example_photo'])) {
        $msg .= "🖼 *Референс:* [Открыть файл]({$site_url}uploads/orders/" . rawurlencode($item['example_photo']) . ")\n";
    } else {
        $msg .= "🖼 *Референс:* _не прикреплен_\n";
    }

    return $msg;
}

// ── DEADLINE ─────────────────────────────────────────────────────

function getDeadlineInfo($created_at, $is_urgent = false, $urgent_at = null) {
    $now = new DateTime();

    if ($is_urgent) {
        // Срочный: 24ч с момента urgent_at (или created_at, если urgent_at не задан)
        $start    = new DateTime($urgent_at ?: $created_at);
        $deadline = clone $start;
        $deadline->modify('+24 hours');
        $diff_sec = $deadline->getTimestamp() - $now->getTimestamp();

        if ($diff_sec <= 0) {
            return ['text' => '🚨 СРОЧНЫЙ ПРОСРОЧЕН', 'button' => '🔴 просрочен'];
        }
        $hours   = floor($diff_sec / 3600);
        $minutes = floor(($diff_sec % 3600) / 60);
        return [
            'text'   => "🔴 срочный, осталось {$hours}ч {$minutes}м",
            'button' => "🔴 {$hours}ч {$minutes}м",
        ];
    } else {
        // Обычный: 5 дней с created_at
        $created   = new DateTime($created_at);
        $days_left = 5 - $created->diff($now)->days;
        if ($days_left < 0) {
            return ['text' => '🚨 ДЕДЛАЙН ПРОСРОЧЕН', 'button' => 'просрочен'];
        }
        return [
            'text'   => "осталось {$days_left} дн.",
            'button' => "{$days_left} дн.",
        ];
    }
}

// ── REMINDERS ────────────────────────────────────────────────────
// Вызывается через cron 3 раза в день: 12:00, 15:00, 20:00
// Настрой cron на сервере:
//   0 12 * * * curl "https://kostlimdzn.kesug.com/bot.php?cron=remind&secret=kostlim_cron_2024" > /dev/null 2>&1
//   0 15 * * * curl "https://kostlimdzn.kesug.com/bot.php?cron=remind&secret=kostlim_cron_2024" > /dev/null 2>&1
//   0 20 * * * curl "https://kostlimdzn.kesug.com/bot.php?cron=remind&secret=kostlim_cron_2024" > /dev/null 2>&1

function sendReminders($pdo, $token, $admin_id) {
    $q_stmt = $pdo->query("
        SELECT id, username, status, created_at, is_urgent, urgent_at
        FROM orders
        WHERE status IN ('pending', 'in_progress')
        ORDER BY is_urgent DESC, created_at ASC
    ");
    $orders = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($orders)) {
        return; // нет активных заказов — молчим
    }

    $total   = count($orders);
    $urgent  = array_filter($orders, fn($o) => !empty($o['is_urgent']));
    $overdue = [];

    $lines = "🔔 *Напоминание о заказах*\n";
    $lines .= "Активных заказов: *{$total}*";
    if (count($urgent) > 0) {
        $lines .= " | 🔴 Срочных: *" . count($urgent) . "*";
    }
    $lines .= "\n\n";

    foreach ($orders as $o) {
        $deadline = getDeadlineInfo($o['created_at'], !empty($o['is_urgent']), $o['urgent_at'] ?? null);
        $is_over  = str_contains($deadline['text'], 'ПРОСРОЧЕН');
        if ($is_over) {
            $overdue[] = $o;
        }
        $icon = !empty($o['is_urgent']) ? '🔴' : ($is_over ? '🚨' : '📦');
        $name = $o['username'] ?: "ID#{$o['id']}";
        $lines .= "{$icon} #{$o['id']} {$name} — {$deadline['text']}\n";
    }

    if (!empty($overdue)) {
        $lines .= "\n⚠️ *Просроченных заказов: " . count($overdue) . "* — срочно обрати внимание!";
    }

    sendTelegram($token, 'sendMessage', [
        'chat_id'    => $admin_id,
        'text'       => $lines,
        'parse_mode' => 'Markdown',
    ]);
}

// ── HELPERS ──────────────────────────────────────────────────────

function getOrderTelegram($pdo, $order_id) {
    $stmt = $pdo->prepare("SELECT telegram FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$order_id]);
    return (string)$stmt->fetchColumn();
}

function notifyClient($pdo, $token, $order_id, $text) {
    $stmt = $pdo->prepare("SELECT client_chat_id FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$order_id]);
    $chat_id = $stmt->fetchColumn();
    if (!empty($chat_id)) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'Markdown']);
    }
}

function sendOrderPhotos($token, $chat_id, $item) {
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

function cleanTelegramUsername($value) {
    $value = trim((string)$value);
    $value = str_replace(['https://t.me/', 'http://t.me/', 't.me/', '@'], '', $value);
    return preg_replace('/[^A-Za-z0-9_]/', '', $value) ?? '';
}

function normalizeBotText($text) {
    $text = trim((string)$text);
    $text = preg_replace('/[^\p{L}\p{N}\s_\-\/]+/u', '', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function mdEscape($text) {
    return str_replace(
        ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'],
        ['\_', '\*', '\[', '\]', '\(', '\)', '\~', '\`', '\>', '\#', '\+', '\-', '\=', '\|', '\{', '\}', '\.', '\!'],
        (string)$text
    );
}

function botLog($message) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents(__DIR__ . '/bot_debug.log', $line, FILE_APPEND);
}

function sendTelegram($token, $method, $params = []) {
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res   = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    $data = json_decode((string)$res, true);
    if ($error !== '' || !($data['ok'] ?? false)) {
        botLog("telegram error method={$method} error={$error} response={$res}");
    }
    return $res;
}

function sendTelegramFile($token, $method, $params = []) {
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res   = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    $data = json_decode((string)$res, true);
    if ($error !== '' || !($data['ok'] ?? false)) {
        botLog("telegram file error method={$method} error={$error} response={$res}");
    }
    return $res;
}