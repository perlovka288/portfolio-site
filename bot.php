<?php

require_once __DIR__ . '/config/db.php';

$token    = getenv('BOT_TOKEN') ?: "8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg";
$admin_id = getenv('ADMIN_ID')  ?: "1710365896";
$site_url = "https://portfolio-site-boo5.onrender.com/";

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

    if (strpos($callback_data, 'adm_work_') === 0) {
        $order_id = (int)str_replace('adm_work_', '', $callback_data);
        $pdo->prepare("UPDATE orders SET status = 'in_progress' WHERE id = ?")->execute([$order_id]);
        sendTelegram($token, 'editMessageText', [
            'chat_id'      => $cal_chat_id,
            'message_id'   => $msg_id,
            'text'         => "🚀 *Заказ \#${order_id} взят в работу\.*",
            'parse_mode'   => 'MarkdownV2',
            'reply_markup' => orderKeyboard($order_id, 'in_progress', getOrderTelegram($pdo, $order_id)),
        ]);
        notifyClient($pdo, $token, $order_id, "🎨 Ваш заказ \#*${order_id}* взят в работу\. Дизайнер уже начал выполнение\.");
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Заказ взят в работу']);
        exit;
    }

    if (strpos($callback_data, 'adm_ready_') === 0) {
        $order_id = (int)str_replace('adm_ready_', '', $callback_data);
        $pdo->prepare("UPDATE orders SET status = 'ready' WHERE id = ?")->execute([$order_id]);
        sendTelegram($token, 'editMessageText', [
            'chat_id'    => $cal_chat_id,
            'message_id' => $msg_id,
            'text'       => "✅ *Заказ \#${order_id} успешно выполнен\.*",
            'parse_mode' => 'MarkdownV2',
        ]);
        notifyClient($pdo, $token, $order_id, "🎉 Ваш заказ \#*${order_id}* готов\! Дизайнер свяжется с вами для передачи финальных файлов\.");
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Заказ выполнен']);
        exit;
    }

    if (strpos($callback_data, 'adm_dec_') === 0) {
        $order_id = (int)str_replace('adm_dec_', '', $callback_data);
        $pdo->prepare("UPDATE orders SET status = 'declined' WHERE id = ?")->execute([$order_id]);
        sendTelegram($token, 'editMessageText', [
            'chat_id'    => $cal_chat_id,
            'message_id' => $msg_id,
            'text'       => "❌ *Заказ \#${order_id} отклонен\.*",
            'parse_mode' => 'MarkdownV2',
        ]);
        notifyClient($pdo, $token, $order_id, "🔴 К сожалению, ваш заказ \#*${order_id}* был отклонён дизайнером\.");
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Заказ отклонен']);
        exit;
    }

    // ── Бан клиента в чёрный список ───────────────────────────────
    if (strpos($callback_data, 'adm_ban_') === 0) {
        $order_id = (int)str_replace('adm_ban_', '', $callback_data);
        $order = $pdo->prepare("SELECT telegram, client_ip FROM orders WHERE id = ? LIMIT 1");
        $order->execute([$order_id]);
        $o = $order->fetch(PDO::FETCH_ASSOC);

        if ($o) {
            $tg_clean = ltrim(str_replace(['https://t.me/', 'http://t.me/', '@'], '', $o['telegram'] ?? ''), '@');
            try {
                $pdo->prepare("
                    INSERT IGNORE INTO blacklist (telegram, ip, order_id, reason, created_at)
                    VALUES (?, ?, ?, 'Бан из Telegram-панели', NOW())
                ")->execute([$tg_clean ?: null, $o['client_ip'] ?: null, $order_id]);

                sendTelegram($token, 'editMessageText', [
                    'chat_id'    => $cal_chat_id,
                    'message_id' => $msg_id,
                    'text'       => "🚫 *Клиент заблокирован\!*\nЗаказ \#{$order_id} — @{$tg_clean}\nIP: " . ($o['client_ip'] ?? 'неизвестен'),
                    'parse_mode' => 'MarkdownV2',
                ]);
                sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '🚫 Клиент добавлен в чёрный список']);
            } catch (PDOException $e) {
                sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Ошибка: ' . $e->getMessage(), 'show_alert' => true]);
            }
        } else {
            sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Заказ не найден', 'show_alert' => true]);
        }
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

    botLog("message chat={$chat_id} text={$text} key={$text_key}");
    try {

    if ($text === '/start' || $text === '/menu') {
        sendTelegram($token, 'sendMessage', [
            'chat_id'      => $chat_id,
            'text'         => "👋 Привет! Добро пожаловать в Kostlim Design!\n\nЗдесь можно посмотреть портфолио, узнать актуальный прайс, отправить ТЗ и проверить статус заказа.",
            'reply_markup' => mainKeyboard((string)$chat_id === $admin_id),
        ]);
        exit;
    }

    if ($text_key === 'смотреть portfolio' || $text_key === 'portfolio' || $text_key === 'портфолио') {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chat_id,
            'text'    => "🎨 Портфолио Kostlim Design:\n{$site_url}",
        ]);
        exit;
    }

    if ($text_key === 'прайс-лист' || $text_key === 'прайс лист' || $text_key === 'прайс') {
        $p_stmt = $pdo->query("SELECT title, description, features, price_uan, price_rub, image FROM prices ORDER BY id ASC");
        $prices = $p_stmt->fetchAll(PDO::FETCH_ASSOC);
        $price_msg = "📋 Актуальный прайс-лист:\n\n";
        foreach ($prices as $p) {
            $title = $p['title'] ?? 'Услуга';
            $rub   = (int)($p['price_rub'] ?? 0);
            $uan   = (int)($p['price_uan'] ?? 0);
            $desc  = trim((string)($p['description'] ?? ''));
            $price_msg .= "▪️ {$title}: {$rub} ₽ / {$uan} ₴\n";
            if ($desc !== '') {
                $price_msg .= "  {$desc}\n";
            }
            foreach (explode('|', (string)($p['features'] ?? '')) as $feature) {
                $feature = trim($feature);
                if ($feature !== '') {
                    $price_msg .= "  • {$feature}\n";
                }
            }
            $price_msg .= "\n";
        }
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chat_id,
            'text'    => $price_msg,
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

    if ($text_key === 'сделать заказ' || $text_key === 'заказ') {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chat_id,
            'text'    => "🤖 Форма отправки ТЗ:\n{$site_url}order.php",
        ]);
        exit;
    }

    if ($text === '/admin' || $text_key === 'admin panel' || $text_key === 'админ панель') {
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

    if ($text_key === 'главное меню') {
        if ((string)$chat_id !== $admin_id) { exit; }
        sendTelegram($token, 'sendMessage', [
            'chat_id'      => $admin_id,
            'text'         => "🏠 Главное меню",
            'reply_markup' => mainKeyboard(true),
        ]);
        exit;
    }

    if ($text_key === 'очередь заказов' || $text_key === 'очередь') {
        if ((string)$chat_id !== $admin_id) {
            sendTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => '⛔ Доступ закрыт.']);
            exit;
        }
        showAdminQueue($pdo, $token, $admin_id, $site_url);
        exit;
    }

    if ($text_key === 'срочные заказы' || $text_key === 'срочные') {
        if ((string)$chat_id !== $admin_id) {
            sendTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => '⛔ Доступ закрыт.']);
            exit;
        }
        showUrgentQueue($pdo, $token, $admin_id, $site_url);
        exit;
    }

    if ($text_key === 'статистика') {
        if ((string)$chat_id !== $admin_id) {
            sendTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => '⛔ Доступ закрыт.']);
            exit;
        }
        sendAdminStats($pdo, $token, $admin_id);
        exit;
    }

    if ($text_key === 'открыть сайт') {
        if ((string)$chat_id !== $admin_id) {
            sendTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => '⛔ Доступ закрыт.']);
            exit;
        }
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $admin_id,
            'text'    => "🌐 Ссылка на сайт:\n{$site_url}admin/index.php",
        ]);
        exit;
    }

    $text_cmd = explode('@', $text)[0];
    if (strpos($text_cmd, '/status_') === 0) {
        $order_id = (int)str_replace('/status_', '', $text_cmd);

        try {
            $pdo->prepare("UPDATE orders SET client_chat_id = ? WHERE id = ?")->execute([$chat_id, $order_id]);
        } catch (Throwable $e) {
            botLog("client_chat_id update failed: " . $e->getMessage());
        }

        // Безопасный запрос — без is_urgent, добавим отдельно если колонка есть
        $o_stmt = $pdo->prepare("
            SELECT o.id, o.status, o.created_at, o.details, o.username, o.telegram,
                   o.service_key, o.screenshot, o.example_photo,
                   p.title AS service_title, p.price_rub, p.price_uan
            FROM orders o
            LEFT JOIN prices p ON p.category_key = o.service_key
            WHERE o.id = ?
            LIMIT 1
        ");
        $o_stmt->execute([$order_id]);
        $order = $o_stmt->fetch(PDO::FETCH_ASSOC);

        if ($order) {
            // Пробуем получить is_urgent отдельно
            $order['is_urgent'] = safeGetIsUrgent($pdo, $order_id);

            $status_translate = [
                'pending'     => '⏳ Ожидает подтверждения',
                'in_progress' => '🎨 В процессе',
                'ready'       => '🟢 Готов',
                'declined'    => '❌ Отклонен',
            ];
            $cur_status = $status_translate[$order['status']] ?? $order['status'];

            $created   = new DateTime($order['created_at']);
            $now       = new DateTime();
            $days_ago  = (int)$created->diff($now)->days;
            $days_left = 5 - $days_ago;
            if ($days_left < 0) {
                $deadline_str = "🚨 Срок просрочен на " . abs($days_left) . " дн.";
            } elseif ($days_left === 0) {
                $deadline_str = "🔴 Сдаётся сегодня";
            } else {
                $deadline_str = "⏱ Осталось: {$days_left} дн.";
            }

            $service  = $order['service_title'] ?? $order['service_key'] ?? 'не указано';
            $price    = (!empty($order['price_rub'])) ? "{$order['price_rub']} ₽ / {$order['price_uan']} ₴" : 'не указана';
            $details  = $order['details'] ? mb_substr($order['details'], 0, 200) : 'не указано';
            $urgent   = !empty($order['is_urgent']) ? "🔴 Да" : "Нет";

            $has_check = !empty($order['screenshot'])    ? "✅ Прикреплён" : "❌ Не прикреплён";
            $has_ref   = !empty($order['example_photo']) ? "✅ Прикреплён" : "❌ Не прикреплён";

            $msg  = "📦 Заказ #{$order['id']}\n";
            $msg .= "Статус: {$cur_status}\n";
            $msg .= "Услуга: {$service}\n";
            $msg .= "Цена: {$price}\n";
            $msg .= "Срочный: {$urgent}\n";
            $msg .= "\nТЗ: {$details}\n";
            $msg .= "\nСоздан: {$order['created_at']}\n";
            $msg .= "{$deadline_str}\n";
            $msg .= "Чек оплаты: {$has_check}\n";
            $msg .= "Референс: {$has_ref}\n";

            sendTelegram($token, 'sendMessage', [
                'chat_id' => $chat_id,
                'text'    => $msg,
            ]);
        } else {
            sendTelegram($token, 'sendMessage', [
                'chat_id' => $chat_id,
                'text'    => "❌ Заказ #{$order_id} не найден. Проверь номер заказа.",
            ]);
        }
        exit;
    }

    sendTelegram($token, 'sendMessage', [
        'chat_id'      => $chat_id,
        'text'         => "Не понял команду. Нажми /menu, чтобы открыть кнопки.",
        'reply_markup' => mainKeyboard((string)$chat_id === $admin_id),
    ]);
    } catch (Throwable $e) {
        botLog("MESSAGE HANDLER ERROR: " . $e->getMessage() . " | text=" . ($text ?? ''));
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chat_id,
            'text'    => "Ошибка: " . $e->getMessage(),
        ]);
    }
}

