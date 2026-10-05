<?php
/**
 * «Полезности» — мини-форум закрытого раздела (Блок 5.1 ТЗ): статьи/гайды
 * от админа, дизайнеры читают и комментируют.
 */

function ensureUsefulSchema__run(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS useful_posts (
            id SERIAL PRIMARY KEY,
            author_tg_id VARCHAR(64) NOT NULL DEFAULT '',
            author_name VARCHAR(150) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            body_html TEXT NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS useful_comments (
            id SERIAL PRIMARY KEY,
            post_id INT NOT NULL REFERENCES useful_posts(id) ON DELETE CASCADE,
            tg_id VARCHAR(64) NOT NULL DEFAULT '',
            author_name VARCHAR(150) NOT NULL DEFAULT '',
            body TEXT NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");
    } catch (Throwable $e) {
        error_log('ensureUsefulSchema error: ' . $e->getMessage());
    }
}

/** KUI: схема проверяется один раз на контейнер (см. includes/schema_once.php) */
function ensureUsefulSchema(PDO $pdo): void
{
    if (!function_exists('kuiSchemaDone')) { require_once __DIR__ . '/schema_once.php'; }
    if (kuiSchemaDone('ensureUsefulSchema')) { return; }
    ensureUsefulSchema__run($pdo);
    kuiSchemaMark('ensureUsefulSchema');
}

function createUsefulPost(PDO $pdo, string $authorTgId, string $authorName, string $title, string $bodyHtml): int
{
    $stmt = $pdo->prepare("INSERT INTO useful_posts (author_tg_id, author_name, title, body_html) VALUES (?,?,?,?) RETURNING id");
    $stmt->execute([$authorTgId, $authorName, $title, $bodyHtml]);
    return (int)$stmt->fetchColumn();
}

function updateUsefulPost(PDO $pdo, int $id, string $title, string $bodyHtml): bool
{
    $st = $pdo->prepare("UPDATE useful_posts SET title = ?, body_html = ?, updated_at = NOW() WHERE id = ?");
    $st->execute([$title, $bodyHtml, $id]);
    return $st->rowCount() > 0;
}

function listUsefulPosts(PDO $pdo): array
{
    return $pdo->query("
        SELECT p.*, (SELECT COUNT(*) FROM useful_comments c WHERE c.post_id = p.id) AS comment_count
        FROM useful_posts p ORDER BY p.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getUsefulPost(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM useful_posts WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function deleteUsefulPost(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM useful_posts WHERE id = ?")->execute([$id]);
}

function addUsefulComment(PDO $pdo, int $postId, string $tgId, string $authorName, string $body): int
{
    $stmt = $pdo->prepare("INSERT INTO useful_comments (post_id, tg_id, author_name, body) VALUES (?,?,?,?) RETURNING id");
    $stmt->execute([$postId, $tgId, $authorName, $body]);
    return (int)$stmt->fetchColumn();
}

function listUsefulComments(PDO $pdo, int $postId): array
{
    $stmt = $pdo->prepare("SELECT * FROM useful_comments WHERE post_id = ? ORDER BY created_at ASC");
    $stmt->execute([$postId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
