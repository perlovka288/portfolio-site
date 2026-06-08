<?php
/**
 * bot_commands.php — Админ-команды для Telegram бота Kostlim Design
 * Команды работают в ЛС и в приват-паке (-1003781426510), если отправитель — админ.
 */

require_once __DIR__ . '/upload_token.php';

define('BOT_PRIVATE_PACK_ID', (int)(getenv('PRIVATE_CHAT_ID') ?: '-1003781426510'));

function getBotAdminId(): int
{
    return (int)(getenv('ADMIN_ID') ?: '1710365896');
}

function isBotAdmin(array $update): bool
{
    $fromId = (int)(
        $update['message']['from']['id']
        ?? $update['callback_query']['from']['id']
        ?? 0
    );
    return $fromId === getBotAdminId();
}

function getBotReplyChatId(array $update): int
{
    return (int)(
        $update['message']['chat']['id']
        ?? $update['callback_query']['message']['chat']['id']
        ?? 0
    );
}

/**
 * @return bool true если команда обработана
 */
function processAdminCommand(PDO $pdo, string $token, int $chat_id, string $text, array $update): bool
{
    if (!isBotAdmin($update)) {
        return false;
    }

    $replyChatId = getBotReplyChatId($update) ?: $chat_id;
    $adminId = getBotAdminId();

    // Исправление обработки /upload [ID] и /upload_[ID] с использованием preg_match
    if (preg_match('/^\/upload(?:_|\s+)(\d+)$/i', $text, $matches)) {
        $portfolioId = (int)$matches[1];
        return cmdHandleUploadAndPublish($pdo, $token, $replyChatId, $portfolioId);
    }

    $parts = explode(' ', $text, 3);
    $command = strtolower(trim($parts[0] ?? ''));
    $arg1 = trim($parts[1] ?? '');
    $arg2 = trim($parts[2] ?? '');

    switch ($command) {
        case '/mute':
            return cmdMute($pdo, $token, $replyChatId, $arg1, $arg2);
        case '/warn':
            return cmdWarn($pdo, $token, $replyChatId, $arg1, $arg2);
        case '/ban':
            return cmdBan($pdo, $token, $replyChatId, $arg1, $arg2);
        case '/unban':
            return cmdUnban($pdo, $token, $replyChatId, $arg1);
        case '/kick':
            return cmdKick($pdo, $token, $replyChatId, $arg1, $arg2);
        case '/admin':
            return cmdAdmin($pdo, $token, $replyChatId);
        case '/stats':
            return cmdStats($pdo, $token, $replyChatId);
        case '/help':
            return cmdHelp($pdo, $token, $replyChatId);
        case '/list_published':
            return cmdListPublished($pdo, $token, $replyChatId);
        case '/upload':
        case '/upload_psd':
            return cmdUploadLink($pdo, $token, $replyChatId, $adminId, 'psd_upload', $arg1);
        case '/upload_preview':
            return cmdUploadLink($pdo, $token, $replyChatId, $adminId, 'preview_upload', $arg1);
        case '/portfolio':
            return cmdPortfolio($pdo, $token, $replyChatId);
        case '/publish_psd':
            return cmdPublishPsd($pdo, $token, $replyChatId, $arg1);
        default:
            return false;
    }
}

function cmdUploadLink(PDO $pdo, string $token, int $chatId, int $adminId, string $purpose, string $portfolioArg): bool
{
    $portfolioId = null;
    if ($portfolioArg !== '' && ctype_digit($portfolioArg)) {
        $portfolioId = (int)$portfolioArg;
    }

    $result = createUploadToken($pdo, $purpose, $chatId, $adminId, $portfolioId);
    if (empty($result['ok'])) {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => '❌ Не удалось создать ссылку для загрузки.',
        ]);
        return true;
    }

    $typeLabel = $purpose === 'preview_upload' ? 'превью' : 'PSD / исходник';
    $text = "🔗 *Одноразовая ссылка для загрузки*\n\n";
    $text .= "📁 Тип: *{$typeLabel}*\n";
    $text .= "💾 Лимит: до *300 МБ*\n";
    $text .= "⏱ Действует *2 часа*, одноразовая\n\n";
    if ($portfolioId) {
        $text .= "🎨 Привязка к портфолио: *#{$portfolioId}*\n\n";
    }
    $text .= "👇 Открой в браузере:\n" . ($result['url'] ?? '');

    sendTelegram($token, 'sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => false,
    ]);
    return true;
}

