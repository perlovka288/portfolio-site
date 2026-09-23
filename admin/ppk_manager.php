<?php
/**
 * Админка: ручная выдача роли PPK по Telegram ID + генератор одноразовых
 * ключей активации. Отдельная страница (не трогает admin/index.php),
 * подключается по ссылке из существующего меню админки — см. инструкцию
 * в PATCH_INSTRUCTIONS.md корня архива.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/auth.php'; // существующая проверка авторизации админа в проекте
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/badges.php';

ensurePpkManualSchema($pdo);

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $adminTgId = getenv('ADMIN_ID') ?: '1710365896';

    if ($action === 'grant') {
        $tgId = trim((string)($_POST['tg_id'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        if ($tgId !== '') {
            grantManualPpk($pdo, $tgId, $adminTgId, $note);
            $message = "✅ PPK выдан пользователю {$tgId}.";
        }
    } elseif ($action === 'revoke') {
        $tgId = trim((string)($_POST['tg_id'] ?? ''));
        if ($tgId !== '') {
            revokeManualPpk($pdo, $tgId);
            $message = "🗑 PPK снят у {$tgId}.";
        }
    } elseif ($action === 'generate_keys') {
        $count = max(1, min(20, (int)($_POST['count'] ?? 1)));
        $codes = generatePpkKeys($pdo, $count);
        $message = 'Сгенерировано ключей: ' . count($codes) . '<br><code style="white-space:pre-line;display:block;margin-top:8px;">' . implode("\n", $codes) . '</code>';
    }
}

$grants = $pdo->query("SELECT * FROM ppk_manual_grants ORDER BY granted_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$keys = $pdo->query("SELECT * FROM ppk_activation_keys ORDER BY created_at DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PPK — управление доступом | Админка</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../assets/admin-theme.css">
    
</head>
<body style="padding:24px;max-width:900px;margin:0 auto;">
    <h1>🎨 Управление ролью PPK</h1>
    <p><a href="index.php">← В админ-панель</a></p>
    <?php if ($message): ?><div class="admin-flash" style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px;margin:16px 0;"><?= $message /* уже экранировано выше */ ?></div><?php endif; ?>

    <section style="margin-top:24px;">
        <h2>Выдать PPK вручную</h2>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <input type="hidden" name="action" value="grant">
            <input type="text" name="tg_id" placeholder="Telegram ID пользователя" required style="padding:9px 11px;border-radius:8px;border:1px solid var(--border);background:rgba(0,0,0,.15);color:var(--text);">
            <input type="text" name="note" placeholder="Заметка (необязательно)" style="padding:9px 11px;border-radius:8px;border:1px solid var(--border);background:rgba(0,0,0,.15);color:var(--text);flex:1;min-width:180px;">
            <button type="submit" class="btn-submit">Выдать PPK</button>
        </form>
    </section>

    <section style="margin-top:32px;">
        <h2>Активные ручные выдачи</h2>
        <table style="width:100%;border-collapse:collapse;">
            <thead><tr style="text-align:left;color:var(--text2);"><th>TG ID</th><th>Заметка</th><th>Выдано</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($grants as $g): ?>
                <tr style="border-top:1px solid var(--border);">
                    <td style="padding:8px 0;"><?= htmlspecialchars($g['tg_id']) ?></td>
                    <td><?= htmlspecialchars($g['note']) ?></td>
                    <td><?= htmlspecialchars($g['granted_at']) ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Снять PPK?')">
                            <input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="tg_id" value="<?= htmlspecialchars($g['tg_id']) ?>">
                            <button type="submit" class="btn-submit" style="padding:6px 12px;background:rgba(239,68,68,.15);box-shadow:none;color:#ef4444;">✕</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$grants): ?><tr><td colspan="4" style="padding:16px 0;color:var(--text2);">Пока никому не выдавалось вручную.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <section style="margin-top:32px;">
        <h2>Одноразовые ключи активации</h2>
        <form method="post" style="display:flex;gap:8px;align-items:center;margin-bottom:16px;">
            <input type="hidden" name="action" value="generate_keys">
            <input type="number" name="count" value="1" min="1" max="20" style="width:70px;padding:9px 11px;border-radius:8px;border:1px solid var(--border);background:rgba(0,0,0,.15);color:var(--text);">
            <button type="submit" class="btn-submit">Сгенерировать</button>
        </form>
        <table style="width:100%;border-collapse:collapse;">
            <thead><tr style="text-align:left;color:var(--text2);"><th>Код</th><th>Статус</th><th>Кем погашен</th></tr></thead>
            <tbody>
            <?php foreach ($keys as $k): ?>
                <tr style="border-top:1px solid var(--border);">
                    <td style="padding:8px 0;font-family:monospace;"><?= htmlspecialchars($k['code']) ?></td>
                    <td><?= $k['is_used'] ? '✅ использован' : '🟢 свободен' ?></td>
                    <td><?= htmlspecialchars($k['used_by_tg_id'] ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</body>
</html>
