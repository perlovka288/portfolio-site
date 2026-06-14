<?php
/**
 * bot_commands.php — Админ-команды для Telegram бота Kostlim Design
 *
 * ВАЖНО: бот должен быть администратором группы с правом "Restrict Members"
 *
 * Антиспам: 10 сообщений за 10 сек → предупреждение, если продолжает → мут 10 мин
 * Антифлуд: 3 одинаковых сообщения подряд → варн
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
// Получить thread_id (тему) из сообщения если есть
// ────────────────────────────────────────────────────────────────
function getMessageThreadId(array $update): ?int
{
    $threadId = $update['message']['message_thread_id'] ?? null;
    return $threadId !== null ? (int)$threadId : null;
}

// ────────────────────────────────────────────────────────────────
// Отправка с поддержкой тем (message_thread_id)
// ────────────────────────────────────────────────────────────────
function sendToThread(string $token, int $chatId, ?int $threadId, string $text, array $extra = []): void
{
    $params = array_merge([
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'Markdown',
    ], $extra);

    if ($threadId !== null) {
        $params['message_thread_id'] = $threadId;
    }

    sendTelegram($token, 'sendMessage', $params);
}

// ────────────────────────────────────────────────────────────────
// getUserIdByUsername
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
        if ($row && !empty($row['tg_id'])) return (int)$row['tg_id'];
    } catch (Exception $e) {}

    return null;
}

// ────────────────────────────────────────────────────────────────
// Telegram API helpers
// ────────────────────────────────────────────────────────────────
function tgRestrictUser(string $token, int $groupChatId, int $userId, int $untilDate = 0): array
{
    $params = [
        'chat_id'     => $groupChatId,
        'user_id'     => $userId,
        'permissions' => json_encode([
            'can_send_messages'         => false,
            'can_send_audios'           => false,
            'can_send_documents'        => false,
            'can_send_photos'           => false,
            'can_send_videos'           => false,
            'can_send_video_notes'      => false,
            'can_send_voice_notes'      => false,
            'can_send_polls'            => false,
            'can_send_other_messages'   => false,
            'can_add_web_page_previews' => false,
            'can_change_info'           => false,
            'can_invite_users'          => false,
            'can_pin_messages'          => false,
        ]),
    ];
    if ($untilDate > 0) $params['until_date'] = $untilDate;

    $ch = curl_init("https://api.telegram.org/bot{$token}/restrictChatMember");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POSTFIELDS => $params]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode((string)$res, true) ?: ['ok' => false, 'description' => 'curl error'];
}

function tgUnrestrictUser(string $token, int $groupChatId, int $userId): array
{
    $params = [
        'chat_id'     => $groupChatId,
        'user_id'     => $userId,
        'permissions' => json_encode([
            'can_send_messages'         => true,
            'can_send_audios'           => true,
            'can_send_documents'        => true,
            'can_send_photos'           => true,
            'can_send_videos'           => true,
            'can_send_video_notes'      => true,
            'can_send_voice_notes'      => true,
            'can_send_polls'            => true,
            'can_send_other_messages'   => true,
            'can_add_web_page_previews' => true,
        ]),
    ];
    $ch = curl_init("https://api.telegram.org/bot{$token}/restrictChatMember");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POSTFIELDS => $params]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode((string)$res, true) ?: ['ok' => false, 'description' => 'curl error'];
}

function tgBanUser(string $token, int $groupChatId, int $userId, int $untilDate = 0): array
{
    $params = ['chat_id' => $groupChatId, 'user_id' => $userId, 'revoke_messages' => false];
    if ($untilDate > 0) $params['until_date'] = $untilDate;
    $ch = curl_init("https://api.telegram.org/bot{$token}/banChatMember");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POSTFIELDS => $params]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode((string)$res, true) ?: ['ok' => false, 'description' => 'curl error'];
}

function tgUnbanUser(string $token, int $groupChatId, int $userId): array
{
    $params = ['chat_id' => $groupChatId, 'user_id' => $userId, 'only_if_banned' => true];
    $ch = curl_init("https://api.telegram.org/bot{$token}/unbanChatMember");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POSTFIELDS => $params]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode((string)$res, true) ?: ['ok' => false, 'description' => 'curl error'];
}

function tgKickUser(string $token, int $groupChatId, int $userId): array
{
    $res = tgBanUser($token, $groupChatId, $userId);
    if (!empty($res['ok'])) { sleep(1); tgUnbanUser($token, $groupChatId, $userId); }
    return $res;
}

/**
 * Создать одноразовую инвайт-ссылку в группу (истекает через 24ч, лимит 1 человек).
 */