// ── FUNCTIONS ────────────────────────────────────────────────────

/**
 * Безопасно получает is_urgent для заказа.
 * Если колонка не существует в БД — возвращает 0 без ошибки.
 */
function safeGetIsUrgent($pdo, $order_id) {
    try {
        $stmt = $pdo->prepare("SELECT is_urgent FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([$order_id]);
        $val = $stmt->fetchColumn();
        return (int)$val;
    } catch (Throwable $e) {
        botLog("safeGetIsUrgent: колонка is_urgent отсутствует — " . $e->getMessage());
        return 0;
    }
}

/**
 * Безопасно строит SELECT с is_urgent.
 * Если колонки нет — возвращает строки с is_urgent = 0.
 */
function safeQueryOrders($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Если упало из-за is_urgent — пробуем без неё
        botLog("safeQueryOrders fallback: " . $e->getMessage());
        $sql_fallback = preg_replace('/COALESCE\((?:o\.)?is_urgent,\s*0\)\s+AS\s+is_urgent[,]?/i', '0 AS is_urgent,', $sql);
        $sql_fallback = preg_replace('/(?:o\.)?is_urgent\s*=\s*1/i', '1=0', $sql_fallback);
        $sql_fallback = preg_replace('/COALESCE\((?:o\.)?is_urgent,\s*0\)\s*=\s*1/i', '1=0', $sql_fallback);
        // Убираем ORDER BY COALESCE(is_urgent...)
        $sql_fallback = preg_replace('/COALESCE\((?:o\.)?is_urgent,\s*0\)\s+DESC[,]?/i', '', $sql_fallback);
        try {
            $stmt2 = $pdo->prepare($sql_fallback);
            $stmt2->execute($params);
            return $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e2) {
            botLog("safeQueryOrders fallback2 failed: " . $e2->getMessage());
            return [];
        }
    }
}

