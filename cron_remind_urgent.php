#!/usr/bin/env php
<?php
/**
 * cron_remind_urgent.php
 *
 * Скрипт для Cron — напоминает о срочных и активных заказах каждые 4 часа.
 *
 * КАК НАСТРОИТЬ:
 * ──────────────────────────────────────────────────────────────
 * Добавь в crontab (crontab -e):
 *   0 */4 * * * php /path/to/cron_remind_urgent.php >> /tmp/kostlim_cron.log 2>&1
 *
 * Или вызывай через веб-сервис каждые 4 часа:
 *   https://твой-сайт.onrender.com/cron_remind_urgent.php?secret=ТУТ_СЕКРЕТ
 * ──────────────────────────────────────────────────────────────
 */

// Секрет для веб-вызова (защита от случайного срабатывания)
define('CRON_SECRET', getenv('CRON_SECRET') ?: 'kostlim_secret_2024');

// Если вызов через браузер — проверяем секрет
if (php_sapi_name() !== 'cli') {
    $secret = $_GET['secret'] ?? '';
    if ($secret !== CRON_SECRET) {
        http_response_code(403);
        die('Forbidden');
    }
    header('Content-Type: text/plain');
}

require_once __DIR__ . '/config/db.php';

$token    = getenv('BOT_TOKEN') ?: "8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg";
$admin_id = getenv('ADMIN_ID')  ?: "1710365896";

// Выбираем все активные заказы (pending, in_progress, urgent)
$stmt = $pdo->query("
    SELECT id, username, telegram, service_key, status, deadline
    FROM orders
    WHERE status IN ('pending', 'in_progress', 'urgent')
    ORDER BY deadline ASC, id ASC
");
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($orders)) {
    $msg = "[" . date('Y-m-d H:i:s') . "] Нет активных заказов.\n";
    echo $msg;
    exit;
}

$now = time();
$urgent_orders = [];
$overdue_orders = [];

// Сортируем по приоритету: просроченные, потом срочные, потом остальные
foreach ($orders as $o) {
    if (empty($o['deadline'])) continue;
    
    $deadline_ts = strtotime($o['deadline']);
    $diff = $deadline_ts - $now;
    
    if ($diff < 0) {
        // Просроченный
        $overdue_orders[] = [
            'order' => $o,
            'hours_overdue' => abs(ceil($diff / 3600)),
        ];
    } elseif ($diff < 24 * 3600 || $o['status'] === 'urgent') {
        // Срочный (менее 24 часов или статус urgent)
        $urgent_orders[] = [
            'order' => $o,
            'hours_left' => ceil($diff / 3600),
        ];
    }
}

// Приоритет: сначала просроченные, потом срочные
$sorted_orders = array_merge($overdue_orders, $urgent_orders);

if (empty($sorted_orders)) {
    echo "[" . date('Y-m-d H:i:s') . "] Нет срочных или просроченных заказов.\n";
    exit;
}

// Формируем сообщение со списком заказов
$message = "🚨 *АКТИВНЫЕ СРОЧНЫЕ ЗАКАЗЫ* 🚨\n";
$message .= "(" . count($sorted_orders) . " шт.)\n\n";

$keyboard = ['inline_keyboard' => []];

foreach ($sorted_orders as $item) {
    $o = $item['order'];
    $status_emoji = [
        'pending' => '⏳',
        'in_progress' => '🎨',
        'urgent' => '⚡️',
    ][$o['status']] ?? '📦';
    
    if (isset($item['hours_overdue'])) {
        // Просроченный
        $message .= "🔴 *Заказ #{$o['id']} (ПРОСРОЧЕН)* — {$o['username']}\n";
        $message .= "   ⚠️ ПРОСРОЧЕНО на {$item['hours_overdue']} ч!\n";
    } else {
        // Срочный или 4-й день в работе
        $message .= "{$status_emoji} *Заказ #{$o['id']} (Остались сутки)* — {$o['username']}\n";
        $message .= "   ⏰ Осталось: ~{$item['hours_left']} ч.\n";
    }
    
    $message .= "   🎨 {$o['service_key']}\n";
    $message .= "   📞 {$o['telegram']}\n\n";
    
    // Добавляем кнопку к заказу
    $keyboard['inline_keyboard'][] = [[
        'text' => "📦 Заказ #{$o['id']} ({$item['hours_left'] ?? $item['hours_overdue']} ч.)",
        'callback_data' => "adm_view_{$o['id']}",
    ]];
}

// Отправляем сообщение в Telegram
$ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'chat_id'      => $admin_id,
        'text'         => $message,
        'parse_mode'   => 'Markdown',
        'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);
$res = curl_exec($ch);
curl_close($ch);

$result = json_decode($res, true);
$status = ($result['ok'] ?? false) ? 'OK' : 'FAIL';

echo "[" . date('Y-m-d H:i:s') . "] Отправлено напоминание о " . count($sorted_orders) . " заказах (включая те, что в работе 4 дня). Status: {$status}\n";