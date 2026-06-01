<?php

require_once __DIR__ . '/config/db.php';

$token    = getenv('BOT_TOKEN') ?: "8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg";
$admin_id = getenv('ADMIN_ID')  ?: "1710365896";
$site_url = getenv('SITE_URL')  ?: "https://portfolio-site-boo5.onrender.com/";

$input  = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) { exit; }

// ── CALLBACK QUERY ──────────────────────────────────────────────
if (isset($update['callback_query'])) {
    $callback_id   = $update['callback_query']['id'];
    $cal_chat_id   = $update['callback_query']['message']['chat']['id'];
    $msg_id        = $update['callback_query']['message']['message_id'];
    $callback_data = $update['callback_query']['data'] ?? '';

    // Только админ может нажимать inline-кнопки
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
            'text'         => "🚀 *Заказ #{$order_id} взят в работу.*",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(orderKeyboard($order_id, 'in_progress', getOrderTelegram($pdo, $order_id)), JSON_UNESCAPED_UNICODE),
        ]);
        notifyClient($pdo, $token, $order_id, "🎨 Ваш заказ *#{$order_id}* взят в работу\\. Дизайнер уже начал выполнение\\.");
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Заказ взят в работу']);
        exit;
    }

    if (strpos($callback_data, 'adm_ready_') === 0) {
        $order_id = (int)str_replace('adm_ready_', '', $callback_data);
        $pdo->prepare("UPDATE orders SET status = 'ready' WHERE id = ?")->execute([$order_id]);
        sendTelegram($token, 'editMessageText', [
            'chat_id'    => $cal_chat_id,
            'message_id' => $msg_id,
            'text'       => "✅ *Заказ #{$order_id} успешно выполнен.*",
            'parse_mode' => 'Markdown',
        ]);
        notifyClient($pdo, $token, $order_id, "🎉 Ваш заказ *#{$order_id}* готов\\! Дизайнер свяжется с вами для передачи финальных файлов\\.");
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Заказ выполнен']);
        exit;
    }

    if (strpos($callback_data, 'adm_dec_') === 0) {
        $order_id = (int)str_replace('adm_dec_', '', $callback_data);
        $pdo->prepare("UPDATE orders SET status = 'declined' WHERE id = ?")->execute([$order_id]);
        sendTelegram($token, 'editMessageText', [
            'chat_id'    => $cal_chat_id,
            'message_id' => $msg_id,
            'text'       => "❌ *Заказ #{$order_id} отклонен.*",
            'parse_mode' => 'Markdown',
        ]);
        notifyClient($pdo, $token, $order_id, "🔴 К сожалению, ваш заказ *#{$order_id}* был отклонён дизайнером\\.");
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Заказ отклонен']);
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

    // /start и /menu
    if ($text === '/start' || $text === '/menu') {
        sendTelegram($token, 'sendMessage', [
            'chat_id'      => $chat_id,
            'text'         => "👋 *Привет! Добро пожаловать в Kostlim Design!*\n\nЗдесь можно посмотреть портфолио, узнать актуальный прайс, отправить ТЗ и проверить статус заказа.",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(mainKeyboard((string)$chat_id === $admin_id), JSON_UNESCAPED_UNICODE),
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
            $title    = mdEscape($p['title'] ?? 'Услуга');
            $rub      = mdEscape((string)($p['price_rub'] ?? 0));
            $uan      = mdEscape((string)($p['price_uan'] ?? 0));
            $desc     = trim((string)($p['description'] ?? ''));
            $price_msg .= "▪️ *{$title}:* {$rub} ₽ / {$uan} ₴\n";
            if ($desc !== '') {
                $price_msg .= "_" . mdEscape($desc) . "_\n";
            }
            foreach (explode('|', (string)($p['features'] ?? '')) as $feature) {
                $feature = trim($feature);
                if ($feature !== '') {
                    $price_msg .= " • " . mdEscape($feature) . "\n";
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

    // Admin Panel (кнопка или команда)
    if ($text === '/admin' || $text_key === 'admin panel' || $text_key === 'админ панель') {
        if ((string)$chat_id !== $admin_id) {
            sendTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => '⛔ Доступ закрыт.']);
            exit;
        }
        sendTelegram($token, 'sendMessage', [
            'chat_id'      => $admin_id,
            'text'         => "⚙️ *Админ-панель Kostlim Design*\n\nВыбери действие:",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(adminKeyboard(), JSON_UNESCAPED_UNICODE),
        ]);
        exit;
    }

    // ── КНОПКА "Очередь заказов" (reply keyboard) ────────────────
    if ($text_key === 'очередь заказов' || $text_key === 'очередь') {
        if ((string)$chat_id !== $admin_id) {
            sendTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => '⛔ Доступ закрыт.']);
            exit;
        }
        showAdminQueue($pdo, $token, $admin_id, $site_url);
        exit;
    }

    // ── КНОПКА "Срочные заказы" (reply keyboard) ─────────────────
    if ($text_key === 'срочные заказы' || $text_key === 'срочные') {
        if ((string)$chat_id !== $admin_id) {
            sendTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => '⛔ Доступ закрыт.']);
            exit;
        }
        showUrgentQueue($pdo, $token, $admin_id, $site_url);
        exit;
    }

    // ── КНОПКА "Статистика" (reply keyboard) ─────────────────────
    if ($text_key === 'статистика') {
        if ((string)$chat_id !== $admin_id) {
            sendTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => '⛔ Доступ закрыт.']);
            exit;
        }
        sendAdminStats($pdo, $token, $admin_id);
        exit;
    }

    // ── КНОПКА "Открыть сайт" ────────────────────────────────────
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

    // ── /status_XX — расширенная инфо по заказу ──────────────────
    if (strpos($text, '/status_') === 0) {
        $order_id = (int)str_replace('/status_', '', $text);

        // Привязываем chat_id клиента к заказу
        $pdo->prepare("UPDATE orders SET client_chat_id = ? WHERE id = ?")->execute([$chat_id, $order_id]);

        $o_stmt = $pdo->prepare("
            SELECT o.id, o.status, o.created_at, o.details, o.username, o.telegram,
                   o.service_key, o.screenshot, o.example_photo, o.is_urgent,
                   p.title AS service_title, p.price_rub, p.price_uan
            FROM orders o
            LEFT JOIN prices p ON p.category_key = o.service_key
            WHERE o.id = ?
            LIMIT 1
        ");
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

            // Считаем дедлайн (5 дней)
            $created  = new DateTime($order['created_at']);
            $now      = new DateTime();
            $days_ago = (int)$created->diff($now)->days;
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

            $has_check = !empty($order['screenshot']) ? "✅ Прикреплён" : "❌ Не прикреплён";
            $has_ref   = !empty($order['example_photo']) ? "✅ Прикреплён" : "❌ Не прикреплён";

            $msg  = "📦 *Заказ #{$order['id']}*\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "🔹 *Статус:* {$cur_status}\n";
            $msg .= "🎨 *Услуга:* " . mdEscape($service) . "\n";
            $msg .= "💰 *Цена:* " . mdEscape($price) . "\n";
            $msg .= "🚀 *Срочный:* {$urgent}\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "📝 *ТЗ:* " . mdEscape($details) . "\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "📅 *Создан:* " . mdEscape($order['created_at']) . "\n";
            $msg .= "{$deadline_str}\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "📸 *Чек оплаты:* {$has_check}\n";
            $msg .= "🖼 *Референс:* {$has_ref}\n";

            sendTelegram($token, 'sendMessage', [
                'chat_id'    => $chat_id,
                'text'       => $msg,
                'parse_mode' => 'Markdown',
            ]);
        } else {
            sendTelegram($token, 'sendMessage', [
                'chat_id' => $chat_id,
                'text'    => "❌ Заказ #{$order_id} не найден\\. Проверь номер заказа\\.",
                'parse_mode' => 'MarkdownV2',
            ]);
        }
        exit;
    }

    // Неизвестная команда
    sendTelegram($token, 'sendMessage', [
        'chat_id'      => $chat_id,
        'text'         => "Не понял команду. Нажми /menu, чтобы открыть кнопки.",
        'reply_markup' => json_encode(mainKeyboard((string)$chat_id === $admin_id), JSON_UNESCAPED_UNICODE),
    ]);
}

