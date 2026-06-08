<?php
/**
 * bot_commands.php — Админ-команды для Telegram бота Kostlim Design
 * Команды работают в ЛС и в приват-паке (-1003781426510), если отправитель — админ.
 */

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
        default:
            return false;
    }
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
    $text .= "• `/admin` — открыть админ-панель\n\n";
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

        $pdo->exec("CREATE TABLE IF NOT EXISTS blacklist (
            id SERIAL PRIMARY KEY,
            telegram VARCHAR(255) DEFAULT '',
            ip VARCHAR(64) DEFAULT NULL,
            reason TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $count = (int)$pdo->query("SELECT COUNT(*) FROM bot_commands")->fetchColumn();
        if ($count === 0) {
            $insert = $pdo->prepare("INSERT INTO bot_commands (command, description, access_level) VALUES (?, ?, 'admin') ON CONFLICT DO NOTHING");
            $defaultCommands = [
                ['mute', '⛔ /mute @username [часы]'],
                ['warn', '⚠️ /warn @username [причина]'],
                ['ban', '🚫 /ban @username [причина]'],
                ['unban', '✅ /unban @username'],
                ['kick', '👢 /kick @username'],
                ['admin', '⚙️ /admin — админ-панель'],
                ['stats', '📊 /stats — статистика'],
                ['help', '📖 /help — список команд'],
            ];
            foreach ($defaultCommands as $cmd) {
                $insert->execute($cmd);
            }
        }
    } catch (Exception $e) {
        error_log('[BotCommands] migration error: ' . $e->getMessage());
    }
}
