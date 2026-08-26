<?php

/**
 * Промокоды: таблица + колонка на заказе, куда сохраняется применённый код.
 */
function ensurePromoSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS promo_codes (
            id SERIAL PRIMARY KEY,
            code VARCHAR(50) NOT NULL,
            discount_percent SMALLINT DEFAULT NULL,
            bonus_text VARCHAR(255) NOT NULL DEFAULT '',
            active BOOLEAN NOT NULL DEFAULT TRUE,
            uses_count INT NOT NULL DEFAULT 0,
            max_uses INT DEFAULT NULL,
            expires_at TIMESTAMP DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            CONSTRAINT uniq_promo_code UNIQUE (code)
        )");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS promo_code VARCHAR(50) DEFAULT NULL");
        foreach (['max_uses INT', 'expires_at TIMESTAMP'] as $col) {
            try { $pdo->exec("ALTER TABLE promo_codes ADD COLUMN IF NOT EXISTS {$col} DEFAULT NULL"); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        error_log('ensurePromoSchema error: ' . $e->getMessage());
    }
}

/**
 * Проверяет промокод для конкретного человека (по client_chat_id и/или
 * session_id) и возвращает подробный результат — используется и на сайте
 * при вводе кода (AJAX), и при реальном сохранении заказа.
 *
 * Промокод одноразовый НА ЧЕЛОВЕКА: если у этого же человека уже есть
 * заказ с этим кодом, который дошёл хотя бы до "в работе" (in_progress/
 * urgent/ready) — считается использованным. Заказы, которые ещё pending
 * (не решено) или declined (не пошёл) — не блокируют повторный ввод,
 * потому что первое применение могло не состояться по факту.
 */
function checkPromoCode(PDO $pdo, string $codeInput, ?int $clientChatId, string $sessionId): array
{
    $codeInput = trim($codeInput);
    if ($codeInput === '') return ['valid' => false, 'reason' => 'empty'];

    $stmt = $pdo->prepare("SELECT * FROM promo_codes WHERE UPPER(code) = UPPER(?) LIMIT 1");
    $stmt->execute([$codeInput]);
    $promo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$promo || !$promo['active']) return ['valid' => false, 'reason' => 'not_found'];

    if (!empty($promo['expires_at']) && strtotime($promo['expires_at']) < time()) {
        return ['valid' => false, 'reason' => 'expired'];
    }
    if (!empty($promo['max_uses']) && (int)$promo['uses_count'] >= (int)$promo['max_uses']) {
        return ['valid' => false, 'reason' => 'maxed_out'];
    }

    try {
        $usedStmt = $pdo->prepare("
            SELECT id FROM orders
            WHERE UPPER(promo_code) = UPPER(?)
              AND status IN ('in_progress','urgent','ready')
              AND ((client_chat_id IS NOT NULL AND client_chat_id = ?) OR (session_id IS NOT NULL AND session_id = ?))
            LIMIT 1
        ");
        $usedStmt->execute([$promo['code'], $clientChatId ?: 0, $sessionId]);
        if ($usedStmt->fetch()) {
            return ['valid' => false, 'reason' => 'already_used'];
        }
    } catch (Throwable $e) {}

    return ['valid' => true, 'promo' => $promo];
}

/**
 * service_keys_extra — JSON-массив ключей всех выбранных услуг (мультивыбор
 * в форме заказа). Пустая/битая строка → пустой массив (тогда используется
 * одна service_key, как раньше — обратная совместимость со старыми заказами).
 */
function decodeServiceKeysList(?string $raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    return array_values(array_unique(array_filter(array_map('strval', $decoded), fn($v) => $v !== '')));
}

function encodeServiceKeysList(array $keys): string
{
    $keys = array_values(array_unique(array_filter(array_map('strval', $keys), fn($v) => $v !== '')));
    return json_encode($keys, JSON_UNESCAPED_SLASHES);
}

/**
 * Собирает список выбранных услуг заказа (title, price_rub, price_uan) —
 * либо все из service_keys_extra (мультивыбор), либо одна по service_key
 * (старые заказы / заказ с одной услугой).
 */