/**
 * Обработка /upload [ID]: обновляет статус в БД и публикует пост с PSD в приватный канал
 */
function cmdHandleUploadAndPublish(PDO $pdo, string $token, int $chatId, int $portfolioId): bool
{
    require_once __DIR__ . '/psd_manager.php';

    // 1. Обновляем статус кейса в базе данных (колонку status добавим через миграцию ниже)
    try {
        $pdo->prepare("UPDATE portfolio SET status = 'published' WHERE id = ?")->execute([$portfolioId]);
    } catch (Throwable $e) {
        // Если колонка еще не создана, она будет добавлена при следующем запуске бота
    }

    // 2. Получаем актуальные данные работы
    $stmt = $pdo->prepare("SELECT * FROM portfolio WHERE id = ? LIMIT 1");
    $stmt->execute([$portfolioId]);
    $work = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$work) {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text'    => "❌ Кейс #{$portfolioId} не найден в базе данных.",
        ]);
        return true;
    }

    // 3. Автоматически публикуем пост с PSD-файлами в приватный канал -1003781426510
    $uploadDir = __DIR__ . '/../uploads/';
    $photoPath = buildWatermarkedPhotoForPortfolio($pdo, $uploadDir, $work);

    $result = publishPortfolioToPrivatePack(
        $pdo,
        $token,
        $portfolioId,
        (string)$work['title'],
        (int)$work['price_rub'],
        (int)$work['price_uan'],
        $photoPath
    );

    if ($photoPath && str_contains($photoPath, sys_get_temp_dir()) && is_file($photoPath)) {
        @unlink($photoPath);
    }

    $msg = ($result['success'] ?? false)
        ? "✅ Кейс #{$portfolioId} («{$work['title']}») обновлен и опубликован в приватный канал!"
        : "⚠️ Статус обновлен, но возникла ошибка при публикации: " . ($result['message'] ?? 'неизвестно');

    sendTelegram($token, 'sendMessage', [
        'chat_id' => $chatId,
        'text'    => $msg,
    ]);
    return true;
}

/**
 * Команда /list_published: выводит список всех кейсов со статусом 'published'
 */
function cmdListPublished(PDO $pdo, string $token, int $chatId): bool
{
    try {
        $stmt = $pdo->query("SELECT id, title FROM portfolio WHERE status = 'published' ORDER BY id DESC");
        $works = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $chatId, 'text' => '❌ Ошибка БД: ' . $e->getMessage()]);
        return true;
    }

    if (empty($works)) {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text'    => "📭 Опубликованных кейсов пока нет.",
        ]);
        return true;
    }

    $text = "📜 *Опубликованные кейсы:*\n\n";
    foreach ($works as $w) {
        $text .= "• #{$w['id']} — {$w['title']}\n";
    }
    $text .= "\n_Всего: " . count($works) . "_";

    sendTelegram($token, 'sendMessage', [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'Markdown',
    ]);
    return true;
}

function cmdPortfolio(PDO $pdo, string $token, int $chatId): bool
{
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM portfolio")->fetchColumn();
        $recent = $pdo->query("SELECT id, title, price_rub, price_uan FROM portfolio ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $chatId, 'text' => '❌ Ошибка БД: ' . $e->getMessage()]);
        return true;
    }

    $text = "🎨 *Портфолио Kostlim Design*\n\n";
    $text .= "📊 Всего работ: *{$count}*\n\n";
    $text .= "*Команды:*\n";
    $text .= "• `/upload` — ссылка для PSD (до 300 МБ)\n";
    $text .= "• `/upload 12` — PSD для работы #12\n";
    $text .= "• `/upload_preview` — ссылка для превью\n";
    $text .= "• `/publish_psd 12` — отправить PSD #12 в приват-пак\n";
    $text .= "• `/stats` — статистика\n\n";

    if (!empty($recent)) {
        $text .= "*Последние работы:*\n";
        foreach ($recent as $row) {
            $text .= "• #{$row['id']} — {$row['title']} ({$row['price_rub']}₽)\n";
        }
    }

    sendTelegram($token, 'sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown',
    ]);
    return true;
}