function tgCreateInviteLink(string $token, int $groupChatId): array
{
    $params = [
        'chat_id'      => $groupChatId,
        'expire_date'  => time() + 86400, // 24 часа
        'member_limit' => 1,              // только 1 человек
        'creates_join_request' => false,
    ];
    $ch = curl_init("https://api.telegram.org/bot{$token}/createChatInviteLink");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POSTFIELDS => $params]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode((string)$res, true) ?: ['ok' => false, 'description' => 'curl error'];
}

// ────────────────────────────────────────────────────────────────
// АНТИСПАМ / АНТИФЛУД
// Вызывается из bot.php на каждое сообщение в группе
// ────────────────────────────────────────────────────────────────

/**
 * Проверить сообщение на спам/флуд.
 * Возвращает true если сообщение заблокировано (дальнейшую обработку можно пропустить).
 */
function checkAntiSpam(PDO $pdo, string $token, array $update): bool
{
    $msg      = $update['message'] ?? null;
    if (!$msg) return false;

    $chatType = $msg['chat']['type'] ?? 'private';
    if ($chatType === 'private') return false; // в ЛС не проверяем

    $userId   = (int)($msg['from']['id'] ?? 0);
    $chatId   = (int)($msg['chat']['id'] ?? 0);
    $threadId = getMessageThreadId($update);
    $username = $msg['from']['username'] ?? '';
    $firstName= $msg['from']['first_name'] ?? $username ?: 'Пользователь';
    $text     = trim($msg['text'] ?? '');
    $now      = time();

    // Администратора не трогаем
    if ($userId === getBotAdminId()) return false;

    $token_env = $token; // используем переданный токен

    // ── Антиспам: счётчик сообщений за последние 10 сек ──────────
    $spamBlocked = false;
    try {
        // Убираем старые записи
        $pdo->prepare("DELETE FROM spam_log WHERE user_id = ? AND chat_id = ? AND sent_at < ?")->execute([$userId, $chatId, $now - 10]);

        // Добавляем текущее
        $pdo->prepare("INSERT INTO spam_log (user_id, chat_id, sent_at) VALUES (?, ?, ?)")->execute([$userId, $chatId, $now]);

        // Считаем за окно
        $cnt = (int)$pdo->prepare("SELECT COUNT(*) FROM spam_log WHERE user_id = ? AND chat_id = ?")->execute([$userId, $chatId]) ? 0 : 0;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM spam_log WHERE user_id = ? AND chat_id = ?");
        $stmt->execute([$userId, $chatId]);
        $cnt = (int)$stmt->fetchColumn();

        if ($cnt >= 10) {
            // Проверяем — уже предупреждали?
            $warnStmt = $pdo->prepare("SELECT id FROM spam_warnings WHERE user_id = ? AND chat_id = ? AND warned_at > ?");
            $warnStmt->execute([$userId, $chatId, $now - 60]); // предупреждение действует 60 сек
            $alreadyWarned = $warnStmt->fetch();

            if (!$alreadyWarned) {
                // Первый раз — предупреждение
                $pdo->prepare("INSERT INTO spam_warnings (user_id, chat_id, warned_at) VALUES (?, ?, ?) ON CONFLICT (user_id, chat_id) DO UPDATE SET warned_at = EXCLUDED.warned_at")
                    ->execute([$userId, $chatId, $now]);

                $mention = $username ? "@{$username}" : $firstName;
                sendToThread($token_env, $chatId, $threadId,
                    "⚠️ {$mention}, пожалуйста не спамь! Если продолжишь — получишь мут на 10 минут."
                );
            } else {
                // Продолжает спамить → мут 10 минут
                $untilDate = $now + 600;
                tgRestrictUser($token_env, $chatId, $userId, $untilDate);

                try {
                    $pdo->prepare("INSERT INTO moderation (user_id, username, type, reason, duration_minutes, issued_by, expires_at) VALUES (?, ?, 'mute', 'Автомут за спам', 10, ?, ?)")
                        ->execute([$userId, $username, getBotAdminId(), date('Y-m-d H:i:s', $untilDate)]);
                } catch (Throwable $e) {}

                // Удаляем счётчик чтобы не мутить повторно сразу
                $pdo->prepare("DELETE FROM spam_log WHERE user_id = ? AND chat_id = ?")->execute([$userId, $chatId]);
                $pdo->prepare("DELETE FROM spam_warnings WHERE user_id = ? AND chat_id = ?")->execute([$userId, $chatId]);

                $mention = $username ? "@{$username}" : $firstName;
                sendToThread($token_env, $chatId, $threadId,
                    "🔇 {$mention} замучен на 10 минут за спам."
                );
                $spamBlocked = true;
            }
        }
    } catch (Throwable $e) {
        botLog("antiSpam error: " . $e->getMessage());
    }

    // ── Антифлуд: повторное сообщение ──────────────────────────
    // 2-е одинаковое → предупреждение + кулдаун 1 час
    // 3-е одинаковое (нарушил кулдаун) → мут 1 час
    if (!$spamBlocked && $text !== '') {
        try {
            // Берём последнее сообщение пользователя
            $stmt = $pdo->prepare(
                "SELECT message_text, sent_at FROM flood_log
                 WHERE user_id = ? AND chat_id = ?
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$userId, $chatId]);
            $lastRow = $stmt->fetch(PDO::FETCH_ASSOC);

            $mention = $username ? "@{$username}" : $firstName;

            if ($lastRow && $lastRow['message_text'] === mb_substr($text, 0, 255)) {
                // Совпадает с предыдущим — проверяем был ли уже варн за это сообщение
                $warnChk = $pdo->prepare(
                    "SELECT id FROM flood_cooldown WHERE user_id = ? AND chat_id = ?"
                );
                $warnChk->execute([$userId, $chatId]);
                $cooldownRow = $warnChk->fetch();

                if (!$cooldownRow) {
                    // Первый повтор — предупреждение + ставим кулдаун 1 час
                    $pdo->prepare(
                        "INSERT INTO flood_cooldown (user_id, chat_id, warned_at, message_text)
                         VALUES (?, ?, ?, ?)
                         ON CONFLICT (user_id, chat_id) DO UPDATE SET warned_at = EXCLUDED.warned_at, message_text = EXCLUDED.message_text"
                    )->execute([$userId, $chatId, $now, mb_substr($text, 0, 255)]);

                    sendToThread($token_env, $chatId, $threadId,
                        "⚠️ {$mention}, повторяющиеся сообщения можно отправлять раз в 1 час. " .
                        "Если продолжишь — получишь мут на 1 час."
                    );
                } else {
                    // Второй повтор (нарушил кулдаун) → мут 1 час
                    $untilDate = $now + 3600;
                    tgRestrictUser($token_env, $chatId, $userId, $untilDate);

                    try {
                        $pdo->prepare(
                            "INSERT INTO moderation (user_id, username, type, reason, duration_minutes, issued_by, expires_at)
                             VALUES (?, ?, 'mute', 'Автомут за повторные сообщения', 60, ?, ?)"
                        )->execute([$userId, $username, getBotAdminId(), date('Y-m-d H:i:s', $untilDate)]);
                    } catch (Throwable $e2) {}

                    // Сбрасываем кулдаун и flood_log
                    $pdo->prepare("DELETE FROM flood_cooldown WHERE user_id = ? AND chat_id = ?")->execute([$userId, $chatId]);
                    $pdo->prepare("DELETE FROM flood_log WHERE user_id = ? AND chat_id = ?")->execute([$userId, $chatId]);

                    sendToThread($token_env, $chatId, $threadId,
                        "🔇 {$mention} замучен на 1 час за повторный флуд."
                    );
                }
            } else {
                // Новое сообщение — сбрасываем кулдаун флуда (другой текст = новая тема)
                if ($lastRow && $lastRow['message_text'] !== mb_substr($text, 0, 255)) {
                    $cdChk = $pdo->prepare("SELECT message_text FROM flood_cooldown WHERE user_id = ? AND chat_id = ?");
                    $cdChk->execute([$userId, $chatId]);
                    $cdRow = $cdChk->fetch();
                    // Сбрасываем только если предупреждали за другой текст
                    if ($cdRow && $cdRow['message_text'] !== mb_substr($text, 0, 255)) {
                        $pdo->prepare("DELETE FROM flood_cooldown WHERE user_id = ? AND chat_id = ?")->execute([$userId, $chatId]);
                    }
                }
            }

            // Сохраняем/обновляем последнее сообщение
            $pdo->prepare(
                "INSERT INTO flood_log (user_id, chat_id, message_text, sent_at) VALUES (?, ?, ?, ?)
                 ON CONFLICT DO NOTHING"
            )->execute([$userId, $chatId, mb_substr($text, 0, 255), $now]);

            // Оставляем только последнее
            $pdo->prepare(
                "DELETE FROM flood_log WHERE user_id = ? AND chat_id = ? AND id NOT IN (
                    SELECT id FROM flood_log WHERE user_id = ? AND chat_id = ? ORDER BY id DESC LIMIT 1
                 )"
            )->execute([$userId, $chatId, $userId, $chatId]);

        } catch (Throwable $e) {
            botLog("antiFlood error: " . $e->getMessage());
        }
    }

    return $spamBlocked;
}

