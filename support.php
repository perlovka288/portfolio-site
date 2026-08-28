<?php
// Скрываем ошибки от пользователей
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ── Фикс сессий для Safari/iOS (SameSite=None + Secure) ──────────────────
require_once 'includes/session.php';
require_once 'config/db.php';

$sid = session_id();

// ── Профиль текущего посетителя (для шапки — совпадает с логикой index.php) ──
$isLinked  = false;
$tgProfile = [];
try {
    $stmt = $pdo->prepare("SELECT site_code, linked, tg_id, tg_username, tg_first_name, tg_photo_url FROM tg_links WHERE session_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$sid]);
    $linkRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($linkRow && $linkRow['linked'] && $linkRow['linked'] !== 'f') {
        $isLinked  = true;
        $tgProfile = $linkRow;
        $tgProfile['tg_photo_url'] = ensureTgAvatarFresh(
            $pdo,
            $sid,
            (string)($linkRow['tg_id'] ?? ''),
            (string)($linkRow['tg_photo_url'] ?? '')
        );
    }
} catch (Throwable $e) {}

define('ADMIN_TG_ID', '1710365896');
$adminTgEnv = getenv('ADMIN_ID') ?: '1710365896';

if (!empty($_GET['tg_id']) && $_GET['tg_id'] === ADMIN_TG_ID) $_SESSION['admin_logged'] = true;
$isAdmin = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
if (!$isAdmin && !empty($tgProfile['tg_id']) && (string)$tgProfile['tg_id'] === $adminTgEnv) {
    $isAdmin = true;
    $_SESSION['admin_logged'] = true;
}

// ── Профиль дизайнера (админа) для карточки поддержки ──
// Подтягиваем реальные имя/аватар из его же собственной Telegram-привязки
// (та же таблица tg_links, что и для обычных клиентов) — если админ хоть
// раз открывал сайт из Telegram, тут будут его настоящие имя и фото.
// Если данных ещё нет — используем понятные значения по умолчанию.
$adminProfile = [
    'tg_first_name' => 'Андрей',
    'tg_username'   => 'Perlo_ovka',
    'tg_photo_url'  => '',
];
try {
    $stmt = $pdo->prepare("SELECT tg_first_name, tg_username, tg_photo_url FROM tg_links WHERE tg_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1");
    $stmt->execute([$adminTgEnv]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if (!empty($row['tg_first_name'])) $adminProfile['tg_first_name'] = $row['tg_first_name'];
        if (!empty($row['tg_username']))   $adminProfile['tg_username']   = $row['tg_username'];
        if (!empty($row['tg_photo_url']))  $adminProfile['tg_photo_url']  = $row['tg_photo_url'];
    }
} catch (Throwable $e) {}

function imgSrc(string $val, string $base = 'uploads/'): string {
    if ($val === '') return '';
    if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) return $val;
    return '/' . ltrim($base . $val, '/');
}

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
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Kostlim Design | Поддержка</title>
<link rel="icon" type="image/png" href="/assets/img/logo.png" sizes="16x16">
<link rel="apple-touch-icon" href="/assets/img/logo.png">
<link rel="stylesheet" href="style.css">
<style>
body::before {
    content:'';position:fixed;top:-120px;left:50%;transform:translateX(-50%);
    width:700px;height:400px;background:radial-gradient(ellipse at center,rgba(249,115,22,0.13) 0%,transparent 70%);
    pointer-events:none;z-index:0;
}
.support-wrap { max-width: 560px; margin: 0 auto; padding: 26px 20px 70px; position: relative; z-index: 1; }
.support-title { font-size: 20px; font-weight: 900; margin-bottom: 4px; }
.support-sub { color: var(--text2); font-size: 13px; margin-bottom: 26px; }

