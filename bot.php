<?php

require_once __DIR__ . '/config/db.php';

$token    = getenv('BOT_TOKEN') ?: "8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg";
$admin_id = getenv('ADMIN_ID')  ?: "1710365896";
$site_url = getenv('SITE_URL')  ?: "https://kostlimdzn.kesug.com/";

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

    botLog("callback chat={$cal_chat_id} data={$callback_data}");

    // ── Клиентские колбэки (просмотр своего заказа) ──
    if (strpos($callback_data, 'cli_view_') === 0) {
        $order_id = (int)str_replace('cli_view_', '', $callback_data);
        showClientOrderDetails($pdo, $token, $cal_chat_id, $order_id);
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id]);
        exit;
    }

    if ($callback_data === 'cli_cabinet') {
        showCabinet($pdo, $token, $cal_chat_id);
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id]);
        exit;
    }

    // ── Только для админа ──
    if ((string)$cal_chat_id !== $admin_id) {
        sendTelegram($token, 'answerCallbackQuery', [
            'callback_query_id' => $callback_id,
            'text'              => 'Доступ закрыт',
            'show_alert'        => true,
        ]);
        exit;
    }

    if ($callback_data === 'adm_show_queue') {
        showAdminQueue($pdo, $token, $admin_id, $site_url);
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
        $total  = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        $ready  = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='ready'")->fetchColumn();
        $active = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','in_progress','urgent')")->fetchColumn();
        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $admin_id,
            'text'       => "📊 *Быстрая статистика*\n\n📥 Всего заказов: *{$total}*\n🔥 Активных: *{$active}*\n✅ Выполненных: *{$ready}*",
            'parse_mode' => 'Markdown',
        ]);
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id]);
        exit;
    }

    // Взять в работу
    if (strpos($callback_data, 'adm_work_') === 0) {
        $order_id = (int)str_replace('adm_work_', '', $callback_data);
        $deadline = date('Y-m-d H:i:s', time() + 5 * 86400); // +5 дней
        $pdo->prepare("UPDATE orders SET status = 'in_progress', deadline = ? WHERE id = ?")->execute([$deadline, $order_id]);
        sendTelegram($token, 'editMessageReplyMarkup', [
            'chat_id'      => $cal_chat_id,
            'message_id'   => $msg_id,
            'reply_markup' => json_encode(orderKeyboard($order_id, 'in_progress', getOrderTelegram($pdo, $order_id)), JSON_UNESCAPED_UNICODE),
        ]);
        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $admin_id,
            'text'       => "🚀 *Заказ #{$order_id} взят в работу.*\n📅 Дедлайн: " . date('d.m.Y в H:i', strtotime($deadline)),
            'parse_mode' => 'Markdown',
        ]);
        // Уведомляем клиента — безопасно, без краша
        safeNotifyClient($pdo, $token, $order_id,
            "🎨 *Ваш заказ #{$order_id} принят в работу!*\n\nДизайнер уже начал выполнение. Дедлайн: *" . date('d.m.Y в H:i', strtotime($deadline)) . "*\n\nМы сообщим вам, когда заказ будет готов."
        );
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Заказ взят в работу']);
        exit;
    }

    // Срочный
    if (strpos($callback_data, 'adm_urgent_') === 0) {
        $order_id = (int)str_replace('adm_urgent_', '', $callback_data);
        $deadline = date('Y-m-d H:i:s', time() + 24 * 3600); // +24 часа
        $pdo->prepare("UPDATE orders SET status = 'urgent', deadline = ? WHERE id = ?")->execute([$deadline, $order_id]);
        sendTelegram($token, 'editMessageReplyMarkup', [
            'chat_id'      => $cal_chat_id,
            'message_id'   => $msg_id,
            'reply_markup' => json_encode(orderKeyboard($order_id, 'urgent', getOrderTelegram($pdo, $order_id)), JSON_UNESCAPED_UNICODE),
        ]);
        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $admin_id,
            'text'       => "⚡️ *Заказ #{$order_id} переведён в СРОЧНЫЙ режим.*\n📅 Дедлайн: " . date('d.m.Y в H:i', strtotime($deadline)),
            'parse_mode' => 'Markdown',
        ]);
        // Уведомляем клиента
        safeNotifyClient($pdo, $token, $order_id,
            "⚡️ *Ваш заказ #{$order_id} переведён в СРОЧНЫЙ режим!*\n\nДизайнер выполнит его в приоритетном порядке. Дедлайн: *" . date('d.m.Y в H:i', strtotime($deadline)) . "*"
        );
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '⚡ Заказ срочный']);
        exit;
    }

    // Выполнен
    if (strpos($callback_data, 'adm_ready_') === 0) {
        $order_id = (int)str_replace('adm_ready_', '', $callback_data);
        $pdo->prepare("UPDATE orders SET status = 'ready' WHERE id = ?")->execute([$order_id]);
        sendTelegram($token, 'editMessageReplyMarkup', [
            'chat_id'      => $cal_chat_id,
            'message_id'   => $msg_id,
            'reply_markup' => json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE),
        ]);
        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $admin_id,
            'text'       => "✅ *Заказ #{$order_id} выполнен.*",
            'parse_mode' => 'Markdown',
        ]);
        // Уведомляем клиента
        safeNotifyClient($pdo, $token, $order_id,
            "🎉 *Ваш заказ #{$order_id} готов!*\n\nДизайнер свяжется с вами для передачи финальных файлов. Спасибо, что выбрали Kostlim Design!"
        );
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ Заказ выполнен']);
        exit;
    }

    // Отклонить
    if (strpos($callback_data, 'adm_dec_') === 0) {
        $order_id = (int)str_replace('adm_dec_', '', $callback_data);
        $pdo->prepare("UPDATE orders SET status = 'declined' WHERE id = ?")->execute([$order_id]);
        sendTelegram($token, 'editMessageReplyMarkup', [
            'chat_id'      => $cal_chat_id,
            'message_id'   => $msg_id,
            'reply_markup' => json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE),
        ]);
        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $admin_id,
            'text'       => "❌ *Заказ #{$order_id} отклонён.*",
            'parse_mode' => 'Markdown',
        ]);
        safeNotifyClient($pdo, $token, $order_id,
            "🔴 *Заказ #{$order_id} отклонён.*\n\nК сожалению, дизайнер не смог принять ваш заказ. По вопросам: @Perlo_ovka"
        );
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Заказ отклонён']);
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

    botLog("message chat={$chat_id} text={$text}");

    // Автоматически привязываем chat_id к заказам по username при каждом сообщении
    $msg_username = $update['message']['from']['username'] ?? '';
    if ($msg_username !== '') {
        try {
            $pdo->prepare("
                UPDATE orders SET client_chat_id = ?
                WHERE (client_chat_id IS NULL OR client_chat_id = '')
                  AND (telegram = ? OR telegram = ? OR telegram = ? OR telegram = ?)
            ")->execute([
                (string)$chat_id,
                '@' . $msg_username,
                $msg_username,
                'https://t.me/' . $msg_username,
                't.me/' . $msg_username,
            ]);
        } catch (Throwable $e) {}
    }

    // Если первое сообщение от админа и клавиатура слетела — восстанавливаем нужную
    // (определяется по /start — бот только запущен или переоткрыт)
    if ((string)$chat_id === $admin_id && $text === '/start') {
        sendTelegram($token, 'sendMessage', [
            'chat_id'      => $admin_id,
            'text'         => "👋 *Добро пожаловать, Админ!*\n\nГлавное меню восстановлено.",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(mainKeyboard(true), JSON_UNESCAPED_UNICODE),
        ]);
        exit;
    }

    // /start — может быть с параметром order_id для привязки chat_id или link_КОД для привязки сайта
    if (strpos($text, '/start') === 0) {
        $param = trim(str_replace('/start', '', $text));

        // /start link_АБCDEF — пользователь перешёл с сайта по кнопке «Открыть бот»
        if (preg_match('/^link_([A-Z0-9]{4,10})$/i', $param, $m)) {
            $site_code = strtoupper($m[1]);
            botLog("/start link_ handler: code={$site_code}");
            linkTgAccount($pdo, $token, $chat_id, $update['message'], $site_code);
            exit;
        }

        // /start order_22 — клиент пришёл по ссылке из уведомления о заказе
        if (preg_match('/^order_(\d+)$/', $param, $m)) {
            $order_id = (int)$m[1];
            // Сохраняем chat_id клиента в заказе
            $pdo->prepare("UPDATE orders SET client_chat_id = ? WHERE id = ?")->execute([$chat_id, $order_id]);
            botLog("Привязан chat_id={$chat_id} к заказу #{$order_id}");

            $o = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
            $o->execute([$order_id]);
            $order = $o->fetch(PDO::FETCH_ASSOC);
            $status_text = statusLabel($order['status'] ?? 'pending');

            sendTelegram($token, 'sendMessage', [
                'chat_id'    => $chat_id,
                'text'       => "✅ *Вы подписались на уведомления по заказу #{$order_id}*\n\n🔹 Текущий статус: {$status_text}\n\nМы будем присылать вам обновления автоматически.",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(mainKeyboard(false), JSON_UNESCAPED_UNICODE),
            ]);
            exit;
        }

        // Обычный /start — привязываем chat_id к заказам по username
        $user_from    = $update['message']['from'] ?? [];
        $start_uname  = $user_from['username'] ?? '';
        if ($start_uname !== '') {
            try {
                $pdo->prepare("
                    UPDATE orders SET client_chat_id = ?
                    WHERE (client_chat_id IS NULL OR client_chat_id = '')
                      AND (telegram = ? OR telegram = ? OR telegram = ? OR telegram = ?)
                ")->execute([
                    (string)$chat_id,
                    '@' . $start_uname,
                    $start_uname,
                    'https://t.me/' . $start_uname,
                    't.me/' . $start_uname,
                ]);
                botLog("/start auto-linked chat_id={$chat_id} username={$start_uname}");
            } catch (Throwable $e) {}
        }

        sendTelegram($token, 'sendMessage', [
            'chat_id'      => $chat_id,
            'text'         => "👋 *Привет! Добро пожаловать в Kostlim Design!*\n\nЗдесь можно посмотреть портфолио, узнать актуальный прайс, отправить ТЗ и проверить статус заказа.",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(mainKeyboard((string)$chat_id === $admin_id), JSON_UNESCAPED_UNICODE),
        ]);
        exit;
    }

    // /customer_КОД — ручная отправка кода привязки (альтернатива кнопке)
    if (strpos($text, '/customer_') === 0) {
        $site_code = strtoupper(trim(str_replace('/customer_', '', $text)));
        botLog("customer_ handler: code={$site_code} raw={$text}");
        if ($site_code !== '' && preg_match('/^[A-Z0-9]{4,10}$/', $site_code)) {
            linkTgAccount($pdo, $token, $chat_id, $update['message'], $site_code);
        } else {
            sendTelegram($token, 'sendMessage', [
                'chat_id'    => $chat_id,
                'text'       => "❌ Неверный формат кода. Проверь код на сайте и попробуй снова.",
                'parse_mode' => 'Markdown',
            ]);
        }
        exit;
    }

    if ($text === '/menu') {
        sendTelegram($token, 'sendMessage', [
            'chat_id'      => $chat_id,
            'text'         => "👋 *Главное меню Kostlim Design*",
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

    // Личный кабинет — кнопка или команда
    if ($text_key === 'личный кабинет' || $text_key === 'мои заказы' || $text === '/cabinet') {
        showCabinet($pdo, $token, $chat_id);
        exit;
    }

    // ── Открыть админ-панель ──────────────────────────────────────
    if ($text === '/admin' || $text_key === 'admin panel' || $text_key === 'админ панель' || $text === '⚙️ Админ-панель') {
        if ((string)$chat_id !== $admin_id) {
            sendTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => '⛔ Доступ закрыт.']);
            exit;
        }
        sendTelegram($token, 'sendMessage', [
            'chat_id'      => $admin_id,
            'text'         => "⚙️ *Админ-панель Kostlim Design*\n\nВыбери действие из меню 👇",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(adminReplyKeyboard(), JSON_UNESCAPED_UNICODE),
        ]);
        exit;
    }

    // ── Обработка кнопок AdminReplyKeyboard (только для админа) ──
    if ((string)$chat_id === $admin_id) {

        if ($text === '🗂 Очередь заказов') {
            showAdminQueue($pdo, $token, $admin_id, $site_url);
            exit;
        }

        if ($text === '📊 Статистика') {
            $total    = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
            $ready    = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='ready'")->fetchColumn();
            $active   = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','in_progress','urgent')")->fetchColumn();
            $declined = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='declined'")->fetchColumn();
            $tg_links = 0;
            try { $tg_links = (int)$pdo->query("SELECT COUNT(*) FROM tg_links WHERE linked=TRUE")->fetchColumn(); } catch(Throwable $e){}
            sendTelegram($token, 'sendMessage', [
                'chat_id'    => $admin_id,
                'text'       => "📊 *Статистика*\n\n"
                    . "📦 Всего заказов: *{$total}*\n"
                    . "🚀 Активных: *{$active}*\n"
                    . "✅ Выполнено: *{$ready}*\n"
                    . "❌ Отклонено: *{$declined}*\n"
                    . "🔗 TG привязок: *{$tg_links}*",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(adminReplyKeyboard(), JSON_UNESCAPED_UNICODE),
            ]);
            exit;
        }

        if ($text === '💾 Бэкап БД') {
            sendTelegram($token, 'sendMessage', [
                'chat_id'      => $admin_id,
                'text'         => '⏳ Генерирую SQL-дамп…',
                'reply_markup' => json_encode(adminReplyKeyboard(), JSON_UNESCAPED_UNICODE),
            ]);
            adminSendDbBackup($pdo, $token, $admin_id);
            exit;
        }

        if ($text === '🔗 Привязки TG') {
            try {
                $rows = $pdo->query("SELECT tg_username, tg_first_name, tg_id, created_at FROM tg_links WHERE linked=TRUE ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
                if (empty($rows)) {
                    $msg = "🔗 *Привязки TG*\n\nПока никто не привязал Telegram.";
                } else {
                    $msg = "🔗 *Привязки TG* (" . count($rows) . " шт)\n\n";
                    foreach ($rows as $r) {
                        $name  = $r['tg_first_name'] ?: '—';
                        $uname = $r['tg_username'] ? '@' . $r['tg_username'] : '—';
                        $date  = date('d.m.Y', strtotime($r['created_at']));
                        $msg  .= "• {$name} {$uname} ({$date})\n";
                    }
                }
            } catch (Throwable $e) { $msg = "❌ Ошибка: " . $e->getMessage(); }
            sendTelegram($token, 'sendMessage', [
                'chat_id'    => $admin_id,
                'text'       => $msg,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(adminReplyKeyboard(), JSON_UNESCAPED_UNICODE),
            ]);
            exit;
        }

        if ($text === '🐛 Диагностика БД') {
            try {
                $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
                $msg = "🐛 *Диагностика БД*\n\nТаблицы:\n";
                foreach ($tables as $t) {
                    $cnt  = (int)$pdo->query("SELECT COUNT(*) FROM \"{$t}\"")->fetchColumn();
                    $msg .= "• `{$t}` — {$cnt} строк\n";
                }
                if (in_array('tg_links', $tables)) {
                    $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='tg_links' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
                    $msg .= "\ntg\\_links колонки: " . implode(', ', $cols);
                } else {
                    $msg .= "\n⚠️ Таблица tg\\_links отсутствует!";
                }
            } catch (Throwable $e) { $msg = "❌ Ошибка: " . $e->getMessage(); }
            sendTelegram($token, 'sendMessage', [
                'chat_id'    => $admin_id,
                'text'       => $msg,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(adminReplyKeyboard(), JSON_UNESCAPED_UNICODE),
            ]);
            exit;
        }

        if ($text === '🔧 Починить БД') {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS tg_links (
                    id SERIAL PRIMARY KEY,
                    site_code VARCHAR(20) NOT NULL,
                    session_id VARCHAR(128) NOT NULL DEFAULT '',
                    linked BOOLEAN NOT NULL DEFAULT FALSE,
                    tg_id VARCHAR(64) DEFAULT NULL,
                    tg_username VARCHAR(128) DEFAULT NULL,
                    tg_first_name VARCHAR(255) DEFAULT NULL,
                    tg_photo_url TEXT DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT uniq_tg_links_code UNIQUE (site_code)
                )");
                foreach (['tg_id VARCHAR(64)', 'tg_username VARCHAR(128)', 'tg_first_name VARCHAR(255)', 'tg_photo_url TEXT'] as $col) {
                    try { $pdo->exec("ALTER TABLE tg_links ADD COLUMN IF NOT EXISTS {$col} DEFAULT NULL"); } catch(Throwable $e){}
                }
                $msg = "✅ *БД починена!*\n\nТаблица tg\\_links готова — привязка TG должна работать.";
            } catch (Throwable $e) { $msg = "❌ Ошибка: " . $e->getMessage(); }
            sendTelegram($token, 'sendMessage', [
                'chat_id'    => $admin_id,
                'text'       => $msg,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(adminReplyKeyboard(), JSON_UNESCAPED_UNICODE),
            ]);
            exit;
        }

        if ($text === '◀️ Главное меню') {
            sendTelegram($token, 'sendMessage', [
                'chat_id'      => $admin_id,
                'text'         => "🏠 *Главное меню*",
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(mainKeyboard(true), JSON_UNESCAPED_UNICODE),
            ]);
            exit;
        }

        // ── Очистка всех заказов ────────────────────────────────────
        if ($text === '🗑 Очистить все заказы') {
            $captcha = strtoupper(substr(md5(uniqid()), 0, 5));
            file_put_contents(sys_get_temp_dir() . '/clear_captcha_' . $admin_id . '.txt', $captcha . '|' . (time() + 120));
            sendTelegram($token, 'sendMessage', [
                'chat_id'      => $admin_id,
                'text'         => "⚠️ *ВНИМАНИЕ! Опасная операция!*\n\n🗑 Это удалит *ВСЕ заказы, обращения и историю* безвозвратно.\n\n📊 Статистика дохода также обнулится.\n\nДля подтверждения введи этот код:\n\n`{$captcha}`\n\n_Код действителен 2 минуты._",
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['keyboard' => [[['text' => '◀️ Главное меню']]], 'resize_keyboard' => true], JSON_UNESCAPED_UNICODE),
            ]);
            exit;
        }

        // Проверяем — может пользователь ввёл код подтверждения очистки
        $captchaFile = sys_get_temp_dir() . '/clear_captcha_' . $admin_id . '.txt';
        if (file_exists($captchaFile)) {
            $parts   = explode('|', file_get_contents($captchaFile));
            $stored  = trim($parts[0] ?? '');
            $expires = (int)($parts[1] ?? 0);
            if ($expires > time() && strtoupper(trim($text)) === $stored) {
                unlink($captchaFile);
                try {
                    $pdo->exec("TRUNCATE TABLE appeals_messages RESTART IDENTITY CASCADE");
                    $pdo->exec("TRUNCATE TABLE appeals RESTART IDENTITY CASCADE");
                    $pdo->exec("TRUNCATE TABLE orders RESTART IDENTITY CASCADE");
                    sendTelegram($token, 'sendMessage', [
                        'chat_id'      => $admin_id,
                        'text'         => "✅ *База данных очищена!*\n\nВсе заказы, обращения и история удалены.\nСтатистика обнулена.",
                        'parse_mode'   => 'Markdown',
                        'reply_markup' => json_encode(adminReplyKeyboard(), JSON_UNESCAPED_UNICODE),
                    ]);
                } catch (Throwable $e) {
                    sendTelegram($token, 'sendMessage', [
                        'chat_id'      => $admin_id,
                        'text'         => "❌ Ошибка очистки: " . $e->getMessage(),
                        'parse_mode'   => 'Markdown',
                        'reply_markup' => json_encode(adminReplyKeyboard(), JSON_UNESCAPED_UNICODE),
                    ]);
                }
                exit;
            } elseif ($expires <= time()) {
                unlink($captchaFile);
            }
        }
    } // end if admin

    // /status_X — клиент проверяет статус и привязывает chat_id
    if (strpos($text, '/status_') === 0) {
        $order_id = (int)str_replace('/status_', '', $text);
        // Привязываем chat_id если ещё не привязан
        $pdo->prepare("UPDATE orders SET client_chat_id = ? WHERE id = ? AND (client_chat_id IS NULL OR client_chat_id = '')")
            ->execute([$chat_id, $order_id]);

        $o_stmt = $pdo->prepare("SELECT status, created_at FROM orders WHERE id = ?");
        $o_stmt->execute([$order_id]);
        $order = $o_stmt->fetch(PDO::FETCH_ASSOC);

        if ($order) {
            $cur_status = statusLabel($order['status']);
            sendTelegram($token, 'sendMessage', [
                'chat_id'    => $chat_id,
                'text'       => "📦 *Заказ #{$order_id}*\n\n🔹 *Статус:* {$cur_status}\n📅 *Дата создания:* {$order['created_at']}\n\n_Вы подписаны на уведомления об изменении статуса._",
                'parse_mode' => 'Markdown',
            ]);
        } else {
            sendTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => "Заказ #{$order_id} не найден."]);
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