function getOrderServicesList(PDO $pdo, array $order): array
{
    $extraKeys = decodeServiceKeysList((string)($order['service_keys_extra'] ?? ''));
    $allKeys   = !empty($extraKeys) ? $extraKeys : [(string)($order['service_key'] ?? '')];
    $allKeys   = array_values(array_unique(array_filter($allKeys, fn($k) => $k !== '')));
    if (empty($allKeys)) return [];
    try {
        $placeholders = implode(',', array_fill(0, count($allKeys), '?'));
        $stmt = $pdo->prepare("SELECT title, category_key, price_rub, price_uan FROM prices WHERE category_key IN ($placeholders)");
        $stmt->execute($allKeys);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Сохраняем порядок выбора пользователя, а не порядок в БД.
        usort($rows, function($a, $b) use ($allKeys) {
            return array_search($a['category_key'], $allKeys) <=> array_search($b['category_key'], $allKeys);
        });
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Комбинированное название услуги(-уг) заказа — "Превью для видео + Баннер
 * для канала" при мультивыборе, иначе просто название одной услуги.
 */
function getOrderServiceTitle(PDO $pdo, array $order): string
{
    $list = getOrderServicesList($pdo, $order);
    if (empty($list)) return (string)($order['service_key'] ?? '');
    return implode(' + ', array_map(fn($r) => (string)$r['title'], $list));
}

/**
 * Считает итоговую цену заказа: сначала накидывает срочность (+50%), и
 * ТОЛЬКО ПОТОМ вычитает скидку промокода — порядок специально такой
 * (см. пожелание админа), иначе скидка "съедает" часть наценки за срочность.
 */
function computeOrderPriceWithPromo(PDO $pdo, array $order): array
{
    $baseRub = 0; $baseUan = 0; $discountPct = 0; $bonusText = ''; $promoCode = '';
    try {
        // Заказ может включать НЕСКОЛЬКО услуг (мультивыбор в форме) —
        // суммируем цены всех выбранных, а не только первой.
        foreach (getOrderServicesList($pdo, $order) as $row) {
            $baseRub += (float)($row['price_rub'] ?? 0);
            $baseUan += (float)($row['price_uan'] ?? 0);
        }
    } catch (Throwable $e) {}

    if (in_array($order['status'] ?? '', ['urgent'], true) || ($order['requested_urgency'] ?? '') === 'urgent') {
        $baseRub *= 1.5;
        $baseUan *= 1.5;
    }

    if (!empty($order['promo_code'])) {
        try {
            $pp = $pdo->prepare("SELECT code, discount_percent, bonus_text FROM promo_codes WHERE UPPER(code) = UPPER(?) LIMIT 1");
            $pp->execute([$order['promo_code']]);
            $pr = $pp->fetch(PDO::FETCH_ASSOC);
            if ($pr) {
                $promoCode   = $pr['code'];
                $discountPct = (int)($pr['discount_percent'] ?? 0);
                $bonusText   = (string)($pr['bonus_text'] ?? '');
            }
        } catch (Throwable $e) {}
    }

    $finalRub = $discountPct > 0 ? round($baseRub * (1 - $discountPct / 100), 2) : $baseRub;
    $finalUan = $discountPct > 0 ? round($baseUan * (1 - $discountPct / 100), 2) : $baseUan;

    return [
        'base_rub' => round($baseRub, 2), 'base_uan' => round($baseUan, 2),
        'final_rub' => $finalRub, 'final_uan' => $finalUan,
        'discount_percent' => $discountPct, 'promo_code' => $promoCode, 'bonus_text' => $bonusText,
    ];
}

function ensureOrderFlowSchema(PDO $pdo): void
{
    try {
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_status VARCHAR(32) NOT NULL DEFAULT 'not_requested'");
        // Мультивыбор услуг в форме заказа: service_key по-прежнему хранит
        // ПЕРВУЮ выбранную услугу (для обратной совместимости со всеми
        // существующими "LEFT JOIN prices ON category_key = service_key" по
        // всему проекту — они продолжают работать как раньше и без этой
        // колонки). Полный список выбранных услуг — здесь, JSON-массив.
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS service_keys_extra TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_receipt TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_received_at TIMESTAMP DEFAULT NULL");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS accepted_at TIMESTAMP DEFAULT NULL");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS started_at TIMESTAMP DEFAULT NULL");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS declined_reason TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS is_urgent BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_receipt_count INT NOT NULL DEFAULT 0");
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_messages (
            id SERIAL PRIMARY KEY,
            order_id INT NOT NULL,
            author VARCHAR(32) NOT NULL DEFAULT 'system',
            message TEXT NOT NULL DEFAULT '',
            attachment TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_order_messages_order ON order_messages(order_id, created_at)");

        // Настройки сайта (ключ-значение) — сейчас используется для режима
        // "приём заказов вкл/выкл" (режим отпуска), но можно переиспользовать
        // и под другие простые флаги в будущем.
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(64) PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ''
        )");
        // ВАЖНО: "CREATE TABLE IF NOT EXISTS" ничего не делает, если таблица
        // уже существовала без колонки value — именно это вызывало ошибку
        // "column value does not exist" в логах. Явно догоняем колонку.
        try { $pdo->exec("ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS value TEXT DEFAULT ''"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS setting_key VARCHAR(64)"); } catch (Throwable $e) {}

        // Дедупликация вебхуков Telegram: если наш ответ не успевает уйти
        // вовремя (например, пока идёт фоновая заливка чека на ImgBB),
        // Telegram повторно доставляет ТОТ ЖЕ update_id — без этой таблицы
        // такой повтор обрабатывался заново целиком (задваивался чек,
        // задваивались уведомления и т.д.).
        $pdo->exec("CREATE TABLE IF NOT EXISTS processed_updates (
            update_id BIGINT PRIMARY KEY,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        // Сдача готовой работы дизайнером + цикл правок:
        // work_file/work_file_name — последний сданный файл (имя в
        // uploads/orders/ + оригинальное имя для красивого скачивания);
        // work_message_id — id сообщения с файлом в чате клиента (нужен,
        // чтобы удалить его при запросе правки — п.2 ТЗ);
        // client_accepted_at — когда клиент нажал "Принять работу";
        // revision_count — счётчик пересдач (для истории/статистики).
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS work_file TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS work_file_name TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS work_message_id BIGINT DEFAULT NULL");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS client_accepted_at TIMESTAMP DEFAULT NULL");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS revision_count INT NOT NULL DEFAULT 0");
    } catch (Throwable $e) {
        error_log('ensureOrderFlowSchema error: ' . $e->getMessage());
    }
}

/**
 * true, если этот update_id от Telegram уже обрабатывался (повторная
 * доставка вебхука) — вызывающий код должен просто завершиться, ничего
 * не переделывая. Возвращает false и при этом сама помечает update_id
 * как обработанный, если это первая встреча.
 */
function isDuplicateTelegramUpdate(PDO $pdo, int $updateId): bool
{
    if ($updateId <= 0) return false;
    try {
        $stmt = $pdo->prepare("INSERT INTO processed_updates (update_id) VALUES (?) ON CONFLICT (update_id) DO NOTHING");
        $stmt->execute([$updateId]);
        if ($stmt->rowCount() === 0) return true; // уже был — это повтор

        // Лёгкая уборка старых записей, не на каждый запрос.
        if (random_int(1, 200) === 1) {
            $pdo->exec("DELETE FROM processed_updates WHERE created_at < NOW() - INTERVAL '3 days'");
        }
        return false;
    } catch (Throwable $e) {
        error_log('isDuplicateTelegramUpdate error: ' . $e->getMessage());
        return false; // при сбое лучше обработать, чем молча потерять update
    }
}

/**
 * Режим "приём заказов" — вкл/выкл (аналог "режима отпуска" из ТЗ).
 * Когда выключено, форма заказа на сайте вежливо отказывает вместо приёма.
 */
function isOrdersAvailable(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SELECT value FROM site_settings WHERE setting_key = 'orders_available' LIMIT 1");
        $val = $stmt ? $stmt->fetchColumn() : false;
        if ($val === false) return true; // по умолчанию приём включён
        return $val !== '0';
    } catch (Throwable $e) {
        return true;
    }
}

function setOrdersAvailable(PDO $pdo, bool $available, string $returnDate = ''): void
{
    try {
        $pdo->exec("INSERT INTO site_settings (setting_key, value) VALUES ('orders_available', " . ($available ? "'1'" : "'0'") . ")
                    ON CONFLICT (setting_key) DO UPDATE SET value = EXCLUDED.value");
        if ($returnDate !== '') {
            $esc = str_replace("'", "''", $returnDate);
            $pdo->exec("INSERT INTO site_settings (setting_key, value) VALUES ('orders_return_date', '{$esc}')
                        ON CONFLICT (setting_key) DO UPDATE SET value = EXCLUDED.value");
        }
    } catch (Throwable $e) {
        error_log('setOrdersAvailable error: ' . $e->getMessage());
    }
}

function getOrdersReturnDate(PDO $pdo): string
{
    try {
        $stmt = $pdo->query("SELECT value FROM site_settings WHERE setting_key = 'orders_return_date' LIMIT 1");
        return (string)($stmt ? $stmt->fetchColumn() : '');
    } catch (Throwable $e) {
        return '';
    }
}

function paymentSupportLine(): string
{
    return "По вопросам оплаты пишите - @Perlo_ovka";
}

/**
 * Единая функция расчёта дедлайна заказа.
 * Обычный заказ — ровно 5 суток (120 часов), срочный — ровно 24 часа.
 * Отсчёт всегда идёт от момента вызова (получения оплаты / прикрепления чека).
 */
function calculateOrderDeadline(bool $isUrgent): string
{
    $hours = $isUrgent ? 24 : 120; // 24ч срочный / 120ч (5 суток) обычный
    return date('Y-m-d H:i:s', time() + $hours * 3600);
}

/**
 * payment_receipt хранится как JSON-массив (аналогично example_photo) —
 * на один заказ может быть до 3 чеков (см. payment_receipt_count), и
 * раньше колонка была одиночной строкой: при 2-м/3-м чеке она либо вообще
 * не трогалась (терялись 2-й/3-й чек), либо перезатиралась (терялся 1-й).
 * Теперь это всегда JSON-массив всех присланных чеков. Старые заказы, где
 * там просто голая строка (ссылка/имя файла/file_id) — тоже поддержаны:
 * decodeReceiptList() трактует не-JSON/не-массив как один элемент.
 */
function decodeReceiptList(?string $raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return array_values(array_filter(array_map('strval', $decoded), fn($v) => $v !== ''));
    }
    return [$raw];
}

function encodeReceiptList(array $list): string
{
    $list = array_values(array_filter(array_map('strval', $list), fn($v) => $v !== ''));
    return json_encode($list, JSON_UNESCAPED_SLASHES);
}

/**
 * Скачивает файл из Telegram (по file_id) и сохраняет его локально.
 * Возвращает true при успехе.
 */
function downloadTelegramFileToLocal(string $token, string $fileId, string $destPath): bool
{
    try {
        $ch = curl_init("https://api.telegram.org/bot{$token}/getFile");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => ['file_id' => $fileId],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string)$resp, true);
        $filePath = $data['result']['file_path'] ?? '';
        if ($filePath === '') return false;

        // Явный таймаут на скачивание самого файла — раньше file_get_contents()
        // тут мог зависнуть без ограничения (это и была основная причина
        // "бесконечной загрузки" при приёме чека), т.к. эта функция сейчас
        // вызывается уже ПОСЛЕ того, как клиент/админ получили подтверждение,
        // но зависший запрос всё равно держит воркер и может упереться в
        // max_execution_time — 10с более чем достаточно для фото из Telegram.
        $ctx = stream_context_create(['http' => ['timeout' => 10]]);
        $fileUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";
        $bytes = @file_get_contents($fileUrl, false, $ctx);
        if ($bytes === false || strlen($bytes) < 50) return false;

        $dir = dirname($destPath);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return (bool)@file_put_contents($destPath, $bytes);
    } catch (Throwable $e) {
        error_log('downloadTelegramFileToLocal error: ' . $e->getMessage());
        return false;
    }
}

