<?php
session_start();
require_once 'config/db.php';

define('ADMIN_TG_ID', '1710365896');
if (!empty($_GET['tg_id']) && $_GET['tg_id'] === ADMIN_TG_ID) {
    $_SESSION['admin_logged'] = true;
}
$isAdmin = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;

// ── Inline-редактирование цен (только для админа) ─────────────
if ($isAdmin && isset($_POST['inline_edit_price'])) {
    header('Content-Type: application/json');
    $id      = (int)($_POST['id'] ?? 0);
    $field   = $_POST['field'] ?? '';
    $value   = trim($_POST['value'] ?? '');
    $allowed = ['title', 'price_rub', 'price_uan', 'description', 'features'];
    if ($id > 0 && in_array($field, $allowed, true)) {
        $pdo->prepare("UPDATE prices SET {$field} = ? WHERE id = ?")->execute([$value, $id]);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

// ── Генерация/обновление кода привязки для текущей сессии ──────
$linkCode = null;
$isLinked = false;
try {
    $sid  = session_id();
    $stmt = $pdo->prepare("SELECT site_code, linked FROM tg_links WHERE session_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$sid]);
    $linkRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($linkRow && $linkRow['linked']) {
        $isLinked = true;
    } elseif ($linkRow) {
        $linkCode = $linkRow['site_code'];
    } else {
        // Создаём новый код
        $code = strtoupper(substr(md5(uniqid($sid, true)), 0, 6));
        $pdo->prepare("INSERT INTO tg_links (site_code, session_id, linked, created_at) VALUES (?, ?, FALSE, NOW())")->execute([$code, $sid]);
        $linkCode = $code;
    }
} catch (Throwable $e) {
    // Таблица tg_links ещё не создана — показываем заглушку
    $linkCode = null;
}

function imgSrc(string $val, string $base = 'uploads/'): string {
    if ($val === '') return '';
    if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) return $val;
    return $base . $val;
}

$categories   = $pdo->query("SELECT * FROM portfolio_categories ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$categoryMap  = [];
foreach ($categories as $category) { $categoryMap[$category['category_key']] = $category; }
$works  = $pdo->query("SELECT * FROM portfolio ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$admin  = $pdo->query("SELECT avatar FROM users LIMIT 1")->fetch();
$avatar = (!empty($admin['avatar'])) ? $admin['avatar'] : '';

$settings     = $pdo->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$themePreset  = $settings['theme_preset']  ?? 'onyx';
$themeShape   = $settings['theme_shape']   ?? 'soft';
$themeDensity = $settings['theme_density'] ?? 'normal';
$themeEffects = $settings['theme_effects'] ?? 'glow';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kostlim Design | Портфолио</title>
<link rel="stylesheet" href="style.css">
<style>
body::before {
    content:'';position:fixed;top:-120px;left:50%;transform:translateX(-50%);
    width:700px;height:400px;background:radial-gradient(ellipse at center,rgba(249,115,22,0.13) 0%,transparent 70%);
    pointer-events:none;z-index:0;
}
body::after {
    content:'';position:fixed;bottom:-100px;right:-100px;width:500px;height:500px;
    background:radial-gradient(ellipse at center,rgba(249,115,22,0.07) 0%,transparent 65%);
    pointer-events:none;z-index:0;
}

/* ── Плашка привязки TG ── */
.tg-link-banner {
    display: flex;
    align-items: center;
    gap: 16px;
    background: linear-gradient(135deg, rgba(249,115,22,0.15), rgba(249,115,22,0.06));
    border: 1px solid rgba(249,115,22,0.45);
    border-radius: 14px;
    padding: 14px 18px;
    margin-bottom: 24px;
    position: relative;
    z-index: 1;
}
.tg-link-banner .tg-icon {
    width: 44px; height: 44px; flex-shrink: 0;
    background: rgba(249,115,22,0.2);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
}
.tg-link-banner .tg-text {
    flex: 1;
    font-size: 13px;
    color: #e8e8f0;
    line-height: 1.45;
}
.tg-link-banner .tg-text strong {
    display: block;
    color: #fff;
    font-size: 14px;
    margin-bottom: 2px;
}
.tg-link-banner .tg-code-row {
    display: flex; align-items: center; gap: 8px;
    margin-top: 8px;
}
.tg-link-banner .tg-code {
    font-family: monospace; font-size: 16px; font-weight: 900;
    color: #fb923c; letter-spacing: 3px;
    background: rgba(0,0,0,0.3); border-radius: 6px; padding: 4px 10px;
    border: 1px solid rgba(249,115,22,0.3);
    user-select: all;
}
.tg-link-banner .copy-btn {
    cursor: pointer;
    background: rgba(249,115,22,0.2); border: 1px solid rgba(249,115,22,0.4);
    border-radius: 6px; padding: 4px 10px; color: #fdba74; font-size: 12px; font-weight: 700;
    transition: .2s; white-space: nowrap;
}
.tg-link-banner .copy-btn:hover { background: rgba(249,115,22,0.4); color: #fff; }
.tg-link-banner .tg-bot-link {
    display: inline-flex; align-items: center; gap: 5px;
    margin-top: 8px;
    color: #fdba74; font-size: 12px; font-weight: 700; text-decoration: none;
    border: 1px solid rgba(249,115,22,0.35); border-radius: 6px; padding: 5px 10px;
    background: rgba(249,115,22,0.1); transition: .2s;
}
.tg-link-banner .tg-bot-link:hover { background: rgba(249,115,22,0.25); color: #fff; }
.tg-link-banner.linked {
    background: linear-gradient(135deg, rgba(34,197,94,0.12), rgba(34,197,94,0.05));
    border-color: rgba(34,197,94,0.4);
}
.tg-link-banner.linked .tg-text { color: #86efac; }
.tg-link-banner.linked strong { color: #fff; }

/* ── Портфолио ── */
.portfolio-stage { margin-top: 34px; padding-bottom: 70px; position:relative; z-index:1; }
.portfolio-grid { display:grid; grid-template-columns:repeat(3,minmax(260px,1fr)); gap:28px; align-items:start; }
.portfolio-card { background:transparent; display:flex; flex-direction:column; gap:12px; }
.portfolio-media {
    position:relative; width:100%; aspect-ratio:16/9; overflow:hidden;
    border-radius:22px; background:var(--card); border:1px solid var(--border);
    transition:border-color var(--t),box-shadow var(--t);
}
.portfolio-media::after {
    content:''; position:absolute; inset:0; z-index:2; background:transparent; cursor:default;
}
.portfolio-media img {
    width:100%; height:100%; object-fit:cover; display:block;
    transition:transform .5s cubic-bezier(.4,0,.2,1);
    pointer-events:none; user-select:none; -webkit-user-select:none; -webkit-user-drag:none; -moz-user-select:none;
}
.portfolio-card:hover .portfolio-media { border-color:var(--border-accent); box-shadow:0 0 32px rgba(249,115,22,0.22); }
.portfolio-card:hover .portfolio-media img { transform:scale(1.04); }
.order-pill { position:relative; z-index:3; }
.custom-ratio .portfolio-media { aspect-ratio:var(--card-ratio,16/9); }
.portfolio-meta { display:grid; grid-template-columns:1fr auto; gap:12px; align-items:center; padding:0 6px; }
.portfolio-title { color:var(--text); font-size:15px; font-weight:600; overflow-wrap:anywhere; line-height:1.35; }
.portfolio-price { color:var(--accent); font-size:15px; font-weight:800; white-space:nowrap; }
.order-pill {
    grid-column:1 / -1; text-decoration:none; text-align:center;
    border-radius:999px; padding:12px 26px; color:#fff; font-size:13px; font-weight:800;
    letter-spacing:1.5px; text-transform:uppercase;
    background:linear-gradient(135deg,#fb923c,#f97316);
    box-shadow:0 0 22px rgba(249,115,22,0.35),inset 0 1px rgba(255,255,255,.22);
    transition:all .22s cubic-bezier(.4,0,.2,1); border:none; cursor:pointer; display:block;
}
.order-pill:hover {
    transform:translateY(-2px);
    box-shadow:0 0 40px rgba(249,115,22,0.55),0 8px 24px rgba(249,115,22,0.25),inset 0 1px rgba(255,255,255,.22);
    background:linear-gradient(135deg,#fb923c,#ea580c);
}
.nav-icon-btn {
    width:42px; height:42px; display:inline-flex; align-items:center; justify-content:center;
    background:var(--card); border:1px solid var(--border); border-radius:12px; color:var(--accent);
    text-decoration:none; transition:all .22s; flex-shrink:0;
}
.nav-icon-btn svg { color:var(--accent); transition:color .22s; filter:drop-shadow(0 0 4px rgba(249,115,22,0.4)); }
.nav-icon-btn:hover { background:rgba(249,115,22,0.12); border-color:rgba(249,115,22,0.4); box-shadow:0 0 18px rgba(249,115,22,0.35); transform:translateY(-1px); }
.tg-glow-btn {
    width:42px; height:42px; display:inline-flex; align-items:center; justify-content:center;
    background:var(--card); border:1px solid var(--border); border-radius:12px; color:var(--accent);
    text-decoration:none; transition:all .22s; flex-shrink:0;
}
.tg-glow-btn svg { color:var(--accent); filter:drop-shadow(0 0 5px rgba(249,115,22,0.5)); }
.tg-glow-btn:hover { background:rgba(249,115,22,0.12); border-color:rgba(249,115,22,0.45); box-shadow:0 0 20px rgba(249,115,22,0.4); transform:translateY(-1px); }
.design-card { grid-column:1/-1; margin-top:28px; }
.design-card .portfolio-media { aspect-ratio:1590/400; min-height:220px; border-radius:22px; background:#08080b; border:1px solid rgba(255,255,255,.08); }
.design-card .portfolio-media>.design-banner { width:100%; height:100%; object-fit:cover; object-position:center; }
.design-avatar-frame {
    position:absolute; right:clamp(18px,3vw,38px); top:50%; transform:translateY(-50%);
    width:clamp(96px,12vw,156px); height:clamp(96px,12vw,156px);
    border-radius:50%; overflow:hidden; border:3px solid rgba(255,255,255,.9); background:#111116;
    box-shadow:0 14px 34px rgba(0,0,0,.45); z-index:3;
}
.design-avatar-frame .design-avatar { width:100%; height:100%; object-fit:cover; display:block; pointer-events:none; user-select:none; -webkit-user-drag:none; }
.design-card .portfolio-meta { grid-template-columns:1fr auto auto; padding:0 8px; }
.tabs-container { display:flex; justify-content:center; gap:10px; margin:36px 0 30px; flex-wrap:wrap; position:relative; z-index:1; }

@media(max-width:1100px){ .portfolio-grid{grid-template-columns:repeat(2,minmax(240px,1fr));} }
@media(max-width:720px){
    header{position:relative;padding:16px 18px;flex-direction:column;gap:14px;}
    .brand-title{position:static;transform:none;order:-1;}
    .header-right{flex-wrap:wrap;justify-content:center;}
    .portfolio-grid{grid-template-columns:1fr;gap:20px;}
    .portfolio-media{border-radius:16px;}
    .order-pill{font-size:11px;padding:10px 20px;}
    .design-card .portfolio-media{aspect-ratio:16/7;min-height:140px;}
    .design-avatar-frame{right:12px;width:70px;height:70px;border-width:2px;}
    .tg-link-banner{flex-direction:column;align-items:flex-start;}
}
</style>
</head>
<body class="theme-<?= htmlspecialchars($themePreset) ?> shape-<?= htmlspecialchars($themeShape) ?> density-<?= htmlspecialchars($themeDensity) ?> effects-<?= htmlspecialchars($themeEffects) ?>">

<header>
    <div class="header-left" style="display:flex;align-items:center;gap:10px;">
        <?php if ($avatar !== ''): ?>
            <img src="<?= htmlspecialchars(imgSrc($avatar)) ?>" class="avatar-mini" alt="Kostlim">
        <?php else: ?>
            <img src="https://i.imgur.com/w9NThbA.png" class="avatar-mini" alt="Kostlim">
        <?php endif; ?>

        <a href="https://t.me/designkostlim" target="_blank" class="tg-glow-btn" title="Telegram">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        </a>

        <?php if ($isAdmin): ?>
        <a href="admin/index.php" class="tg-glow-btn" title="Админ-панель">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        </a>
        <?php endif; ?>
    </div>

    <div class="brand-title"><h1>KOSTLIM</h1><span>DESIGN</span></div>

    <div class="header-right" style="display:flex;align-items:center;gap:10px;">
        <a href="price.php" class="nav-link nav-price"><span class="icon"></span>Прайс</a>
        <a href="https://t.me/kostlimdznbot" target="_blank" class="nav-link nav-bot"><span class="icon"></span>Бот для заказов</a>
    </div>
</header>

<main class="container portfolio-stage">

    <!-- ── Плашка привязки TG-бота ── -->
    <?php if ($isLinked): ?>
        <div class="tg-link-banner linked">
            <div class="tg-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#86efac" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <div class="tg-text">
                <strong>✅ Telegram привязан!</strong>
                Вы будете получать уведомления о заказах прямо в боте <a href="https://t.me/kostlimdznbot" target="_blank" style="color:#86efac;">@kostlimdznbot</a>.
            </div>
        </div>
    <?php elseif ($linkCode !== null): ?>
        <div class="tg-link-banner" id="tg-link-banner">
            <div class="tg-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fb923c" stroke-width="2.2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
            </div>
            <div class="tg-text">
                <strong>🔗 Подвяжите Telegram к сайту</strong>
                Чтобы получать уведомления о заказах — скопируйте код и отправьте его боту.
                <div class="tg-code-row">
                    <span class="tg-code" id="link-code">/customer_<?= htmlspecialchars($linkCode) ?></span>
                    <button class="copy-btn" onclick="copyCode()">Копировать</button>
                </div>
                <a href="https://t.me/kostlimdznbot?start=link_<?= htmlspecialchars($linkCode) ?>" target="_blank" class="tg-bot-link">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                    Открыть бот @kostlimdznbot
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── Категории-фильтры ── -->
    <div class="tabs-container">
        <button class="tab-btn active" onclick="filterPortfolio('all', event)">Все работы</button>
        <?php foreach ($categories as $category): ?>
            <button class="tab-btn" onclick="filterPortfolio('cat-<?= htmlspecialchars($category['category_key']) ?>', event)">
                <?= htmlspecialchars($category['title']) ?>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- ── Сетка работ ── -->
    <section class="portfolio-grid">
        <?php foreach ($works as $work): ?>
        <?php
            $img_file  = $work['image'] ?? '';
            $ava_file  = $work['avatar_image'] ?? '';
            $key       = strtolower($work['category_key'] ?? '');
            $category  = $categoryMap[$key] ?? null;
            $cat_class = 'cat-' . $key;
            $isDesign  = !empty($category['is_design']) || in_array($key, ['design','design_pack','banner_avatar'], true);
            $width     = (int)($category['width_px'] ?? 0);
            $height    = (int)($category['height_px'] ?? 0);
            $ratioStyle = (!$isDesign && $width > 0 && $height > 0) ? "--card-ratio:{$width}/{$height};" : '';
            $sizeText   = ($width > 0 && $height > 0) ? "{$width}x{$height}" : '';
        ?>
        <article class="portfolio-card filter-item <?= htmlspecialchars($cat_class) ?> <?= $isDesign ? 'design-card' : 'custom-ratio' ?>" style="<?= htmlspecialchars($ratioStyle) ?>">
            <div class="portfolio-media">
                <img src="<?= htmlspecialchars(imgSrc($img_file)) ?>"
                     class="<?= $isDesign ? 'design-banner' : '' ?>"
                     alt="<?= htmlspecialchars($work['title'] ?? 'Портфолио') ?>"
                     draggable="false"
                     onerror="this.style.opacity='0.3'">
                <?php if ($isDesign && $ava_file !== ''): ?>
                    <div class="design-avatar-frame">
                        <img src="<?= htmlspecialchars(imgSrc($ava_file)) ?>" class="design-avatar" alt="Аватарка" draggable="false">
                    </div>
                <?php endif; ?>
            </div>
            <div class="portfolio-meta">
                <div class="portfolio-title">
                    <?= htmlspecialchars($work['title'] ?? 'Без названия') ?>
                    <?php if ($sizeText !== ''): ?>
                        <span style="display:block;color:var(--text2);font-size:11px;margin-top:3px;"><?= htmlspecialchars($sizeText) ?> px</span>
                    <?php endif; ?>
                </div>
                <div class="portfolio-price"><?= (int)($work['price_rub'] ?? 0) ?>₽/<?= (int)($work['price_uan'] ?? 0) ?>грн</div>
                <a href="order.php?service=<?= htmlspecialchars($work['category_key'] ?? 'preview') ?>" class="order-pill">ЗАКАЗАТЬ</a>
            </div>
        </article>
        <?php endforeach; ?>
    </section>
</main>

<footer>
    <div class="container">© <?= date('Y') ?> Kostlim Design</div>
</footer>

<script>
function filterPortfolio(category, event) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    event.currentTarget.classList.add('active');
    document.querySelectorAll('.filter-item').forEach(item => {
        item.style.display = (category === 'all' || item.classList.contains(category)) ? 'flex' : 'none';
    });
}

function copyCode() {
    const el = document.getElementById('link-code');
    if (!el) return;
    const text = el.textContent.trim();
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.querySelector('.copy-btn');
        if (btn) { btn.textContent = '✅ Скопировано'; setTimeout(() => btn.textContent = 'Копировать', 2000); }
    }).catch(() => {
        // Fallback
        const tmp = document.createElement('textarea');
        tmp.value = text; document.body.appendChild(tmp);
        tmp.select(); document.execCommand('copy'); document.body.removeChild(tmp);
        const btn = document.querySelector('.copy-btn');
        if (btn) { btn.textContent = '✅ Скопировано'; setTimeout(() => btn.textContent = 'Копировать', 2000); }
    });
}

// Антивор
(function() {
    document.addEventListener('contextmenu', function(e) {
        if (e.target.closest('.portfolio-media') || e.target.classList.contains('design-avatar')) {
            e.preventDefault(); return false;
        }
    }, true);
    document.addEventListener('dragstart', function(e) {
        if (e.target.tagName === 'IMG' && e.target.closest('.portfolio-media')) {
            e.preventDefault(); return false;
        }
    }, true);
})();
</script>
</body>
</html>