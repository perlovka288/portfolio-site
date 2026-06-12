<?php
/**
 * bot_commands.php — Админ-команды для Telegram бота Kostlim Design
 * Команды работают в ЛС и в приват-паке, если отправитель — владелец бота.
 *
 * ВАЖНО: бот должен быть администратором группы с правом "Restrict Members"
 * иначе /mute /ban /kick не будут работать.
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

// ────────────────────────────────────────────────────────────────
// Получить числовой ID пользователя из username через tg_links
// ────────────────────────────────────────────────────────────────
function getUserIdByUsername(PDO $pdo, string $username): ?int
{
    $username = ltrim(trim($username), '@');
    if ($username === '') return null;

    try {
        $stmt = $pdo->prepare(
            "SELECT tg_id FROM tg_links
             WHERE (tg_username = ? OR tg_username = ?)
               AND linked = TRUE
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$username, '@' . $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['tg_id'])) {
            return (int)$row['tg_id'];
        }
    } catch (Exception $e) {}

    return null;
}

// ────────────────────────────────────────────────────────────────
// Вызов Telegram API — restrict / unrestrict / ban / unban
// ────────────────────────────────────────────────────────────────

/**
 * Замутить пользователя в Telegram-группе через restrictChatMember.
 * $groupChatId — числовой ID группы (например -1003781426510)
 * $userId      — числовой tg_id пользователя
 * $untilDate   — unix timestamp окончания мута (0 = навсегда)
 */
function tgRestrictUser(string $token, int $groupChatId, int $userId, int $untilDate = 0): array
{
    $params = [
        'chat_id' => $groupChatId,
        'user_id' => $userId,
        'permissions' => json_encode([
            'can_send_messages'       => false,
            'can_send_audios'         => false,
            'can_send_documents'      => false,
            'can_send_photos'         => false,
            'can_send_videos'         => false,
            'can_send_video_notes'    => false,
            'can_send_voice_notes'    => false,
            'can_send_polls'          => false,
            'can_send_other_messages' => false,
            'can_add_web_page_previews' => false,
            'can_change_info'         => false,
            'can_invite_users'        => false,
            'can_pin_messages'        => false,
        ]),
    ];
    if ($untilDate > 0) {
        $params['until_date'] = $untilDate;
    }

    $ch = curl_init("https://api.telegram.org/bot{$token}/restrictChatMember");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POSTFIELDS     => $params,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode((string)$res, true) ?: ['ok' => false, 'description' => 'curl error'];
}

/**
 * Снять мут — вернуть все права обычного участника.
 */
function tgUnrestrictUser(string $token, int $groupChatId, int $userId): array
{
    $params = [
        'chat_id' => $groupChatId,
        'user_id' => $userId,
        'permissions' => json_encode([
            'can_send_messages'       => true,
            'can_send_audios'         => true,
            'can_send_documents'      => true,
            'can_send_photos'         => true,
            'can_send_videos'         => true,
            'can_send_video_notes'    => true,
            'can_send_voice_notes'    => true,
            'can_send_polls'          => true,
            'can_send_other_messages' => true,
            'can_add_web_page_previews' => true,
        ]),
    ];

    $ch = curl_init("https://api.telegram.org/bot{$token}/restrictChatMember");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POSTFIELDS     => $params,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode((string)$res, true) ?: ['ok' => false, 'description' => 'curl error'];
}

/**
 * Забанить через banChatMember.
 */
function tgBanUser(string $token, int $groupChatId, int $userId, int $untilDate = 0): array
{
    $params = ['chat_id' => $groupChatId, 'user_id' => $userId, 'revoke_messages' => false];
    if ($untilDate > 0) $params['until_date'] = $untilDate;

    $ch = curl_init("https://api.telegram.org/bot{$token}/banChatMember");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POSTFIELDS => $params]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode((string)$res, true) ?: ['ok' => false, 'description' => 'curl error'];
}

/**
 * Разбанить через unbanChatMember.
 */
function tgUnbanUser(string $token, int $groupChatId, int $userId): array
{
    $params = ['chat_id' => $groupChatId, 'user_id' => $userId, 'only_if_banned' => true];
    $ch = curl_init("https://api.telegram.org/bot{$token}/unbanChatMember");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POSTFIELDS => $params]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode((string)$res, true) ?: ['ok' => false, 'description' => 'curl error'];
}

/**
 * Кикнуть (бан + сразу разбан = исключить без постоянного бана).
 */
function tgKickUser(string $token, int $groupChatId, int $userId): array
{
    $res = tgBanUser($token, $groupChatId, $userId);
    if (!empty($res['ok'])) {
        sleep(1);
        tgUnbanUser($token, $groupChatId, $userId);
    }
    return $res;
}

