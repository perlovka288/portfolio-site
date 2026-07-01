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

function paymentInstructionsText(int $orderId, array $priceInfo = [], bool $isCooperation = false): string
{
    $rub = (int)($priceInfo['price_rub'] ?? 0);
    $uan = (int)($priceInfo['price_uan'] ?? 0);
    if ($isCooperation) {
        $rub = 0;
        $uan = 0;
    }

    $details = trim((string)(getenv('PAYMENT_REQUISITES') ?: 'Рубли: https://www.donationalerts.com/r/andrewkostdzn' . "\n" . 'Гривны: реквизиты карты уточните у дизайнера'));

    return "Заказ #{$orderId} принят. Ожидается оплата.\n\n"
        . "Сумма: {$rub} ₽ / {$uan} ₴\n"
        . "Обязательно укажите заказ #{$orderId} в комментарии к оплате.\n\n"
        . "Реквизиты:\n{$details}\n\n"
        . paymentSupportLine();
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

