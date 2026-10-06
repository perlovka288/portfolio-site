<?php
/**
 * «Полезности» — мини-форум закрытого раздела (Блок 5.1 ТЗ).
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/ppk_access.php';
require_once __DIR__ . '/includes/useful_lib.php';
require_once __DIR__ . '/includes/notifications_lib.php';
require_once __DIR__ . '/includes/notifications_bell.php';
require_once __DIR__ . '/includes/rich_editor.php';

ensureUsefulSchema($pdo);
ensureNotificationsSchema($pdo);

$access = resolvePpkAccess($pdo);
$isAdmin = $access['isAdmin'];
$isPackDesigner = $access['isPackDesigner'];
$tgProfile = $access['tgProfile'];
$myTgId = $access['tgId'];
$myName = $tgProfile['tg_first_name'] ?? ($isAdmin ? 'Kostlim' : 'Дизайнер');

if (!$isPackDesigner) {
    http_response_code(403);
    ?>
    <!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Доступ закрыт | Kostlim Design</title><link rel="stylesheet" href="style.css"><?php @include __DIR__ . '/includes/icons_head.php'; ?></head>
    <body style="display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center;padding:24px;">
        <div><h1>🔒 Доступ закрыт</h1><p>«Полезности» доступны только участникам Приват Пака.</p><p><a href="privat_pak.php">← В Приват Пак</a></p></div>
    </body></html>
    <?php exit;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_post' && $isAdmin) {
        $title = trim((string)($_POST['title'] ?? ''));
        $body  = trim((string)($_POST['body'] ?? ''));
        if ($title !== '' && $body !== '') {
            $postId = createUsefulPost($pdo, $myTgId, $myName, $title, $body);
            // Блок 5.2 ТЗ: уведомление о новой статье всем дизайнерам пака.
            $excerpt = mb_substr(trim(strip_tags($body)), 0, 120);
            broadcastNotification($pdo, 'useful_post', '📰 Новая статья: ' . $title, $excerpt, 'useful.php?id=' . $postId, $myTgId);
        }
        header('Location: useful.php?ok=1');
        exit;
    }
    if ($action === 'update_post' && $isAdmin) {
        $editId = (int)($_POST['id'] ?? 0);
        $title  = trim((string)($_POST['title'] ?? ''));
        $body   = trim((string)($_POST['body'] ?? ''));
        // пустой Quill отдаёт <p><br></p> — считаем это пустым текстом
        $bodyEmpty = trim(html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8')) === '' && stripos($body, '<img') === false;
        if ($editId > 0 && $title !== '' && !$bodyEmpty) {
            updateUsefulPost($pdo, $editId, $title, $body);
            header('Location: useful.php?id=' . $editId . '&saved=1');
        } else {
            header('Location: useful.php?id=' . $editId . '&edit=1&err=1');
        }
        exit;
    }
    if ($action === 'delete_post' && $isAdmin) {
        deleteUsefulPost($pdo, (int)($_POST['id'] ?? 0));
        header('Location: useful.php');
        exit;
    }
    if ($action === 'add_comment') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $body = trim((string)($_POST['comment'] ?? ''));
        if ($postId > 0 && $body !== '') {
            addUsefulComment($pdo, $postId, $myTgId, $myName, $body);
        }
        header('Location: useful.php?id=' . $postId);
        exit;
    }
}

$openId = (int)($_GET['id'] ?? 0);
$openPost = $openId > 0 ? getUsefulPost($pdo, $openId) : null;
$posts = listUsefulPosts($pdo);

require_once __DIR__ . '/includes/ppk_icons.php';

/** Мета для карточки статьи: обложка (первая картинка), время чтения, теги (#хэштеги из текста). */
function usefulCardMeta(array $p): array
{
    $html = (string)($p['body_html'] ?? '');
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    $words = preg_match_all('/\S+/u', $text);
    $minutes = max(1, (int)ceil($words / 180));
    $cover = '';
    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) $cover = $m[1];
    $tags = [];
    if (preg_match_all('/#([\p{L}\d_]{2,24})/u', $text, $mm)) $tags = array_slice(array_values(array_unique($mm[1])), 0, 2);
    if (!$tags) $tags = ['Гайд'];
    return ['cover' => $cover, 'minutes' => $minutes, 'tags' => $tags];
}

