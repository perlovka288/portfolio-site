<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'auth.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/order_flow.php';
require_once __DIR__ . '/psd_manager.php';
require_once __DIR__ . '/bot_commands.php';
ensureBotCommandTables($pdo);
ensureOrderFlowSchema($pdo);
ensurePromoSchema($pdo);

try {
    $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS cooperation BOOLEAN NOT NULL DEFAULT FALSE;");
    $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS deadline TIMESTAMP;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_rules (id SERIAL PRIMARY KEY, rule_key VARCHAR(100) UNIQUE, rule_text TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);");
} catch (PDOException $e) {
    // ignore
}

// ══════════════════════════════════════════════════════════════════
// НАСТРОЙКИ / КЛЮЧИ (site_settings) — единая таблица "ключ → значение",
// которую теперь можно редактировать прямо из вкладки "🔑 Ключи и API"
// без захода в переменные окружения на Render. Если в таблице пусто —
// используется getenv()/дефолт как раньше (обратная совместимость).
// ══════════════════════════════════════════════════════════════════
function ensureSiteSettingsTable(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (setting_key VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    } catch (Throwable $e) {}
}
function getSetting(PDO $pdo, string $key, string $default = ''): string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    $val = $default;
    try {
        $stmt = $pdo->prepare("SELECT value FROM site_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && trim((string)$row['value']) !== '') $val = $row['value'];
    } catch (Throwable $e) {}
    $cache[$key] = $val;
    return $val;
}
function setSetting(PDO $pdo, string $key, string $value): void
{
    try {
        $pdo->prepare("INSERT INTO site_settings (setting_key, value, updated_at) VALUES (?, ?, NOW())
                       ON CONFLICT (setting_key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()")
            ->execute([$key, $value]);
    } catch (Throwable $e) {}
}
ensureSiteSettingsTable($pdo);

// Список ключей, которые редактируются во вкладке "Ключи и API".
// group "core"  — реально используются в этом файле (ImgBB, бот, чаты, сайт).
// group "other" — читаются другими файлами проекта (bot.php, ai_widget.php и т.п.)
// напрямую через getenv(), поэтому запись отсюда — записная книжка и подставится
// туда, только если в тех файлах тоже вызвать getSetting().
$API_KEY_FIELDS = [
    'core' => [
        ['key' => 'BOT_TOKEN',              'label' => '🤖 Токен Telegram-бота',                 'secret' => true],
        ['key' => 'PORTFOLIO_CHANNEL_CHAT', 'label' => '📣 Канал портфолио (chat_id/@username)', 'secret' => false],
        ['key' => 'PRIVATE_CHAT_ID',        'label' => '🔒 Приватный чат для PSD-паков',         'secret' => false],
        ['key' => 'SITE_URL',               'label' => '🌐 Публичный URL сайта',                 'secret' => false],
        ['key' => 'IMGBB_API_KEY',          'label' => '🖼 ImgBB — ключ №1',                     'secret' => true],
        ['key' => 'IMGBB_API_KEY2',         'label' => '🖼 ImgBB — ключ №2 (резерв)',            'secret' => true],
        ['key' => 'IMGBB_API_KEY3',         'label' => '🖼 ImgBB — ключ №3 (резерв)',            'secret' => true],
        ['key' => 'ADMIN_EMAIL',            'label' => '📧 Email администратора',                'secret' => false],
        ['key' => 'ADMIN_TELEGRAM_ID',      'label' => '🆔 Telegram ID администратора',          'secret' => false],
    ],
    'other' => [
        ['key' => 'GEMINI_API_KEY',            'label' => '✨ Gemini API ключ (ИИ-помощник)', 'secret' => true],
        ['key' => 'GEMINI_MODEL',              'label' => '✨ Gemini модель',                 'secret' => false],
        ['key' => 'CLOUDINARY_CLOUD_NAME',     'label' => '☁️ Cloudinary — cloud name',       'secret' => false],
        ['key' => 'CLOUDINARY_API_KEY',        'label' => '☁️ Cloudinary — API key',          'secret' => true],
        ['key' => 'CLOUDINARY_API_SECRET',     'label' => '☁️ Cloudinary — API secret',       'secret' => true],
        ['key' => 'GDRIVE_FOLDER_ID',          'label' => '📁 Google Drive — ID папки',       'secret' => false],
        ['key' => 'PAYMENT_REQUISITES_RUB',    'label' => '💳 Реквизиты для оплаты ₽',        'secret' => false],
        ['key' => 'PAYMENT_REQUISITES_UAH',    'label' => '💳 Реквизиты для оплаты ₴',        'secret' => false],
        ['key' => 'PAYMENT_REQUISITES_CRYPTO', 'label' => '₿ Реквизиты для оплаты (крипта)',  'secret' => false],
        ['key' => 'PAYMENT_REQUISITES_MONO',   'label' => '🏦 Реквизиты Монобанк',            'secret' => false],
        ['key' => 'YT_API_KEY',                'label' => '▶️ YouTube API ключ',              'secret' => true],
        ['key' => 'TURNSTILE_SITE_KEY',        'label' => '🛡 Turnstile — site key',          'secret' => false],
        ['key' => 'TURNSTILE_SECRET_KEY',      'label' => '🛡 Turnstile — secret key',        'secret' => true],
    ],
];

// ── Сохранение ключей (AJAX) ──────────────────────────────────────
if (isset($_POST['save_api_keys']) && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    foreach (array_merge($API_KEY_FIELDS['core'], $API_KEY_FIELDS['other']) as $f) {
        if (array_key_exists($f['key'], $_POST)) {
            setSetting($pdo, $f['key'], trim((string)$_POST[$f['key']]));
        }
    }
    echo json_encode(['ok' => true, 'msg' => '✅ Ключи сохранены и уже применяются.']);
    exit;
}

$message = '';
$uploadDir = '../uploads/';
define('TELEGRAM_BOT_TOKEN', getSetting($pdo, 'BOT_TOKEN', getenv('BOT_TOKEN') ?: getenv('TELEGRAM_BOT_TOKEN') ?: '8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg'));
define('PORTFOLIO_CHANNEL_CHAT', getSetting($pdo, 'PORTFOLIO_CHANNEL_CHAT', getenv('PORTFOLIO_CHANNEL_CHAT') ?: '@designkostlim'));
if (!defined('PRIVATE_PACK_CHAT_ID')) {
    define('PRIVATE_PACK_CHAT_ID', getSetting($pdo, 'PRIVATE_CHAT_ID', getenv('PRIVATE_CHAT_ID') ?: '-1003781426510'));
}
define('PUBLIC_SITE_URL', rtrim(getSetting($pdo, 'SITE_URL', getenv('SITE_URL') ?: 'https://portfolio-site-boo5.onrender.com/'), '/') . '/');
define('ADMIN_EMAIL', getSetting($pdo, 'ADMIN_EMAIL', 'jeffkostlim@gmail.com'));
define('ADMIN_TELEGRAM_ID', getSetting($pdo, 'ADMIN_TELEGRAM_ID', '1710365896'));
$telegramLastError = '';

// ══════════════════════════════════════════════════════════════════
// DonationAlerts
// ══════════════════════════════════════════════════════════════════
$_da_path = __DIR__ . '/../donationalerts.php';
if (file_exists($_da_path)) {
    require_once $_da_path;
}
if (!function_exists('daEnsureAccessToken')) {
    function daEnsureAccessToken(PDO $pdo): ?string { return null; }
}
if (!function_exists('daGetAuthorizeUrl')) {
    function daGetAuthorizeUrl(): string { return '#'; }
}
if (!function_exists('daGetDonations')) {
    function daGetDonations(PDO $pdo, int $limit = 200): array { return []; }
}
if (!function_exists('daGetCurrentMonthDonationTotalUsd')) {
    function daGetCurrentMonthDonationTotalUsd(array $donations): float { return 0.0; }
}
if (!function_exists('daGetCurrentMonthPayoutStats')) {
    function daGetCurrentMonthPayoutStats(PDO $pdo): array {
        return ['gross' => 0.0, 'count' => 0, 'commission' => 0.0, 'net' => 0.0];
    }
}

ensureDefaultPortfolioCategories($pdo);

// ──────────────────────────────────────────────────────────────────
// ОТЗЫВЫ - одобрение/отклонение
// ──────────────────────────────────────────────────────────────────
if (isset($_POST['approve_review'])) {
    $rid = (int)($_POST['review_id'] ?? 0);
    if ($rid > 0) {
        $pdo->prepare("UPDATE reviews SET approved = TRUE WHERE id = ?")->execute([$rid]);
        $message = '✅ Отзыв одобрен.';
        // Уведомление админу в TG
        try {
            $rv = $pdo->prepare("SELECT * FROM reviews WHERE id = ? LIMIT 1");
            $rv->execute([$rid]);
            $rvRow = $rv->fetch(PDO::FETCH_ASSOC);
            if ($rvRow && defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN !== '' && defined('ADMIN_TELEGRAM_ID') && ADMIN_TELEGRAM_ID !== '') {
                $stars = str_repeat('⭐', (int)($rvRow['rating'] ?? 5));
                $name  = htmlspecialchars($rvRow['tg_first_name'] ?: ('Клиент #' . $rvRow['order_id']));
                $txt   = htmlspecialchars(mb_substr($rvRow['text'] ?? '', 0, 200));
                $tgMsg = "✅ <b>Отзыв одобрен!</b>\n\n👤 {$name}\n{$stars}\n\n💬 <i>{$txt}</i>";
                sendTelegramRequest('sendMessage', ['chat_id' => ADMIN_TELEGRAM_ID, 'text' => $tgMsg, 'parse_mode' => 'HTML']);
            }
        } catch (Throwable $e) {}
    }
}

if (isset($_POST['reject_review'])) {
    $rid = (int)($_POST['review_id'] ?? 0);
    if ($rid > 0) {
        $pdo->prepare("DELETE FROM reviews WHERE id = ?")->execute([$rid]);
        $message = '🗑️ Отзыв удален.';
    }
}

// Уведомление о новом отзыве (вызывается из review.php через POST с ключом notify_new_review)
if (isset($_POST['notify_new_review_internal'])) {
    $rid = (int)($_POST['review_id_notify'] ?? 0);
    if ($rid > 0) {
        try {
            $rv = $pdo->prepare("SELECT * FROM reviews WHERE id = ? LIMIT 1");
            $rv->execute([$rid]);
            $rvRow = $rv->fetch(PDO::FETCH_ASSOC);
            if ($rvRow && defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN !== '' && defined('ADMIN_TELEGRAM_ID') && ADMIN_TELEGRAM_ID !== '') {
                $stars = str_repeat('⭐', (int)($rvRow['rating'] ?? 5));
                $name  = $rvRow['tg_first_name'] ?: ('Клиент #' . $rvRow['order_id']);
                $txt   = mb_substr($rvRow['text'] ?? '', 0, 300);
                $adminUrl = PUBLIC_SITE_URL . 'admin/index.php';
                $tgMsg = "⭐ <b>Новый отзыв!</b> (Заказ #" . (int)$rvRow['order_id'] . ")\n\n👤 {$name}\n{$stars}\n\n💬 <i>{$txt}</i>\n\n🔗 <a href=\"{$adminUrl}\">Модерировать</a>";
                sendTelegramRequest('sendMessage', ['chat_id' => ADMIN_TELEGRAM_ID, 'text' => $tgMsg, 'parse_mode' => 'HTML']);
            }
        } catch (Throwable $e) {}
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ──────────────────────────────────────────────────────────────────
// КОМАНДЫ БОТА - сохранение
// ──────────────────────────────────────────────────────────────────
if (isset($_POST['save_bot_commands'])) {
    $commands  = $_POST['bot_commands']   ?? [];
    $toDelete  = $_POST['delete_commands'] ?? [];

    // Удаляем отмеченные
    foreach ($toDelete as $delId) {
        try { $pdo->prepare("DELETE FROM bot_commands WHERE id = ?")->execute([(int)$delId]); } catch (Throwable $e) {}
    }

    // Обновляем оставшиеся
    foreach ($commands as $id => $data) {
        $id = (int)$id;
        if (in_array((string)$id, array_map('strval', $toDelete))) continue;
        $cmd   = trim($data['command'] ?? '');
        $desc  = trim($data['description'] ?? '');
        $level = trim($data['access_level'] ?? 'admin');
        if ($cmd !== '' && $id > 0) {
            try {
                $pdo->prepare("UPDATE bot_commands SET command = ?, description = ?, access_level = ? WHERE id = ?")
                    ->execute([$cmd, $desc, $level, $id]);
            } catch (Throwable $e) {}
        }
    }

    // Добавить новую команду если указана
    $newCmd  = trim($_POST['new_command_name'] ?? '');
    $newDesc = trim($_POST['new_command_desc'] ?? '');
    if ($newCmd !== '') {
        try {
            $pdo->prepare("INSERT INTO bot_commands (command, description, access_level) VALUES (?, ?, 'admin') ON CONFLICT DO NOTHING")
                ->execute([ltrim($newCmd, '/'), $newDesc]);
        } catch (Throwable $e) {}
    }

    // Синхронизируем с Telegram (setMyCommands)
    try {
        $_botToken = getenv('BOT_TOKEN') ?: getenv('TELEGRAM_BOT_TOKEN') ?: '8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg';
        $_allCmds  = $pdo->query("SELECT command, description FROM bot_commands ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $_tgCmds   = array_map(fn($c) => ['command' => ltrim($c['command'],'/'), 'description' => ($c['description'] ?: $c['command'])], $_allCmds);
        if (!empty($_tgCmds)) {
            $_ch = curl_init("https://api.telegram.org/bot{$_botToken}/setMyCommands");
            curl_setopt_array($_ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
                CURLOPT_POSTFIELDS => ['commands' => json_encode($_tgCmds, JSON_UNESCAPED_UNICODE)]]);
            curl_exec($_ch); curl_close($_ch);
        }
    } catch (Throwable $e) {}

    $message = '✅ Команды сохранены и обновлены в Telegram.';
}

// ──────────────────────────────────────────────────────────────────
// ПРАВИЛА ЗАКАЗА - сохранение
// ──────────────────────────────────────────────────────────────────
if (isset($_POST['save_order_rules'])) {
    $rulesText = trim($_POST['order_rules_text'] ?? '');
    try {
        $pdo->prepare("INSERT INTO site_rules (rule_key, rule_text, updated_at) VALUES (?, ?, NOW()) ON CONFLICT (rule_key) DO UPDATE SET rule_text = EXCLUDED.rule_text, updated_at = NOW()")
            ->execute(['order_terms', $rulesText]);
        $message = '✅ Правила заказа сохранены.';
    } catch (Throwable $e) {
        $message = '❌ Ошибка сохранения: ' . $e->getMessage();
    }
}

// ──────────────────────────────────────────────────────────────────
// Вспомогательная функция: дедлайн заказа
// ──────────────────────────────────────────────────────────────────
function calcDeadline(string $created_at, bool $isUrgent = false): ?\DateTime
{
    try {
        $dt = new \DateTime($created_at);
        $dt->modify($isUrgent ? '+24 hours' : '+5 days');
        return $dt;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * ВАЖНО: эта функция ТОЛЬКО отображает переданную дату — она больше НЕ
 * прибавляет к ней дни сама. Раньше, когда сюда передавали уже готовый,
 * реально сохранённый дедлайн (order['deadline'], который уже был равен
 * "создан + 5 дней"), функция прибавляла к нему ЕЩЁ 5 дней сверху — отсюда
 * баг "заказ создан 5-го, а сдать почему-то 15-го" (5+5+5 вместо 5).
 * Если дедлайна в базе ещё нет (заказ ещё не оплачен) — считать его
 * явно через calcDeadline() ДО вызова этой функции.
 */
function deadlineBadge(string $deadlineDatetime, bool $isUrgent = false, string $status = ''): string
{
    try {
        $dl = new \DateTime($deadlineDatetime);
    } catch (\Throwable $e) {
        return '';
    }
    $now = new \DateTime();
    $diff = $now->diff($dl);
    $overdue = $dl < $now;
    $dateStr = $dl->format('d.m.Y H:i');
    $color = $overdue ? '#ef4444' : ($isUrgent ? '#f97316' : '#60a5fa');
    $bg    = $overdue ? 'rgba(239,68,68,.18)' : ($isUrgent ? 'rgba(249,115,22,.18)' : 'rgba(96,165,250,.12)');
    $icon  = $overdue ? '🔴' : ($isUrgent ? '⚡' : '📅');
    if ($status === 'ready') {
        // Заказ уже сдан — не "ПРОСРОЧЕН" (звучит как незакрытая проблема),
        // а итог: успели в дедлайн или нет. Бейдж всё равно продолжает
        // показываться после статуса "Готов", просто с адекватной подписью.
        $icon  = $overdue ? '🔴' : '✅';
        $label = $overdue ? "Сдан с опозданием (дедлайн был {$dateStr})" : "Сдан в срок (дедлайн был {$dateStr})";
    } else {
        $label = $overdue
            ? "ПРОСРОЧЕН ({$dateStr})"
            : ($isUrgent ? "Срочно: {$dateStr}" : "Сдать: {$dateStr}");
    }
    return "<span style=\"display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:4px 9px;font-size:11px;font-weight:800;background:{$bg};color:{$color};border:1px solid {$color}33;\">{$icon} {$label}</span>";
}

// ── Ручное начисление денег в статистику (кнопка "+" рядом со статистикой) ──
if (isset($_POST['add_manual_earning'])) {
    $meMethod   = in_array($_POST['me_method'] ?? '', ['donation','crypto','monobank','other'], true) ? $_POST['me_method'] : 'other';
    $meCurrency = in_array($_POST['me_currency'] ?? '', ['RUB','USD','UAH'], true) ? $_POST['me_currency'] : 'RUB';
    $meAmount   = (float)str_replace(',', '.', $_POST['me_amount'] ?? '0');
    $meNote     = trim((string)($_POST['me_note'] ?? ''));
    $meSign     = ($_POST['me_sign'] ?? 'add') === 'subtract' ? -1 : 1;
    if ($meAmount > 0) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS manual_earnings (
                id SERIAL PRIMARY KEY,
                method VARCHAR(20) NOT NULL DEFAULT 'other',
                amount NUMERIC(12,2) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT 'RUB',
                note VARCHAR(255) NOT NULL DEFAULT '',
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )");
            $pdo->prepare("INSERT INTO manual_earnings (method, amount, currency, note) VALUES (?,?,?,?)")
                ->execute([$meMethod, $meAmount * $meSign, $meCurrency, $meNote]);
        } catch (Throwable $e) {}
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#earnings'); exit;
}
if (isset($_GET['delete_manual_earning_id'])) {
    try { $pdo->prepare("DELETE FROM manual_earnings WHERE id = ?")->execute([(int)$_GET['delete_manual_earning_id']]); } catch (Throwable $e) {}
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#earnings'); exit;
}

// ── Промокоды: добавление / переключение активности / удаление ──────
if (isset($_POST['add_promo'])) {
    $pcCode     = trim((string)($_POST['pc_code'] ?? ''));
    $pcPct      = trim((string)($_POST['pc_discount'] ?? ''));
    $pcBonus    = trim((string)($_POST['pc_bonus'] ?? ''));
    $pcDuration = trim((string)($_POST['pc_duration'] ?? '')); // '' | '7' | '10' | '15' | '20' | '25' | '30' | 'custom' | 'none'
    $pcCustomDate = trim((string)($_POST['pc_custom_date'] ?? ''));
    $pcMaxUses  = trim((string)($_POST['pc_max_uses'] ?? ''));

    $pcExpiresAt = null;
    if ($pcDuration !== '' && $pcDuration !== 'none') {
        if ($pcDuration === 'custom' && $pcCustomDate !== '') {
            $pcExpiresAt = $pcCustomDate . ' 23:59:59';
        } elseif (ctype_digit($pcDuration)) {
            $pcExpiresAt = date('Y-m-d H:i:s', strtotime('+' . (int)$pcDuration . ' days'));
        }
    }

    $pcNewId = 0;
    if ($pcCode !== '') {
        try {
            $ins = $pdo->prepare("INSERT INTO promo_codes (code, discount_percent, bonus_text, max_uses, expires_at, active) VALUES (?,?,?,?,?,TRUE)
                ON CONFLICT (code) DO UPDATE SET discount_percent = EXCLUDED.discount_percent, bonus_text = EXCLUDED.bonus_text, max_uses = EXCLUDED.max_uses, expires_at = EXCLUDED.expires_at, active = TRUE
                RETURNING id");
            $ins->execute([$pcCode, $pcPct !== '' ? (int)$pcPct : null, $pcBonus, $pcMaxUses !== '' ? (int)$pcMaxUses : null, $pcExpiresAt]);
            $pcNewId = (int)$ins->fetchColumn();
        } catch (Throwable $e) {}
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json; charset=utf-8');
        if ($pcCode === '' || $pcNewId <= 0) { echo json_encode(['ok' => false, 'msg' => '❌ Укажи код промокода.']); exit; }
        $row = $pdo->prepare("SELECT * FROM promo_codes WHERE id = ? LIMIT 1"); $row->execute([$pcNewId]);
        $promo = $row->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'msg' => '✅ Промокод создан.', 'html' => renderPromoCardHtml($promo)]);
        exit;
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#promo'); exit;
}
if (isset($_GET['toggle_promo_id'])) {
    try { $pdo->prepare("UPDATE promo_codes SET active = NOT active WHERE id = ?")->execute([(int)$_GET['toggle_promo_id']]); } catch (Throwable $e) {}
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#promo'); exit;
}
if (isset($_GET['delete_promo_id'])) {
    try { $pdo->prepare("DELETE FROM promo_codes WHERE id = ?")->execute([(int)$_GET['delete_promo_id']]); } catch (Throwable $e) {}
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#promo'); exit;
}

// ── AJAX endpoint: добавить портфолио ────────────────────────────
if (isset($_POST['add_portfolio']) && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    ob_start();

    $title        = trim($_POST['title'] ?? '');
    $category_key = $_POST['category_key'] ?? 'preview';
    $price_rub    = !empty($_POST['price_rub']) ? (int)$_POST['price_rub'] : 0;
    $price_uan    = !empty($_POST['price_uan']) ? (int)$_POST['price_uan'] : 0;
    $publish_tg   = !empty($_POST['publish_tg']);

    $filename_main   = uploadImage('image', 'main', $uploadDir);
    $filename_avatar = uploadImage('avatar_image', 'ava', $uploadDir);

    if ($title === '') {
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => '❌ Укажи название проекта.']);
        exit;
    }
    if ($filename_main === '') {
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => '❌ Не удалось загрузить изображение. Проверь IMGBB_API_KEY в Render.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO portfolio (title, category_key, price_rub, price_uan, image, avatar_image) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$title, $category_key, $price_rub, $price_uan, $filename_main, $filename_avatar]);
    $portfolioId = (int)$pdo->lastInsertId();

    $postedToChannel = false;
    $watermarkedPath = null;
    if ($publish_tg) {
        $watermarkedPath = buildWatermarkedPhotoForPortfolio($pdo, $uploadDir, [
            'title'        => $title,
            'category_key' => $category_key,
            'price_rub'    => $price_rub,
            'price_uan'    => $price_uan,
            'image'        => $filename_main,
            'avatar_image' => $filename_avatar,
        ]);
        if ($watermarkedPath && is_file($watermarkedPath)) {
            $caption = "💰 Цена работы: {$price_rub}₽ | {$price_uan}₴\n\n💬 Оценить данную работу можно в комментариях.\n\n🚀 Заказать дизайн можно тут - <a href=\"" . htmlspecialchars(PUBLIC_SITE_URL, ENT_QUOTES, 'UTF-8') . "\">Kostlim Design</a>";
            $result = sendTelegramRequest('sendPhoto', ['chat_id' => PORTFOLIO_CHANNEL_CHAT, 'caption' => $caption, 'parse_mode' => 'HTML'], ['photo' => new CURLFile($watermarkedPath)]);
            $postedToChannel = (bool)($result['ok'] ?? false);
        } else {
            $postedToChannel = publishPortfolioToChannel($pdo, $uploadDir, [
                'title'        => $title,
                'category_key' => $category_key,
                'price_rub'    => $price_rub,
                'price_uan'    => $price_uan,
                'image'        => $filename_main,
                'avatar_image' => $filename_avatar,
            ]);
        }
    }

    $postedToPrivate = false;
    if ($watermarkedPath && str_contains($watermarkedPath, sys_get_temp_dir()) && is_file($watermarkedPath)) {
        @unlink($watermarkedPath);
    }

    ob_end_clean();
    $msg = '✅ Портфолио сохранено! ID #' . $portfolioId;
    if ($publish_tg) {
        $msg .= $postedToChannel
            ? ' Пост отправлен в Telegram-канал.'
            : ' (Telegram-канал: ' . ($telegramLastError ?: 'проверь настройки бота') . ')';
    } else {
        $msg .= ' Без публикации в Telegram.';
    }
    echo json_encode(['ok' => true, 'msg' => $msg, 'portfolio_id' => $portfolioId]);
    exit;
}

// ── Уведомление клиенту о смене статуса ──────────────────────────
function notifyClientOrderStatus(PDO $pdo, int $orderId, string $newStatus): void
{
    $stmt = $pdo->prepare("SELECT client_chat_id, telegram, session_id FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;

    $chatId = trim((string)($row['client_chat_id'] ?? ''));

    if (($chatId === '' || !is_numeric($chatId)) && !empty($row['session_id'])) {
        try {
            $lnk = $pdo->prepare("
                SELECT NULLIF(CAST(tg_id AS VARCHAR),'') AS chat_id
                FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1
            ");
            $lnk->execute([$row['session_id']]);
            $lnk_row = $lnk->fetch(PDO::FETCH_ASSOC);
            if (!empty($lnk_row['chat_id']) && is_numeric($lnk_row['chat_id'])) {
                $chatId = $lnk_row['chat_id'];
                $pdo->prepare("UPDATE orders SET client_chat_id = ? WHERE id = ?")
                    ->execute([$chatId, $orderId]);
            }
        } catch (Throwable $e) {}
    }

    if ($chatId === '' || !is_numeric($chatId)) return;

    $statusMessages = [
        'in_progress'  => "🎨 Ваш заказ #<b>{$orderId}</b> взят в работу! Дизайнер уже начал выполнение.",
        'urgent'       => "⚡ Ваш заказ #<b>{$orderId}</b> помечен как срочный — срок сдачи 24 часа.",
        'ready'        => "🎉 Ваш заказ #<b>{$orderId}</b> готов! Дизайнер свяжется для передачи файлов.",
        // Раньше этот текст отличался от того, что шлёт бот при отказе через
        // Telegram-кнопки (там указывается причина + фото otkaz.jpg) — теперь
        // одинаково, независимо от того, откуда админ нажал "Отклонить".
        'declined'     => "🔴 <b>Заказ #{$orderId} отклонён.</b>",
        // Раньше у "Сотрудничество" из веб-панели не было отдельного текста
        // вообще (кнопки не существовало) — теперь текст 1-в-1 с ботом.
        'cooperation'  => "🤝 <b>Заказ #{$orderId} принят по сотрудничеству!</b>\n\n✅ Оплата не требуется — договорённость в силе.\n🚀 Дизайнер уже приступает к работе.",
    ];

    $text = $statusMessages[$newStatus] ?? null;
    if (!$text) return;

    $profileUrl = PUBLIC_SITE_URL . 'profile.php?order=' . $orderId;
    $text .= "\n\n🔗 <a href=\"{$profileUrl}\">Открыть заказ</a>\n\n❓ Если есть вопросы — пишите: @Perlo_ovka";

    // Картинки для сообщений о смене статуса — теперь у КАЖДОГО статуса есть
    // своя картинка, идентично тому, что шлёт бот при действии через Telegram
    // (раньше "Отклонить"/"Взять в работу", нажатые из веб-панели, уходили
    // вообще без фото — теперь единообразно).
    $statusPhotos = [
        'in_progress' => __DIR__ . '/../assets/notify/v_rabotu.jpg',
        'ready'       => __DIR__ . '/../assets/notify/gotovo.jpg',
        'urgent'      => __DIR__ . '/../assets/notify/fast.jpg',
        'declined'    => __DIR__ . '/../assets/notify/otkaz.jpg',
        'cooperation' => __DIR__ . '/../assets/notify/sot.jpg',
        'status'      => __DIR__ . '/../assets/notify/status.jpg',
    ];
    $photoPath = $statusPhotos[$newStatus] ?? '';
    if ($photoPath !== '' && is_file($photoPath)) {
        $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendPhoto');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POSTFIELDS     => ['chat_id' => $chatId, 'caption' => $text, 'parse_mode' => 'HTML', 'photo' => new CURLFile($photoPath)],
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        $decodedResp = json_decode((string)$resp, true);
        if (empty($decodedResp['ok'])) {
            // Фото не ушло — не оставляем клиента совсем без уведомления
            $ch2 = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
            curl_setopt_array($ch2, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_POSTFIELDS     => ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'],
            ]);
            $resp = curl_exec($ch2);
            $err  = curl_error($ch2);
            curl_close($ch2);
        }
    } else {
        $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'],
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
    }

    $logLine = '[' . date('Y-m-d H:i:s') . "] notifyClient order={$orderId} status={$newStatus} chat={$chatId} err={$err} resp=" . substr((string)$resp, 0, 120) . PHP_EOL;
    @file_put_contents(__DIR__ . '/../bot_debug.log', $logLine, FILE_APPEND);
}

function setOrderDeadline(PDO $pdo, int $orderId, bool $isUrgent = false): void
{
    $deadline = calculateOrderDeadline($isUrgent); // 120ч обычный / 24ч срочный
    try {
        $pdo->prepare("UPDATE orders SET deadline = ? WHERE id = ?")->execute([$deadline, $orderId]);
    } catch (\Throwable $e) {}
}

/**
 * Отправляет клиенту реквизиты на оплату — 1-в-1 с тем, что шлёт бот при
 * нажатии "Обычный (5 сут.)" / "Срочный (24ч, +50%)" в Telegram
 * (см. adm_accept_ / adm_accept_urgent_ в bot.php). Раньше кнопка "Принять"
 * в веб-панели сразу переводила заказ в "в работе" БЕЗ этого шага вообще —
 * из-за этого сайт и бот вели себя по-разному для одного и того же заказа.
 */
function adminAcceptWithPayment(PDO $pdo, int $orderId, bool $isUrgent): void
{
    $pdo->prepare("UPDATE orders SET status = 'awaiting_payment', payment_status = 'requested', accepted_at = NOW(), is_urgent = ? WHERE id = ?")
        ->execute([$isUrgent ? 1 : 0, $orderId]);

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) return;

    // Ищем chat_id клиента — та же логика, что в notifyClientOrderStatus.
    $chatId = trim((string)($order['client_chat_id'] ?? ''));
    if (($chatId === '' || !is_numeric($chatId)) && !empty($order['session_id'])) {
        try {
            $lnk = $pdo->prepare("SELECT NULLIF(CAST(tg_id AS VARCHAR),'') AS chat_id FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1");
            $lnk->execute([$order['session_id']]);
            $lnkRow = $lnk->fetch(PDO::FETCH_ASSOC);
            if (!empty($lnkRow['chat_id']) && is_numeric($lnkRow['chat_id'])) {
                $chatId = $lnkRow['chat_id'];
                $pdo->prepare("UPDATE orders SET client_chat_id = ? WHERE id = ?")->execute([$chatId, $orderId]);
                $order['client_chat_id'] = $chatId;
            }
        } catch (Throwable $e) {}
    }

    $promoDiscountPct = 0;
    $promoCodeApplied = '';
    if (!empty($order['promo_code'])) {
        try {
            $ppStmt = $pdo->prepare("SELECT discount_percent FROM promo_codes WHERE UPPER(code) = UPPER(?) LIMIT 1");
            $ppStmt->execute([$order['promo_code']]);
            $promoDiscountPct = (int)($ppStmt->fetchColumn() ?: 0);
            $promoCodeApplied = $order['promo_code'];
        } catch (Throwable $e) {}
    }

    $priceInfo = [];
    try {
        // Мультивыбор услуг: суммируем цены всех выбранных (для заказа с
        // одной услугой — тот же результат, что и раньше).
        $svcListAccept = getOrderServicesList($pdo, $order);
        $priceInfo = [
            'title'     => getOrderServiceTitle($pdo, $order),
            'price_rub' => array_sum(array_map(fn($s) => (float)($s['price_rub'] ?? 0), $svcListAccept)),
            'price_uan' => array_sum(array_map(fn($s) => (float)($s['price_uan'] ?? 0), $svcListAccept)),
        ];
    } catch (Throwable $e) {}

    $payText = paymentInstructionsText($orderId, array_merge($priceInfo, ['cooperation' => $order['cooperation'] ?? false]), !empty($order['cooperation']), $isUrgent, $promoDiscountPct, $promoCodeApplied);

    if ($chatId === '' || !is_numeric($chatId)) return;

    $payUrl = PUBLIC_SITE_URL . 'profile.php?order=' . $orderId;
    $keyboard = paymentKeyboard($orderId, $payUrl);
    $photoPath = __DIR__ . '/../assets/notify/pay.jpg';

    if (is_file($photoPath)) {
        $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendPhoto');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => [
                'chat_id' => $chatId, 'caption' => $payText, 'parse_mode' => 'HTML',
                'photo' => new CURLFile($photoPath), 'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
            ],
        ]);
        curl_exec($ch); curl_close($ch);
    } else {
        $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => [
                'chat_id' => $chatId, 'text' => $payText, 'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
            ],
        ]);
        curl_exec($ch); curl_close($ch);
    }
    try { addOrderMessage($pdo, $orderId, 'admin', 'Заказ принят. Клиенту отправлены реквизиты.'); } catch (Throwable $e) {}
}

