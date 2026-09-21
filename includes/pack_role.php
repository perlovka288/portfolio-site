<?php
/**
 * Роль "Designer PPK" — синхронизация с приватной Telegram-группой пака.
 *
 * chat_id группы и токен бота НЕ хардкодятся — берутся из тех же настроек,
 * что уже редактируются во вкладке "🔑 Ключи и API" админки:
 *   - PRIVATE_CHAT_ID          — chat_id приватной группы (уже был в системе)
 *   - PRIVATE_CHAT_INVITE_LINK — пригласительная ссылка (новое поле, добавлено
 *                                этим обновлением рядом с PRIVATE_CHAT_ID)
 * Обе можно поменять в любой момент прямо в админке, без правки кода.
 *
 * Проверка участия — через Telegram getChatMember, с кэшем на TTL, чтобы не
 * дёргать Telegram API на каждую перезагрузку страницы каждым посетителем
 * (см. checkPackMembership). Кэш обновляется:
 *   - на сайте — при каждой загрузке index.php/profile.php авторизованным
 *     через Telegram пользователем (см. подключение ниже);
 *   - в боте — при каждом /start и при заходе в главное меню.
 */

function ensurePackRoleSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pack_membership_cache (
            tg_id VARCHAR(64) PRIMARY KEY,
            is_member BOOLEAN NOT NULL DEFAULT FALSE,
            checked_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");
    } catch (Throwable $e) {
        error_log('ensurePackRoleSchema error: ' . $e->getMessage());
    }
}

/**
 * Проверяет участие пользователя в приватной группе пака через getChatMember,
 * с кэшем на $ttlSeconds (по умолчанию 10 минут). Если Telegram недоступен —
 * отдаёт последнее известное значение из кэша, а не молча сбрасывает роль.
 */
function checkPackMembership(PDO $pdo, string $token, string $groupChatId, string $tgId, int $ttlSeconds = 600): bool
{
    if ($tgId === '' || $groupChatId === '' || $token === '') return false;
    ensurePackRoleSchema($pdo);

    $cached = null;
    try {
        $stmt = $pdo->prepare("SELECT is_member, checked_at FROM pack_membership_cache WHERE tg_id = ? LIMIT 1");
        $stmt->execute([$tgId]);
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cached && (time() - strtotime($cached['checked_at'])) < $ttlSeconds) {
            return (bool)$cached['is_member'];
        }
    } catch (Throwable $e) {}

    $isMember = false;
    try {
        $ch = curl_init("https://api.telegram.org/bot{$token}/getChatMember");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_POSTFIELDS     => ['chat_id' => $groupChatId, 'user_id' => $tgId],
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err !== '') throw new RuntimeException($err);

        $data   = json_decode((string)$res, true);
        $status = $data['result']['status'] ?? '';
        // "left"/"kicked" — не участник; member/administrator/creator/
        // restricted (но не покинувший) — считаем участником пака.
        $isMember = in_array($status, ['member', 'administrator', 'creator', 'restricted'], true);
    } catch (Throwable $e) {
        error_log('checkPackMembership error: ' . $e->getMessage());
        return $cached ? (bool)$cached['is_member'] : false;
    }

    try {
        $pdo->prepare("
            INSERT INTO pack_membership_cache (tg_id, is_member, checked_at) VALUES (?, ?, NOW())
            ON CONFLICT (tg_id) DO UPDATE SET is_member = EXCLUDED.is_member, checked_at = NOW()
        ")->execute([$tgId, $isMember ? 1 : 0]);
    } catch (Throwable $e) {}

    return $isMember;
}

/**
 * true, если человек — админ ИЛИ состоит в приватной группе пака
 * (роль "Designer PPK"). Этим правом открывается закрытый интерфейс
 * (раздел ресурсов / ИИ-тренажёр / личный планер — см. дальнейшие блоки ТЗ).
 */
function isPackDesigner(PDO $pdo, string $token, string $groupChatId, string $tgId, bool $isAdmin = false): bool
{
    if ($isAdmin) return true;
    return checkPackMembership($pdo, $token, $groupChatId, $tgId);
}