// ────────────────────────────────────────────────────────────────
// Роутер команд
// ────────────────────────────────────────────────────────────────
function processAdminCommand(PDO $pdo, string $token, int $chat_id, string $text, array $update): bool
{
    if (!isBotAdmin($update)) {
        return false;
    }

    $replyChatId = getBotReplyChatId($update) ?: $chat_id;

    // Определяем группу для применения ограничений:
    // если команда написана в группе — используем этот чат,
    // если в ЛС — используем BOT_PRIVATE_PACK_ID
    $groupChatId = (abs($replyChatId) !== abs(getBotAdminId())) ? $replyChatId : BOT_PRIVATE_PACK_ID;

    $parts   = explode(' ', $text, 3);
    $command = strtolower(trim($parts[0] ?? ''));
    $arg1    = trim($parts[1] ?? '');
    $arg2    = trim($parts[2] ?? '');

    switch ($command) {
        case '/mute':
            return cmdMute($pdo, $token, $replyChatId, $groupChatId, $arg1, $arg2);
        case '/unmute':
            return cmdUnmute($pdo, $token, $replyChatId, $groupChatId, $arg1);
        case '/warn':
            return cmdWarn($pdo, $token, $replyChatId, $groupChatId, $arg1, $arg2);
        case '/unwarn':
            return cmdUnwarn($pdo, $token, $replyChatId, $arg1);
        case '/ban':
            return cmdBan($pdo, $token, $replyChatId, $groupChatId, $arg1, $arg2);
        case '/unban':
            return cmdUnban($pdo, $token, $replyChatId, $groupChatId, $arg1);
        case '/kick':
            return cmdKick($pdo, $token, $replyChatId, $groupChatId, $arg1);
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

// ────────────────────────────────────────────────────────────────
// Команды
// ────────────────────────────────────────────────────────────────

/**
 * /mute @username [минуты] [причина]
 * Пример: /mute @user 30 спам
 * Если минуты не указаны — мут навсегда.
 */
function cmdMute(PDO $pdo, string $token, int $replyChatId, int $groupChatId, string $target, string $arg2): bool
{
    if ($target === '') {
        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $replyChatId,
            'text'       => "⛔ *Использование:* `/mute @username [минуты] [причина]`\n\nПример: `/mute @user 30 спам`",
            'parse_mode' => 'Markdown',
        ]);
        return true;
    }

    $username = ltrim($target, '@');

    // Парсим: первое слово arg2 — число минут, остальное — причина
    $parts2      = explode(' ', $arg2, 2);
    $maybeMinutes = trim($parts2[0] ?? '');
    $reason       = trim($parts2[1] ?? '');

    if ($maybeMinutes !== '' && is_numeric($maybeMinutes)) {
        $durationMin = max(1, (int)$maybeMinutes);
        if ($reason === '') $reason = 'Без причины';
    } else {
        $durationMin = 0; // навсегда
        $reason      = $arg2 ?: 'Без причины';
    }

    $untilDate  = $durationMin > 0 ? time() + $durationMin * 60 : 0;
    $durationTxt = $durationMin > 0 ? "на {$durationMin} мин." : 'навсегда';

    // 1) Ищем tg_id по username
    $userId = getUserIdByUsername($pdo, $username);

    // 2) Применяем в Telegram если нашли ID
    $tgResult = null;
    $tgOk     = false;
    if ($userId) {
        $tgResult = tgRestrictUser($token, $groupChatId, $userId, $untilDate);
        $tgOk     = !empty($tgResult['ok']);
    }

    // 3) Пишем в БД
    try {
        $expiresAt = $durationMin > 0 ? date('Y-m-d H:i:s', $untilDate) : null;
        $pdo->prepare(
            "INSERT INTO moderation (user_id, username, type, reason, duration_minutes, issued_by, expires_at)
             VALUES (?, ?, 'mute', ?, ?, ?, ?)"
        )->execute([$userId ?? 0, $username, $reason, $durationMin, getBotAdminId(), $expiresAt]);
    } catch (Exception $e) {}

    // 4) Ответ
    if (!$userId) {
        $statusLine = "⚠️ _Пользователь не найден в БД — запись сделана, но ограничение в TG не применено._\n_Попроси его написать боту `/start` для привязки._";
    } elseif ($tgOk) {
        $statusLine = "✅ Ограничение применено в Telegram.";
    } else {
        $desc = $tgResult['description'] ?? 'нет ответа';
        $statusLine = "⚠️ _Запись в БД сделана, но Telegram ответил ошибкой:_\n`{$desc}`\n\n_Убедись, что бот — администратор с правом «Restrict Members»._";
    }

    sendTelegram($token, 'sendMessage', [
        'chat_id'    => $replyChatId,
        'text'       => "⛔ *Мут: @{$username}* — {$durationTxt}\n📝 Причина: {$reason}\n\n{$statusLine}",
        'parse_mode' => 'Markdown',
    ]);
    return true;
}

