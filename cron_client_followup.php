#!/usr/bin/env php
<?php
/**
 * cron_client_followup.php
 *
 * Удержание клиентов / повторные продажи: спустя ~1–2 месяца после того,
 * как клиент получил готовую работу, бот сам мягко напоминает о себе —
 * "Как просмотры? Если нужно что-то свежее — я на связи" — с кнопкой
 * "Оформить новый заказ". Никакого спама: одному заказу — максимум ОДНО
 * такое напоминание (см. followup_sent_at), и напоминание не шлётся
 * раньше FOLLOWUP_DAYS дней с момента сдачи работы.
 *
 * КАК НАСТРОИТЬ (аналогично cron_remind_urgent.php):
 * ──────────────────────────────────────────────────────────────
 * Добавь в crontab (crontab -e) — запускать РАЗ В СУТКИ достаточно:
 *   0 10 * * * php /path/to/cron_client_followup.php >> /tmp/kostlim_followup.log 2>&1
 *
 * Или вызывай через веб-сервис (например, uptime-пинг раз в день):
 *   https://твой-сайт.onrender.com/cron_client_followup.php?secret=ТУТ_СЕКРЕТ
 *
 * Через сколько дней после сдачи слать напоминание — настраивается через
 * переменную окружения FOLLOWUP_DAYS (по умолчанию 45 — "через месяц-два").
 * ──────────────────────────────────────────────────────────────
 */

define('CRON_SECRET', getenv('CRON_SECRET') ?: 'kostlim_secret_2024');

if (php_sapi_name() !== 'cli') {
    $secret = $_GET['secret'] ?? '';
    if ($secret !== CRON_SECRET) {
        http_response_code(403);
        die('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/order_flow.php';

// На случай, если скрипт запускают на свежей БД раньше, чем сработает
// автомиграция внутри bot.php — гарантируем, что нужная колонка есть.
ensureOrderFlowSchema($pdo);

$token       = getenv('BOT_TOKEN') ?: getenv('TELEGRAM_BOT_TOKEN') ?: '';
$site_url    = getenv('SITE_URL')  ?: "https://kostlimdzn.kesug.com/";
$followupDays = (int)(getenv('FOLLOWUP_DAYS') ?: 45);
if ($followupDays < 14) $followupDays = 45; // защита от случайной опечатки в env (слишком рано слать)

/**
 * Заказы, которые:
 *  - реально сданы клиенту (status = 'ready');
 *  - есть куда писать (client_chat_id);
 *  - напоминание по ним ещё не отправлялось (followup_sent_at IS NULL);
 *  - с момента сдачи (client_accepted_at — когда клиент нажал "Принять
 *    работу"; если этого клика не было, берём started_at как приближение)
 *    прошло достаточно времени.
 * Один клиент может быть учтён несколько раз, если у него несколько
 * готовых заказов — в реальности это редкость, а дубль напоминания раз в
 * пару месяцев не страшен.
 */
$stmt = $pdo->query("
    SELECT id, username, telegram, client_chat_id, service_key, service_keys_extra,
           COALESCE(client_accepted_at, started_at) AS delivered_at
    FROM orders
    WHERE status = 'ready'
      AND client_chat_id IS NOT NULL AND client_chat_id <> ''
      AND followup_sent_at IS NULL
      AND COALESCE(client_accepted_at, started_at) IS NOT NULL
      AND COALESCE(client_accepted_at, started_at) <= (NOW() - (? || ' days')::interval)
    ORDER BY id ASC
    LIMIT 200
");
$stmt->execute([$followupDays]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($orders)) {
    echo "[" . date('Y-m-d H:i:s') . "] Нет заказов, готовых для напоминания (порог: {$followupDays} дн.).\n";
    exit;
}

$orderUrl = rtrim($site_url, '/') . '/order.php';
$sentCount = 0;

foreach ($orders as $o) {
    $serviceTitle = getOrderServiceTitle($pdo, $o) ?: ($o['service_key'] ?? 'заказ');

    $text = "👋 Привет! Давно не виделись — как там дела с оформлением по заказу #{$o['id']} ({$serviceTitle})? Как просмотры/охваты? 📈\n\n"
          . "Если контент обновился и нужно что-то свежее (новое превью, баннер, аватарка) — я на связи, оформить можно в пару кликов 👇";

    $keyboard = [
        'inline_keyboard' => [[
            ['text' => '🎨 Оформить новый заказ', 'url' => $orderUrl],
        ]],
    ];

    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id'      => $o['client_chat_id'],
            'text'         => $text,
            'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $result = json_decode((string)$res, true);
    $ok = (bool)($result['ok'] ?? false);

    // Помечаем как отправленное НЕЗАВИСИМО от результата доставки (кроме
    // явной ошибки авторизации бота) — если клиент заблокировал бота,
    // повторные попытки раз за разом смысла не имеют и будут только
    // засорять логи; такие случаи видно в логе ниже по "FAIL".
    $pdo->prepare("UPDATE orders SET followup_sent_at = NOW() WHERE id = ?")->execute([$o['id']]);

    if ($ok) $sentCount++;
    echo "[" . date('Y-m-d H:i:s') . "] Заказ #{$o['id']} (chat_id={$o['client_chat_id']}): " . ($ok ? 'OK' : 'FAIL — ' . ($result['description'] ?? 'unknown error')) . "\n";

    usleep(150000); // небольшая пауза между сообщениями — не долбить Telegram API пачкой
}

echo "[" . date('Y-m-d H:i:s') . "] Готово. Отправлено напоминаний: {$sentCount} из " . count($orders) . ".\n";