function paymentInstructionsText(int $orderId, array $priceInfo = [], bool $isCooperation = false, bool $isUrgent = false, int $discountPercent = 0, string $promoCode = ''): string
{
    $baseRub = (int)($priceInfo['price_rub'] ?? 0);
    $baseUan = (int)($priceInfo['price_uan'] ?? 0);
    if ($isCooperation) {
        $baseRub = 0;
        $baseUan = 0;
    }

    // Срочный заказ (24ч вместо 5 суток) — наценка +50% к стоимости
    $rub = $isUrgent ? (int)round($baseRub * 1.5) : $baseRub;
    $uan = $isUrgent ? (int)round($baseUan * 1.5) : $baseUan;

    // Порядок важен: сначала наценка за срочность, и ТОЛЬКО ПОТОМ скидка по
    // промокоду — иначе скидка "съедала" бы часть наценки за срочность.
    $rubBeforeDiscount = $rub;
    $uanBeforeDiscount = $uan;
    if ($discountPercent > 0 && !$isCooperation) {
        $rub = (int)round($rub * (1 - $discountPercent / 100));
        $uan = (int)round($uan * (1 - $discountPercent / 100));
    }

    $rubDetailsRaw = trim((string)(getenv('PAYMENT_REQUISITES_RUB') ?: 'https://www.donationalerts.com/r/andrewkostdzn'));
    $uanDetails    = htmlspecialchars(trim((string)(getenv('PAYMENT_REQUISITES_UAH') ?: '4874070010369708 (Monobank)')), ENT_QUOTES);
    $cryptoDetails = htmlspecialchars(trim((string)(getenv('PAYMENT_REQUISITES_CRYPTO') ?: 'THMpgSQAPwEB9brstbD12EKPPTwnGoPxC2')), ENT_QUOTES);
    $monoDetails   = htmlspecialchars(trim((string)(getenv('PAYMENT_REQUISITES_MONO') ?: '4874070010369708')), ENT_QUOTES);

    // Рублёвый реквизит обычно ссылка (DonationAlerts) — показываем её как
    // кликабельное название сервиса, а не голым URL, как просили.
    if (preg_match('~^https?://~i', $rubDetailsRaw)) {
        $rubLine = '<a href="' . htmlspecialchars($rubDetailsRaw, ENT_QUOTES) . '">DonationAlerts</a>';
    } else {
        $rubLine = '<code>' . htmlspecialchars($rubDetailsRaw, ENT_QUOTES) . '</code>';
    }

    // ВАЖНО: раньше это форматировалось звёздочками (*bold*) под parse_mode
    // "Markdown" (старый режим v1). Это ЛОМАЛО ВСЮ отправку любого сообщения,
    // где встречается "@Perlo_ovka" (или вообще любой текст с одиночным "_") —
    // Markdown v1 считает "_" началом курсива, и без парной закрывающей "_"
    // Telegram отклоняет ВЕСЬ запрос целиком с ошибкой "can't parse entities",
    // то есть не уходило вообще ничего — ни текст, ни фото. Поэтому здесь
    // используется HTML-разметка (<b>/<i>/<code>) и parse_mode="HTML" —
    // визуально даёт тот же результат (жирный/курсив/моноширинный), но не
    // страдает от одиночных подчёркиваний в username.
    $header = $isUrgent
        ? "⚡ <b>Заказ #{$orderId} принят как СРОЧНЫЙ.</b>\nОжидается оплата.\n\n"
        : "✅ <b>Заказ #{$orderId} принят.</b>\nОжидается оплата.\n\n";

    $priceBlock = "💰 <b>Итого:</b>\n<b>{$rub} ₽ / {$uan} ₴</b>\n\n";

    $footNotes = [];
    if ($isUrgent && !$isCooperation) {
        $footNotes[] = "<i>⚡ Наценка за срочность (+50%, готово за 24ч вместо 5 суток): +" . ($rubBeforeDiscount - $baseRub) . " ₽ / +" . ($uanBeforeDiscount - $baseUan) . " ₴</i>";
    }
    if ($discountPercent > 0 && !$isCooperation) {
        $footNotes[] = "<i>Промокод \"" . htmlspecialchars($promoCode, ENT_QUOTES) . "\" (-{$discountPercent}%): -" . ($rubBeforeDiscount - $rub) . " ₽ / -" . ($uanBeforeDiscount - $uan) . " ₴</i>";
        $footNotes[] = "<i>Стоимость без скидки: {$rubBeforeDiscount} ₽ / {$uanBeforeDiscount} ₴</i>";
    } elseif ($isUrgent && !$isCooperation) {
        $footNotes[] = "<i>Базовая стоимость (без срочности): {$baseRub} ₽ / {$baseUan} ₴</i>";
    }

    $text = $header
        . $priceBlock
        . "Реквизиты:\n"
        . "📍 <b>Рубли:</b> {$rubLine}\n"
        . "📍 <b>Гривны:</b> <code>{$uanDetails}</code>\n"
        . "📍 <b>Крипта:</b> <code>{$cryptoDetails}</code>\n";

    if (!empty($footNotes)) {
        $text .= "\n" . implode("\n", $footNotes) . "\n";
    }

    $text .= "\n❓ @Perlo_ovka";

    return $text;
}