// ── Админские POST действия над заказом ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['order_action'])) {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $action  = trim($_POST['order_action']);

    if ($orderId > 0) {
        try {
            $msgAdmin = '';
            if ($action === 'accept_normal') {
                // Точная копия "✅ Обычный (5 сут.)" из Telegram — раньше кнопка
                // "Принять" в веб-панели сразу ставила "в работе" МИНУЯ оплату.
                adminAcceptWithPayment($pdo, $orderId, false);
                $msgAdmin = "✅ Заказ #{$orderId} принят, клиенту отправлены реквизиты на оплату.";
            } elseif ($action === 'accept_urgent') {
                adminAcceptWithPayment($pdo, $orderId, true);
                $msgAdmin = "⚡ Заказ #{$orderId} принят как срочный, клиенту отправлены реквизиты (+50%).";
            } elseif ($action === 'accept_queue') {
                // Точная копия "📥 Просто в очередь" из Telegram — сразу в работу,
                // минуя оплату (для бартера/договорённостей без денег).
                $deadline = calculateOrderDeadline(false);
                $pdo->prepare("UPDATE orders SET status = 'in_progress', payment_status = 'skipped', accepted_at = NOW(), started_at = NOW(), deadline = ? WHERE id = ?")
                    ->execute([$deadline, $orderId]);
                notifyClientOrderStatus($pdo, $orderId, 'in_progress');
                $msgAdmin = "📥 Заказ #{$orderId} принят в очередь, минуя оплату.";
            } elseif ($action === 'take_work') {
                $pdo->prepare("UPDATE orders SET status = 'in_progress' WHERE id = ?")->execute([$orderId]);
                setOrderDeadline($pdo, $orderId, false);
                notifyClientOrderStatus($pdo, $orderId, 'in_progress');
                $msgAdmin = "🚀 Заказ #{$orderId} взят в работу.";
            } elseif ($action === 'urgent') {
                $pdo->prepare("UPDATE orders SET status = 'urgent' WHERE id = ?")->execute([$orderId]);
                setOrderDeadline($pdo, $orderId, true);
                notifyClientOrderStatus($pdo, $orderId, 'urgent');
                $msgAdmin = "⚡️ Заказ #{$orderId} помечен как срочный.";
            } elseif ($action === 'status') {
                $payMethod   = $_POST['pay_method']   ?? 'other';
                $paidAmount  = (float)($_POST['paid_amount'] ?? 0);
                $paidCurrency= in_array($_POST['paid_currency'] ?? '', ['RUB','USD','UAH']) ? $_POST['paid_currency'] : 'RUB';
                $pdo->prepare("UPDATE orders SET status='ready', payment_method=?, paid_amount=?, paid_currency=? WHERE id=?")
                    ->execute([$payMethod, $paidAmount, $paidCurrency, $orderId]);

                // Файл готовой работы (п.1 ТЗ) — если приложен, уходит клиенту
                // sendDocument'ом (без сжатия) с кнопками "Принять"/"На правку"
                // ВМЕСТО обычного текстового уведомления о статусе. Если файл
                // не выбрали — как раньше, просто текст/декоративная картинка.
                $workDelivered = false;
                if (!empty($_FILES['work_file']['name']) && ($_FILES['work_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $origName = basename((string)$_FILES['work_file']['name']);
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $ext = preg_match('/^[a-z0-9]{1,10}$/', $ext) ? $ext : 'bin';
                    $storedName = 'work_' . $orderId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $workDir = __DIR__ . '/../uploads/orders/';
                    if (!is_dir($workDir)) @mkdir($workDir, 0777, true);
                    $destPath = $workDir . $storedName;
                    if (is_writable($workDir) && move_uploaded_file($_FILES['work_file']['tmp_name'], $destPath)) {
                        $workDelivered = deliverWorkFileToClient($pdo, TELEGRAM_BOT_TOKEN, $orderId, $destPath, $storedName, $origName);
                    }
                }
                if (!$workDelivered) {
                    notifyClientOrderStatus($pdo, $orderId, 'ready');
                }

                // Уведомление себе с деталями оплаты
                $_payLabels = ['donation' => '💳 Донейшен', 'crypto' => '₿ Крипта', 'monobank' => '🏦 Монобанк', 'other' => '💰 Другое'];
                $_payLabel  = $_payLabels[$payMethod] ?? $payMethod;
                $_currSymbols = ['RUB' => '₽', 'USD' => '$', 'UAH' => '₴'];
                $_sym = $_currSymbols[$paidCurrency] ?? $paidCurrency;
                $_adminTg = getenv('ADMIN_ID') ?: '1710365896';
                $_tok = getenv('BOT_TOKEN') ?: getenv('TELEGRAM_BOT_TOKEN') ?: '8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg';
                $_ch = curl_init("https://api.telegram.org/bot{$_tok}/sendMessage");
                curl_setopt_array($_ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
                    CURLOPT_POSTFIELDS => ['chat_id' => $_adminTg, 'parse_mode' => 'HTML',
                        'text' => "✅ <b>Заказ #{$orderId} выполнен</b>

{$_payLabel}
💰 Получено: <b>{$_sym}" . number_format($paidAmount, 2, '.', ' ') . "</b>"]]);
                curl_exec($_ch); curl_close($_ch);
                $msgAdmin = $workDelivered
                    ? "✅ Заказ #{$orderId} отмечен как готов, файл отправлен клиенту в Telegram."
                    : "✅ Заказ #{$orderId} отмечен как готов.";
            } elseif ($action === 'redeliver_work') {
                // Пересдача после правки (п.2 ТЗ) — платёж уже был записан
                // раньше, тут только новый файл + новое сообщение с кнопками.
                if (!empty($_FILES['work_file']['name']) && ($_FILES['work_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $origName = basename((string)$_FILES['work_file']['name']);
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $ext = preg_match('/^[a-z0-9]{1,10}$/', $ext) ? $ext : 'bin';
                    $storedName = 'work_' . $orderId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $workDir = __DIR__ . '/../uploads/orders/';
                    if (!is_dir($workDir)) @mkdir($workDir, 0777, true);
                    $destPath = $workDir . $storedName;
                    if (is_writable($workDir) && move_uploaded_file($_FILES['work_file']['tmp_name'], $destPath)) {
                        if (deliverWorkFileToClient($pdo, TELEGRAM_BOT_TOKEN, $orderId, $destPath, $storedName, $origName)) {
                            $pdo->prepare("UPDATE orders SET revision_count = revision_count + 1 WHERE id = ?")->execute([$orderId]);
                            $msgAdmin = "📤 Заказ #{$orderId}: исправленный файл отправлен клиенту.";
                        } else {
                            $message = '❌ Не удалось отправить файл клиенту в Telegram (проверь, привязан ли у него бот).';
                        }
                    } else {
                        $message = '❌ Не удалось сохранить файл на сервере.';
                    }
                } else {
                    $message = '❌ Выбери файл для пересдачи.';
                }
            } elseif ($action === 'cooperation') {
                $deadline = calculateOrderDeadline(false);
                $pdo->prepare("UPDATE orders SET cooperation = TRUE, status = 'in_progress', payment_status = 'skipped', accepted_at = NOW(), started_at = NOW(), deadline = ? WHERE id = ?")
                    ->execute([$deadline, $orderId]);
                notifyClientOrderStatus($pdo, $orderId, 'cooperation');
                $msgAdmin = "🤝 Заказ #{$orderId} отмечен как сотрудничество.";
            } elseif ($action === 'decline') {
                $pdo->prepare("UPDATE orders SET status = 'declined' WHERE id = ?")->execute([$orderId]);
                notifyClientOrderStatus($pdo, $orderId, 'declined');
                $msgAdmin = "❌ Заказ #{$orderId} отклонён.";
            } elseif ($action === 'ban') {
                $r = $pdo->prepare("SELECT telegram, client_ip FROM orders WHERE id = ? LIMIT 1");
                $r->execute([$orderId]);
                $ord = $r->fetch(PDO::FETCH_ASSOC) ?: [];
                $tg  = $ord['telegram'] ?? '';
                $ip  = $ord['client_ip'] ?? null;
                if ($tg !== '') {
                    $ins = $pdo->prepare("INSERT INTO blacklist (telegram, ip, reason, created_at) VALUES (?, ?, ?, NOW())");
                    $ins->execute([$tg, $ip, 'admin_ban']);
                }
                $msgAdmin = "🚫 Клиент заказа #{$orderId} добавлен в чёрный список.";
            } elseif ($action === 'message_client') {
                $text = trim($_POST['message_text'] ?? '');
                $stmt = $pdo->prepare("SELECT client_chat_id, telegram FROM orders WHERE id = ? LIMIT 1");
                $stmt->execute([$orderId]);
                $row  = $stmt->fetch(PDO::FETCH_ASSOC);
                $chat = trim((string)($row['client_chat_id'] ?? ''));
                if ($chat === '' || !is_numeric($chat)) {
                    try {
                        $sess = $pdo->prepare("SELECT session_id FROM orders WHERE id = ? LIMIT 1");
                        $sess->execute([$orderId]);
                        $sessRow = $sess->fetch(PDO::FETCH_ASSOC);
                        if (!empty($sessRow['session_id'])) {
                            $lnkQ = $pdo->prepare("SELECT NULLIF(CAST(tg_id AS VARCHAR),'') AS chat_id FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1");
                            $lnkQ->execute([$sessRow['session_id']]);
                            $lnkR = $lnkQ->fetch(PDO::FETCH_ASSOC);
                            if (!empty($lnkR['chat_id']) && is_numeric($lnkR['chat_id'])) {
                                $chat = $lnkR['chat_id'];
                            }
                        }
                    } catch (Throwable $e) {}
                }
                if (!empty($chat) && is_numeric($chat) && $text !== '') {
                    $res = sendTelegramRequest('sendMessage', ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML']);
                    $msgAdmin = ($res && ($res['ok'] ?? false))
                        ? "✉️ Сообщение отправлено клиенту (chat: {$chat})."
                        : "⚠️ Не удалось отправить (chat: {$chat}). Клиент не писал боту.";
                } else {
                    $msgAdmin = "⚠️ Невозможно отправить: клиент не привязал Telegram к боту.";
                }
            } elseif ($action === 'delete_order') {
                // Удаление заказа целиком из БД — безвозвратно, поэтому кнопка
                // на фронте всегда спрашивает подтверждение перед отправкой
                // (см. confirm() на кнопке "Удалить заказ" в карточке заказа).
                try { $pdo->prepare("DELETE FROM order_messages WHERE order_id = ?")->execute([$orderId]); } catch (Throwable $e) {}
                try { $pdo->prepare("DELETE FROM appeals_messages WHERE appeal_id IN (SELECT id FROM appeals WHERE order_id = ?)")->execute([$orderId]); } catch (Throwable $e) {}
                try { $pdo->prepare("DELETE FROM appeals WHERE order_id = ?")->execute([$orderId]); } catch (Throwable $e) {}
                $pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$orderId]);
                $msgAdmin = "🗑 Заказ #{$orderId} удалён из базы.";
            }

            if (!empty($msgAdmin)) {
                if (defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN !== '' && defined('ADMIN_TELEGRAM_ID') && ADMIN_TELEGRAM_ID !== '') {
                    sendTelegramRequest('sendMessage', ['chat_id' => ADMIN_TELEGRAM_ID, 'text' => $msgAdmin, 'parse_mode' => 'Markdown']);
                }
            }
        } catch (\Throwable $e) {
            $message = 'Ошибка выполнения действия: ' . $e->getMessage();
        }
    }

    if ($action === 'delete_order') {
        // После удаления не пытаемся заново открыть уже несуществующий
        // заказ по view_order= — уводим на список заказов.
        $redirectBase = strtok($_SERVER['REQUEST_URI'], '?');
        header('Location: ' . $redirectBase . '?deleted=1'); exit;
    }

    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}

// ── Обработка массовой рассылки ──
if (isset($_POST['mass_broadcast'])) {
    $text   = trim($_POST['broadcast_text'] ?? '');
    $photos = [];
    
    if (!empty($_FILES['broadcast_photos']['name'][0])) {
        foreach ($_FILES['broadcast_photos']['tmp_name'] as $i => $tmp) {
            if ($i >= 5) break;
            if (!empty($tmp)) {
                $url = uploadToImgBB($tmp, 'promo_' . $i);
                if ($url) $photos[] = $url;
            }
        }
    }

    if (($text !== '' || !empty($photos)) && TELEGRAM_BOT_TOKEN !== '') {
        $chatIds = $pdo->query("SELECT DISTINCT client_chat_id FROM orders WHERE client_chat_id IS NOT NULL AND client_chat_id != ''")->fetchAll(PDO::FETCH_COLUMN);
        $sent = 0;
        foreach (array_unique($chatIds) as $cid) {
            if (!is_numeric($cid)) continue;
            
            if (!empty($photos)) {
                if (count($photos) === 1) {
                    sendTelegramRequest('sendPhoto', ['chat_id' => $cid, 'photo' => $photos[0], 'caption' => $text, 'parse_mode' => 'HTML']);
                } else {
                    $media = [];
                    foreach ($photos as $idx => $url) {
                        $item = ['type' => 'photo', 'media' => $url];
                        if ($idx === 0) { $item['caption'] = $text; $item['parse_mode'] = 'HTML'; }
                        $media[] = $item;
                    }
                    sendTelegramRequest('sendMediaGroup', ['chat_id' => $cid, 'media' => json_encode($media)]);
                }
            } else {
                sendTelegramRequest('sendMessage', ['chat_id' => $cid, 'text' => $text, 'parse_mode' => 'HTML']);
            }
            $sent++;
            usleep(50000);
        }
        $message = "✅ Рассылка завершена. Доставлено: {$sent} чел.";
    }
}

function sendTelegramRequest(string $method, array $params, array $files = []): ?array
{
    global $telegramLastError;
    $telegramLastError = '';

    if (TELEGRAM_BOT_TOKEN === '') {
        $telegramLastError = 'не задан токен бота';
        return null;
    }

    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, empty($files) ? http_build_query($params) : array_merge($params, $files));
    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $response === '') {
        $telegramLastError = $curlError !== '' ? $curlError : 'пустой ответ Telegram API';
        return null;
    }

    $data = json_decode($response, true);
    if (!($data['ok'] ?? false)) {
        $telegramLastError = (string)($data['description'] ?? 'Telegram API вернул ошибку');
    }
    return $data;
}

function notifyAdminNewAppeal(array $ap): void
{
    if (TELEGRAM_BOT_TOKEN === '' || ADMIN_TELEGRAM_ID === '') return;
    $adminUrl = PUBLIC_SITE_URL . 'admin/index.php';
    $text = "📩 <b>Новое обращение!</b>\n\n"
        . "👤 Клиент: <b>" . htmlspecialchars($ap['username'] ?? '') . "</b>\n"
        . "📋 Заказ: <b>#" . (int)($ap['order_id'] ?? 0) . "</b>\n"
        . "📌 Тема: <b>" . htmlspecialchars($ap['subject'] ?? '') . "</b>\n\n"
        . "💬 <i>" . htmlspecialchars(mb_substr($ap['message'] ?? '', 0, 300)) . (mb_strlen($ap['message'] ?? '') > 300 ? '...' : '') . "</i>\n\n"
        . "🔗 <a href=\"" . $adminUrl . "\">Открыть админ-панель</a>";

    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => ['chat_id' => ADMIN_TELEGRAM_ID, 'text' => $text, 'parse_mode' => 'HTML']]);
    curl_exec($ch); curl_close($ch);
}

function defaultPortfolioCategories(): array
{
    return [
        ['preview', 'Превью', 1920, 1080, 0, 10],
        ['youtube_design', 'Оформление для YouTube', 1920, 768, 1, 20],
        ['vk_design', 'Оформление для VK', 1920, 768, 1, 30],
        ['banner', 'Баннеры', 1000, 1200, 0, 40],
        ['avatar', 'Аватарки', 1000, 1000, 0, 50],
    ];
}

function ensureDefaultPortfolioCategories(PDO $pdo): void
{
    $stmt = $pdo->prepare("
        INSERT INTO portfolio_categories (category_key, title, width_px, height_px, is_design, sort_order)
        VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT (category_key) DO UPDATE SET
            title = EXCLUDED.title, width_px = EXCLUDED.width_px, height_px = EXCLUDED.height_px,
            is_design = EXCLUDED.is_design, sort_order = EXCLUDED.sort_order
    ");
    foreach (defaultPortfolioCategories() as $category) { $stmt->execute($category); }
}

function imageFromFile(string $path)
{
    $info = @getimagesize($path);
    if (!$info) return null;
    return match ($info[2]) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => imagecreatefrompng($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : null,
        IMAGETYPE_GIF  => imagecreatefromgif($path),
        default        => null,
    };
}

function gdFontPath(bool $regular = false): string
{
    $paths = $regular
        ? [__DIR__ . '/../assets/fonts/GoogleSans-Regular.ttf', __DIR__ . '/../assets/fonts/Montserrat-Regular.ttf',
           '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf']
        : [__DIR__ . '/../assets/fonts/GoogleSans-Bold.ttf', __DIR__ . '/../assets/fonts/Montserrat-Bold.ttf',
           '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'];
    foreach ($paths as $path) { if (is_file($path)) return $path; }
    return '';
}

function channelTemplatePath(): string
{
    $paths = [__DIR__ . '/../uploads/channel_template.png', __DIR__ . '/../uploads/channel-template.png',
              __DIR__ . '/../assets/channel_template.png'];
    foreach ($paths as $path) { if (is_file($path)) return $path; }
    return '';
}

function drawFilledRoundedRect($img, int $x, int $y, int $w, int $h, int $radius, int $color): void
{
    imagefilledrectangle($img, $x + $radius, $y, $x + $w - $radius, $y + $h, $color);
    imagefilledrectangle($img, $x, $y + $radius, $x + $w, $y + $h - $radius, $color);
    imagefilledellipse($img, $x + $radius, $y + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x + $w - $radius, $y + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x + $radius, $y + $h - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x + $w - $radius, $y + $h - $radius, $radius * 2, $radius * 2, $color);
}

function drawTextFit($img, string $text, int $x, int $y, int $maxW, int $size, int $color, string $font, int $minSize = 20): void
{
    $text = trim($text);
    if ($text === '') return;
    if ($font !== '' && function_exists('imagettftext')) {
        while ($size > $minSize) {
            $box = imagettfbbox($size, 0, $font, $text);
            if (($box[2] - $box[0]) <= $maxW) break;
            $size -= 2;
        }
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
        return;
    }
    $fontId = 5;
    $sourceW = max(1, imagefontwidth($fontId) * strlen($text));
    $sourceH = max(1, imagefontheight($fontId));
    $targetH = max(18, $size);
    $targetW = (int)round($sourceW * ($targetH / $sourceH));
    if ($targetW > $maxW) { $targetW = $maxW; $targetH = (int)round($sourceH * ($targetW / $sourceW)); }
    $tmp = imagecreatetruecolor($sourceW, $sourceH);
    imagealphablending($tmp, false); imagesavealpha($tmp, true);
    $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
    imagefill($tmp, 0, 0, $transparent);
    imagestring($tmp, $fontId, 0, 0, $text, $color);
    imagecopyresampled($img, $tmp, $x, $y - $targetH, 0, 0, $targetW, $targetH, $sourceW, $sourceH);
    imagedestroy($tmp);
}

function drawTextCenteredFit($img, string $text, int $centerX, int $y, int $maxW, int $size, int $color, string $font, int $minSize = 20): void
{
    $text = trim($text);
    if ($text === '') return;
    if ($font !== '' && function_exists('imagettftext')) {
        while ($size > $minSize) {
            $box = imagettfbbox($size, 0, $font, $text);
            if (($box[2] - $box[0]) <= $maxW) break;
            $size -= 2;
        }
        $box = imagettfbbox($size, 0, $font, $text);
        $textW = $box[2] - $box[0];
        imagettftext($img, $size, 0, (int)round($centerX - ($textW / 2)), $y, $color, $font, $text);
        return;
    }
    $fontId = 5;
    $sourceW = max(1, imagefontwidth($fontId) * strlen($text));
    $sourceH = max(1, imagefontheight($fontId));
    $targetH = max(18, $size);
    $targetW = (int)round($sourceW * ($targetH / $sourceH));
    if ($targetW > $maxW) { $targetW = $maxW; $targetH = (int)round($sourceH * ($targetW / $sourceW)); }
    $tmp = imagecreatetruecolor($sourceW, $sourceH);
    imagealphablending($tmp, false); imagesavealpha($tmp, true);
    $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
    imagefill($tmp, 0, 0, $transparent);
    imagestring($tmp, $fontId, 0, 0, $text, $color);
    imagecopyresampled($img, $tmp, (int)round($centerX - ($targetW / 2)), $y - $targetH, 0, 0, $targetW, $targetH, $sourceW, $sourceH);
    imagedestroy($tmp);
}

function copyImageCover($dst, $src, int $dx, int $dy, int $dw, int $dh): void
{
    $sw = imagesx($src); $sh = imagesy($src);
    if ($sw <= 0 || $sh <= 0 || $dw <= 0 || $dh <= 0) return;
    $srcRatio = $sw / $sh; $dstRatio = $dw / $dh;
    if ($srcRatio > $dstRatio) { $cropH = $sh; $cropW = (int)round($sh * $dstRatio); $sx = (int)round(($sw - $cropW) / 2); $sy = 0; }
    else { $cropW = $sw; $cropH = (int)round($sw / $dstRatio); $sx = 0; $sy = (int)round(($sh - $cropH) / 2); }
    imagecopyresampled($dst, $src, $dx, $dy, $sx, $sy, $dw, $dh, $cropW, $cropH);
}

function copyImageContain($dst, $src, int $dx, int $dy, int $dw, int $dh): void
{
    $sw = imagesx($src); $sh = imagesy($src);
    if ($sw <= 0 || $sh <= 0 || $dw <= 0 || $dh <= 0) return;
    $scale = min($dw / $sw, $dh / $sh);
    $drawW = (int)round($sw * $scale); $drawH = (int)round($sh * $scale);
    $drawX = $dx + (int)round(($dw - $drawW) / 2); $drawY = $dy + (int)round(($dh - $drawH) / 2);
    imagecopyresampled($dst, $src, $drawX, $drawY, 0, 0, $drawW, $drawH, $sw, $sh);
}

function applyRoundedCorners($img, int $radius): void
{
    $w = imagesx($img); $h = imagesy($img);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $inCorner = false; $cx = $x; $cy = $y;
            if ($x < $radius && $y < $radius) { $cx = $radius; $cy = $radius; $inCorner = true; }
            elseif ($x >= $w - $radius && $y < $radius) { $cx = $w - $radius - 1; $cy = $radius; $inCorner = true; }
            elseif ($x < $radius && $y >= $h - $radius) { $cx = $radius; $cy = $h - $radius - 1; $inCorner = true; }
            elseif ($x >= $w - $radius && $y >= $h - $radius) { $cx = $w - $radius - 1; $cy = $h - $radius - 1; $inCorner = true; }
            if ($inCorner) { $dx = $x - $cx; $dy = $y - $cy; if (($dx * $dx + $dy * $dy) > ($radius * $radius)) imagesetpixel($img, $x, $y, $transparent); }
        }
    }
}

function drawCircularImage($dst, $src, int $x, int $y, int $size): void
{
    $avatar = imagecreatetruecolor($size, $size);
    imagealphablending($avatar, false); imagesavealpha($avatar, true);
    $transparent = imagecolorallocatealpha($avatar, 0, 0, 0, 127);
    imagefill($avatar, 0, 0, $transparent);
    imagecopyresampled($avatar, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
    $radius = $size / 2;
    for ($py = 0; $py < $size; $py++) {
        for ($px = 0; $px < $size; $px++) {
            $ddx = $px - $radius; $ddy = $py - $radius;
            if (($ddx * $ddx + $ddy * $ddy) <= ($radius * $radius)) imagesetpixel($dst, $x + $px, $y + $py, imagecolorat($avatar, $px, $py));
        }
    }
    imagedestroy($avatar);
}

function createWatermarkedImage(string $mainPath, string $avatarPath, string $title = '', int $priceRub = 0, int $priceUan = 0, array $category = []): string
{
    if (!extension_loaded('gd') || !is_file($mainPath)) return $mainPath;
    $main = imageFromFile($mainPath);
    if (!$main) return $mainPath;
    $avatar = (is_file($avatarPath)) ? imageFromFile($avatarPath) : null;
    $mainW = max(1, imagesx($main)); $mainH = max(1, imagesy($main));
    $catW = (int)($category['width_px'] ?? 0); $catH = (int)($category['height_px'] ?? 0);
    $isDesign = !empty($category['is_design']);
    if ($catW <= 0 || $catH <= 0) { $catW = $mainW; $catH = $mainH; }
    $outW = $catW; $outH = $catH;
    $scale = 2; $canvasW = $outW * $scale; $canvasH = $outH * $scale;
    $canvas = imagecreatetruecolor($canvasW, $canvasH);
    imagealphablending($canvas, true); imagesavealpha($canvas, true);
    $template = channelTemplatePath();
    $templateImg = $template !== '' ? imageFromFile($template) : null;
    if ($templateImg) { copyImageCover($canvas, $templateImg, 0, 0, $canvasW, $canvasH); imagedestroy($templateImg); }
    else {
        for ($y = 0; $y < $canvasH; $y++) {
            $mix = $y / $canvasH; $r = (int)(10 + 34 * $mix); $g = (int)(10 + 12 * $mix); $b = (int)(14 + 4 * $mix);
            imageline($canvas, 0, $y, $canvasW, $y, imagecolorallocate($canvas, $r, $g, $b));
        }
        $glow = imagecolorallocatealpha($canvas, 249, 115, 22, 105);
        imagefilledellipse($canvas, (int)($canvasW * .86), (int)($canvasH * .18), (int)($canvasW * .70), (int)($canvasH * .45), $glow);
        imagefilledellipse($canvas, (int)($canvasW * .15), (int)($canvasH * .92), (int)($canvasW * .55), (int)($canvasH * .35), $glow);
    }
    $padding = (int)round(min($canvasW, $canvasH) * 0.055);
    $avatarSize = $avatar ? (int)round(min($canvasW, $canvasH) * 0.12) : 0;
    $brandH = $avatar ? (int)round($avatarSize * 1.45) : 0;
    $gap = $avatar ? (int)round(min($canvasW, $canvasH) * 0.026) : 0;
    $availableW = $canvasW - ($padding * 2);
    $availableH = $canvasH - ($padding * 2) - $brandH - $gap;
    if ($availableH < (int)round($canvasH * .48)) $availableH = (int)round($canvasH * .48);
    $frameScale = min($availableW / $catW, $availableH / $catH);
    $panelW = (int)round($catW * $frameScale); $panelH = (int)round($catH * $frameScale);
    $panelX = (int)round(($canvasW - $panelW) / 2); $panelY = $padding + (int)round(($availableH - $panelH) / 2);
    $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 78);
    drawFilledRoundedRect($canvas, $panelX + (8 * $scale), $panelY + (10 * $scale), $panelW, $panelH, 34 * $scale, $shadow);
    $panel = imagecreatetruecolor($panelW, $panelH);
    imagealphablending($panel, true); imagesavealpha($panel, true);
    $transparent = imagecolorallocatealpha($panel, 0, 0, 0, 127);
    imagefill($panel, 0, 0, $transparent);
    copyImageContain($panel, $main, 0, 0, $panelW, $panelH);
    applyRoundedCorners($panel, 26 * $scale);
    imagecopy($canvas, $panel, $panelX, $panelY, 0, 0, $panelW, $panelH);
    imagedestroy($panel);
    $line = imagecolorallocatealpha($canvas, 255, 255, 255, 34);
    imagesetthickness($canvas, max(1, 2 * $scale));
    imagerectangle($canvas, $panelX, $panelY, $panelX + $panelW, $panelY + $panelH, $line);
    if ($avatar && $isDesign) {
        $avatarSize = (int)round(min($panelW, $panelH) * 0.26);
        $avatarSize = max(80 * $scale, min($avatarSize, 190 * $scale));
        $avatarPad = (int)round($avatarSize * 0.18);
        $blockW = $avatarSize + ($avatarPad * 2); $blockH = $avatarSize + ($avatarPad * 2);
        $blockX = $panelX + $panelW - $blockW - (int)round($panelW * 0.035);
        $blockY = $panelY + $panelH - $blockH - (int)round($panelH * 0.055);
        $blockBg = imagecolorallocatealpha($canvas, 0, 0, 0, 24);
        drawFilledRoundedRect($canvas, $blockX, $blockY, $blockW, $blockH, 24 * $scale, $blockBg);
        drawCircularImage($canvas, $avatar, $blockX + $avatarPad, $blockY + $avatarPad, $avatarSize);
        imagedestroy($avatar);
    } elseif ($avatar) {
        $blockW = (int)round($avatarSize * 1.6); $blockH = (int)round($avatarSize * 1.25);
        $blockX = (int)round(($canvasW - $blockW) / 2); $blockY = $panelY + $panelH + $gap;
        $blockBg = imagecolorallocatealpha($canvas, 0, 0, 0, 22);
        drawFilledRoundedRect($canvas, $blockX, $blockY, $blockW, $blockH, 24 * $scale, $blockBg);
        drawCircularImage($canvas, $avatar, (int)round(($canvasW - $avatarSize) / 2), $blockY + (int)round(($blockH - $avatarSize) / 2), $avatarSize);
        imagedestroy($avatar);
    }
    $final = imagecreatetruecolor($outW, $outH);
    imagecopyresampled($final, $canvas, 0, 0, 0, 0, $outW, $outH, $canvasW, $canvasH);
    $output = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'portfolio_channel_' . uniqid('', true) . '.jpg';
    imagejpeg($final, $output, 100);
    imagedestroy($main); imagedestroy($canvas); imagedestroy($final);
    return $output;
}

