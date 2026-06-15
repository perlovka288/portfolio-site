<?php
/**
 * /admin/youtube_parser.php
 * Модуль YouTube-парсера в админ-панели.
 */

session_start();

if (empty($_SESSION['admin'])) {
    header('Location: ../admin_index.php');
    exit;
}

// Конфиг + БД
$configFile = __DIR__ . '/../config/config.php';
if (file_exists($configFile)) require_once $configFile;

$dbFile = __DIR__ . '/../includes/db.php';
require_once $dbFile;

if (class_exists('Database')) {
    $db  = new Database();
    $pdo = $db->getConnection();
}

// ── CSV-экспорт ────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->query('SELECT * FROM channels ORDER BY created_at DESC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="youtube_channels_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM для Excel
    fputcsv($out, ['ID','Название','Ссылка','Telegram','Email','Другие','Подписчики','Статус','Добавлен']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['channel_name'], $r['channel_url'],
            $r['contacts_tg'], $r['contacts_email'], $r['contacts_other'],
            $r['subscriber_count'], $r['status'],
            $r['created_at'],
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

$totalStmt = $pdo->query('SELECT COUNT(*) FROM channels');
$total     = (int)$totalStmt->fetchColumn();
$pages     = (int)ceil($total / $perPage);

$stmt = $pdo->prepare('SELECT * FROM channels ORDER BY created_at DESC LIMIT :lim OFFSET :off');
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
$stmt->execute();
$channels = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YouTube Парсер — Kostlim Admin</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        /* ── YouTube Parser module styles ── */
        .yt-wrap          { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .yt-header        { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
        .yt-header h1     { font-size:22px; font-weight:700; margin:0; }

        .yt-form-card     { background:var(--card-bg, #1e1e2e); border:1px solid var(--border, #2a2a3e);
                            border-radius:12px; padding:24px; margin-bottom:28px; }
        .yt-form-grid     { display:grid; grid-template-columns:1fr 160px 160px 180px; gap:14px; align-items:end; }
        .yt-form-grid label   { display:block; font-size:12px; color:#999; margin-bottom:6px; }
        .yt-form-grid input,
        .yt-form-grid select  { width:100%; background:var(--input-bg,#13131f);
                                border:1px solid var(--border,#2a2a3e); border-radius:8px;
                                padding:10px 14px; color:#fff; font-size:14px;
                                outline:none; transition:border-color .2s; box-sizing:border-box; }
        .yt-form-grid input:focus,
        .yt-form-grid select:focus { border-color:#5865f2; }

        .btn-search       { background:#5865f2; color:#fff; border:none; border-radius:8px;
                            padding:10px 22px; font-size:14px; font-weight:600;
                            cursor:pointer; transition:background .2s; width:100%; }
        .btn-search:hover { background:#4752c4; }
        .btn-search:disabled { background:#333; cursor:not-allowed; }

        .yt-actions       { display:flex; gap:10px; margin-bottom:16px; }
        .btn-export       { background:#1a6b3a; color:#fff; border:none; border-radius:8px;
                            padding:9px 18px; font-size:13px; cursor:pointer; text-decoration:none;
                            display:inline-flex; align-items:center; gap:6px; }
        .btn-export:hover { background:#1d7f45; }

        /* ── Alert box ── */
        .yt-alert         { border-radius:8px; padding:12px 16px; margin-bottom:16px; font-size:14px;
                            display:none; }
        .yt-alert.success { background:#1a3a1a; border:1px solid #2d6e2d; color:#6ddb6d; }
        .yt-alert.error   { background:#3a1a1a; border:1px solid #6e2d2d; color:#db6d6d; }
        .yt-alert.loading { background:#1a2a3a; border:1px solid #2d4a6e; color:#6daedd; }

        /* ── Stats bar ── */
        .yt-stats         { display:flex; gap:16px; margin-bottom:20px; }
        .yt-stat-card     { background:var(--card-bg,#1e1e2e); border:1px solid var(--border,#2a2a3e);
                            border-radius:10px; padding:14px 20px; text-align:center; min-width:110px; }
        .yt-stat-card .num  { font-size:24px; font-weight:700; color:#5865f2; }
        .yt-stat-card .lbl  { font-size:11px; color:#888; margin-top:2px; }

        /* ── Table ── */
        .yt-table-wrap    { overflow-x:auto; }
        .yt-table         { width:100%; border-collapse:collapse; font-size:13px; }
        .yt-table th      { background:var(--card-bg,#1e1e2e); color:#888; font-weight:600;
                            padding:10px 14px; text-align:left; border-bottom:1px solid var(--border,#2a2a3e);
                            white-space:nowrap; }
        .yt-table td      { padding:10px 14px; border-bottom:1px solid var(--border,#2a2a3e); vertical-align:middle; }
        .yt-table tr:hover td { background:rgba(88,101,242,.05); }

        .ch-avatar        { width:34px; height:34px; border-radius:50%; object-fit:cover;
                            background:#333; display:block; }
        .ch-name          { font-weight:600; color:#fff; text-decoration:none; }
        .ch-name:hover    { color:#5865f2; }
        .badge-tg         { background:#1a3a50; color:#4eaed5; border-radius:5px;
                            padding:2px 8px; font-size:12px; display:inline-block; }
        .badge-email      { background:#2a2a1a; color:#d5c84e; border-radius:5px;
                            padding:2px 8px; font-size:12px; display:inline-block; }
        .subs-num         { font-weight:600; color:#aaa; }

        .btn-del          { background:#3a1a1a; color:#db6d6d; border:1px solid #6e2d2d;
                            border-radius:6px; padding:5px 11px; font-size:12px; cursor:pointer; }
        .btn-del:hover    { background:#4e1f1f; }

        /* ── Pagination ── */
        .yt-pagination    { display:flex; gap:6px; justify-content:center; margin-top:20px; flex-wrap:wrap; }
        .yt-pagination a,
        .yt-pagination span { padding:7px 14px; border-radius:7px; text-decoration:none;
                              font-size:13px; border:1px solid var(--border,#2a2a3e); color:#ccc; }
        .yt-pagination a:hover { background:#5865f2; color:#fff; border-color:#5865f2; }
        .yt-pagination span.cur { background:#5865f2; color:#fff; border-color:#5865f2; }

        .empty-state      { text-align:center; padding:50px 20px; color:#555; }
        .empty-state svg  { margin-bottom:14px; }

        @media(max-width:768px) {
            .yt-form-grid { grid-template-columns:1fr 1fr; }
            .yt-stats     { flex-wrap:wrap; }
        }
    </style>
</head>
<body>
<div class="yt-wrap">

    <!-- Шапка -->
    <div class="yt-header">
        <h1>📺 YouTube Парсер</h1>
        <a href="index.php" style="color:#888;text-decoration:none;font-size:13px;">← Назад в админку</a>
    </div>

    <!-- Стата -->
    <div class="yt-stats">
        <div class="yt-stat-card">
            <div class="num"><?= $total ?></div>
            <div class="lbl">Каналов в базе</div>
        </div>
        <?php
        $withTg    = $pdo->query("SELECT COUNT(*) FROM channels WHERE contacts_tg IS NOT NULL")->fetchColumn();
        $withEmail = $pdo->query("SELECT COUNT(*) FROM channels WHERE contacts_email IS NOT NULL")->fetchColumn();
        ?>
        <div class="yt-stat-card">
            <div class="num"><?= $withTg ?></div>
            <div class="lbl">С Telegram</div>
        </div>
        <div class="yt-stat-card">
            <div class="num"><?= $withEmail ?></div>
            <div class="lbl">С Email</div>
        </div>
    </div>

    <!-- Форма поиска -->
    <div class="yt-form-card">
        <div class="yt-form-grid">
            <div>
                <label>Ключевое слово</label>
                <input type="text" id="yt-keyword" placeholder="Minecraft, vlog, дизайн...">
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
    </div>

    <!-- Таблица -->
    <div class="yt-table-wrap">
        <?php if ($channels): ?>
        <table class="yt-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Канал</th>
                    <th>Telegram</th>
                    <th>Email</th>
                    <th>Другие</th>
                    <th>Подписчики</th>
                    <th>Добавлен</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($channels as $i => $ch): ?>
                <tr>
                    <td style="color:#555"><?= $offset + $i + 1 ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px">
                            <?php if ($ch['preview_url']): ?>
                                <img class="ch-avatar" src="<?= htmlspecialchars($ch['preview_url']) ?>" alt="">
                            <?php else: ?>
                                <div class="ch-avatar" style="background:#2a2a3e;display:flex;align-items:center;justify-content:center;font-size:14px">📺</div>
                            <?php endif; ?>
                            <div>
                                <a class="ch-name" href="<?= htmlspecialchars($ch['channel_url']) ?>" target="_blank">
                                    <?= htmlspecialchars($ch['channel_name']) ?>
                                </a>
                                <?php if ($ch['video_url']): ?>
                                    <br><a href="<?= htmlspecialchars($ch['video_url']) ?>" target="_blank" style="font-size:11px;color:#666">последнее видео ↗</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if ($ch['contacts_tg']): ?>
                            <span class="badge-tg"><?= htmlspecialchars($ch['contacts_tg']) ?></span>
                        <?php else: ?>
                            <span style="color:#333">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($ch['contacts_email']): ?>
                            <span class="badge-email"><?= htmlspecialchars($ch['contacts_email']) ?></span>
                        <?php else: ?>
                            <span style="color:#333">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:#777;font-size:12px"><?= htmlspecialchars($ch['contacts_other'] ?? '—') ?></td>
                    <td>
                        <span class="subs-num"><?= number_format($ch['subscriber_count'], 0, '.', ' ') ?></span>
                    </td>
                    <td style="color:#555;font-size:12px;white-space:nowrap">
                        <?= date('d.m.Y H:i', strtotime($ch['created_at'])) ?>
                    </td>
                    <td>
                        <form method="post" onsubmit="return confirm('Удалить канал?')">
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
            <div style="font-size:48px;margin-bottom:12px">📺</div>
            <div style="font-size:16px;color:#666">Каналов пока нет</div>
            <div style="font-size:13px;color:#444;margin-top:6px">Введите ключевое слово и нажмите «Найти клиентов»</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Пагинация -->
    <?php if ($pages > 1): ?>
    <div class="yt-pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>">‹ Пред.</a>
        <?php endif; ?>
        <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
            <?php if ($p === $page): ?>
                <span class="cur"><?= $p ?></span>
            <?php else: ?>
                <a href="?page=<?= $p ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $pages): ?>
            <a href="?page=<?= $page + 1 ?>">След. ›</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<script>
function showAlert(msg, type) {
    const el = document.getElementById('yt-alert');
    el.className = 'yt-alert ' + type;
    el.textContent = msg;
    el.style.display = 'block';
    el.style.marginTop = '16px';
}

function runSearch() {
    const keyword = document.getElementById('yt-keyword').value.trim();
    const region  = document.getElementById('yt-region').value;
    const max     = document.getElementById('yt-max').value;
    const btn     = document.getElementById('btn-search');

    if (!keyword) {
        showAlert('Введите ключевое слово', 'error');
        return;
    }

    btn.disabled = true;
    btn.textContent = '⏳ Ищем...';
    showAlert('Запрос к YouTube API... Это может занять 15–60 секунд.', 'loading');

    const body = new FormData();
    body.append('keyword', keyword);
    body.append('region', region);
    body.append('max_results', max);

    fetch('../includes/ajax/youtube_search.php', {
        method: 'POST',
        body: body,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showAlert('✅ ' + data.message, 'success');
            // Перезагрузить таблицу через 1.5 сек
            setTimeout(() => location.reload(), 1500);
        } else {
            showAlert('❌ Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
        }
    })
    .catch(err => {
        showAlert('❌ Сетевая ошибка: ' + err.message, 'error');
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = '🔍 Найти клиентов';
    });
}

// Enter в поле ключевого слова
document.getElementById('yt-keyword').addEventListener('keydown', e => {
    if (e.key === 'Enter') runSearch();
});
</script>
</body>
</html>