function cmdPublishPsd(PDO $pdo, string $token, int $chatId, string $portfolioArg): bool
{
    if ($portfolioArg === '' || !ctype_digit($portfolioArg)) {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => "📦 *Использование:* `/publish_psd ID`\n\nПример: `/publish_psd 15`",
            'parse_mode' => 'Markdown',
        ]);
        return true;
    }

    require_once __DIR__ . '/psd_manager.php';

    $portfolioId = (int)$portfolioArg;
    $stmt = $pdo->prepare("SELECT title, price_rub, price_uan, image, avatar_image, category_key FROM portfolio WHERE id = ? LIMIT 1");
    $stmt->execute([$portfolioId]);
    $work = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$work) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $chatId, 'text' => "❌ Работа #{$portfolioId} не найдена."]);
        return true;
    }

    if (!hasPortfolioPsdFiles($pdo, $portfolioId)) {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => "⚠️ У работы #{$portfolioId} нет PSD. Сначала загрузи: `/upload {$portfolioId}`",
            'parse_mode' => 'Markdown',
        ]);
        return true;
    }

    $uploadDir = __DIR__ . '/../uploads/';
    $photoPath = buildWatermarkedPhotoForPortfolio($pdo, $uploadDir, [
        'title' => $work['title'],
        'category_key' => $work['category_key'] ?? 'preview',
        'price_rub' => (int)$work['price_rub'],
        'price_uan' => (int)$work['price_uan'],
        'image' => $work['image'],
        'avatar_image' => $work['avatar_image'] ?? '',
    ]);

    $result = publishPortfolioToPrivatePack(
        $pdo,
        $token,
        $portfolioId,
        (string)$work['title'],
        (int)$work['price_rub'],
        (int)$work['price_uan'],
        $photoPath
    );

    if ($photoPath && str_contains($photoPath, sys_get_temp_dir()) && is_file($photoPath)) {
        @unlink($photoPath);
    }

    sendTelegram($token, 'sendMessage', [
        'chat_id' => $chatId,
        'text' => ($result['success'] ?? false)
            ? "✅ {$result['message']} (работа #{$portfolioId})"
            : '❌ ' . ($result['message'] ?? 'Ошибка публикации'),
        'parse_mode' => 'Markdown',
    ]);
    return true;
}

function getUserIdByUsername(PDO $pdo, string $username, array $update): ?int
{
    $username = ltrim(trim($username), '@');
    if ($username === '') {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT tg_id FROM tg_links WHERE (tg_username = ? OR tg_username = ?) AND linked = TRUE ORDER BY id DESC LIMIT 1");
        $stmt->execute([$username, '@' . $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['tg_id'])) {
            return (int)$row['tg_id'];
        }
    } catch (Exception $e) {}

    return null;
}

function cmdMute(PDO $pdo, string $token, int $chatId, string $target, string $reason): bool
{
    if ($target === '') {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => "⛔ *Использование:* `/mute @username [часы]`",
            'parse_mode' => 'Markdown',
        ]);
        return true;
    }

    $username = ltrim($target, '@');
    $duration = ($reason !== '' && is_numeric($reason)) ? (int)$reason : 0;

    try {
        $expiresAt = $duration > 0 ? date('Y-m-d H:i:s', time() + $duration * 3600) : null;
        $pdo->prepare("INSERT INTO moderation (user_id, username, type, reason, duration_minutes, issued_by, expires_at) VALUES (0, ?, 'mute', ?, ?, ?, ?)")
            ->execute([$username, is_numeric($reason) ? 'Без причины' : ($reason ?: 'Без причины'), $duration * 60, getBotAdminId(), $expiresAt]);

        $durationText = $duration > 0 ? "на {$duration} ч." : 'навсегда';
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => "⛔ @{$username} замучен {$durationText}",
            'parse_mode' => 'Markdown',
        ]);
    } catch (Exception $e) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $chatId, 'text' => '❌ ' . $e->getMessage()]);
    }
    return true;
}

function cmdWarn(PDO $pdo, string $token, int $chatId, string $target, string $reason): bool
{
    if ($target === '') {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => "⚠️ *Использование:* `/warn @username [причина]`",
            'parse_mode' => 'Markdown',
        ]);
        return true;
    }

    $username = ltrim($target, '@');
    try {
        $pdo->prepare("INSERT INTO moderation (user_id, username, type, reason, issued_by) VALUES (0, ?, 'warn', ?, ?)")
            ->execute([$username, $reason ?: 'Без причины', getBotAdminId()]);
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM moderation WHERE username = ? AND type = 'warn' AND is_active = TRUE");
        $cntStmt->execute([$username]);
        $warnCount = (int)$cntStmt->fetchColumn();
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => "⚠️ @{$username} — предупреждение ({$warnCount}/3)",
            'parse_mode' => 'Markdown',
        ]);
    } catch (Exception $e) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $chatId, 'text' => '❌ ' . $e->getMessage()]);
    }
    return true;
}