/**
 * /unmute @username — снять мут
 */
function cmdUnmute(PDO $pdo, string $token, int $replyChatId, int $groupChatId, string $target): bool
{
    if ($target === '') {
        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $replyChatId,
            'text'       => "✅ *Использование:* `/unmute @username`",
            'parse_mode' => 'Markdown',
        ]);
        return true;
    }

    $username = ltrim($target, '@');
    $userId   = getUserIdByUsername($pdo, $username);

    $tgOk  = false;
    $tgMsg = '';
    if ($userId) {
        $res  = tgUnrestrictUser($token, $groupChatId, $userId);
        $tgOk = !empty($res['ok']);
        if (!$tgOk) $tgMsg = "\n⚠️ TG: " . ($res['description'] ?? 'ошибка');
    }

    try {
        $pdo->prepare("UPDATE moderation SET is_active = FALSE WHERE username = ? AND type = 'mute' AND is_active = TRUE")
            ->execute([$username]);
    } catch (Exception $e) {}

    $icon = $tgOk ? '✅' : '📝';
    sendTelegram($token, 'sendMessage', [
        'chat_id'    => $replyChatId,
        'text'       => "{$icon} *@{$username}* размучен.{$tgMsg}" . (!$userId ? "\n⚠️ _ID не найден в БД — только снято в записях._" : ''),
        'parse_mode' => 'Markdown',
    ]);
    return true;
}

/**
 * /warn @username [причина]
 */
function cmdWarn(PDO $pdo, string $token, int $replyChatId, int $groupChatId, string $target, string $reason): bool
{
    if ($target === '') {
        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $replyChatId,
            'text'       => "⚠️ *Использование:* `/warn @username [причина]`",
            'parse_mode' => 'Markdown',
        ]);
        return true;
    }

    $username = ltrim($target, '@');
    $reason   = $reason ?: 'Без причины';

    try {
        $pdo->prepare("INSERT INTO moderation (user_id, username, type, reason, issued_by) VALUES (0, ?, 'warn', ?, ?)")
            ->execute([$username, $reason, getBotAdminId()]);

        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM moderation WHERE username = ? AND type = 'warn' AND is_active = TRUE");
        $cntStmt->execute([$username]);
        $warnCount = (int)$cntStmt->fetchColumn();

        $extra = '';
        // После 3 варнов — автомут на 60 минут
        if ($warnCount >= 3) {
            $userId = getUserIdByUsername($pdo, $username);
            if ($userId) {
                $untilDate = time() + 3600;
                tgRestrictUser($token, $groupChatId, $userId, $untilDate);
                $pdo->prepare("INSERT INTO moderation (user_id, username, type, reason, duration_minutes, issued_by, expires_at) VALUES (?, ?, 'mute', ?, 60, ?, ?)")
                    ->execute([$userId, $username, 'Авто-мут за 3 варна', getBotAdminId(), date('Y-m-d H:i:s', $untilDate)]);
            }
            $extra = "\n🚨 *3 варна — автомут на 60 мин!*";
        }

        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $replyChatId,
            'text'       => "⚠️ *Варн: @{$username}* ({$warnCount}/3)\n📝 Причина: {$reason}{$extra}",
            'parse_mode' => 'Markdown',
        ]);
    } catch (Exception $e) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $replyChatId, 'text' => '❌ ' . $e->getMessage()]);
    }
    return true;
}

/**
 * /unwarn @username — снять последний варн
 */
function cmdUnwarn(PDO $pdo, string $token, int $replyChatId, string $target): bool
{
    if ($target === '') {
        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $replyChatId,
            'text'       => "✅ *Использование:* `/unwarn @username`",
            'parse_mode' => 'Markdown',
        ]);
        return true;
    }

    $username = ltrim($target, '@');
    try {
        // Снимаем самый последний активный варн
        $pdo->prepare(
            "UPDATE moderation SET is_active = FALSE
             WHERE id = (
                 SELECT id FROM moderation
                 WHERE username = ? AND type = 'warn' AND is_active = TRUE
                 ORDER BY created_at DESC LIMIT 1
             )"
        )->execute([$username]);

        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM moderation WHERE username = ? AND type = 'warn' AND is_active = TRUE");
        $cntStmt->execute([$username]);
        $remaining = (int)$cntStmt->fetchColumn();

        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $replyChatId,
            'text'       => "✅ *@{$username}* — 1 варн снят. Осталось: {$remaining}/3",
            'parse_mode' => 'Markdown',
        ]);
    } catch (Exception $e) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $replyChatId, 'text' => '❌ ' . $e->getMessage()]);
    }
    return true;
}

