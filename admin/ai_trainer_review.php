<?php
/**
 * Админка: входящие результаты ИИ-тренажёра, которыми дизайнеры
 * поделились через кнопку «Поделиться с Kostlim». Админ может открыть
 * диалог целиком и оставить реакцию/комментарий.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['session_id'] ?? 0);
    $reaction = trim((string)($_POST['reaction'] ?? ''));
    $comment = trim((string)($_POST['comment'] ?? ''));
    if ($id > 0) {
        $pdo->prepare("UPDATE trainer_sessions SET admin_reaction = ?, admin_comment = ? WHERE id = ?")
            ->execute([$reaction, $comment, $id]);
    }
    header('Location: ai_trainer_review.php');
    exit;
}

$sessions = $pdo->query("
    SELECT ts.*, tl.tg_first_name, tl.tg_username
    FROM trainer_sessions ts
    LEFT JOIN tg_links tl ON tl.tg_id = ts.tg_id AND tl.linked = TRUE
    WHERE ts.shared_with_admin = TRUE
    ORDER BY ts.updated_at DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тренажёр — результаты | Админка</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../assets/admin-theme.css">
    
</head>
<body style="padding:24px;max-width:900px;margin:0 auto;">
    <h1>🎮 Результаты тренажёра клиентов</h1>
    <p><a href="index.php">← В админ-панель</a></p>

    <?php foreach ($sessions as $s): ?>
    <div class="service-card" style="margin-bottom:16px;padding:16px;">
        <h3 style="margin:0 0 6px;"><?= htmlspecialchars($s['tg_first_name'] ?: $s['tg_id']) ?> <?= $s['tg_username'] ? '(@' . htmlspecialchars($s['tg_username']) . ')' : '' ?></h3>
        <p style="color:var(--text2);margin:0 0 10px;">Клиент: <?= htmlspecialchars($s['client_name']) ?> · Тема: <?= htmlspecialchars($s['topic']) ?> · Сложность: <?= htmlspecialchars($s['difficulty']) ?></p>
        <p><strong>Оценка: <?= (int)$s['score'] ?>/100</strong></p>
        <p style="color:var(--text2);"><?= nl2br(htmlspecialchars($s['review'])) ?></p>
        <form method="post" style="display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap;">
            <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
            <select name="reaction" class="at-select" style="width:auto;min-width:150px;">
                <option value="">Без реакции</option>
                <option value="fire" <?= $s['admin_reaction']==='fire'?'selected':'' ?>>🔥</option>
                <option value="like" <?= $s['admin_reaction']==='like'?'selected':'' ?>>👍</option>
                <option value="think" <?= $s['admin_reaction']==='think'?'selected':'' ?>>🤔 нужно поработать</option>
            </select>
            <input type="text" name="comment" class="at-input" value="<?= htmlspecialchars($s['admin_comment']) ?>" placeholder="Комментарий дизайнеру" style="flex:1;min-width:180px;">
            <button type="submit" class="at-btn at-btn-primary">Сохранить</button>
        </form>
    </div>
    <?php endforeach; ?>
    <?php if (!$sessions): ?><p style="color:var(--text2);">Пока никто не поделился результатом.</p><?php endif; ?>
</body>
</html>