// ────────────────────────────────────────────────────────────────
// Роутер команд
// ────────────────────────────────────────────────────────────────
function processAdminCommand(PDO $pdo, string $token, int $chat_id, string $text, array $update): bool
{
    if (!isBotAdmin($update)) return false;

    $replyChatId = getBotReplyChatId($update) ?: $chat_id;
    $threadId    = getMessageThreadId($update);

    // Группа для применения ограничений
    $groupChatId = ($replyChatId !== getBotAdminId()) ? $replyChatId : BOT_PRIVATE_PACK_ID;

    $parts   = explode(' ', $text, 3);
    $command = strtolower(trim($parts[0] ?? ''));
    $arg1    = trim($parts[1] ?? '');
    $arg2    = trim($parts[2] ?? '');

    switch ($command) {
        case '/mute':   return cmdMute($pdo, $token, $replyChatId, $threadId, $groupChatId, $arg1, $arg2);
        case '/unmute': return cmdUnmute($pdo, $token, $replyChatId, $threadId, $groupChatId, $arg1);
        case '/warn':   return cmdWarn($pdo, $token, $replyChatId, $threadId, $groupChatId, $arg1, $arg2);
        case '/unwarn': return cmdUnwarn($pdo, $token, $replyChatId, $threadId, $arg1);
        case '/ban':    return cmdBan($pdo, $token, $replyChatId, $threadId, $groupChatId, $arg1, $arg2);
        case '/unban':  return cmdUnban($pdo, $token, $replyChatId, $threadId, $groupChatId, $arg1);
        case '/kick':   return cmdKick($pdo, $token, $replyChatId, $threadId, $groupChatId, $arg1);
        case '/invite': return cmdInvite($pdo, $token, $replyChatId, $threadId, $groupChatId, $arg1);
        case '/admin':  return cmdAdmin($pdo, $token, $replyChatId, $threadId);
        case '/stats':  return cmdStats($pdo, $token, $replyChatId, $threadId);
        case '/help':   return cmdHelp($pdo, $token, $chat_id, $update); // всегда в ЛС
        default:        return false;
    }
}

