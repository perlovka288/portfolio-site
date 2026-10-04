<?php
// Скрываем ошибки от пользователей
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ── Фикс сессий для Safari/iOS (SameSite=None + Secure) ──────────────────
require_once 'includes/session.php';
require_once 'config/db.php';
require_once __DIR__ . '/includes/ppk_icons.php';

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

require_once __DIR__ . '/includes/kui_cache.php';
$settings     = kuiSettingsAll($pdo);
$themePreset  = $settings['theme_preset']  ?? 'onyx';
$themeShape   = $settings['theme_shape']   ?? 'soft';
$themeDensity = $settings['theme_density'] ?? 'normal';
$themeEffects = $settings['theme_effects'] ?? 'glow';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title>Kostlim Design | Поддержка</title>
<link rel="icon" type="image/png" href="/assets/img/logo-64.png" sizes="16x16">
<link rel="apple-touch-icon" href="/assets/img/logo-180.png">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<?php include __DIR__ . '/includes/ui_head.php'; ?>
<link rel="stylesheet" href="/assets/ppk-redesign.css?v=<?= @filemtime(__DIR__ . '/assets/ppk-redesign.css') ?: time() ?>">
</head>
<body class="kui theme-<?= htmlspecialchars($themePreset) ?> shape-<?= htmlspecialchars($themeShape) ?> density-<?= htmlspecialchars($themeDensity) ?> effects-<?= htmlspecialchars($themeEffects) ?>">
<?php
    // ── KUI: оболочка (меню-док ПК / шапка + нижнее меню) — как на остальных страницах ──
    $kuiActive = 'support';
    include __DIR__ . '/includes/ui_shell.php';

    $supName   = htmlspecialchars((string)$adminProfile['tg_first_name']);
    $supHandle = htmlspecialchars((string)$adminProfile['tg_username']);
    $supLetter = htmlspecialchars(mb_strtoupper(mb_substr((string)$adminProfile['tg_first_name'], 0, 1)));
    $supAva    = !empty($adminProfile['tg_photo_url']) ? htmlspecialchars(imgSrc($adminProfile['tg_photo_url'])) : '';
?>

<!-- Баннеры сверху: дизайнер + «Поддержка» (animated-banner) -->
<section class="kui-hero-row kui-ppk-hero-row">
    <div class="rd-banner rd-banner--profile">
        <span class="rd-banner-fx" aria-hidden="true"></span>
        <span class="rd-banner-ov1" aria-hidden="true"></span><span class="rd-banner-ov2" aria-hidden="true"></span>
        <div class="rd-banner-in" style="flex-direction:column;align-items:flex-start;justify-content:flex-end">
            <div class="rd-prof">
                <?php if ($supAva !== ''): ?>
                    <img src="<?= $supAva ?>" alt="" onerror="this.src='/assets/img/logo.webp'">
                <?php else: ?>
                    <span class="rd-ava rd-ava--lg"><?= $supLetter ?></span>
                <?php endif; ?>
                <div style="min-width:0">
                    <small>Ваш дизайнер</small>
                    <h3><?= $supName ?></h3>
                    <div class="h">@<?= $supHandle ?></div>
                </div>
            </div>
            <div class="rd-chips">
                <span class="hc hc--md hc--primary hc--default"><?= ppkIcon('bolt', 'ai--loop') ?>ДИЗАЙНЕР</span>
                <span class="hc hc--md hc--secondary hc--default"><?= ppkIcon('send') ?>@<?= $supHandle ?></span>
            </div>
        </div>
    </div>

    <a class="rd-banner rd-banner--profile" href="https://t.me/<?= $supHandle ?>" target="_blank" rel="noopener">
        <span class="rd-banner-fx" aria-hidden="true"></span>
        <span class="rd-banner-ov1" aria-hidden="true"></span><span class="rd-banner-ov2" aria-hidden="true"></span>
        <div class="rd-banner-in">
            <div class="rd-banner-txt">
                <h3>Поддержка</h3>
                <p>Вопросы по заказу, срокам или оплате — сюда.</p>
                <span class="rd-banner-cta">Написать в Telegram <?= ppkIcon('arrow') ?></span>
            </div>
        </div>
    </a>
</section>

<main class="kui-main kui-ppk-main">
    <h2 class="kui-h2">Как связаться</h2>
    <div class="rd-svc-grid">
        <a href="https://t.me/<?= $supHandle ?>" target="_blank" rel="noopener" class="rd-svc rd-svc--o" title="Ответим лично — обычно в течение дня">
            <h3 class="rd-svc-title">Написать в Telegram</h3>
            <span class="rd-svc-more"><span>Ответим лично</span><?= ppkIcon('arrow') ?></span>
            <span class="rd-svc-ico" aria-hidden="true"><?= ppkIcon('send', 'ai--loop') ?></span>
        </a>
        <button type="button" class="rd-svc rd-svc--d" onclick="window.openAiWidgetPanel && window.openAiWidgetPanel()" title="Быстрые ответы по заказу прямо сейчас">
            <h3 class="rd-svc-title">Спросить у ИИ-ассистента</h3>
            <span class="rd-svc-more"><span>Ответ сразу</span><?= ppkIcon('arrow') ?></span>
            <span class="rd-svc-ico" aria-hidden="true"><?= ppkIcon('success', 'ai--loop') ?></span>
        </button>
        <a href="profile.php#orders-section" class="rd-svc rd-svc--a" title="Статус, детали и переписка по заказу">
            <h3 class="rd-svc-title">Мои заказы</h3>
            <span class="rd-svc-more"><span>Статус и переписка</span><?= ppkIcon('arrow') ?></span>
            <img class="rd-svc-img" src="/assets/img/PLANER.webp" alt="" width="160" height="160" loading="lazy" decoding="async">
        </a>
        <a href="price.php" class="rd-svc rd-svc--g" title="Стоимость работ и пакетов">
            <h3 class="rd-svc-title">Прайс</h3>
            <span class="rd-svc-more"><span>Посмотреть цены</span><?= ppkIcon('arrow') ?></span>
            <span class="rd-svc-ico" aria-hidden="true"><?= ppkIcon('eye', 'ai--loop') ?></span>
        </a>
    </div>

    <div class="kui-card kui-ppk-note">Сайт в тестовом режиме — если что-то работает не так, просто напишите об этом в Telegram, поправим быстро.</div>
</main>

<footer>
    <div class="container">© <?= date('Y') ?> Kostlim Design</div>
</footer>

<?php $aiWidgetHideFab = true; include __DIR__ . '/includes/ai_widget.php'; ?>
<script src="/assets/kostlim-ui.js?v=<?= @filemtime(__DIR__ . '/assets/kostlim-ui.js') ?: time() ?>"></script>
</body>
</html>