function mainKeyboard($isAdmin) {
    $buttons = [
        [['text' => '🎨 Смотреть portfolio'], ['text' => '📋 Прайс-лист']],
        [['text' => '🤖 Сделать заказ']],
    ];
    if ($isAdmin) {
        $buttons[] = [['text' => '💻 Admin Panel']];
    }
    return ['keyboard' => $buttons, 'resize_keyboard' => true];
}

function adminReplyKeyboard() {
    return [
        'keyboard' => [
            [['text' => '🔴 Срочные заказы'], ['text' => '🗂️ Очередь заказов']],
            [['text' => '📊 Статистика'],      ['text' => '🌐 Открыть сайт']],
            [['text' => '🔙 Главное меню']],
        ],
        'resize_keyboard' => true,
    ];
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
    $clean_tg = cleanTelegramUsername($telegram);
    if ($clean_tg !== '') {
        $keyboard['inline_keyboard'][] = [
            ['text' => '💬 Написать клиенту', 'url' => "https://t.me/{$clean_tg}"],
        ];
    }
    return $keyboard;
}

function showAdminQueue($pdo, $token, $admin_id, $site_url) {
    $queue = safeQueryOrders($pdo, "
        SELECT id, username, telegram, service_key, details, screenshot, example_photo, status, created_at,
               COALESCE(is_urgent, 0) AS is_urgent
        FROM orders
        WHERE status IN ('pending', 'in_progress')
        ORDER BY created_at ASC, id ASC
    ");

    if (empty($queue)) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $admin_id, 'text' => '🎉 Очередь пустая. Активных заказов нет.']);
        return;
    }

    $message  = "📁 Активная очередь заказов:\n\n";
    $keyboard = ['inline_keyboard' => []];

    foreach ($queue as $item) {
        $deadline    = getDeadlineInfo($item['created_at']);
        $status_icon = ($item['status'] === 'in_progress') ? '🎨' : '⏳';
        $message    .= "{$status_icon} Заказ #{$item['id']} — {$deadline['text']}\n";
        $keyboard['inline_keyboard'][] = [[
            'text'          => "📦 Заказ #{$item['id']} • {$deadline['button']}",
            'callback_data' => "adm_view_{$item['id']}",
        ]];
    }

    sendTelegram($token, 'sendMessage', [
        'chat_id'      => $admin_id,
        'text'         => $message,
        'reply_markup' => $keyboard,
    ]);
}

