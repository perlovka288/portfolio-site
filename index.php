<?php
session_start();
require_once 'config/db.php';

function imgSrc(string $val, string $base = 'uploads/'): string {
    if ($val === '') return '';
    return (str_starts_with($val,'http://') || str_starts_with($val,'https://')) ? $val : $base . $val;
}

$categories = $pdo->query("SELECT * FROM portfolio_categories ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$categoryMap = [];
foreach ($categories as $category) {
    $categoryMap[$category['category_key']] = $category;
}
$works = $pdo->query("SELECT * FROM portfolio ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$admin = $pdo->query("SELECT avatar FROM users LIMIT 1")->fetch();
$avatar = (!empty($admin['avatar'])) ? $admin['avatar'] : 'default_avatar.png';
$settings = $pdo->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$themePreset = $settings['theme_preset'] ?? 'onyx';
$themeShape = $settings['theme_shape'] ?? 'soft';
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
        .portfolio-stage { margin-top: 34px; padding-bottom: 70px; }
        .portfolio-grid { display: grid; grid-template-columns: repeat(3, minmax(260px, 1fr)); gap: 28px; align-items: start; }
        .portfolio-card { background: transparent; display: flex; flex-direction: column; gap: 12px; }
        .portfolio-media { position: relative; width: 100%; aspect-ratio: 16 / 9; overflow: hidden; border-radius: 30px; background: #f4f4f4; }
        .portfolio-media img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .custom-ratio .portfolio-media { aspect-ratio: var(--card-ratio, 16 / 9); }
        .portfolio-meta { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center; padding: 0 10px; }
        .portfolio-title { color: #fff; font-size: 22px; font-weight: 500; overflow-wrap: anywhere; }
        .portfolio-price { color: #fff; font-size: 21px; font-weight: 800; white-space: nowrap; }
        .order-pill { justify-self: center; min-width: 180px; text-decoration: none; text-align: center; border-radius: 999px; padding: 12px 26px; color: #fff; font-size: 18px; font-weight: 800; letter-spacing: 1px; background: linear-gradient(135deg, #f97316, #ea580c); box-shadow: inset 0 1px rgba(255,255,255,.22), 0 8px 24px rgba(249,115,22,.3); }
        .design-card { grid-column: 1 / -1; margin-top: 28px; }
        .design-card .portfolio-media { aspect-ratio: 1590 / 400; min-height: 220px; border-radius: 28px; background: #08080b; border: 1px solid rgba(255,255,255,.08); }
        .design-card .portfolio-media > .design-banner { width: 100%; height: 100%; object-fit: cover; object-position: center; background: #08080b; }
        .design-avatar-frame { position: absolute; right: clamp(18px, 3vw, 38px); top: 50%; transform: translateY(-50%); width: clamp(96px, 12vw, 156px); height: clamp(96px, 12vw, 156px); border-radius: 50%; overflow: hidden; border: 4px solid #f7f7f7; background: #111116; box-shadow: 0 14px 34px rgba(0,0,0,.45); z-index: 3; }
        .portfolio-media .design-avatar-frame .design-avatar { width: 100%; height: 100%; border: 0; border-radius: 0; object-fit: cover; object-position: center; display: block; }
        .design-card .portfolio-meta { grid-template-columns: 1fr auto auto; padding: 0 8px; }
        .avatar-container > .admin-link:not(.nav-link) { display: none; }

        /* Icon buttons like Blackwatch */
        .hicon-btn {
            width: 36px; height: 36px; border-radius: 8px;
            border: 1px solid var(--border); background: transparent;
            color: var(--text2); cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            transition: all var(--t); text-decoration: none; flex-shrink: 0;
        }
        .hicon-btn:hover {
            border-color: var(--accent); color: var(--accent);
            background: var(--accent-dim);
        }
        .hicon-btn.hicon-admin {
            color: var(--accent);
            border-color: var(--border-accent);
            background: var(--accent-dim);
        }
        .hicon-btn.hicon-admin:hover {
            background: rgba(249,115,22,.16);
            box-shadow: 0 0 16px var(--accent-glow);
        }
        .tabs-container { display: flex; justify-content: center; gap: 12px; margin: 32px 0; flex-wrap: wrap; }
        @media (max-width: 1100px) {
            .portfolio-grid { grid-template-columns: repeat(2, minmax(240px, 1fr)); }
        }
        @media (max-width: 720px) {
            header { position: relative; padding: 18px; flex-direction: column; gap: 16px; }
            .brand-title { position: static; transform: none; order: -1; }
            .header-right { flex-wrap: wrap; justify-content: center; }
            .portfolio-grid { grid-template-columns: 1fr; gap: 24px; }
            .portfolio-media { border-radius: 20px; }
            .portfolio-title, .portfolio-price { font-size: 18px; }
            .order-pill { width: 100%; font-size: 16px; }
            .design-card .portfolio-media { aspect-ratio: 16 / 7; min-height: 150px; }
            .design-card .portfolio-meta { grid-template-columns: 1fr; }
            .design-avatar-frame { right: 14px; width: 76px; height: 76px; border-width: 3px; }
        }
    </style>
</head>
<body class="theme-<?= htmlspecialchars($themePreset) ?> shape-<?= htmlspecialchars($themeShape) ?> density-<?= htmlspecialchars($themeDensity) ?> effects-<?= htmlspecialchars($themeEffects) ?>">

<header>
    <div class="header-left">
        <div class="avatar-container" style="display: flex; align-items: center; gap: 10px;">
            <img src="<?= htmlspecialchars(imgSrc($avatar)) ?>" class="avatar-mini" alt="Kostlim">
            <a href="https://t.me/designkostlim" target="_blank" class="hicon-btn" title="Telegram">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4 20-7z"/><path d="M22 2 11 13"/></svg>
            </a>
            <?php if (isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true): ?>
            <a href="admin/index.php" class="hicon-btn hicon-admin" title="Админ-панель">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="brand-title"><h1>KOSTLIM</h1><span>DESIGN</span></div>

    <div class="header-right">
        <a href="price.php" class="hicon-btn" title="Прайс">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="2" width="6" height="4" rx="1"/><path d="M5 4h-.6A2 2 0 0 0 2.4 6L2 20a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2L21.6 6a2 2 0 0 0-2-2H19"/><path d="M9 14h6"/><path d="M9 10h6"/></svg>
        </a>
        <a href="https://t.me/kostlimdznbot" target="_blank" class="hicon-btn" title="Бот для заказов">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1"/></svg>
        </a>
    </div>
</header>

<main class="container portfolio-stage">
    <div class="tabs-container">
        <button class="tab-btn active" onclick="filterPortfolio('all', event)">Все работы</button>
        <?php foreach ($categories as $category): ?>
            <button class="tab-btn" onclick="filterPortfolio('cat-<?= htmlspecialchars($category['category_key']) ?>', event)">
                <?= htmlspecialchars($category['title']) ?>
            </button>
        <?php endforeach; ?>
    </div>

    <section class="portfolio-grid">
        <?php foreach ($works as $work): ?>
            <?php
                $img_file = $work['image'] ?? '';
                $ava_file = $work['avatar_image'] ?? '';
                $key = strtolower($work['category_key'] ?? '');
                $category = $categoryMap[$key] ?? null;
                $cat_class = 'cat-' . $key;
                $isDesign = !empty($category['is_design']) || in_array($key, ['design', 'design_pack', 'banner_avatar'], true);
                $width = (int)($category['width_px'] ?? 0);
                $height = (int)($category['height_px'] ?? 0);
                $ratioStyle = (!$isDesign && $width > 0 && $height > 0) ? "--card-ratio: {$width} / {$height};" : '';
                $sizeText = ($width > 0 && $height > 0) ? "{$width}x{$height}" : '';
            ?>

            <article class="portfolio-card filter-item <?= htmlspecialchars($cat_class) ?> <?= $isDesign ? 'design-card' : 'custom-ratio' ?> <?= ($isDesign && $ava_file !== '') ? 'has-avatar' : '' ?>" style="<?= htmlspecialchars($ratioStyle) ?>">
                <div class="portfolio-media">
                    <img src="<?= htmlspecialchars(imgSrc($img_file)) ?>" class="<?= $isDesign ? 'design-banner' : '' ?>" alt="<?= htmlspecialchars($work['title'] ?? 'Портфолио') ?>">
                    <?php if ($isDesign && $ava_file !== ''): ?>
                        <div class="design-avatar-frame">
                            <img src="<?= htmlspecialchars(imgSrc($ava_file)) ?>" class="design-avatar" alt="Аватарка">
                        </div>
                    <?php endif; ?>
                </div>

                <div class="portfolio-meta">
                    <div class="portfolio-title">
                        <?= htmlspecialchars($work['title'] ?? 'Без названия') ?>
                        <?php if ($sizeText !== ''): ?>
                            <span style="display:block; color:#8a8a96; font-size:12px; margin-top:4px;"><?= htmlspecialchars($sizeText) ?> px</span>
                        <?php endif; ?>
                    </div>
                    <div class="portfolio-price"><?= (int)($work['price_rub'] ?? 0) ?>₽/<?= (int)($work['price_uan'] ?? 0) ?>грн</div>
                    <a href="order.php?service=<?= htmlspecialchars($work['category_key'] ?? 'preview') ?>" class="order-pill">ЗАКАЗАТЬ</a>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
</main>

<script>
function filterPortfolio(category, event) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    event.currentTarget.classList.add('active');

    document.querySelectorAll('.filter-item').forEach(item => {
        item.style.display = (category === 'all' || item.classList.contains(category)) ? 'flex' : 'none';
    });
}
</script>
</body>
</html>