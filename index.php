<?php
session_start();
require_once 'config/db.php';
require_once 'donationalerts.php';

define('ADMIN_TG_ID', '1710365896');
if (!empty($_GET['tg_id']) && $_GET['tg_id'] === ADMIN_TG_ID) {
    $_SESSION['admin_logged'] = true;
}
$isAdmin = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;

// Если пользователь привязал TG и его tg_id = ADMIN_ID — тоже admin
$adminTgId = getenv('ADMIN_ID') ?: '1710365896';
if (!$isAdmin && !empty($tgProfile['tg_id'] ?? '') && (string)$tgProfile['tg_id'] === $adminTgId) {
    $isAdmin = true;
}
// Примечание: $tgProfile заполняется ниже в блоке работы с tg_links,
// поэтому повторно проверяем после его заполнения (см. ниже)

// ── AJAX: проверить статус привязки (polling) ──────────────────
if (isset($_GET['check_linked'])) {
    header('Content-Type: application/json');
    $sid  = session_id();
    try {
        $stmt = $pdo->prepare("SELECT linked, tg_username, tg_first_name, tg_photo_url FROM tg_links WHERE session_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$sid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($row['linked']) && $row['linked'] !== 'f' && $row['linked'] !== false) {
            echo json_encode([
                'linked'     => true,
                'username'   => $row['tg_username'] ?? '',
                'first_name' => $row['tg_first_name'] ?? '',
                'photo_url'  => $row['tg_photo_url'] ?? '',
            ]);
        } else {
            echo json_encode(['linked' => false]);
        }
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
$tgProfile = [];
try {
    $sid  = session_id();
    $stmt = $pdo->prepare("SELECT site_code, linked, tg_id, tg_username, tg_first_name, tg_photo_url FROM tg_links WHERE session_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$sid]);
    $linkRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($linkRow && $linkRow['linked'] && $linkRow['linked'] !== 'f') {
        $isLinked  = true;
        $tgProfile = [
            'tg_id'      => $linkRow['tg_id'] ?? '',
            'username'   => $linkRow['tg_username'] ?? '',
            'first_name' => $linkRow['tg_first_name'] ?? '',
            'photo_url'  => $linkRow['tg_photo_url'] ?? '',
        ];
        // Проверяем — вдруг это админ по TG ID
        if (!$isAdmin && !empty($tgProfile['tg_id']) && (string)$tgProfile['tg_id'] === $adminTgId) {
            $isAdmin = true;
        }
    } elseif ($linkRow) {
        $linkCode = $linkRow['site_code'];
    } else {
        // Генерируем уникальный код, повторяя при коллизии
        $attempts = 0;
        do {
            $code = strtoupper(substr(md5(uniqid($sid . $attempts, true)), 0, 6));
            $attempts++;
            try {
                $pdo->prepare("INSERT INTO tg_links (site_code, session_id, linked, created_at) VALUES (?, ?, FALSE, NOW())")->execute([$code, $sid]);
                $linkCode = $code;
                break;
            } catch (Throwable $ins_e) {
                // UNIQUE conflict — попробуем ещё раз
            }
        } while ($attempts < 5);
    }
} catch (Throwable $e) {
    // Если таблица не существует — генерируем код на клиентской стороне из session_id
    $linkCode = strtoupper(substr(md5(session_id()), 0, 6));
}

if (isset($_GET['code']) && trim((string)$_GET['code']) !== '') {
    if (daExchangeAuthorizationCode($pdo, trim((string)$_GET['code']))) {
        $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?');
        header('Location: ' . ($redirectUrl ?: '/'));
        exit;
    }
}

$daAccessToken = daEnsureAccessToken($pdo);
$daConnected = $daAccessToken !== null;
$daDonationTotalUsd = 0.0;
$daPayoutStats = ['gross' => 0.0, 'count' => 0, 'commission' => 0.0, 'net' => 0.0];

if ($isAdmin && $daConnected) {
    $daDonations = daGetDonations($pdo, 200);
    $daDonationTotalUsd = daGetCurrentMonthDonationTotalUsd($daDonations);
    $daPayoutStats = daGetCurrentMonthPayoutStats($pdo);
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
/* ══ TG-профиль в хедере ══ */
.tg-user-chip {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 5px 12px 5px 5px;
    background: rgba(34,197,94,0.1);
    border: 1px solid rgba(34,197,94,0.3);
    border-radius: 30px;
    text-decoration: none;
    color: #86efac;
    font-size: 12px;
    font-weight: 700;
    transition: background .2s, border-color .2s;
    max-width: 160px;
}
.tg-user-chip:hover {
    background: rgba(34,197,94,0.18);
    border-color: rgba(34,197,94,0.5);
}
.tg-user-ava {
    width: 26px; height: 26px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
    border: 1.5px solid rgba(34,197,94,0.4);
}
.tg-user-ava-fallback {
    width: 26px; height: 26px;
    border-radius: 50%;
    background: rgba(34,197,94,0.2);
    border: 1.5px solid rgba(34,197,94,0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 900;
    color: #86efac;
    flex-shrink: 0;
    text-transform: uppercase;
}
.tg-user-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.tg-admin-tag {
    font-size: 9px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #fb923c;
    background: rgba(249,115,22,0.15);
    border: 1px solid rgba(249,115,22,0.35);
    border-radius: 4px;
    padding: 1px 5px;
    flex-shrink: 0;
    line-height: 14px;
}
.tg-link-trigger-btn {
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.da-stats-panel {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin: 22px 0 14px;
}
.da-connect-panel,
.da-stat-card {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 18px;
    padding: 18px 20px;
    min-width: 220px;
    flex: 1 1 220px;
}
.da-connect-panel h2,
.da-stat-card span {
    display: block;
    color: #f8f8f2;
    font-size: 11px;
    letter-spacing: .8px;
    text-transform: uppercase;
    margin-bottom: 10px;
}
.da-connect-panel p {
    color: #c7c7d8;
    margin: 0 0 14px;
    font-size: 13px;
    line-height: 1.55;
}
.da-connect-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    padding: 12px 16px;
    background: #f97316;
    color: #111827;
    border-radius: 12px;
    font-weight: 800;
    text-decoration: none;
}
.da-stat-card strong {
    display: block;
    margin-top: 8px;
    font-size: 22px;
    color: #fff;
}
@media(max-width:700px){
    .tg-user-chip { max-width: 120px; font-size: 11px; }
    .tg-user-name { max-width: 70px; }
}

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
<?php if (!$isLinked): ?>
<!-- ══════════════════════════════════════════
     МОДАЛЬНОЕ ОКНО ПРИВЯЗКИ TG (только когда не привязан)
══════════════════════════════════════════ -->
<div class="tg-modal-overlay" id="tgModalOverlay">
  <div class="tg-modal" id="tgModal">
    <button class="tg-modal-close" onclick="closeTgModal()" title="Закрыть">×</button>

    <!-- ── НЕ ПРИВЯЗАН ── -->
    <div class="tg-modal-icon">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fb923c" stroke-width="2.2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
    </div>
    <h2>Привяжи Telegram</h2>
    <p class="tg-modal-sub">Чтобы получать уведомления о заказах прямо в бот — выполни 2 шага.</p>

    <div class="tg-steps">
      <div class="tg-step">
        <div class="tg-step-num">1</div>
        <div class="tg-step-text">Скопируй свой персональный код:</div>
      </div>
    </div>

    <div class="tg-code-box">
      <?php if ($linkCode): ?>
      <span class="tg-code-val" id="modalCode">/customer_<?= htmlspecialchars($linkCode) ?></span>
      <button class="tg-copy-btn" id="copyCodeBtn" onclick="copyModalCode()">Копировать</button>
      <?php else: ?>
      <span class="tg-code-val" style="font-size:12px;color:#8a8a96;">Обнови страницу — код генерируется</span>
      <?php endif; ?>
    </div>

    <div class="tg-steps">
      <div class="tg-step">
        <div class="tg-step-num">2</div>
        <div class="tg-step-text">Открой бота и отправь ему этот код — он автоматически всё привяжет.<br><span style="color:#6b6b7a;font-size:11px;">Или просто нажми кнопку ниже — код отправится автоматически через deep link.</span></div>
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
      Ожидаю привязку…
    </div>

    <button class="tg-skip-btn" onclick="closeTgModal()">Закрыть</button>
  </div>
</div>
<?php endif; ?>

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

        <?php if ($isLinked && !empty($tgProfile)): ?>
        <!-- ── TG ПРОФИЛЬ (привязан) ── -->
        <a href="profile.php" class="tg-user-chip" title="Личный профиль">
            <?php if (!empty($tgProfile['photo_url'])): ?>
                <img src="<?= htmlspecialchars($tgProfile['photo_url']) ?>" class="tg-user-ava" alt="аватар" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <span class="tg-user-ava-fallback" style="display:none;">
                    <?= mb_substr($tgProfile['first_name'] ?: ($tgProfile['username'] ?: '?'), 0, 1) ?>
                </span>
            <?php else: ?>
                <span class="tg-user-ava-fallback">
                    <?= mb_substr($tgProfile['first_name'] ?: ($tgProfile['username'] ?: '?'), 0, 1) ?>
                </span>
            <?php endif; ?>
            <span class="tg-user-name">
                <?= htmlspecialchars($tgProfile['first_name'] ?: ('@' . $tgProfile['username'])) ?>
            </span>
            <?php if ($isAdmin): ?>
                <span class="tg-admin-tag">admin</span>
            <?php endif; ?>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="opacity:.5"><path d="M9 18l6-6-6-6"/></svg>
        </a>
        <?php else: ?>
        <!-- ── КНОПКА ПРИВЯЗКИ ── -->
        <button class="nav-link nav-bot tg-link-trigger-btn" onclick="openTgModal()" title="Привязать Telegram">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="flex-shrink:0"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
            Привязать TG
        </button>
        <?php endif; ?>
    </div>
</header>

<?php if ($isAdmin): ?>
    <section class="da-stats-panel">
        <?php if (!$daConnected): ?>
            <div class="da-connect-panel">
                <h2>DonationAlerts</h2>
                <p>Чтобы увидеть статистику донатов и автоматическую оплату заказов, подключите DonationAlerts.</p>
                <a class="da-connect-btn" href="<?= htmlspecialchars(daGetAuthorizeUrl()) ?>">Подключить DonationAlerts</a>
            </div>
        <?php else: ?>
            <div class="da-stats-grid">
                <div class="da-stat-card">
                    <span>Донатов за месяц</span>
                    <strong>$<?= number_format($daDonationTotalUsd, 2, '.', '') ?></strong>
                </div>
                <div class="da-stat-card">
                    <span>На вывод за месяц</span>
                    <strong>$<?= number_format($daPayoutStats['gross'], 2, '.', '') ?></strong>
                </div>
                <div class="da-stat-card">
                    <span>Комиссия вывода</span>
                    <strong>$<?= number_format($daPayoutStats['commission'], 2, '.', '') ?></strong>
                </div>
                <div class="da-stat-card">
                    <span>Чистыми</span>
                    <strong>$<?= number_format($daPayoutStats['net'], 2, '.', '') ?></strong>
                </div>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

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

<?php if (!$isLinked): ?>
<!-- ══ ВСПЛЫВАЮЩАЯ ПЛАШКА ПРИВЯЗКИ TG ══ -->
<div class="tg-float-banner" id="tgFloatBanner">
    <button class="tg-float-close" id="tgFloatClose" title="Закрыть">×</button>
    <div class="tg-float-inner">
        <div class="tg-float-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fb923c" stroke-width="2.2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        </div>
        <div class="tg-float-text">
            <div class="tg-float-title">Привяжи Telegram</div>
            <div class="tg-float-sub">Получай уведомления о заказах и&nbsp;личный профиль с&nbsp;историей</div>
        </div>
    </div>
    <div class="tg-float-perks">
        <div class="tg-float-perk">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
            Статусы заказов в боте
        </div>
        <div class="tg-float-perk">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
            Личный профиль на сайте
        </div>
        <div class="tg-float-perk">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
            История всех заказов
        </div>
    </div>
    <button class="tg-float-btn" onclick="openTgModal(); document.getElementById('tgFloatBanner').classList.remove('show');">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        Привязать Telegram
    </button>
</div>

<style>
.tg-float-banner {
    position: fixed;
    bottom: 28px;
    right: 24px;
    width: 270px;
    background: #0f0f14;
    border: 1px solid rgba(249,115,22,0.35);
    border-radius: 18px;
    padding: 18px 16px 16px;
    box-shadow: 0 0 40px rgba(249,115,22,0.15), 0 20px 60px rgba(0,0,0,0.7);
    z-index: 8000;
    transform: translateY(20px) scale(0.96);
    opacity: 0;
    pointer-events: none;
    transition: transform .35s cubic-bezier(.34,1.56,.64,1), opacity .3s ease;
}
.tg-float-banner.show {
    transform: translateY(0) scale(1);
    opacity: 1;
    pointer-events: auto;
}
/* Оранжевое свечение сверху */
.tg-float-banner::before {
    content: '';
    position: absolute;
    top: -1px; left: 20px; right: 20px; height: 2px;
    background: linear-gradient(90deg, transparent, rgba(249,115,22,0.7), transparent);
    border-radius: 2px;
}

.tg-float-close {
    position: absolute;
    top: 10px; right: 12px;
    background: none; border: none;
    color: #44445a; font-size: 18px;
    cursor: pointer; line-height: 1;
    transition: color .2s;
    padding: 2px 4px;
}
.tg-float-close:hover { color: #888; }

.tg-float-inner {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
    padding-right: 18px;
}
.tg-float-icon {
    width: 42px; height: 42px; flex-shrink: 0;
    background: linear-gradient(135deg, rgba(249,115,22,0.2), rgba(249,115,22,0.06));
    border: 1px solid rgba(249,115,22,0.3);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
}
.tg-float-title {
    font-size: 14px;
    font-weight: 900;
    color: #fff;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 3px;
}
.tg-float-sub {
    font-size: 11px;
    color: #8a8a96;
    line-height: 1.45;
}

.tg-float-perks {
    display: flex;
    flex-direction: column;
    gap: 5px;
    margin-bottom: 14px;
    padding: 10px 12px;
    background: rgba(0,0,0,0.3);
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.04);
}
.tg-float-perk {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 11px;
    color: #c0c0d0;
    font-weight: 600;
}

.tg-float-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    width: 100%;
    padding: 11px;
    background: linear-gradient(135deg, #fb923c, #f97316);
    border: none;
    border-radius: 10px;
    color: #fff;
    font-size: 12px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 1px;
    cursor: pointer;
    font-family: inherit;
    box-shadow: 0 0 18px rgba(249,115,22,0.35);
    transition: opacity .2s, transform .2s;
}
.tg-float-btn:hover { opacity: .88; transform: translateY(-1px); }

@media(max-width: 480px) {
    .tg-float-banner {
        width: calc(100vw - 32px);
        right: 16px;
        bottom: 20px;
        border-radius: 16px;
    }
}
</style>

<script>
(function() {
    var banner  = document.getElementById('tgFloatBanner');
    var closeBtn = document.getElementById('tgFloatClose');
    if (!banner) return;

    // Не показываем если пользователь уже закрывал (до конца сессии)
    if (sessionStorage.getItem('tgBannerClosed')) return;

    // Показываем через 4 секунды
    var showTimer = setTimeout(function() {
        banner.classList.add('show');
    }, 4000);

    closeBtn.addEventListener('click', function() {
        banner.classList.remove('show');
        sessionStorage.setItem('tgBannerClosed', '1');
        clearTimeout(showTimer);
    });
})();
</script>
<?php endif; ?>

<script>
// ── Статус привязки (из PHP) ──
var IS_LINKED = <?= $isLinked ? 'true' : 'false' ?>;
var pendingOrderService = '';
var pollInterval = null;

// ── Открыть модалку привязки TG (по кнопке в хедере) ──
function openTgModal() {
    document.getElementById('tgModalOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}

// ── Нажатие «ЗАКАЗАТЬ» — всегда идём на order.php, TG не блокирует ──
function handleOrder(serviceKey) {
    pendingOrderService = serviceKey;
    window.location.href = 'order.php?service=' + encodeURIComponent(serviceKey) + '&accepted=1';
}

// ── Закрыть модалку ──
function closeTgModal() {
    document.getElementById('tgModalOverlay').classList.remove('active');
    document.body.style.overflow = '';
    stopPolling();
}

// ── Закрыть по клику на оверлей ──
var _overlay = document.getElementById('tgModalOverlay');
if (_overlay) {
    _overlay.addEventListener('click', function(e) {
        if (e.target === this) closeTgModal();
    });
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
    var w = document.getElementById('tgWaiting');
    if (w) w.classList.add('show');
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
                var modal = document.getElementById('tgModal');
                var displayName = data.first_name || (data.username ? '@' + data.username : 'Ты');
                var photoHtml = data.photo_url
                    ? '<img src="' + data.photo_url + '" style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid rgba(34,197,94,0.5);margin:0 auto 14px;display:block;" onerror="this.style.display=\'none\'">'
                    : '<div class="tg-modal-icon" style="background:linear-gradient(135deg,rgba(34,197,94,0.2),rgba(34,197,94,0.08));border-color:rgba(34,197,94,0.35);"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#86efac" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>';
                modal.innerHTML =
                    photoHtml +
                    '<h2>✅ Привязано!</h2>' +
                    '<p class="tg-modal-sub"><b>' + displayName + '</b>, Telegram успешно связан с сайтом! Теперь в хедере появится твой профиль.</p>' +
                    '<a href="profile.php" class="tg-bot-open-btn" style="background:linear-gradient(135deg,#4ade80,#22c55e);box-shadow:0 0 20px rgba(34,197,94,0.4);">' +
                    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Мой профиль</a>' +
                    '<button class="tg-skip-btn" onclick="location.reload()">Закрыть и обновить</button>';
                // Перезагружаем страницу через 2 сек чтобы показать профиль в хедере
                setTimeout(function(){ window.location.reload(); }, 2000);
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