function downloadToTemp(string $url): string
{
    if ($url === '') return '';
    $tmp = tempnam(sys_get_temp_dir(), 'imgdl_') . '.jpg';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20]);
    $data = curl_exec($ch); curl_close($ch);
    if ($data && file_put_contents($tmp, $data)) return $tmp;
    return '';
}

function publishPortfolioToChannel(PDO $pdo, string $uploadDir, array $case): bool
{
    global $telegramLastError;
    $imgVal = (string)($case['image'] ?? '');
    if (str_starts_with($imgVal, 'http://') || str_starts_with($imgVal, 'https://')) { $mainPath = downloadToTemp($imgVal); $downloaded = true; }
    else { $mainPath = $uploadDir . basename($imgVal); $downloaded = false; }
    if (!is_file($mainPath)) return false;
    $rub = (int)($case['price_rub'] ?? 0); $uan = (int)($case['price_uan'] ?? 0);
    $category = [];
    try {
        $catKey = (string)($case['category_key'] ?? '');
        if ($catKey !== '') {
            $stmt = $pdo->prepare('SELECT width_px, height_px, is_design FROM portfolio_categories WHERE category_key = ? LIMIT 1');
            $stmt->execute([$catKey]); $category = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (\Throwable $e) {}
    $avatarVal = (string)($case['avatar_image'] ?? '');
    try {
        if ($avatarVal === '' && empty($category['is_design'])) {
            $stmt = $pdo->query('SELECT avatar FROM users LIMIT 1');
            $avatarVal = (string)($stmt->fetchColumn() ?: '');
        }
    } catch (\Throwable $e) {}
    if (str_starts_with($avatarVal, 'http://') || str_starts_with($avatarVal, 'https://')) { $avatarPath = downloadToTemp($avatarVal); $avatarDownloaded = true; }
    else { $avatarPath = $avatarVal !== '' ? $uploadDir . basename($avatarVal) : ''; $avatarDownloaded = false; }
    $photoPath = createWatermarkedImage($mainPath, $avatarPath, (string)($case['title'] ?? ''), $rub, $uan, $category);
    $caption = "💰 Цена работы: {$rub}₽ | {$uan}₴\n\n💬 Оценить данную работу можно в комментариях.\n\n🚀 Заказать дизайн можно тут - <a href=\"" . htmlspecialchars(PUBLIC_SITE_URL, ENT_QUOTES, 'UTF-8') . "\">Kostlim Design</a>";
    $result = sendTelegramRequest('sendPhoto', ['chat_id' => PORTFOLIO_CHANNEL_CHAT, 'caption' => $caption, 'parse_mode' => 'HTML'], ['photo' => new CURLFile($photoPath)]);
    if ($photoPath !== $mainPath && is_file($photoPath)) unlink($photoPath);
    if ($downloaded && is_file($mainPath)) unlink($mainPath);
    if ($avatarDownloaded && $avatarPath !== '' && is_file($avatarPath)) unlink($avatarPath);
    return (bool)($result['ok'] ?? false);
}

function uploadToImgBB(string $tmpPath, string $name = 'image'): string
{
    global $pdo;
    if (!is_file($tmpPath)) { error_log("ImgBB: file not found ($tmpPath)"); return ''; }
    $keys = array_filter([
        getSetting($pdo, 'IMGBB_API_KEY', getenv('IMGBB_API_KEY') ?: ''),
        getSetting($pdo, 'IMGBB_API_KEY2', getenv('IMGBB_API_KEY2') ?: ''),
        getSetting($pdo, 'IMGBB_API_KEY3', getenv('IMGBB_API_KEY3') ?: ''),
    ]);
    if (empty($keys)) { error_log("ImgBB: no API keys set"); return ''; }
    $b64 = base64_encode(file_get_contents($tmpPath));
    foreach ($keys as $index => $apiKey) {
        $ch = curl_init('https://api.imgbb.com/1/upload');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_POSTFIELDS => ['key' => $apiKey, 'image' => $b64, 'name' => $name]]);
        $res = curl_exec($ch); $cerr = curl_error($ch); curl_close($ch);
        if ($res === false || $res === '') { continue; }
        $data = json_decode($res, true); $url = $data['data']['url'] ?? '';
        if ($url !== '') { return $url; }
    }
    return '';
}

function uploadImage(string $field, string $prefix, string $uploadDir): string
{
    global $message;
    $err = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE || empty($_FILES[$field]['name'])) return '';
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { $message = '❌ Файл слишком большой.'; return ''; }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES[$field]['tmp_name'])) return '';
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return '';
    $tmp = $_FILES[$field]['tmp_name'];
    $url = uploadToImgBB($tmp, $prefix . '_' . time());
    if ($url !== '') return $url;
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
    if (is_writable($uploadDir)) {
        $filename = $prefix . '_' . time() . '_' . uniqid() . '.' . $ext;
        $dest = $uploadDir . $filename;
        if (move_uploaded_file($tmp, $dest)) return $filename;
    }
    $message = '❌ Не удалось загрузить изображение. Проверь IMGBB_API_KEY.';
    return '';
}

function uploadNestedImage(string $field, int $id, string $prefix, string $uploadDir): string
{
    if (empty($_FILES[$field]['name'][$id]) || empty($_FILES[$field]['tmp_name'][$id])) return '';
    $err = $_FILES[$field]['error'][$id] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) return '';
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'][$id], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return '';
    $tmp = $_FILES[$field]['tmp_name'][$id];
    $url = uploadToImgBB($tmp, $prefix . '_' . time() . '_' . $id);
    if ($url !== '') return $url;
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
    if (is_writable($uploadDir)) {
        $filename = $prefix . '_' . time() . '_' . $id . '_' . uniqid() . '.' . $ext;
        $dest = $uploadDir . $filename;
        if (move_uploaded_file($tmp, $dest)) return $filename;
    }
    return '';
}

function money(int|float $value): string { return number_format((float)$value, 0, '.', ' '); }

function imgSrc(string $val, string $baseUrl = '../uploads/'): string
{
    if ($val === '') return '';
    if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) return $val;
    $siteRoot = rtrim(getenv('SITE_URL') ?: '', '/');
    // В admin используем относительный путь из папки admin/ если нет SITE_URL
    if ($siteRoot !== '') {
        // из admin baseUrl типа '../uploads/' — убираем ../ для абс. пути
        $cleanBase = ltrim(str_replace('../', '', $baseUrl), '/');
        return $siteRoot . '/' . $cleanBase . $val;
    }
    return $baseUrl . $val;
}

// ══════════════════════════════════════════════════════════════════
// Рендер карточка+drawer для категории / услуги / промокода — используются
// и при обычной отрисовке страницы, и как ответ AJAX-обработчиков ниже,
// чтобы новый элемент появлялся в сетке МГНОВЕННО, без перезагрузки.
// ══════════════════════════════════════════════════════════════════
function renderCategoryCardHtml(array $category): string
{
    global $pdo;
    $linkedPrice = ['price_rub' => 0, 'price_uan' => 0];
    try {
        $lp = $pdo->prepare("SELECT price_rub, price_uan FROM prices WHERE category_key = ? LIMIT 1");
        $lp->execute([$category['category_key']]);
        $row = $lp->fetch(PDO::FETCH_ASSOC);
        if ($row) $linkedPrice = $row;
    } catch (Throwable $e) {}
    $catId = (int)$category['id'];
    ob_start(); ?>
    <div class="item-card" onclick="openDrawer('drawer-cat-<?= $catId ?>')">
        <div class="item-card-media"><div class="no-media">📂</div></div>
        <div class="item-card-body">
            <div class="item-card-title"><?= htmlspecialchars($category['title']) ?></div>
            <div class="item-card-sub">🔑 <?= htmlspecialchars($category['category_key']) ?></div>
            <div class="item-card-foot">
                <span class="item-card-price"><?php if ((int)$category['width_px']>0 && (int)$category['height_px']>0): ?><?= (int)$category['width_px'] ?>×<?= (int)$category['height_px'] ?><?php else: ?>— px —<?php endif; ?></span>
                <?php if (!empty($category['is_design'])): ?><span class="item-card-badge">👤 С аватаркой</span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="edit-drawer" id="drawer-cat-<?= $catId ?>" onclick="event.stopPropagation()">
        <div class="edit-drawer-head"><h3><span class="ico">📂</span> Редактирование категории</h3><button type="button" class="edit-drawer-close" onclick="closeDrawers()">✕</button></div>
        <form method="POST">
            <input type="hidden" name="cat_id" value="<?= $catId ?>">
            <label><span class="ico">📝</span> Название категории</label>
            <input type="text" name="cat_title" value="<?= htmlspecialchars($category['title']) ?>">
            <label><span class="ico">🔑</span> Ключ категории</label>
            <input type="text" name="cat_key" value="<?= htmlspecialchars($category['category_key']) ?>" style="text-transform:lowercase;">
            <div class="avatar-hint">⚠️ Ключ используется для связи с прайсом и уже загруженными работами. При смене всё автоматически перепривяжется на новый ключ — но если где-то во внешнем коде (например, в боте) ключ жёстко прописан текстом, там его придётся поправить руками.</div>
            <div class="two-cols">
                <div><label><span class="ico">↔️</span> Ширина, px</label><input type="number" name="cat_width" value="<?= (int)$category['width_px'] ?>"></div>
                <div><label><span class="ico">↕️</span> Высота, px</label><input type="number" name="cat_height" value="<?= (int)$category['height_px'] ?>"></div>
            </div>
            <label class="tg-checkbox" style="margin-top:14px;"><input type="checkbox" name="cat_is_design" value="1" <?= !empty($category['is_design']) ? 'checked' : '' ?>> 👤 Это оформление с аватаркой</label>
            <hr class="divider">
            <div class="drawer-meta-row"><span>Цена (из прайса)</span><b><?= (int)$linkedPrice['price_rub'] ?> ₽ / <?= (int)$linkedPrice['price_uan'] ?> ₴</b></div>
            <div class="avatar-hint">Цену меняешь во вкладке «Прайс» — там она хранится и оттуда подтягивается сюда и в портфолио.</div>
            <button type="submit" name="update_portfolio_category" class="btn-panel">💾 Сохранить категорию</button>
        </form>
        <a class="drawer-danger" href="?delete_portfolio_category_id=<?= $catId ?>" onclick="return confirm('Удалить категорию?')">🗑 Удалить категорию</a>
    </div>
    <?php return (string)ob_get_clean();
}

function renderServiceCardHtml(array $service): string
{
    global $pdo;
    $id = (int)$service['id'];
    $allCats = [];
    try { $allCats = $pdo->query("SELECT category_key, title, width_px, height_px FROM portfolio_categories ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    ob_start(); ?>
    <div class="item-card" onclick="openDrawer('drawer-price-<?= $id ?>')">
        <div class="item-card-media">
            <?php if (!empty($service['image'])): ?><img src="<?= htmlspecialchars(imgSrc($service['image']??'')) ?>" alt=""><?php else: ?><div class="no-media">🎨</div><?php endif; ?>
        </div>
        <div class="item-card-body">
            <div class="item-card-title"><?= htmlspecialchars($service['title']??'') ?></div>
            <div class="item-card-sub">🔑 <?= htmlspecialchars($service['category_key']??'') ?></div>
            <div class="item-card-foot">
                <span class="item-card-price"><span class="ico">💰</span><?= money($service['price_rub']??0) ?> ₽ / <?= money($service['price_uan']??0) ?> ₴</span>
            </div>
        </div>
    </div>
    <div class="edit-drawer" id="drawer-price-<?= $id ?>" onclick="event.stopPropagation()">
        <div class="edit-drawer-head"><h3><span class="ico">✏️</span> Редактирование услуги</h3><button type="button" class="edit-drawer-close" onclick="closeDrawers()">✕</button></div>
        <?php if (!empty($service['image'])): ?><img src="<?= htmlspecialchars(imgSrc($service['image']??'')) ?>" class="drawer-preview" alt=""><?php endif; ?>
        <label><span class="ico">📝</span> Название услуги</label>
        <input type="text" name="prices[<?= $id ?>][title]" value="<?= htmlspecialchars($service['title']??'') ?>">
        <label><span class="ico">🔑</span> Категория портфолио</label>
        <select name="prices[<?= $id ?>][category_key]">
            <?php
                $svcCatKey = (string)($service['category_key'] ?? '');
                $catKeysList = array_column($allCats, 'category_key');
                if ($svcCatKey !== '' && !in_array($svcCatKey, $catKeysList, true)) {
                    // У услуги ключ, которого нет среди текущих категорий (сирота
                    // или категория была удалена) — обязательно даём реальный
                    // пункт с этим значением, иначе браузер по умолчанию
                    // выберет первую категорию в списке и её тихо перезапишет.
                    echo '<option value="' . htmlspecialchars($svcCatKey) . '" selected>⚠️ ' . htmlspecialchars($svcCatKey) . ' (нет такой категории)</option>';
                }
            ?>
            <?php foreach ($allCats as $c): $lbl = $c['title'] . (((int)$c['width_px']>0 && (int)$c['height_px']>0) ? " ({$c['width_px']}x{$c['height_px']})" : ''); ?>
                <option value="<?= htmlspecialchars($c['category_key']) ?>" <?= $c['category_key'] === $svcCatKey ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="avatar-hint">Меняешь категорию — эта услуга (и её цена) привяжется к другой категории портфолио.</div>
        <label><span class="ico">📄</span> Описание</label>
        <textarea name="prices[<?= $id ?>][description]"><?= htmlspecialchars($service['description']??'') ?></textarea>
        <label><span class="ico">⚡</span> Фичи</label>
        <input type="text" name="prices[<?= $id ?>][features]" value="<?= htmlspecialchars($service['features']??'') ?>" placeholder="Через | например: PSD-файл|2 правки">
        <div class="two-cols">
            <div><label><span class="ico">💰</span> Цена ₽</label><input type="number" name="prices[<?= $id ?>][price_rub]" value="<?= htmlspecialchars($service['price_rub']??'0') ?>"></div>
            <div><label><span class="ico">💵</span> Цена ₴</label><input type="number" name="prices[<?= $id ?>][price_uan]" value="<?= htmlspecialchars($service['price_uan']??'0') ?>"></div>
        </div>
        <hr class="divider">
        <label><span class="ico">🖼</span> Заменить обложку</label>
        <input type="file" name="price_images[<?= $id ?>]" accept="image/*">
        <div class="avatar-hint">Кнопка ниже сохраняет изменения по всем услугам сразу.</div>
        <button type="submit" name="save_all_prices" class="btn-panel" style="background:linear-gradient(135deg,#4ade80,#22c55e);box-shadow:0 8px 24px rgba(34,197,94,.3);">💾 Сохранить прайс</button>
        <a class="drawer-danger" href="?delete_price_id=<?= $id ?>" onclick="return confirm('Удалить услугу?')">🗑 Удалить услугу</a>
    </div>
    <?php return (string)ob_get_clean();
}

function renderPromoCardHtml(array $pc): string
{
    $pcExpired = !empty($pc['expires_at']) && strtotime($pc['expires_at']) < time();
    ob_start(); ?>
    <div class="item-card" onclick="openDrawer('drawer-promo-<?= (int)$pc['id'] ?>')" style="<?= $pc['active'] ? '' : 'opacity:.5;' ?>">
        <div class="item-card-media"><div class="no-media">🎟</div></div>
        <div class="item-card-body">
            <div class="item-card-title"><?= htmlspecialchars($pc['code']) ?></div>
            <div class="item-card-sub"><?= htmlspecialchars($pc['bonus_text'] ?: 'без бонуса') ?></div>
            <div class="item-card-foot">
                <span class="item-card-price"><?php if ((int)($pc['discount_percent'] ?? 0) > 0): ?>−<?= (int)$pc['discount_percent'] ?>%<?php else: ?>—<?php endif; ?></span>
                <span class="item-card-badge <?= $pc['active'] ? '' : 'off' ?>"><?= $pc['active'] ? '🟢 Активен' : '⚪ Выключен' ?></span>
            </div>
        </div>
    </div>
    <div class="edit-drawer" id="drawer-promo-<?= (int)$pc['id'] ?>" onclick="event.stopPropagation()">
        <div class="edit-drawer-head"><h3><span class="ico">🎟</span> <?= htmlspecialchars($pc['code']) ?></h3><button type="button" class="edit-drawer-close" onclick="closeDrawers()">✕</button></div>
        <div class="drawer-meta-row"><span>Скидка</span><b><?= (int)($pc['discount_percent'] ?? 0) > 0 ? '−' . (int)$pc['discount_percent'] . '%' : '—' ?></b></div>
        <div class="drawer-meta-row"><span>Бонус</span><b><?= htmlspecialchars($pc['bonus_text'] ?: '—') ?></b></div>
        <div class="drawer-meta-row"><span>Использован</span><b style="color:<?= (!empty($pc['max_uses']) && (int)$pc['uses_count'] >= (int)$pc['max_uses']) ? '#f87171' : '#d8d8e8' ?>;"><?= (int)($pc['uses_count'] ?? 0) ?><?= $pc['max_uses'] ? ' / ' . (int)$pc['max_uses'] : ' / без лимита' ?></b></div>
        <div class="drawer-meta-row"><span>Срок действия</span><b style="color:<?= $pcExpired ? '#f87171' : '#d8d8e8' ?>;"><?= !empty($pc['expires_at']) ? date('d.m.Y', strtotime($pc['expires_at'])) . ($pcExpired ? ' (истёк)' : '') : 'бессрочно' ?></b></div>
        <div class="drawer-meta-row"><span>Статус</span><b style="color:<?= $pc['active'] ? '#4ade80' : '#8a8a96' ?>;"><?= $pc['active'] ? 'Активен' : 'Выключен' ?></b></div>
        <a class="drawer-toggle" href="?toggle_promo_id=<?= (int)$pc['id'] ?>#promo"><?= $pc['active'] ? '⏸ Выключить' : '▶️ Включить' ?></a>
        <a class="drawer-danger" href="?delete_promo_id=<?= (int)$pc['id'] ?>" onclick="return confirm('Удалить промокод <?= htmlspecialchars($pc['code']) ?>?')">🗑 Удалить промокод</a>
    </div>
    <?php return (string)ob_get_clean();
}

// ===================== UPLOAD SITE AVATAR =====================
if (isset($_POST['upload_site_avatar'])) {
    $newAvatar = uploadImage('site_avatar', 'avatar', $uploadDir);
    if ($newAvatar !== '') {
        $pdo->prepare("UPDATE users SET avatar = ? WHERE username = 'Kostlim'")->execute([$newAvatar]);
        $message = '✅ Аватарка сайта обновлена.';
    } else {
        if ($message === '') $message = '❌ Не удалось загрузить аватарку.';
    }
}

// ===================== ОЧИСТКА ЛОГА БОТА =====================
if (isset($_POST['clear_bot_log'])) {
    $logFileToClear = __DIR__ . '/../bot_debug.log';
    if (is_file($logFileToClear)) {
        @file_put_contents($logFileToClear, '');
    }
    $message = '✅ Лог очищен.';
}

// ===================== ПРОМПТ ИИ-ПОМОЩНИКА =====================
if (isset($_POST['save_ai_prompt']) || isset($_POST['reset_ai_prompt'])) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (setting_key VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL DEFAULT '')");
        $newPromptValue = isset($_POST['reset_ai_prompt']) ? '' : trim((string)($_POST['ai_system_prompt'] ?? ''));
        $pdo->prepare("INSERT INTO site_settings (setting_key, value) VALUES ('ai_system_prompt', ?)
                        ON CONFLICT (setting_key) DO UPDATE SET value = EXCLUDED.value")
            ->execute([$newPromptValue]);
        $message = isset($_POST['reset_ai_prompt']) ? '✅ Промпт сброшен к встроенному по умолчанию.' : '✅ Промпт ИИ сохранён.';
    } catch (Throwable $e) {
        $message = '❌ Не удалось сохранить: ' . $e->getMessage();
    }
}

// ===================== PORTFOLIO =====================
if (isset($_POST['add_portfolio']) && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $title        = trim($_POST['title'] ?? '');
    $category_key = $_POST['category_key'] ?? 'preview';
    $price_rub    = !empty($_POST['price_rub']) ? (int)$_POST['price_rub'] : 0;
    $price_uan    = !empty($_POST['price_uan']) ? (int)$_POST['price_uan'] : 0;
    $publish_tg   = !empty($_POST['publish_tg']);
    $filename_main   = uploadImage('image', 'main', $uploadDir);
    $filename_avatar = uploadImage('avatar_image', 'ava', $uploadDir);
    if ($title === '') { $message = '❌ Укажи название проекта.'; }
    elseif ($filename_main === '') { if ($message === '') $message = '❌ Загрузи главное изображение.'; }
    else {
        $stmt = $pdo->prepare("INSERT INTO portfolio (title, category_key, price_rub, price_uan, image, avatar_image) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $category_key, $price_rub, $price_uan, $filename_main, $filename_avatar]);
        $portfolioId = (int)$pdo->lastInsertId();
        $psdResult = savePortfolioPsdFiles($pdo, $portfolioId);
        $postedToChannel = false;
        $watermarkedPath = null;
        if ($publish_tg) {
            $watermarkedPath = buildWatermarkedPhotoForPortfolio($pdo, $uploadDir, [
                'title' => $title, 'category_key' => $category_key, 'price_rub' => $price_rub, 'price_uan' => $price_uan,
                'image' => $filename_main, 'avatar_image' => $filename_avatar,
            ]);
            if ($watermarkedPath && is_file($watermarkedPath)) {
                $caption = "💰 Цена работы: {$price_rub}₽ | {$price_uan}₴\n\n💬 Оценить данную работу можно в комментариях.\n\n🚀 Заказать дизайн можно тут - <a href=\"" . htmlspecialchars(PUBLIC_SITE_URL, ENT_QUOTES, 'UTF-8') . "\">Kostlim Design</a>";
                $result = sendTelegramRequest('sendPhoto', ['chat_id' => PORTFOLIO_CHANNEL_CHAT, 'caption' => $caption, 'parse_mode' => 'HTML'], ['photo' => new CURLFile($watermarkedPath)]);
                $postedToChannel = (bool)($result['ok'] ?? false);
            } else {
                $postedToChannel = publishPortfolioToChannel($pdo, $uploadDir, ['title' => $title, 'category_key' => $category_key, 'price_rub' => $price_rub, 'price_uan' => $price_uan, 'image' => $filename_main, 'avatar_image' => $filename_avatar]);
            }
        }
        if ($publish_tg && !empty($psdResult['has_files'])) {
            publishPortfolioToPrivatePack($pdo, TELEGRAM_BOT_TOKEN, $portfolioId, $title, $price_rub, $price_uan, $watermarkedPath);
        }
        if ($watermarkedPath && str_contains($watermarkedPath, sys_get_temp_dir()) && is_file($watermarkedPath)) {
            @unlink($watermarkedPath);
        }
        $message = '✅ Портфолио сохранено! ID #' . $portfolioId . ($publish_tg ? ($postedToChannel ? ' Пост в Telegram-канал отправлен.' : ' Telegram: ' . ($telegramLastError ?: 'проверь настройки.')) : ' Без публикации в Telegram.');
        if (!empty($psdResult['messages'])) {
            $message .= ' ' . implode(' ', $psdResult['messages']);
        }
    }
}

// ===================== APPEALS =====================
// Закрыть обращение — клиент сможет только создать новое
if (isset($_POST['close_appeal'])) {
    $closeId = (int)($_POST['appeal_id'] ?? 0);
    if ($closeId > 0) {
        try {
            $pdo->prepare("UPDATE appeals SET status = 'closed' WHERE id = ?")->execute([$closeId]);
            $message = '🔒 Обращение #' . $closeId . ' закрыто.';
        } catch (\Throwable $e) {}
    }
}

if (isset($_POST['reply_appeal'])) {
    $appealId = (int)($_POST['appeal_id'] ?? 0);
    $reply    = trim($_POST['reply_text'] ?? '');
    if ($appealId > 0 && $reply !== '') {
        try {
            $mstmt = $pdo->prepare("INSERT INTO appeals_messages (appeal_id, author, message, created_at) VALUES (?, 'admin', ?, NOW())");
            $mstmt->execute([$appealId, $reply]);
            $pdo->prepare("UPDATE appeals SET status = 'answered', replied_at = NOW() WHERE id = ?")->execute([$appealId]);
        } catch (\Throwable $e) {}

        $ap = $pdo->prepare("SELECT a.*, COALESCE(NULLIF(a.telegram,''), NULLIF(o.telegram,''), '') AS client_telegram FROM appeals a LEFT JOIN orders o ON o.id = a.order_id WHERE a.id = ? LIMIT 1");
        $ap->execute([$appealId]);
        $ap = $ap->fetch(PDO::FETCH_ASSOC);

        if ($ap && !empty($ap['client_telegram']) && TELEGRAM_BOT_TOKEN !== '') {
            $link = PUBLIC_SITE_URL . 'profile.php?order=' . (int)$ap['order_id'];
            $text = "✅ По вашему обращению <b>«" . htmlspecialchars($ap['subject']) . "»</b> по заказу <b>#" . (int)$ap['order_id'] . "</b> пришел ответ!\n\n"
                  . "💬 <i>" . htmlspecialchars(mb_substr($reply, 0, 200)) . (mb_strlen($reply) > 200 ? '...' : '') . "</i>\n\n"
                  . "🔗 <a href=\"" . $link . "\">Посмотреть в профиле</a>";
            $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_POSTFIELDS => ['chat_id' => $ap['client_telegram'], 'text' => $text, 'parse_mode' => 'HTML']]);
            curl_exec($ch); curl_close($ch);
        }
        $message = '✅ Ответ на обращение #' . $appealId . ' отправлен.';
    }
}

if (isset($_GET['delete_portfolio_id'])) {
    $del_id    = (int)$_GET['delete_portfolio_id'];
    $img_stmt  = $pdo->prepare("SELECT image, avatar_image FROM portfolio WHERE id = ?");
    $img_stmt->execute([$del_id]);
    $work_files = $img_stmt->fetch(PDO::FETCH_ASSOC);
    foreach (['image', 'avatar_image'] as $field) {
        $val = $work_files[$field] ?? '';
        if ($val === '' || str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) continue;
        $path = $uploadDir . basename($val);
        if (file_exists($path)) unlink($path);
    }
    $pdo->prepare("DELETE FROM portfolio WHERE id = ?")->execute([$del_id]);
    $message = '🗑️ Кейс удален из портфолио.';
}

if (isset($_POST['update_portfolio_media'])) {
    $caseId   = (int)($_POST['portfolio_id'] ?? 0);
    $img_stmt = $pdo->prepare("SELECT image, avatar_image FROM portfolio WHERE id = ?");
    $img_stmt->execute([$caseId]);
    $currentFiles = $img_stmt->fetch(PDO::FETCH_ASSOC);
    if ($currentFiles) {
        $newMain   = uploadImage('portfolio_image', 'main', $uploadDir);
        $newAvatar = uploadImage('portfolio_avatar', 'ava', $uploadDir);
        if ($newMain   !== '') $pdo->prepare("UPDATE portfolio SET image = ? WHERE id = ?")->execute([$newMain, $caseId]);
        if ($newAvatar !== '') $pdo->prepare("UPDATE portfolio SET avatar_image = ? WHERE id = ?")->execute([$newAvatar, $caseId]);

        // Название, цена и категория кейса — раньше их вообще нельзя было
        // поменять после публикации, только удалить и залить заново.
        $newTitle    = trim($_POST['portfolio_title'] ?? '');
        $newCategory = trim($_POST['portfolio_category'] ?? '');
        $newPriceRub = isset($_POST['portfolio_price_rub']) ? (int)$_POST['portfolio_price_rub'] : null;
        $newPriceUan = isset($_POST['portfolio_price_uan']) ? (int)$_POST['portfolio_price_uan'] : null;
        if ($newTitle !== '') {
            $pdo->prepare("UPDATE portfolio SET title = ? WHERE id = ?")->execute([$newTitle, $caseId]);
        }
        if ($newCategory !== '') {
            $pdo->prepare("UPDATE portfolio SET category_key = ? WHERE id = ?")->execute([$newCategory, $caseId]);
        }
        if ($newPriceRub !== null && $newPriceUan !== null) {
            $pdo->prepare("UPDATE portfolio SET price_rub = ?, price_uan = ? WHERE id = ?")->execute([$newPriceRub, $newPriceUan, $caseId]);
        }
        $message = '✅ Кейс обновлён.';
    }
}