/**
 * Клавиатура под сообщением с реквизитами: 2 кнопки.
 * 1) "Оплатить на сайте" — url-кнопка, ведёт сразу в кабинет клиента
 *    на страницу заказа (профиль), с tg_token для автопривязки TG,
 *    чтобы клиент попал прямо на форму загрузки чека.
 * 2) "Оплатить в Telegram" — callback-кнопка. Бот подсказывает
 *    отправить фото чека сюда же в чат — дальше чек прикрепляется
 *    к заказу автоматически (существующая логика приёма фото).
 */
function paymentKeyboard(int $orderId, string $payUrl): array
{
    return [
        'inline_keyboard' => [
            [['text' => '💻 Оплатить на сайте', 'url' => $payUrl]],
            [['text' => '📸 Скинуть чек в ТГ', 'callback_data' => "cli_pay_tg_{$orderId}"]],
        ],
    ];
}

function addOrderMessage(PDO $pdo, int $orderId, string $author, string $message, string $attachment = ''): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO order_messages (order_id, author, message, attachment, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$orderId, $author, $message, $attachment]);
    } catch (Throwable $e) {
        error_log('addOrderMessage error: ' . $e->getMessage());
    }
}

/**
 * Заливает файл на ImgBB и возвращает публичную постоянную ссылку.
 * Раньше чек оплаты с сайта сохранялся только на локальный диск сервера —
 * там, где диск эфемерный (Render/аналоги), файл мог пропасть после
 * рестарта, а Telegram не мог его подтянуть по ссылке на sendPhoto
 * ("не приходит сообщение в ТГ"). ImgBB даёт постоянную ссылку сразу.
 * Возвращает '' если не получилось — тогда вызывающий код должен
 * откатиться на локальное сохранение как раньше.
 */
