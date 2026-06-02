<?php
session_start();
require_once 'config/db.php';

define('ADMIN_TG_ID', '1710365896');
if (!empty($_GET['tg_id']) && $_GET['tg_id'] === ADMIN_TG_ID) {
    $_SESSION['admin_logged'] = true;
}
$isAdmin = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;

// ── AJAX: проверить статус привязки (polling) ──────────────────
if (isset($_GET['check_linked'])) {
    header('Content-Type: application/json');
    $sid  = session_id();
    try {
        $stmt = $pdo->prepare("SELECT linked FROM tg_links WHERE session_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$sid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['linked' => !empty($row['linked'])]);
    } catch (Throwable $e) {
        echo json_encode(['linked' => false]);
    }
    exit;
}

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
        $code = strtoupper(substr(md5(uniqid($sid, true)), 0, 6));
        $pdo->prepare("INSERT INTO tg_links (site_code, session_id, linked, created_at) VALUES (?, ?, FALSE, NOW())")->execute([$code, $sid]);
        $linkCode = $code;
    }
} catch (Throwable $e) {
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

/* ══════════════════════════════════════════
   МОДАЛЬНОЕ ОКНО ПРИВЯЗКИ TG
══════════════════════════════════════════ */
.tg-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.75);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    z-index: 9000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.tg-modal-overlay.active {
    display: flex;
}
.tg-modal {
    background: #111116;
    border: 1px solid rgba(249,115,22,0.4);
    border-radius: 20px;
    padding: 32px 28px;
    max-width: 420px;
    width: 100%;
    position: relative;
    box-shadow: 0 0 60px rgba(249,115,22,0.2), 0 30px 80px rgba(0,0,0,0.6);
    animation: modalIn .25s cubic-bezier(.34,1.56,.64,1);
}
@keyframes modalIn {
    from { opacity:0; transform:scale(.92) translateY(12px); }
    to   { opacity:1; transform:scale(1)   translateY(0); }
}
.tg-modal-close {
    position: absolute;
    top: 14px; right: 16px;
    background: none; border: none;
    color: #555568; font-size: 22px;
    cursor: pointer; line-height: 1;
    transition: color .2s;
}
.tg-modal-close:hover { color: #fff; }

.tg-modal-icon {
    width: 56px; height: 56px;
    background: linear-gradient(135deg, rgba(249,115,22,0.25), rgba(249,115,22,0.1));
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 18px;
    border: 1px solid rgba(249,115,22,0.35);
}
.tg-modal h2 {
    text-align: center;
    color: #fff;
    font-size: 18px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin: 0 0 8px;
}
.tg-modal-sub {
    text-align: center;
    color: #8a8a96;
    font-size: 13px;
    line-height: 1.55;
    margin-bottom: 24px;
}

/* Шаги */
.tg-steps {
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin-bottom: 24px;
}
.tg-step {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}
.tg-step-num {
    width: 26px; height: 26px; flex-shrink: 0;
    background: rgba(249,115,22,0.18);
    border: 1px solid rgba(249,115,22,0.4);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 900; color: #fb923c;
}
.tg-step-text {
    color: #d0d0e0;
    font-size: 13px;
    line-height: 1.5;
    padding-top: 3px;
}
.tg-step-text b { color: #fff; }

/* Код */
.tg-code-box {
    background: rgba(0,0,0,0.35);
    border: 1px solid rgba(249,115,22,0.3);
    border-radius: 10px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
}
.tg-code-val {
    font-family: monospace;
    font-size: 18px;
    font-weight: 900;
    color: #fb923c;
    letter-spacing: 4px;
    user-select: all;
}
.tg-copy-btn {
    background: rgba(249,115,22,0.18);
    border: 1px solid rgba(249,115,22,0.4);
    border-radius: 7px;
    padding: 6px 14px;
    color: #fdba74;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
    transition: .2s;
    white-space: nowrap;
    font-family: inherit;
}
.tg-copy-btn:hover { background: rgba(249,115,22,0.35); color: #fff; }

/* Кнопки */
.tg-bot-open-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    background: linear-gradient(135deg, #fb923c, #f97316);
    color: #fff;
    text-decoration: none;
    padding: 13px;
    border-radius: 10px;
    font-weight: 900;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 1px;
    box-shadow: 0 0 20px rgba(249,115,22,0.4);
    transition: opacity .2s, transform .2s;
    margin-bottom: 10px;
}
.tg-bot-open-btn:hover { opacity: .9; transform: translateY(-1px); }

.tg-skip-btn {
    display: block;
    width: 100%;
    text-align: center;
    background: none;
    border: 1px solid #2a2a3a;
    border-radius: 10px;
    padding: 12px;
    color: #666678;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: border-color .2s, color .2s;
    font-family: inherit;
}
.tg-skip-btn:hover { border-color: #444456; color: #8a8a96; }

/* Статус ожидания */
.tg-waiting {
    display: none;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 12px;
    background: rgba(249,115,22,0.07);
    border: 1px solid rgba(249,115,22,0.2);
    border-radius: 9px;
    color: #fdba74;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 10px;
}
.tg-waiting.show { display: flex; }
.tg-spinner {
    width: 16px; height: 16px;
    border: 2px solid rgba(249,115,22,0.3);
    border-top-color: #f97316;
    border-radius: 50%;
    animation: spin .8s linear infinite;
    flex-shrink: 0;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Уже привязан — зелёная версия */
.tg-modal.linked-state {
    border-color: rgba(34,197,94,0.4);
    box-shadow: 0 0 60px rgba(34,197,94,0.15), 0 30px 80px rgba(0,0,0,0.6);
}
.tg-modal.linked-state .tg-modal-icon {
    background: linear-gradient(135deg, rgba(34,197,94,0.2), rgba(34,197,94,0.08));
    border-color: rgba(34,197,94,0.35);
}

/* ══ Портфолио ══ */
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
.design-card .portfolio-media { aspect-ratio:16/7; min-height:160px; }
.custom-ratio .portfolio-media { aspect-ratio: var(--card-ratio, 16/9); }
.design-banner { object-fit:cover; object-position:center top; }
.design-avatar-frame {
    position:absolute; bottom:10px; right:14px; width:80px; height:80px;
    border-radius:50%; border:2.5px solid var(--border-accent);
    overflow:hidden; background:var(--card); z-index:3;
    box-shadow:0 0 16px rgba(249,115,22,0.35);
}
.design-avatar { width:100%; height:100%; object-fit:cover; }
.portfolio-meta { padding:0 4px; }
.portfolio-title { color:var(--text); font-size:13px; font-weight:700; margin-bottom:6px; }
.portfolio-price { color:var(--text2); font-size:12px; margin-bottom:10px; }
.order-pill {
    display:inline-flex; align-items:center; justify-content:center;
    background:linear-gradient(135deg,var(--accent2),var(--accent));
    color:#fff; border:none; padding:9px 22px; border-radius:30px;
    font-size:12px; font-weight:900; letter-spacing:1.5px; text-transform:uppercase;
    text-decoration:none; cursor:pointer;
    box-shadow:0 0 16px rgba(249,115,22,0.3);
    transition:opacity .2s, transform .2s, box-shadow .2s;
}
.order-pill:hover { opacity:.9; transform:translateY(-2px); box-shadow:0 0 28px rgba(249,115,22,0.5); }
.tabs-container { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:28px; position:relative; z-index:1; }
.tab-btn {
    background:#111118; border:1px solid #1e1e2c; color:#8a8a96;
    padding:8px 18px; border-radius:30px; font-size:12px; font-weight:700;
    cursor:pointer; transition:.2s; font-family:inherit;
}
.tab-btn.active,.tab-btn:hover { background:rgba(249,115,22,0.12); border-color:rgba(249,115,22,0.4); color:#fb923c; }
@media(max-width:700px){
    .header-right{flex-wrap:wrap;justify-content:center;}
    .portfolio-grid{grid-template-columns:1fr;gap:20px;}
    .portfolio-media{border-radius:16px;}
    .order-pill{font-size:11px;padding:10px 20px;}
    .design-card .portfolio-media{aspect-ratio:16/7;min-height:140px;}
    .design-avatar-frame{right:12px;width:70px;height:70px;border-width:2px;}
    .tg-modal { padding: 24px 18px; }
    .tg-code-val { font-size: 15px; letter-spacing: 2px; }
}
</style>
</head>
<body class="theme-<?= htmlspecialchars($themePreset) ?> shape-<?= htmlspecialchars($themeShape) ?> density-<?= htmlspecialchars($themeDensity) ?> effects-<?= htmlspecialchars($themeEffects) ?>">

<!-- ══════════════════════════════════════════
     МОДАЛЬНОЕ ОКНО ПРИВЯЗКИ TG
══════════════════════════════════════════ -->
<div class="tg-modal-overlay" id="tgModalOverlay">
  <div class="tg-modal" id="tgModal">
    <button class="tg-modal-close" onclick="closeTgModal()" title="Закрыть">×</button>

    <?php if ($isLinked): ?>
    <!-- ── УЖЕ ПРИВЯЗАН ── -->
    <div class="tg-modal-icon">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#86efac" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    </div>
    <h2>Telegram привязан ✅</h2>
    <p class="tg-modal-sub">Твой аккаунт уже связан с ботом. Уведомления о заказе придут в <a href="https://t.me/kostlimdznbot" target="_blank" style="color:#86efac;">@kostlimdznbot</a>.</p>
    <a href="#" id="goOrderLinked" class="tg-bot-open-btn" style="background:linear-gradient(135deg,#4ade80,#22c55e);box-shadow:0 0 20px rgba(34,197,94,0.4);">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
      Перейти к заказу
    </a>
    <button class="tg-skip-btn" onclick="closeTgModal()">Закрыть</button>

    <?php else: ?>
    <!-- ── НЕ ПРИВЯЗАН ── -->
    <div class="tg-modal-icon">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fb923c" stroke-width="2.2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
    </div>
    <h2>Привяжи Telegram</h2>
    <p class="tg-modal-sub">Чтобы получать уведомления о статусе заказа прямо в бот — выполни 2 шага.</p>

    <div class="tg-steps">
      <div class="tg-step">
        <div class="tg-step-num">1</div>
        <div class="tg-step-text">Скопируй свой персональный код:</div>
      </div>
    </div>

    <?php if ($linkCode): ?>
    <div class="tg-code-box">
      <span class="tg-code-val" id="modalCode">/customer_<?= htmlspecialchars($linkCode) ?></span>
      <button class="tg-copy-btn" id="copyCodeBtn" onclick="copyModalCode()">Копировать</button>
    </div>
    <?php endif; ?>

    <div class="tg-steps">
      <div class="tg-step">
        <div class="tg-step-num">2</div>
        <div class="tg-step-text">Открой бота и отправь ему этот код — он автоматически всё привяжет.</div>
      </div>
    </div>

    <a href="https://t.me/kostlimdznbot?start=link_<?= htmlspecialchars($linkCode ?? '') ?>"
       target="_blank" class="tg-bot-open-btn" id="openBotBtn"
       onclick="startPolling()">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
      Открыть @kostlimdznbot
    </a>

    <div class="tg-waiting" id="tgWaiting">
      <div class="tg-spinner"></div>
      Ожидаю привязку… (введи код в бот)
    </div>

    <button class="tg-skip-btn" onclick="skipAndOrder()">Пропустить — перейти без уведомлений</button>
    <?php endif; ?>
  </div>
</div>

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
                <!-- Кнопка ЗАКАЗАТЬ — открывает модалку если не привязан, иначе сразу order.php -->
                <button class="order-pill"
                    onclick="handleOrder('<?= htmlspecialchars($work['category_key'] ?? 'preview') ?>')">
                    ЗАКАЗАТЬ
                </button>
            </div>
        </article>
        <?php endforeach; ?>
    </section>
</main>

<footer>
    <div class="container">© <?= date('Y') ?> Kostlim Design</div>
</footer>

<script>
// ── Статус привязки (из PHP) ──
var IS_LINKED = <?= $isLinked ? 'true' : 'false' ?>;
var pendingOrderService = '';
var pollInterval = null;

// ── Нажатие «ЗАКАЗАТЬ» ──
function handleOrder(serviceKey) {
    pendingOrderService = serviceKey;

    if (IS_LINKED) {
        // Сразу на страницу заказа
        window.location.href = 'order.php?service=' + encodeURIComponent(serviceKey) + '&accepted=1';
        return;
    }

    // Открываем модалку
    document.getElementById('tgModalOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';

    // Обновляем href «Перейти к заказу» (на случай если уже привязан)
    var goBtn = document.getElementById('goOrderLinked');
    if (goBtn) goBtn.href = 'order.php?service=' + encodeURIComponent(serviceKey) + '&accepted=1';
}

// ── Закрыть модалку ──
function closeTgModal() {
    document.getElementById('tgModalOverlay').classList.remove('active');
    document.body.style.overflow = '';
    stopPolling();
}

// ── Закрыть по клику на оверлей ──
document.getElementById('tgModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeTgModal();
});

// ── Пропустить и перейти к заказу ──
function skipAndOrder() {
    stopPolling();
    closeTgModal();
    if (pendingOrderService) {
        window.location.href = 'order.php?service=' + encodeURIComponent(pendingOrderService) + '&accepted=1';
    }
}

// ── Копировать код ──
function copyModalCode() {
    var el = document.getElementById('modalCode');
    if (!el) return;
    var text = el.textContent.trim();
    navigator.clipboard.writeText(text).then(function() {
        var btn = document.getElementById('copyCodeBtn');
        if (btn) { btn.textContent = '✅ Скопировано'; setTimeout(function(){ btn.textContent = 'Копировать'; }, 2000); }
    }).catch(function() {
        var tmp = document.createElement('textarea');
        tmp.value = text; document.body.appendChild(tmp);
        tmp.select(); document.execCommand('copy'); document.body.removeChild(tmp);
        var btn = document.getElementById('copyCodeBtn');
        if (btn) { btn.textContent = '✅ Скопировано'; setTimeout(function(){ btn.textContent = 'Копировать'; }, 2000); }
    });
}

// ── Polling: ждём привязку ──
function startPolling() {
    document.getElementById('tgWaiting').classList.add('show');
    if (pollInterval) clearInterval(pollInterval);
    pollInterval = setInterval(checkLinked, 3000);
}

function stopPolling() {
    if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
    var w = document.getElementById('tgWaiting');
    if (w) w.classList.remove('show');
}

function checkLinked() {
    fetch('index.php?check_linked=1')
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.linked) {
                stopPolling();
                IS_LINKED = true;
                // Показываем «Привязан!» и даём кнопку перейти к заказу
                var modal = document.getElementById('tgModal');
                var orderUrl = pendingOrderService
                    ? 'order.php?service=' + encodeURIComponent(pendingOrderService) + '&accepted=1'
                    : 'order.php?accepted=1';
                modal.innerHTML =
                    '<div class="tg-modal-icon" style="background:linear-gradient(135deg,rgba(34,197,94,0.2),rgba(34,197,94,0.08));border-color:rgba(34,197,94,0.35);">' +
                    '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#86efac" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>' +
                    '<h2>✅ Привязано!</h2>' +
                    '<p class="tg-modal-sub">Telegram успешно связан. Теперь переходим к заказу!</p>' +
                    '<a href="' + orderUrl + '" class="tg-bot-open-btn" style="background:linear-gradient(135deg,#4ade80,#22c55e);box-shadow:0 0 20px rgba(34,197,94,0.4);">' +
                    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg> Перейти к заказу</a>' +
                    '<button class="tg-skip-btn" onclick="closeTgModal()">Закрыть</button>';
                // Автоматически переходим через 1.5 сек
                setTimeout(function(){ window.location.href = orderUrl; }, 1500);
            }
        })
        .catch(function(){});
}

// ── Фильтрация портфолио ──
function filterPortfolio(category, event) {
    document.querySelectorAll('.tab-btn').forEach(function(btn){ btn.classList.remove('active'); });
    event.currentTarget.classList.add('active');
    document.querySelectorAll('.filter-item').forEach(function(item) {
        item.style.display = (category === 'all' || item.classList.contains(category)) ? 'flex' : 'none';
    });
}

// ── Антивор ──
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