function cmdBan(PDO $pdo, string $token, int $chatId, string $target, string $reason): bool
{
    if ($target === '') {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => "🚫 *Использование:* `/ban @username [причина]`",
            'parse_mode' => 'Markdown',
        ]);
        return true;
    }

    $username = ltrim($target, '@');
    try {
        $pdo->prepare("INSERT INTO moderation (user_id, username, type, reason, issued_by) VALUES (0, ?, 'ban', ?, ?)")
            ->execute([$username, $reason ?: 'Без причины', getBotAdminId()]);
        $pdo->prepare("INSERT INTO blacklist (telegram, reason, created_at) VALUES (?, ?, NOW()) ON CONFLICT DO NOTHING")
            ->execute(['@' . $username, $reason ?: 'ban_by_admin']);
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => "🚫 @{$username} забанен",
            'parse_mode' => 'Markdown',
        ]);
    } catch (Exception $e) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $chatId, 'text' => '❌ ' . $e->getMessage()]);
    }
    return true;
}

function cmdUnban(PDO $pdo, string $token, int $chatId, string $target): bool
{
    if ($target === '') {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => "✅ *Использование:* `/unban @username`",
            'parse_mode' => 'Markdown',
        ]);
        return true;
    }

    $username = ltrim($target, '@');
    try {
        $pdo->prepare("UPDATE moderation SET is_active = FALSE WHERE username = ? AND type = 'ban'")->execute([$username]);
        $pdo->prepare("DELETE FROM blacklist WHERE telegram = ?")->execute(['@' . $username]);
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => "✅ @{$username} разбанен",
            'parse_mode' => 'Markdown',
        ]);
    } catch (Exception $e) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $chatId, 'text' => '❌ ' . $e->getMessage()]);
    }
    return true;
}

function cmdKick(PDO $pdo, string $token, int $chatId, string $target, string $reason): bool
{
    if ($target === '') {
        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => "👢 *Использование:* `/kick @username` — в группе приват-пака",
            'parse_mode' => 'Markdown',
        ]);
        return true;
    }

    sendTelegram($token, 'sendMessage', [
        'chat_id' => $chatId,
        'text' => "👢 Используй `/kick` в группе, где бот — администратор.",
        'parse_mode' => 'Markdown',
    ]);
    return true;
}

function cmdAdmin(PDO $pdo, string $token, int $chatId): bool
{
    $siteUrl = rtrim(getenv('SITE_URL') ?: 'https://kostlimdzn.kesug.com/', '/') . '/';
    sendTelegram($token, 'sendMessage', [
        'chat_id' => $chatId,
        'text' => "⚙️ *Админ-панель*\n\n🌐 {$siteUrl}admin/\n\n📖 `/help` · 🎨 `/portfolio` · 📤 `/upload`",
        'parse_mode' => 'Markdown',
    ]);
    return true;
}

function cmdStats(PDO $pdo, string $token, int $chatId): bool
{
    try {
        $total = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        $ready = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='ready'")->fetchColumn();
        $active = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','in_progress','urgent')")->fetchColumn();
        $psdCount = (int)$pdo->query("SELECT COUNT(*) FROM portfolio_psd")->fetchColumn();
        $portfolioCount = (int)$pdo->query("SELECT COUNT(*) FROM portfolio")->fetchColumn();

        sendTelegram($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => "📊 *Статистика*\n\n📦 Заказов: *{$total}* (активных *{$active}*, готово *{$ready}*)\n🎨 Портфолио: *{$portfolioCount}*\n📁 PSD: *{$psdCount}*",
            'parse_mode' => 'Markdown',
        ]);
    } catch (Exception $e) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $chatId, 'text' => '❌ ' . $e->getMessage()]);
    }
    return true;
}

