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
        $pdo->prepare("UPDATE orders SET status = 'in_progress' WHERE id = ?")->execute([$order_id]);
        sendTelegram($token, 'editMessageReplyMarkup', [
            'chat_id'      => $cal_chat_id,
            'message_id'   => $msg_id,
            'reply_markup' => json_encode(orderKeyboard($order_id, 'in_progress', getOrderTelegram($pdo, $order_id)), JSON_UNESCAPED_UNICODE),
        ]);
        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $admin_id,
            'text'       => "🚀 *Заказ #{$order_id} взят в работу.*",
            'parse_mode' => 'Markdown',
        ]);
        // Уведомляем клиента — безопасно, без краша
        safeNotifyClient($pdo, $token, $order_id,
            "🎨 *Ваш заказ #{$order_id} принят в работу!*\n\nДизайнер уже начал выполнение. Мы сообщим вам, когда заказ будет готов."
        );
        sendTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Заказ взят в работу']);
        exit;
    }

    // Срочный
    if (strpos($callback_data, 'adm_urgent_') === 0) {
        $order_id = (int)str_replace('adm_urgent_', '', $callback_data);
        $pdo->prepare("UPDATE orders SET status = 'urgent' WHERE id = ?")->execute([$order_id]);
        sendTelegram($token, 'editMessageReplyMarkup', [
            'chat_id'      => $cal_chat_id,
            'message_id'   => $msg_id,
            'reply_markup' => json_encode(orderKeyboard($order_id, 'urgent', getOrderTelegram($pdo, $order_id)), JSON_UNESCAPED_UNICODE),
        ]);
        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $admin_id,
            'text'       => "⚡️ *Заказ #{$order_id} переведён в СРОЧНЫЙ режим.*",
            'parse_mode' => 'Markdown',
        ]);
        // Уведомляем клиента
        safeNotifyClient($pdo, $token, $order_id,
            "⚡️ *Ваш заказ #{$order_id} переведён в СРОЧНЫЙ режим!*\n\nДизайнер выполнит его в приоритетном порядке — сегодня или завтра."
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

        // Обычный /start
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

    // Админ-панель
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

    if ($row['linked']) {
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
        $pdo->prepare("UPDATE tg_links SET linked = 1 WHERE site_code = ?")
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

    // Привязываем заказы по username
    if ($username !== '') {
        try {
            $pdo->prepare("
                UPDATE orders SET client_chat_id = ?
                WHERE (telegram = ? OR telegram = ?)
                  AND (client_chat_id IS NULL OR client_chat_id = '')
            ")->execute([$tg_id, '@' . $username, $username]);
        } catch (Throwable $e) {
            botLog("linkTgAccount: orders update error: " . $e->getMessage());
        }
    }

    $name_display = $first_name ?: ($username ? '@' . $username : 'пользователь');

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
        $date        = date('d.m.Y H:i', strtotime($order['created_at']));

        // Дедлайн
        $created   = new DateTime($order['created_at']);
        $now       = new DateTime();
        $days_left = 5 - $created->diff($now)->days;
        $deadline  = ($days_left < 0) ? '🚨 Срок истёк' : "⏱ Осталось {$days_left} дн.";

        $text  = "📦 *Заказ #{$order['id']}*\n";
        $text .= "━━━━━━━━━━━━━━━━━━\n";
        $text .= "🎨 *Услуга:* " . mdEscape($svc_title) . "\n";
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
        $stmt = $pdo->prepare("SELECT client_chat_id FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([$order_id]);
        $chat_id = $stmt->fetchColumn();

        if (!empty($chat_id)) {
            sendTelegram($token, 'sendMessage', [
                'chat_id'    => $chat_id,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]);
            botLog("notifyClient order={$order_id} chat={$chat_id} ok");
        } else {
            botLog("notifyClient order={$order_id} — client_chat_id пустой, уведомление не отправлено");
        }
    } catch (Exception $e) {
        botLog("notifyClient error order={$order_id}: " . $e->getMessage());
        // Не бросаем исключение дальше — не роняем весь скрипт
    }
}

// ── Keyboards ──────────────────────────────────────────────────

function mainKeyboard($isAdmin) {
    $buttons = [
        [['text' => '🎨 Смотреть portfolio'], ['text' => '📋 Прайс-лист']],
        [['text' => '🤖 Сделать заказ'],      ['text' => '📂 Личный кабинет']],
    ];
    if ($isAdmin) {
        $buttons[] = [['text' => '💻 Admin Panel']];
    }
    return ['keyboard' => $buttons, 'resize_keyboard' => true];
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

function showAdminQueue($pdo, $token, $admin_id, $site_url) {
    $q_stmt = $pdo->query("
        SELECT id, username, telegram, service_key, status, created_at
        FROM orders
        WHERE status IN ('pending','in_progress','urgent')
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
        $emoji        = statusEmoji($item['status']);
        $message     .= "{$emoji} *Заказ #{$item['id']}* — {$deadline['text']}\n";
        $keyboard['inline_keyboard'][] = [[
            'text'          => "{$emoji} Заказ #{$item['id']} • {$deadline['button']}",
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
        SELECT id, username, telegram, service_key, details, screenshot, example_photo, status, created_at
        FROM orders WHERE id = ? AND status IN ('pending','in_progress','urgent') LIMIT 1
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
    $created  = new DateTime($item['created_at']);
    $now      = new DateTime();
    $days_left = 5 - $created->diff($now)->days;
    $days_str  = ($days_left < 0) ? '🚨 ДЕДЛАЙН ПРОСРОЧЕН' : "{$days_left} дн.";

    $status_text  = statusLabel($item['status']);
    $service_title = $price_info['title'] ?? $item['service_key'];
    $p_rub         = $price_info['price_rub'] ?? 0;
    $p_uan         = $price_info['price_uan'] ?? 0;

    $msg  = "📦 *ЗАКАЗ #{$item['id']}*\n";
    $msg .= "━━━━━━━━━━━━━━━━━━\n";
    $msg .= "👤 *Имя:* "    . mdEscape($item['username'] ?? '') . "\n";
    $msg .= "📞 *Связь:* "  . mdEscape($item['telegram']  ?? '') . "\n";
    $msg .= "🎨 *Услуга:* " . mdEscape($service_title) . "\n";
    $msg .= "💰 *Цена:* "   . mdEscape((string)$p_rub) . " ₽ / " . mdEscape((string)$p_uan) . " ₴\n";
    $msg .= "📝 *ТЗ:* "     . mdEscape($item['details'] ?? '') . "\n";
    $msg .= "━━━━━━━━━━━━━━━━━━\n";
    $msg .= "🔹 *Статус:* {$status_text}\n";
    $msg .= "⏱ *Осталось:* {$days_str}\n";

    if (!empty($item['screenshot'])) {
        $msg .= "📸 *Чек:* [Открыть файл]({$site_url}uploads/orders/" . rawurlencode($item['screenshot']) . ")\n";
    } else {
        $msg .= "📸 *Чек:* _не прикреплён_\n";
    }

    if (!empty($item['example_photo'])) {
        $msg .= "🖼 *Референс:* [Открыть файл]({$site_url}uploads/orders/" . rawurlencode($item['example_photo']) . ")\n";
    } else {
        $msg .= "🖼 *Референс:* _не прикреплён_\n";
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