function uploadReceiptToImgBB(string $tmpPath, string $name = 'receipt'): string
{
    if (!is_file($tmpPath)) return '';
    $keys = array_filter([
        getenv('IMGBB_API_KEY')  ?: '',
        getenv('IMGBB_API_KEY2') ?: '',
        getenv('IMGBB_API_KEY3') ?: '',
    ]);
    if (empty($keys)) return '';

    $b64 = base64_encode((string)file_get_contents($tmpPath));
    foreach ($keys as $apiKey) {
        try {
            $ch = curl_init('https://api.imgbb.com/1/upload');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_POSTFIELDS     => ['key' => $apiKey, 'image' => $b64, 'name' => $name],
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            if ($res === false || $res === '') continue;
            $data = json_decode($res, true);
            $url  = $data['data']['url'] ?? '';
            if ($url !== '') return $url;
        } catch (Throwable $e) {
            error_log('uploadReceiptToImgBB error: ' . $e->getMessage());
        }
    }
    return '';
}

/**
 * Отправляет клиенту готовую работу файлом БЕЗ пережатия (sendDocument, не
 * sendPhoto — Telegram пережимает фото при sendPhoto, а тут как раз нужен
 * оригинал в максимальном качестве). Под файлом — кнопки "Принять работу"
 * / "Отправить на правку". Сохраняет message_id отправленного сообщения —
 * он понадобится, чтобы удалить его при запросе правки (см. ниже).
 * Возвращает true при успехе.
 */
