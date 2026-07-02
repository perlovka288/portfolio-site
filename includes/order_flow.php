<?php

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
    } catch (Throwable $e) {
        error_log('ensureOrderFlowSchema error: ' . $e->getMessage());
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

function paymentInstructionsText(int $orderId, array $priceInfo = [], bool $isCooperation = false): string
{
    $rub = (int)($priceInfo['price_rub'] ?? 0);
    $uan = (int)($priceInfo['price_uan'] ?? 0);
    if ($isCooperation) {
        $rub = 0;
        $uan = 0;
    }

    $rubDetails    = trim((string)(getenv('PAYMENT_REQUISITES_RUB') ?: 'https://www.donationalerts.com/r/andrewkostdzn'));
    $uanDetails    = trim((string)(getenv('PAYMENT_REQUISITES_UAH') ?: 'реквизиты карты уточните у дизайнера'));
    $cryptoDetails = trim((string)(getenv('PAYMENT_REQUISITES_CRYPTO') ?: 'THMpgSQAPwEB9brstbD12EKPPTwnGoPxC2'));
    $monoDetails   = trim((string)(getenv('PAYMENT_REQUISITES_MONO') ?: '4874070010369708'));

    return "✅ Заказ #{$orderId} принят. Ожидается оплата.\n\n"
        . "Сумма: {$rub} ₽ / {$uan} ₴\n"
        . "❗ Обязательно укажите заказ #{$orderId} в комментарии к оплате.\n\n"
        . "🔗 Реквизиты:\n"
        . "-Рубли: {$rubDetails}\n"
        . "-Гривны: {$uanDetails}\n"
        . "-Монобанк: {$monoDetails}\n"
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