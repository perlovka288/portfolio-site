<?php
require_once 'includes/session.php';
require_once 'config/db.php';
require_once 'includes/order_flow.php';

// AUTO-LINK: Если клиент перешёл с TG по нашей ссылке — привязываем его TG автоматически
processTgAutoLink($pdo);
ensureOrderFlowSchema($pdo);

$adminQuery = $pdo->query("SELECT avatar FROM users LIMIT 1")->fetch();
$siteAvatar = (!empty($adminQuery['avatar'])) ? $adminQuery['avatar'] : '';

if (!function_exists('imgSrc')) {
    function imgSrc(string $val, string $base = 'uploads/'): string {
        if ($val === '') return '';
        if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) return $val;
        return '/' . ltrim($base . $val, '/');
    }
}

$adminTgId = getenv('ADMIN_ID') ?: '1710365896';
$botToken  = getenv('TELEGRAM_BOT_TOKEN') ?: getenv('BOT_TOKEN') ?: '8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg';
$siteUrl   = rtrim(getenv('SITE_URL') ?: 'https://portfolio-site-boo5.onrender.com/', '/') . '/';

$isAdmin   = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;

$sid     = session_id();
$profile = null;
$orders  = [];

function profileSendTelegram(string $token, string $method, array $fields): void
{
    if ($token === '') return;
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_POSTFIELDS => $fields,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function profileOwnsOrder(PDO $pdo, int $orderId, string $sid): ?array
{
    $stmt = $pdo->prepare("SELECT id, client_chat_id, telegram, status, session_id, is_urgent FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $linkStmt = $pdo->prepare("SELECT tg_id, tg_username FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1");
    $linkStmt->execute([$sid]);
    $linkRow = $linkStmt->fetch(PDO::FETCH_ASSOC);

    $ok = !empty($row['session_id']) && $row['session_id'] === $sid;
    if ($linkRow) {
        $tgId = (string)($linkRow['tg_id'] ?? '');
        $tgUser = ltrim((string)($linkRow['tg_username'] ?? ''), '@');
        $telegram = ltrim((string)($row['telegram'] ?? ''), '@');
        if ($tgId !== '' && (string)$row['client_chat_id'] === $tgId) $ok = true;
        if ($tgUser !== '' && in_array($telegram, [$tgUser, 'https://t.me/' . $tgUser, 't.me/' . $tgUser], true)) $ok = true;
    }
    return $ok ? $row : null;
}

// ── Обработка отмены заказа ──────────────────────────────────
$cancelMsg = '';
if (isset($_POST['cancel_order'])) {
    $cancelId = (int)($_POST['order_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT id, client_chat_id, telegram, status, session_id FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([$cancelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && in_array($row['status'], ['pending', 'in_progress', 'urgent'])) {
            $linkStmt = $pdo->prepare("SELECT tg_id, tg_username FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1");
            $linkStmt->execute([$sid]);
            $linkRow = $linkStmt->fetch(PDO::FETCH_ASSOC);
            $canCancel = false;
            if ($linkRow) {
                $tgId   = (string)($linkRow['tg_id'] ?? '');
                $tgUser = ltrim((string)($linkRow['tg_username'] ?? ''), '@');
                if ($tgId !== '' && (string)$row['client_chat_id'] === $tgId) {
                    $canCancel = true;
                } elseif (!empty($tgUser) && (
                    ltrim((string)$row['telegram'], '@') === $tgUser ||
                    '@' . $tgUser === (string)$row['telegram']
                )) {
                    $canCancel = true;
                } elseif (!empty($row['session_id']) && $row['session_id'] === $sid) {
                    $canCancel = true;
                }
            }
            if ($canCancel) {
                $pdo->prepare("UPDATE orders SET status = 'declined' WHERE id = ?")->execute([$cancelId]);
                $cancelMsg = 'cancel_ok';
            }
        }
    } catch (Throwable $e) {}
    header('Location: ' . $_SERVER['PHP_SELF'] . '?cancelled=' . $cancelId);
    exit;
}

// ── Клиент принимает сданную работу (п.1/3 ТЗ — кнопка доступна и на сайте) ──
if (isset($_POST['accept_work'])) {
    $acceptId = (int)($_POST['order_id'] ?? 0);
    $row = profileOwnsOrder($pdo, $acceptId, $sid);
    if ($row && ($row['status'] ?? '') === 'ready') {
        $pdo->prepare("UPDATE orders SET client_accepted_at = NOW() WHERE id = ?")->execute([$acceptId]);
        // Убираем кнопки под файлом в Telegram — так же, как при приёме через бота,
        // чтобы нельзя было случайно запросить правку после уже принятой работы.
        try {
            $msgIdStmt = $pdo->prepare("SELECT client_chat_id, work_message_id FROM orders WHERE id = ? LIMIT 1");
            $msgIdStmt->execute([$acceptId]);
            $wm = $msgIdStmt->fetch(PDO::FETCH_ASSOC);
            if ($wm && !empty($wm['work_message_id']) && !empty($wm['client_chat_id'])) {
                profileSendTelegram($botToken, 'editMessageReplyMarkup', [
                    'chat_id'      => $wm['client_chat_id'],
                    'message_id'   => $wm['work_message_id'],
                    'reply_markup' => json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE),
                ]);
            }
        } catch (Throwable $e) {}
        $adminTgIdLocal = getenv('ADMIN_ID') ?: $adminTgId;
        profileSendTelegram($botToken, 'sendMessage', ['chat_id' => $adminTgIdLocal, 'text' => "✅ Клиент принял работу по заказу #{$acceptId} (через сайт)."]);
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?order=' . $acceptId);
    exit;
}

// ── Клиент запрашивает правку прямо с сайта (п.2 ТЗ — "или на сайте") ──
if (isset($_POST['request_revision'])) {
    $revId   = (int)($_POST['order_id'] ?? 0);
    $revNote = trim((string)($_POST['revision_note'] ?? ''));
    $row = profileOwnsOrder($pdo, $revId, $sid);
    if ($row && ($row['status'] ?? '') === 'ready' && $revNote !== '') {
        requestOrderRevision($pdo, $botToken, $revId, $revNote, 'site');
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?order=' . $revId);
    exit;
}

// ── Редактирование ТЗ заказа клиентом (пока заказ не взят в работу) ──
$editMsg = '';
if (isset($_POST['edit_order_details'])) {
    $editId      = (int)($_POST['order_id'] ?? 0);
    $newDetails  = trim((string)($_POST['new_details'] ?? ''));
    try {
        $stmt = $pdo->prepare("SELECT id, client_chat_id, telegram, status, session_id FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([$editId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        // Редактировать можно, только пока заказ ещё не взят в работу —
        // после этого дизайнер уже мог начать по исходному ТЗ, менять его
        // задним числом небезопасно для обеих сторон.
        if ($row && in_array($row['status'], ['pending', 'awaiting_payment'], true) && $newDetails !== '' && mb_strlen($newDetails) <= 4000) {
            $linkStmt = $pdo->prepare("SELECT tg_id, tg_username FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1");
            $linkStmt->execute([$sid]);
            $linkRow = $linkStmt->fetch(PDO::FETCH_ASSOC);
            $canEdit = false;
            if ($linkRow) {
                $tgId   = (string)($linkRow['tg_id'] ?? '');
                $tgUser = ltrim((string)($linkRow['tg_username'] ?? ''), '@');
                if ($tgId !== '' && (string)$row['client_chat_id'] === $tgId) {
                    $canEdit = true;
                } elseif (!empty($tgUser) && (
                    ltrim((string)$row['telegram'], '@') === $tgUser ||
                    '@' . $tgUser === (string)$row['telegram']
                )) {
                    $canEdit = true;
                } elseif (!empty($row['session_id']) && $row['session_id'] === $sid) {
                    $canEdit = true;
                }
            }
            if ($canEdit) {
                $pdo->prepare("UPDATE orders SET details = ? WHERE id = ?")->execute([$newDetails, $editId]);
                addOrderMessage($pdo, $editId, 'client', 'Клиент отредактировал ТЗ заказа.');
                // Раньше админу уходил короткий текст-обрывок с новым ТЗ —
                // теперь целиком пересылается обновлённая карточка заказа,
                // в том же виде, что и при создании (услуга, цена, контакт,
                // полное ТЗ) — чтобы не листать сайт, чтобы увидеть контекст.
                try {
                    $tkn = getenv('BOT_TOKEN') ?: getenv('TELEGRAM_BOT_TOKEN') ?: '';
                    $adminIdEnv = getenv('ADMIN_TELEGRAM_ID') ?: getenv('ADMIN_ID') ?: '';
                    if ($tkn !== '' && $adminIdEnv !== '') {
                        $fullStmt = $pdo->prepare("
                            SELECT o.id, o.service_key, o.telegram, o.username, o.client_ip, o.cooperation,
                                   p.title AS price_title, p.price_rub, p.price_uan
                            FROM orders o LEFT JOIN prices p ON p.category_key = o.service_key
                            WHERE o.id = ? LIMIT 1
                        ");
                        $fullStmt->execute([$editId]);
                        $full = $fullStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                        $cardText = "✏️ <b>ЗАКАЗ #{$editId} ОТРЕДАКТИРОВАН КЛИЕНТОМ</b>\n\n";
                        $cardText .= "👤 Клиент: " . htmlspecialchars($full['username'] ?? 'Клиент') . "\n";
                        if (!empty($full['telegram'])) {
                            $cardText .= "📞 Контакт: " . htmlspecialchars($full['telegram']) . "\n";
                        }
                        $cardText .= "🎨 Услуга: " . htmlspecialchars($full['price_title'] ?? $full['service_key'] ?? '—') . "\n";
                        if (!empty($full['price_rub']) || !empty($full['price_uan'])) {
                            $cardText .= "💰 Стоимость: " . (int)($full['price_rub'] ?? 0) . "₽ / " . (int)($full['price_uan'] ?? 0) . "₴\n";
                        }
                        if (!empty($full['cooperation'])) {
                            $cardText .= "🤝 Отмечено как сотрудничество\n";
                        }
                        $cardText .= "\n📝 <b>НОВОЕ ТЕХНИЧЕСКОЕ ЗАДАНИЕ:</b>\n" . htmlspecialchars($newDetails) . "\n";
                        if (!empty($full['client_ip'])) {
                            $cardText .= "\n🌐 IP: " . htmlspecialchars($full['client_ip']);
                        }

                        $editKeyboard = ['inline_keyboard' => [
                            [
                                ['text' => '🟢 Принять заказ', 'callback_data' => "adm_menu_accept_{$editId}"],
                                ['text' => '🔴 Отклонить',      'callback_data' => "adm_menu_decline_{$editId}"],
                            ],
                        ]];
                        $cleanTgForBtn = ltrim((string)($full['telegram'] ?? ''), '@');
                        if ($cleanTgForBtn !== '') {
                            $editKeyboard['inline_keyboard'][] = [['text' => '💬 Написать клиенту', 'url' => "https://t.me/{$cleanTgForBtn}"]];
                        }

                        $ch = curl_init("https://api.telegram.org/bot{$tkn}/sendMessage");
                        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
                            CURLOPT_POSTFIELDS => [
                                'chat_id'      => $adminIdEnv,
                                'text'         => $cardText,
                                'parse_mode'   => 'HTML',
                                'reply_markup' => json_encode($editKeyboard, JSON_UNESCAPED_UNICODE),
                            ]]);
                        curl_exec($ch); curl_close($ch);
                    }
                } catch (Throwable $e) {}
                $editMsg = 'edit_ok';
            } else {
                $editMsg = 'edit_denied';
            }
        } else {
            $editMsg = 'edit_denied';
        }
    } catch (Throwable $e) {
        $editMsg = 'edit_error';
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?edited=' . $editId . '&edit_status=' . $editMsg);
    exit;
}

// ── Обработка отправки обращения ─────────────────────────────
$receiptStatus = $_GET['receipt'] ?? '';
if (isset($_POST['upload_payment_receipt'])) {
    $receiptOrderId = (int)($_POST['order_id'] ?? 0);
    $redirect = $_SERVER['PHP_SELF'] . '?order=' . $receiptOrderId;
    try {
        $orderRow = profileOwnsOrder($pdo, $receiptOrderId, $sid);
        if (!$orderRow || $orderRow['status'] !== 'awaiting_payment') {
            header('Location: ' . $redirect . '&receipt=bad_order');
            exit;
        }
        if (empty($_FILES['payment_receipt']['tmp_name']) || ($_FILES['payment_receipt']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            header('Location: ' . $redirect . '&receipt=bad_file');
            exit;
        }

        $tmp = $_FILES['payment_receipt']['tmp_name'];
        $ext = strtolower(pathinfo((string)($_FILES['payment_receipt']['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            $ext = 'jpg';
        }
        $dir = __DIR__ . '/uploads/orders/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $fileName = 'receipt_' . $receiptOrderId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = $dir . $fileName;
        if (!move_uploaded_file($tmp, $target)) {
            header('Location: ' . $redirect . '&receipt=bad_file');
            exit;
        }

        // Заливаем на ImgBB — постоянная ссылка, надёжно открывается Telegram
        // sendPhoto (локальный путь на диске мог пропасть при рестарте сервера).
        $receiptPath = ($ext !== 'pdf') ? uploadReceiptToImgBB($target, 'receipt_' . $receiptOrderId) : '';
        if ($receiptPath === '') {
            // Фолбэк — просто имя файла (БЕЗ префикса 'uploads/orders/' —
            // imgSrc() в админке сама достраивает этот префикс; путь с
            // префиксом давал битую двойную ссылку на картинку).
            $receiptPath = $fileName;
        }
        $isUrgent = !empty($orderRow['is_urgent']);
        $deadline = calculateOrderDeadline($isUrgent);
        $newStatus = $isUrgent ? 'urgent' : 'in_progress';
        $pdo->prepare("UPDATE orders SET status = ?, payment_status = 'receipt_received', payment_receipt = ?, payment_received_at = NOW(), started_at = NOW(), deadline = ? WHERE id = ?")
            ->execute([$newStatus, encodeReceiptList([$receiptPath]), $deadline, $receiptOrderId]);
        addOrderMessage($pdo, $receiptOrderId, 'client', 'Клиент отправил чек оплаты через сайт.', $receiptPath);

        $adminText = "💳 Чек оплаты по заказу #{$receiptOrderId}\nСтатус: заказ запущен в работу. Дедлайн: " . date('d.m.Y H:i', strtotime($deadline));
        $absoluteReceiptUrl = str_starts_with($receiptPath, 'http') ? $receiptPath : ($siteUrl . 'uploads/orders/' . ltrim($receiptPath, '/'));
        if ($ext === 'pdf') {
            profileSendTelegram($botToken, 'sendMessage', ['chat_id' => $adminTgId, 'text' => $adminText . "\n" . $absoluteReceiptUrl]);
        } else {
            profileSendTelegram($botToken, 'sendPhoto', ['chat_id' => $adminTgId, 'photo' => $absoluteReceiptUrl, 'caption' => $adminText]);
        }

        // Раньше при загрузке чека ЧЕРЕЗ САЙТ клиент не получал никакого
        // подтверждения в Telegram — это сообщение уходило только когда чек
        // присылали прямо в бота. Теперь текст одинаковый в обоих случаях.
        $clientChatId = trim((string)($orderRow['client_chat_id'] ?? ''));
        if ($clientChatId !== '' && is_numeric($clientChatId)) {
            $clientProfileUrl = rtrim($siteUrl, '/') . '/profile.php?order=' . $receiptOrderId;
            profileSendTelegram($botToken, 'sendMessage', [
                'chat_id'      => $clientChatId,
                'text'         => "Чек отправлен, ожидайте своего заказа. Дедлайн: " . date('d.m.Y H:i', strtotime($deadline)) . ". Отследить можно на сайте",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[['text' => '👤 Открыть профиль', 'url' => $clientProfileUrl]]],
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }

        header('Location: ' . $redirect . '&receipt=ok');
        exit;
    } catch (Throwable $e) {
        error_log('payment receipt upload error: ' . $e->getMessage());
        header('Location: ' . $redirect . '&receipt=err');
        exit;
    }
}

$appealMsg = '';
if (isset($_POST['send_appeal'])) {
    $appealOrderId  = (int)($_POST['appeal_order_id'] ?? 0);
    $appealSubject  = trim($_POST['appeal_subject'] ?? '');
    $appealText     = trim($_POST['appeal_message'] ?? '');
    $appealUsername = '';
    $appealTelegram = '';

    try {
        // Validate inputs
        if ($appealOrderId <= 0) {
            $appealMsg = 'err_order_id';
            throw new Exception("Invalid Order ID: " . $appealOrderId);
        }
        if (mb_strlen($appealSubject) < 3) { // Minimum length for subject
            $appealMsg = 'err_subject_short';
            throw new Exception("Subject too short");
        }
        if (mb_strlen($appealText) < 10) { // Minimum length for message
            $appealMsg = 'err_message_short';
            throw new Exception("Message too short");
        }

        $linkStmt = $pdo->prepare("SELECT tg_id, tg_username, tg_first_name FROM tg_links WHERE session_id = ? ORDER BY id DESC LIMIT 1");
        $linkStmt->execute([$sid]);
        $linkRow = $linkStmt->fetch(PDO::FETCH_ASSOC);
        if ($linkRow) {
            $appealTelegram = (string)($linkRow['tg_id'] ?? '');
            $tgFirstName    = trim((string)($linkRow['tg_first_name'] ?? ''));
            $tgUsername     = trim((string)($linkRow['tg_username'] ?? ''));
            if ($tgFirstName !== '' && $tgUsername !== '') {
                $appealUsername = $tgFirstName . ' (@' . ltrim($tgUsername, '@') . ')';
            } elseif ($tgFirstName !== '') {
                $appealUsername = $tgFirstName;
            } elseif ($tgUsername !== '') {
                $appealUsername = '@' . ltrim($tgUsername, '@');
            }
        }

        // Сохраняем обращение
        $ins = $pdo->prepare("INSERT INTO appeals (order_id, username, telegram, subject, status, created_at) VALUES (?, ?, ?, ?, 'open', NOW()) RETURNING id");
        $ins->execute([$appealOrderId, $appealUsername, $appealTelegram, $appealSubject]);
        
        // Получаем ID созданного обращения (PostgreSQL way)
        $aid = (int)($ins->fetchColumn() ?: 0);

        if ($aid > 0) {
            $m = $pdo->prepare("INSERT INTO appeals_messages (appeal_id, author, message, created_at) VALUES (?, 'client', ?, NOW())");
            $m->execute([$aid, $appealText]);
        }

        $appealMsg = 'ok';

        // ── Уведомление админу в Telegram ──
        $_tgToken = getenv('BOT_TOKEN') ?: getenv('TELEGRAM_BOT_TOKEN') ?: '8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg';
        $_adminId = getenv('ADMIN_ID') ?: '1710365896';
        $_siteUrl = 'https://portfolio-site-boo5.onrender.com/admin/index.php?view_order=' . $appealOrderId;
        $_tgText  = "📩 <b>Новое обращение по заказу!</b>\n\n"
            . "👤 Клиент: <b>" . htmlspecialchars($appealUsername ?: 'Клиент') . "</b>\n"
            . "📋 Заказ: <b>#" . $appealOrderId . "</b>\n"
            . "📌 Тема: <b>" . htmlspecialchars($appealSubject) . "</b>\n\n"
            . "💬 <i>" . htmlspecialchars(mb_substr($appealText, 0, 300)) . (mb_strlen($appealText) > 300 ? '...' : '') . "</i>\n\n"
            . "🔗 <a href=\"" . $_siteUrl . "\">Открыть заказ в админке</a>\n"
            . "💡 <i>Ответить можно во вкладке «Обращения»</i>";
        $_ch = curl_init('https://api.telegram.org/bot' . $_tgToken . '/sendMessage');
        curl_setopt_array($_ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_POSTFIELDS     => ['chat_id' => $_adminId, 'text' => $_tgText, 'parse_mode' => 'HTML'],
        ]);
        curl_exec($_ch);
        curl_close($_ch);
    } catch (Throwable $e) {
        error_log("Appeal submission error: " . $e->getMessage());
        if ($appealMsg === '') $appealMsg = 'err'; // Fallback generic error
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?order=' . $appealOrderId . '&appeal=' . $appealMsg);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT tg_id, tg_username, tg_first_name, tg_photo_url, linked, created_at
        FROM tg_links
        WHERE session_id = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$sid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && $row['linked'] && $row['linked'] !== 'f') {
        $profile = $row;
        if (!$isAdmin && !empty($row['tg_id']) && (string)$row['tg_id'] === $adminTgId) {
            $isAdmin = true;
        }

        $tg_id       = $row['tg_id'] ?? '';
        $tg_username = $row['tg_username'] ?? '';

        // ── Lazy avatar refresh: re-fetch if empty or expired TG URL ──
        // Вынесено в includes/session.php::ensureTgAvatarFresh(), чтобы
        // главная страница (index.php) умела то же самое, а не только профиль.
        $profile['tg_photo_url'] = ensureTgAvatarFresh(
            $pdo,
            $sid,
            (string)$tg_id,
            (string)($row['tg_photo_url'] ?? '')
        );

        $params  = [];
        $clauses = [];
        if ($tg_id !== '') {
            $clauses[] = 'client_chat_id = ?';
            $params[]  = $tg_id;
        }
        if ($tg_username !== '') {
            $clauses[] = 'telegram = ?';
            $params[]  = '@' . ltrim($tg_username, '@');
            $clauses[] = 'telegram = ?';
            $params[]  = ltrim($tg_username, '@');
        }
        if ($sid !== '') {
            $clauses[] = 'session_id = ?';
            $params[]  = $sid;
        }

        if (!empty($clauses)) {
            $sql = "SELECT id, service_key, service_keys_extra, status, details, created_at, screenshot, example_photo, client_chat_id, deadline,
                           payment_status, payment_receipt, payment_received_at, accepted_at, started_at, declined_reason, is_urgent,
                           work_file, work_file_name, client_accepted_at, revision_count
                    FROM orders
                    WHERE " . implode(' OR ', $clauses) . "
                    ORDER BY created_at DESC
                    LIMIT 50";
            $ostmt = $pdo->prepare($sql);
            $ostmt->execute($params);
            $orders = $ostmt->fetchAll(PDO::FETCH_ASSOC);

            if ($tg_id !== '' && !empty($orders)) {
                foreach ($orders as $o) {
                    if (empty($o['client_chat_id'])) {
                        try {
                            $pdo->prepare("UPDATE orders SET client_chat_id = ? WHERE id = ? AND (client_chat_id IS NULL OR client_chat_id = '')")
                                ->execute([$tg_id, (int)$o['id']]);
                        } catch (Throwable $e) {}
                    }
                }
            }
        }

        // Загружаем обращения — ищем по tg_id (числовой chat_id) И по username
        $userAppeals = [];
        try {
            $apClauses = [];
            $apParams  = [];
            if ($tg_id !== '') {
                $apClauses[] = 'telegram = ?';
                $apParams[]  = $tg_id;
            }
            if ($tg_username !== '') {
                $apClauses[] = 'username LIKE ?';
                $apParams[]  = '%' . ltrim($tg_username, '@') . '%';
            }
            if (!empty($apClauses)) {
                $apSql = "SELECT * FROM appeals WHERE " . implode(' OR ', $apClauses) . " ORDER BY id DESC";
                $astmt = $pdo->prepare($apSql);
                $astmt->execute($apParams);
                $userAppeals = $astmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            // Если ничего не нашли — ищем по order_id заказов пользователя
            if (empty($userAppeals) && !empty($orders)) {
                $orderIds = array_column($orders, 'id');
                $in = implode(',', array_fill(0, count($orderIds), '?'));
                $astmt2 = $pdo->prepare("SELECT * FROM appeals WHERE order_id IN ($in) ORDER BY id DESC");
                $astmt2->execute($orderIds);
                $userAppeals = $astmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {}
    }
} catch (Throwable $e) {}

try {
    $settings = $pdo->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) { $settings = []; }
$themePreset  = $settings['theme_preset']  ?? 'onyx';
$themeShape   = $settings['theme_shape']   ?? 'soft';
$themeDensity = $settings['theme_density'] ?? 'normal';
$themeEffects = $settings['theme_effects'] ?? 'glow';

function profileDeadlineBadge(?string $deadline, string $status): string
{
    // Раньше бейдж дедлайна принудительно скрывался, как только заказ
    // переходил в статус "Готов" — то есть клиент не видел, сдали ли
    // заказ вовремя или с опозданием. Теперь он не пропадает после "Готов",
    // просрочку по-прежнему видно (как и в списке заказов в админке).
    // "Отклонён" — единственный статус, где дедлайн уже не имеет смысла.
    if ($status === 'declined') return '';
    if (empty($deadline)) return '';
    try {
        $dl  = new DateTime($deadline);
        $now = new DateTime();
    } catch (Throwable $e) { return ''; }

    $overdue  = $dl < $now;
    $isUrgent = $status === 'urgent';
    $diff     = $dl->getTimestamp() - time();
    $dateStr  = $dl->format('d.m.Y в H:i');

    // Заказ уже сдан — показываем итог (успели/не успели), но без вечной
    // тревожной пульсации: она была нужна, пока заказ ещё в работе.
    if ($status === 'ready') {
        if ($overdue) {
            return '<span class="order-deadline-badge deadline-overdue" style="animation:none;">🔴 Сдан с опозданием (дедлайн был ' . $dateStr . ')</span>';
        }
        return '<span class="order-deadline-badge deadline-normal">✅ Сдан в срок (дедлайн был ' . $dateStr . ')</span>';
    }

    if ($overdue) {
        $icon  = '🔴';
        $label = "Просрочен ({$dateStr})";
        $cls   = 'deadline-overdue';
    } elseif ($diff < 86400) {
        $h    = max(1, (int)ceil($diff / 3600));
        $icon  = '🟠';
        $label = "Осталось ~{$h} ч.";
        $cls   = 'deadline-urgent';
    } elseif ($isUrgent) {
        $icon  = '⚡';
        $label = "Срочно: {$dateStr}";
        $cls   = 'deadline-urgent';
    } else {
        $icon  = '📅';
        $label = "Сдать: {$dateStr}";
        $cls   = 'deadline-normal';
    }
    return "<span class=\"order-deadline-badge {$cls}\">{$icon} {$label}</span>";
}

function profileStatusLabel(string $s): string {
    if ($s === 'awaiting_payment') return 'Ожидает оплату';
    return match($s) {
        'pending'     => 'Ожидает',
        'in_progress' => 'В работе',
        'urgent'      => 'Срочный',
        'ready'       => 'Готов',
        'revision'                  => 'Правка: жди уточнение',
        'revision_free'             => 'Правка в работе',
        'revision_awaiting_payment' => 'Правка: ждёт оплаты',
        'revision_paid'             => 'Правка в работе',
        'declined'    => 'Отклонён',
        default       => ucfirst($s),
    };
}
function profileStatusColor(string $s): string {
    if ($s === 'awaiting_payment') return '#eab308';
    return match($s) {
        'pending'     => '#fb923c',
        'in_progress' => '#60a5fa',
        'urgent'      => '#f43f5e',
        'ready'       => '#4ade80',
        'revision', 'revision_free', 'revision_awaiting_payment', 'revision_paid' => '#fbbf24',
        'declined'    => '#6b7280',
        default       => '#8a8a96',
    };
}
function profileStatusEmoji(string $s): string {
    if ($s === 'awaiting_payment') return '💳';
    return match($s) {
        'pending'     => '🕐',
        'in_progress' => '🚀',
        'urgent'      => '⚡',
        'ready'       => '✅',
        'revision', 'revision_free', 'revision_paid' => '🔧',
        'revision_awaiting_payment' => '💳',
        'declined'    => '❌',
        default       => '📦',
    };
}

// Helper: render messages thread for an appeal
function renderAppealMessages(PDO $pdo, int $aid): string
{
    try {
        $mstmt = $pdo->prepare("SELECT author, message, created_at FROM appeals_messages WHERE appeal_id = ? ORDER BY id ASC");
        $mstmt->execute([$aid]);
        $msgs = $mstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $msgs = []; }

    if (empty($msgs)) {
        return '<div style="color:#8a8a96;font-size:12px;">Сообщений пока нет.</div>';
    }
    $html = '';
    foreach ($msgs as $m) {
        $isAdmin = ($m['author'] ?? '') === 'admin';
        $date    = date('d.m.Y H:i', strtotime($m['created_at']));
        $text    = nl2br(htmlspecialchars($m['message']));
        if ($isAdmin) {
            $html .= "<div style=\"background:rgba(34,197,94,.07);border-left:3px solid #22c55e;border-radius:0 8px 8px 0;padding:10px 13px;margin-bottom:8px;\">
                <div style=\"font-size:11px;font-weight:800;color:#86efac;margin-bottom:4px;\">Ответ дизайнера · {$date}</div>
                <div style=\"font-size:13px;color:#d8d8e8;white-space:pre-wrap;word-break:break-word;\">{$text}</div>
            </div>";
        } else {
            $html .= "<div style=\"background:rgba(249,115,22,.04);border-left:3px solid rgba(249,115,22,.3);border-radius:0 8px 8px 0;padding:10px 13px;margin-bottom:8px;\">
                <div style=\"font-size:11px;font-weight:800;color:#fdba74;margin-bottom:4px;\">Ваше сообщение · {$date}</div>
                <div style=\"font-size:13px;color:#d8d8e8;white-space:pre-wrap;word-break:break-word;\">{$text}</div>
            </div>";
        }
    }
    return $html;
}

$displayName = $profile ? (
    !empty($profile['tg_first_name']) ? $profile['tg_first_name'] :
    (!empty($profile['tg_username'])  ? '@' . ltrim($profile['tg_username'], '@') : 'Гость')
) : 'Гость';

$activeOrders   = array_filter($orders, fn($o) => in_array($o['status'], ['pending','awaiting_payment','in_progress','urgent','revision','revision_free','revision_awaiting_payment','revision_paid']));
$finishedOrders = array_filter($orders, fn($o) => in_array($o['status'], ['ready','declined']));

$statusPriority = ['urgent' => 0, 'in_progress' => 1, 'awaiting_payment' => 2, 'pending' => 3];
usort($activeOrders, function($a, $b) use ($statusPriority) {
    $pa = $statusPriority[$a['status']] ?? 9;
    $pb = $statusPriority[$b['status']] ?? 9;
    if ($pa !== $pb) return $pa <=> $pb;
    return (int)$b['id'] <=> (int)$a['id'];
});
usort($finishedOrders, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);

$expandedOrderId = (int)($_GET['order'] ?? 0);
$appealStatus    = $_GET['appeal'] ?? '';
$cancelledId     = (int)($_GET['cancelled'] ?? 0);
$editedId        = (int)($_GET['edited'] ?? 0);
$editStatus      = $_GET['edit_status'] ?? '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Профиль | Kostlim Design</title>
<link rel="icon" type="image/png" href="/assets/notify/fav.png" sizes="16x16">
<link rel="apple-touch-icon" href="/assets/notify/fav.png">
<link rel="shortcut icon" href="/assets/notify/fav.png">
<link rel="stylesheet" href="style.css">
<style>
body::before {
    content:'';position:fixed;top:-120px;left:50%;transform:translateX(-50%);
    width:700px;height:400px;background:radial-gradient(ellipse at center,rgba(249,115,22,0.13) 0%,transparent 70%);
    pointer-events:none;z-index:0;
}
.profile-wrap { max-width:calc(100% - 40px);margin:0 auto;padding:40px 20px 80px;position:relative;z-index:1; }
.profile-hero {
    background:var(--card);border:1px solid var(--border);border-radius:24px;padding:32px 28px;
    display:flex;align-items:center;gap:24px;margin-bottom:32px;
    box-shadow:0 0 40px rgba(0,0,0,0.3);position:relative;overflow:hidden;
}
.profile-hero::before {
    content:'';position:absolute;top:-40px;right:-40px;width:200px;height:200px;
    background:radial-gradient(circle,rgba(34,197,94,0.08) 0%,transparent 70%);pointer-events:none;
}
.profile-ava-wrap { position:relative;flex-shrink:0; }
.profile-ava { width:88px;height:88px;border-radius:50%;object-fit:cover;border:3px solid rgba(34,197,94,0.5);box-shadow:0 0 24px rgba(34,197,94,0.2);display:block; }
.profile-ava-fallback {
    width:88px;height:88px;border-radius:50%;background:linear-gradient(135deg,rgba(34,197,94,0.2),rgba(34,197,94,0.05));
    border:3px solid rgba(34,197,94,0.4);display:flex;align-items:center;justify-content:center;
    font-size:32px;font-weight:900;color:#86efac;text-transform:uppercase;
}
.profile-tg-badge {
    position:absolute;bottom:2px;right:2px;width:24px;height:24px;background:#0088cc;
    border-radius:50%;border:2px solid var(--bg);display:flex;align-items:center;justify-content:center;
}
.profile-info { flex:1;min-width:0; }
.profile-name { font-size:22px;font-weight:900;color:var(--text);margin:0 0 4px;text-transform:uppercase;letter-spacing:0.5px; }
.profile-username { color:#86efac;font-size:13px;font-weight:700;margin-bottom:10px; }
.profile-meta { display:flex;gap:16px;flex-wrap:wrap; }
.profile-stat { display:flex;flex-direction:column;align-items:center;background:rgba(0,0,0,0.25);border:1px solid var(--border);border-radius:10px;padding:8px 16px;min-width:70px; }
.profile-stat-num { font-size:20px;font-weight:900;color:var(--text); }
.profile-stat-label { font-size:10px;color:var(--text2);text-transform:uppercase;letter-spacing:0.5px;margin-top:2px; }
.profile-actions { display:flex;flex-direction:column;gap:8px;flex-shrink:0; }
.profile-action-btn { display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:10px;font-size:12px;font-weight:800;text-decoration:none;border:none;cursor:pointer;transition:.2s;font-family:inherit;white-space:nowrap; }
.btn-catalog { background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);color:#c0c0d0; }
.btn-catalog:hover { background:rgba(255,255,255,0.1); }
.btn-order { background:linear-gradient(135deg,#fb923c,#f97316);color:#fff;box-shadow:0 0 16px rgba(249,115,22,0.3); }
.btn-order:hover { opacity:.88;transform:translateY(-1px); }
.btn-bot { background:rgba(0,136,204,0.15);border:1px solid rgba(0,136,204,0.3);color:#60c8f5; }
.btn-bot:hover { background:rgba(0,136,204,0.25); }

.orders-section { margin-bottom:28px; }
.orders-section-title { font-size:13px;font-weight:900;text-transform:uppercase;letter-spacing:1.5px;color:var(--text2);margin:0 0 14px;display:flex;align-items:center;gap:8px; }
.orders-section-title::after { content:'';flex:1;height:1px;background:var(--border); }

.order-deadline-badge { display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:3px 9px;font-size:11px;font-weight:800;border:1px solid;white-space:nowrap;margin-top:4px; }
.order-deadline-badge.deadline-normal  { background:rgba(96,165,250,.12); color:#60a5fa; border-color:rgba(96,165,250,.3); }
.order-deadline-badge.deadline-urgent  { background:rgba(249,115,22,.15); color:#fb923c; border-color:rgba(249,115,22,.35); animation:pulse-orange 1.8s ease-in-out infinite; }
.order-deadline-badge.deadline-overdue { background:rgba(239,68,68,.18);  color:#ef4444; border-color:rgba(239,68,68,.4);  animation:pulse-red 1.5s ease-in-out infinite; }
@keyframes pulse-orange { 0%,100%{ box-shadow:0 0 0 0 rgba(249,115,22,0); } 50%{ box-shadow:0 0 0 3px rgba(249,115,22,.3); } }
@keyframes pulse-red    { 0%,100%{ box-shadow:0 0 0 0 rgba(239,68,68,0);  } 50%{ box-shadow:0 0 0 3px rgba(239,68,68,.35); } }

.order-card { background:var(--card);border:1px solid var(--border);border-radius:16px;margin-bottom:12px;overflow:hidden;transition:border-color .2s,box-shadow .2s; }
.order-card:hover { border-color:var(--border-accent);box-shadow:0 0 20px rgba(249,115,22,0.1); }
.order-card.status-urgent     { border-color:rgba(244,63,94,0.5);  box-shadow:0 0 18px rgba(244,63,94,0.12);  background:linear-gradient(135deg,rgba(244,63,94,0.06),var(--card)); }
.order-card.status-in_progress{ border-color:rgba(96,165,250,0.35); box-shadow:0 0 14px rgba(96,165,250,0.08); }
.order-card.status-awaiting_payment{ border-color:rgba(234,179,8,0.45); box-shadow:0 0 14px rgba(234,179,8,0.08); }
.order-card.status-pending    { border-color:rgba(249,115,22,0.25); }
.order-card.status-ready      { border-color:rgba(74,222,128,0.35); background:linear-gradient(135deg,rgba(74,222,128,0.04),var(--card)); }
.order-card.status-declined   { border-color:rgba(239,68,68,0.2); opacity:.65; }
.order-card-header { padding:18px 20px;display:flex;align-items:flex-start;gap:16px;cursor:pointer;user-select:none; }
.order-card-header:hover { background:rgba(255,255,255,0.02); }
.order-card-emoji { font-size:22px;flex-shrink:0;margin-top:2px; }
.order-card-body { flex:1;min-width:0; }
.order-card-title { font-size:14px;font-weight:800;color:var(--text);margin:0 0 4px; }
.order-card-meta { font-size:12px;color:var(--text2);margin-bottom:4px; }
.order-card-details { font-size:12px;color:var(--text2);line-height:1.55;max-height:44px;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical; }
.order-status-badge { display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:800;border:1px solid;white-space:nowrap;flex-shrink:0; }
.order-expand-arrow { flex-shrink:0;color:var(--text2);transition:transform .25s;margin-top:4px; }
.order-card-header[aria-expanded="true"] .order-expand-arrow { transform:rotate(180deg); }
.btn-toggle-history { background:transparent;border:1px solid var(--border);border-radius:8px;padding:7px 14px;color:var(--text2);font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:.2s; }
.btn-toggle-history:hover { border-color:var(--border-accent);color:var(--text); }

.order-card-expanded { border-top:1px solid var(--border);padding:18px 20px;display:none; }
.order-card-expanded.open { display:block; }

/* ── Миниатюры прикреплённых файлов (референсы/чек) + лайтбокс ── */
.order-thumbs-row { display:flex; gap:8px; flex-wrap:wrap; margin:0 0 14px; }
.order-thumb-link { display:block; line-height:0; text-decoration:none; }
.order-thumb {
    width:52px; height:52px; object-fit:cover; border-radius:10px;
    border:1px solid var(--border); cursor:pointer;
    transition:border-color .18s, transform .15s, box-shadow .18s;
}
.order-thumb:hover {
    border-color:rgba(249,115,22,.6);
    box-shadow:0 0 0 1px rgba(249,115,22,.25), 0 6px 16px rgba(249,115,22,.15);
    transform:translateY(-2px);
}
#order-lightbox-overlay {
    display:none; position:fixed; inset:0; z-index:9999;
    background:rgba(5,5,8,.92); backdrop-filter:blur(4px);
    align-items:center; justify-content:center; padding:24px;
    animation:orderLightboxFade .15s ease;
}
#order-lightbox-overlay.open { display:flex; }
@keyframes orderLightboxFade { from{opacity:0;} to{opacity:1;} }
#order-lightbox-overlay img {
    max-width:90vw; max-height:88vh; border-radius:14px;
    box-shadow:0 20px 60px rgba(0,0,0,.6); border:1px solid rgba(249,115,22,.25);
}
#order-lightbox-close {
    position:absolute; top:18px; right:22px;
    width:40px; height:40px; border-radius:50%;
    background:linear-gradient(135deg,var(--accent2),var(--accent)); color:#fff;
    border:none; font-size:20px; line-height:1; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    box-shadow:0 6px 20px rgba(249,115,22,.45); transition:transform .15s, opacity .15s;
}
#order-lightbox-close:hover { transform:scale(1.08); opacity:.9; }
.order-detail-block { background:rgba(0,0,0,0.2);border-radius:10px;padding:12px 14px;margin-bottom:12px;font-size:13px;color:var(--text2);line-height:1.6;white-space:pre-wrap;word-break:break-word; }

/* ── Редактирование ТЗ ── */
.order-detail-row { display:flex; align-items:flex-start; gap:8px; margin-bottom:12px; }
.order-detail-row .order-detail-block { flex:1; margin-bottom:0; }
.order-edit-btn {
    flex-shrink:0; width:32px; height:32px; border-radius:9px;
    background:rgba(255,255,255,0.04); border:1px solid var(--border);
    color:var(--text2); cursor:pointer; display:flex; align-items:center; justify-content:center;
    transition:background .18s, border-color .18s, color .18s, transform .15s;
}
.order-edit-btn:hover {
    background:rgba(249,115,22,.12); border-color:rgba(249,115,22,.4);
    color:var(--accent); transform:translateY(-1px);
}
.order-edit-form { display:none; margin:-4px 0 12px; }
.order-edit-form.open { display:block; }
.order-edit-form textarea {
    width:100%; background:rgba(0,0,0,0.25); border:1px solid var(--border);
    border-radius:10px; padding:12px 14px; color:#fff; font-size:16px;
    line-height:1.6; font-family:inherit; resize:vertical; margin-bottom:10px;
    transition:border-color .18s;
}
.order-edit-form textarea:focus { outline:none; border-color:rgba(249,115,22,.5); }
.order-edit-form-actions { display:flex; gap:10px; }
.order-actions-row { display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px; }
.btn-cancel-order {
    display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;
    background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;
    font-size:12px;font-weight:800;cursor:pointer;font-family:inherit;transition:.2s;
}
.btn-cancel-order:hover { background:rgba(239,68,68,0.2);border-color:#ef4444; }
.btn-appeal-toggle {
    display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;
    background:rgba(249,115,22,0.1);border:1px solid rgba(249,115,22,0.3);color:#fdba74;
    font-size:12px;font-weight:800;cursor:pointer;font-family:inherit;transition:.2s;
}
.btn-appeal-toggle:hover { background:rgba(249,115,22,0.2);border-color:#f97316; }

.appeal-form-wrap { background:rgba(249,115,22,0.05);border:1px solid rgba(249,115,22,0.2);border-radius:12px;padding:16px;margin-top:12px;display:none; }
.appeal-form-wrap.open { display:block; }
.appeal-form-wrap label { display:block;color:#d9d9e4;font-size:11px;font-weight:800;margin:10px 0 5px;text-transform:uppercase;letter-spacing:.5px; }
.appeal-form-wrap input, .appeal-form-wrap textarea {
    /* font-size 16px — иначе iOS Safari зумит страницу при фокусе */
    width:100%;background:#0e0e14;color:#fff;border:1px solid #2a2a38;border-radius:8px;
    padding:10px 12px;outline:none;font-family:Montserrat,sans-serif;font-size:16px;transition:.2s;box-sizing:border-box;
}
.appeal-form-wrap input:focus, .appeal-form-wrap textarea:focus { border-color:#f97316;box-shadow:0 0 0 3px rgba(249,115,22,0.15); }
.appeal-form-wrap textarea { min-height:80px;resize:vertical; }
.btn-appeal-submit {
    margin-top:10px;border:none;border-radius:9px;padding:10px 20px;
    background:linear-gradient(135deg,#fb923c,#f97316);color:#fff;font-weight:800;
    cursor:pointer;font-family:Montserrat,sans-serif;font-size:13px;
    box-shadow:0 6px 18px rgba(249,115,22,0.3);transition:.2s;
}
.btn-appeal-submit:hover { opacity:.88;transform:translateY(-1px); }

.pay-card {
    border-radius:14px;padding:18px 18px 16px;position:relative;overflow:hidden;
    background:linear-gradient(160deg,rgba(234,179,8,.10),rgba(234,179,8,.03));
    border:1px solid rgba(234,179,8,.35);
}
.pay-card::before {
    content:"";position:absolute;top:0;left:0;right:0;height:3px;
    background:linear-gradient(90deg,#fbbf24,#f97316);
}
.pay-card-title { display:flex;align-items:center;gap:8px;color:#fde68a;font-weight:800;font-size:14px;margin-bottom:10px; }
.pay-card-title svg { flex-shrink:0; }
.pay-card-hint { font-size:12px;color:var(--text2);line-height:1.55;margin-bottom:14px; }
.file-picker-row { display:flex;align-items:center;gap:10px;flex-wrap:wrap; }
.file-picker-label {
    display:inline-flex;align-items:center;gap:7px;border:none;border-radius:9px;padding:10px 18px;
    background:linear-gradient(135deg,#fb923c,#f97316);color:#fff;font-weight:800;
    cursor:pointer;font-family:Montserrat,sans-serif;font-size:12.5px;
    box-shadow:0 6px 18px rgba(249,115,22,0.3);transition:.2s;white-space:nowrap;
}
.file-picker-label:hover { opacity:.88;transform:translateY(-1px); }
.file-picker-label input[type=file] { display:none; }
.file-picker-name { font-size:12px;color:#8a8a96;overflow:hidden;text-overflow:ellipsis;max-width:200px;white-space:nowrap; }
.pay-card-support { font-size:11px;color:#8a8a96;margin-top:12px;padding-top:12px;border-top:1px dashed rgba(234,179,8,.25); }
.pay-card-support a { color:#fdba74;text-decoration:none;font-weight:700; }
.pay-card-support a:hover { text-decoration:underline; }

.profile-notice { border-radius:12px;padding:13px 16px;margin-bottom:18px;font-weight:700;font-size:13px; }
.profile-notice.ok { background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.35);color:#86efac; }
.profile-notice.err { background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.35);color:#fca5a5; }
.profile-notice.info { background:rgba(249,115,22,0.1);border:1px solid rgba(249,115,22,0.35);color:#fdba74; }

.empty-state { text-align:center;padding:40px 20px;color:var(--text2); }
.empty-state-icon { font-size:40px;margin-bottom:12px; }
.empty-state p { font-size:14px;margin:0 0 16px; }
.empty-state a { display:inline-flex;align-items:center;gap:7px;padding:10px 22px;background:linear-gradient(135deg,#fb923c,#f97316);color:#fff;border-radius:30px;text-decoration:none;font-size:13px;font-weight:800;box-shadow:0 0 16px rgba(249,115,22,0.3); }
.not-linked-card { background:var(--card);border:1px solid var(--border);border-radius:20px;padding:40px 28px;text-align:center;max-width:420px;margin:60px auto; }
.not-linked-card h2 { color:var(--text);font-size:18px;margin:16px 0 8px; }
.not-linked-card p { color:var(--text2);font-size:13px;margin:0 0 20px; }

/* Appeals thread block inside order */
.appeals-thread { margin-top:14px;border-top:1px solid var(--border);padding-top:14px; }
.appeals-thread-title { font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#8a8a96;margin-bottom:10px; }
.appeal-thread-item { background:#0b0b10;border-radius:10px;padding:12px 14px;margin-bottom:10px;border:1px solid rgba(255,255,255,.04); }
.appeal-thread-subject { font-size:12px;font-weight:800;color:#d8d8e8;margin-bottom:8px;display:flex;align-items:center;gap:6px; }
.appeal-status-dot { width:7px;height:7px;border-radius:50%;flex-shrink:0; }

@media(max-width:600px){
    /* ── П.3.2: карточка профиля — весь контент центрирован ── */
    .profile-hero{flex-direction:column;align-items:center;text-align:center;padding:26px 20px;}
    .profile-info{width:100%;display:flex;flex-direction:column;align-items:center;text-align:center;}
    .profile-name{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:8px;}
    .profile-meta{gap:10px;justify-content:center;width:100%;}
    /* ── Кнопки действий вертикально, во всю ширину карточки, крупнее ── */
    .profile-actions{flex-direction:column;width:100%;gap:10px;margin-top:6px;}
    .profile-action-btn{width:100%;justify-content:center;min-height:46px;font-size:13px;padding:12px 16px;}
    .btn-order{box-shadow:0 0 20px rgba(249,115,22,0.4);}
    .orders-section-title{justify-content:center;text-align:center;}
    .orders-section-title::after{display:none;}
    /* ── П.3.3: заказы центрированы, крупнее номер/статус ── */
    .order-card-header{flex-wrap:wrap;flex-direction:column;align-items:center;text-align:center;gap:8px;}
    .order-card-body{width:100%;}
    .order-card-title{font-size:15px;}
    .order-card-meta{justify-content:center;display:flex;flex-wrap:wrap;gap:6px;}
    .order-status-badge{font-size:12px;padding:5px 12px;}
    .order-expand-arrow{position:static;}
    .order-actions-row{flex-direction:column;}
}
</style>
<style>
/* ── AI-попап "Нужна помощь?" — кнопка крупнее, центрирована ── */
.ai-widget-bubble-btn {
    min-height: 46px !important;
    padding: 13px !important;
    font-size: 13px !important;
    text-align: center !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}
@media (max-width: 600px) {
    /* Плавающая иконка (корзина/бот) — не перекрывает нижние кнопки заказа */
    #ai-widget-root { bottom: 18px; right: 16px; }
    #ai-widget-fab { width: 54px; height: 54px; }
}
.hidden { display: none !important; }
</style>
</head>
<body class="theme-<?= htmlspecialchars($themePreset) ?> shape-<?= htmlspecialchars($themeShape) ?> density-<?= htmlspecialchars($themeDensity) ?> effects-<?= htmlspecialchars($themeEffects) ?>">

<header>
    <div class="header-left" style="display:flex;align-items:center;gap:10px;">
        <a href="index.php" style="display:flex;align-items:center;" title="На главную">
            <?php if ($siteAvatar !== ''): ?>
                <img src="<?= htmlspecialchars(imgSrc($siteAvatar)) ?>" class="avatar-mini" alt="Kostlim">
            <?php else: ?>
                <img src="https://i.imgur.com/w9NThbA.png" class="avatar-mini" alt="Kostlim" onerror="this.src='https://i.imgur.com/w9NThbA.png'">
            <?php endif; ?>
        </a>

        <a href="https://t.me/designkostlim" target="_blank" class="tg-glow-btn" title="Telegram">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        </a>

        <?php if ($isAdmin): ?>
        <a href="admin/index.php" class="tg-glow-btn" title="Админ-панель">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        </a>
        <?php endif; ?>
    </div>
    <div class="brand-title"><a href="index.php" style="text-decoration:none;color:inherit;"><h1>KOSTLIM</h1><span>DESIGN</span></a></div>
    <div class="header-right" style="display:flex;align-items:center;gap:10px;">
        <a href="price.php" class="nav-link nav-price">Прайс</a>
        <?php if ($profile): ?>
        <span class="tg-user-chip" style="cursor:default;">
            <?php if (!empty($profile['tg_photo_url'])): ?>
                <img src="<?= htmlspecialchars(imgSrc($profile['tg_photo_url'] ?? '')) ?>" class="tg-user-ava" alt="аватар" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <span class="tg-user-ava-fallback" style="display:none;"><?= mb_strtoupper(mb_substr($displayName, 0, 1)) ?></span>
            <?php else: ?>
                <span class="tg-user-ava-fallback"><?= mb_strtoupper(mb_substr($displayName, 0, 1)) ?></span>
            <?php endif; ?>
            <span class="tg-user-name"><?= htmlspecialchars($displayName) ?></span>
        </span>
        <?php endif; ?>
    </div>
</header>

<div class="profile-wrap">

<?php if (!$profile): ?>
<div class="not-linked-card">
    <div style="font-size:48px;">🔗</div>
    <h2>Профиль не найден</h2>
    <p>Ты ещё не привязал Telegram к этому сайту. Нажми кнопку «Привязать TG» на главной странице — это займёт 30 секунд.</p>
    <a href="index.php" class="profile-action-btn btn-order" style="text-decoration:none;display:inline-flex;justify-content:center;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        На главную
    </a>
</div>

<?php else: ?>

<?php if ($cancelledId > 0): ?>
<div class="profile-notice info">✅ Заказ #<?= $cancelledId ?> отменён и убран из очереди.</div>
<?php endif; ?>
<?php if ($editedId > 0 && $editStatus === 'edit_ok'): ?>
<div class="profile-notice info">✅ ТЗ заказа #<?= $editedId ?> обновлено. Дизайнер уведомлён.</div>
<?php elseif ($editedId > 0 && $editStatus !== ''): ?>
<div class="profile-notice info" style="border-color:rgba(239,68,68,.3);color:#f87171;">❌ Не удалось изменить ТЗ (заказ уже взят в работу или произошла ошибка).</div>
<?php endif; ?>
<?php if ($appealStatus === 'err_order_id'): ?>
<div class="profile-notice err">❌ Не удалось отправить обращение. Неверный ID заказа.</div>
<?php elseif ($appealStatus === 'err_subject_short'): ?>
<div class="profile-notice err">❌ Не удалось отправить обращение. Тема должна быть не менее 3 символов.</div>
<?php elseif ($appealStatus === 'err_message_short'): ?>
<div class="profile-notice err">❌ Не удалось отправить обращение. Сообщение должно быть не менее 10 символов.</div>
<?php elseif ($appealStatus === 'ok'): ?>
<div class="profile-notice ok">✅ Обращение отправлено дизайнеру! Ответ появится в разделе ниже.</div>
<?php elseif ($appealStatus === 'err'): ?>
<div class="profile-notice err">❌ Не удалось отправить обращение. Заполни все поля.</div>
<?php endif; ?>

<!-- ── HERO-КАРТОЧКА ── -->
<div class="profile-hero">
    <div class="profile-ava-wrap">
        <?php if (!empty($profile['tg_photo_url'])): ?>
            <img src="<?= htmlspecialchars(imgSrc($profile['tg_photo_url'] ?? '')) ?>" class="profile-ava" alt="аватар" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <div class="profile-ava-fallback" style="display:none;"><?= mb_strtoupper(mb_substr($displayName, 0, 1)) ?></div>
        <?php else: ?>
            <div class="profile-ava-fallback"><?= mb_strtoupper(mb_substr($displayName, 0, 1)) ?></div>
        <?php endif; ?>
        <div class="profile-tg-badge">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        </div>
    </div>
    <div class="profile-info">
        <div class="profile-name">
            <?= htmlspecialchars($displayName) ?>
            <?php if ($isAdmin): ?><span style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:0.5px;color:#fb923c;background:rgba(249,115,22,0.15);border:1px solid rgba(249,115,22,0.35);border-radius:5px;padding:2px 7px;vertical-align:middle;margin-left:6px;">admin</span><?php endif; ?>
        </div>
        <?php if (!empty($profile['tg_username'])): ?>
            <div class="profile-username">@<?= htmlspecialchars(ltrim($profile['tg_username'], '@')) ?></div>
        <?php endif; ?>
        <div class="profile-meta">
            <div class="profile-stat"><div class="profile-stat-num"><?= count($orders) ?></div><div class="profile-stat-label">Всего</div></div>
            <div class="profile-stat"><div class="profile-stat-num" style="color:#60a5fa;"><?= count($activeOrders) ?></div><div class="profile-stat-label">Активных</div></div>
            <div class="profile-stat"><div class="profile-stat-num" style="color:#4ade80;"><?= count(array_filter($orders, fn($o) => $o['status'] === 'ready')) ?></div><div class="profile-stat-label">Готовых</div></div>
        </div>
    </div>
    <div class="profile-actions">
        <a href="index.php" class="profile-action-btn btn-catalog">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            В каталог
        </a>
        <a href="order.php" class="profile-action-btn btn-order">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
            Новый заказ
        </a>
        <a href="https://t.me/kostlimdznbot" target="_blank" class="profile-action-btn btn-bot">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
            Открыть бот
        </a>
        <a href="https://t.me/Perlo_ovka" target="_blank" class="profile-action-btn" style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);color:var(--text2);">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            Поддержка
        </a>
    </div>
</div>

<!-- ── АКТИВНЫЕ ЗАКАЗЫ ── -->
<div class="orders-section">
    <div class="orders-section-title"><span>⚡ Активные заказы</span></div>
    <?php if (empty($activeOrders)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">📭</div>
        <p>Нет активных заказов</p>
        <a href="order.php">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
            Оформить заказ
        </a>
    </div>
    <?php else: ?>
        <?php foreach ($activeOrders as $order):
            $color = profileStatusColor($order['status']);
            $label = profileStatusLabel($order['status']);
            $emoji = profileStatusEmoji($order['status']);
            $date  = date('d.m.Y H:i', strtotime($order['created_at']));
            $oid   = (int)$order['id'];
            $isExpanded = ($expandedOrderId === $oid);
            $orderAppeals = array_values(array_filter($userAppeals ?? [], fn($a) => (int)$a['order_id'] === $oid));
        ?>
        <div class="order-card status-<?= htmlspecialchars($order['status']) ?>" id="order-<?= $oid ?>">
            <div class="order-card-header" onclick="toggleOrder(<?= $oid ?>)" aria-expanded="<?= $isExpanded ? 'true' : 'false' ?>" id="hdr-<?= $oid ?>">
                <div class="order-card-emoji"><?= $emoji ?></div>
                <div class="order-card-body">
                    <div class="order-card-title">Заказ #<?= $oid ?> — <?= htmlspecialchars($order['service_key']) ?></div>
                    <div class="order-card-meta"><?= $date ?></div>
                    <?php $dlBadge = profileDeadlineBadge($order['deadline'] ?? null, $order['status']); ?>
                    <?php if ($dlBadge): ?><div><?= $dlBadge ?></div><?php endif; ?>
                    <?php if (!empty($order['details'])): ?>
                    <div class="order-card-details"><?= htmlspecialchars($order['details']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="order-status-badge" style="color:<?= $color ?>;border-color:<?= $color ?>22;background:<?= $color ?>11;">
                    <?= $emoji ?> <?= htmlspecialchars($label) ?>
                </div>
                <svg class="order-expand-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            </div>

            <div class="order-card-expanded <?= $isExpanded ? 'open' : '' ?>" id="exp-<?= $oid ?>">
                <?php if ($dlBadge): ?><div style="margin-bottom:12px;"><?= $dlBadge ?></div><?php endif; ?>
                <?php $canEditDetails = in_array($order['status'], ['pending', 'awaiting_payment'], true); ?>
                <?php if (!empty($order['details'])): ?>
                <div class="order-detail-row">
                    <div class="order-detail-block" id="details-view-<?= $oid ?>"><?= htmlspecialchars($order['details']) ?></div>
                    <?php if ($canEditDetails): ?>
                    <button type="button" class="order-edit-btn" onclick="toggleOrderEdit(<?= $oid ?>)" title="Редактировать ТЗ">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                    </button>
                    <?php endif; ?>
                </div>
                <?php if ($canEditDetails): ?>
                <form method="POST" class="order-edit-form" id="edit-form-<?= $oid ?>">
                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                    <textarea name="new_details" maxlength="4000" rows="4"><?= htmlspecialchars($order['details']) ?></textarea>
                    <div class="order-edit-form-actions">
                        <button type="submit" name="edit_order_details" class="btn-appeal-submit" style="padding:9px 16px;">💾 Сохранить</button>
                        <button type="button" class="btn-appeal-toggle" onclick="toggleOrderEdit(<?= $oid ?>)">Отмена</button>
                    </div>
                </form>
                <?php endif; ?>
                <?php endif; ?>

                <?php
                    // Собираем миниатюры: референсы (example_photo — JSON-массив ссылок) + чек(и)
                    $thumbUrls = [];
                    if (!empty($order['example_photo'])) {
                        $decodedThumbs = json_decode((string)$order['example_photo'], true);
                        $thumbList = is_array($decodedThumbs) ? $decodedThumbs : [(string)$order['example_photo']];
                        foreach ($thumbList as $tu) {
                            $tu = trim((string)$tu);
                            if ($tu !== '') $thumbUrls[] = ['url' => imgSrc($tu), 'label' => 'Референс'];
                        }
                    }
                    // payment_receipt — JSON-массив (до 3 чеков на заказ, см.
                    // payment_receipt_count). Раньше показывался только один чек
                    // (последний записанный, а 2-й/3-й вообще терялись на сайте) —
                    // теперь показываем все присланные.
                    $receiptPdfUrls = [];
                    $receiptList = decodeReceiptList((string)($order['payment_receipt'] ?? ''));
                    foreach ($receiptList as $i => $rv) {
                        $receiptUrlTmp = imgSrc($rv, 'uploads/orders/');
                        $label = count($receiptList) > 1 ? ('Чек оплаты ' . ($i + 1)) : 'Чек оплаты';
                        if (str_ends_with(strtolower($receiptUrlTmp), '.pdf')) {
                            // PDF-чек нельзя показать через <img> — показываем
                            // отдельной ссылкой-кнопкой, а не миниатюрой.
                            $receiptPdfUrls[] = ['url' => $receiptUrlTmp, 'label' => $label];
                        } else {
                            $thumbUrls[] = ['url' => $receiptUrlTmp, 'label' => $label];
                        }
                    }
                ?>
                <?php if (!empty($thumbUrls)): ?>
                <div class="order-thumbs-row">
                    <?php foreach ($thumbUrls as $th): ?>
                        <a href="<?= htmlspecialchars($th['url']) ?>" target="_blank" rel="noopener" class="order-thumb-link" onclick="return openOrderLightbox(event, this.href)"><img src="<?= htmlspecialchars($th['url']) ?>" class="order-thumb" alt="<?= htmlspecialchars($th['label']) ?>" title="<?= htmlspecialchars($th['label']) ?>" onerror="this.closest('a').style.display='none'"></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($receiptPdfUrls)): ?>
                <div class="order-thumbs-row">
                    <?php foreach ($receiptPdfUrls as $rp): ?>
                    <a href="<?= htmlspecialchars($rp['url']) ?>" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:8px;background:#0b0b10;border:1px solid #2a2a38;border-radius:10px;padding:10px 14px;color:#fdba74;text-decoration:none;font-size:12px;font-weight:700;">📄 <?= htmlspecialchars($rp['label']) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($order['status'] === 'awaiting_payment'): ?>
                <div class="pay-card">
                    <div class="pay-card-title">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fbbf24" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                        Реквизиты отправлены в Telegram
                    </div>
                    <div class="pay-card-hint">Полная сумма и реквизиты — в сообщении от бота. После оплаты прикрепи сюда чек: срок заказа начнётся только после получения чека.</div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="order_id" value="<?= $oid ?>">
                        <div class="file-picker-row">
                            <label class="file-picker-label" for="receipt-file-<?= $oid ?>">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>
                                Выбрать файл
                                <input type="file" id="receipt-file-<?= $oid ?>" name="payment_receipt" accept="image/*,.pdf" required onchange="document.getElementById('receipt-name-<?= $oid ?>').textContent = this.files[0] ? this.files[0].name : 'Файл не выбран'; var rb=document.getElementById('receipt-submit-<?= $oid ?>'); rb.disabled = !this.files[0]; rb.style.opacity = this.files[0] ? '1' : '.5'; rb.style.cursor = this.files[0] ? 'pointer' : 'not-allowed';">
                            </label>
                            <span class="file-picker-name" id="receipt-name-<?= $oid ?>">Файл не выбран</span>
                        </div>
                        <button type="submit" name="upload_payment_receipt" id="receipt-submit-<?= $oid ?>" class="btn-appeal-submit" disabled style="opacity:.5;cursor:not-allowed;">💳 Отправить чек</button>
                    </form>
                    <div class="pay-card-support">По вопросам оплаты пишите - <a href="https://t.me/Perlo_ovka" target="_blank">@Perlo_ovka</a></div>
                </div>
                <?php elseif (!empty($order['payment_receipt'])): ?>
                <div style="font-size:12px;color:#86efac;margin-bottom:12px;">✅ Чек оплаты прикреплен к заказу.</div>
                <?php endif; ?>

                <?php if ($order['status'] === 'revision'): ?>
                <div style="font-size:12px;color:#fbbf24;background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.25);border-radius:10px;padding:10px 12px;margin-bottom:12px;">🔧 Правка получена, дизайнер сейчас её оценивает.</div>
                <?php elseif ($order['status'] === 'revision_free'): ?>
                <div style="font-size:12px;color:#fbbf24;background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.25);border-radius:10px;padding:10px 12px;margin-bottom:12px;">🔧 Правка принята в работу бесплатно — жди обновлённый файл.</div>
                <?php elseif ($order['status'] === 'revision_awaiting_payment'): ?>
                <div class="pay-card">
                    <div class="pay-card-title">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fbbf24" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                        Реквизиты на правку отправлены в Telegram
                    </div>
                    <div class="pay-card-hint">К оплате: <?= number_format((float)($order['revision_price_rub'] ?? 0), 0) ?> ₽ / <?= number_format((float)($order['revision_price_uan'] ?? 0), 0) ?> ₴. Чек нужно прислать в чат с ботом — как только он придёт, дизайнер сразу вернётся к правке.</div>
                    <div class="pay-card-support">По вопросам оплаты пишите - <a href="https://t.me/Perlo_ovka" target="_blank">@Perlo_ovka</a></div>
                </div>
                <?php elseif ($order['status'] === 'revision_paid'): ?>
                <div style="font-size:12px;color:#86efac;background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.25);border-radius:10px;padding:10px 12px;margin-bottom:12px;">✅ Оплата правки получена — дизайнер уже работает.</div>
                <?php endif; ?>

                <div class="order-actions-row">
                    <form method="POST" onsubmit="return confirm('Отменить заказ #<?= $oid ?>?');" style="margin:0;">
                        <input type="hidden" name="order_id" value="<?= $oid ?>">
                        <button type="submit" name="cancel_order" class="btn-cancel-order">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
                            Отменить заказ
                        </button>
                    </form>
                    <a href="order.php?service=<?= urlencode($order['service_key']) ?>" class="btn-appeal-toggle" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 4v6h6M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
                        Заказать снова
                    </a>
                    <button type="button" class="btn-appeal-toggle" onclick="toggleAppeal(<?= $oid ?>)">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        Связаться с дизайнером
                    </button>
                </div>

                <!-- Форма обращения — ВНУТРИ expanded -->
                <div class="appeal-form-wrap" id="appeal-form-<?= $oid ?>">
                    <?php $hasOpenAppeal = !empty(array_filter($orderAppeals, fn($a) => in_array($a['status'], ['open','answered']))); ?>
                    <?php if ($hasOpenAppeal): ?>
                        <div style="background:rgba(249,115,22,.07);border:1px solid rgba(249,115,22,.25);border-radius:8px;padding:12px 14px;font-size:13px;color:#fdba74;">
                            ⚠️ У тебя уже есть открытое обращение по этому заказу. Дождись ответа или создай новое после закрытия.
                        </div>
                    <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="appeal_order_id" value="<?= $oid ?>">
                        <label>Тема обращения</label>
                        <input type="text" name="appeal_subject" required placeholder="Например: уточнение по заказу" minlength="3" maxlength="200">
                        <label>Сообщение</label>
                        <textarea name="appeal_message" required rows="4" placeholder="Опиши вопрос или пожелание подробно..." minlength="10"></textarea>
                        <button type="submit" name="send_appeal" class="btn-appeal-submit">📤 Отправить обращение</button>
                    </form>
                    <?php endif; ?>
                </div>

                <!-- Тред обращений по этому заказу -->
                <?php if (!empty($orderAppeals)): ?>
                <div class="appeals-thread">
                    <div class="appeals-thread-title">💬 Переписка (<?= count($orderAppeals) ?>)</div>
                    <?php foreach ($orderAppeals as $ap): ?>
                    <div class="appeal-thread-item">
                        <div class="appeal-thread-subject">
                            <span class="appeal-status-dot" style="background:<?= $ap['status'] === 'open' ? '#f97316' : ($ap['status'] === 'closed' ? '#ef4444' : '#22c55e') ?>;"></span>
                            📩 <?= htmlspecialchars($ap['subject'] ?? '') ?><?php if ($ap['status'] === 'closed'): ?> <span style="font-size:10px;background:rgba(239,68,68,.15);color:#f87171;border-radius:4px;padding:1px 6px;margin-left:4px;">🔒 закрыто</span><?php endif; ?>
                            <span style="margin-left:auto;font-size:10px;color:#555568;font-weight:400;"><?= date('d.m.Y H:i', strtotime($ap['created_at'])) ?></span>
                        </div>
                        <?= renderAppealMessages($pdo, (int)$ap['id']) ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </div><!-- /order-card-expanded -->
        </div><!-- /order-card -->
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ── ЗАВЕРШЁННЫЕ ЗАКАЗЫ ── -->
<?php if (!empty($finishedOrders)): ?>
<div class="orders-section" id="history-section">
    <div class="orders-section-title" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <span>📁 История заказов (<?= count($finishedOrders) ?>)</span>
        <button type="button" class="btn-toggle-history" id="btn-toggle-history" onclick="toggleHistory()">Скрыть историю</button>
    </div>
    <div id="history-list">
    <?php foreach ($finishedOrders as $order):
        $color = profileStatusColor($order['status']);
        $label = profileStatusLabel($order['status']);
        $emoji = profileStatusEmoji($order['status']);
        $date  = date('d.m.Y', strtotime($order['created_at']));
        $oid   = (int)$order['id'];
        $isExpanded = ($expandedOrderId === $oid);
        $orderAppeals = array_values(array_filter($userAppeals ?? [], fn($a) => (int)$a['order_id'] === $oid));
    ?>
    <div class="order-card status-<?= htmlspecialchars($order['status']) ?>" id="order-<?= $oid ?>">
        <div class="order-card-header" onclick="toggleOrder(<?= $oid ?>)" aria-expanded="<?= $isExpanded ? 'true' : 'false' ?>" id="hdr-<?= $oid ?>">
            <div class="order-card-emoji"><?= $emoji ?></div>
            <div class="order-card-body">
                <div class="order-card-title">Заказ #<?= $oid ?> — <?= htmlspecialchars($order['service_key']) ?></div>
                <div class="order-card-meta"><?= $date ?></div>
                <?php if (!empty($order['details'])): ?>
                <div class="order-card-details"><?= htmlspecialchars(mb_substr($order['details'], 0, 100)) ?></div>
                <?php endif; ?>
            </div>
            <div class="order-status-badge" style="color:<?= $color ?>;border-color:<?= $color ?>22;background:<?= $color ?>11;">
                <?= $emoji ?> <?= htmlspecialchars($label) ?>
            </div>
            <svg class="order-expand-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
        </div>

        <!-- order-card-expanded для истории — ЗАКРЫТЫЙ правильно -->
        <div class="order-card-expanded <?= $isExpanded ? 'open' : '' ?>" id="exp-<?= $oid ?>">
            <?php if (!empty($order['details'])): ?>
            <div class="order-detail-block"><?= htmlspecialchars($order['details']) ?></div>
            <?php endif; ?>

            <?php
                $thumbUrlsHist = [];
                if (!empty($order['example_photo'])) {
                    $decodedThumbsH = json_decode((string)$order['example_photo'], true);
                    $thumbListH = is_array($decodedThumbsH) ? $decodedThumbsH : [(string)$order['example_photo']];
                    foreach ($thumbListH as $tu) {
                        $tu = trim((string)$tu);
                        if ($tu !== '') $thumbUrlsHist[] = ['url' => imgSrc($tu), 'label' => 'Референс'];
                    }
                }
                // payment_receipt — JSON-массив (до 3 чеков на заказ), показываем все.
                $receiptPdfUrlsHist = [];
                $receiptListHist = decodeReceiptList((string)($order['payment_receipt'] ?? ''));
                foreach ($receiptListHist as $i => $rv) {
                    $receiptUrlHist = imgSrc($rv, 'uploads/orders/');
                    $labelHist = count($receiptListHist) > 1 ? ('Чек оплаты ' . ($i + 1)) : 'Чек оплаты';
                    if (str_ends_with(strtolower($receiptUrlHist), '.pdf')) {
                        $receiptPdfUrlsHist[] = ['url' => $receiptUrlHist, 'label' => $labelHist];
                    } else {
                        $thumbUrlsHist[] = ['url' => $receiptUrlHist, 'label' => $labelHist];
                    }
                }
            ?>
            <?php if (!empty($thumbUrlsHist)): ?>
            <div class="order-thumbs-row">
                <?php foreach ($thumbUrlsHist as $th): ?>
                    <a href="<?= htmlspecialchars($th['url']) ?>" target="_blank" rel="noopener" class="order-thumb-link" onclick="return openOrderLightbox(event, this.href)"><img src="<?= htmlspecialchars($th['url']) ?>" class="order-thumb" alt="<?= htmlspecialchars($th['label']) ?>" title="<?= htmlspecialchars($th['label']) ?>" onerror="this.closest('a').style.display='none'"></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($receiptPdfUrlsHist)): ?>
            <div class="order-thumbs-row">
                <?php foreach ($receiptPdfUrlsHist as $rp): ?>
                <a href="<?= htmlspecialchars($rp['url']) ?>" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:8px;background:#0b0b10;border:1px solid #2a2a38;border-radius:10px;padding:10px 14px;color:#fdba74;text-decoration:none;font-size:12px;font-weight:700;">📄 <?= htmlspecialchars($rp['label']) ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="order-actions-row">
                <button type="button" class="btn-appeal-toggle" onclick="toggleAppeal(<?= $oid ?>)">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    Написать дизайнеру
                </button>
                <a href="order.php?service=<?= urlencode($order['service_key']) ?>" class="btn-appeal-toggle" style="text-decoration:none;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 4v6h6M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
                    Заказать снова
                </a>
                <?php if ($order['status'] === 'ready'): ?>
                <a href="review.php?order=<?= $oid ?>" class="btn-appeal-toggle" style="text-decoration:none;background:rgba(249,115,22,.12);border-color:rgba(249,115,22,.3);color:#fb923c;">
                    ⭐ Оставить отзыв
                </a>
                <?php endif; ?>
            </div>

            <?php if ($order['status'] === 'ready' && !empty($order['work_file'])): ?>
            <!-- П.1/3 ТЗ: сданная работа — скачивание + приём/правка прямо на сайте -->
            <?php $workFileUrl = imgSrc((string)$order['work_file'], 'uploads/orders/'); ?>
            <div style="display:flex;align-items:center;gap:10px;background:#0e0e15;border:1px solid #232330;border-radius:12px;padding:12px 14px;margin-top:10px;">
                <div style="flex:1;min-width:0;">
                    <div style="font-size:12px;color:#e0e0ec;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">📄 <?= htmlspecialchars($order['work_file_name'] ?: 'Готовая работа') ?></div>
                    <?php if (!empty($order['client_accepted_at'])): ?>
                    <div style="font-size:11px;color:#4ade80;margin-top:2px;">✅ Принято</div>
                    <?php elseif ((int)($order['revision_count'] ?? 0) > 0): ?>
                    <div style="font-size:11px;color:#8a8a96;margin-top:2px;">Правок: <?= (int)$order['revision_count'] ?></div>
                    <?php endif; ?>
                </div>
                <a href="<?= htmlspecialchars($workFileUrl) ?>" download="<?= htmlspecialchars($order['work_file_name'] ?: 'work') ?>" title="Скачать оригинал" style="flex-shrink:0;width:38px;height:38px;border-radius:10px;background:rgba(249,115,22,.12);border:1px solid rgba(249,115,22,.35);display:flex;align-items:center;justify-content:center;color:#fdba74;text-decoration:none;">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </a>
            </div>
            <?php if (empty($order['client_accepted_at'])): ?>
            <div style="display:flex;gap:8px;margin-top:8px;">
                <form method="POST" style="flex:1;">
                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                    <button type="submit" name="accept_work" style="width:100%;background:rgba(74,222,128,.14);border:1px solid rgba(74,222,128,.4);color:#86efac;border-radius:9px;padding:10px;font-size:12px;font-weight:800;cursor:pointer;font-family:inherit;">✅ Принять работу</button>
                </form>
                <button type="button" onclick="document.getElementById('revision-form-<?= $oid ?>').classList.toggle('hidden')" style="flex:1;background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.35);color:#fbbf24;border-radius:9px;padding:10px;font-size:12px;font-weight:800;cursor:pointer;font-family:inherit;">✏️ На правку</button>
            </div>
            <form method="POST" id="revision-form-<?= $oid ?>" class="hidden" style="margin-top:8px;display:grid;gap:8px;">
                <input type="hidden" name="order_id" value="<?= $oid ?>">
                <textarea name="revision_note" required minlength="5" rows="3" placeholder="Что нужно поправить?" style="width:100%;box-sizing:border-box;background:#0a0a10;border:1px solid #2a2a38;border-radius:9px;padding:10px 12px;color:#fff;font-size:12.5px;font-family:inherit;resize:vertical;"></textarea>
                <button type="submit" name="request_revision" style="background:linear-gradient(135deg,#fbbf24,#f59e0b);border:none;color:#1a1200;border-radius:9px;padding:10px;font-size:12px;font-weight:800;cursor:pointer;font-family:inherit;">📤 Отправить правку</button>
            </form>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Форма обращения — ВНУТРИ expanded, правильно -->
            <div class="appeal-form-wrap" id="appeal-form-<?= $oid ?>">
                <?php $hasOpenAppeal2 = !empty(array_filter($orderAppeals, fn($a) => in_array($a['status'], ['open','answered']))); ?>
                <?php if ($hasOpenAppeal2): ?>
                    <div style="background:rgba(249,115,22,.07);border:1px solid rgba(249,115,22,.25);border-radius:8px;padding:12px 14px;font-size:13px;color:#fdba74;">
                        ⚠️ Уже есть открытое обращение. Дождись ответа или создай новое после закрытия.
                    </div>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="appeal_order_id" value="<?= $oid ?>">
                    <label>Тема обращения</label>
                    <input type="text" name="appeal_subject" required placeholder="Например: уточнение по заказу" minlength="3" maxlength="200">
                    <label>Сообщение</label>
                    <textarea name="appeal_message" required rows="3" placeholder="Опиши вопрос или пожелание подробно..." minlength="10"></textarea>
                    <button type="submit" name="send_appeal" class="btn-appeal-submit">📤 Отправить</button>
                </form>
                <?php endif; ?>
            </div>

            <!-- Тред обращений -->
            <?php if (!empty($orderAppeals)): ?>
            <div class="appeals-thread">
                <div class="appeals-thread-title">💬 Переписка (<?= count($orderAppeals) ?>)</div>
                <?php foreach ($orderAppeals as $ap): ?>
                <div class="appeal-thread-item">
                    <div class="appeal-thread-subject">
                        <span class="appeal-status-dot" style="background:<?= $ap['status'] === 'open' ? '#f97316' : ($ap['status'] === 'closed' ? '#ef4444' : '#22c55e') ?>;"></span>
                        📩 <?= htmlspecialchars($ap['subject'] ?? '') ?><?php if ($ap['status'] === 'closed'): ?> <span style="font-size:10px;background:rgba(239,68,68,.15);color:#f87171;border-radius:4px;padding:1px 6px;margin-left:4px;">🔒 закрыто</span><?php endif; ?>
                        <span style="margin-left:auto;font-size:10px;color:#555568;font-weight:400;"><?= date('d.m.Y H:i', strtotime($ap['created_at'])) ?></span>
                    </div>
                    <?= renderAppealMessages($pdo, (int)$ap['id']) ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div><!-- /order-card-expanded -->
    </div><!-- /order-card -->
    <?php endforeach; ?>
    </div><!-- /history-list -->
</div>
<?php endif; ?>

<?php endif; ?>

</div>

<footer><div class="container">© <?= date('Y') ?> Kostlim Design</div></footer>

<style>
.tg-user-chip { display:inline-flex;align-items:center;gap:7px;padding:5px 12px 5px 5px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:30px;text-decoration:none;color:#86efac;font-size:12px;font-weight:700; }
.tg-user-ava { width:26px;height:26px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1.5px solid rgba(34,197,94,0.4); }
.tg-user-ava-fallback { width:26px;height:26px;border-radius:50%;background:rgba(34,197,94,0.2);border:1.5px solid rgba(34,197,94,0.4);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;color:#86efac;flex-shrink:0; }
.tg-user-name { overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:120px; }
</style>

<script>
function toggleOrder(id) {
    const hdr = document.getElementById('hdr-' + id);
    const exp = document.getElementById('exp-' + id);
    const isOpen = exp.classList.contains('open');
    exp.classList.toggle('open', !isOpen);
    hdr.setAttribute('aria-expanded', !isOpen ? 'true' : 'false');
}

// ── Лайтбокс для миниатюр референсов/чека ──
// Миниатюра — это обычная ссылка <a href="полное фото" target="_blank">.
// Если что-то в JS пойдёт не так — сработает обычное открытие фото в новой
// вкладке (это уже гарантированно работает всегда, браузер это умеет сам).
// Если JS отработал нормально — вместо этого открывается красивый лайтбокс
// поверх страницы, а переход по ссылке отменяется через preventDefault().
function openOrderLightbox(event, src) {
    try {
        let overlay = document.getElementById('order-lightbox-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'order-lightbox-overlay';
            overlay.innerHTML = '<button type="button" id="order-lightbox-close" onclick="closeOrderLightbox()">&times;</button><img id="order-lightbox-img" src="" alt="">';
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) closeOrderLightbox();
            });
            document.body.appendChild(overlay);
        }
        document.getElementById('order-lightbox-img').src = src;
        overlay.classList.add('open');
        if (event) event.preventDefault();
        return false;
    } catch (e) {
        // Лайтбокс не смог открыться — пусть отработает обычная ссылка
        return true;
    }
}
function closeOrderLightbox() {
    const overlay = document.getElementById('order-lightbox-overlay');
    if (overlay) overlay.classList.remove('open');
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeOrderLightbox();
});

// ── Редактирование ТЗ ──
function toggleOrderEdit(id) {
    const form = document.getElementById('edit-form-' + id);
    const view = document.getElementById('details-view-' + id);
    if (!form) return;
    form.classList.toggle('open');
    if (view) view.style.display = form.classList.contains('open') ? 'none' : '';
}

function toggleAppeal(id) {
    const exp  = document.getElementById('exp-' + id);
    const form = document.getElementById('appeal-form-' + id);
    if (!exp.classList.contains('open')) {
        exp.classList.add('open');
        const hdr = document.getElementById('hdr-' + id);
        if (hdr) hdr.setAttribute('aria-expanded', 'true');
    }
    form.classList.toggle('open');
    if (form.classList.contains('open')) {
        setTimeout(() => form.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 50);
    }
}

function toggleHistory() {
    const list = document.getElementById('history-list');
    const btn  = document.getElementById('btn-toggle-history');
    if (!list || !btn) return;
    const hidden = list.style.display === 'none';
    list.style.display = hidden ? '' : 'none';
    btn.textContent = hidden ? 'Скрыть историю' : 'Показать историю';
}

(function() {
    const params = new URLSearchParams(location.search);
    const oid = params.get('order');
    if (oid) {
        const exp  = document.getElementById('exp-' + oid);
        const hdr  = document.getElementById('hdr-' + oid);
        if (exp) exp.classList.add('open');
        if (hdr) hdr.setAttribute('aria-expanded', 'true');
        const card = document.getElementById('order-' + oid);
        if (card) setTimeout(() => card.scrollIntoView({ behavior: 'smooth', block: 'center' }), 150);
        const appeal = params.get('appeal');
        if (appeal === 'err') {
            const form = document.getElementById('appeal-form-' + oid);
            if (form) form.classList.add('open');
        }
    }
})();
</script>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script>
(function(){
    try {
        var tg = window.Telegram && window.Telegram.WebApp;
        if (!tg || !tg.initData) return;
        tg.ready();
        fetch('/tg_webapp_auth.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'text/plain' },
            body: tg.initData
        }).catch(function(){});
    } catch (e) {}
})();
</script>
<?php include __DIR__ . '/includes/ai_widget.php'; ?>
</body>
</html>