function deliverWorkFileToClient(PDO $pdo, string $token, int $orderId, string $localFilePath, string $storedFileName, string $origFileName): bool
{
    $stmt = $pdo->prepare("SELECT client_chat_id FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$orderId]);
    $chatId = trim((string)$stmt->fetchColumn());
    if ($chatId === '' || !is_numeric($chatId) || !is_file($localFilePath)) return false;

    $caption = "🎉 <b>Заказ #{$orderId} готов!</b>\n\nПроверь файл и подтверди приём или отправь на правку 👇";
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendDocument");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => [
            'chat_id'      => $chatId,
            'document'     => new CURLFile($localFilePath, mime_content_type($localFilePath) ?: 'application/octet-stream', $origFileName),
            'caption'      => $caption,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [[
                    ['text' => '✅ Принять работу', 'callback_data' => "work_accept_{$orderId}"],
                    ['text' => '✏️ Отправить на правку', 'callback_data' => "work_revision_{$orderId}"],
                ]],
            ], JSON_UNESCAPED_UNICODE),
        ],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    if (empty($data['ok'])) {
        error_log("[deliverWorkFileToClient] sendDocument failed for order #{$orderId}: " . ($err ?: substr((string)$resp, 0, 300)));
        return false;
    }
    $messageId = (int)($data['result']['message_id'] ?? 0);
    $pdo->prepare("UPDATE orders SET work_file = ?, work_file_name = ?, work_message_id = ?, status = 'ready', client_accepted_at = NULL WHERE id = ?")
        ->execute([$storedFileName, $origFileName, $messageId ?: null, $orderId]);
    return true;
}