// ═══════════════════════════════════════════════════════════════
// ФУНКЦИИ
// ═══════════════════════════════════════════════════════════════

/**
 * Привязать Telegram-аккаунт к сессии на сайте по site_code.
 * Сохраняет tg_id, username, first_name, photo_url в tg_links.
 */
function linkTgAccount($pdo, $token, $chat_id, $message, $site_code) {
    botLog("linkTgAccount chat_id={$chat_id} code={$site_code}");

    try {
        // Проверяем — есть ли такой код в таблице
        $stmt = $pdo->prepare("SELECT id, linked FROM tg_links WHERE site_code = ? LIMIT 1");
        $stmt->execute([$site_code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        botLog("linkTgAccount DB error (select): " . $e->getMessage());
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chat_id,
            'text'    => "⚠️ Ошибка базы данных. Попробуй позже.",
        ]);
        return;
    }

    if (!$row) {
        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $chat_id,
            'text'       => "❌ *Код не найден.*\n\nПроверь, что ввёл код правильно, или обнови страницу сайта и попробуй снова.",
            'parse_mode' => 'Markdown',
        ]);
        return;
    }

    if ($row['linked'] === true || $row['linked'] === 't') {
        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $chat_id,
            'text'       => "✅ *Этот код уже был использован.*\n\nТвой Telegram уже привязан к сайту. Можешь вернуться и оформить заказ.",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(mainKeyboard(false), JSON_UNESCAPED_UNICODE),
        ]);
        return;
    }

    // Получаем данные пользователя из сообщения
    $user       = $message['from'] ?? [];
    $tg_id      = (string)($user['id'] ?? $chat_id);
    $username   = $user['username'] ?? '';
    $first_name = $user['first_name'] ?? '';

    // Получаем фото профиля через getProfilePhotos
    $photo_url = '';
    try {
        $photosResp = sendTelegram($token, 'getUserProfilePhotos', [
            'user_id' => $chat_id,
            'limit'   => 1,
        ]);
        $photosData = json_decode($photosResp, true);
        if (!empty($photosData['result']['photos'][0])) {
            $fileId = $photosData['result']['photos'][0][0]['file_id'] ?? '';
            if ($fileId) {
                $fileResp = sendTelegram($token, 'getFile', ['file_id' => $fileId]);
                $fileData = json_decode($fileResp, true);
                $filePath = $fileData['result']['file_path'] ?? '';
                if ($filePath) {
                    $photo_url = "https://api.telegram.org/file/bot{$token}/{$filePath}";
                }
            }
        }
    } catch (Throwable $e) {
        botLog("photo fetch error: " . $e->getMessage());
    }

    // ШАГ 1 — базовый UPDATE (linked=1), работает всегда
    try {
        $pdo->prepare("UPDATE tg_links SET linked = TRUE WHERE site_code = ?")
            ->execute([$site_code]);
        botLog("linkTgAccount: linked=1 set for code={$site_code}");
    } catch (Throwable $e) {
        botLog("linkTgAccount DB error (update linked): " . $e->getMessage());
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chat_id,
            'text'    => "⚠️ Не удалось привязать аккаунт. Попробуй позже.",
        ]);
        return;
    }

    // ШАГ 2 — дополнительные поля профиля (если колонки уже добавлены миграцией)
    try {
        $pdo->prepare("
            UPDATE tg_links
            SET tg_id = ?, tg_username = ?, tg_first_name = ?, tg_photo_url = ?
            WHERE site_code = ?
        ")->execute([$tg_id, $username, $first_name, $photo_url, $site_code]);
        botLog("linkTgAccount: profile saved tg_id={$tg_id} username={$username}");
    } catch (Throwable $e) {
        // Колонки ещё не добавлены — не критично, linked=1 уже стоит
        botLog("linkTgAccount: profile columns missing (run migration!) " . $e->getMessage());
    }

    // Привязываем заказы по username и session_id — все форматы
    try {
        // Ищем session_id из tg_links для этого site_code
        $sess_stmt = $pdo->prepare("SELECT session_id FROM tg_links WHERE site_code = ? LIMIT 1");
        $sess_stmt->execute([$site_code]);
        $sess_row = $sess_stmt->fetch(PDO::FETCH_ASSOC);
        $link_session = $sess_row['session_id'] ?? '';

        $conditions = ["(client_chat_id IS NULL OR client_chat_id = '')"];
        $params_upd  = [$tg_id];
        $where_parts = [];

        // По session_id
        if ($link_session !== '') {
            $where_parts[] = 'session_id = ?';
            $params_upd[]  = $link_session;
        }
        // По telegram полю — все варианты написания
        if ($username !== '') {
            $where_parts[] = 'telegram = ?';
            $params_upd[]  = '@' . $username;
            $where_parts[] = 'telegram = ?';
            $params_upd[]  = $username;
            $where_parts[] = 'telegram = ?';
            $params_upd[]  = 'https://t.me/' . $username;
            $where_parts[] = 'telegram = ?';
            $params_upd[]  = 't.me/' . $username;
        }

        if (!empty($where_parts)) {
            $sql_upd = "UPDATE orders SET client_chat_id = ? WHERE (client_chat_id IS NULL OR client_chat_id = '') AND (" . implode(' OR ', $where_parts) . ")";
            $rows_updated = $pdo->prepare($sql_upd)->execute($params_upd);
            botLog("linkTgAccount: updated orders client_chat_id={$tg_id} by username/session");
        }
    } catch (Throwable $e) {
        botLog("linkTgAccount: orders update error: " . $e->getMessage());
    }

    $name_display = $first_name ?: ($username ? '@' . $username : 'пользователь');

    // Проверяем есть ли активные заказы — уведомляем о них
    try {
        $active_stmt = $pdo->prepare("SELECT id, status FROM orders WHERE client_chat_id = ? AND status IN ('pending','in_progress','urgent') ORDER BY id DESC LIMIT 5");
        $active_stmt->execute([$tg_id]);
        $active_orders = $active_stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($active_orders)) {
            $statusLabel = ['pending' => '⏳ Ожидает', 'in_progress' => '🎨 В работе', 'urgent' => '⚡ Срочный'];
            $msg = "📦 *Твои активные заказы:*\n\n";
            foreach ($active_orders as $ao) {
                $msg .= ($statusLabel[$ao['status']] ?? '📦') . " Заказ *#{$ao['id']}*\n";
            }
            $msg .= "\nТеперь ты будешь получать уведомления об их изменении автоматически.";
            sendTelegram($token, 'sendMessage', [
                'chat_id'    => $chat_id,
                'text'       => $msg,
                'parse_mode' => 'Markdown',
            ]);
        }
    } catch (Throwable $e) {}

    sendTelegram($token, 'sendMessage', [
        'chat_id'    => $chat_id,
        'text'       => "🎉 *{$name_display}, Telegram успешно привязан к сайту!*\n\nТеперь ты можешь оформлять заказы и получать уведомления прямо в этот бот.\n\nВернись на сайт — страница обновится автоматически.",
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode(mainKeyboard(false), JSON_UNESCAPED_UNICODE),
    ]);
}