function showUrgentQueue($pdo, $token, $admin_id, $site_url) {
    $queue = safeQueryOrders($pdo, "
        SELECT id, username, telegram, service_key, details, screenshot, example_photo, status, created_at,
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

    $message  = "🔴 Срочные заказы:\n\n";
    $keyboard = ['inline_keyboard' => []];

    foreach ($queue as $item) {
        $deadline    = getDeadlineInfo($item['created_at']);
        $urgent_mark = !empty($item['is_urgent']) ? '🔴 СРОЧНЫЙ' : '🚨 ПРОСРОЧЕН';
        $message    .= "{$urgent_mark} Заказ #{$item['id']} — {$deadline['text']}\n";
        $keyboard['inline_keyboard'][] = [[
            'text'          => "🔴 Заказ #{$item['id']} • {$deadline['button']}",
            'callback_data' => "adm_view_{$item['id']}",
        ]];
    }

    sendTelegram($token, 'sendMessage', [
        'chat_id'      => $admin_id,
        'text'         => $message,
        'reply_markup' => $keyboard,
    ]);
}

function sendAdminStats($pdo, $token, $admin_id) {
    try {
        $total    = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        $ready    = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='ready'")->fetchColumn();
        $active   = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','in_progress')")->fetchColumn();
        $declined = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='declined'")->fetchColumn();

        // Срочные — безопасно
        $urgent = 0;
        try {
            $urgent = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','in_progress') AND COALESCE(is_urgent,0)=1")->fetchColumn();
        } catch (Throwable $e) {
            botLog("stats urgent: " . $e->getMessage());
        }

        $overdue = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','in_progress') AND created_at <= NOW() - INTERVAL '5 days'")->fetchColumn();

        $income_rub = (float)$pdo->query("SELECT COALESCE(SUM(p.price_rub),0) FROM orders o LEFT JOIN prices p ON p.category_key=o.service_key WHERE o.status='ready'")->fetchColumn();
        $income_uan = (float)$pdo->query("SELECT COALESCE(SUM(p.price_uan),0) FROM orders o LEFT JOIN prices p ON p.category_key=o.service_key WHERE o.status='ready'")->fetchColumn();

        $msg  = "Статистика Kostlim Design\n\n";
        $msg .= "Всего заказов: {$total}\n";
        $msg .= "Активных: {$active}\n";
        $msg .= "Срочных: {$urgent}\n";
        $msg .= "Просроченных: {$overdue}\n";
        $msg .= "Выполненных: {$ready}\n";
        $msg .= "Отклонённых: {$declined}\n\n";
        $msg .= "Заработано: " . number_format($income_rub, 0, '.', ' ') . " руб / " . number_format($income_uan, 0, '.', ' ') . " грн";

        sendTelegram($token, 'sendMessage', ['chat_id' => $admin_id, 'text' => $msg]);
    } catch (Throwable $e) {
        botLog("sendAdminStats error: " . $e->getMessage());
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $admin_id,
            'text'    => "Ошибка статистики: " . $e->getMessage(),
        ]);
    }
}