// ── FUNCTIONS ────────────────────────────────────────────────────

function mainKeyboard($isAdmin) {
    $buttons = [
        [['text' => '🎨 Смотреть portfolio'], ['text' => '📋 Прайс-лист']],
        [['text' => '🤖 Сделать заказ']],
    ];
    if ($isAdmin) {
        $buttons[] = [['text' => '💻 Admin Panel']];
        $buttons[] = [
            ['text' => '🔴 Срочные заказы'],
            ['text' => '🗂️ Очередь заказов'],
        ];
        $buttons[] = [
            ['text' => '📊 Статистика'],
            ['text' => '🌐 Открыть сайт'],
        ];
    }
    return ['keyboard' => $buttons, 'resize_keyboard' => true];
}

function adminKeyboard() {
    return [
        'inline_keyboard' => [
            [['text' => '🔴 Срочные заказы',          'callback_data' => 'adm_urgent']],
            [['text' => '🗂️ Показать очередь заказов', 'callback_data' => 'adm_show_queue']],
            [['text' => '📊 Быстрая статистика',        'callback_data' => 'adm_stats']],
        ],
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

// Показать обычную очередь заказов
function showAdminQueue($pdo, $token, $admin_id, $site_url) {
    $q_stmt = $pdo->query("
        SELECT id, username, telegram, service_key, details, screenshot, example_photo, status, created_at
        FROM orders
        WHERE status IN ('pending', 'in_progress')
        ORDER BY created_at ASC, id ASC
    ");
    $queue = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($queue)) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $admin_id, 'text' => '🎉 Очередь пустая. Активных заказов нет.']);
        return;
    }

    $message  = "📁 *Активная очередь заказов:*\n\n";
    $keyboard = ['inline_keyboard' => []];

    foreach ($queue as $item) {
        $deadline     = getDeadlineInfo($item['created_at']);
        $status_icon  = ($item['status'] === 'in_progress') ? '🎨' : '⏳';
        $message     .= "{$status_icon} *Заказ #{$item['id']}* — {$deadline['text']}\n";
        $keyboard['inline_keyboard'][] = [[
            'text'          => "📦 Заказ #{$item['id']} • {$deadline['button']}",
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

// Показать СРОЧНЫЕ заказы
function showUrgentQueue($pdo, $token, $admin_id, $site_url) {
    // Срочные = is_urgent = 1 ИЛИ дедлайн просрочен
    $q_stmt = $pdo->query("
        SELECT id, username, telegram, service_key, details, screenshot, example_photo, status, created_at, is_urgent
        FROM orders
        WHERE status IN ('pending', 'in_progress')
          AND (is_urgent = 1 OR created_at <= DATE_SUB(NOW(), INTERVAL 5 DAY))
        ORDER BY is_urgent DESC, created_at ASC
    ");
    $queue = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($queue)) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $admin_id, 'text' => '✅ Срочных заказов нет.']);
        return;
    }

    $message  = "🔴 *Срочные заказы:*\n\n";
    $keyboard = ['inline_keyboard' => []];

    foreach ($queue as $item) {
        $deadline    = getDeadlineInfo($item['created_at']);
        $urgent_mark = !empty($item['is_urgent']) ? '🔴 СРОЧНЫЙ' : '🚨 ПРОСРОЧЕН';
        $message    .= "{$urgent_mark} *Заказ #{$item['id']}* — {$deadline['text']}\n";
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

// Отправить статистику
function sendAdminStats($pdo, $token, $admin_id) {
    $total     = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $ready     = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='ready'")->fetchColumn();
    $active    = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending', 'in_progress')")->fetchColumn();
    $declined  = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='declined'")->fetchColumn();
    $urgent    = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','in_progress') AND is_urgent=1")->fetchColumn();
    $overdue   = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','in_progress') AND created_at <= DATE_SUB(NOW(), INTERVAL 5 DAY)")->fetchColumn();

    // Доход (готовые заказы)
    $income_rub = (float)$pdo->query("
        SELECT COALESCE(SUM(p.price_rub), 0)
        FROM orders o
        LEFT JOIN prices p ON p.category_key = o.service_key
        WHERE o.status = 'ready'
    ")->fetchColumn();
    $income_uan = (float)$pdo->query("
        SELECT COALESCE(SUM(p.price_uan), 0)
        FROM orders o
        LEFT JOIN prices p ON p.category_key = o.service_key
        WHERE o.status = 'ready'
    ")->fetchColumn();

    $msg  = "📊 *Статистика Kostlim Design*\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "📥 Всего заказов: *{$total}*\n";
    $msg .= "🔥 Активных: *{$active}*\n";
    $msg .= "🔴 Срочных: *{$urgent}*\n";
    $msg .= "🚨 Просроченных: *{$overdue}*\n";
    $msg .= "✅ Выполненных: *{$ready}*\n";
    $msg .= "❌ Отклонённых: *{$declined}*\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "💰 Заработано: *" . number_format($income_rub, 0, '.', ' ') . " ₽* / *" . number_format($income_uan, 0, '.', ' ') . " ₴*\n";

    sendTelegram($token, 'sendMessage', [
        'chat_id'    => $admin_id,
        'text'       => $msg,
        'parse_mode' => 'Markdown',
    ]);
}

function showAdminOrderDetails($pdo, $token, $admin_id, $site_url, $order_id) {
    $o_stmt = $pdo->prepare("
        SELECT id, username, telegram, service_key, details, screenshot, example_photo, status, created_at, is_urgent
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
    $created   = new DateTime($item['created_at']);
    $now       = new DateTime();
    $days_left = 5 - $created->diff($now)->days;
    $days_str  = ($days_left < 0) ? '🚨 ДЕДЛАЙН ПРОСРОЧЕН' : "{$days_left} дн.";

    $status_text   = ['pending' => '⏳ Ожидает подтверждения', 'in_progress' => '🎨 В процессе'][$item['status']] ?? $item['status'];
    $service_title = $price_info['title'] ?? $item['service_key'];
    $p_rub         = $price_info['price_rub'] ?? 0;
    $p_uan         = $price_info['price_uan'] ?? 0;
    $urgent        = !empty($item['is_urgent']) ? '🔴 Да' : 'Нет';

    $msg  = "📦 *ЗАКАЗ #{$item['id']}*";
    if (!empty($item['is_urgent'])) $msg .= " 🔴 *СРОЧНЫЙ*";
    $msg .= "\n-------------------------\n";
    $msg .= "👤 *Имя:* "    . mdEscape($item['username'] ?? '') . "\n";
    $msg .= "📞 *Связь:* "   . mdEscape($item['telegram'] ?? '') . "\n";
    $msg .= "🎨 *Услуга:* "  . mdEscape($service_title) . "\n";
    $msg .= "💰 *Цена:* "    . mdEscape((string)$p_rub) . " ₽ / " . mdEscape((string)$p_uan) . " ₴\n";
    $msg .= "🚀 *Срочный:* " . $urgent . "\n";
    $msg .= "📝 *ТЗ:* "      . mdEscape($item['details'] ?? '') . "\n";
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