function cmdHelp(PDO $pdo, string $token, int $chatId): bool
{
    try {
        $commands = $pdo->query("SELECT command, description FROM bot_commands ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $commands = [];
    }

    $text = "📖 *Команды Kostlim Bot*\n\n";
    $text .= "🎨 *Портфолио:*\n";
    $text .= "• `/portfolio` — справка и последние работы\n";
    $text .= "• `/upload` — ссылка для PSD (300 МБ)\n";
    $text .= "• `/upload ID` — PSD для работы #ID\n";
    $text .= "• `/upload_preview` — ссылка для превью\n";
    $text .= "• `/publish_psd ID` — в приват-пак\n\n";
    $text .= "• `/list_published` — список опубликованных\n\n";
    $text .= "⚙️ *Админ:* `/admin` `/stats` `/help`\n";
    $text .= "🛡 *Модерация:* `/mute` `/warn` `/ban` `/unban`\n\n";
    $text .= "_Работают в ЛС и в приват-паке._";

    if (!empty($commands)) {
        $text .= "\n\n*Из БД:*\n";
        foreach ($commands as $cmd) {
            $text .= "• `/{$cmd['command']}`\n";
        }
    }

    sendTelegram($token, 'sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown',
    ]);
    return true;
}

function ensureBotCommandTables(PDO $pdo): void
{
    ensureUploadTokenTable($pdo);

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_commands (
            id SERIAL PRIMARY KEY,
            command VARCHAR(100) NOT NULL UNIQUE,
            description TEXT NOT NULL DEFAULT '',
            access_level VARCHAR(20) NOT NULL DEFAULT 'admin',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS moderation (
            id SERIAL PRIMARY KEY,
            user_id BIGINT NOT NULL DEFAULT 0,
            username VARCHAR(255) DEFAULT '',
            type VARCHAR(50) NOT NULL,
            reason TEXT DEFAULT '',
            duration_minutes INT DEFAULT 0,
            issued_by BIGINT NOT NULL,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP DEFAULT NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS portfolio_psd (
            id SERIAL PRIMARY KEY,
            portfolio_id INT NOT NULL,
            psd_file TEXT NOT NULL,
            original_name TEXT NOT NULL DEFAULT '',
            file_size BIGINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS blacklist (
            id SERIAL PRIMARY KEY,
            telegram VARCHAR(255) DEFAULT '',
            ip VARCHAR(64) DEFAULT NULL,
            reason TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        try {
            $pdo->exec("ALTER TABLE portfolio ADD COLUMN IF NOT EXISTS psd_dir TEXT DEFAULT NULL");
            $pdo->exec("ALTER TABLE portfolio ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'published'");
            $pdo->exec("ALTER TABLE portfolio ADD COLUMN IF NOT EXISTS psd_external_link TEXT DEFAULT NULL");
        } catch (Exception $e) {}

        $count = (int)$pdo->query("SELECT COUNT(*) FROM bot_commands")->fetchColumn();
        if ($count === 0) {
            $insert = $pdo->prepare("INSERT INTO bot_commands (command, description, access_level) VALUES (?, ?, 'admin') ON CONFLICT DO NOTHING");
            $defaultCommands = [
                ['portfolio', '🎨 /portfolio — портфолио и команды загрузки'],
                ['upload', '📤 /upload [ID] — одноразовая ссылка для PSD до 300 МБ'],
                ['upload_preview', '🖼 /upload_preview — ссылка для превью'],
                ['publish_psd', '📦 /publish_psd ID — отправить PSD в приват-пак'],
                ['mute', '⛔ /mute @username [часы]'],
                ['warn', '⚠️ /warn @username [причина]'],
                ['ban', '🚫 /ban @username [причина]'],
                ['unban', '✅ /unban @username'],
                ['kick', '👢 /kick @username'],
                ['admin', '⚙️ /admin — админ-панель'],
                ['stats', '📊 /stats — статистика'],
                ['help', '📖 /help — список команд'],
                ['list_published', '📜 /list_published — список опубликованных кейсов'],
            ];
            foreach ($defaultCommands as $cmd) {
                $insert->execute($cmd);
            }
        } else {
            $extra = $pdo->prepare("INSERT INTO bot_commands (command, description, access_level) VALUES (?, ?, 'admin') ON CONFLICT DO NOTHING");
            foreach ([
                ['portfolio', '🎨 /portfolio — портфолио и команды загрузки'],
                ['upload', '📤 /upload [ID] — одноразовая ссылка для PSD до 300 МБ'],
                ['upload_preview', '🖼 /upload_preview — ссылка для превью'],
                ['publish_psd', '📦 /publish_psd ID — отправить PSD в приват-пак'],
                ['list_published', '📜 /list_published — список опубликованных кейсов'],
            ] as $cmd) {
                $extra->execute($cmd);
            }
        }
    } catch (Exception $e) {
        error_log('[BotCommands] migration error: ' . $e->getMessage());
    }
}