// ────────────────────────────────────────────────────────────────
// Команды
// ────────────────────────────────────────────────────────────────

function cmdMute(PDO $pdo, string $token, int $replyChatId, ?int $threadId, int $groupChatId, string $target, string $arg2): bool
{
    if ($target === '') {
        sendToThread($token, $replyChatId, $threadId, "⛔ *Использование:* `/mute @username [минуты] [причина]`\n\nПример: `/mute @user 30 спам`");
        return true;
    }

    $username = ltrim($target, '@');
    $parts2   = explode(' ', $arg2, 2);
    $maybeMin = trim($parts2[0] ?? '');
    $reason   = trim($parts2[1] ?? '');

    if ($maybeMin !== '' && is_numeric($maybeMin)) {
        $durationMin = max(1, (int)$maybeMin);
        if ($reason === '') $reason = 'Без причины';
    } else {
        $durationMin = 0;
        $reason      = $arg2 ?: 'Без причины';
    }

    $untilDate   = $durationMin > 0 ? time() + $durationMin * 60 : 0;
    $durationTxt = $durationMin > 0 ? "на {$durationMin} мин." : 'навсегда';
    $userId      = getUserIdByUsername($pdo, $username);

    $tgOk  = false;
    $tgMsg = '';
    if ($userId) {
        $res  = tgRestrictUser($token, $groupChatId, $userId, $untilDate);
        $tgOk = !empty($res['ok']);
        if (!$tgOk) $tgMsg = "\n⚠️ TG: " . ($res['description'] ?? 'ошибка');
    }

    try {
        $expiresAt = $durationMin > 0 ? date('Y-m-d H:i:s', $untilDate) : null;
        $pdo->prepare("INSERT INTO moderation (user_id, username, type, reason, duration_minutes, issued_by, expires_at) VALUES (?, ?, 'mute', ?, ?, ?, ?)")
            ->execute([$userId ?? 0, $username, $reason, $durationMin, getBotAdminId(), $expiresAt]);
    } catch (Exception $e) {}

    $statusLine = !$userId
        ? "⚠️ _ID не найден в БД — запись сделана, но ограничение в TG не применено._"
        : ($tgOk ? "✅ Применено в Telegram." : "⚠️ _Запись сделана, но ошибка TG:_{$tgMsg}\n_Убедись что бот — администратор с правом «Restrict Members»._");

    sendToThread($token, $replyChatId, $threadId, "⛔ *Мут: @{$username}* — {$durationTxt}\n📝 Причина: {$reason}\n\n{$statusLine}");
    return true;
}