/* Карточка дизайнера */
.support-admin-card {
    display: flex; align-items: center; gap: 14px;
    background: var(--card); border: 1px solid var(--border);
    border-radius: 20px; padding: 18px 20px; margin-bottom: 16px;
}
.support-admin-ava {
    width: 56px; height: 56px; border-radius: 50%; object-fit: cover;
    border: 2px solid var(--border-accent); flex-shrink: 0;
}
.support-admin-ava-fallback {
    width: 56px; height: 56px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg, var(--accent2), var(--accent));
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; font-weight: 900; color: #fff;
}
.support-admin-name { font-size: 15px; font-weight: 800; color: var(--text); }
.support-admin-handle { font-size: 12px; color: var(--text2); }
.support-admin-role {
    margin-left: auto; flex-shrink: 0;
    font-size: 9px; font-weight: 900; text-transform: uppercase; letter-spacing: .5px;
    color: var(--accent3); background: var(--accent-dim);
    border: 1px solid var(--border-accent); border-radius: 6px; padding: 3px 8px;
}

/* Кнопки действий поддержки */
.support-actions { display: flex; flex-direction: column; gap: 10px; margin-bottom: 26px; }
.support-action-btn {
    display: flex; align-items: center; gap: 12px;
    background: var(--card); border: 1px solid var(--border);
    border-radius: 16px; padding: 16px 18px;
    color: var(--text); font-size: 14px; font-weight: 700;
    transition: all var(--t); cursor: pointer; font-family: inherit; text-align: left; width: 100%;
}
.support-action-btn:hover { border-color: var(--border-accent); background: var(--accent-dim); transform: translateY(-1px); }
.support-action-icon {
    width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    background: var(--accent-dim); color: var(--accent);
}
.support-action-text { display: flex; flex-direction: column; gap: 2px; }
.support-action-sub { font-size: 11.5px; font-weight: 500; color: var(--text2); }
.support-action-btn.primary {
    background: linear-gradient(135deg, var(--accent2), var(--accent));
    border-color: transparent; color: #fff;
    box-shadow: inset 0 1px rgba(255,255,255,.22), var(--shadow-accent);
}
.support-action-btn.primary .support-action-icon { background: rgba(255,255,255,.18); color: #fff; }
.support-action-btn.primary .support-action-sub { color: rgba(255,255,255,.8); }

/* FAQ / доп. инфо */
.support-note {
    background: var(--accent-dim); border: 1px solid var(--border-accent);
    border-radius: 14px; padding: 14px 16px; color: var(--text2); font-size: 12.5px; line-height: 1.6;
}
</style>
</head>
<body class="theme-<?= htmlspecialchars($themePreset) ?> shape-<?= htmlspecialchars($themeShape) ?> density-<?= htmlspecialchars($themeDensity) ?> effects-<?= htmlspecialchars($themeEffects) ?>">

<?php $sectionTabsActive = 'support'; include __DIR__ . '/includes/section_tabs.php'; ?>

<header>
    <div class="header-left header-icon-row" style="display:flex;align-items:center;gap:10px;">
        <a href="https://t.me/designkostlim" target="_blank" class="tg-glow-btn" title="Telegram">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        </a>
        <?php if ($isAdmin): ?>
        <a href="admin/index.php" class="tg-glow-btn" title="Админ-панель">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        </a>
        <?php endif; ?>
    </div>

    <div class="brand-title"><a href="index.php"><img src="/assets/img/logo.png" class="brand-logo-img" alt="Kostlim Design" style="height:40px;width:auto;max-width:160px;display:block;"></a></div>

    <div class="header-right" style="display:flex;align-items:center;gap:10px;">
        <a href="price.php" class="nav-link nav-price"><span class="icon"></span>Прайс</a>
        <?php if ($isLinked && !empty($tgProfile)): ?>
        <a href="profile.php" class="tg-user-chip" title="Личный профиль">
            <?php if (!empty($tgProfile['tg_photo_url'])): ?>
               <img src="<?= htmlspecialchars(imgSrc((string)($tgProfile['tg_photo_url'] ?? ''))) ?>" class="tg-user-ava" alt="аватар"
     onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <span class="tg-user-ava-fallback" style="display:none;">
                    <?= mb_substr(($tgProfile['tg_first_name'] ?? '') ?: (($tgProfile['tg_username'] ?? '') ?: '?'), 0, 1) ?>
                </span>
            <?php else: ?>
                <span class="tg-user-ava-fallback">
                    <?= mb_substr(($tgProfile['tg_first_name'] ?? '') ?: (($tgProfile['tg_username'] ?? '') ?: '?'), 0, 1) ?>
                </span>
            <?php endif; ?>
            <span class="tg-user-name">
                <?= htmlspecialchars(($tgProfile['tg_first_name'] ?? '') ?: ('@' . ($tgProfile['tg_username'] ?? ''))) ?>
            </span>
            <?php if ($isAdmin): ?><span class="tg-admin-tag">admin</span><?php endif; ?>
        </a>
        <?php else: ?>
        <a href="index.php" class="nav-link nav-bot">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="flex-shrink:0"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
            Привязать TG
        </a>
        <?php endif; ?>
    </div>
</header>

<div class="support-wrap">
    <div class="support-title">Поддержка</div>
    <div class="support-sub">Вопросы по заказу, срокам или оплате — сюда.</div>

    <div class="support-admin-card">
        <?php if (!empty($adminProfile['tg_photo_url'])): ?>
            <img src="<?= htmlspecialchars(imgSrc($adminProfile['tg_photo_url'])) ?>" class="support-admin-ava" alt="Аватар"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <div class="support-admin-ava-fallback" style="display:none;"><?= mb_strtoupper(mb_substr($adminProfile['tg_first_name'], 0, 1)) ?></div>
        <?php else: ?>
            <div class="support-admin-ava-fallback"><?= mb_strtoupper(mb_substr($adminProfile['tg_first_name'], 0, 1)) ?></div>
        <?php endif; ?>
        <div>
            <div class="support-admin-name"><?= htmlspecialchars($adminProfile['tg_first_name']) ?></div>
            <div class="support-admin-handle">@<?= htmlspecialchars($adminProfile['tg_username']) ?></div>
        </div>
        <span class="support-admin-role">Дизайнер</span>
    </div>

    <div class="support-actions">
        <a href="https://t.me/<?= htmlspecialchars($adminProfile['tg_username']) ?>" target="_blank" class="support-action-btn">
            <span class="support-action-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
            </span>
            <span class="support-action-text">
                Написать в Telegram
                <span class="support-action-sub">Ответим лично — обычно в течение дня</span>
            </span>
        </a>

        <button type="button" class="support-action-btn primary" onclick="window.openAiWidgetPanel && window.openAiWidgetPanel()">
            <span class="support-action-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2a7 7 0 0 0-7 7c0 2.4 1.2 4.5 3 5.7V17a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2v-2.3c1.8-1.2 3-3.3 3-5.7a7 7 0 0 0-7-7z"/><line x1="9" y1="22" x2="15" y2="22"/></svg>
            </span>
            <span class="support-action-text">
                Спросить у ИИ-ассистента
                <span class="support-action-sub">Быстрые ответы по заказу прямо сейчас</span>
            </span>
        </button>

        <a href="profile.php#orders-section" class="support-action-btn">
            <span class="support-action-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/></svg>
            </span>
            <span class="support-action-text">
                Мои заказы
                <span class="support-action-sub">Статус, детали и переписка по заказу</span>
            </span>
        </a>
    </div>

    <div class="support-note">
        Сайт в тестовом режиме — если что-то работает не так, просто напиши об этом в Telegram, поправим быстро.
    </div>
</div>

<footer>
    <div class="container">© <?= date('Y') ?> Kostlim Design</div>
</footer>

<?php include __DIR__ . '/includes/ai_widget.php'; ?>
</body>
</html>