/**
 * /ban @username [причина]
 */
function cmdBan(PDO $pdo, string $token, int $replyChatId, int $groupChatId, string $target, string $reason): bool
{
    if ($target === '') {
        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $replyChatId,
            'text'       => "🚫 *Использование:* `/ban @username [причина]`",
            'parse_mode' => 'Markdown',
        ]);
        return true;
    }

    $username = ltrim($target, '@');
    $reason   = $reason ?: 'Без причины';
    $userId   = getUserIdByUsername($pdo, $username);

    $tgOk  = false;
    $tgMsg = '';
    if ($userId) {
        $res  = tgBanUser($token, $groupChatId, $userId);
        $tgOk = !empty($res['ok']);
        if (!$tgOk) $tgMsg = "\n⚠️ TG: " . ($res['description'] ?? 'ошибка');
    }

    try {
        $pdo->prepare("INSERT INTO moderation (user_id, username, type, reason, issued_by) VALUES (?, ?, 'ban', ?, ?)")
            ->execute([$userId ?? 0, $username, $reason, getBotAdminId()]);
        $pdo->prepare("INSERT INTO blacklist (telegram, reason, created_at) VALUES (?, ?, NOW()) ON CONFLICT DO NOTHING")
            ->execute(['@' . $username, $reason]);
    } catch (Exception $e) {}

    $icon = $tgOk ? '🚫' : '📝';
    sendTelegram($token, 'sendMessage', [
        'chat_id'    => $replyChatId,
        'text'       => "{$icon} *Бан: @{$username}*\n📝 Причина: {$reason}{$tgMsg}" . (!$userId ? "\n⚠️ _ID не найден — только в чёрном списке сайта._" : ''),
        'parse_mode' => 'Markdown',
    ]);
    return true;
}

/**
 * /unban @username
 */
function cmdUnban(PDO $pdo, string $token, int $replyChatId, int $groupChatId, string $target): bool
{
    if ($target === '') {
        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $replyChatId,
            'text'       => "✅ *Использование:* `/unban @username`",
            'parse_mode' => 'Markdown',
        ]);
        return true;
    }

    $username = ltrim($target, '@');
    $userId   = getUserIdByUsername($pdo, $username);

    $tgOk  = false;
    $tgMsg = '';
    if ($userId) {
        $res  = tgUnbanUser($token, $groupChatId, $userId);
        $tgOk = !empty($res['ok']);
        if (!$tgOk) $tgMsg = "\n⚠️ TG: " . ($res['description'] ?? 'ошибка');
    }

    try {
        $pdo->prepare("UPDATE moderation SET is_active = FALSE WHERE username = ? AND type = 'ban'")->execute([$username]);
        $pdo->prepare("DELETE FROM blacklist WHERE telegram = ?")->execute(['@' . $username]);
    } catch (Exception $e) {}

    $icon = $tgOk ? '✅' : '📝';
    sendTelegram($token, 'sendMessage', [
        'chat_id'    => $replyChatId,
        'text'       => "{$icon} *@{$username}* разбанен.{$tgMsg}",
        'parse_mode' => 'Markdown',
    ]);
    return true;
}

/**
 * /kick @username — кикнуть из группы (бан + сразу разбан)
 */
function cmdKick(PDO $pdo, string $token, int $replyChatId, int $groupChatId, string $target): bool
{
    if ($target === '') {
        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $replyChatId,
            'text'       => "👢 *Использование:* `/kick @username`",
            'parse_mode' => 'Markdown',
        ]);
        return true;
    }

    $username = ltrim($target, '@');
    $userId   = getUserIdByUsername($pdo, $username);

    $tgOk  = false;
    $tgMsg = '';
    if ($userId) {
        $res  = tgKickUser($token, $groupChatId, $userId);
        $tgOk = !empty($res['ok']);
        if (!$tgOk) $tgMsg = "\n⚠️ TG: " . ($res['description'] ?? 'ошибка');
    }

    $icon = $tgOk ? '👢' : '📝';
    sendTelegram($token, 'sendMessage', [
        'chat_id'    => $replyChatId,
        'text'       => "{$icon} *@{$username}* кикнут из группы.{$tgMsg}" . (!$userId ? "\n⚠️ _ID не найден в БД._" : ''),
        'parse_mode' => 'Markdown',
    ]);
    return true;
}