function cmdUnmute(PDO $pdo, string $token, int $replyChatId, ?int $threadId, int $groupChatId, string $target): bool
{
    if ($target === '') {
        sendToThread($token, $replyChatId, $threadId, "✅ *Использование:* `/unmute @username` или `/unmute 123456789` (по ID)");
        return true;
    }

    // Поддержка числового ID напрямую
    if (is_numeric($target)) {
        $userId   = (int)$target;
        $username = $target;
    } else {
        $username = ltrim($target, '@');
        $userId   = getUserIdByUsername($pdo, $username);
    }

    $tgOk  = false;
    $tgMsg = '';
    if ($userId) {
        $res  = tgUnrestrictUser($token, $groupChatId, $userId);
        $tgOk = !empty($res['ok']);
        if (!$tgOk) $tgMsg = "\n⚠️ TG: " . ($res['description'] ?? 'ошибка');
    }

    try {
        $pdo->prepare("UPDATE moderation SET is_active = FALSE WHERE (username = ? OR user_id = ?) AND type = 'mute' AND is_active = TRUE")
            ->execute([$username, $userId ?? 0]);
    } catch (Exception $e) {}

    $icon = $tgOk ? '✅' : '📝';
    sendToThread($token, $replyChatId, $threadId, "{$icon} *@{$username}* размучен.{$tgMsg}" . (!$userId ? "\n⚠️ _ID не найден — только снято в БД._" : ''));
    return true;
}

function cmdWarn(PDO $pdo, string $token, int $replyChatId, ?int $threadId, int $groupChatId, string $target, string $reason): bool
{
    if ($target === '') {
        sendToThread($token, $replyChatId, $threadId, "⚠️ *Использование:* `/warn @username [причина]`");
        return true;
    }

    $username = ltrim($target, '@');
    $reason   = $reason ?: 'Без причины';

    try {
        $pdo->prepare("INSERT INTO moderation (user_id, username, type, reason, issued_by) VALUES (0, ?, 'warn', ?, ?)")
            ->execute([$username, $reason, getBotAdminId()]);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM moderation WHERE username = ? AND type = 'warn' AND is_active = TRUE");
        $stmt->execute([$username]);
        $warnCount = (int)$stmt->fetchColumn();

        $extra = '';
        if ($warnCount >= 3) {
            $userId    = getUserIdByUsername($pdo, $username);
            $untilDate = time() + 3600;
            if ($userId) {
                tgRestrictUser($token, $groupChatId, $userId, $untilDate);
                $pdo->prepare("INSERT INTO moderation (user_id, username, type, reason, duration_minutes, issued_by, expires_at) VALUES (?, ?, 'mute', 'Авто-мут за 3 варна', 60, ?, ?)")
                    ->execute([$userId, $username, getBotAdminId(), date('Y-m-d H:i:s', $untilDate)]);
                // Сбрасываем варны после мута
                $pdo->prepare("UPDATE moderation SET is_active = FALSE WHERE username = ? AND type = 'warn' AND is_active = TRUE")
                    ->execute([$username]);
            }
            $extra = "\n🚨 *3 варна — мут на 1 час! Варны сброшены.*";
        }

        sendToThread($token, $replyChatId, $threadId, "⚠️ *Варн: @{$username}* ({$warnCount}/3)\n📝 Причина: {$reason}{$extra}");
    } catch (Exception $e) {
        sendToThread($token, $replyChatId, $threadId, '❌ ' . $e->getMessage());
    }
    return true;
}