/**
 * Показывает личный кабинет клиента — список его заказов с кнопками.
 */
function showCabinet($pdo, $token, $chat_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, service_key, status, created_at
            FROM orders
            WHERE client_chat_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$chat_id]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($orders)) {
            sendTelegram($token, 'sendMessage', [
                'chat_id'    => $chat_id,
                'text'       => "📂 *Личный кабинет*\n\nУ вас пока нет привязанных заказов.\n\nЧтобы привязать заказ, отправьте команду:\n`/status_НОМЕР_ЗАКАЗА`\n\nНомер заказа вы получили на сайте при оформлении.",
                'parse_mode' => 'Markdown',
            ]);
            return;
        }

        $text     = "📂 *Ваши заказы:*\n\n";
        $keyboard = ['inline_keyboard' => []];

        foreach ($orders as $o) {
            $emoji  = statusEmoji($o['status']);
            $label  = statusLabel($o['status']);
            $date   = date('d.m.Y', strtotime($o['created_at']));
            $svc    = $o['service_key'] ?? '?';
            $text  .= "{$emoji} *Заказ #{$o['id']}* — {$label}\n";
            $keyboard['inline_keyboard'][] = [[
                'text'          => "{$emoji} Заказ #{$o['id']} • {$svc} • {$date}",
                'callback_data' => "cli_view_{$o['id']}",
            ]];
        }

        $text .= "\nНажмите на заказ, чтобы увидеть подробности.";

        sendTelegram($token, 'sendMessage', [
            'chat_id'      => $chat_id,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
        ]);

    } catch (Exception $e) {
        botLog("showCabinet error: " . $e->getMessage());
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chat_id,
            'text'    => "⚠️ Не удалось загрузить кабинет. Попробуйте позже.",
        ]);
    }
}