function cmdAdmin(PDO $pdo, string $token, int $chatId): bool
{
    $siteUrl = rtrim(getenv('SITE_URL') ?: 'https://kostlimdzn.kesug.com/', '/') . '/';
    sendTelegram($token, 'sendMessage', [
        'chat_id'    => $chatId,
        'text'       => "⚙️ *Админ-панель*\n\n🌐 {$siteUrl}admin/\n\n📖 `/help` · 📊 `/stats`",
        'parse_mode' => 'Markdown',
    ]);
    return true;
}

function cmdStats(PDO $pdo, string $token, int $chatId): bool
{
    try {
        $total    = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        $ready    = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='ready'")->fetchColumn();
        $active   = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','in_progress','urgent')")->fetchColumn();
        $mutedNow = (int)$pdo->query("SELECT COUNT(*) FROM moderation WHERE type='mute' AND is_active=TRUE AND (expires_at IS NULL OR expires_at > NOW())")->fetchColumn();
        $bans     = (int)$pdo->query("SELECT COUNT(*) FROM blacklist")->fetchColumn();

        sendTelegram($token, 'sendMessage', [
            'chat_id'    => $chatId,
            'text'       => "📊 *Статистика*\n\n📦 Заказов: *{$total}* (активных *{$active}*, готово *{$ready}*)\n⛔ Замучено сейчас: *{$mutedNow}*\n🚫 В чёрном списке: *{$bans}*",
            'parse_mode' => 'Markdown',
        ]);
    } catch (Exception $e) {
        sendTelegram($token, 'sendMessage', ['chat_id' => $chatId, 'text' => '❌ ' . $e->getMessage()]);
    }
    return true;
}

function cmdHelp(PDO $pdo, string $token, int $chatId): bool
{
    $text  = "📖 *Команды Kostlim Bot*\n\n";
    $text .= "🛡 *Модерация (время в минутах):*\n";
    $text .= "• `/mute @user 30` — замутить на 30 мин\n";
    $text .= "• `/mute @user 0` или `/mute @user` — навсегда\n";
    $text .= "• `/unmute @user` — снять мут\n";
    $text .= "• `/warn @user [причина]` — предупреждение (3 → автомут 60 мин)\n";
    $text .= "• `/unwarn @user` — снять последний варн\n";
    $text .= "• `/ban @user [причина]` — бан\n";
    $text .= "• `/unban @user` — разбан\n";
    $text .= "• `/kick @user` — кик из группы\n\n";
    $text .= "⚙️ *Прочее:*\n";
    $text .= "• `/admin` — ссылка на сайт\n";
    $text .= "• `/stats` — статистика\n\n";
    $text .= "⚠️ _Бот должен быть администратором с правом «Restrict Members»._";

    sendTelegram($token, 'sendMessage', [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'Markdown',
    ]);
    return true;
}

// ────────────────────────────────────────────────────────────────
// Миграция таблиц
// ────────────────────────────────────────────────────────────────
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
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT uniq_blacklist_tg UNIQUE (telegram)
        )");

        // Добавляем UNIQUE constraint если ещё нет (для ON CONFLICT)
        try {
            $pdo->exec("ALTER TABLE blacklist ADD CONSTRAINT uniq_blacklist_tg UNIQUE (telegram)");
        } catch (Throwable $e) {}

        $count = (int)$pdo->query("SELECT COUNT(*) FROM bot_commands")->fetchColumn();
        if ($count === 0) {
            $insert = $pdo->prepare("INSERT INTO bot_commands (command, description, access_level) VALUES (?, ?, 'admin') ON CONFLICT DO NOTHING");
            foreach ([
                ['mute',   '⛔ /mute @username [минуты] [причина]'],
                ['unmute', '✅ /unmute @username'],
                ['warn',   '⚠️ /warn @username [причина]'],
                ['unwarn', '✅ /unwarn @username'],
                ['ban',    '🚫 /ban @username [причина]'],
                ['unban',  '✅ /unban @username'],
                ['kick',   '👢 /kick @username'],
                ['admin',  '⚙️ /admin — админ-панель'],
                ['stats',  '📊 /stats — статистика'],
                ['help',   '📖 /help — список команд'],
            ] as $cmd) {
                $insert->execute($cmd);
            }
        }
    } catch (Exception $e) {
        error_log('[BotCommands] migration error: ' . $e->getMessage());
    }
}