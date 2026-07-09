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
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            CONSTRAINT uniq_promo_code UNIQUE (code)
        )");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS promo_code VARCHAR(50) DEFAULT NULL");
    } catch (Throwable $e) {
        error_log('ensurePromoSchema error: ' . $e->getMessage());
    }
}

function ensureOrderFlowSchema(PDO $pdo): void
{
    try {
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_status VARCHAR(32) NOT NULL DEFAULT 'not_requested'");
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
    } catch (Throwable $e) {
        error_log('ensureOrderFlowSchema error: ' . $e->getMessage());
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
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POSTFIELDS     => ['file_id' => $fileId],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string)$resp, true);
        $filePath = $data['result']['file_path'] ?? '';
        if ($filePath === '') return false;

        $fileUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";
        $bytes = @file_get_contents($fileUrl);
        if ($bytes === false || strlen($bytes) < 50) return false;

        $dir = dirname($destPath);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return (bool)@file_put_contents($destPath, $bytes);
    } catch (Throwable $e) {
        error_log('downloadTelegramFileToLocal error: ' . $e->getMessage());
        return false;
    }
}

function paymentInstructionsText(int $orderId, array $priceInfo = [], bool $isCooperation = false, bool $isUrgent = false): string
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

    $rubDetails    = htmlspecialchars(trim((string)(getenv('PAYMENT_REQUISITES_RUB') ?: 'https://www.donationalerts.com/r/andrewkostdzn')), ENT_QUOTES);
    $uanDetails    = htmlspecialchars(trim((string)(getenv('PAYMENT_REQUISITES_UAH') ?: '4874070010369708 (Monobank)')), ENT_QUOTES);
    $cryptoDetails = htmlspecialchars(trim((string)(getenv('PAYMENT_REQUISITES_CRYPTO') ?: 'THMpgSQAPwEB9brstbD12EKPPTwnGoPxC2')), ENT_QUOTES);
    $monoDetails   = htmlspecialchars(trim((string)(getenv('PAYMENT_REQUISITES_MONO') ?: '4874070010369708')), ENT_QUOTES);

    // ВАЖНО: раньше это форматировалось звёздочками (*bold*) под parse_mode
    // "Markdown" (старый режим v1). Это ЛОМАЛО ВСЮ отправку любого сообщения,
    // где встречается "@Perlo_ovka" (или вообще любой текст с одиночным "_") —
    // Markdown v1 считает "_" началом курсива, и без парной закрывающей "_"
    // Telegram отклоняет ВЕСЬ запрос целиком с ошибкой "can't parse entities",
    // то есть не уходило вообще ничего — ни текст, ни фото. Поэтому теперь
    // используется HTML-разметка (<b>...</b>) и parse_mode="HTML" — она не
    // страдает от одиночных подчёркиваний.
    $header = $isUrgent
        ? "⚡ <b>Заказ #{$orderId} принят как СРОЧНЫЙ.</b> Ожидается оплата.\n\n"
        : "✅ <b>Заказ #{$orderId} принят.</b> Ожидается оплата.\n\n";

    $priceBlock = "💰💰💰 <b>К ОПЛАТЕ: {$rub} ₽ / {$uan} ₴</b> 💰💰💰\n\n";
    if ($isUrgent && !$isCooperation) {
        $priceBlock = "Базовая стоимость: {$baseRub} ₽ / {$baseUan} ₴\n"
            . "⚡ Наценка за срочность (+50%, готово за 24ч вместо 5 суток): +" . ($rub - $baseRub) . " ₽ / +" . ($uan - $baseUan) . " ₴\n\n"
            . "💰💰💰 <b>ИТОГО К ОПЛАТЕ: {$rub} ₽ / {$uan} ₴</b> 💰💰💰\n\n";
    }

    return $header
        . $priceBlock
        . "🔗 Реквизиты:\n"
        . "-Рубли: {$rubDetails}\n"
        . "-Гривны: {$uanDetails}\n"
        . "-Крипта: {$cryptoDetails}\n\n"
        . "❓ " . paymentSupportLine();
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
                CURLOPT_TIMEOUT        => 30,
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