/**
 * Показывает клиенту детали конкретного заказа.
 */
function showClientOrderDetails($pdo, $token, $chat_id, $order_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, service_key, status, details, created_at, screenshot, example_photo
            FROM orders
            WHERE id = ? AND client_chat_id = ?
            LIMIT 1
        ");
        $stmt->execute([$order_id, $chat_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            sendTelegram($token, 'sendMessage', [
                'chat_id' => $chat_id,
                'text'    => "❌ Заказ #{$order_id} не найден или не принадлежит вам.",
            ]);
            return;
        }

        // Подтягиваем название услуги
        $p_stmt = $pdo->prepare("SELECT title, price_rub, price_uan FROM prices WHERE category_key = ? LIMIT 1");
        $p_stmt->execute([$order['service_key']]);
        $price = $p_stmt->fetch(PDO::FETCH_ASSOC);

        $emoji       = statusEmoji($order['status']);
        $status_text = statusLabel($order['status']);
        $svc_title   = $price['title'] ?? $order['service_key'];
        $p_rub       = $price['price_rub'] ?? 0;
        $p_uan       = $price['price_uan'] ?? 0;
        $is_coop     = !empty($order['cooperation']);
        if ($is_coop && in_array($order['status'], ['in_progress','urgent','ready'], true)) {
            $p_rub = 0;
            $p_uan = 0;
        }
        $date        = date('d.m.Y H:i', strtotime($order['created_at']));

        // Дедлайн
        $created   = new DateTime($order['created_at']);
        $now       = new DateTime();
        $days_left = 5 - $created->diff($now)->days;
        $deadline  = ($days_left < 0) ? '🚨 Срок истёк' : "⏱ Осталось {$days_left} дн.";

        $text  = "📦 *Заказ #{$order['id']}*\n";
        $text .= "━━━━━━━━━━━━━━━━━━\n";
        $text .= "🎨 *Услуга:* " . mdEscape($svc_title) . "\n";
        if ($is_coop && in_array($order['status'], ['in_progress','urgent','ready'], true)) {
            $text .= "💼 *Сотрудничество:* да\n";
        }
        $text .= "💰 *Стоимость:* {$p_rub} ₽ / {$p_uan} ₴\n";
        $text .= "📝 *ТЗ:* " . mdEscape($order['details'] ?? '—') . "\n";
        $text .= "━━━━━━━━━━━━━━━━━━\n";
        $text .= "{$emoji} *Статус:* {$status_text}\n";
        $text .= "📅 *Создан:* {$date}\n";
        $text .= "{$deadline}\n";

        $keyboard = ['inline_keyboard' => [[
            ['text' => '◀️ Назад к списку', 'callback_data' => 'cli_cabinet'],
        ]]];

        sendTelegram($token, 'sendMessage', [
            'chat_id'      => $chat_id,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
        ]);

    } catch (Exception $e) {
        botLog("showClientOrderDetails error: " . $e->getMessage());
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chat_id,
            'text'    => "⚠️ Ошибка при загрузке заказа. Попробуйте позже.",
        ]);
    }
}