function cmdUnwarn(PDO $pdo, string $token, int $replyChatId, ?int $threadId, string $target): bool
{
    if ($target === '') {
        sendToThread($token, $replyChatId, $threadId, "✅ *Использование:* `/unwarn @username`");
        return true;
    }

    $username = ltrim($target, '@');
    try {
        $pdo->prepare(
            "UPDATE moderation SET is_active = FALSE WHERE id = (
                SELECT id FROM moderation WHERE username = ? AND type = 'warn' AND is_active = TRUE
                ORDER BY created_at DESC LIMIT 1
             )"
        )->execute([$username]);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM moderation WHERE username = ? AND type = 'warn' AND is_active = TRUE");
        $stmt->execute([$username]);
        $remaining = (int)$stmt->fetchColumn();

        sendToThread($token, $replyChatId, $threadId, "✅ *@{$username}* — 1 варн снят. Осталось: {$remaining}/3");
    } catch (Exception $e) {
        sendToThread($token, $replyChatId, $threadId, '❌ ' . $e->getMessage());
    }
    return true;
}

function cmdBan(PDO $pdo, string $token, int $replyChatId, ?int $threadId, int $groupChatId, string $target, string $reason): bool
{
    if ($target === '') {
        sendToThread($token, $replyChatId, $threadId, "🚫 *Использование:* `/ban @username [причина]`");
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
        $pdo->prepare("INSERT INTO blacklist (telegram, reason, created_at) VALUES (?, ?, NOW()) ON CONFLICT (telegram) DO NOTHING")
            ->execute(['@' . $username, $reason]);
    } catch (Exception $e) {}

    $icon = $tgOk ? '🚫' : '📝';
    sendToThread($token, $replyChatId, $threadId, "{$icon} *Бан: @{$username}*\n📝 Причина: {$reason}{$tgMsg}" . (!$userId ? "\n⚠️ _ID не найден — только в чёрном списке._" : ''));
    return true;
}

function cmdUnban(PDO $pdo, string $token, int $replyChatId, ?int $threadId, int $groupChatId, string $target): bool
{
    if ($target === '') {
        sendToThread($token, $replyChatId, $threadId, "✅ *Использование:* `/unban @username` или `/unban 123456789` (по ID)");
        return true;
    }

    // Поддержка числового ID
    if (is_numeric($target)) {
        $userId   = (int)$target;
        $username = $target;
    } else {
        $username = ltrim($target, '@');
        $userId   = getUserIdByUsername($pdo, $username);
    }

    $tgOk  = false;
    $tgMsg = '';
    if ($userId) {
        $res  = tgUnbanUser($token, $groupChatId, $userId);
        $tgOk = !empty($res['ok']);
        if (!$tgOk) $tgMsg = "\n⚠️ TG: " . ($res['description'] ?? 'ошибка');
    }

    try {
        $pdo->prepare("UPDATE moderation SET is_active = FALSE WHERE (username = ? OR user_id = ?) AND type = 'ban'")->execute([$username, $userId ?? 0]);
        $pdo->prepare("DELETE FROM blacklist WHERE telegram = ? OR telegram = ?")->execute(['@' . $username, $username]);
    } catch (Exception $e) {}

    // После разбана — тихо генерируем инвайт и отправляем ТОЛЬКО тебе в ЛС
    if ($tgOk && $userId) {
        $invRes = tgCreateInviteLink($token, $groupChatId);
        if (!empty($invRes['ok'])) {
            $invLink = $invRes['result']['invite_link'] ?? '';
            if ($invLink !== '') {
                // Только владельцу в ЛС — не в чат группы
                sendTelegram($token, 'sendMessage', [
                    'chat_id'    => getBotAdminId(),
                    'text'       => "🔓 @{$username} разбанен.\n🔗 Ссылка (24ч, 1 использование):\n{$invLink}",
                    'parse_mode' => 'Markdown',
                ]);
            }
        }
    }

    // В чате группы — только тихое подтверждение без ссылки
    $icon = $tgOk ? '✅' : '📝';
    sendToThread($token, $replyChatId, $threadId, "{$icon} @{$username} разбанен.{$tgMsg}" . (!$userId ? " ⚠️ ID не найден." : ''));
    return true;
}

