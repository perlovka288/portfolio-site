#!/usr/bin/env php
<?php
/**
 * cron_remind_urgent.php
 *
 * Скрипт для Cron — напоминает о срочных заказах каждые 2 часа.
 *
 * КАК НАСТРОИТЬ (Render или любой Linux-хостинг):
 * ──────────────────────────────────────────────────────────────
 * Добавь в crontab (crontab -e):
 *
 *   0 * * * * php /path/to/cron_remind_urgent.php >> /tmp/kostlim_cron.log 2>&1
 *
 * Это запустит скрипт каждый час, но сам скрипт проверяет
 * внутренне — прошло ли 2 часа с последнего напоминания по каждому заказу.
 *
 * На Render (без cron): можно вызывать через внешний cron-сервис
 * (например cron-job.org) по URL: https://твой-сайт.onrender.com/cron_remind_urgent.php?secret=ТУТ_СЕКРЕТ
 * ──────────────────────────────────────────────────────────────
 */

// Секрет для веб-вызова (защита от случайного срабатывания)
define('CRON_SECRET', getenv('CRON_SECRET') ?: 'kostlim_secret_2024');

// Если вызов через браузер — проверяем секрет
if (php_sapi_name() !== 'cli') {
    $secret = $_GET['secret'] ?? '';
    if ($secret !== CRON_SECRET) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

require_once __DIR__ . '/config/db.php';

$token    = getenv('BOT_TOKEN') ?: "8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg";
$admin_id = getenv('ADMIN_ID')  ?: "1710365896";

// Выбираем все срочные активные заказы
$stmt = $pdo->query("
    SELECT id, username, service_key, urgent_deadline, last_reminded_at
    FROM orders
    WHERE is_urgent = 1 AND status IN ('pending', 'in_progress')
");
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($orders)) {
    echo "[" . date('Y-m-d H:i:s') . "] Срочных заказов нет.\n";
    exit;
}

$now = new DateTime();
$reminded = 0;

foreach ($orders as $o) {
    // Проверяем: прошло ли 2 часа с последнего напоминания
    if (!empty($o['last_reminded_at'])) {
        $last     = new DateTime($o['last_reminded_at']);
        $diff_sec = $now->getTimestamp() - $last->getTimestamp();
        if ($diff_sec < 7200) { // 2 часа = 7200 сек
            continue;
        }
    }

    $left_str = 'неизвестно';
    $is_overdue = false;
    if (!empty($o['urgent_deadline'])) {
        $dt   = new DateTime($o['urgent_deadline']);
        $diff = $now->diff($dt);
        $h    = $diff->h + $diff->days * 24;
        if ($dt > $now) {
            $left_str = "~{$h} часов";
        } else {
            $left_str = "⚠️ ДЕДЛАЙН ПРОСРОЧЕН!";
            $is_overdue = true;
        }
    }

    $emoji = $is_overdue ? '🚨🚨' : '🚨';

    $keyboard = json_encode([
        'inline_keyboard' => [[
            ['text' => "📦 Открыть заказ #{$o['id']}", 'callback_data' => "adm_view_{$o['id']}"],
        ]],
    ], JSON_UNESCAPED_UNICODE);

    // Отправляем напоминание
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'chat_id'      => $admin_id,
        'text'         => "{$emoji} *Напоминание! Срочный заказ*\n\n📦 Заказ *#{$o['id']}* — {$o['username']}\n🎨 Услуга: {$o['service_key']}\n⏰ Осталось: *{$left_str}*",
        'parse_mode'   => 'Markdown',
        'reply_markup' => $keyboard,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);

    // Обновляем время последнего напоминания
    $pdo->prepare("UPDATE orders SET last_reminded_at = NOW() WHERE id = ?")->execute([$o['id']]);
    $reminded++;

    echo "[" . date('Y-m-d H:i:s') . "] Напомнил о заказе #{$o['id']} ({$o['username']}), осталось: {$left_str}\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Готово. Напомнил: {$reminded} заказов из " . count($orders) . " срочных.\n";