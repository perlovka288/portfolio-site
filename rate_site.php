<?php
/**
 * Приём оценки удобства сайта (1-5 звёзд), которую клиент оставляет сразу
 * после отправки заказа. Оценка уходит админу в Telegram.
 */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_ratings (
        id SERIAL PRIMARY KEY,
        order_id INT DEFAULT NULL,
        rating SMALLINT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
} catch (Throwable $e) {}

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$rating  = (int)($input['rating'] ?? 0);
$orderId = (int)($input['order_id'] ?? 0);

if ($rating < 1 || $rating > 5) {
    echo json_encode(['ok' => false, 'error' => 'invalid_rating']);
    exit;
}

try {
    $pdo->prepare("INSERT INTO site_ratings (order_id, rating) VALUES (?, ?)")->execute([$orderId ?: null, $rating]);

    $token    = getenv('BOT_TOKEN') ?: getenv('TELEGRAM_BOT_TOKEN') ?: '';
    $adminId  = getenv('ADMIN_TELEGRAM_ID') ?: getenv('ADMIN_ID') ?: '';
    if ($token !== '' && $adminId !== '') {
        $stars = str_repeat('⭐', $rating) . str_repeat('☆', 5 - $rating);
        $text  = "📊 Новая оценка удобства сайта: {$stars} ({$rating}/5)";
        if ($orderId > 0) $text .= "\nК заказу #{$orderId}";
        $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_POSTFIELDS => ['chat_id' => $adminId, 'text' => $text],
        ]);
        curl_exec($ch); curl_close($ch);
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('rate_site error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}