<?php
/**
 * /admin/youtube_parser.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['admin'])) {
    http_response_code(403);
    echo '<div style="color:#f87171;padding:20px;font-family:monospace;">403 — нет доступа</div>';
    exit;
}

// БД — как в основной админке
require_once __DIR__ . '/../config/db.php';

// ── CSV-экспорт ────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->query('SELECT * FROM channels ORDER BY created_at DESC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="youtube_channels_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['ID','Название','Ссылка','Telegram','Email','Другие','Подписчики','Статус','Добавлен']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['channel_name'], $r['channel_url'],
            $r['contacts_tg'], $r['contacts_email'], $r['contacts_other'],
            $r['subscriber_count'], $r['status'], $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

// ── Удаление канала ────────────────────────────────────────────────────────
if (isset($_POST['delete_channel_id'])) {
    $stmt = $pdo->prepare('DELETE FROM channels WHERE id = :id');
    $stmt->execute([':id' => (int)$_POST['delete_channel_id']]);
    header('Location: youtube_parser.php');
    exit;
}

// ── Пагинация ──────────────────────────────────────────────────────────────
$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// Проверяем существование таблицы
$tableExists = false;
try {
    $pdo->query('SELECT 1 FROM channels LIMIT 1');
    $tableExists = true;
} catch (PDOException $e) {
    $tableExists = false;
}

$total    = 0;
$pages    = 0;
$channels = [];
$withTg   = 0;
$withEmail= 0;

if ($tableExists) {
    $total    = (int)$pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn();
    $pages    = (int)ceil($total / $perPage);
    $withTg   = (int)$pdo->query("SELECT COUNT(*) FROM channels WHERE contacts_tg IS NOT NULL")->fetchColumn();
    $withEmail= (int)$pdo->query("SELECT COUNT(*) FROM channels WHERE contacts_email IS NOT NULL")->fetchColumn();
    $stmt = $pdo->prepare('SELECT * FROM channels ORDER BY created_at DESC LIMIT :lim OFFSET :off');
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YouTube Парсер</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        body { background:#08080b; color:#fff; font-family:Montserrat,Arial,sans-serif; margin:0; }
        .yt-wrap { padding:24px; }
        .yt-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; }
        .yt-header h1 { font-size:20px; font-weight:800; margin:0; }

        .yt-form-card { background:#111116; border:1px solid #20202c; border-radius:12px; padding:20px; margin-bottom:20px; }
        .yt-form-grid { display:grid; grid-template-columns:1fr 160px 160px 180px; gap:12px; align-items:end; }
        .yt-form-grid label { display:block; font-size:11px; color:#888; margin-bottom:5px; font-weight:700; text-transform:uppercase; }
        .yt-form-grid input,
        .yt-form-grid select { width:100%; background:#171720; border:1px solid #2a2a38; border-radius:8px;
                               padding:10px 12px; color:#fff; font-size:13px; outline:none;
                               transition:border-color .2s; box-sizing:border-box; font-family:Montserrat,sans-serif; }
        .yt-form-grid input:focus,
        .yt-form-grid select:focus { border-color:#f97316; }

        .btn-search { background:linear-gradient(135deg,#fb923c,#f97316); color:#fff; border:none;
                      border-radius:8px; padding:10px 22px; font-size:13px; font-weight:800;
                      cursor:pointer; width:100%; font-family:Montserrat,sans-serif;
                      transition:opacity .2s; letter-spacing:.5px; text-transform:uppercase; }
        .btn-search:hover { opacity:.85; }
        .btn-search:disabled { opacity:.5; cursor:not-allowed; }

        .yt-alert { border-radius:8px; padding:12px 16px; margin-top:14px; font-size:13px; display:none; font-weight:700; }
        .yt-alert.success { background:rgba(34,197,94,.1); border:1px solid rgba(34,197,94,.3); color:#86efac; }
        .yt-alert.error   { background:rgba(239,68,68,.1);  border:1px solid rgba(239,68,68,.3);  color:#fca5a5; }
        .yt-alert.loading { background:rgba(96,165,250,.1); border:1px solid rgba(96,165,250,.3); color:#93c5fd; }

        .yt-stats { display:flex; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
        .yt-stat-card { background:#111116; border:1px solid #20202c; border-radius:10px;
                        padding:14px 20px; text-align:center; min-width:100px; }
        .yt-stat-card .num { font-size:22px; font-weight:800; color:#f97316; }
        .yt-stat-card .lbl { font-size:11px; color:#888; margin-top:2px; }

        .yt-actions { display:flex; gap:10px; margin-bottom:14px; align-items:center; }
        .btn-export { background:#1a3a1a; color:#86efac; border:1px solid rgba(34,197,94,.3);
                      border-radius:8px; padding:8px 16px; font-size:12px; font-weight:700;
                      cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px;
                      font-family:Montserrat,sans-serif; }
        .btn-export:hover { background:rgba(34,197,94,.15); }

        .no-table-warn { background:rgba(249,115,22,.1); border:1px solid rgba(249,115,22,.3);
                         border-radius:10px; padding:16px 20px; color:#fdba74; font-size:13px; font-weight:700; }

        table { width:100%; border-collapse:collapse; font-size:13px; }
        th { color:#888; font-size:11px; text-transform:uppercase; letter-spacing:.5px;
             padding:10px 12px; text-align:left; border-bottom:1px solid #20202c;
             background:#111116; white-space:nowrap; }
        td { padding:10px 12px; border-bottom:1px solid #20202c; color:#efeff7; vertical-align:middle; }
        tr:last-child td { border-bottom:0; }
        tr:hover td { background:rgba(249,115,22,.03); }
        .yt-table-wrap { overflow-x:auto; border:1px solid #20202c; border-radius:12px; }

        .ch-avatar { width:32px; height:32px; border-radius:50%; object-fit:cover; background:#20202c; display:block; flex-shrink:0; }
        .ch-name   { font-weight:700; color:#fff; text-decoration:none; }
        .ch-name:hover { color:#f97316; }
        .badge-tg    { background:rgba(78,174,213,.15); color:#4eaed5; border-radius:5px; padding:2px 8px; font-size:12px; }
        .badge-email { background:rgba(213,200,78,.12); color:#d5c84e; border-radius:5px; padding:2px 8px; font-size:12px; }
        .btn-del { background:rgba(239,68,68,.12); color:#fca5a5; border:1px solid rgba(239,68,68,.25);
                   border-radius:6px; padding:5px 10px; font-size:12px; cursor:pointer; font-family:Montserrat,sans-serif; }
        .btn-del:hover { background:rgba(239,68,68,.25); }

        .empty-state { text-align:center; padding:50px 20px; color:#555; }

        .yt-pagination { display:flex; gap:6px; justify-content:center; margin-top:16px; flex-wrap:wrap; }
        .yt-pagination a,
        .yt-pagination span { padding:6px 12px; border-radius:7px; text-decoration:none;
                               font-size:12px; border:1px solid #20202c; color:#ccc; font-weight:700; }
        .yt-pagination a:hover { background:#f97316; color:#fff; border-color:#f97316; }
        .yt-pagination span.cur { background:#f97316; color:#fff; border-color:#f97316; }

        @media(max-width:700px) { .yt-form-grid { grid-template-columns:1fr 1fr; } }
    </style>
</head>
<body>
<div class="yt-wrap">

    <div class="yt-header">
        <h1>📺 YouTube Парсер</h1>
    </div>

    <?php if (!$tableExists): ?>
    <div class="no-table-warn">
        ⚠️ Таблица <code>channels</code> не создана в БД.<br>
        Выполни SQL из файла <code>sql/20250615_add_channels_table.sql</code> в NeonDB.
    </div>
    <?php else: ?>

    <!-- Статистика -->
    <div class="yt-stats">
        <div class="yt-stat-card"><div class="num"><?= $total ?></div><div class="lbl">Каналов в базе</div></div>
        <div class="yt-stat-card"><div class="num"><?= $withTg ?></div><div class="lbl">С Telegram</div></div>
        <div class="yt-stat-card"><div class="num"><?= $withEmail ?></div><div class="lbl">С Email</div></div>
    </div>

    <!-- Форма поиска -->
    <div class="yt-form-card">
        <div class="yt-form-grid">
            <div>
                <label>Ключевое слово</label>
                <input type="text" id="yt-keyword" placeholder="Minecraft, дизайн, vlog...">
            </div>
            <div>
                <label>Регион</label>
                <select id="yt-region">
                    <option value="RU">🇷🇺 Россия</option>
                    <option value="UA">🇺🇦 Украина</option>
                    <option value="US">🇺🇸 США</option>
                    <option value="GB">🇬🇧 Великобритания</option>
                    <option value="DE">🇩🇪 Германия</option>
                    <option value="KZ">🇰🇿 Казахстан</option>
                    <option value="BY">🇧🇾 Беларусь</option>
                    <option value="PL">🇵🇱 Польша</option>
                </select>
            </div>
            <div>
                <label>Макс. видео</label>
                <select id="yt-max">
                    <option value="25">25</option>
                    <option value="50" selected>50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div>
                <label>&nbsp;</label>
                <button class="btn-search" id="btn-search" onclick="runSearch()">🔍 Найти клиентов</button>
            </div>
        </div>
        <div id="yt-alert" class="yt-alert"></div>
    </div>

    <!-- Действия -->
    <div class="yt-actions">
        <a href="?export=csv" class="btn-export">⬇️ Экспорт CSV</a>
        <span style="color:#555;font-size:12px;">Каналы с 500–20 000 подписчиков, опубликовавшие видео за последние 48ч</span>
    </div>

    <!-- Таблица -->
    <div class="yt-table-wrap">
        <?php if ($channels): ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Канал</th>
                    <th>Telegram</th>
                    <th>Email</th>
                    <th>Другие контакты</th>
                    <th>Подписчики</th>
                    <th>Добавлен</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($channels as $i => $ch): ?>
                <tr>
                    <td style="color:#555;font-size:12px;"><?= $offset + $i + 1 ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <?php if ($ch['preview_url']): ?>
                                <img class="ch-avatar" src="<?= htmlspecialchars($ch['preview_url']) ?>" alt="" onerror="this.style.display='none'">
                            <?php else: ?>
                                <div class="ch-avatar" style="display:flex;align-items:center;justify-content:center;font-size:14px;">📺</div>
                            <?php endif; ?>
                            <div>
                                <a class="ch-name" href="<?= htmlspecialchars($ch['channel_url']) ?>" target="_blank">
                                    <?= htmlspecialchars($ch['channel_name']) ?>
                                </a>
                                <?php if ($ch['video_url']): ?>
                                    <br><a href="<?= htmlspecialchars($ch['video_url']) ?>" target="_blank" style="font-size:11px;color:#666;">видео ↗</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td><?= $ch['contacts_tg'] ? '<span class="badge-tg">'.htmlspecialchars($ch['contacts_tg']).'</span>' : '<span style="color:#333">—</span>' ?></td>
                    <td><?= $ch['contacts_email'] ? '<span class="badge-email">'.htmlspecialchars($ch['contacts_email']).'</span>' : '<span style="color:#333">—</span>' ?></td>
                    <td style="color:#777;font-size:12px;"><?= htmlspecialchars($ch['contacts_other'] ?? '—') ?></td>
                    <td style="font-weight:700;color:#aaa;"><?= number_format((int)$ch['subscriber_count'], 0, '.', ' ') ?></td>
                    <td style="color:#555;font-size:12px;white-space:nowrap;"><?= date('d.m.Y H:i', strtotime($ch['created_at'])) ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Удалить канал из базы?')">
                            <input type="hidden" name="delete_channel_id" value="<?= $ch['id'] ?>">
                            <button class="btn-del" type="submit">✕</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
            <div style="font-size:48px;margin-bottom:12px;">📺</div>
            <div style="font-size:15px;color:#555;">Каналов пока нет</div>
            <div style="font-size:12px;color:#444;margin-top:6px;">Введи ключевое слово выше и нажми «Найти клиентов»</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Пагинация -->
    <?php if ($pages > 1): ?>
    <div class="yt-pagination">
        <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>">‹</a><?php endif; ?>
        <?php for ($p = max(1,$page-3); $p <= min($pages,$page+3); $p++): ?>
            <?php if ($p===$page): ?><span class="cur"><?= $p ?></span>
            <?php else: ?><a href="?page=<?= $p ?>"><?= $p ?></a><?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><a href="?page=<?= $page+1 ?>">›</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; // tableExists ?>

</div>
<script>
function showAlert(msg, type) {
    const el = document.getElementById('yt-alert');
    el.className = 'yt-alert ' + type;
    el.textContent = msg;
    el.style.display = 'block';
}

function runSearch() {
    const keyword = document.getElementById('yt-keyword').value.trim();
    const region  = document.getElementById('yt-region').value;
    const max     = document.getElementById('yt-max').value;
    const btn     = document.getElementById('btn-search');

    if (!keyword) { showAlert('Введи ключевое слово', 'error'); return; }

    btn.disabled = true;
    btn.textContent = '⏳ Ищем...';
    showAlert('Запрос к YouTube API... Это может занять 15–60 секунд.', 'loading');

    const fd = new FormData();
    fd.append('keyword', keyword);
    fd.append('region', region);
    fd.append('max_results', max);

    fetch('../includes/ajax/youtube_search.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showAlert('✅ ' + data.message, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showAlert('❌ ' + (data.error || 'Неизвестная ошибка'), 'error');
            }
        })
        .catch(err => showAlert('❌ Сетевая ошибка: ' + err.message, 'error'))
        .finally(() => { btn.disabled = false; btn.textContent = '🔍 Найти клиентов'; });
}

document.getElementById('yt-keyword')?.addEventListener('keydown', e => { if (e.key==='Enter') runSearch(); });
</script>
</body>
</html>