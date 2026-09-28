<?php
/**
 * Центр уведомлений закрытого раздела (Блок 5.2 ТЗ) — колокольчик 🔔 в
 * шапке. Три источника уведомлений: новые материалы (resources.php),
 * новые статьи в «Полезностях» (useful.php), ответ Kostlim на прохождение
 * тренажёра (admin/ai_trainer_review.php).
 */

function ensureNotificationsSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pack_notifications (
            id SERIAL PRIMARY KEY,
            tg_id VARCHAR(64) NOT NULL,
            type VARCHAR(30) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            body TEXT NOT NULL DEFAULT '',
            link VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            read_at TIMESTAMP
        )");
    } catch (Throwable $e) {
        error_log('ensureNotificationsSchema error: ' . $e->getMessage());
    }
}

function createNotification(PDO $pdo, string $tgId, string $type, string $title, string $body, string $link): void
{
    if ($tgId === '') return;
    $pdo->prepare("INSERT INTO pack_notifications (tg_id, type, title, body, link) VALUES (?,?,?,?,?)")
        ->execute([$tgId, $type, $title, $body, $link]);
}

/**
 * Список всех, кого хоть раз проверяли на членство в паке и кто сейчас
 * является участником (см. includes/pack_role.php::checkPackMembership) —
 * ближайший доступный "список дизайнеров" без отдельного опроса Telegram
 * API за полным составом группы.
 */
function getKnownPackDesignerTgIds(PDO $pdo, string $excludeTgId = ''): array
{
    $stmt = $pdo->prepare("SELECT tg_id FROM pack_membership_cache WHERE is_member = TRUE AND tg_id <> ?");
    $stmt->execute([$excludeTgId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/** Рассылает уведомление всем известным дизайнерам пака, кроме автора действия. */
function broadcastNotification(PDO $pdo, string $type, string $title, string $body, string $link, string $excludeTgId = ''): void
{
    foreach (getKnownPackDesignerTgIds($pdo, $excludeTgId) as $tgId) {
        createNotification($pdo, $tgId, $type, $title, $body, $link);
    }
}

function listNotifications(PDO $pdo, string $tgId, int $limit = 20): array
{
    $stmt = $pdo->prepare("SELECT * FROM pack_notifications WHERE tg_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->bindValue(1, $tgId);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getUnreadNotificationCount(PDO $pdo, string $tgId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pack_notifications WHERE tg_id = ? AND read_at IS NULL");
    $stmt->execute([$tgId]);
    return (int)$stmt->fetchColumn();
}

function markNotificationsRead(PDO $pdo, string $tgId): void
{
    $pdo->prepare("UPDATE pack_notifications SET read_at = NOW() WHERE tg_id = ? AND read_at IS NULL")->execute([$tgId]);
}