// ===================== PRICES =====================
if (isset($_POST['save_all_prices'])) {
    $priceSaveErrors = [];
    foreach (($_POST['prices'] ?? []) as $id => $data) {
        $id = (int)$id;
        $newImage = uploadNestedImage('price_images', $id, 'price', $uploadDir);
        $newCatKey = trim((string)($data['category_key'] ?? ''));
        try {
            if ($newImage !== '') {
                $stmt = $pdo->prepare("UPDATE prices SET title=?,description=?,features=?,price_uan=?,price_rub=?,image=?,category_key=COALESCE(NULLIF(?,''),category_key) WHERE id=?");
                $stmt->execute([$data['title']??'', $data['description']??'', $data['features']??'', $data['price_uan']??0, $data['price_rub']??0, $newImage, $newCatKey, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE prices SET title=?,description=?,features=?,price_uan=?,price_rub=?,category_key=COALESCE(NULLIF(?,''),category_key) WHERE id=?");
                $stmt->execute([$data['title']??'', $data['description']??'', $data['features']??'', $data['price_uan']??0, $data['price_rub']??0, $newCatKey, $id]);
            }
        } catch (Throwable $e) {
            // Например, попытка присвоить ключ категории, который уже занят
            // другой услугой — не роняем всю форму, просто пропускаем эту
            // строку и сообщаем, что именно не сохранилось.
            $priceSaveErrors[] = "#{$id}: " . $e->getMessage();
        }
    }
    $message = empty($priceSaveErrors)
        ? '💾 Прайс-лист обновлен.'
        : '⚠️ Прайс обновлён частично. Не сохранено: ' . implode('; ', $priceSaveErrors);
}

if (isset($_POST['add_price_service'])) {
    $title        = trim($_POST['service_title'] ?? '');
    // Теперь ключ услуги выбирается из выпадающего списка категорий портфолио
    // (select "service_key" на фронте), а не вводится вручную — так услуга
    // и категория портфолио гарантированно совпадают по ключу, и цена из
    // прайса автоматически подтягивается при выборе категории в портфолио.
    $category_key = trim($_POST['service_key'] ?? '');
    $description  = trim($_POST['service_description'] ?? '');
    $features     = trim($_POST['service_features'] ?? '');
    $price_rub    = !empty($_POST['service_price_rub']) ? (int)$_POST['service_price_rub'] : 0;
    $price_uan    = !empty($_POST['service_price_uan']) ? (int)$_POST['service_price_uan'] : 0;
    $image        = uploadImage('service_image', 'price', $uploadDir);
    if ($category_key === '') $category_key = 'service_' . time();
    $category_key = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $category_key));
    $newServiceId = 0;
    if ($title === '') { $message = '❌ Укажи название услуги.'; }
    else {
        $stmt = $pdo->prepare("INSERT INTO prices (category_key,title,description,price_rub,price_uan,features,image) VALUES (?,?,?,?,?,?,?) RETURNING id");
        try {
            $stmt->execute([$category_key, $title, $description, $price_rub, $price_uan, $features, $image]);
            $newServiceId = (int)$stmt->fetchColumn();
            $message = '✅ Новая услуга добавлена в прайс.';
        }
        catch (PDOException $e) { $message = '❌ Такой ключ услуги уже существует.'; }
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json; charset=utf-8');
        if ($newServiceId <= 0) { echo json_encode(['ok' => false, 'msg' => $message]); exit; }
        $row = $pdo->prepare("SELECT * FROM prices WHERE id = ? LIMIT 1"); $row->execute([$newServiceId]);
        $svc = $row->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'ok' => true, 'msg' => $message, 'html' => renderServiceCardHtml($svc),
            'category_key' => $svc['category_key'], 'price_rub' => (int)$svc['price_rub'], 'price_uan' => (int)$svc['price_uan'],
        ]);
        exit;
    }
}

if (isset($_GET['delete_price_id'])) {
    $pdo->prepare("DELETE FROM prices WHERE id = ?")->execute([(int)$_GET['delete_price_id']]);
    $message = '🗑️ Услуга удалена из прайса.';
}

// ===================== CATEGORIES =====================
// Редактирование существующей категории — правим и саму категорию, и
// связанную по ключу услугу в прайсе (цена), одной формой из drawer'а.
if (isset($_POST['update_portfolio_category'])) {
    $catId = (int)($_POST['cat_id'] ?? 0);
    if ($catId > 0) {
        try {
            $rowStmt = $pdo->prepare("SELECT category_key FROM portfolio_categories WHERE id = ? LIMIT 1");
            $rowStmt->execute([$catId]);
            $catKeyExisting = (string)($rowStmt->fetchColumn() ?: '');
            if ($catKeyExisting !== '') {
                $newTitle    = trim($_POST['cat_title'] ?? '');
                $newWidth    = !empty($_POST['cat_width']) ? (int)$_POST['cat_width'] : 0;
                $newHeight   = !empty($_POST['cat_height']) ? (int)$_POST['cat_height'] : 0;
                $newIsDesign = !empty($_POST['cat_is_design']) ? 1 : 0;
                $newKeyRaw   = trim((string)($_POST['cat_key'] ?? ''));
                $newKey      = $newKeyRaw !== '' ? strtolower(preg_replace('/[^a-z0-9_]/i', '_', $newKeyRaw)) : $catKeyExisting;

                if ($newTitle === '') {
                    $message = '❌ Укажи название категории.';
                } else {
                    $pdo->beginTransaction();
                    try {
                        if ($newKey !== $catKeyExisting) {
                            // Переименование ключа — переносим ВСЕ связи разом:
                            // саму категорию, связанную услугу прайса и все
                            // существующие работы портфолио с этим ключом.
                            // Иначе старые работы и услуга "отвязались" бы молча.
                            $dupCheck = $pdo->prepare("SELECT COUNT(*) FROM portfolio_categories WHERE category_key = ? AND id != ?");
                            $dupCheck->execute([$newKey, $catId]);
                            if ((int)$dupCheck->fetchColumn() > 0) {
                                throw new Exception('Категория с ключом "' . $newKey . '" уже существует.');
                            }
                            $pdo->prepare("UPDATE portfolio_categories SET category_key=?, title=?, width_px=?, height_px=?, is_design=? WHERE id=?")
                                ->execute([$newKey, $newTitle, $newWidth, $newHeight, $newIsDesign, $catId]);
                            $pdo->prepare("UPDATE prices SET category_key=? WHERE category_key=?")->execute([$newKey, $catKeyExisting]);
                            $pdo->prepare("UPDATE portfolio SET category_key=? WHERE category_key=?")->execute([$newKey, $catKeyExisting]);
                            $catKeyExisting = $newKey;
                            $message = '✅ Категория обновлена, ключ переименован в "' . $newKey . '" — все связанные работы и услуга перенесены автоматически.';
                        } else {
                            $pdo->prepare("UPDATE portfolio_categories SET title=?, width_px=?, height_px=?, is_design=? WHERE id=?")
                                ->execute([$newTitle, $newWidth, $newHeight, $newIsDesign, $catId]);
                            $message = '✅ Категория обновлена.';
                        }
                        // Убеждаемся, что связанная услуга в прайсе существует
                        // (на случай старых категорий без пары) — но саму цену
                        // здесь НЕ трогаем: цена живёт только в прайсе и правится
                        // только там, категория её лишь отображает.
                        $pdo->prepare("INSERT INTO prices (category_key, title, price_rub, price_uan) VALUES (?, ?, 0, 0)
                                       ON CONFLICT (category_key) DO NOTHING")
                            ->execute([$catKeyExisting, $newTitle]);
                        $pdo->commit();
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        $message = '❌ Не удалось сохранить категорию: ' . $e->getMessage();
                    }
                }
            }
        } catch (Throwable $e) {
            $message = '❌ Не удалось сохранить категорию: ' . $e->getMessage();
        }
    }
}

if (isset($_POST['add_portfolio_category'])) {
    $catTitle    = trim($_POST['cat_title'] ?? '');
    $catKey      = trim($_POST['cat_key'] ?? '');
    $catWidth    = !empty($_POST['cat_width']) ? (int)$_POST['cat_width'] : 1920;
    $catHeight   = !empty($_POST['cat_height']) ? (int)$_POST['cat_height'] : 1080;
    $catIsDesign = !empty($_POST['cat_is_design']) ? 1 : 0;
    $catPriceRub = !empty($_POST['cat_price_rub']) ? (int)$_POST['cat_price_rub'] : 0;
    $catPriceUan = !empty($_POST['cat_price_uan']) ? (int)$_POST['cat_price_uan'] : 0;
    if ($catKey === '') $catKey = 'cat_' . time();
    $catKey = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $catKey));
    $newCatId = 0;
    if ($catTitle === '') { $message = '❌ Укажи название категории.'; }
    else {
        try {
            $insCat = $pdo->prepare("INSERT INTO portfolio_categories (category_key,title,width_px,height_px,is_design,sort_order) VALUES (?,?,?,?,?,100) RETURNING id");
            $insCat->execute([$catKey, $catTitle, $catWidth, $catHeight, $catIsDesign]);
            $newCatId = (int)$insCat->fetchColumn();
            // Автоматически заводим связанную услугу в прайсе с тем же ключом —
            // категория и услуга теперь всегда связаны 1-к-1 по category_key,
            // и цену для этой категории можно сразу редактировать во вкладке "Прайс".
            $pdo->prepare("INSERT INTO prices (category_key, title, price_rub, price_uan) VALUES (?, ?, ?, ?)
                           ON CONFLICT (category_key) DO NOTHING")
                ->execute([$catKey, $catTitle, $catPriceRub, $catPriceUan]);
            $message = '✅ Категория добавлена, связанная услуга создана в прайсе.';
        } catch (PDOException $e) { $message = '❌ Такая категория уже существует.'; }
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json; charset=utf-8');
        if ($newCatId <= 0) { echo json_encode(['ok' => false, 'msg' => $message]); exit; }
        $row = $pdo->prepare("SELECT * FROM portfolio_categories WHERE id = ? LIMIT 1"); $row->execute([$newCatId]);
        $cat = $row->fetch(PDO::FETCH_ASSOC);
        $priceRow = $pdo->prepare("SELECT * FROM prices WHERE category_key = ? LIMIT 1"); $priceRow->execute([$catKey]);
        $svc = $priceRow->fetch(PDO::FETCH_ASSOC);
        $sizeLabel = ((int)$cat['width_px']>0 && (int)$cat['height_px']>0) ? " ({$cat['width_px']}x{$cat['height_px']})" : '';
        echo json_encode([
            'ok' => true, 'msg' => $message,
            'html' => renderCategoryCardHtml($cat),
            'category_key' => $cat['category_key'],
            'category_label' => $cat['title'] . $sizeLabel,
            'is_design' => (bool)$cat['is_design'],
            'service_html' => $svc ? renderServiceCardHtml($svc) : null,
        ]);
        exit;
    }
}

if (isset($_GET['delete_portfolio_category_id'])) {
    $pdo->prepare("DELETE FROM portfolio_categories WHERE id = ?")->execute([(int)$_GET['delete_portfolio_category_id']]);
    $message = '🗑️ Категория удалена.';
}