/**
 * Запрос клиента на правку — общая точка для обоих путей (кнопка в
 * Telegram ИЛИ кнопка на сайте в профиле, см. п.2 ТЗ): удаляет из чата
 * клиента предыдущее сообщение с файлом (если оно ещё не удалено — при
 * заходе через Telegram-кнопку это уже могло произойти раньше), переводит
 * заказ в статус "на правке", логирует текст правки в переписку заказа и
 * уведомляет админа.
 */
function requestOrderRevision(PDO $pdo, string $token, int $orderId, string $note, string $source = 'site'): void
{
    $stmt = $pdo->prepare("SELECT client_chat_id, work_message_id, revision_count FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;

    $chatId = trim((string)($row['client_chat_id'] ?? ''));
    $msgId  = (int)($row['work_message_id'] ?? 0);

    // Раньше сообщение с файлом просто оставалось в чате навсегда — теперь
    // удаляем его, чтобы не путал финальные и нефинальные версии (п.2 ТЗ).
    if ($chatId !== '' && is_numeric($chatId) && $msgId > 0) {
        $ch = curl_init("https://api.telegram.org/bot{$token}/deleteMessage");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => ['chat_id' => $chatId, 'message_id' => $msgId]]);
        curl_exec($ch); curl_close($ch);
    }

    $pdo->prepare("UPDATE orders SET status = 'revision', work_message_id = NULL WHERE id = ?")->execute([$orderId]);
    addOrderMessage($pdo, $orderId, 'client', 'Запрошена правка: ' . $note);

    if ($chatId !== '' && is_numeric($chatId)) {
        $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => ['chat_id' => $chatId, 'parse_mode' => 'HTML',
                'text' => "🔧 Правки по заказу #{$orderId} приняты в работу — дизайнер уже смотрит."]]);
        curl_exec($ch); curl_close($ch);
    }

    $adminId = getenv('ADMIN_ID') ?: '';
    if ($adminId !== '') {
        $srcLabel = $source === 'telegram' ? '' : ' (через сайт)';
        $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => ['chat_id' => $adminId, 'parse_mode' => 'HTML',
                'text' => "✏️ <b>Правка по заказу #{$orderId}</b>{$srcLabel}\n\n" . htmlspecialchars($note)]]);
        curl_exec($ch); curl_close($ch);
    }
}