/** «2 дек. 2025» */
function usefulRuDate(string $ts): string
{
    static $m = [1 => 'янв.', 'фев.', 'мар.', 'апр.', 'мая', 'июн.', 'июл.', 'авг.', 'сен.', 'окт.', 'нояб.', 'дек.'];
    $t = strtotime($ts);
    return date('j', $t) . ' ' . $m[(int)date('n', $t)] . ' ' . date('Y', $t);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Полезности | Kostlim Design</title>
    <link rel="icon" type="image/png" href="/assets/img/logo-64.png" sizes="16x16">
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
    <link rel="stylesheet" href="assets/kostlim-upgrade.css?v=<?= @filemtime(__DIR__ . '/assets/kostlim-upgrade.css') ?: time() ?>">
    <?php if ($isAdmin) renderRichEditorAssets(); ?>
    <link rel="stylesheet" href="assets/rich-content.css?v=<?= @filemtime(__DIR__ . '/assets/rich-content.css') ?: time() ?>">
    <style>
        .useful-wrap { max-width: 900px; margin: 0 auto; padding: 22px 20px 50px; }
        .useful-top { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom: 18px; }
        .useful-back {
            display:inline-flex; align-items:center; gap:6px; background: var(--card); border:1px solid var(--border);
            color: var(--text); padding: 9px 14px; border-radius: 10px; font-size: 12.5px; font-weight: 700; text-decoration:none;
        }
        .useful-back:hover { border-color: var(--border-accent); color: var(--accent2); }
        .useful-title { font-size: 19px; font-weight: 900; margin: 0 0 4px; }
        .useful-sub { color: var(--text2); font-size: 13px; margin-bottom: 20px; }
        .useful-add-btn {
            display:flex; align-items:center; justify-content:center; gap:8px;
            background: linear-gradient(135deg, var(--accent2), var(--accent)); color:#fff; border:none;
            padding: 12px 20px; border-radius: 12px; font-size: 12.5px; font-weight: 800; text-transform: uppercase;
            letter-spacing: .6px; cursor:pointer; box-shadow: var(--shadow-accent); margin-bottom: 20px;
        }
        .useful-add-form { display:none; background: var(--card); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:22px; }
        .useful-add-form.show { display:block; }
        .useful-add-form input[type=text] {
            width:100%; box-sizing:border-box; background: rgba(0,0,0,.15); border:1px solid var(--border); color: var(--text);
            padding:10px 12px; border-radius:8px; font-family:inherit; margin-bottom:12px; font-size:14px; font-weight:700;
        }
        .useful-post-card {
            display:block; background: var(--card); border:1px solid var(--border); border-radius:14px;
            padding:18px; margin-bottom:14px; text-decoration:none; color:inherit; transition: border-color .2s;
        }
        .useful-post-card:hover { border-color: rgba(249,115,22,.35); }
        .useful-post-card h3 { margin:0 0 6px; font-size:15px; }
        .useful-post-meta { color: var(--text2); font-size:12px; display:flex; gap:10px; }
        .useful-post-excerpt { color: var(--text2); font-size:13px; margin-top:8px; line-height:1.5; }
        .useful-empty { text-align:center; color: var(--text2); font-size:13px; padding: 40px 0; }

        .useful-admin-tools { display:flex; justify-content:flex-end; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
        .useful-edit-btn {
            background: rgba(255,122,0,.12); border:1px solid rgba(255,122,0,.4); color:#ffb067; padding:7px 14px;
            border-radius:10px; font-size:12.5px; font-weight:800; cursor:pointer; font-family:inherit;
        }
        .useful-edit-btn:hover, .useful-edit-btn.on { background: rgba(255,122,0,.25); }
        .useful-flash { padding:10px 14px; border-radius:10px; font-size:13px; margin-bottom:12px; }
        .useful-flash.ok { background: rgba(74,222,128,.12); border:1px solid rgba(74,222,128,.35); color:#4ade80; }
        .useful-flash.err { background: rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.35); color:#f87171; }
        .useful-article { background: var(--card); border:1px solid var(--border); border-radius:16px; padding:26px; margin-bottom:24px; }
        .useful-article h1 { margin:0 0 8px; font-size:20px; }
        .useful-article .useful-post-meta { margin-bottom:18px; }
        .useful-article-body { line-height:1.7; color: var(--text2); }
        .useful-article-body img { max-width:100%; border-radius:10px; }
        .useful-article-body p { margin: 0 0 12px; }
        .useful-article-body.rich-content { line-height:1.42; color:#F4F4F4; }
        .useful-article-body.rich-content p { margin:0; }
        .useful-del-btn { background:none;border:none;color:#ef4444;font-size:12px;cursor:pointer;margin-left:auto; }

        .useful-comments-head { font-size:14px; font-weight:800; margin: 24px 0 12px; }
        .useful-comment { background: var(--card); border:1px solid var(--border); border-radius:12px; padding:12px 14px; margin-bottom:10px; }
        .useful-comment-meta { font-size:11.5px; color: var(--text2); margin-bottom:4px; font-weight:700; }
        .useful-comment-body { font-size:13px; color: var(--text); line-height:1.5; white-space:pre-wrap; }
        .useful-comment-form { display:flex; gap:8px; margin-top:14px; }
        .useful-comment-form textarea {
            flex:1; background: rgba(0,0,0,.15); border:1px solid var(--border); color: var(--text);
            padding:10px 12px; border-radius:8px; font-family:inherit; font-size:13px; min-height:44px; resize:vertical;
        }
    </style>
    <link rel="stylesheet" href="assets/ppk-redesign.css?v=<?= @filemtime(__DIR__ . '/assets/ppk-redesign.css') ?: time() ?>">
</head>
<body>

<div class="useful-wrap">
    <div class="useful-top">
        <a href="privat_pak.php" class="useful-back"><?= ppkIcon('arrow') ?> Приват Пак</a>
        <?php renderNotificationBell(); ?>
    </div>

    <?php if ($openPost): ?>
        <h1 class="useful-title">📚 Полезности</h1>
        <div class="useful-article">
            <?php if ($isAdmin): ?>
            <div class="useful-admin-tools">
                <button type="button" class="useful-edit-btn" id="usefulEditBtn" onclick="document.getElementById('editPostForm').classList.toggle('show');this.classList.toggle('on')">✏️ Редактировать</button>
                <form method="post" onsubmit="return confirm('Удалить статью?')" style="margin:0;">
                    <input type="hidden" name="action" value="delete_post">
                    <input type="hidden" name="id" value="<?= (int)$openPost['id'] ?>">
                    <button type="submit" class="useful-del-btn">🗑 Удалить</button>
                </form>
            </div>
            <?php if (!empty($_GET['saved'])): ?><div class="useful-flash ok">✅ Статья сохранена</div><?php endif; ?>
            <?php if (!empty($_GET['err'])): ?><div class="useful-flash err">Заголовок и текст не должны быть пустыми — изменения не сохранены.</div><?php endif; ?>

            <form class="useful-add-form <?= !empty($_GET['edit']) ? 'show' : '' ?>" id="editPostForm" method="post">
                <input type="hidden" name="action" value="update_post">
                <input type="hidden" name="id" value="<?= (int)$openPost['id'] ?>">
                <input type="text" name="title" value="<?= htmlspecialchars($openPost['title']) ?>" placeholder="Заголовок статьи" required>
                <?php renderRichEditor('body', (string)$openPost['body_html']); ?>
                <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap">
                    <button type="submit" class="save-all-btn">Сохранить изменения</button>
                    <button type="button" class="save-all-btn" style="background:#1e1e2a;box-shadow:none;border:1px solid #2a2a38"
                            onclick="document.getElementById('editPostForm').classList.remove('show');document.getElementById('usefulEditBtn').classList.remove('on')">Отмена</button>
                </div>
            </form>
            <?php endif; ?>
            <h1><?= htmlspecialchars($openPost['title']) ?></h1>
            <div class="useful-post-meta">
                <span>✍️ <?= htmlspecialchars($openPost['author_name']) ?></span>
                <span>🕐 <?= date('d.m.Y', strtotime($openPost['created_at'])) ?></span>
            </div>
            <div class="useful-article-body rich-content"><?= $openPost['body_html'] ?></div>
        </div>

        <div class="useful-comments-head">💬 Комментарии</div>
        <?php foreach (listUsefulComments($pdo, $openPost['id']) as $c): ?>
            <div class="useful-comment">
                <div class="useful-comment-meta"><?= htmlspecialchars($c['author_name']) ?> · <?= date('d.m.Y H:i', strtotime($c['created_at'])) ?></div>
                <div class="useful-comment-body"><?= htmlspecialchars($c['body']) ?></div>
            </div>
        <?php endforeach; ?>
        <?php if (!listUsefulComments($pdo, $openPost['id'])): ?><p style="color:var(--text2);font-size:13px;">Пока нет комментариев.</p><?php endif; ?>

        <form method="post" class="useful-comment-form">
            <input type="hidden" name="action" value="add_comment">
            <input type="hidden" name="post_id" value="<?= (int)$openPost['id'] ?>">
            <textarea name="comment" placeholder="Написать комментарий..." required></textarea>
            <button type="submit" class="save-all-btn" style="align-self:flex-end;">Отправить</button>
        </form>

    <?php else: ?>
        <h1 class="useful-title">📚 Полезности</h1>
        <p class="useful-sub">Статьи, гайды и фишки от Kostlim.</p>

        <?php if ($isAdmin): ?>
        <button type="button" class="useful-add-btn" onclick="document.getElementById('newPostForm').classList.toggle('show')">+ Написать статью</button>
        <form class="useful-add-form" id="newPostForm" method="post">
            <input type="hidden" name="action" value="create_post">
            <input type="text" name="title" placeholder="Заголовок статьи" required>
            <?php renderRichEditor('body'); ?>
            <button type="submit" class="save-all-btn" style="margin-top:12px;">Опубликовать</button>
        </form>
        <?php endif; ?>

        <?php if (!$posts): ?>
            <p class="useful-empty">Пока нет статей.</p>
        <?php else: ?>
        <div class="rd-articles">
        <?php foreach ($posts as $p): $m = usefulCardMeta($p); $au = (string)$p['author_name']; ?>
            <a href="useful.php?id=<?= (int)$p['id'] ?>" class="rd-post">
                <div class="rd-media">
                    <?php if ($m['cover'] !== ''): ?><img src="<?= htmlspecialchars($m['cover']) ?>" alt="<?= htmlspecialchars($p['title']) ?>" loading="lazy" onerror="this.remove()"><?php else: ?><span class="bg"></span><?php endif; ?>
                    <span class="rd-shade"></span>
                    <div class="rd-tags"><?php foreach ($m['tags'] as $t): ?><span class="hc hc--sm hc--secondary hc--accent"><?= htmlspecialchars($t) ?></span><?php endforeach; ?></div>
                    <div class="rd-hover"><span class="rd-cta"><?= ppkIcon('book') ?>Читать статью</span></div>
                </div>
                <div class="rd-body">
                    <div class="rd-body-t">
                        <h3><?= htmlspecialchars($p['title']) ?></h3>
                        <p><?= htmlspecialchars(mb_substr(trim(strip_tags($p['body_html'])), 0, 160)) ?></p>
                    </div>
                    <div class="rd-foot">
                        <div class="rd-who">
                            <span class="rd-ava"><?= htmlspecialchars(mb_strtoupper(mb_substr($au !== '' ? $au : 'K', 0, 1))) ?></span>
                            <span class="rd-who-t"><b><?= htmlspecialchars($au) ?></b><span><?= usefulRuDate($p['created_at']) ?></span></span>
                        </div>
                        <span class="rd-read"><?= ppkIcon('clock') ?><span><?= (int)$m['minutes'] ?> мин чтения</span></span>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