// ===================== FETCH DATA =====================
$services   = $pdo->query("SELECT * FROM prices ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT * FROM portfolio_categories ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$categoryMap = [];
foreach ($categories as $category) { $categoryMap[$category['category_key']] = $category; }
$works = $pdo->query("SELECT * FROM portfolio ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Отзывы
$pendingReviews = [];
$approvedReviews = [];
try {
    $pendingReviews = $pdo->query("SELECT * FROM reviews WHERE approved = FALSE ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $approvedReviews = $pdo->query("SELECT * FROM reviews WHERE approved = TRUE ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Команды бота
$botCommands = [];
try {
    $botCommands = $pdo->query("SELECT * FROM bot_commands ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Правила заказа
$orderRulesText = '';
try {
    $rulesRow = $pdo->query("SELECT rule_text FROM site_rules WHERE rule_key = 'order_terms' LIMIT 1")->fetch();
    $orderRulesText = $rulesRow['rule_text'] ?? '';
} catch (Throwable $e) {}

$orderStats = $pdo->query("
    SELECT COUNT(*) AS total,
        COUNT(*) FILTER (WHERE status='pending') AS pending,
        COUNT(*) FILTER (WHERE status='in_progress') AS in_progress,
        COUNT(*) FILTER (WHERE status='urgent') AS urgent,
        COUNT(*) FILTER (WHERE status='ready') AS ready,
        COUNT(*) FILTER (WHERE status='declined') AS declined
    FROM orders
")->fetch(PDO::FETCH_ASSOC) ?: [];

$daAccessToken  = daEnsureAccessToken($pdo);
$daConnected    = $daAccessToken !== null;
$daDonationTotalUsd = 0.0;
$daPayoutStats  = ['gross' => 0.0, 'count' => 0, 'commission' => 0.0, 'net' => 0.0];
if ($daConnected) {
    $daDonations = daGetDonations($pdo, 200);
    $daDonationTotalUsd = daGetCurrentMonthDonationTotalUsd($daDonations);
    $daPayoutStats = daGetCurrentMonthPayoutStats($pdo);
}

$revenue = $pdo->query("
    SELECT COALESCE(SUM(CASE WHEN o.cooperation THEN 0 ELSE p.price_rub END),0) AS rub,
           COALESCE(SUM(CASE WHEN o.cooperation THEN 0 ELSE p.price_uan END),0) AS uan
    FROM orders o LEFT JOIN prices p ON p.category_key=o.service_key WHERE o.status='ready'
")->fetch(PDO::FETCH_ASSOC) ?: ['rub'=>0,'uan'=>0];

// ── Курсы валют (ЦБ РФ) ──────────────────────────────────────────────
function fetchExchangeRates(): array {
    $rates = ['USD' => 90.0, 'UAH' => 2.2]; // fallback
    try {
        $ch = curl_init('https://www.cbr-xml-daily.ru/daily_json.js');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_SSL_VERIFYPEER => false]);
        $resp = curl_exec($ch); curl_close($ch);
        $data = $resp ? json_decode($resp, true) : null;
        if (!empty($data['Valute']['USD']['Value'])) $rates['USD'] = (float)$data['Valute']['USD']['Value'];
        if (!empty($data['Valute']['UAH']['Value'])) $rates['UAH'] = (float)($data['Valute']['UAH']['Value'] / $data['Valute']['UAH']['Nominal']);
    } catch (Throwable $e) {}
    return $rates;
}
$rates = fetchExchangeRates();
$usdRate = $rates['USD']; // руб за 1 USD
$uahRate = $rates['UAH']; // руб за 1 UAH

// ── Статистика по способам оплаты (только ready заказы с paid_amount > 0) ──
$payStats = $pdo->query("
    SELECT payment_method,
           paid_currency,
           COALESCE(SUM(paid_amount), 0) AS total
    FROM orders
    WHERE status = 'ready' AND paid_amount > 0
    GROUP BY payment_method, paid_currency
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Группируем по методу и считаем в рублях
$payByMethod = ['donation' => 0, 'crypto' => 0, 'monobank' => 0, 'other' => 0];
$totalRubFromPaid = 0;
foreach ($payStats as $row) {
    $amt = (float)$row['total'];
    $rubAmt = match($row['paid_currency']) {
        'USD' => $amt * $usdRate,
        'UAH' => $amt * $uahRate,
        default => $amt,
    };
    $method = $row['payment_method'] ?? 'other';
    if (!isset($payByMethod[$method])) $payByMethod[$method] = 0;
    $payByMethod[$method] += $rubAmt;
    $totalRubFromPaid += $rubAmt;
}

// ── Ручные начисления (кнопка "+" рядом со статистикой) ──────────────
// Не любой доход идёт через оформленный заказ на сайте — донат мимо бота,
// перевод напрямую в монобанк и т.п. Раньше такие суммы просто нигде не
// учитывались. Таблица создаётся сама при первом обращении.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS manual_earnings (
        id SERIAL PRIMARY KEY,
        method VARCHAR(20) NOT NULL DEFAULT 'other',
        amount NUMERIC(12,2) NOT NULL,
        currency VARCHAR(3) NOT NULL DEFAULT 'RUB',
        note VARCHAR(255) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
    $manualRows = $pdo->query("SELECT method, currency, COALESCE(SUM(amount),0) AS total FROM manual_earnings GROUP BY method, currency")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($manualRows as $row) {
        $amt = (float)$row['total'];
        $rubAmt = match($row['currency']) {
            'USD' => $amt * $usdRate,
            'UAH' => $amt * $uahRate,
            default => $amt,
        };
        $method = $row['method'] ?: 'other';
        if (!isset($payByMethod[$method])) $payByMethod[$method] = 0;
        $payByMethod[$method] += $rubAmt;
        $totalRubFromPaid += $rubAmt;
    }
} catch (Throwable $e) {}

$totalUsdFromPaid = $usdRate > 0 ? $totalRubFromPaid / $usdRate : 0;
$totalUahFromPaid = $uahRate > 0 ? $totalRubFromPaid / $uahRate : 0;

$activeValue = $pdo->query("
    SELECT COALESCE(SUM(CASE WHEN o.cooperation AND o.status IN ('in_progress','urgent','ready') THEN 0 ELSE p.price_rub END),0) AS rub,
           COALESCE(SUM(CASE WHEN o.cooperation AND o.status IN ('in_progress','urgent','ready') THEN 0 ELSE p.price_uan END),0) AS uan
    FROM orders o LEFT JOIN prices p ON p.category_key=o.service_key WHERE o.status IN ('pending','in_progress','urgent')
")->fetch(PDO::FETCH_ASSOC) ?: ['rub'=>0,'uan'=>0];

$ordersPerPage  = 15;
$orders_page    = max(1, (int)($_GET['orders_page'] ?? 1));
$orders_status  = trim((string)($_GET['orders_status'] ?? ''));

$pendingOrders = $pdo->query("SELECT id, username, telegram, service_key, created_at, cooperation, deadline FROM orders WHERE status = 'pending' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

$where = ''; $params = [];
if ($orders_status !== '') { $where = "WHERE o.status = ?"; $params[] = $orders_status; }

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o " . $where);
$countStmt->execute($params);
$ordersTotal      = (int)$countStmt->fetchColumn();
$ordersTotalPages = max(1, (int)ceil($ordersTotal / $ordersPerPage));

$offset = ($orders_page - 1) * $ordersPerPage;
$sql = "SELECT o.id, o.username, o.telegram, o.service_key, o.status, o.created_at, o.cooperation, o.deadline,
    CASE WHEN o.cooperation AND o.status IN ('in_progress','urgent','ready') THEN 0 ELSE p.price_rub END AS price_rub,
    CASE WHEN o.cooperation AND o.status IN ('in_progress','urgent','ready') THEN 0 ELSE p.price_uan END AS price_uan,
    p.title, p.price_rub AS price_rub_from_price, p.price_uan AS price_uan_from_price
    FROM orders o LEFT JOIN prices p ON p.category_key=o.service_key " . ($where ?: '') . " ORDER BY o.id DESC LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
$execParams = $params;
$execParams[] = $ordersPerPage;
$execParams[] = $offset;
$stmt->execute($execParams);
$recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categoryLabels = [];
foreach ($categories as $category) {
    $size = ((int)$category['width_px']>0 && (int)$category['height_px']>0) ? " ({$category['width_px']}x{$category['height_px']})" : '';
    $categoryLabels[$category['category_key']] = $category['title'] . $size;
}

$statusLabels = [
    'pending'     => 'Новый',
    'in_progress' => 'В процессе',
    'urgent'      => 'Срочный',
    'ready'       => 'Готов',
    'revision'    => 'На правке',
    'declined'    => 'Отклонён',
];

$appeals = [];
$openAppealsCount = 0;
try {
    $appeals = $pdo->query("
        SELECT a.id, a.order_id, a.username, a.subject, a.message, a.reply, a.status, a.created_at, a.replied_at, a.telegram
        FROM appeals a ORDER BY a.status ASC, a.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $openAppealsCount = count(array_filter($appeals, fn($a) => $a['status'] === 'open'));
} catch (\Throwable $e) {}

$viewOrder    = null;
$orderAppeals = [];
if (isset($_GET['view_order'])) {
    $vid = (int)$_GET['view_order'];
    if ($vid > 0) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([$vid]);
        $viewOrder = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($viewOrder) {
            try {
                $ast = $pdo->prepare("SELECT * FROM appeals WHERE order_id = ? ORDER BY id ASC");
                $ast->execute([$vid]);
                $orderAppeals = $ast->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) {}
        }
    }
}

// ── Базовая цена заказа для калькулятора скидки в модалке "Готово" ──────
// Единая функция computeOrderPriceWithPromo() (includes/order_flow.php) —
// сначала +50% за срочность, потом скидка по промокоду.
$readyCalcBaseRub = 0; $readyCalcBaseUan = 0; $readyCalcDiscountPct = 0;
if ($viewOrder) {
    $priceCalcView = computeOrderPriceWithPromo($pdo, $viewOrder);
    $readyCalcBaseRub     = $priceCalcView['base_rub'];
    $readyCalcBaseUan     = $priceCalcView['base_uan'];
    $readyCalcDiscountPct = $priceCalcView['discount_percent'];
}

if (isset($_POST['send_order_message'])) {
    $oid  = (int)($_POST['order_id'] ?? 0);
    $subj = trim($_POST['msg_subject'] ?? 'Сообщение от администрации');
    $body = trim($_POST['msg_text'] ?? '');
    if ($oid > 0 && $body !== '') {
        $ost = $pdo->prepare("SELECT id, username, telegram, client_chat_id, session_id FROM orders WHERE id = ? LIMIT 1");
        $ost->execute([$oid]);
        $orow = $ost->fetch(PDO::FETCH_ASSOC);
        $sendTo = '';
        if ($orow) {
            $sendTo = trim((string)($orow['client_chat_id'] ?? ''));
            if ($sendTo === '' || !is_numeric($sendTo)) {
                if (!empty($orow['session_id'])) {
                    try {
                        $lq = $pdo->prepare("SELECT NULLIF(CAST(tg_id AS VARCHAR),'') AS chat_id FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1");
                        $lq->execute([$orow['session_id']]);
                        $lr = $lq->fetch(PDO::FETCH_ASSOC);
                        if (!empty($lr['chat_id']) && is_numeric($lr['chat_id'])) {
                            $sendTo = $lr['chat_id'];
                            $pdo->prepare("UPDATE orders SET client_chat_id = ? WHERE id = ?")->execute([$sendTo, $oid]);
                        }
                    } catch (Throwable $e) {}
                }
            }
        }
        try {
            $adminName = 'Администратор';
            $ins = $pdo->prepare("INSERT INTO appeals (order_id, username, telegram, subject, status, created_at) VALUES (?, ?, ?, ?, 'open', NOW()) RETURNING id");
            $ins->execute([$oid, $adminName, $sendTo, $subj]);
            $aid = (int)$ins->fetchColumn();
            if ($aid > 0) {
                $m = $pdo->prepare("INSERT INTO appeals_messages (appeal_id, author, message, created_at) VALUES (?, 'admin', ?, NOW())");
                $m->execute([$aid, $body]);
            }
        } catch (\Throwable $e) {}
        if ($sendTo !== '' && TELEGRAM_BOT_TOKEN !== '') {
            $text = "📨 <b>Сообщение по вашему заказу #{$oid}</b>\n\n" . htmlspecialchars($body);
            sendTelegramRequest('sendMessage', ['chat_id' => $sendTo, 'text' => $text, 'parse_mode' => 'HTML']);
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?view_order=' . $oid . '&msg=sent'); exit;
    }
}

$currentAvatarRow  = $pdo->query("SELECT avatar FROM users LIMIT 1")->fetch();
$currentAvatarFile = $currentAvatarRow['avatar'] ?? '';
$imgbbKeys         = array_filter([
    getSetting($pdo, 'IMGBB_API_KEY', getenv('IMGBB_API_KEY') ?: ''),
    getSetting($pdo, 'IMGBB_API_KEY2', getenv('IMGBB_API_KEY2') ?: ''),
    getSetting($pdo, 'IMGBB_API_KEY3', getenv('IMGBB_API_KEY3') ?: ''),
]);
$imgbbKeyCount     = count($imgbbKeys);
$imgbbKeySet       = $imgbbKeyCount > 0;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kostlim Admin</title>
    <link rel="icon" type="image/png" href="/assets/notify/fav.png" sizes="16x16">
    <link rel="stylesheet" href="../style.css">
    <style>
        * { scrollbar-width: thin; scrollbar-color: #f97316 #111116; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #111116; border-radius: 99px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(180deg,#fb923c,#f97316); border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: #fb923c; }
        body { background: #08080b; color: #fff; font-family: Montserrat, Arial, sans-serif; }
        .admin-shell { max-width: 1480px; margin: 0 auto; padding: 24px; }
        .admin-top { display: flex; justify-content: space-between; align-items: center; gap: 18px; margin-bottom: 22px; }
        .admin-title h1 { font-size: 28px; line-height: 1.1; margin: 0 0 6px; }
        .admin-title p { color: #8a8a96; margin: 0; }
        .admin-meta { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
        .admin-meta span { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #242432; background: #111116; color: #d8d8e8; border-radius: 999px; padding: 7px 11px; font-size: 12px; font-weight: 800; }
        .admin-meta span.ok   { border-color: rgba(34,197,94,.5);  background: rgba(34,197,94,.1);  color: #86efac; }
        .admin-meta span.warn { border-color: rgba(249,115,22,.5); background: rgba(249,115,22,.1); color: #fdba74; }
        .admin-link-top { color: #fff; text-decoration: none; border: 1px solid #242432; border-radius: 10px; padding: 11px 18px; background: #111116; font-size: 13px; font-weight: 700; transition: .2s; }
        .admin-link-top:hover { border-color: #f97316; background: rgba(249,115,22,.1); }
        .notice { border: 1px solid rgba(249,115,22,.45); background: rgba(249,115,22,.10); border-radius: 12px; padding: 14px 16px; margin-bottom: 18px; font-weight: 700; }
        .notice.success { border-color: rgba(34,197,94,.45); background: rgba(34,197,94,.10); color: #86efac; }
        .notice.error   { border-color: rgba(239,68,68,.45);  background: rgba(239,68,68,.10);  color: #fca5a5; }
        .admin-board { display: grid; grid-template-columns: 230px minmax(0,1fr); gap: 18px; align-items: start; }
        .admin-tabs { position: sticky; top: 18px; display: grid; gap: 9px; background: #111116; border: 1px solid #20202c; border-radius: 14px; padding: 12px; }
        .admin-tab-group-label { font-size: 10.5px; font-weight: 900; text-transform: uppercase; letter-spacing: .8px; color: #4a4a58; padding: 10px 8px 2px; grid-column: 1 / -1; }
        .admin-tab { display: flex; align-items: center; gap: 10px; width: 100%; border: 1px solid transparent; border-radius: 10px; padding: 12px 13px; background: transparent; color: #d8d8e8; font-weight: 900; text-align: left; cursor: pointer; font-family: Montserrat,sans-serif; font-size: 13px; transition: .2s; }
        .admin-tab:hover { background: #171720; border-color: #2a2a38; }
        .admin-tab.active { color: #fff; background: linear-gradient(135deg,#f97316,#ea580c); box-shadow: 0 12px 28px rgba(249,115,22,.28); border-color: transparent; }
        .admin-content { min-width: 0; }
        .stats-grid { display: grid; grid-template-columns: repeat(6, minmax(150px,1fr)); gap: 12px; margin-bottom: 18px; }
        .stat-card { background: #111116; border: 1px solid #20202c; border-radius: 12px; padding: 16px; min-height: 92px; }
        .stat-card span { color: #8a8a96; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .stat-card strong { display: block; font-size: 25px; margin-top: 10px; }
        .stat-card.accent { border-color: rgba(249,115,22,.6); background: linear-gradient(145deg,rgba(249,115,22,.18),#111116); }
        .stat-card.warn strong { color: #f97316; }
        .admin-layout { display: grid; grid-template-columns: 380px minmax(0,1fr); gap: 18px; align-items: start; }
        .admin-layout.single-column { grid-template-columns: 1fr; }
        .panel { background: #111116; border: 1px solid #20202c; border-radius: 14px; padding: 18px; margin-bottom: 18px; }
        .panel h2 { font-size: 16px; margin-bottom: 14px; }
        .avatar-preview-wrap { display: flex; align-items: center; gap: 18px; margin-bottom: 16px; }
        .avatar-preview-img { width: 72px; height: 72px; border-radius: 50%; object-fit: cover; border: 3px solid #f97316; background: #0b0b10; flex-shrink: 0; }
        .avatar-preview-info { color: #8a8a96; font-size: 12px; line-height: 1.6; }
        .avatar-preview-info strong { display: block; color: #d8d8e8; margin-bottom: 2px; font-size: 13px; }
        label { display: block; color: #d9d9e4; font-size: 12px; font-weight: 800; margin: 12px 0 6px; text-transform: uppercase; letter-spacing: .5px; }
        input:not([type="file"]):not([type="checkbox"]), select, textarea { width: 100%; background: #171720; color: #fff; border: 1px solid #2a2a38; border-radius: 9px; padding: 11px 12px; outline: none; font-family: Montserrat,sans-serif; font-size: 13px; transition: .2s; }
        input:not([type="file"]):not([type="checkbox"]):focus, select:focus, textarea:focus { border-color: #f97316; box-shadow: 0 0 0 3px rgba(249,115,22,.18), 0 0 14px rgba(249,115,22,.22); }
        textarea { min-height: 64px; resize: vertical; }
        select { cursor: pointer; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238a8a96' stroke-width='2.5'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; }
        input[type="file"].styled-hidden { display: none; }
        .file-upload-wrap { display: flex; align-items: center; gap: 10px; width: 100%; }
        .file-upload-btn { display: inline-flex; align-items: center; gap: 7px; cursor: pointer; background: #1e1e2a; border: 1px solid #2a2a38; border-radius: 9px; padding: 9px 16px; color: #d8d8e8; font-size: 12px; font-weight: 700; white-space: nowrap; transition: .2s; font-family: Montserrat,sans-serif; flex-shrink: 0; user-select: none; }
        .file-upload-btn:hover { background: rgba(249,115,22,.15); border-color: #f97316; color: #fff; }
        .file-upload-btn svg { flex-shrink: 0; }
        .file-upload-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #8a8a96; font-size: 12px; font-style: italic; }
        .file-upload-name.has-file { color: #86efac; font-style: normal; font-weight: 700; }
        .mini-file-wrap { display: flex; flex-direction: column; gap: 6px; }
        .mini-file-btn { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; background: #1a1a24; border: 1px solid #2a2a38; border-radius: 7px; padding: 7px 12px; color: #c8c8d8; font-size: 11px; font-weight: 700; white-space: nowrap; transition: .2s; font-family: Montserrat,sans-serif; user-select: none; }
        .mini-file-btn:hover { background: rgba(249,115,22,.15); border-color: #f97316; color: #fff; }
        .mini-file-name { font-size: 10px; color: #8a8a96; font-style: italic; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px; }
        .mini-file-name.has-file { color: #86efac; font-style: normal; }
        .two-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .tg-checkbox { display:flex; gap:10px; align-items:center; color:#d8d8e8; font-size:13px; text-transform:none; letter-spacing:0; margin:4px 0 18px; }
        .tg-checkbox input { width:auto; margin:0; accent-color:#f97316; }
        .avatar-hint { color: #8a8a96; font-size: 12px; line-height: 1.5; margin-top: 8px; background: rgba(255,255,255,.03); border-radius: 7px; padding: 8px 10px; border-left: 2px solid #f97316; }
        .tab-hidden { display: none !important; }
        #admin-toast { position: fixed; bottom: 28px; right: 28px; z-index: 9999; min-width: 280px; max-width: 420px; border-radius: 14px; padding: 16px 20px; font-weight: 700; font-size: 14px; font-family: Montserrat,sans-serif; box-shadow: 0 8px 32px rgba(0,0,0,.5); opacity: 0; transform: translateY(20px); transition: opacity .3s, transform .3s; pointer-events: none; }
        #admin-toast.show { opacity: 1; transform: translateY(0); pointer-events: auto; }
        #admin-toast.success { background: #0f2b1a; border: 1px solid rgba(34,197,94,.5); color: #86efac; }
        #admin-toast.error   { background: #2b0f0f; border: 1px solid rgba(239,68,68,.5);  color: #fca5a5; }
        #admin-toast.loading { background: #1a1a24; border: 1px solid rgba(249,115,22,.5); color: #fdba74; }
        .admin-table-wrap { overflow-x: auto; border: 1px solid #20202c; border-radius: 12px; }
        table { width: 100%; border-collapse: collapse; min-width: 760px; }
        th, td { padding: 12px; border-bottom: 1px solid #20202c; text-align: left; vertical-align: middle; }
        th { color: #8a8a96; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
        td { color: #efeff7; font-size: 13px; }
        tr:last-child td { border-bottom: 0; }
        tr:hover td { background: rgba(255,255,255,.015); }
        .thumb-pair { display: flex; align-items: center; gap: 8px; }
        .case-thumb-wrap { position: relative; display: inline-block; user-select: none; }
        .case-thumb-wrap::after { content: ''; position: absolute; inset: 0; z-index: 2; cursor: default; }
        .case-thumb { width: 98px; height: 55px; object-fit: cover; border-radius: 8px; background: #0b0b10; pointer-events: none; user-select: none; -webkit-user-drag: none; display: block; }
        .case-ava { width: 38px; height: 38px; object-fit: cover; border-radius: 50%; border: 2px solid #f97316; margin-left: -22px; background: #111116; pointer-events: none; user-select: none; -webkit-user-drag: none; }
        .price-thumb { width: 70px; height: 44px; object-fit: cover; border-radius: 8px; background: #0b0b10; border: 1px solid #272735; pointer-events: none; }
        .status { display: inline-flex; border-radius: 999px; padding: 6px 10px; background: #191924; color: #d8d8e8; font-weight: 800; font-size: 12px; }
        .status.pending     { background: rgba(249,115,22,.18); color: #fb923c; }
        .status.in_progress { background: rgba(59,130,246,.14); color: #60a5fa; }
        .status.urgent      { background: rgba(234,88,12,.18); color: #fb923c; }
        .status.ready       { background: rgba(34,197,94,.16); color: #86efac; }
        .status.revision    { background: rgba(251,191,36,.16); color: #fbbf24; }
        .status.declined    { background: rgba(239,68,68,.15); color: #fca5a5; }
        tr.order-row.status-ready td       { background: rgba(34,197,94,.08); }
        tr.order-row.status-revision td    { background: rgba(251,191,36,.07); }
        tr.order-row.status-in_progress td { background: rgba(59,130,246,.05); }
        tr.order-row.status-urgent td      { background: rgba(234,88,12,.10); outline: 1px solid rgba(239,68,68,.25); }
        tr.order-row.status-declined td    { background: rgba(239,68,68,.06); }
        tr.order-row.status-pending td     { background: rgba(249,115,22,.04); }

        /* ── Карточки заказов в админке: весь прямоугольник — кликабельный ── */
        .admin-orders-cards { display: flex; flex-direction: column; gap: 10px; }
        .admin-order-card {
            display: flex;
            align-items: center;
            gap: 16px;
            background: #14141c;
            border: 1px solid #23232f;
            border-radius: 14px;
            padding: 14px 18px;
            cursor: pointer;
            transition: border-color .18s, box-shadow .18s, transform .12s, background .18s;
        }
        .admin-order-card:hover, .admin-order-card:focus-visible {
            border-color: rgba(249,115,22,.55);
            box-shadow: 0 0 0 1px rgba(249,115,22,.25), 0 8px 24px rgba(249,115,22,.10);
            background: #17171f;
            transform: translateY(-1px);
            outline: none;
        }
        .admin-order-card.status-urgent { border-color: rgba(239,68,68,.35); }
        .admin-order-card.status-urgent:hover { border-color: rgba(239,68,68,.6); box-shadow: 0 0 0 1px rgba(239,68,68,.3), 0 8px 24px rgba(239,68,68,.12); }
        .admin-order-card.status-ready { border-color: rgba(34,197,94,.4); background: rgba(34,197,94,.06); }
        .admin-order-card.status-ready:hover { border-color: rgba(34,197,94,.65); box-shadow: 0 0 0 1px rgba(34,197,94,.3), 0 8px 24px rgba(34,197,94,.12); background: rgba(34,197,94,.09); }
        .admin-order-card.status-revision { border-color: rgba(251,191,36,.4); background: rgba(251,191,36,.06); }
        .admin-order-card.status-revision:hover { border-color: rgba(251,191,36,.65); box-shadow: 0 0 0 1px rgba(251,191,36,.3), 0 8px 24px rgba(251,191,36,.12); background: rgba(251,191,36,.09); }
        .admin-order-card-id { font-weight: 900; color: #fff; font-size: 14px; flex-shrink: 0; width: 42px; }
        .admin-order-card-main { flex: 1; min-width: 0; }
        .admin-order-card-client { font-size: 13px; font-weight: 700; color: #fff; margin-bottom: 4px; }
        .admin-order-card-client span { color: #8a8a96; font-weight: 500; margin-left: 6px; }
        .admin-order-card-status-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .admin-order-card-price { text-align: right; font-size: 13px; font-weight: 800; color: #fff; flex-shrink: 0; }
        .admin-order-card-price span { color: #8a8a96; font-weight: 500; }
        .admin-order-card-accept { flex-shrink: 0; }

        /* Выпадающее меню "Принять" — те же 4 варианта, что и в Telegram-боте
           (Обычный / Срочный / Сотрудничество / Просто в очередь), вместо
           одной кнопки, которая раньше сразу ставила заказ в работу без
           оплаты — теперь сайт и бот ведут себя одинаково. */
        details.accept-menu { position: relative; display: inline-block; }
        details.accept-menu summary::-webkit-details-marker { display: none; }
        details.accept-menu .accept-menu-popup {
            position: absolute; top: calc(100% + 6px); right: 0; z-index: 40;
            background: #171720; border: 1px solid #2a2a38; border-radius: 10px;
            padding: 6px; display: flex; flex-direction: column; gap: 4px;
            min-width: 210px; box-shadow: 0 10px 30px rgba(0,0,0,.5);
        }
        details.accept-menu .accept-menu-popup button {
            border: 0; background: transparent; color: #e8e8ee; text-align: left;
            padding: 8px 10px; border-radius: 7px; font-size: 12px; font-weight: 700;
            cursor: pointer; font-family: Montserrat, sans-serif; width: 100%;
        }
        details.accept-menu .accept-menu-popup button:hover { background: #24242e; }
        details.accept-menu-full .accept-menu-popup { flex-direction: column; }
        details.accept-menu-full .accept-menu-popup button {
            border: 1px solid #2a2a38; margin-bottom: 4px; text-align: center;
        }
        .admin-order-card-chevron { color: #4a4a58; font-size: 18px; flex-shrink: 0; transition: color .18s, transform .18s; }
        .admin-order-card:hover .admin-order-card-chevron { color: #f97316; transform: translateX(3px); }
        @media (max-width: 640px) {
            .admin-order-card { flex-wrap: wrap; }
            .admin-order-card-price { order: 3; margin-left: 58px; }
        }
        tr.order-row.status-urgent { outline: 2px solid rgba(239,68,68,.45); border-radius: 8px; }
        .deadline-badge-overdue { animation: pulse-red 1.5s ease-in-out infinite; }
        @keyframes pulse-red { 0%,100%{ box-shadow: 0 0 0 0 rgba(239,68,68,0); } 50%{ box-shadow: 0 0 0 4px rgba(239,68,68,.35); } }
        .delete-link { color: #ff6b76; text-decoration: none; font-weight: 800; font-size: 12px; padding: 6px 12px; border: 1px solid rgba(255,107,118,.25); border-radius: 7px; transition: .2s; display: inline-block; }
        .delete-link:hover { background: rgba(255,107,118,.12); border-color: #ff6b76; }
        .mini-media-form { display: grid; gap: 7px; min-width: 190px; }
        .mini-media-form button { border: 0; border-radius: 8px; padding: 8px 12px; background: linear-gradient(135deg,#fb923c,#f97316); color: #fff; font-weight: 800; cursor: pointer; font-family: Montserrat,sans-serif; font-size: 11px; letter-spacing: .5px; text-transform: uppercase; transition: .2s; }
        .mini-media-form button:hover { opacity: .85; }
        .btn-panel { width: 100%; margin-top: 14px; border: none; border-radius: 10px; padding: 13px 16px; background: linear-gradient(135deg,#fb923c,#f97316); color: #fff; font-weight: 900; cursor: pointer; text-transform: uppercase; font-family: Montserrat,sans-serif; letter-spacing: 1px; font-size: 13px; box-shadow: 0 8px 24px rgba(249,115,22,.30); transition: .2s; position: relative; }
        .btn-panel:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 0 28px rgba(249,115,22,.55), 0 12px 30px rgba(249,115,22,.25); }
        .btn-panel:disabled { opacity: .6; cursor: not-allowed; transform: none; }
        .btn-panel .btn-spinner { display: none; }
        .btn-panel.loading .btn-text { display: none; }
        .btn-panel.loading .btn-spinner { display: inline-flex; align-items: center; gap: 8px; }
        .review-card { background: #0b0b10; border: 1px solid #20202c; border-radius: 10px; padding: 14px; margin-bottom: 10px; }
        .review-card.pending { border-color: rgba(249,115,22,.35); background: rgba(249,115,22,.04); }
        .review-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; flex-wrap: wrap; }
        .review-header .name { font-weight: 800; color: #fff; }
        .review-header .rating { font-size: 14px; font-weight: 700; color: #f97316; }
        .review-actions { display: flex; gap: 6px; }
        .review-btn { border: none; border-radius: 6px; padding: 6px 12px; font-size: 11px; font-weight: 800; cursor: pointer; font-family: Montserrat,sans-serif; transition: .2s; }
        .review-btn.approve { background: rgba(34,197,94,.2); color: #86efac; border: 1px solid rgba(34,197,94,.35); }
        .review-btn.approve:hover { background: rgba(34,197,94,.35); }
        .review-btn.reject { background: rgba(239,68,68,.2); color: #fca5a5; border: 1px solid rgba(239,68,68,.35); }
        .review-btn.reject:hover { background: rgba(239,68,68,.35); }
        .cmd-row { display: grid; grid-template-columns: 100px 1fr 100px 60px; gap: 8px; align-items: center; margin-bottom: 8px; padding: 8px; background: #0b0b10; border-radius: 8px; }
        @media (max-width: 1100px) { .admin-board { grid-template-columns: 1fr; } .admin-tabs { position: static; grid-template-columns: repeat(2,1fr); } .stats-grid { grid-template-columns: repeat(2,1fr); } .admin-layout { grid-template-columns: 1fr; } }
        @media (max-width: 640px) { .admin-shell { padding: 16px; } .admin-top { align-items: flex-start; flex-direction: column; } .admin-tabs { grid-template-columns: 1fr; } .stats-grid { grid-template-columns: 1fr; } .two-cols { grid-template-columns: 1fr; } }

        /* ══════════════════════════════════════════════════════════════
           НОВАЯ КОНЦЕПЦИЯ: центральная форма + сетка карточек + Drawer
           Единый паттерн для Портфолио / Услуг / Категорий / Промокодов
        ══════════════════════════════════════════════════════════════ */
        .section-block { margin-bottom: 40px; }
        .section-heading { display: flex; align-items: center; gap: 10px; font-size: 17px; font-weight: 900; margin: 0 0 18px; letter-spacing: .2px; }
        .section-heading .neon-ico { font-size: 18px; filter: drop-shadow(0 0 8px rgba(249,115,22,.55)); }

        .create-card { max-width: 960px; margin: 0 auto 6px; background: linear-gradient(180deg,#14141c,#111116); border: 1px solid rgba(255,255,255,.06); border-radius: 20px; padding: 28px 30px 26px; box-shadow: 0 0 30px rgba(249,115,22,.08), 0 20px 50px rgba(0,0,0,.35); }
        .create-card.collapsed { display: none; }
        .create-toggle-btn { max-width: 960px; margin: 0 auto 22px; width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; border: 1px dashed rgba(249,115,22,.4); border-radius: 16px; padding: 16px; background: rgba(249,115,22,.05); color: #fdba74; font-weight: 900; font-size: 13.5px; text-transform: uppercase; letter-spacing: .5px; cursor: pointer; font-family: Montserrat,sans-serif; transition: .18s; }
        .create-toggle-btn:hover { background: rgba(249,115,22,.12); border-color: #f97316; color: #fff; }
        .create-toggle-btn.is-hidden { display: none; }
        .create-card-close { position: absolute; top: 0; right: 0; background: transparent; border: 1px solid #2a2a38; color: #8a8a96; width: 30px; height: 30px; border-radius: 8px; cursor: pointer; font-size: 15px; line-height: 1; transition: .18s; }
        .create-card-close:hover { color: #fff; border-color: #f97316; background: rgba(249,115,22,.12); }
        .create-card-head { position: relative; }
        .create-card-head { text-align: center; margin-bottom: 20px; }
        .create-card-head h2 { font-size: 19px; margin: 0 0 6px; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .create-card-head p { color: #8a8a96; font-size: 12.5px; margin: 0; }
        .create-card hr.divider { border: none; border-top: 1px solid rgba(255,255,255,.06); margin: 18px 0; }
        .create-card label { text-align: left; }
        .create-card .btn-panel { max-width: 420px; margin-left: auto; margin-right: auto; display: block; }

        .grid-wrap { max-width: 1160px; margin: 34px auto 0; }
        .card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 22px; align-items: start; justify-items: stretch; }
        [data-panel="portfolio-list"] .card-grid { grid-template-columns: repeat(3, 1fr) !important; }
        @media (max-width: 900px) { [data-panel="portfolio-list"] .card-grid { grid-template-columns: repeat(2, 1fr) !important; } }
        @media (max-width: 560px) { [data-panel="portfolio-list"] .card-grid { grid-template-columns: 1fr !important; } }
        .portfolio-case-formlink { display: none !important; }

        .item-card { position: relative; background: #181A23; border: 1px solid rgba(255,255,255,.05); border-radius: 17px; overflow: hidden; cursor: pointer; box-shadow: 0 0 25px rgba(255,136,0,.08); transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease; }
        .item-card:hover { transform: scale(1.03); box-shadow: 0 0 42px rgba(255,136,0,.22), 0 14px 32px rgba(0,0,0,.45); border-color: rgba(249,115,22,.35); }
        .item-card-media { position: relative; width: 100%; aspect-ratio: 4 / 3; background: #0d0d12; overflow: hidden; }
        .item-card-media img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .item-card-media .no-media { display:flex; align-items:center; justify-content:center; height:100%; color:#3d3d4a; font-size:34px; }
        .item-card-body { padding: 13px 15px 15px; }
        .item-card-title { font-size: 14px; font-weight: 800; color: #fff; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .item-card-sub { font-size: 11.5px; color: #8a8a96; margin-bottom: 10px; text-transform: uppercase; letter-spacing: .3px; }
        .item-card-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .item-card-price { font-weight: 900; color: #fdba74; font-size: 13px; }
        .item-card-badge { font-size: 10.5px; font-weight: 800; padding: 3px 10px; border-radius: 999px; background: rgba(34,197,94,.15); color: #86efac; white-space: nowrap; }
        .item-card-badge.off { background: rgba(255,255,255,.06); color: #8a8a96; }
        .empty-hint { color: #666674; font-size: 13px; text-align: center; padding: 30px 10px; }

        .drawer-overlay { position: fixed; inset: 0; background: rgba(5,5,8,.65); backdrop-filter: blur(3px); z-index: 300; opacity: 0; pointer-events: none; transition: opacity .25s ease; }
        .drawer-overlay.open { opacity: 1; pointer-events: auto; }
        .edit-drawer { position: fixed; top: 50%; left: 50%; transform: translate(-50%,-46%) scale(.97); width: 460px; max-width: 92vw; max-height: 86vh; background: linear-gradient(180deg,#15151d,#111116); border: 1px solid rgba(255,255,255,.07); border-radius: 22px; z-index: 301; padding: 26px 26px 24px; overflow-y: auto; opacity: 0; pointer-events: none; transition: opacity .25s ease, transform .25s cubic-bezier(.4,0,.2,1); box-shadow: 0 0 0 rgba(0,0,0,0); }
        .edit-drawer.open { opacity: 1; pointer-events: auto; transform: translate(-50%,-50%) scale(1); box-shadow: 0 0 50px rgba(249,115,22,.15), 0 30px 80px rgba(0,0,0,.6); }
        .edit-drawer-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
        .edit-drawer-head h3 { font-size: 16px; margin: 0; display: flex; align-items: center; gap: 8px; }
        .edit-drawer-close { background: transparent; border: 1px solid #2a2a38; color: #8a8a96; font-size: 15px; width: 30px; height: 30px; border-radius: 8px; cursor: pointer; line-height: 1; transition: .2s; }
        .edit-drawer-close:hover { color: #fff; border-color: #f97316; background: rgba(249,115,22,.12); }
        .edit-drawer .drawer-preview { width: 100%; border-radius: 12px; margin: 14px 0 4px; object-fit: cover; max-height: 180px; background: #0d0d12; }
        .edit-drawer .btn-panel { margin-top: 18px; }
        .drawer-danger { width: 100%; margin-top: 10px; border: 1px solid rgba(255,107,118,.3); background: rgba(255,107,118,.08); color: #ff6b76; border-radius: 10px; padding: 11px 14px; font-weight: 800; font-size: 12.5px; text-align: center; cursor: pointer; text-decoration: none; display: block; transition: .2s; font-family: Montserrat,sans-serif; }
        .drawer-danger:hover { background: rgba(255,107,118,.16); }
        .drawer-toggle { width: 100%; margin-top: 8px; border: 1px solid #2a2a38; background: #171720; color: #d8d8e8; border-radius: 10px; padding: 11px 14px; font-weight: 800; font-size: 12.5px; text-align: center; cursor: pointer; text-decoration: none; display: block; transition: .2s; font-family: Montserrat,sans-serif; }
        .drawer-toggle:hover { border-color: #f97316; background: rgba(249,115,22,.1); color: #fff; }
        .drawer-meta-row { display: flex; justify-content: space-between; font-size: 12px; color: #8a8a96; padding: 8px 0; border-bottom: 1px solid #20202c; }
        .drawer-meta-row b { color: #d8d8e8; font-weight: 700; }

        /* ── Неоновые эмодзи-иконки ── */
        .ico { display: inline-block; filter: sepia(1) saturate(6) hue-rotate(-15deg) brightness(1.25) drop-shadow(0 0 5px rgba(249,115,22,.75)); }
        .neon-ico { filter: sepia(1) saturate(6) hue-rotate(-15deg) brightness(1.3) drop-shadow(0 0 7px rgba(249,115,22,.8)); }
        .item-card-badge .ico, .item-card-price .ico { filter: sepia(1) saturate(6) hue-rotate(-15deg) brightness(1.3) drop-shadow(0 0 4px rgba(249,115,22,.7)); }

        /* ── Неоновые чекбоксы / тумблеры ── */
        input[type="checkbox"] { appearance: none; -webkit-appearance: none; width: 20px; height: 20px; min-width: 20px; border-radius: 6px; border: 1.5px solid #34343f; background: #14141a; cursor: pointer; position: relative; vertical-align: middle; transition: .18s; margin: 0; }
        input[type="checkbox"]:hover { border-color: #f97316; }
        input[type="checkbox"]:checked { background: linear-gradient(135deg,#fb923c,#f97316); border-color: #f97316; box-shadow: 0 0 12px rgba(249,115,22,.55); }
        input[type="checkbox"]:checked::after { content: ''; position: absolute; left: 6px; top: 2px; width: 5px; height: 10px; border: solid #fff; border-width: 0 2px 2px 0; transform: rotate(45deg); }
        .tg-checkbox { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 12.5px; color: #d8d8e8; font-weight: 700; }
    </style>
</head>
<body>
<div class="drawer-overlay" id="drawer-overlay" onclick="closeDrawers()"></div>

<div id="admin-toast"></div>

<main class="admin-shell">
    <div class="admin-top">
        <div class="admin-title">
            <h1>⚙️ Админ-панель Kostlim Design</h1>
            <p>Портфолио, прайс, заказы и деньги в одном месте.</p>
            <div class="admin-meta">
                <?php if ($imgbbKeySet): ?>
                    <span class="ok">✅ ImgBB: <?= $imgbbKeyCount ?> <?= $imgbbKeyCount === 1 ? 'ключ' : ($imgbbKeyCount < 5 ? 'ключа' : 'ключей') ?></span>
                <?php else: ?>
                    <span class="warn">⚠️ IMGBB_API_KEY не задан!</span>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
            <a href="profile.php" class="admin-link-top" style="display:flex;align-items:center;gap:7px;">
                <?php $headerAvatarSrc = imgSrc($currentAvatarFile ?? '', '../uploads/'); ?>
                <img src="<?= htmlspecialchars($headerAvatarSrc ?: 'https://i.imgur.com/w9NThbA.png') ?>" alt="" style="width:22px;height:22px;border-radius:50%;object-fit:cover;" onerror="this.src='https://i.imgur.com/w9NThbA.png'">
                👤 Мой профиль
            </a>
            <a href="../index.php" class="admin-link-top">← На сайт</a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice <?= str_starts_with($message,'✅')||str_starts_with($message,'💾') ? 'success' : (str_starts_with($message,'❌') ? 'error' : '') ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="admin-board">
        <nav class="admin-tabs" aria-label="Разделы">
            <button type="button" class="admin-tab active" data-tab="overview"   onclick="activateAdminTab('overview')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg> Обзор</button>

            <div class="admin-tab-group-label">Контент и цены</div>
            <button type="button" class="admin-tab"        data-tab="portfolio"  onclick="activateAdminTab('portfolio')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="15" rx="2"/><path d="M16 2l-4 5-4-5"/></svg> Портфолио</button>
            <button type="button" class="admin-tab"        data-tab="categories" onclick="activateAdminTab('categories')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg> Категории</button>
            <button type="button" class="admin-tab"        data-tab="price"      onclick="activateAdminTab('price')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> Прайс</button>
            <button type="button" class="admin-tab"        data-tab="promo"      onclick="activateAdminTab('promo')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41L11 22a2 2 0 0 1-2.83 0l-6.17-6.17a2 2 0 0 1 0-2.83L11.59 3.41A2 2 0 0 1 13 2.83L20.59 13.41z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg> Промокоды</button>

            <div class="admin-tab-group-label">Клиенты</div>
            <button type="button" class="admin-tab"        data-tab="orders"     onclick="activateAdminTab('orders')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg> Заказы</button>
            <button type="button" class="admin-tab"        data-tab="appeals"    onclick="activateAdminTab('appeals')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Обращения<?php if (!empty($openAppealsCount)): ?> <span style="background:#f97316;color:#fff;border-radius:999px;padding:1px 7px;font-size:10px;margin-left:4px;"><?= $openAppealsCount ?></span><?php endif; ?></button>
            <button type="button" class="admin-tab"        data-tab="reviews"    onclick="activateAdminTab('reviews')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Отзывы<?php if (!empty($pendingReviews)): ?> <span style="background:#f97316;color:#fff;border-radius:999px;padding:1px 7px;font-size:10px;margin-left:4px;"><?= count($pendingReviews) ?></span><?php endif; ?></button>

            <div class="admin-tab-group-label">Бот и сайт</div>
            <button type="button" class="admin-tab"        data-tab="commands"   onclick="activateAdminTab('commands')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg> Команды</button>
            <button type="button" class="admin-tab"        data-tab="rules"      onclick="activateAdminTab('rules')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 21H3V3h18v18zm-3-10H6"/></svg> Правила</button>
            <button type="button" class="admin-tab"        data-tab="ai-prompt"  onclick="activateAdminTab('ai-prompt')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/></svg> ИИ-промпт</button>

            <div class="admin-tab-group-label">Система</div>
            <button type="button" class="admin-tab"        data-tab="keys"       onclick="activateAdminTab('keys')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg> Ключи и API</button>
            <button type="button" class="admin-tab"        data-tab="logs"       onclick="activateAdminTab('logs')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg> Логи</button>
        </nav>

        <div class="admin-content">
            <section class="stats-grid">
                <div class="stat-card accent"><span>Заработано</span><strong><?= money($revenue['rub']??0) ?> ₽</strong><span><?= money($revenue['uan']??0) ?> ₴</span></div>
                <div class="stat-card"><span>В активе</span><strong><?= money($activeValue['rub']??0) ?> ₽</strong><span><?= money($activeValue['uan']??0) ?> ₴</span></div>
                <div class="stat-card"><span>Всего заказов</span><strong><?= (int)($orderStats['total']??0) ?></strong></div>
                <div class="stat-card"><span>Новые</span><strong><?= (int)($orderStats['pending']??0) ?></strong></div>
                <div class="stat-card warn"><span>Срочные</span><strong><?= (int)($orderStats['urgent']??0) ?></strong></div>
                <div class="stat-card"><span>В процессе</span><strong><?= (int)($orderStats['in_progress']??0) ?></strong></div>
                <div class="stat-card"><span>Готово</span><strong><?= (int)($orderStats['ready']??0) ?></strong></div>
            </section>

            <?php
            // ── БЛОК DonationAlerts (API) ──
            // Спрятан по просьбе: основной статистикой теперь считается
            // ручной блок ниже (Донейшен/Крипта/Монобанк, вводится при
            // нажатии "Готово"). Код оставлен нетронутым внутри if(false) —
            // если понадобится снова включить API-статистику, просто
            // поменяй false на true.
            if (false):
            ?>
            <section class="stats-grid">
                <?php if (!$daConnected): ?>
                    <div class="stat-card accent"><span>DonationAlerts</span><strong>Не подключено</strong><span><a href="<?= htmlspecialchars(daGetAuthorizeUrl()) ?>" style="color:#f97316; text-decoration:none;">Авторизовать</a></span></div>
                    <div class="stat-card"><span>Донатов за месяц</span><strong>$0.00</strong></div>
                    <div class="stat-card"><span>Выведено за месяц</span><strong>$0.00</strong></div>
                    <div class="stat-card"><span>Комиссия</span><strong>$0.00</strong></div>
                <?php else: ?>
                    <div class="stat-card accent"><span>DonationAlerts</span><strong>Подключено</strong><span>Токен сохранён</span></div>
                    <div class="stat-card"><span>Донатов за месяц</span><strong>$<?= number_format($daDonationTotalUsd, 2, '.', '') ?></strong></div>
                    <div class="stat-card"><span>Выведено за месяц</span><strong>$<?= number_format($daPayoutStats['gross'], 2, '.', '') ?></strong></div>
                    <div class="stat-card"><span>Чистыми</span><strong>$<?= number_format($daPayoutStats['net'], 2, '.', '') ?></strong></div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <!-- ── ОСНОВНАЯ СТАТИСТИКА: ЗАРАБОТОК ПО СПОСОБАМ ОПЛАТЫ (ручной ввод при "Готово") ── -->
            <div id="earnings" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                <span style="color:#8a8a96;font-size:12px;text-transform:uppercase;letter-spacing:.5px;">Статистика заработка</span>
                <button type="button" onclick="document.getElementById('manualEarningModal').style.display='flex'" title="Добавить/убрать сумму вручную" style="width:26px;height:26px;border-radius:50%;border:1px solid rgba(249,115,22,.4);background:rgba(249,115,22,.15);color:#fdba74;font-size:16px;font-weight:900;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;">+</button>
            </div>
            <section class="stats-grid" style="margin-bottom:0;">
                <div class="stat-card" style="background:rgba(249,115,22,.08);border-color:rgba(249,115,22,.3);">
                    <span>💳 Донейшен</span>
                    <strong><?= money($payByMethod['donation']) ?> ₽</strong>
                    <span style="font-size:11px;color:#888;">$<?= number_format($payByMethod['donation']/$usdRate,2,'.',',') ?></span>
                </div>
                <div class="stat-card" style="background:rgba(96,165,250,.08);border-color:rgba(96,165,250,.3);">
                    <span>₿ Крипта</span>
                    <strong><?= money($payByMethod['crypto']) ?> ₽</strong>
                    <span style="font-size:11px;color:#888;">$<?= number_format($payByMethod['crypto']/$usdRate,2,'.',',') ?></span>
                </div>
                <div class="stat-card" style="background:rgba(34,197,94,.08);border-color:rgba(34,197,94,.3);">
                    <span>🏦 Монобанк</span>
                    <strong><?= money($payByMethod['monobank']) ?> ₽</strong>
                    <span style="font-size:11px;color:#888;">₴<?= number_format($payByMethod['monobank']/$uahRate,2,'.',',') ?></span>
                </div>
            </section>
            <section class="stats-grid" style="margin-top:8px;">
                <div class="stat-card accent" style="grid-column:span 3;">
                    <span>💰 Итого заработано (все способы)</span>
                    <div style="display:flex;gap:24px;align-items:center;flex-wrap:wrap;margin-top:4px;">
                        <strong style="font-size:22px;"><?= money($totalRubFromPaid) ?> ₽</strong>
                        <span style="color:#f97316;font-size:16px;font-weight:800;">$<?= number_format($totalUsdFromPaid,2,'.',',') ?></span>
                        <span style="color:#60a5fa;font-size:16px;font-weight:800;">₴<?= number_format($totalUahFromPaid,2,'.',',') ?></span>
                        <span style="font-size:11px;color:#555;margin-left:auto;">Курс: 1$ = <?= number_format($usdRate,2) ?>₽ · 1₴ = <?= number_format($uahRate,4) ?>₽</span>
                    </div>
                </div>
            </section>

            <?php
                // Последние ручные начисления — чтобы было видно, что и когда
                // добавляли руками, и можно было удалить, если ошиблись.
                $recentManual = [];
                try { $recentManual = $pdo->query("SELECT * FROM manual_earnings ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
            ?>
            <?php if ($recentManual): ?>
            <div style="margin-top:10px;display:flex;flex-direction:column;gap:6px;">
                <?php foreach ($recentManual as $me): ?>
                    <?php $meAmt = (float)$me['amount']; ?>
                    <div style="display:flex;align-items:center;gap:10px;font-size:12px;color:#9a9aa8;background:#14141c;border:1px solid #22222e;border-radius:8px;padding:7px 10px;">
                        <span style="color:<?= $meAmt >= 0 ? '#4ade80' : '#f87171' ?>;font-weight:800;"><?= $meAmt >= 0 ? '+' : '' ?><?= number_format($meAmt,2,'.',',') ?> <?= htmlspecialchars($me['currency']) ?></span>
                        <span><?= htmlspecialchars(['donation'=>'Донейшен','crypto'=>'Крипта','monobank'=>'Монобанк','other'=>'Другое'][$me['method']] ?? $me['method']) ?></span>
                        <?php if (!empty($me['note'])): ?><span style="color:#666;">— <?= htmlspecialchars($me['note']) ?></span><?php endif; ?>
                        <span style="margin-left:auto;color:#555;"><?= date('d.m H:i', strtotime($me['created_at'])) ?></span>
                        <a href="?delete_manual_earning_id=<?= (int)$me['id'] ?>#earnings" onclick="return confirm('Удалить эту запись из статистики?')" style="color:#ef4444;text-decoration:none;font-weight:800;">✕</a>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Модалка ручного добавления суммы -->
            <div id="manualEarningModal" class="modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.6);display:none;align-items:center;justify-content:center;z-index:9999;">
                <form method="POST" style="background:#171720;border:1px solid #2a2a38;border-radius:14px;padding:22px;width:320px;display:flex;flex-direction:column;gap:12px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <strong style="color:#fff;font-size:15px;">➕ Изменить сумму</strong>
                        <button type="button" onclick="document.getElementById('manualEarningModal').style.display='none'" style="background:none;border:0;color:#888;font-size:18px;cursor:pointer;">×</button>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <label style="flex:1;cursor:pointer;">
                            <input type="radio" name="me_sign" value="add" checked style="display:none;" onchange="this.closest('form').querySelector('#meSignAdd').style.background='rgba(34,197,94,.18)';this.closest('form').querySelector('#meSignAdd').style.borderColor='#4ade80';this.closest('form').querySelector('#meSignSub').style.background='transparent';this.closest('form').querySelector('#meSignSub').style.borderColor='#2a2a38';">
                            <div id="meSignAdd" style="text-align:center;padding:8px;border-radius:8px;border:1px solid #4ade80;background:rgba(34,197,94,.18);color:#4ade80;font-weight:800;font-size:12px;">➕ Добавить</div>
                        </label>
                        <label style="flex:1;cursor:pointer;">
                            <input type="radio" name="me_sign" value="subtract" style="display:none;" onchange="this.closest('form').querySelector('#meSignSub').style.background='rgba(239,68,68,.18)';this.closest('form').querySelector('#meSignSub').style.borderColor='#ef4444';this.closest('form').querySelector('#meSignAdd').style.background='transparent';this.closest('form').querySelector('#meSignAdd').style.borderColor='#2a2a38';">
                            <div id="meSignSub" style="text-align:center;padding:8px;border-radius:8px;border:1px solid #2a2a38;color:#f87171;font-weight:800;font-size:12px;">➖ Убрать</div>
                        </label>
                    </div>
                    <label style="font-size:12px;color:#9a9aa8;">Способ
                        <select name="me_method" style="width:100%;margin-top:4px;background:#0f0f16;color:#fff;border:1px solid #2a2a38;border-radius:8px;padding:8px 10px;">
                            <option value="donation">💳 Донейшен</option>
                            <option value="crypto">₿ Крипта</option>
                            <option value="monobank">🏦 Монобанк</option>
                            <option value="other">💰 Другое</option>
                        </select>
                    </label>
                    <div style="display:flex;gap:8px;">
                        <label style="font-size:12px;color:#9a9aa8;flex:1;">Сумма
                            <input type="text" name="me_amount" required placeholder="1000" style="width:100%;margin-top:4px;background:#0f0f16;color:#fff;border:1px solid #2a2a38;border-radius:8px;padding:8px 10px;box-sizing:border-box;">
                        </label>
                        <label style="font-size:12px;color:#9a9aa8;width:90px;">Валюта
                            <select name="me_currency" style="width:100%;margin-top:4px;background:#0f0f16;color:#fff;border:1px solid #2a2a38;border-radius:8px;padding:8px 10px;">
                                <option value="RUB">₽ RUB</option>
                                <option value="USD">$ USD</option>
                                <option value="UAH">₴ UAH</option>
                            </select>
                        </label>
                    </div>
                    <label style="font-size:12px;color:#9a9aa8;">Заметка (необязательно)
                        <input type="text" name="me_note" placeholder="Например: перевод мимо бота" style="width:100%;margin-top:4px;background:#0f0f16;color:#fff;border:1px solid #2a2a38;border-radius:8px;padding:8px 10px;box-sizing:border-box;">
                    </label>
                    <button type="submit" name="add_manual_earning" style="margin-top:4px;border:0;border-radius:10px;padding:11px;background:linear-gradient(135deg,#fb923c,#f97316);color:#fff;font-weight:800;cursor:pointer;">Сохранить</button>
                </form>
            </div>

            <!-- ════════════════════════════════════════════════════════════
                 ПРОМОКОДЫ — отдельная вкладка, тот же паттерн: форма → карточки → drawer
            ════════════════════════════════════════════════════════════ -->
            <div class="panel tab-hidden section-block" data-panel="promo">
                <button type="button" class="create-toggle-btn" id="promo-create-toggle-btn" onclick="toggleCreatePanel('promo-create-card', this)">➕ Создать промокод</button>
                <div class="create-card collapsed" id="promo-create-card">
                    <div class="create-card-head">
                        <h2><span class="ico">🎟</span> Создать промокод</h2>
                        <button type="button" class="create-card-close" onclick="hideCreatePanel('promo-create-card','promo-create-toggle-btn')">✕</button>
                        <p>Каждый промокод одноразовый на человека: повторно ввести код, уже занятый в заказе, клиент не сможет</p>
                    </div>
                    <form id="promo-form" method="POST">
                        <div class="two-cols">
                            <div><label><span class="ico">🔤</span> Код</label><input type="text" name="pc_code" required placeholder="NEWYEAR2027" style="text-transform:uppercase;"></div>
                            <div><label>％ Скидка (необязательно)</label><input type="number" name="pc_discount" min="0" max="100" placeholder="10"></div>
                        </div>
                        <label><span class="ico">🎁</span> Бонус (текст, покажется клиенту)</label>
                        <input type="text" name="pc_bonus" placeholder="Например: бесплатная доп. правка">
                        <hr class="divider">
                        <div class="two-cols">
                            <div>
                                <label>⏳ Срок действия</label>
                                <select name="pc_duration" id="pc_duration_select" onchange="document.getElementById('pc_custom_date_wrap').style.display = this.value==='custom' ? 'block' : 'none';">
                                    <option value="none">Без срока</option>
                                    <option value="7">Неделя (7 дней)</option>
                                    <option value="10">10 дней</option>
                                    <option value="15">15 дней</option>
                                    <option value="20">20 дней</option>
                                    <option value="25">25 дней</option>
                                    <option value="30">Месяц (30 дней)</option>
                                    <option value="custom">Другая дата…</option>
                                </select>
                            </div>
                            <div><label><span class="ico">🔢</span> Лимит активаций</label><input type="number" name="pc_max_uses" min="1" placeholder="без лимита"></div>
                        </div>
                        <div id="pc_custom_date_wrap" style="display:none;">
                            <label><span class="ico">📅</span> Дата окончания</label>
                            <input type="date" name="pc_custom_date">
                        </div>
                        <button type="submit" name="add_promo" class="btn-panel" id="promo-submit-btn">🟠 Создать промокод</button>
                    </form>
                </div>

                <?php
                    $promoList = [];
                    try { $promoList = $pdo->query("SELECT * FROM promo_codes ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
                ?>
                <div class="grid-wrap">
                    <div class="card-grid" id="promo-grid">
                        <?php foreach ($promoList as $pc): echo renderPromoCardHtml($pc); ?><?php endforeach; ?>
                    </div>
                    <p class="empty-hint" id="promo-empty-hint" style="<?= $promoList ? 'display:none;' : '' ?>">Промокодов пока нет — создайте первый выше 👆</p>
                </div>
            </div>

            <!-- ════════════════════════════════════════════════════════════
                 ОБЗОР + ОСТАЛЬНЫЕ ПАНЕЛИ
            ════════════════════════════════════════════════════════════ -->

            <div class="admin-layout">
                <aside>
                    <!-- ════ ОТЗЫВЫ ════ -->
                    <div class="panel" data-panel="reviews">
                        <h2><span class="ico">⭐</span> Отзывы на проверку</h2>
                        <?php if (empty($pendingReviews)): ?>
                            <div style="color:#555568;font-size:13px;">Все отзывы одобрены! 🎉</div>
                        <?php else: ?>
                            <?php foreach ($pendingReviews as $rv): ?>
                                <div class="review-card pending">
                                    <div class="review-header">
                                        <span class="name"><?= htmlspecialchars($rv['tg_first_name'] ?: ('Клиент #' . $rv['order_id'])) ?></span>
                                        <span class="rating"><?= str_repeat('★', (int)$rv['rating']) . str_repeat('☆', 5 - (int)$rv['rating']) ?></span>
                                        <div class="review-actions" style="margin-left:auto;">
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="review_id" value="<?= (int)$rv['id'] ?>">
                                                <button type="submit" name="approve_review" class="review-btn approve">✅ Одобрить</button>
                                            </form>
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="review_id" value="<?= (int)$rv['id'] ?>">
                                                <button type="submit" name="reject_review" onclick="return confirm('Удалить отзыв?')" class="review-btn reject">🗑️ Отклонить</button>
                                            </form>
                                        </div>
                                    </div>
                                    <div style="color:#d8d8e8;font-size:13px;line-height:1.55;word-break:break-word;"><?= nl2br(htmlspecialchars($rv['text'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <hr style="border:none;border-top:1px solid #20202c;margin:14px 0;"/>
                        <h2 style="margin-top:18px;"><span class="ico">✅</span> Одобренные</h2>
                        <?php if (empty($approvedReviews)): ?>
                            <div style="color:#555568;font-size:13px;">Нет одобренных отзывов.</div>
                        <?php else: ?>
                            <?php foreach (array_slice($approvedReviews, 0, 5) as $rv): ?>
                                <div class="review-card" style="background:rgba(34,197,94,.05);border-color:rgba(34,197,94,.25);">
                                    <div class="review-header">
                                        <span class="name"><?= htmlspecialchars($rv['tg_first_name'] ?: ('Клиент #' . $rv['order_id'])) ?></span>
                                        <span class="rating" style="color:#86efac;"><?= str_repeat('★', (int)$rv['rating']) ?></span>
                                    </div>
                                    <div style="color:#c8c8d8;font-size:12px;line-height:1.5;max-height:60px;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;"><?= nl2br(htmlspecialchars($rv['text'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- ════ КОМАНДЫ БОТА ════ -->
                    <div class="panel" data-panel="commands">
                        <h2><span class="ico">🤖</span> Команды бота</h2>
                        <form method="POST">
                            <div style="display:grid;gap:8px;margin-bottom:12px;">
                                <?php foreach ($botCommands as $cmd): ?>
                                    <div class="cmd-row">
                                        <input type="text" name="bot_commands[<?= (int)$cmd['id'] ?>][command]" value="<?= htmlspecialchars($cmd['command'] ?? '') ?>" style="margin:0;">
                                        <textarea name="bot_commands[<?= (int)$cmd['id'] ?>][description]" rows="1" style="margin:0;"><?= htmlspecialchars($cmd['description'] ?? '') ?></textarea>
                                        <select name="bot_commands[<?= (int)$cmd['id'] ?>][access_level]" style="margin:0;">
                                            <option value="admin" <?= ($cmd['access_level'] ?? '') === 'admin' ? 'selected' : '' ?>>admin</option>
                                            <option value="user" <?= ($cmd['access_level'] ?? '') === 'user' ? 'selected' : '' ?>>user</option>
                                        </select>
                                        <button type="button" onclick="if(confirm('Удалить команду?')) { this.closest('.cmd-row').style.opacity='.3'; this.closest('.cmd-row').querySelector('.del-chk').checked=true; this.closest('.cmd-row').style.pointerEvents='none'; }" style="border:1px solid #dc2626;background:rgba(220,38,38,.15);color:#fca5a5;cursor:pointer;border-radius:6px;font-weight:800;padding:4px;">✕</button>
                                        <input type="checkbox" class="del-chk" name="delete_commands[]" value="<?= (int)$cmd['id'] ?>" style="display:none;">
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <hr style="border:none;border-top:1px solid #20202c;margin:12px 0;"/>
                            <h3 style="font-size:12px;color:#8a8a96;text-transform:uppercase;margin:12px 0 8px;">Добавить новую</h3>
                            <div style="display:grid;gap:6px;margin-bottom:12px;">
                                <input type="text" name="new_command_name" placeholder="/command" style="margin:0;padding:8px 10px;">
                                <textarea name="new_command_desc" rows="1" placeholder="Описание" style="margin:0;padding:8px 10px;"></textarea>
                            </div>

                            <button type="submit" name="save_bot_commands" class="btn-panel" style="margin-top:8px;">💾 Сохранить команды</button>
                        </form>
                    </div>

                    <!-- ════ ПРАВИЛА ЗАКАЗА ════ -->
                    <div class="panel" data-panel="rules">
                        <h2><span class="ico">📋</span> Правила заказа</h2>
                        <form method="POST">
                            <label>Текст правил (HTML поддерживается)</label>
                            <textarea name="order_rules_text" rows="6" style="margin-bottom:12px;font-family:monospace;font-size:11px;"><?= htmlspecialchars($orderRulesText) ?></textarea>
                            <div style="color:#8a8a96;font-size:11px;margin-bottom:12px;line-height:1.6;">💡 Эти правила будут отображаться при оформлении заказа. Поддерживаются HTML теги: &lt;b&gt;, &lt;i&gt;, &lt;br&gt;, &lt;a href=""&gt;</div>
                            <button type="submit" name="save_order_rules" class="btn-panel" style="margin-top:8px;">💾 Сохранить правила</button>
                        </form>
                    </div>

                    <!-- ════ ПОРТФОЛИО: ДОБАВЛЕНИЕ ════ -->
                    <section class="panel section-block" data-panel="portfolio-add">
                        <button type="button" class="create-toggle-btn" id="portfolio-create-toggle-btn" onclick="toggleCreatePanel('portfolio-create-card', this)">➕ Добавить в портфолио</button>
                        <div class="create-card collapsed" id="portfolio-create-card">
                            <div class="create-card-head">
                                <h2><span class="ico">✨</span> Добавить в портфолио</h2>
                                <button type="button" class="create-card-close" onclick="hideCreatePanel('portfolio-create-card','portfolio-create-toggle-btn')">✕</button>
                                <p>Загрузи новую работу — она появится в кейсах и на сайте</p>
                            </div>
                            <form id="portfolio-form" enctype="multipart/form-data">
                                <div class="two-cols">
                                    <div><label><span class="ico">📝</span> Название проекта</label><input type="text" name="title" required placeholder="Например: сет Naruto"></div>
                                    <div>
                                        <label><span class="ico">🖼</span> Категория графики</label>
                                        <select name="category_key" id="category_select" onchange="toggleAvatarField(); applyCategoryPrice();">
                                            <?php foreach ($categories as $category): ?>
                                                <option value="<?= htmlspecialchars($category['category_key']) ?>">
                                                    <?= htmlspecialchars($category['title']) ?>
                                                    <?php if ((int)$category['width_px']>0 && (int)$category['height_px']>0): ?> (<?= (int)$category['width_px'] ?>x<?= (int)$category['height_px'] ?>)<?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <hr class="divider">
                                <div class="two-cols">
                                    <div><label><span class="ico">💰</span> Цена в рублях</label><input type="number" name="price_rub" id="portfolio_price_rub" value="0" min="0"></div>
                                    <div><label><span class="ico">💵</span> Цена в гривнах</label><input type="number" name="price_uan" id="portfolio_price_uan" value="0" min="0"></div>
                                </div>
                                <div class="avatar-hint">Цена подтягивается из услуги в прайсе, привязанной к этой категории (её можно поменять здесь вручную только для этого кейса).</div>
                                <hr class="divider">
                                <label><span class="ico">🖼</span> Главное изображение / шапка</label>
                                <input type="file" name="image" accept="image/*" required>
                                <div id="avatar_upload_block" style="display:none;">
                                    <label><span class="ico">👤</span> Аватарка к оформлению</label>
                                    <input type="file" name="avatar_image" accept="image/*">
                                    <div class="avatar-hint">Для категории "Оформление" шапка широкая, аватарка — круглое превью.</div>
                                </div>
                                <label class="tg-checkbox" style="margin-top:16px;"><input type="checkbox" name="publish_tg" value="1" checked> ✈️ Публиковать в Telegram-канал</label>
                                <button type="submit" class="btn-panel" id="portfolio-submit-btn">
                                    <span class="btn-text">🟠 Добавить проект</span>
                                    <span class="btn-spinner"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin 1s linear infinite"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Загружаем на ImgBB...</span>
                                </button>
                            </form>
                        </div>
                        <style>@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}</style>
                    </section>

                    <!-- ════ КАТЕГОРИИ ════ -->
                    <section class="panel section-block" data-panel="categories">
                        <button type="button" class="create-toggle-btn" id="categories-create-toggle-btn" onclick="toggleCreatePanel('categories-create-card', this)">➕ Создать категорию</button>
                        <div class="create-card collapsed" id="categories-create-card">
                            <div class="create-card-head">
                                <h2><span class="ico">📂</span> Создать категорию</h2>
                                <button type="button" class="create-card-close" onclick="hideCreatePanel('categories-create-card','categories-create-toggle-btn')">✕</button>
                                <p>Категории используются в портфолио и прайсе для группировки работ</p>
                            </div>
                            <form id="category-form" action="" method="POST">
                                <div class="two-cols">
                                    <div><label><span class="ico">📝</span> Название категории</label><input type="text" name="cat_title" required placeholder="Например: Пост VK"></div>
                                    <div><label><span class="ico">🔑</span> Ключ категории</label><input type="text" name="cat_key" placeholder="vk_post"></div>
                                </div>
                                <div class="avatar-hint">Ключ — латиницей, без пробелов. Вместе с категорией сразу создаётся связанная услуга в прайсе с тем же ключом (цену 0₽/0₴ потом поменяешь во вкладке «Прайс»).</div>
                                <hr class="divider">
                                <div class="two-cols">
                                    <div><label><span class="ico">↔️</span> Ширина рамки, px</label><input type="number" name="cat_width" min="0" placeholder="1920"></div>
                                    <div><label><span class="ico">↕️</span> Высота рамки, px</label><input type="number" name="cat_height" min="0" placeholder="1080"></div>
                                </div>
                                <label class="tg-checkbox" style="margin-top:16px;"><input type="checkbox" name="cat_is_design" value="1"> 👤 Это оформление с аватаркой</label>
                                <button type="submit" name="add_portfolio_category" class="btn-panel" id="category-submit-btn">🟠 Добавить категорию</button>
                            </form>
                        </div>

                        <div class="grid-wrap">
                            <div class="card-grid" id="categories-grid">
                                <?php foreach ($categories as $category): echo renderCategoryCardHtml($category); ?><?php endforeach; ?>
                            </div>
                            <p class="empty-hint" id="categories-empty-hint" style="<?= $categories ? 'display:none;' : '' ?>">Категорий пока нет — добавьте первую выше 👆</p>
                        </div>
                    </section>

                    <!-- ════ ЗАКАЗЫ ════ -->
                    <section class="panel" data-panel="orders">
                        <h2><span class="ico">🧾</span> Заказы</h2>
                        <?php if (!empty($pendingOrders)): ?>
                            <div style="margin-bottom:14px;padding:14px;border:1px solid rgba(249,115,22,.25);background:rgba(249,115,22,.08);border-radius:12px;">
                                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                                    <strong>Новые заказы:</strong>
                                    <span style="background:#f97316;color:#111827;padding:6px 10px;border-radius:999px;font-size:13px;font-weight:800;"><?= count($pendingOrders) ?></span>
                                </div>
                                <div style="margin-top:12px;display:grid;gap:10px;">
                                    <?php foreach ($pendingOrders as $pord): ?>
                                        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 12px;border:1px solid rgba(255,255,255,.06);border-radius:10px;background:#0f0f14;">
                                            <div style="min-width:0;overflow:hidden;">
                                                <div style="font-size:13px;color:#f8f8fa;">Заказ #<?= (int)$pord['id'] ?> — <?= htmlspecialchars($pord['username'] ?: 'Клиент') ?></div>
                                                <div style="color:#8a8a96;font-size:12px;"><?= htmlspecialchars($pord['telegram'] ?: '—') ?> · <?= date('d.m.Y H:i', strtotime($pord['created_at'])) ?></div>
                                            </div>
                                            <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
                                                <a href="<?= $_SERVER['PHP_SELF'] ?>?view_order=<?= (int)$pord['id'] ?>" class="btn-panel" style="background:#262640;padding:8px 12px;margin-top:0;">Открыть</a>
                                                <details class="accept-menu">
                                                    <summary class="btn-panel" style="background:#10b981;padding:8px 12px;margin-top:0;list-style:none;cursor:pointer;">Принять ▾</summary>
                                                    <div class="accept-menu-popup">
                                                        <form method="POST" style="margin:0;"><input type="hidden" name="order_id" value="<?= (int)$pord['id'] ?>"><button type="submit" name="order_action" value="accept_normal">✅ Обычный (5 сут.)</button></form>
                                                        <form method="POST" style="margin:0;"><input type="hidden" name="order_id" value="<?= (int)$pord['id'] ?>"><button type="submit" name="order_action" value="accept_urgent">⚡️ Срочный (24ч, +50%)</button></form>
                                                        <form method="POST" style="margin:0;"><input type="hidden" name="order_id" value="<?= (int)$pord['id'] ?>"><button type="submit" name="order_action" value="cooperation">🤝 Сотрудничество</button></form>
                                                        <form method="POST" style="margin:0;"><input type="hidden" name="order_id" value="<?= (int)$pord['id'] ?>"><button type="submit" name="order_action" value="accept_queue">📥 Просто в очередь</button></form>
                                                    </div>
                                                </details>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                            <form method="GET" style="display:flex;gap:8px;align-items:center;">
                                <label style="color:#8a8a96;font-size:13px;text-transform:none;margin:0;">Статус:</label>
                                <select name="orders_status" onchange="this.form.submit()">
                                    <option value="">Все</option>
                                    <?php foreach ($statusLabels as $sk => $sv): ?>
                                        <option value="<?= htmlspecialchars($sk) ?>" <?= $orders_status === $sk ? 'selected' : '' ?>><?= htmlspecialchars($sv) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <div style="margin-left:auto;color:#8a8a96;font-size:13px;">Всего: <strong><?= $ordersTotal ?></strong></div>
                        </div>
                        <div class="admin-orders-cards">
                            <?php foreach ($recentOrders as $order): ?>
                                <?php
                                    $isUrgent = $order['status'] === 'urgent';
                                    $deadlineHtml = '';
                                    if (!empty($order['deadline'])) {
                                        $deadlineHtml = deadlineBadge($order['deadline'], $isUrgent, $order['status']);
                                    } elseif (in_array($order['status'], ['in_progress','urgent'], true)) {
                                        $fallbackDl = calcDeadline($order['created_at'], $isUrgent);
                                        if ($fallbackDl) $deadlineHtml = deadlineBadge($fallbackDl->format('Y-m-d H:i:s'), $isUrgent, $order['status']);
                                    }
                                    $viewUrl = $_SERVER['PHP_SELF'] . '?view_order=' . (int)$order['id'];
                                ?>
                                <div class="admin-order-card status-<?= htmlspecialchars($order['status']) ?>" onclick="window.location.href='<?= htmlspecialchars($viewUrl) ?>'" role="link" tabindex="0" onkeypress="if(event.key==='Enter')window.location.href='<?= htmlspecialchars($viewUrl) ?>'">
                                    <div class="admin-order-card-id">#<?= (int)$order['id'] ?></div>
                                    <div class="admin-order-card-main">
                                        <div class="admin-order-card-client"><?= htmlspecialchars($order['username']??'Клиент') ?> <span><?= htmlspecialchars($order['telegram']??'') ?></span></div>
                                        <div class="admin-order-card-status-row">
                                            <span class="status status-<?= htmlspecialchars($order['status']) ?>"><?= htmlspecialchars($statusLabels[$order['status']]??$order['status']) ?></span>
                                            <?php if ($deadlineHtml): ?><?= $deadlineHtml ?><?php endif; ?>
                                            <?php if (!empty($order['cooperation'])): ?>
                                                <span style="color:#fb923c;font-size:11px;padding:3px 8px;border:1px solid rgba(251,146,60,.25);border-radius:8px;">Сотрудничество</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="admin-order-card-price"><?= (int)($order['price_rub']??0) ?> ₽<br><span><?= (int)($order['price_uan']??0) ?> ₴</span></div>
                                    <?php if ($order['status'] === 'pending'): ?>
                                        <details class="accept-menu" onclick="event.stopPropagation();" style="margin-top:0;">
                                            <summary class="btn-panel admin-order-card-accept" style="background:#10b981;margin-top:0;padding:8px 14px;list-style:none;cursor:pointer;">Принять ▾</summary>
                                            <div class="accept-menu-popup">
                                                <form method="POST" style="margin:0;"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><button type="submit" name="order_action" value="accept_normal">✅ Обычный (5 сут.)</button></form>
                                                <form method="POST" style="margin:0;"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><button type="submit" name="order_action" value="accept_urgent">⚡️ Срочный (24ч, +50%)</button></form>
                                                <form method="POST" style="margin:0;"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><button type="submit" name="order_action" value="cooperation">🤝 Сотрудничество</button></form>
                                                <form method="POST" style="margin:0;"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><button type="submit" name="order_action" value="accept_queue">📥 Просто в очередь</button></form>
                                            </div>
                                        </details>
                                    <?php endif; ?>
                                    <span class="admin-order-card-chevron">→</span>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($recentOrders)): ?>
                                <div style="color:#8a8a96;padding:20px;text-align:center;">Заказов нет.</div>
                            <?php endif; ?>
                        </div>
                        <?php if ($ordersTotalPages > 1): ?>
                            <div style="display:flex;gap:8px;align-items:center;margin-top:12px;">
                                <?php for ($p = 1; $p <= $ordersTotalPages; $p++): ?>
                                    <a href="<?= $_SERVER['PHP_SELF'] . '?orders_page=' . $p . ($orders_status !== '' ? '&orders_status=' . urlencode($orders_status) : '') ?>" class="btn-panel" style="width:auto;padding:8px 12px;margin-top:0;<?= $p === $orders_page ? '' : 'opacity:0.7;' ?>"><?= $p ?></a>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- ════ ЛОГИ ════ -->
                    <section class="panel" data-panel="logs">
                        <h2><span class="ico">📝</span> Логи ошибок бота</h2>
                        <p style="color:#8a8a96;font-size:13px;margin:-4px 0 14px;">
                            Последние записи из <code>bot_debug.log</code> — сюда пишутся все сбои доставки
                            сообщений клиентам, ошибки БД и т.п. Полезно, когда что-то "не приходит" и
                            непонятно почему.
                        </p>
                        <?php
                            $logFilePath = __DIR__ . '/../bot_debug.log';
                            $logLines = [];
                            if (is_file($logFilePath)) {
                                $allLines = @file($logFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                                $logLines = array_slice($allLines, -200); // последние 200 строк
                                $logLines = array_reverse($logLines);     // новые сверху
                            }
                        ?>
                        <?php if (empty($logLines)): ?>
                            <div style="color:#8a8a96;padding:20px;text-align:center;border:1px dashed #2a2a38;border-radius:12px;">
                                Логов пока нет (или файл ещё не создан — появится после первой ошибки).
                                <br><span style="font-size:11px;">Диск на Render эфемерный — после рестарта сервера старые логи пропадают.</span>
                            </div>
                        <?php else: ?>
                            <form action="" method="POST" style="margin-bottom:10px;" onsubmit="return confirm('Очистить лог-файл?');">
                                <button type="submit" name="clear_bot_log" value="1" class="btn-panel" style="background:#3a1a1a;width:auto;padding:8px 14px;margin-top:0;">🗑️ Очистить лог</button>
                            </form>
                            <div style="max-height:520px;overflow-y:auto;background:#0c0c12;border:1px solid #23232f;border-radius:12px;padding:14px;font-family:monospace;font-size:12px;line-height:1.7;">
                                <?php foreach ($logLines as $line): ?>
                                    <?php
                                        $isError = (stripos($line, 'error') !== false || stripos($line, 'failed') !== false || stripos($line, 'no chat_id') !== false);
                                    ?>
                                    <div style="color:<?= $isError ? '#f87171' : '#a0a0b0' ?>;white-space:pre-wrap;word-break:break-all;padding:3px 0;border-bottom:1px solid #17171f;"><?= htmlspecialchars($line) ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- ════ ПРОМПТ ИИ-ПОМОЩНИКА ════ -->
                    <section class="panel" data-panel="ai-prompt">
                        <h2><span class="ico">🤖</span> Промпт ИИ-помощника</h2>
                        <p style="color:#8a8a96;font-size:13px;margin:-4px 0 14px;">
                            Инструкция, с которой ИИ-виджет на сайте общается с клиентами. Меняй цены,
                            акции, ссылки и правила здесь — без изменения кода. Если поле пустое,
                            используется встроенный текст по умолчанию.
                        </p>
                        <?php
                            $currentAiPrompt = '';
                            try {
                                $aiPromptStmt = $pdo->query("SELECT value FROM site_settings WHERE setting_key = 'ai_system_prompt' LIMIT 1");
                                $currentAiPrompt = $aiPromptStmt ? (string)$aiPromptStmt->fetchColumn() : '';
                            } catch (Throwable $e) {}
                        ?>
                        <form method="POST" action="">
                            <textarea name="ai_system_prompt" rows="20" style="width:100%;box-sizing:border-box;background:#0c0c12;border:1px solid #23232f;border-radius:12px;padding:14px;color:#e0e0ec;font-size:13px;line-height:1.6;font-family:inherit;resize:vertical;margin-bottom:14px;" placeholder="Оставь пустым, чтобы использовать текст по умолчанию из кода…"><?= htmlspecialchars($currentAiPrompt) ?></textarea>
                            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                                <button type="submit" name="save_ai_prompt" class="btn-panel" style="width:auto;padding:12px 22px;margin-top:0;">💾 Сохранить промпт</button>
                                <button type="submit" name="reset_ai_prompt" class="btn-panel" style="width:auto;padding:12px 22px;margin-top:0;background:#3a1a1a;" onclick="return confirm('Сбросить промпт к встроенному тексту по умолчанию?');">↺ Сбросить к дефолту</button>
                            </div>
                        </form>
                    </section>

                    <!-- ════ КЛЮЧИ И API ════ -->
                    <section class="panel" data-panel="keys">
                        <h2><span class="ico">🔑</span> Ключи и API</h2>
                        <p style="color:#8a8a96;font-size:13px;margin:-4px 0 14px;">
                            Все чувствительные значения — токен бота, ImgBB, Gemini, Cloudinary, реквизиты
                            оплаты и т.п. — теперь редактируются прямо тут, без переменных окружения на Render.
                            Сохраняется мгновенно, без перезагрузки страницы.
                        </p>
                        <form id="api-keys-form" onsubmit="return false;">
                            <div class="section-heading" style="font-size:14px;"><span class="neon-ico">⚙️</span> Используются в этом файле сразу</div>
                            <div style="display:grid;gap:12px;margin-bottom:26px;">
                                <?php foreach ($API_KEY_FIELDS['core'] as $f): $cur = getSetting($pdo, $f['key'], ''); ?>
                                    <div>
                                        <label style="display:flex;align-items:center;justify-content:space-between;">
                                            <span><?= htmlspecialchars($f['label']) ?></span>
                                            <span style="color:#4a4a58;font-size:10px;font-weight:600;text-transform:none;">env: <?= htmlspecialchars($f['key']) ?></span>
                                        </label>
                                        <div class="file-upload-wrap" style="gap:6px;">
                                            <input type="<?= $f['secret'] ? 'password' : 'text' ?>" name="<?= htmlspecialchars($f['key']) ?>" id="key-<?= htmlspecialchars($f['key']) ?>" value="<?= htmlspecialchars($cur) ?>" placeholder="не задано — используется значение по умолчанию" style="margin:0;">
                                            <?php if ($f['secret']): ?>
                                            <button type="button" onclick="const i=document.getElementById('key-<?= htmlspecialchars($f['key']) ?>'); i.type = i.type==='password'?'text':'password';" class="mini-file-btn" style="flex-shrink:0;">👁</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="section-heading" style="font-size:14px;"><span class="neon-ico">📓</span> Записная книжка (используются в других файлах проекта)</div>
                            <div class="avatar-hint" style="margin-bottom:14px;">Эти значения читают bot.php, ai_widget.php, donationalerts.php и т.д. напрямую из переменных окружения — сохранённое здесь пока служит удобным хранилищем и подставится туда автоматически только после того, как в тех файлах тоже будет вызван <code>getSetting()</code> вместо <code>getenv()</code>. Спроси меня, если хочешь, чтобы я довёл это до конца.</div>
                            <div style="display:grid;gap:12px;margin-bottom:20px;">
                                <?php foreach ($API_KEY_FIELDS['other'] as $f): $cur = getSetting($pdo, $f['key'], ''); ?>
                                    <div>
                                        <label style="display:flex;align-items:center;justify-content:space-between;">
                                            <span><?= htmlspecialchars($f['label']) ?></span>
                                            <span style="color:#4a4a58;font-size:10px;font-weight:600;text-transform:none;">env: <?= htmlspecialchars($f['key']) ?></span>
                                        </label>
                                        <div class="file-upload-wrap" style="gap:6px;">
                                            <input type="<?= $f['secret'] ? 'password' : 'text' ?>" name="<?= htmlspecialchars($f['key']) ?>" id="key-<?= htmlspecialchars($f['key']) ?>" value="<?= htmlspecialchars($cur) ?>" placeholder="не задано" style="margin:0;">
                                            <?php if ($f['secret']): ?>
                                            <button type="button" onclick="const i=document.getElementById('key-<?= htmlspecialchars($f['key']) ?>'); i.type = i.type==='password'?'text':'password';" class="mini-file-btn" style="flex-shrink:0;">👁</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" onclick="saveApiKeys()" class="btn-panel" id="keys-submit-btn" style="max-width:320px;">💾 Сохранить все ключи</button>
                        </form>
                    </section>

                    <!-- ════ ДОБАВИТЬ УСЛУГУ В ПРАЙС ════ -->
                    <section class="panel section-block" data-panel="price-add">
                        <button type="button" class="create-toggle-btn" id="price-create-toggle-btn" onclick="toggleCreatePanel('price-create-card', this)">➕ Добавить услугу</button>
                        <div class="create-card collapsed" id="price-create-card">
                            <div class="create-card-head">
                                <h2><span class="ico">🎨</span> Добавить услугу</h2>
                                <button type="button" class="create-card-close" onclick="hideCreatePanel('price-create-card','price-create-toggle-btn')">✕</button>
                                <p>Новая позиция появится в прайс-листе и на сайте</p>
                            </div>
                            <form id="service-form" action="" method="POST" enctype="multipart/form-data">
                                <div class="two-cols">
                                    <div><label><span class="ico">📝</span> Название услуги</label><input type="text" name="service_title" required placeholder="Например: Баннер для постов"></div>
                                    <div>
                                        <label><span class="ico">🔑</span> Категория (ключ услуги)</label>
                                        <select name="service_key" id="service_key_select">
                                            <option value="">— выбери категорию портфолио —</option>
                                            <?php foreach ($categories as $categoryOpt): ?>
                                                <option value="<?= htmlspecialchars($categoryOpt['category_key']) ?>"><?= htmlspecialchars($categoryLabels[$categoryOpt['category_key']] ?? $categoryOpt['category_key']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="avatar-hint">Услуга привязывается к категории портфолио по этому ключу — цена, указанная тут, потом сама подставляется при добавлении работ этой категории.</div>
                                    </div>
                                </div>
                                <hr class="divider">
                                <div class="two-cols">
                                    <div><label><span class="ico">💰</span> Цена в рублях</label><input type="number" name="service_price_rub" value="0" min="0"></div>
                                    <div><label><span class="ico">💵</span> Цена в гривнах</label><input type="number" name="service_price_uan" value="0" min="0"></div>
                                </div>
                                <hr class="divider">
                                <label><span class="ico">📄</span> Описание</label>
                                <textarea name="service_description" placeholder="Коротко, что входит в услугу"></textarea>
                                <label><span class="ico">⚡</span> Фичи</label>
                                <input type="text" name="service_features" placeholder="Через | например: PSD-файл|2 правки|быстрая сдача">
                                <label><span class="ico">🖼</span> Обложка услуги</label>
                                <input type="file" name="service_image" accept="image/*">
                                <button type="submit" name="add_price_service" class="btn-panel" id="service-submit-btn">🟠 Добавить услугу</button>
                            </form>
                        </div>
                    </section>

                </aside>

                <section>
                    <!-- ════ МЕНЕДЖЕР ЦЕН ════ -->
                    <div class="panel section-block" data-panel="price-manager">
                        <div class="section-heading"><span class="neon-ico">💲</span> Все услуги</div>
                        <div class="grid-wrap">
                            <form action="" method="POST" enctype="multipart/form-data" id="services-form">
                                <div class="card-grid" id="services-grid">
                                    <?php foreach ($services as $service): echo renderServiceCardHtml($service); ?><?php endforeach; ?>
                                </div>
                                <p class="empty-hint" id="services-empty-hint" style="<?= $services ? 'display:none;' : '' ?>">Услуг пока нет — добавьте первую выше 👆</p>
                            </form>
                        </div>
                    </div>

                    <!-- ════ УПРАВЛЕНИЕ КЕЙСАМИ ════ -->
                    <div class="panel section-block" data-panel="portfolio-list">
                        <div class="section-heading"><span class="neon-ico">🎬</span> Все проекты</div>
                        <div class="grid-wrap">
                            <?php if ($works): ?>
                            <div class="card-grid">
                                <?php foreach ($works as $work): ?>
                                    <?php
                                        $img = $work['image']??''; $ava = $work['avatar_image']??''; $cat = $work['category_key']??'preview';
                                        $fid = 'case-edit-' . (int)$work['id'];
                                        $drawerId = 'drawer-case-' . (int)$work['id'];
                                        $catLabel = $categoryLabels[$cat] ?? $cat;
                                    ?>
                                    <div class="item-card" onclick="openDrawer('<?= $drawerId ?>')">
                                        <div class="item-card-media">
                                            <?php if ($img !== ''): ?><img src="<?= htmlspecialchars(imgSrc($img)) ?>" alt="" draggable="false"><?php else: ?><div class="no-media">🖼</div><?php endif; ?>
                                        </div>
                                        <div class="item-card-body">
                                            <div class="item-card-title"><?= htmlspecialchars($work['title']??'') ?></div>
                                            <div class="item-card-sub">🎨 <?= htmlspecialchars($catLabel) ?></div>
                                            <div class="item-card-foot">
                                                <span class="item-card-price"><span class="ico">💰</span><?= money($work['price_rub']??0) ?> ₽ / <?= money($work['price_uan']??0) ?> ₴</span>
                                                <span class="item-card-badge">🟢 Активно</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Форма кейса объявлена пустой здесь; поля лежат в Drawer ниже
                                         и связаны через атрибут form="<?= $fid ?>" -->
                                    <form id="<?= $fid ?>" class="portfolio-case-formlink" action="" method="POST" enctype="multipart/form-data"></form>
                                    <input type="hidden" form="<?= $fid ?>" name="portfolio_id" value="<?= (int)$work['id'] ?>">
                                    <div class="edit-drawer" id="<?= $drawerId ?>" onclick="event.stopPropagation()">
                                        <div class="edit-drawer-head"><h3><span class="ico">✏️</span> Редактирование</h3><button type="button" class="edit-drawer-close" onclick="closeDrawers()">✕</button></div>
                                        <?php if ($img !== ''): ?><img src="<?= htmlspecialchars(imgSrc($img)) ?>" class="drawer-preview" alt=""><?php endif; ?>
                                        <label><span class="ico">📝</span> Название</label>
                                        <input type="text" form="<?= $fid ?>" name="portfolio_title" value="<?= htmlspecialchars($work['title']??'') ?>">
                                        <label><span class="ico">🖼</span> Категория</label>
                                        <select form="<?= $fid ?>" name="portfolio_category">
                                            <?php foreach ($categories as $catOpt): ?>
                                                <option value="<?= htmlspecialchars($catOpt['category_key']) ?>" <?= ($catOpt['category_key'] === $cat) ? 'selected' : '' ?>><?= htmlspecialchars($categoryLabels[$catOpt['category_key']] ?? $catOpt['category_key']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="two-cols">
                                            <div><label><span class="ico">💰</span> Цена ₽</label><input type="number" form="<?= $fid ?>" name="portfolio_price_rub" value="<?= (int)($work['price_rub']??0) ?>"></div>
                                            <div><label><span class="ico">💵</span> Цена ₴</label><input type="number" form="<?= $fid ?>" name="portfolio_price_uan" value="<?= (int)($work['price_uan']??0) ?>"></div>
                                        </div>
                                        <hr class="divider">
                                        <label><span class="ico">🖼</span> Заменить главное изображение</label>
                                        <input type="file" form="<?= $fid ?>" name="portfolio_image" accept="image/*">
                                        <label><span class="ico">👤</span> Заменить аватарку</label>
                                        <input type="file" form="<?= $fid ?>" name="portfolio_avatar" accept="image/*">
                                        <button type="submit" form="<?= $fid ?>" name="update_portfolio_media" class="btn-panel">💾 Сохранить изменения</button>
                                        <a class="drawer-danger" href="?delete_portfolio_id=<?= (int)$work['id'] ?>" onclick="return confirm('Удалить кейс?')">🗑 Удалить кейс</a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                                <p class="empty-hint">Кейсов пока нет — добавьте первый выше 👆</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ════ ОБРАЩЕНИЯ ════ -->
                    <div class="panel" data-panel="appeals">
                        <h2><span class="ico">📩</span> Обращения клиентов
                            <?php if ($openAppealsCount > 0): ?>
                                <span style="background:#f97316;color:#fff;border-radius:999px;padding:2px 10px;font-size:12px;margin-left:8px;"><?= $openAppealsCount ?> открытых</span>
                            <?php endif; ?>
                        </h2>
                        <?php if (empty($appeals)): ?>
                            <p style="color:#8a8a96;">Обращений пока нет.</p>
                        <?php else: ?>
                        <div style="display:grid;gap:14px;">
                        <?php foreach ($appeals as $ap): ?>
                            <?php $isOpen = $ap['status'] === 'open'; ?>
                            <div style="border-radius:12px;padding:16px 18px;background:<?= $isOpen ? 'rgba(249,115,22,.06)' : ($ap['status'] === 'closed' ? 'rgba(239,68,68,.04)' : '#111116') ?>;border:1px solid <?= $isOpen ? 'rgba(249,115,22,.35)' : ($ap['status'] === 'closed' ? 'rgba(239,68,68,.2)' : '#20202c') ?>;">
                                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
                                    <span style="font-size:12px;color:#8a8a96;font-weight:800;">Обращение #<?= (int)$ap['id'] ?></span>
                                    <span style="font-size:12px;color:#8a8a96;">→ Заказ #<?= (int)$ap['order_id'] ?></span>
                                    <strong style="flex:1;font-size:14px;"><?= htmlspecialchars($ap['subject']) ?></strong>
                                    <span style="border-radius:999px;padding:4px 10px;font-size:11px;font-weight:800;<?= $isOpen ? 'background:rgba(249,115,22,.2);color:#fdba74;' : ($ap['status'] === 'closed' ? 'background:rgba(239,68,68,.15);color:#f87171;' : 'background:rgba(34,197,94,.15);color:#86efac;') ?>">
                                        <?= $isOpen ? '⏳ Ожидает ответа' : ($ap['status'] === 'closed' ? '🔒 Закрыто' : '✅ Отвечено') ?>
                                    </span>
                                    <span style="color:#8a8a96;font-size:11px;font-weight:700;"><?= htmlspecialchars($ap['username']) ?></span>
                                    <span style="color:#666674;font-size:11px;"><?= date('d.m.Y H:i', strtotime($ap['created_at'])) ?></span>
                                </div>
                                <?php
                                    $mstmt2 = $pdo->prepare("SELECT author, message, created_at FROM appeals_messages WHERE appeal_id = ? ORDER BY id ASC");
                                    $mstmt2->execute([(int)$ap['id']]);
                                    $msgs2 = $mstmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];
                                ?>
                                <?php if (!empty($msgs2)): ?>
                                    <?php foreach ($msgs2 as $m): ?>
                                        <?php if (($m['author'] ?? '') === 'admin'): ?>
                                            <div style="background:rgba(34,197,94,.07);border-left:3px solid #22c55e;border-radius:0 8px 8px 0;padding:10px 13px;margin-bottom:12px;color:#d8d8e8;">
                                                <div style="font-size:11px;font-weight:800;color:#86efac;margin-bottom:5px;">Ответ администратора · <?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></div>
                                                <div style="font-size:13px;white-space:pre-wrap;word-break:break-word;"><?= htmlspecialchars($m['message']) ?></div>
                                            </div>
                                        <?php else: ?>
                                            <div style="background:#0e0e14;border-radius:8px;padding:12px;font-size:13px;color:#d8d8e8;line-height:1.6;white-space:pre-wrap;margin-bottom:12px;word-break:break-word;"><?= htmlspecialchars($m['message']) ?></div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="background:#0e0e14;border-radius:8px;padding:12px;font-size:13px;color:#d8d8e8;margin-bottom:12px;"><?= htmlspecialchars($ap['message'] ?? '') ?></div>
                                <?php endif; ?>
                                <form action="" method="POST" style="display:grid;gap:8px;<?= $ap['status'] === 'closed' ? 'opacity:.45;pointer-events:none;' : '' ?>">
                                    <input type="hidden" name="appeal_id" value="<?= (int)$ap['id'] ?>">
                                    <textarea name="reply_text" required rows="3" placeholder="Напиши ответ клиенту..." style="background:#171720;color:#fff;border:1px solid #2a2a38;border-radius:8px;padding:10px 12px;font-family:Montserrat,sans-serif;font-size:13px;outline:none;width:100%;box-sizing:border-box;resize:vertical;transition:.2s;"></textarea>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                        <button type="submit" name="reply_appeal" style="border:none;border-radius:9px;padding:10px 20px;background:linear-gradient(135deg,#fb923c,#f97316);color:#fff;font-weight:800;cursor:pointer;font-family:Montserrat,sans-serif;font-size:13px;">📤 Отправить ответ</button>
                                        <button type="submit" name="close_appeal" onclick="return confirm('Закрыть обращение? Клиент сможет создать только новое.')" style="border:1px solid rgba(239,68,68,.4);border-radius:9px;padding:10px 18px;background:rgba(239,68,68,.1);color:#f87171;font-weight:800;cursor:pointer;font-family:Montserrat,sans-serif;font-size:13px;">🔒 Закрыть</button>
                                    </div>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- ════ ДЕТАЛЬ ЗАКАЗА ════ -->
                    <div id="order-detail-panel" style="display:<?= isset($_GET['view_order']) ? 'block' : 'none' ?>;">
                        <div class="panel" data-panel="order-detail" style="max-width:1100px;margin:0 auto;padding:0;background:transparent;border:none;">
                            <?php if (!empty($viewOrder)): ?>
                                <?php
                                    $isUrgentView = $viewOrder['status'] === 'urgent';
                                    $dlBadge = '';
                                    if (!empty($viewOrder['deadline'])) {
                                        $dlBadge = deadlineBadge($viewOrder['deadline'], $isUrgentView, $viewOrder['status']);
                                    } elseif (in_array($viewOrder['status'], ['in_progress','urgent'], true)) {
                                        $fallbackDlView = calcDeadline($viewOrder['created_at'], $isUrgentView);
                                        if ($fallbackDlView) $dlBadge = deadlineBadge($fallbackDlView->format('Y-m-d H:i:s'), $isUrgentView, $viewOrder['status']);
                                    }
                                    $cleanTg = trim($viewOrder['telegram'] ?? '');
                                    $cleanTg = ltrim(str_replace(['https://t.me/','http://t.me/','@'], '', $cleanTg), '@');
                                    $screenshotSrc = imgSrc($viewOrder['screenshot'] ?? '', '../uploads/orders/');
                                    // Новый флоу оплаты (order_flow.php): чек, который клиент прикрепляет
                                    // ПОСЛЕ принятия заказа, хранится в payment_receipt — теперь это
                                    // JSON-массив (до 3 чеков на заказ), раньше показывался только один.
                                    $paymentReceiptList = decodeReceiptList($viewOrder['payment_receipt'] ?? '');
                                    $examples = [];
                                    $raw = $viewOrder['example_photo'] ?? '';
                                    if ($raw !== '') {
                                        $dec = json_decode($raw, true);
                                        $examples = is_array($dec) ? $dec : [$raw];
                                    }
                                ?>

                                <!-- Шапка заказа -->
                                <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:20px;">
                                    <a href="<?= $_SERVER['PHP_SELF'] ?>" style="color:#8a8a96;text-decoration:none;font-size:13px;display:flex;align-items:center;gap:5px;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg> Назад
                                    </a>
                                    <span style="color:#2a2a38;">|</span>
                                    <h2 style="margin:0;font-size:18px;font-weight:900;">Заказ #<?= (int)$viewOrder['id'] ?> <span style="color:#8a8a96;font-weight:500;">— <?= htmlspecialchars($viewOrder['username'] ?? 'Клиент') ?></span></h2>
                                    <span class="status <?= htmlspecialchars($viewOrder['status']) ?>"><?= htmlspecialchars($statusLabels[$viewOrder['status']] ?? $viewOrder['status']) ?></span>
                                    <?php if ($dlBadge): echo $dlBadge; endif; ?>
                                    <?php if (!empty($viewOrder['cooperation'])): ?>
                                        <span style="background:rgba(251,146,60,.15);color:#fb923c;border:1px solid rgba(251,146,60,.3);border-radius:999px;padding:4px 10px;font-size:11px;font-weight:800;">💼 Сотрудничество</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Двухколоночный layout -->
                                <div style="display:grid;grid-template-columns:1fr minmax(0,320px);gap:16px;align-items:start;width:100%;box-sizing:border-box;">

                                    <!-- Левая колонка -->
                                    <div style="display:grid;gap:14px;">

                                        <!-- Инфо о клиенте + ТЗ -->
                                        <div style="background:#111116;border:1px solid #20202c;border-radius:14px;padding:18px;">
                                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                                                <div>
                                                    <div style="font-size:11px;color:#555568;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Клиент</div>
                                                    <div style="font-size:14px;color:#efeff7;font-weight:700;"><?= htmlspecialchars($viewOrder['username'] ?? '—') ?></div>
                                                </div>
                                                <div>
                                                    <div style="font-size:11px;color:#555568;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Telegram</div>
                                                    <div style="font-size:14px;color:#efeff7;font-weight:700;">
                                                        <?php if ($cleanTg): ?>
                                                            <a href="https://t.me/<?= htmlspecialchars($cleanTg) ?>" target="_blank" style="color:#60a5fa;text-decoration:none;">@<?= htmlspecialchars($cleanTg) ?></a>
                                                        <?php else: ?>—<?php endif; ?>
                                                    </div>
                                                </div>
                                                <div>
                                                    <div style="font-size:11px;color:#555568;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Услуга</div>
                                                    <div style="font-size:14px;color:#efeff7;font-weight:700;"><?= htmlspecialchars($viewOrder ? getOrderServiceTitle($pdo, $viewOrder) : '—') ?></div>
                                                </div>
                                                <div>
                                                    <div style="font-size:11px;color:#555568;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Цена</div>
                                                    <div style="font-size:14px;color:#efeff7;font-weight:700;">
                                                        <?php if (!empty($viewOrder['promo_code']) && $readyCalcDiscountPct > 0): ?>
                                                            <s style="color:#666;"><?= number_format($readyCalcBaseRub,0) ?>₽</s> → <span style="color:#4ade80;"><?= number_format($readyCalcBaseRub * (1 - $readyCalcDiscountPct/100),0) ?>₽</span>
                                                        <?php else: ?>
                                                            <?= number_format($readyCalcBaseRub,0) ?>₽
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php if (!empty($viewOrder['promo_code'])): ?>
                                                <div>
                                                    <div style="font-size:11px;color:#555568;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Промокод</div>
                                                    <div style="font-size:14px;color:#fdba74;font-weight:700;">🎁 <?= htmlspecialchars($viewOrder['promo_code']) ?><?= $readyCalcDiscountPct > 0 ? ' (−' . (int)$readyCalcDiscountPct . '%)' : '' ?></div>
                                                </div>
                                                <?php endif; ?>
                                                <div>
                                                    <div style="font-size:11px;color:#555568;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Дата</div>
                                                    <div style="font-size:14px;color:#efeff7;font-weight:700;"><?= date('d.m.Y H:i', strtotime($viewOrder['created_at'])) ?></div>
                                                </div>
                                            </div>
                                            <?php if (!empty($viewOrder['details'])): ?>
                                                <div style="font-size:11px;color:#555568;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Техническое задание</div>
                                                <?php if (!empty($viewOrder['promo_code'])): ?>
                                                <div style="font-size:12px;color:#fdba74;margin-bottom:8px;">🎁 Промокод "<?= htmlspecialchars($viewOrder['promo_code']) ?>" был применён к этому заказу<?= $readyCalcDiscountPct > 0 ? ' (скидка ' . (int)$readyCalcDiscountPct . '%)' : '' ?>.</div>
                                                <?php endif; ?>
                                                <div style="background:#0b0b10;border-radius:10px;padding:14px;font-size:13px;color:#d8d8e8;line-height:1.7;white-space:pre-wrap;word-break:break-word;"><?= htmlspecialchars($viewOrder['details']) ?></div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Файлы -->
                                        <?php if ($screenshotSrc !== '' || !empty($paymentReceiptList) || !empty($examples)): ?>
                                        <div style="background:#111116;border:1px solid #20202c;border-radius:14px;padding:18px;">
                                            <div style="font-size:11px;color:#555568;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px;">Файлы</div>
                                            <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">
                                                <?php if (!empty($paymentReceiptList)): ?>
                                                <div>
                                                    <div style="font-size:11px;color:#8a8a96;margin-bottom:6px;">💳 Чек оплаты<?= count($paymentReceiptList) > 1 ? ' (' . count($paymentReceiptList) . ')' : '' ?></div>
                                                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                                    <?php foreach ($paymentReceiptList as $rv): $paymentReceiptSrc = imgSrc($rv, '../uploads/orders/'); if ($paymentReceiptSrc === '') continue; ?>
                                                        <?php if (str_ends_with(strtolower($paymentReceiptSrc), '.pdf')): ?>
                                                            <a href="<?= htmlspecialchars($paymentReceiptSrc) ?>" target="_blank" style="display:flex;align-items:center;gap:8px;background:#0b0b10;border:1px solid #2a2a38;border-radius:10px;padding:12px 16px;color:#fdba74;text-decoration:none;font-size:12px;font-weight:700;">📄 Открыть PDF-чек</a>
                                                        <?php else: ?>
                                                            <a href="<?= htmlspecialchars($paymentReceiptSrc) ?>" target="_blank">
                                                                <img src="<?= htmlspecialchars($paymentReceiptSrc) ?>" style="max-width:200px;max-height:160px;border-radius:10px;object-fit:cover;display:block;" onerror="this.style.display='none'">
                                                            </a>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($screenshotSrc !== ''): ?>
                                                <div>
                                                    <div style="font-size:11px;color:#8a8a96;margin-bottom:6px;">Чек оплаты (архив)</div>
                                                    <a href="<?= htmlspecialchars($screenshotSrc) ?>" target="_blank">
                                                        <img src="<?= htmlspecialchars($screenshotSrc) ?>" style="max-width:200px;max-height:160px;border-radius:10px;object-fit:cover;display:block;" onerror="this.style.display='none'">
                                                    </a>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (!empty($examples)): ?>
                                                <div style="flex:1;">
                                                    <div style="font-size:11px;color:#8a8a96;margin-bottom:6px;">Референсы</div>
                                                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                                        <?php foreach ($examples as $ex): $exSrc = imgSrc($ex, '../uploads/orders/'); ?>
                                                            <?php if ($exSrc !== ''): ?>
                                                                <a href="<?= htmlspecialchars($exSrc) ?>" target="_blank">
                                                                    <img src="<?= htmlspecialchars($exSrc) ?>" style="width:100px;height:80px;border-radius:8px;object-fit:cover;" onerror="this.style.display='none'">
                                                                </a>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Переписка -->
                                        <div style="background:#111116;border:1px solid #20202c;border-radius:14px;padding:18px;">
                                            <div style="font-size:11px;color:#555568;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px;">Переписка</div>
                                            <?php if (!empty($orderAppeals)): ?>
                                                <?php foreach ($orderAppeals as $oap):
                                                    $mstmt3 = $pdo->prepare("SELECT author, message, created_at FROM appeals_messages WHERE appeal_id = ? ORDER BY id ASC");
                                                    $mstmt3->execute([(int)$oap['id']]);
                                                    $msgs3 = $mstmt3->fetchAll(PDO::FETCH_ASSOC) ?: [];
                                                ?>
                                                <div style="margin-bottom:14px;">
                                                    <div style="font-size:11px;color:#8a8a96;font-weight:700;margin-bottom:8px;"><?= htmlspecialchars($oap['subject'] ?: 'Обращение') ?></div>
                                                    <?php foreach ($msgs3 as $m): ?>
                                                        <?php $isAdmin = ($m['author'] ?? '') === 'admin'; ?>
                                                        <div style="display:flex;gap:8px;margin-bottom:8px;<?= $isAdmin ? 'flex-direction:row-reverse;' : '' ?>">
                                                            <div style="width:28px;height:28px;border-radius:50%;background:<?= $isAdmin ? 'rgba(249,115,22,.2)' : 'rgba(96,165,250,.2)' ?>;display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;">
                                                                <?= $isAdmin ? '⚙' : '👤' ?>
                                                            </div>
                                                            <div style="background:<?= $isAdmin ? 'rgba(249,115,22,.06)' : 'rgba(96,165,250,.06)' ?>;border:1px solid <?= $isAdmin ? 'rgba(249,115,22,.15)' : 'rgba(96,165,250,.15)' ?>;border-radius:10px;padding:10px 12px;max-width:85%;">
                                                                <div style="font-size:10px;color:#555568;margin-bottom:4px;"><?= $isAdmin ? 'Дизайнер' : 'Клиент' ?> · <?= date('d.m H:i', strtotime($m['created_at'])) ?></div>
                                                                <div style="font-size:13px;color:#d8d8e8;white-space:pre-wrap;word-break:break-word;"><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div style="color:#555568;font-size:13px;">Переписки пока нет.</div>
                                            <?php endif; ?>

                                            <!-- Форма нового сообщения -->
                                            <form action="" method="POST" style="margin-top:16px;display:grid;gap:8px;">
                                                <input type="hidden" name="order_id" value="<?= (int)$viewOrder['id'] ?>">
                                                <input type="text" name="msg_subject" placeholder="Тема" value="Уточнение по заказу #<?= (int)$viewOrder['id'] ?>" style="font-size:13px;margin:0;padding:10px 12px;">
                                                <textarea name="msg_text" required rows="3" placeholder="Сообщение клиенту..." style="background:#171720;color:#fff;border:1px solid #2a2a38;border-radius:8px;padding:10px 12px;font-family:Montserrat,sans-serif;font-size:13px;resize:vertical;margin:0;"></textarea>
                                                <button type="submit" name="send_order_message" class="btn-panel" style="margin-top:0;">📤 Отправить клиенту</button>
                                            </form>
                                        </div>

                                    </div>

                                    <!-- Правая колонка — действия -->
                                    <div style="display:grid;gap:12px;position:sticky;top:18px;">

                                        <!-- ✅ ИСПРАВЛЕННЫЙ Блок управления - полная ширина кнопок -->
                                        <div style="background:#111116;border:1px solid #20202c;border-radius:14px;padding:18px;flex:1;">
                                            <div style="font-size:11px;color:#555568;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px;">Управление</div>
                                            <form method="POST" style="display:grid;grid-template-columns:1fr;gap:8px;width:100%;margin:0;">
                                                <input type="hidden" name="order_id" value="<?= (int)$viewOrder['id'] ?>">
                                                <details class="accept-menu accept-menu-full">
                                                    <summary style="border:none;border-radius:10px;padding:12px 14px;width:100%;box-sizing:border-box;background:linear-gradient(135deg,#fb923c,#f97316);color:#fff;font-weight:800;cursor:pointer;font-family:Montserrat,sans-serif;font-size:13px;transition:.15s;text-transform:uppercase;letter-spacing:0.5px;margin:0;list-style:none;text-align:center;">🚀 Принять заказ ▾</summary>
                                                    <div class="accept-menu-popup" style="position:static;width:100%;box-sizing:border-box;margin-top:8px;">
                                                        <button type="submit" name="order_action" value="accept_normal">✅ Обычный (5 сут.)</button>
                                                        <button type="submit" name="order_action" value="accept_urgent">⚡️ Срочный (24ч, +50%)</button>
                                                        <button type="submit" name="order_action" value="cooperation" onclick="return confirm('Отметить заказ как сотрудничество? Оплата не потребуется.')">🤝 Сотрудничество</button>
                                                        <button type="submit" name="order_action" value="accept_queue">📥 Просто в очередь</button>
                                                    </div>
                                                </details>
                                                <button type="button" onclick="openReadyModal(<?= (int)$viewOrder['id'] ?>, <?= (float)$readyCalcBaseRub ?>, <?= (float)$readyCalcBaseUan ?>, <?= (int)$readyCalcDiscountPct ?>)" style="border:1px solid rgba(52,211,153,.3);border-radius:10px;padding:12px 14px;width:100%;box-sizing:border-box;background:rgba(52,211,153,.15);color:#34d399;font-weight:800;cursor:pointer;font-family:Montserrat,sans-serif;font-size:13px;transition:.15s;text-transform:uppercase;letter-spacing:0.5px;margin:0;-webkit-appearance:none;appearance:none;">✅ Готово</button>
                                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:4px;width:100%;box-sizing:border-box;">
                                                    <button type="submit" name="order_action" value="decline" onclick="return confirm('Отклонить заказ?')" style="border:1px solid rgba(251,113,133,.25);border-radius:10px;padding:11px 12px;width:100%;box-sizing:border-box;background:rgba(251,113,133,.12);color:#fb7185;font-weight:800;cursor:pointer;font-family:Montserrat,sans-serif;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;margin:0;-webkit-appearance:none;appearance:none;">❌ Отклонить</button>
                                                    <button type="submit" name="order_action" value="ban" onclick="return confirm('Добавить в чёрный список?')" style="border:1px solid rgba(124,58,237,.25);border-radius:10px;padding:11px 12px;width:100%;box-sizing:border-box;background:rgba(124,58,237,.15);color:#a78bfa;font-weight:800;cursor:pointer;font-family:Montserrat,sans-serif;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;margin:0;-webkit-appearance:none;appearance:none;">🚫 Бан</button>
                                                </div>
                                                <button type="submit" name="order_action" value="delete_order" onclick="return confirm('Удалить заказ #<?= (int)$viewOrder['id'] ?> НАВСЕГДА? Это действие нельзя отменить — заказ и все его сообщения будут стёрты из базы.')" style="margin-top:4px;border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:11px 12px;width:100%;box-sizing:border-box;background:rgba(239,68,68,.1);color:#f87171;font-weight:800;cursor:pointer;font-family:Montserrat,sans-serif;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;-webkit-appearance:none;appearance:none;">🗑 Удалить заказ</button>
                                            </form>
                                        </div>

                                        <?php if (($viewOrder['status'] ?? '') === 'revision'): ?>
                                        <!-- 🔧 Заказ на правке — показываем последнюю правку и форму пересдачи -->
                                        <div style="background:rgba(251,191,36,.06);border:1px solid rgba(251,191,36,.25);border-radius:14px;padding:18px;">
                                            <div style="font-size:11px;color:#fbbf24;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">🔧 Запрошена правка</div>
                                            <?php
                                                $lastRevisionNote = '';
                                                try {
                                                    $rnStmt = $pdo->prepare("SELECT message FROM order_messages WHERE order_id = ? AND message LIKE 'Запрошена правка:%' ORDER BY id DESC LIMIT 1");
                                                    $rnStmt->execute([(int)$viewOrder['id']]);
                                                    $lastRevisionNote = (string)($rnStmt->fetchColumn() ?: '');
                                                } catch (Throwable $e) {}
                                            ?>
                                            <?php if ($lastRevisionNote !== ''): ?>
                                            <div style="font-size:12.5px;color:#d8d8e8;background:#0e0e15;border:1px solid #232330;border-radius:10px;padding:10px 12px;margin-bottom:14px;white-space:pre-wrap;word-break:break-word;"><?= nl2br(htmlspecialchars(preg_replace('/^Запрошена правка:\s*/u', '', $lastRevisionNote))) ?></div>
                                            <?php endif; ?>
                                            <form method="POST" enctype="multipart/form-data" style="display:grid;gap:8px;">
                                                <input type="hidden" name="order_id" value="<?= (int)$viewOrder['id'] ?>">
                                                <input type="hidden" name="order_action" value="redeliver_work">
                                                <input type="file" name="work_file" required style="width:100%;box-sizing:border-box;background:#0a0a10;border:1px solid #2a2a38;border-radius:10px;padding:10px 12px;color:#ccc;font-size:12px;font-family:Montserrat,sans-serif;">
                                                <button type="submit" style="border:none;border-radius:10px;padding:12px 14px;width:100%;box-sizing:border-box;background:linear-gradient(135deg,#fb923c,#f97316);color:#fff;font-weight:800;cursor:pointer;font-family:Montserrat,sans-serif;font-size:13px;text-transform:uppercase;letter-spacing:0.5px;">📤 Пересдать работу</button>
                                            </form>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Написать клиенту -->
                                        <div style="background:#111116;border:1px solid #20202c;border-radius:14px;padding:18px;">
                                            <div style="font-size:11px;color:#555568;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px;">Написать клиенту</div>
                                            <?php if ($cleanTg): ?>
                                                <a href="https://t.me/<?= htmlspecialchars($cleanTg) ?>" target="_blank" style="display:flex;align-items:center;justify-content:center;gap:8px;background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.25);color:#60a5fa;border-radius:10px;padding:10px;text-decoration:none;font-size:13px;font-weight:700;margin-bottom:10px;">
                                                    💬 Открыть чат в Telegram
                                                </a>
                                            <?php endif; ?>
                                            <form method="POST" style="display:grid;gap:8px;">
                                                <input type="hidden" name="order_id" value="<?= (int)$viewOrder['id'] ?>">
                                                <input type="hidden" name="order_action" value="message_client">
                                                <textarea name="message_text" rows="3" placeholder="Сообщение через бот..." style="background:#0b0b10;color:#fff;border:1px solid #2a2a38;border-radius:8px;padding:10px 12px;font-family:Montserrat,sans-serif;font-size:13px;resize:vertical;width:100%;box-sizing:border-box;margin:0;"></textarea>
                                                <button type="submit" style="border:none;border-radius:10px;padding:10px;background:rgba(96,165,250,.15);color:#60a5fa;border:1px solid rgba(96,165,250,.25);font-weight:800;cursor:pointer;font-family:Montserrat,sans-serif;font-size:13px;">✉️ Отправить через бот</button>
                                            </form>
                                        </div>

                                    </div>
                                </div>

                                <style>
                                @media(max-width:768px){
                                    [style*="grid-template-columns:1fr minmax(0,320px)"]{grid-template-columns:1fr!important;}
                                    [style*="grid-template-columns:1fr 1fr"]{grid-template-columns:1fr!important;}
                                }
                                </style>

                            <?php else: ?>
                                <div class="panel" style="text-align:center;padding:40px;">
                                    <div style="font-size:32px;margin-bottom:12px;">📦</div>
                                    <div style="color:#8a8a96;">Заказ не найден. <a href="<?= $_SERVER['PHP_SELF'] ?>" style="color:#f97316;">Вернуться назад</a></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</main>

<script>
function showToast(msg, type = 'success', duration = 5000) {
    const t = document.getElementById('admin-toast');
    t.textContent = msg;
    t.className   = 'show ' + type;
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.classList.remove('show'); }, duration);
}

// ── Показ/скрытие форм "Создать ..." по кнопке — чтобы не мозолили глаза ──
function toggleCreatePanel(cardId, btn) {
    const card = document.getElementById(cardId);
    if (!card) return;
    card.classList.remove('collapsed');
    if (btn) btn.classList.add('is-hidden');
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    const firstInput = card.querySelector('input:not([type=hidden]), select, textarea');
    if (firstInput) setTimeout(() => firstInput.focus(), 300);
}
function hideCreatePanel(cardId, btnId) {
    const card = document.getElementById(cardId);
    const btn  = document.getElementById(btnId);
    if (card) card.classList.add('collapsed');
    if (btn) btn.classList.remove('is-hidden');
}

document.getElementById('portfolio-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn  = document.getElementById('portfolio-submit-btn');
    const form = this;

    btn.disabled = true;
    btn.classList.add('loading');
    showToast('⏳ Загружаем на ImgBB... Это может занять 10–30 сек.', 'loading', 60000);
    const fd = new FormData(form);
    fd.append('add_portfolio', '1');
    try {
        const resp = await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
        const data = await resp.json();
        showToast(data.msg, data.ok ? 'success' : 'error', 7000);
        if (data.ok) {
            form.reset();
            document.querySelectorAll('.file-upload-name, .mini-file-name').forEach(el => { el.textContent = 'Файл не выбран'; el.classList.remove('has-file'); });
            hideCreatePanel('portfolio-create-card', 'portfolio-create-toggle-btn');
        }
    } catch (err) {
        showToast('❌ Ошибка соединения. Попробуй ещё раз.', 'error', 7000);
    } finally {
        btn.disabled = false;
        btn.classList.remove('loading');
    }
});

function toggleAvatarField() {
    const category = document.getElementById('category_select').value;
    const block    = document.getElementById('avatar_upload_block');
    const designCategories = <?= json_encode(array_values(array_filter(array_map(fn($c) => !empty($c['is_design']) ? $c['category_key'] : null, $categories))), JSON_UNESCAPED_UNICODE) ?>;
    block.style.display = designCategories.includes(category) ? 'block' : 'none';
}

// ── Карта "категория → цена из связанной услуги в прайсе" ──────────
// Используется, чтобы при добавлении работы в портфолио цена сама
// подставлялась из услуги, привязанной к выбранной категории.
let categoryPriceMap = <?= json_encode(array_reduce($services, function ($acc, $s) {
    $acc[$s['category_key']] = ['rub' => (int)$s['price_rub'], 'uan' => (int)$s['price_uan']];
    return $acc;
}, []), JSON_UNESCAPED_UNICODE) ?>;

function applyCategoryPrice() {
    const category = document.getElementById('category_select').value;
    const p = categoryPriceMap[category];
    if (p) {
        document.getElementById('portfolio_price_rub').value = p.rub;
        document.getElementById('portfolio_price_uan').value = p.uan;
    }
}

// ── Универсальный AJAX-сабмит формы: без перезагрузки страницы ─────
async function submitFormAjax(form, submitName, onSuccess) {
    const btn = form.querySelector(`[name="${submitName}"]`) || form.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;
    const fd = new FormData(form);
    fd.append(submitName, '1');
    try {
        const resp = await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
        const data = await resp.json();
        showToast(data.msg || (data.ok ? '✅ Готово' : '❌ Ошибка'), data.ok ? 'success' : 'error', 6000);
        if (data.ok && typeof onSuccess === 'function') onSuccess(data);
        if (data.ok) form.reset();
    } catch (err) {
        showToast('❌ Ошибка соединения. Попробуй ещё раз.', 'error', 6000);
    } finally {
        if (btn) btn.disabled = false;
    }
}

// ── Категория портфолио: создание без перезагрузки ──────────────────
const categoryForm = document.getElementById('category-form');
if (categoryForm) {
    categoryForm.addEventListener('submit', function(e) {
        e.preventDefault();
        submitFormAjax(this, 'add_portfolio_category', function(data) {
            document.getElementById('categories-empty-hint').style.display = 'none';
            document.getElementById('categories-grid').insertAdjacentHTML('afterbegin', data.html);
            // Новая категория появляется в выпадающих списках сразу же —
            // и в форме "Добавить в портфолио", и в форме услуги прайса.
            const opt1 = new Option(data.category_label, data.category_key);
            document.getElementById('category_select').add(opt1, 0);
            const opt2 = new Option(data.category_label, data.category_key);
            document.getElementById('service_key_select').add(opt2);
            categoryPriceMap[data.category_key] = { rub: 0, uan: 0 };
            if (data.service_html) {
                document.getElementById('services-empty-hint').style.display = 'none';
                document.getElementById('services-grid').insertAdjacentHTML('afterbegin', data.service_html);
            }
            if (typeof initFileInputs === 'function') initFileInputs();
            hideCreatePanel('categories-create-card', 'categories-create-toggle-btn');
        });
    });
}

// ── Услуга прайса: создание без перезагрузки ─────────────────────────
const serviceForm = document.getElementById('service-form');
if (serviceForm) {
    serviceForm.addEventListener('submit', function(e) {
        e.preventDefault();
        submitFormAjax(this, 'add_price_service', function(data) {
            document.getElementById('services-empty-hint').style.display = 'none';
            document.getElementById('services-grid').insertAdjacentHTML('afterbegin', data.html);
            if (data.category_key) categoryPriceMap[data.category_key] = { rub: data.price_rub, uan: data.price_uan };
            if (typeof initFileInputs === 'function') initFileInputs();
            hideCreatePanel('price-create-card', 'price-create-toggle-btn');
        });
    });
}

// ── Промокод: создание без перезагрузки ──────────────────────────────
const promoForm = document.getElementById('promo-form');
if (promoForm) {
    promoForm.addEventListener('submit', function(e) {
        e.preventDefault();
        submitFormAjax(this, 'add_promo', function(data) {
            document.getElementById('promo-empty-hint').style.display = 'none';
            document.getElementById('promo-grid').insertAdjacentHTML('afterbegin', data.html);
            hideCreatePanel('promo-create-card', 'promo-create-toggle-btn');
        });
    });
}

// ── Ключи и API: сохранение без перезагрузки ─────────────────────────
async function saveApiKeys() {
    const form = document.getElementById('api-keys-form');
    const btn  = document.getElementById('keys-submit-btn');
    btn.disabled = true;
    const fd = new FormData(form);
    fd.append('save_api_keys', '1');
    try {
        const resp = await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
        const data = await resp.json();
        showToast(data.msg, data.ok ? 'success' : 'error', 6000);
    } catch (err) {
        showToast('❌ Ошибка соединения.', 'error', 6000);
    } finally {
        btn.disabled = false;
    }
}


function openDrawer(id) {
    document.querySelectorAll('.edit-drawer.open').forEach(d => d.classList.remove('open'));
    const d = document.getElementById(id);
    if (d) d.classList.add('open');
    const ov = document.getElementById('drawer-overlay');
    if (ov) ov.classList.add('open');
}
function closeDrawers() {
    document.querySelectorAll('.edit-drawer.open').forEach(d => d.classList.remove('open'));
    const ov = document.getElementById('drawer-overlay');
    if (ov) ov.classList.remove('open');
}
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeDrawers(); });

function activateAdminTab(tab) {
    const ordPanel = document.getElementById('order-detail-panel');
    if (ordPanel) ordPanel.style.display = 'none';

    document.querySelectorAll('.admin-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    // ИСПРАВЛЕНО: раньше был querySelector (только 1-й блок статистики),
    // из-за чего 2 других .stats-grid (по способам оплаты + итого) не
    // скрывались на других вкладках и путали разметку.
    const stats  = document.querySelectorAll('.stats-grid');
    const layout = document.querySelector('.admin-layout');
    stats.forEach(s => s.classList.add('tab-hidden'));
    if (layout) layout.classList.add('single-column');
    document.querySelectorAll('.panel').forEach(p => p.classList.add('tab-hidden'));

    const show = (...names) => names.forEach(n => {
        document.querySelectorAll(`.panel[data-panel="${n}"]`).forEach(el => el.classList.remove('tab-hidden'));
    });

    if (tab === 'overview')    { stats.forEach(s => s.classList.remove('tab-hidden')); show('orders'); }
    else if (tab === 'portfolio') { show('portfolio-add','portfolio-list'); }
    else if (tab === 'price')     { show('price-add','price-manager'); }
    else if (tab === 'orders')    { stats.forEach(s => s.classList.remove('tab-hidden')); show('orders'); }
    else if (tab === 'categories'){ show('categories'); }
    else if (tab === 'promo')     { show('promo'); }
    else if (tab === 'reviews')   { show('reviews'); }
    else if (tab === 'commands')  { show('commands'); }
    else if (tab === 'rules')     { show('rules'); }
    else if (tab === 'appeals')   { show('appeals'); }
    else if (tab === 'logs')      { show('logs'); }
    else if (tab === 'ai-prompt') { show('ai-prompt'); }
    else if (tab === 'keys')      { show('keys'); }
    if (typeof closeDrawers === 'function') closeDrawers();
    try { localStorage.setItem('admin_active_tab', tab); } catch (e) {}
}

function initFileInputs() {
    document.querySelectorAll('input[type="file"]').forEach(input => {
        if (input.dataset.styled) return;
        input.dataset.styled = '1';
        input.classList.add('styled-hidden');
        const isMini = input.closest('.mini-media-form') !== null;
        const wrap   = document.createElement('div');
        wrap.className = isMini ? 'mini-file-wrap' : 'file-upload-wrap';
        const label  = document.createElement('label');
        label.htmlFor = input.id || (input.id = 'fi_' + Math.random().toString(36).slice(2));
        label.className = isMini ? 'mini-file-btn' : 'file-upload-btn';
        label.style.margin = '0';
        label.innerHTML = `<svg width="${isMini?12:14}" height="${isMini?12:14}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Выбрать файл`;
        const nameSpan = document.createElement('span');
        nameSpan.className = isMini ? 'mini-file-name' : 'file-upload-name';
        nameSpan.textContent = 'Файл не выбран';
        input.addEventListener('change', () => {
            const name = input.files[0]?.name || 'Файл не выбран';
            const hasFile = !!input.files[0];
            nameSpan.textContent = hasFile ? name : 'Файл не выбран';
            nameSpan.classList.toggle('has-file', hasFile);
        });
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        wrap.appendChild(label);
        wrap.appendChild(nameSpan);
    });
}

function initAntiTheft() {
    document.addEventListener('contextmenu', function(e) {
        const t = e.target;
        if (t.tagName === 'IMG' && (t.classList.contains('case-thumb') || t.classList.contains('case-ava') || t.classList.contains('price-thumb'))) { e.preventDefault(); return false; }
    }, true);
    document.addEventListener('dragstart', function(e) {
        if (e.target.tagName === 'IMG') { const c = e.target.classList; if (c.contains('case-thumb') || c.contains('case-ava') || c.contains('price-thumb')) e.preventDefault(); }
    }, true);
}

document.addEventListener('DOMContentLoaded', () => {
    toggleAvatarField();
    const params = new URLSearchParams(window.location.search);
    if (params.has('view_order')) {
        // Показываем таб заказов и скрываем все остальные панели кроме detail
        document.querySelectorAll('.admin-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === 'orders'));
        document.querySelectorAll('.panel').forEach(p => {
            if (p.dataset.panel && p.dataset.panel !== 'order-detail') {
                p.classList.add('tab-hidden');
            }
        });
        const det = document.querySelector('.panel[data-panel="order-detail"]');
        if (det) det.classList.remove('tab-hidden');
        const wrapper = document.getElementById('order-detail-panel');
        if (wrapper) wrapper.style.display = 'block';
        // ВАЖНО: без этого левая колонка (aside, 380px) оставалась пустой,
        // но зарезервированной сеткой — карточка заказа съезжала вправо.
        const layoutOrd = document.querySelector('.admin-layout');
        if (layoutOrd) layoutOrd.classList.add('single-column');
    } else {
        let savedTab = 'overview';
        try { savedTab = localStorage.getItem('admin_active_tab') || 'overview'; } catch (e) {}
        const knownTabs = ['overview','portfolio','price','orders','categories','promo','reviews','commands','rules','appeals','logs','ai-prompt','keys'];
        if (!knownTabs.includes(savedTab)) savedTab = 'overview';
        activateAdminTab(savedTab);
    }
    initFileInputs();
    initAntiTheft();
    document.querySelectorAll('.admin-tab').forEach(btn => {
        btn.addEventListener('click', () => setTimeout(initFileInputs, 50));
    });
});
</script>
<!-- ── МОДАЛКА ГОТОВО ── -->
<div id="ready-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
    <div style="background:#13131a;border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:28px 28px 24px;width:360px;max-width:95vw;box-shadow:0 0 60px rgba(0,0,0,.6);">
        <div style="font-size:18px;font-weight:900;color:#fff;margin-bottom:18px;">✅ Отметить как готово</div>
        <form method="POST" id="ready-form" enctype="multipart/form-data">
            <input type="hidden" name="order_id" id="ready-order-id">
            <input type="hidden" name="order_action" value="status">
            <label style="display:block;font-size:11px;font-weight:800;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">📎 Файл готовой работы</label>
            <div style="margin-bottom:16px;">
                <input type="file" name="work_file" id="ready-work-file" style="width:100%;box-sizing:border-box;background:#0a0a10;border:1px solid #2a2a38;border-radius:10px;padding:10px 12px;color:#ccc;font-size:12px;font-family:Montserrat,sans-serif;">
                <div style="font-size:10.5px;color:#666;margin-top:5px;">Уйдёт клиенту в Telegram файлом без сжатия, с кнопками «Принять» / «На правку». Не выбрал файл — клиент получит только текстовое уведомление, без файла.</div>
            </div>
            <label style="display:block;font-size:11px;font-weight:800;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Способ оплаты</label>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:16px;">
                <label style="cursor:pointer;"><input type="radio" name="pay_method" value="donation" style="display:none;" onchange="selectPayMethod(this)"><div class="pay-method-btn" id="pm-donation" onclick="selectPayMethod2('donation')" style="border:1px solid rgba(249,115,22,.3);border-radius:10px;padding:10px 6px;text-align:center;font-size:12px;font-weight:800;color:#fdba74;background:rgba(249,115,22,.08);cursor:pointer;transition:.15s;">💳<br>Донейшен</div></label>
                <label style="cursor:pointer;"><input type="radio" name="pay_method" value="crypto" style="display:none;" onchange="selectPayMethod(this)"><div class="pay-method-btn" id="pm-crypto" onclick="selectPayMethod2('crypto')" style="border:1px solid rgba(96,165,250,.3);border-radius:10px;padding:10px 6px;text-align:center;font-size:12px;font-weight:800;color:#93c5fd;background:rgba(96,165,250,.08);cursor:pointer;transition:.15s;">₿<br>Крипта</div></label>
                <label style="cursor:pointer;"><input type="radio" name="pay_method" value="monobank" style="display:none;" onchange="selectPayMethod(this)"><div class="pay-method-btn" id="pm-monobank" onclick="selectPayMethod2('monobank')" style="border:1px solid rgba(34,197,94,.3);border-radius:10px;padding:10px 6px;text-align:center;font-size:12px;font-weight:800;color:#86efac;background:rgba(34,197,94,.08);cursor:pointer;transition:.15s;">🏦<br>Монобанк</div></label>
            </div>
            <div id="discount-calc" style="background:#0e0e15;border:1px solid #232330;border-radius:12px;padding:14px;margin-bottom:16px;">
                <div style="font-size:11px;font-weight:800;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">🧮 Калькулятор скидки</div>
                <div id="discount-base-line" style="font-size:12px;color:#9a9aa8;margin-bottom:10px;">Базовая цена (с учётом срочности): <strong id="discount-base-text" style="color:#fff;">—</strong></div>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                    <input type="range" id="discount-slider" min="1" max="100" value="10" style="flex:1;accent-color:#f97316;">
                    <span style="min-width:42px;text-align:right;font-weight:900;color:#fdba74;font-size:14px;"><span id="discount-pct-label">10</span>%</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <label style="font-size:11px;color:#888;white-space:nowrap;">Скидка, ₽:</label>
                    <input type="number" id="discount-amount-input" step="0.01" min="0" style="flex:1;background:#0a0a10;border:1px solid #2a2a38;border-radius:8px;padding:8px 10px;color:#fff;font-size:13px;font-weight:800;font-family:Montserrat,sans-serif;outline:none;">
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px;padding-top:10px;border-top:1px solid #1e1e28;">
                    <span style="font-size:12px;color:#9a9aa8;">Итого к оплате: <strong id="discount-final-text" style="color:#4ade80;font-size:15px;">—</strong></span>
                    <button type="button" onclick="applyDiscountToAmount()" style="border:1px solid rgba(249,115,22,.4);background:rgba(249,115,22,.15);color:#fdba74;font-weight:800;font-size:11px;border-radius:8px;padding:7px 10px;cursor:pointer;">Подставить в сумму</button>
                </div>
            </div>
            <label style="display:block;font-size:11px;font-weight:800;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Сумма получена</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:20px;">
                <input type="number" name="paid_amount" id="ready-amount" step="0.01" min="0" placeholder="Сумма..." style="background:#0a0a10;border:1px solid #2a2a38;border-radius:10px;padding:14px 16px;color:#fff;font-size:20px;font-weight:900;font-family:Montserrat,sans-serif;outline:none;letter-spacing:1px;width:100%;box-sizing:border-box;">
                <select name="paid_currency" id="ready-currency" style="background:#0a0a10;border:1px solid #2a2a38;border-radius:10px;padding:14px 14px;color:#fff;font-size:16px;font-weight:800;font-family:Montserrat,sans-serif;outline:none;cursor:pointer;width:100%;box-sizing:border-box;">
                    <option value="RUB">₽ RUB</option>
                    <option value="USD">$ USD</option>
                    <option value="UAH">₴ UAH</option>
                </select>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" style="flex:1;background:linear-gradient(135deg,#22c55e,#16a34a);border:none;border-radius:10px;padding:12px;color:#fff;font-size:14px;font-weight:900;cursor:pointer;font-family:Montserrat,sans-serif;">✅ Подтвердить</button>
                <button type="button" onclick="closeReadyModal()" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:12px 18px;color:#888;font-size:14px;font-weight:800;cursor:pointer;font-family:Montserrat,sans-serif;">Отмена</button>
            </div>
        </form>
    </div>
</div>
<script>
let _selectedPayMethod = 'donation';
let _discountBaseRub = 0;
let _discountBaseUan = 0;

function openReadyModal(orderId, baseRub, baseUan, discountPct) {
    document.getElementById('ready-order-id').value = orderId;
    document.getElementById('ready-modal').style.display = 'flex';
    selectPayMethod2('donation');

    _discountBaseRub = Number(baseRub) || 0;
    _discountBaseUan = Number(baseUan) || 0;

    var slider = document.getElementById('discount-slider');
    var pct = Number(discountPct) || 0;
    // Если у заказа уже стоит промокод со скидкой — сразу выставляем её
    // ползунком, чтобы не сверять руками; если нет — по умолчанию 10%,
    // просто как отправная точка для расчёта.
    slider.value = pct > 0 ? pct : 10;

    document.getElementById('discount-base-text').textContent =
        _discountBaseRub > 0 ? (_discountBaseRub.toFixed(2) + ' ₽ / ' + _discountBaseUan.toFixed(2) + ' ₴') : 'нет данных о цене услуги';

    recalcDiscountFromSlider();
}

function closeReadyModal() {
    document.getElementById('ready-modal').style.display = 'none';
}

// Ползунок -> сумма скидки (двигаем ползунок, поле суммы плавно подстраивается)
function recalcDiscountFromSlider() {
    var slider = document.getElementById('discount-slider');
    var pct = Number(slider.value);
    document.getElementById('discount-pct-label').textContent = pct;
    var discountRub = _discountBaseRub * pct / 100;
    document.getElementById('discount-amount-input').value = discountRub.toFixed(2);
    updateDiscountFinalText(pct);
}

// Поле суммы -> процент (вписал сумму руками, ползунок сам встаёт на нужный %)
function recalcDiscountFromAmount() {
    var amountInput = document.getElementById('discount-amount-input');
    var amount = Number(amountInput.value) || 0;
    var pct = _discountBaseRub > 0 ? Math.min(100, Math.max(0, (amount / _discountBaseRub) * 100)) : 0;
    var slider = document.getElementById('discount-slider');
    slider.value = Math.round(pct);
    document.getElementById('discount-pct-label').textContent = Math.round(pct);
    updateDiscountFinalText(pct, amount);
}

function updateDiscountFinalText(pct, exactAmountRub) {
    var discountRub = (exactAmountRub !== undefined) ? exactAmountRub : (_discountBaseRub * pct / 100);
    var finalRub = Math.max(0, _discountBaseRub - discountRub);
    var finalUan = _discountBaseUan > 0 ? Math.max(0, _discountBaseUan * (finalRub / (_discountBaseRub || 1))) : 0;
    document.getElementById('discount-final-text').textContent =
        _discountBaseRub > 0 ? (finalRub.toFixed(2) + ' ₽ / ' + finalUan.toFixed(2) + ' ₴') : '—';
}

function applyDiscountToAmount() {
    var pct = Number(document.getElementById('discount-slider').value);
    var discountRub = _discountBaseRub * pct / 100;
    var finalRub = Math.max(0, _discountBaseRub - discountRub);
    document.getElementById('ready-amount').value = finalRub.toFixed(2);
    document.getElementById('ready-currency').value = 'RUB';
}

document.getElementById('discount-slider').addEventListener('input', recalcDiscountFromSlider);
document.getElementById('discount-amount-input').addEventListener('input', recalcDiscountFromAmount);

function selectPayMethod(radioEl) {
    if (radioEl && radioEl.value) selectPayMethod2(radioEl.value);
}
function selectPayMethod2(method) {
    _selectedPayMethod = method;
    ['donation','crypto','monobank'].forEach(m => {
        const el = document.getElementById('pm-' + m);
        if (!el) return;
        el.style.opacity = m === method ? '1' : '0.45';
        el.style.transform = m === method ? 'scale(1.05)' : 'scale(1)';
    });
    // Set hidden radio
    const radio = document.querySelector('input[name="pay_method"][value="' + method + '"]');
    if (radio) radio.checked = true;
}
document.getElementById('ready-modal').addEventListener('click', function(e) {
    if (e.target === this) closeReadyModal();
});
</script>
<script>
// Закрываем открытое меню "Принять", если кликнули куда-то ещё
document.addEventListener('click', function(e) {
    document.querySelectorAll('details.accept-menu[open]').forEach(function(d) {
        if (!d.contains(e.target)) d.removeAttribute('open');
    });
});
</script>
</body>
</html>