function cmdKick(PDO $pdo, string $token, int $replyChatId, ?int $threadId, int $groupChatId, string $target): bool
{
    if ($target === '') {
        sendToThread($token, $replyChatId, $threadId, "👢 *Использование:* `/kick @username`");
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
    sendToThread($token, $replyChatId, $threadId, "{$icon} *@{$username}* кикнут.{$tgMsg}" . (!$userId ? "\n⚠️ _ID не найден в БД._" : ''));
    return true;
}

function cmdInvite(PDO $pdo, string $token, int $replyChatId, ?int $threadId, int $groupChatId, string $target): bool
{
    if ($target === '') {
        sendToThread($token, $replyChatId, $threadId,
            "🔗 *Использование:* `/invite @username` или `/invite 123456789`

Бот сгенерирует одноразовую ссылку и отправит её пользователю в ЛС."
        );
        return true;
    }

    // Числовой ID или username
    if (is_numeric($target)) {
        $userId   = (int)$target;
        $username = $target;
    } else {
        $username = ltrim($target, '@');
        $userId   = getUserIdByUsername($pdo, $username);
    }

    // Генерируем инвайт-ссылку
    $res = tgCreateInviteLink($token, $groupChatId);

    if (empty($res['ok'])) {
        $desc = $res['description'] ?? 'нет ответа';
        sendToThread($token, $replyChatId, $threadId,
            "❌ Не удалось создать ссылку.
`{$desc}`

_Убедись, что бот — администратор с правом «Invite Users»._"
        );
        return true;
    }

    $link = $res['result']['invite_link'] ?? '';

    // Отправляем ссылку пользователю в ЛС если знаем ID
    $sentToDm = false;
    if ($userId && $link !== '') {
        $tgEnv = getenv('TELEGRAM_BOT_TOKEN') ?: '';
        $dmRes = sendTelegram($tgEnv, 'sendMessage', [
            'chat_id'    => $userId,
            'text'       => "🎉 Тебя пригласили вступить в группу!

🔗 Ссылка действует 24 часа (1 использование):
{$link}",
            'parse_mode' => 'Markdown',
        ]);
        $dmData   = json_decode((string)$dmRes, true);
        $sentToDm = !empty($dmData['ok']);
    }

    $display   = is_numeric($target) ? "ID `{$target}`" : "@{$username}";
    $dmStatus  = $sentToDm
        ? "✉️ Ссылка отправлена пользователю в ЛС."
        : "⚠️ _Не удалось отправить в ЛС_ " . (!$userId ? "(ID не найден в БД)" : "(пользователь не писал боту)") . ".";

    sendToThread($token, $replyChatId, $threadId,
        "🔗 *Инвайт для {$display}*

`{$link}`

{$dmStatus}
_Ссылка действует 24ч, 1 использование._"
    );
    return true;
}

function cmdAdmin(PDO $pdo, string $token, int $chatId, ?int $threadId): bool
{
    $siteUrl = rtrim(getenv('SITE_URL') ?: 'https://kostlimdzn.kesug.com/', '/') . '/';
    sendToThread($token, $chatId, $threadId, "⚙️ *Админ-панель*\n\n🌐 {$siteUrl}admin/");
    return true;
}

function cmdStats(PDO $pdo, string $token, int $chatId, ?int $threadId): bool
{
    try {
        $total    = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        $ready    = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='ready'")->fetchColumn();
        $active   = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','in_progress','urgent')")->fetchColumn();
        $mutedNow = (int)$pdo->query("SELECT COUNT(*) FROM moderation WHERE type='mute' AND is_active=TRUE AND (expires_at IS NULL OR expires_at > NOW())")->fetchColumn();
        $bans     = (int)$pdo->query("SELECT COUNT(*) FROM blacklist")->fetchColumn();

        sendToThread($token, $chatId, $threadId,
            "📊 *Статистика*\n\n📦 Заказов: *{$total}* (активных *{$active}*, готово *{$ready}*)\n⛔ Замучено сейчас: *{$mutedNow}*\n🚫 В чёрном списке: *{$bans}*"
        );
    } catch (Exception $e) {
        sendToThread($token, $chatId, $threadId, '❌ ' . $e->getMessage());
    }
    return true;
}

/**
 * /help — всегда отправляется в ЛС к администратору, не в чат
 */
function cmdHelp(PDO $pdo, string $token, int $chatId, array $update): bool
{
    $adminId  = getBotAdminId();
    $sendToId = $adminId; // всегда в ЛС

    $text  = "📖 *Команды Kostlim Bot*\n\n";
    $text .= "🛡 *Модерация (время в минутах):*\n";
    $text .= "• `/mute @user 30` — замутить на 30 мин\n";
    $text .= "• `/mute @user` — навсегда\n";
    $text .= "• `/unmute @user` — снять мут\n";
    $text .= "• `/unmute 123456789` — снять мут по числовому ID\n";
    $text .= "• `/warn @user [причина]` — варн (3 → автомут 60 мин)\n";
    $text .= "• `/unwarn @user` — снять последний варн\n";
    $text .= "• `/ban @user [причина]` — бан\n";
    $text .= "• `/unban @user` — разбан\n";
    $text .= "• `/unban 123456789` — разбан по числовому ID\n";
    $text .= "• `/kick @user` — кик из группы\n\n";
    $text .= "⚙️ *Прочее:*\n";
    $text .= "• `/stats` — статистика\n";
    $text .= "• `/admin` — ссылка на панель\n";
    $text .= "• `/help` — эта справка (всегда в ЛС)\n\n";
    $text .= "🤖 *Авто-модерация:*\n";
    $text .= "• 10 сообщений за 10 сек → предупреждение\n";
    $text .= "• Продолжает → мут 10 мин\n";
    $text .= "• 3 одинаковых сообщения подряд → варн\n\n";
    $text .= "⚠️ _Бот должен быть администратором с правом «Restrict Members»._";

    sendTelegram($token, 'sendMessage', [
        'chat_id'    => $sendToId,
        'text'       => $text,
        'parse_mode' => 'Markdown',
    ]);

    // Если команда была написана не в ЛС — сообщить что справка отправлена в ЛС
    $replyChatId = getBotReplyChatId($update);
    if ($replyChatId !== $adminId) {
        $threadId = getMessageThreadId($update);
        sendToThread($token, $replyChatId, $threadId, "📖 Справка отправлена тебе в личные сообщения.");
    }

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
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // UNIQUE на blacklist.telegram для ON CONFLICT
        try { $pdo->exec("ALTER TABLE blacklist ADD CONSTRAINT uniq_blacklist_tg UNIQUE (telegram)"); } catch (Throwable $e) {}

        // Таблица для антиспама (счётчик сообщений)
        $pdo->exec("CREATE TABLE IF NOT EXISTS spam_log (
            id SERIAL PRIMARY KEY,
            user_id BIGINT NOT NULL,
            chat_id BIGINT NOT NULL,
            sent_at INT NOT NULL
        )");
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_spam_log_user_chat ON spam_log (user_id, chat_id)"); } catch (Throwable $e) {}

        // Таблица предупреждений о спаме (чтобы не спамить предупреждениями)
        $pdo->exec("CREATE TABLE IF NOT EXISTS spam_warnings (
            user_id BIGINT NOT NULL,
            chat_id BIGINT NOT NULL,
            warned_at INT NOT NULL,
            PRIMARY KEY (user_id, chat_id)
        )");

        // Таблица для антифлуда (последнее сообщение)
        $pdo->exec("CREATE TABLE IF NOT EXISTS flood_log (
            id SERIAL PRIMARY KEY,
            user_id BIGINT NOT NULL,
            chat_id BIGINT NOT NULL,
            message_text VARCHAR(255) NOT NULL,
            sent_at INT NOT NULL
        )");
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_flood_log_user_chat ON flood_log (user_id, chat_id)"); } catch (Throwable $e) {}

        // Кулдаун повторных сообщений (1 час между одинаковыми)
        $pdo->exec("CREATE TABLE IF NOT EXISTS flood_cooldown (
            user_id      BIGINT       NOT NULL,
            chat_id      BIGINT       NOT NULL,
            warned_at    INT          NOT NULL,
            message_text VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (user_id, chat_id)
        )");

        // Заполняем bot_commands если пусто
        $count = (int)$pdo->query("SELECT COUNT(*) FROM bot_commands")->fetchColumn();
        if ($count === 0) {
            $insert = $pdo->prepare("INSERT INTO bot_commands (command, description, access_level) VALUES (?, ?, 'admin') ON CONFLICT DO NOTHING");
            foreach ([
                ['mute',   '⛔ /mute @username [минуты] [причина]'],
                ['unmute', '✅ /unmute @username или ID'],
                ['warn',   '⚠️ /warn @username [причина]'],
                ['unwarn', '✅ /unwarn @username'],
                ['ban',    '🚫 /ban @username [причина]'],
                ['unban',  '✅ /unban @username или ID'],
                ['kick',   '👢 /kick @username'],
                ['invite', '🔗 /invite @username или ID'],
                ['admin',  '⚙️ /admin — панель'],
                ['stats',  '📊 /stats — статистика'],
                ['help',   '📖 /help — справка в ЛС'],
            ] as $cmd) {
                $insert->execute($cmd);
            }
        }
    } catch (Exception $e) {
        error_log('[BotCommands] migration error: ' . $e->getMessage());
    }
}