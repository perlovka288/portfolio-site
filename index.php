<?php
session_start();
require_once 'config/db.php';

$categories = $pdo->query("SELECT * FROM portfolio_categories ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$categoryMap = [];
foreach ($categories as $category) {
    $categoryMap[$category['category_key']] = $category;
}

$works  = $pdo->query("SELECT * FROM portfolio ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$admin  = $pdo->query("SELECT avatar FROM users LIMIT 1")->fetch();
$avatar = (!empty($admin['avatar'])) ? $admin['avatar'] : 'default_avatar.png';

$settings     = $pdo->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$themePreset  = $settings['theme_preset']  ?? 'onyx';
$themeShape   = $settings['theme_shape']   ?? 'soft';
$themeDensity = $settings['theme_density'] ?? 'normal';
$themeEffects = $settings['theme_effects'] ?? 'glow';

/**
 * Хелпер: возвращает правильный src для изображения.
 *
 * Логика:
 *  - Если значение начинается с http:// / https:// — это уже полный URL (новый формат), используем как есть.
 *  - Иначе — старый формат (имя файла), добавляем uploads/.
 *
 * Это обеспечивает обратную совместимость: старые записи в БД продолжают работать,
 * а новые загрузки сохраняются как полный URL и не теряются при деплое.
 */
function imgSrc(string $value): string {
    if ($value === '') return '';
    if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
        return $value;
    }
    return 'uploads/' . $value;
}

function avatarSrc(string $value): string {
    return imgSrc($value);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kostlim Design | Портфолио</title>
<link rel="stylesheet" href="style.css">
<style>
/* ── Заглушка для битых фото ── */
.portfolio-media img.img-error { display: none; }
.portfolio-media .img-fallback {
    display: none;
    width: 100%; height: 100%;
    align-items: center; justify-content: center; flex-direction: column;
    gap: 10px;
    background: linear-gradient(135deg,#111 0%,#1c1c1c 100%);
    color: #333; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1.5px;
    position: absolute; inset: 0;
}
.portfolio-media .img-fallback svg { width: 36px; height: 36px; opacity: .25; }
.portfolio-media img.img-error + .img-fallback { display: flex; }

@media (max-width:1100px) { .portfolio-grid { grid-template-columns: repeat(2,minmax(240px,1fr)); } }
@media (max-width:720px) {
    header { position:relative; padding:18px; flex-direction:column; gap:16px; }
    .brand-title { position:static; transform:none; order:-1; }
    .header-right { flex-wrap:wrap; justify-content:center; }
    .portfolio-grid { grid-template-columns:1fr; gap:24px; }
    .portfolio-media { border-radius:20px; }
    .portfolio-title, .portfolio-price { font-size:18px; }
    .order-pill { width:100%; font-size:16px; }
    .design-card .portfolio-media { aspect-ratio:16/7; min-height:150px; }
    .design-card .portfolio-meta { grid-template-columns:1fr; }
    .design-avatar-frame { right:14px; width:76px; height:76px; border-width:3px; }
}
</style>
</head>
<body class="theme-<?= htmlspecialchars($themePreset) ?> shape-<?= htmlspecialchars($themeShape) ?> density-<?= htmlspecialchars($themeDensity) ?> effects-<?= htmlspecialchars($themeEffects) ?>">

<header>
    <div class="header-left">
        <div style="display:flex;align-items:center;gap:12px;">
            <img src="<?= htmlspecialchars(avatarSrc($avatar)) ?>" class="avatar-mini" alt="Kostlim"
                 onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 44 44%22%3E%3Ccircle cx=%2222%22 cy=%2222%22 r=%2222%22 fill=%22%23222%22/%3E%3C/svg%3E'">

            <a href="https://t.me/designkostlim" target="_blank" class="nav-link" title="Telegram" style="padding:10px 14px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                </svg>
            </a>

            <?php if (isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true): ?>
            <a href="admin/index.php" class="nav-link" title="Админ-панель" style="padding:10px 14px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="brand-title"><h1>KOSTLIM</h1><span>DESIGN</span></div>

    <div class="header-right">
        <a href="price.php" class="nav-link">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                <line x1="6" y1="8" x2="10" y2="8"/><line x1="14" y1="8" x2="18" y2="8"/><line x1="6" y1="12" x2="14" y2="12"/>
            </svg>
            Прайс
        </a>
        <a href="https://t.me/kostlimdznbot" target="_blank" class="nav-link">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="6" width="20" height="14" rx="3"/><circle cx="9" cy="13" r="1.5" fill="currentColor" stroke="none"/><circle cx="15" cy="13" r="1.5" fill="currentColor" stroke="none"/>
                <path d="M8 6V4a4 4 0 0 1 8 0v2"/>
            </svg>
            Бот для заказов
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
    <?php foreach ($works as $work):
        $img_file  = $work['image'] ?? '';
        $ava_file  = $work['avatar_image'] ?? '';
        $key       = strtolower($work['category_key'] ?? '');
        $category  = $categoryMap[$key] ?? null;
        $cat_class = 'cat-' . $key;
        $isDesign  = !empty($category['is_design']) || in_array($key, ['design','design_pack','banner_avatar'], true);
        $width     = (int)($category['width_px']  ?? 0);
        $height    = (int)($category['height_px'] ?? 0);
        $ratioStyle = (!$isDesign && $width > 0 && $height > 0) ? "--card-ratio:{$width}/{$height};" : '';
        $sizeText   = ($width > 0 && $height > 0) ? "{$width}x{$height}" : '';
    ?>
    <article class="portfolio-card filter-item <?= htmlspecialchars($cat_class) ?> <?= $isDesign ? 'design-card' : 'custom-ratio' ?>"
             style="<?= htmlspecialchars($ratioStyle) ?>">

        <div class="portfolio-media">
            <?php if ($img_file !== ''): ?>
            <img
                src="<?= htmlspecialchars(imgSrc($img_file)) ?>"
                class="<?= $isDesign ? 'design-banner' : '' ?>"
                alt="<?= htmlspecialchars($work['title'] ?? 'Портфолио') ?>"
                onerror="this.classList.add('img-error')"
            >
            <?php endif; ?>
            <div class="img-fallback">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/>
                    <polyline points="21 15 16 10 5 21"/>
                </svg>
                <span>Нет фото</span>
            </div>

            <?php if ($isDesign && $ava_file !== ''): ?>
            <div class="design-avatar-frame">
                <img src="<?= htmlspecialchars(imgSrc($ava_file)) ?>" class="design-avatar" alt="Аватарка"
                     onerror="this.style.display='none'">
            </div>
            <?php endif; ?>
        </div>

        <div class="portfolio-meta">
            <div class="portfolio-title">
                <?= htmlspecialchars($work['title'] ?? 'Без названия') ?>
                <?php if ($sizeText !== ''): ?>
                <span style="display:block;color:var(--text2);font-size:12px;margin-top:3px;"><?= htmlspecialchars($sizeText) ?> px</span>
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
</script>
</body>
</html>