function showAdminOrderDetails($pdo, $token, $admin_id, $site_url, $order_id) {
    // Запрос без is_urgent — получим его отдельно через safeGetIsUrgent
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

    // Безопасно получаем is_urgent
    $item['is_urgent'] = safeGetIsUrgent($pdo, $order_id);

    $price_stmt = $pdo->prepare("SELECT title, price_rub, price_uan FROM prices WHERE category_key = ? LIMIT 1");
    $price_stmt->execute([$item['service_key']]);
    $price_info = $price_stmt->fetch(PDO::FETCH_ASSOC);

    sendOrderPhotos($token, $admin_id, $item);

    // buildOrderCard использует MarkdownV2 — отправляем с MarkdownV2
    sendTelegram($token, 'sendMessage', [
        'chat_id'                  => $admin_id,
        'text'                     => buildOrderCard($item, $price_info ?: [], $site_url),
        'parse_mode'               => 'MarkdownV2',
        'disable_web_page_preview' => true,
        'reply_markup'             => orderKeyboard($item['id'], $item['status'], $item['telegram']),
    ]);
}

/**
 * Строит карточку заказа в формате MarkdownV2.
 * Все пользовательские данные экранируются через mdEscape().
 */
function buildOrderCard($item, $price_info, $site_url) {
    $created   = new DateTime($item['created_at']);
    $now       = new DateTime();
    $days_left = 5 - $created->diff($now)->days;
    $days_str  = ($days_left < 0) ? '🚨 ДЕДЛАЙН ПРОСРОЧЕН' : "{$days_left} дн\.";

    $status_text   = ['pending' => '⏳ Ожидает подтверждения', 'in_progress' => '🎨 В процессе'][$item['status']] ?? $item['status'];
    $service_title = $price_info['title'] ?? $item['service_key'];
    $p_rub         = (int)($price_info['price_rub'] ?? 0);
    $p_uan         = (int)($price_info['price_uan'] ?? 0);
    $urgent        = !empty($item['is_urgent']) ? '🔴 Да' : 'Нет';

    $order_id  = (int)$item['id'];

    $msg  = "📦 *ЗАКАЗ \#{$order_id}*";
    if (!empty($item['is_urgent'])) $msg .= " 🔴 *СРОЧНЫЙ*";
    $msg .= "\n\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\n";
    $msg .= "👤 *Имя:* "    . mdEscape($item['username'] ?? '') . "\n";
    $msg .= "📞 *Связь:* "   . mdEscape($item['telegram'] ?? '') . "\n";
    $msg .= "🎨 *Услуга:* "  . mdEscape($service_title) . "\n";
    $msg .= "💰 *Цена:* "    . mdEscape((string)$p_rub) . " ₽ / " . mdEscape((string)$p_uan) . " ₴\n";
    $msg .= "🚀 *Срочный:* " . mdEscape($urgent) . "\n";
    $msg .= "📝 *ТЗ:* "      . mdEscape($item['details'] ?? '') . "\n";
    $msg .= "\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\-\n";
    $msg .= "🔹 *Статус:* "  . mdEscape($status_text) . "\n";
    $msg .= "⏱ *Осталось:* {$days_str}\n";

    if (!empty($item['screenshot'])) {
        $file_url = $site_url . 'uploads/orders/' . rawurlencode($item['screenshot']);
        $msg .= "📸 *Чек:* [Открыть файл](" . mdEscape($file_url) . ")\n";
    } else {
        $msg .= "📸 *Чек:* _не прикреплен_\n";
    }
    if (!empty($item['example_photo'])) {
        $file_url = $site_url . 'uploads/orders/' . rawurlencode($item['example_photo']);
        $msg .= "🖼 *Референс:* [Открыть файл](" . mdEscape($file_url) . ")\n";
    } else {
        $msg .= "🖼 *Референс:* _не прикреплен_\n";
    }
    return $msg;
}

function getDeadlineInfo($created_at) {
    $created   = new DateTime($created_at);
    $now       = new DateTime();
    $days_left = 5 - $created->diff($now)->days;
    if ($days_left < 0) return ['text' => '🚨 срок просрочен', 'button' => 'просрочен'];
    return ['text' => "осталось {$days_left} дн.", 'button' => "{$days_left} дн."];
}

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
        sendTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'MarkdownV2']);
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
    $text = preg_replace('/[^\p{L}\p{N}\s\-_\/]/u', '', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

/**
 * Экранирует строку для MarkdownV2.
 * Используется во всех местах где parse_mode = MarkdownV2.
 */
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
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));
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