/**
 * Уведомляет клиента БЕЗОПАСНО — без выброса исключения если chat_id нет.
 */
function safeNotifyClient($pdo, $token, $order_id, $text) {
    try {
        $stmt = $pdo->prepare("SELECT client_chat_id, telegram, session_id FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([$order_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        $chat_id = trim((string)($row['client_chat_id'] ?? ''));

        // Метод 2: по session_id через tg_links
        if (($chat_id === '' || !is_numeric($chat_id)) && !empty($row['session_id'])) {
            try {
                $lnk = $pdo->prepare("
                    SELECT COALESCE(NULLIF(tg_chat_id,''), NULLIF(CAST(tg_id AS VARCHAR),'')) AS chat_id
                    FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1
                ");
                $lnk->execute([$row['session_id']]);
                $r = $lnk->fetch(PDO::FETCH_ASSOC);
                if (!empty($r['chat_id']) && is_numeric($r['chat_id'])) {
                    $chat_id = $r['chat_id'];
                }
            } catch (Throwable $e) {}
        }

        // Метод 3: по tg_username из поля telegram заказа
        if (($chat_id === '' || !is_numeric($chat_id)) && !empty($row['telegram'])) {
            $tg_clean = ltrim(trim(str_replace(['https://t.me/', 'http://t.me/', 't.me/'], '', $row['telegram'])), '@');
            if ($tg_clean !== '') {
                try {
                    $lnk2 = $pdo->prepare("
                        SELECT COALESCE(NULLIF(tg_chat_id,''), NULLIF(CAST(tg_id AS VARCHAR),'')) AS chat_id
                        FROM tg_links WHERE (tg_username = ? OR tg_username = ?) AND linked = TRUE
                        ORDER BY id DESC LIMIT 1
                    ");
                    $lnk2->execute([$tg_clean, '@' . $tg_clean]);
                    $r2 = $lnk2->fetch(PDO::FETCH_ASSOC);
                    if (!empty($r2['chat_id']) && is_numeric($r2['chat_id'])) {
                        $chat_id = $r2['chat_id'];
                    }
                } catch (Throwable $e) {}
            }
        }

        // Метод 4: getChat через Telegram API
        if (($chat_id === '' || !is_numeric($chat_id)) && !empty($row['telegram'])) {
            $tg_clean = ltrim(trim(str_replace(['https://t.me/', 'http://t.me/', 't.me/'], '', $row['telegram'])), '@');
            if ($tg_clean !== '') {
                $ch = curl_init("https://api.telegram.org/bot{$token}/getChat");
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                    CURLOPT_POSTFIELDS => ['chat_id' => '@' . $tg_clean]]);
                $resp = curl_exec($ch); curl_close($ch);
                $data = json_decode((string)$resp, true);
                if (!empty($data['ok']) && !empty($data['result']['id'])) {
                    $chat_id = (string)$data['result']['id'];
                    botLog("safeNotifyClient order={$order_id} found via getChat: {$chat_id}");
                }
            }
        }

        if ($chat_id !== '' && is_numeric($chat_id)) {
            if (empty($row['client_chat_id'])) {
                $pdo->prepare("UPDATE orders SET client_chat_id = ? WHERE id = ?")->execute([$chat_id, $order_id]);
            }
            $res = sendTelegram($token, 'sendMessage', [
                'chat_id'    => $chat_id,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]);
            $decoded = json_decode((string)$res, true);
            if (!empty($decoded['ok'])) {
                botLog("safeNotifyClient order={$order_id} chat={$chat_id} OK");
            } else {
                botLog("safeNotifyClient order={$order_id} chat={$chat_id} FAILED: " . substr((string)$res, 0, 200));
            }
        } else {
            botLog("safeNotifyClient order={$order_id} no chat_id. telegram=" . ($row['telegram'] ?? ''));
        }
    } catch (Exception $e) {
        botLog("safeNotifyClient error order={$order_id}: " . $e->getMessage());
    }
}

// ── Keyboards ──────────────────────────────────────────────────

function mainKeyboard($isAdmin) {
    $buttons = [
        [['text' => '🎨 Смотреть portfolio'], ['text' => '📋 Прайс-лист']],
        [['text' => '🤖 Сделать заказ'],      ['text' => '📂 Личный кабинет']],
    ];
    if ($isAdmin) {
        $buttons[] = [['text' => '⚙️ Админ-панель']];
    }
    return ['keyboard' => $buttons, 'resize_keyboard' => true];
}

// Постоянное Reply-меню для админа
function adminReplyKeyboard() {
    return [
        'keyboard' => [
            [['text' => '🗂 Очередь заказов'],  ['text' => '📊 Статистика']],
            [['text' => '💾 Бэкап БД'],         ['text' => '🔗 Привязки TG']],
            [['text' => '🐛 Диагностика БД'],    ['text' => '🔧 Починить БД']],
            [['text' => '🗑 Очистить все заказы']],
            [['text' => '◀️ Главное меню']],
        ],
        'resize_keyboard'   => true,
        'one_time_keyboard' => false,
        'input_field_placeholder' => 'Выбери действие…',
    ];
}

function adminKeyboard() {
    return [
        'inline_keyboard' => [
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
            ['text' => '⚡️ Срочный',        'callback_data' => "adm_urgent_{$order_id}"],
        ];
        $keyboard['inline_keyboard'][] = [
            ['text' => '❌ Отклонить', 'callback_data' => "adm_dec_{$order_id}"],
        ];
    }

    if ($status === 'in_progress') {
        $keyboard['inline_keyboard'][] = [
            ['text' => '⚡️ Сделать срочным', 'callback_data' => "adm_urgent_{$order_id}"],
            ['text' => '✅ Готов',            'callback_data' => "adm_ready_{$order_id}"],
        ];
    }

    if ($status === 'urgent') {
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

// ── Admin helpers ──────────────────────────────────────────────

function adminSendDbBackup($pdo, $token, $admin_id) {
    try {
        $tables = $pdo->query("
            SELECT table_name FROM information_schema.tables
            WHERE table_schema = current_schema() ORDER BY table_name
        ")->fetchAll(PDO::FETCH_COLUMN);

        $date = date('Y-m-d_H-i');
        $sql  = "-- Kostlim Design DB Backup | " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Tables: " . implode(', ', $tables) . "\n\n";

        foreach ($tables as $table) {
            $sql .= "\n-- TABLE: {$table}\n";
            $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='{$table}' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
            $sql .= "TRUNCATE TABLE \"{$table}\" RESTART IDENTITY CASCADE;\n";

            $rows = $pdo->query("SELECT * FROM \"{$table}\"")->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) { $sql .= "-- (no rows)\n"; continue; }

            $colList = implode(', ', array_map(fn($c) => "\"{$c}\"", $cols));
            foreach ($rows as $row) {
                $vals = array_map(function($v) {
                    if ($v === null)  return 'NULL';
                    if ($v === true  || $v === 't') return 'TRUE';
                    if ($v === false || $v === 'f') return 'FALSE';
                    return "'" . str_replace("'", "''", (string)$v) . "'";
                }, array_values($row));
                $sql .= "INSERT INTO \"{$table}\" ({$colList}) VALUES (" . implode(', ', $vals) . ");\n";
            }
        }

        $filename = "db_backup_{$date}.sql";
        $filepath = sys_get_temp_dir() . '/' . $filename;
        file_put_contents($filepath, $sql);

        $ch = curl_init("https://api.telegram.org/bot{$token}/sendDocument");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POSTFIELDS     => [
                'chat_id'    => $admin_id,
                'document'   => new CURLFile($filepath, 'application/sql', $filename),
                'caption'    => "💾 *Бэкап БД*\n📅 " . date('d.m.Y H:i') . "\n📊 Таблиц: " . count($tables) . "\n📝 Размер: " . round(strlen($sql)/1024, 1) . " KB",
                'parse_mode' => 'Markdown',
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
        @unlink($filepath);

    } catch (Throwable $e) {
        botLog("adminSendDbBackup error: " . $e->getMessage());
        sendTelegram($token, 'sendMessage', ['chat_id' => $admin_id, 'text' => "❌ Ошибка бэкапа:\n" . $e->getMessage()]);
    }
}

function showAdminQueue($pdo, $token, $admin_id, $site_url) {
    $q_stmt = $pdo->query("
        SELECT id, username, telegram, service_key, status, created_at
        FROM orders
        WHERE status IN ('pending','in_progress','urgent')
        ORDER BY
            CASE status WHEN 'urgent' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END ASC,
            created_at ASC
    ");
    $queue = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($queue)) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $admin_id, 'text' => '🎉 Очередь пустая. Активных заказов нет.']);
        return;
    }

    $message  = "📁 *Активная очередь заказов:*\n\n";
    $keyboard = ['inline_keyboard' => []];

    foreach ($queue as $item) {
        $deadline = getDeadlineInfo($item['created_at']);
        $emoji    = statusEmoji($item['status']);
        $label    = [
            'pending'     => 'Новый',
            'in_progress' => 'В работе',
            'urgent'      => '⚡ СРОЧНЫЙ',
        ][$item['status']] ?? $item['status'];
        $message .= "{$emoji} *Заказ #{$item['id']}* — {$label} — {$deadline['text']}\n";
        $keyboard['inline_keyboard'][] = [[
            'text'          => "{$emoji} #{$item['id']} {$label} • {$deadline['button']}",
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
        SELECT o.id, o.username, o.telegram, o.service_key, o.details, o.screenshot,
               o.example_photo, o.status, o.created_at, o.deadline, o.client_chat_id,
               tl.tg_username
        FROM orders o
        LEFT JOIN tg_links tl ON tl.session_id = o.session_id AND tl.linked = TRUE
        WHERE o.id = ? LIMIT 1
    ");
    $o_stmt->execute([$order_id]);
    $item = $o_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $admin_id, 'text' => "Заказ #{$order_id} не найден."]);
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
    $created  = new DateTime($item['created_at']);
    $now      = new DateTime();
    $days_left = 5 - $created->diff($now)->days;
    $days_str  = ($days_left < 0) ? '🚨 ДЕДЛАЙН ПРОСРОЧЕН' : "{$days_left} дн.";

    $status_text  = statusLabel($item['status']);
    $service_title = $price_info['title'] ?? $item['service_key'];
    $p_rub         = $price_info['price_rub'] ?? 0;
    $p_uan         = $price_info['price_uan'] ?? 0;

    // Форматирование дедлайна
    $deadline_text = '';
    if (!empty($item['deadline'])) {
        $deadline_dt = new DateTime($item['deadline']);
        $diff = $deadline_dt->getTimestamp() - time();
        
        if ($diff < 0) {
            $deadline_text = "🔴 *ПРОСРОЧЕНО!* На " . abs(ceil($diff / 3600)) . " ч.";
        } elseif ($diff < 24 * 3600) { // менее 24 часов
            $hours_left = ceil($diff / 3600);
            $deadline_text = "🟠 *СРОЧНО!* Осталось ~{$hours_left} ч. (" . $deadline_dt->format('d.m в H:i') . ")";
        } else {
            $days_deadline = ceil($diff / (24 * 3600));
            $deadline_text = "🟡 *Дедлайн:* " . $deadline_dt->format('d.m.Y в H:i') . " ({$days_deadline} дн.)";
        }
    }

    $msg  = "📦 *ЗАКАЗ #{$item['id']}*\n";
    $msg .= "━━━━━━━━━━━━━━━━━━\n";
    $msg .= "👤 *Имя:* "    . mdEscape($item['username'] ?? '') . "\n";
    $msg .= "📞 *Связь:* "  . mdEscape($item['telegram']  ?? '') . "\n";
    // TG username из привязки (если отличается от telegram поля)
    $tgUser = trim((string)($item['tg_username'] ?? ''));
    if ($tgUser !== '' && '@' . $tgUser !== $item['telegram'] && $tgUser !== ltrim((string)$item['telegram'], '@')) {
        $msg .= "🔗 *TG аккаунт:* @" . mdEscape($tgUser) . "\n";
    } elseif ($tgUser !== '') {
        $msg .= "🔗 *TG:* @" . mdEscape($tgUser) . "\n";
    }
    $chatId = trim((string)($item['client_chat_id'] ?? ''));
    if ($chatId !== '') {
        $msg .= "🆔 *Chat ID:* `" . mdEscape($chatId) . "`\n";
    }
    $msg .= "🎨 *Услуга:* " . mdEscape($service_title) . "\n";
    $msg .= "💰 *Цена:* "   . mdEscape((string)$p_rub) . " ₽ / " . mdEscape((string)$p_uan) . " ₴\n";
    $msg .= "📝 *ТЗ:* "     . mdEscape($item['details'] ?? '') . "\n";
    $msg .= "━━━━━━━━━━━━━━━━━━\n";
    $msg .= "🔹 *Статус:* {$status_text}\n";
    if ($deadline_text) {
        $msg .= "{$deadline_text}\n";
    }

    // Photos (screenshot / example) are sent as media album separately
    if (!empty($item['screenshot']) || !empty($item['example_photo'])) {
        $msg .= "📸 *Файлы:* отправлены как альбом (фото ниже)\n";
    } else {
        $msg .= "📸 *Файлы:* _не прикреплены_\n";
    }

    return $msg;
}

// ── Helpers ────────────────────────────────────────────────────

/**
 * Смайл по статусу заказа.
 */
function statusEmoji($status) {
    return [
        'pending'     => '⏳',
        'in_progress' => '🎨',
        'urgent'      => '⚡️',
        'ready'       => '✅',
        'declined'    => '❌',
    ][$status] ?? '📦';
}

/**
 * Читаемый статус на русском.
 */
function statusLabel($status) {
    return [
        'pending'     => '⏳ Ожидает подтверждения',
        'in_progress' => '🎨 В работе',
        'urgent'      => '⚡️ Срочный (в приоритете)',
        'ready'       => '✅ Готов',
        'declined'    => '❌ Отклонён',
    ][$status] ?? $status;
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

function sendOrderPhotos($token, $chat_id, $item) {
    botLog("sendOrderPhotos: order={$item['id']} start");
    // collect available photos
    $media = [];
    foreach (['screenshot' => 'Чек оплаты', 'example_photo' => 'Референс'] as $field => $label) {
        if (empty($item[$field])) continue;
        $path = __DIR__ . '/uploads/orders/' . basename($item[$field]);
        if (is_file($path)) {
            botLog("sendOrderPhotos: found local file {$path}");
            $media[] = [
                'type' => 'photo',
                'media' => curl_file_create($path),
                'caption' => "{$label} к заказу #{$item['id']}",
            ];
        } elseif (str_starts_with($item[$field], 'http://') || str_starts_with($item[$field], 'https://')) {
            botLog("sendOrderPhotos: found remote URL {$item[$field]}");
            $media[] = [
                'type' => 'photo',
                'media' => $item[$field],
                'caption' => "{$label} к заказу #{$item['id']}",
            ];
        }
    }

    if (empty($media)) return;

    $count = count($media);
    // If only one media, send as single photo
    if ($count === 1) {
        $m = $media[0];
        botLog("sendOrderPhotos: sending single photo, caption={$m['caption']}");
        if ($m['media'] instanceof CURLFile) {
            $res = sendTelegramFile($token, 'sendPhoto', ['chat_id' => $chat_id, 'photo' => $m['media'], 'caption' => $m['caption']]);
        } else {
            $res = sendTelegram($token, 'sendPhoto', ['chat_id' => $chat_id, 'photo' => $m['media'], 'caption' => $m['caption']]);
        }
        botLog("sendOrderPhotos: single send result=" . substr((string)$res, 0, 200));
        return;
    }

    // multiple media: media group (2..10)
    // detect if any are local files
    $useFiles = false;
    foreach ($media as $m) {
        if ($m['media'] instanceof CURLFile) { $useFiles = true; break; }
    }

    if ($useFiles) {
        // When uploading local files, attach each as "photoN" and reference via "attach://photoN" in media array
        $post = ['chat_id' => $chat_id];
        $mediaPayload = [];
        $i = 0;
        foreach ($media as $m) {
            $i++;
            $attachKey = "photo{$i}";
            $post[$attachKey] = $m['media'];
            $mediaPayload[] = ['type' => 'photo', 'media' => "attach://{$attachKey}", 'caption' => $m['caption']];
        }
        $post['media'] = json_encode($mediaPayload, JSON_UNESCAPED_UNICODE);
        $res = sendTelegramFile($token, 'sendMediaGroup', $post);
        botLog("sendOrderPhotos: sendMediaGroup result=" . substr((string)$res, 0, 200));
    } else {
        // All media are URLs — send via sendMediaGroup with JSON payload
        $mediaPayload = array_map(fn($m) => ['type' => 'photo', 'media' => $m['media'], 'caption' => $m['caption']], $media);
        $res = sendTelegram($token, 'sendMediaGroup', ['chat_id' => $chat_id, 'media' => json_encode($mediaPayload, JSON_UNESCAPED_UNICODE)]);
        botLog("sendOrderPhotos: sendMediaGroup (urls) result=" . substr((string)$res, 0, 200));
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
        ['_',  '*',  '[',  ']',  '(',  ')',  '~',  '`',  '>',  '#',  '+',  '-',  '=',  '|',  '{',  '}',  '.',  '!'],
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
    curl_setopt($ch, CURLOPT_POST,          true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,    http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        10);
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
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        15);
    $res   = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    $data = json_decode((string)$res, true);
    if ($error !== '' || !($data['ok'] ?? false)) {
        botLog("telegram file error method={$method} error={$error} response={$res}");
    }
    return $res;
}