<?php
session_start();
require_once 'config/db.php';

$categories = $pdo->query("SELECT * FROM portfolio_categories ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$categoryMap = [];
foreach ($categories as $category) {
    $categoryMap[$category['category_key']] = $category;
}

$works   = $pdo->query("SELECT * FROM portfolio ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$admin   = $pdo->query("SELECT avatar FROM users LIMIT 1")->fetch();
$avatar  = (!empty($admin['avatar'])) ? $admin['avatar'] : 'default_avatar.png';
$settings    = $pdo->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$themePreset  = $settings['theme_preset']   ?? 'onyx';
$themeShape   = $settings['theme_shape']    ?? 'soft';
$themeDensity = $settings['theme_density']  ?? 'normal';
$themeEffects = $settings['theme_effects']  ?? 'glow';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kostlim Design | Портфолио</title>
<link rel="stylesheet" href="style.css">
<style>
/* ── Портфолио-специфичные стили (дополняют style.css) ── */
.portfolio-stage { margin-top: 34px; padding-bottom: 70px; }

.portfolio-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(260px, 1fr));
  gap: 28px; align-items: start;
}
.portfolio-card {
  background: transparent;
  display: flex; flex-direction: column; gap: 12px;
  transition: transform .25s;
}
.portfolio-card:hover { transform: translateY(-3px); }

.portfolio-media {
  position: relative; width: 100%;
  aspect-ratio: 16 / 9; overflow: hidden;
  border-radius: 24px; background: #0f0f18;
  box-shadow: 0 4px 24px rgba(0,0,0,.4);
  transition: box-shadow .3s;
}
.portfolio-card:hover .portfolio-media {
  box-shadow: 0 8px 40px rgba(192,84,74,.22), 0 4px 16px rgba(0,0,0,.5);
}
.portfolio-media img {
  width: 100%; height: 100%;
  object-fit: cover; display: block;
  transition: transform .4s ease;
}
.portfolio-card:hover .portfolio-media img { transform: scale(1.03); }

.custom-ratio .portfolio-media { aspect-ratio: var(--card-ratio, 16 / 9); }

.portfolio-meta {
  display: grid; grid-template-columns: 1fr auto;
  gap: 12px; align-items: center; padding: 0 10px;
}
.portfolio-title { color: #fff; font-size: 21px; font-weight: 600; overflow-wrap: anywhere; }
.portfolio-price {
  color: #f08060; font-size: 20px; font-weight: 800; white-space: nowrap;
  text-shadow: 0 0 14px rgba(240,128,96,.3);
}
.order-pill {
  justify-self: center; min-width: 160px;
  text-decoration: none; text-align: center;
  border-radius: 999px; padding: 12px 24px;
  color: #fff; font-family: 'Unbounded', sans-serif;
  font-size: 12px; font-weight: 900; letter-spacing: 1px; text-transform: uppercase;
  background: linear-gradient(170deg, #d06459, #a84142);
  box-shadow: inset 0 1px rgba(255,255,255,.22), 0 6px 20px rgba(192,84,74,.35);
  transition: transform .15s, box-shadow .2s;
}
.order-pill:hover {
  transform: translateY(-1px);
  box-shadow: inset 0 1px rgba(255,255,255,.25), 0 10px 28px rgba(192,84,74,.5);
}

.design-card { grid-column: 1 / -1; margin-top: 28px; }
.design-card .portfolio-media {
  aspect-ratio: 1590 / 400; min-height: 220px;
  border-radius: 22px; background: #08080b;
  border: 1px solid rgba(255,255,255,.08);
}
.design-card .portfolio-media > .design-banner {
  width: 100%; height: 100%;
  object-fit: cover; object-position: center; background: #08080b;
}
.design-avatar-frame {
  position: absolute;
  right: clamp(18px,3vw,38px); top: 50%; transform: translateY(-50%);
  width: clamp(96px,12vw,156px); height: clamp(96px,12vw,156px);
  border-radius: 50%; overflow: hidden;
  border: 3px solid rgba(192,84,74,.65);
  background: #111116;
  box-shadow: 0 0 24px rgba(192,84,74,.4), 0 14px 34px rgba(0,0,0,.5);
  z-index: 3;
}
.portfolio-media .design-avatar-frame .design-avatar {
  width: 100%; height: 100%; border: 0; border-radius: 0;
  object-fit: cover; object-position: center; display: block;
}
.design-card .portfolio-meta { grid-template-columns: 1fr auto auto; padding: 0 8px; }
.avatar-container > .admin-link:not(.nav-link) { display: none; }

.tabs-container {
  display: flex; justify-content: center; gap: 10px;
  margin: 36px 0; flex-wrap: wrap;
}

@media (max-width: 1100px) {
  .portfolio-grid { grid-template-columns: repeat(2, minmax(240px, 1fr)); }
}
@media (max-width: 720px) {
  header { position: relative; padding: 18px; flex-direction: column; gap: 16px; }
  .brand-title { position: static; transform: none; order: -1; }
  .header-right { flex-wrap: wrap; justify-content: center; }
  .portfolio-grid { grid-template-columns: 1fr; gap: 22px; }
  .portfolio-media { border-radius: 18px; }
  .portfolio-title, .portfolio-price { font-size: 16px; }
  .order-pill { width: 100%; font-size: 12px; }
  .design-card .portfolio-media { aspect-ratio: 16 / 7; min-height: 150px; }
  .design-card .portfolio-meta { grid-template-columns: 1fr; }
  .design-avatar-frame { right: 14px; width: 76px; height: 76px; border-width: 2px; }
}
</style>
</head>
<body class="theme-<?= htmlspecialchars($themePreset) ?> shape-<?= htmlspecialchars($themeShape) ?> density-<?= htmlspecialchars($themeDensity) ?> effects-<?= htmlspecialchars($themeEffects) ?>">

<header>
  <div class="header-left">
    <div class="avatar-container" style="display:flex; align-items:center; gap:10px;">
      <img src="uploads/<?= htmlspecialchars($avatar) ?>" class="avatar-mini" alt="Kostlim">
      <a href="https://t.me/designkostlim" target="_blank" class="nav-link" title="Telegram">✈</a>
      <?php if (isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true): ?>
        <a href="admin/index.php" class="admin-link nav-link" title="Админ-панель" style="font-size:18px;">⚙️</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="brand-title">
    <h1>KOSTLIM</h1>
    <span>DESIGN</span>
  </div>

  <div class="header-right">
    <a href="price.php" class="nav-link">📋 Прайс</a>
    <a href="https://t.me/kostlimdznbot" target="_blank" class="nav-link">🤖 Бот для заказов</a>
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
        $img_file = $work['image']        ?? '';
        $ava_file = $work['avatar_image'] ?? '';
        $key      = strtolower($work['category_key'] ?? '');
        $category = $categoryMap[$key] ?? null;
        $cat_class = 'cat-' . $key;

        $isDesign = !empty($category['is_design'])
          || in_array($key, ['design','design_pack','banner_avatar'], true);

        $width     = (int)($category['width_px']  ?? 0);
        $height    = (int)($category['height_px'] ?? 0);
        $ratioStyle = (!$isDesign && $width > 0 && $height > 0)
          ? "--card-ratio: {$width} / {$height};" : '';
        $sizeText = ($width > 0 && $height > 0) ? "{$width}x{$height}" : '';
      ?>
      <article
        class="portfolio-card filter-item <?= htmlspecialchars($cat_class) ?>
               <?= $isDesign ? 'design-card' : 'custom-ratio' ?>
               <?= ($isDesign && $ava_file !== '') ? 'has-avatar' : '' ?>"
        style="<?= htmlspecialchars($ratioStyle) ?>">

        <div class="portfolio-media">
          <img
            src="uploads/<?= htmlspecialchars($img_file) ?>"
            class="<?= $isDesign ? 'design-banner' : '' ?>"
            alt="<?= htmlspecialchars($work['title'] ?? 'Портфолио') ?>"
          >
          <?php if ($isDesign && $ava_file !== ''): ?>
            <div class="design-avatar-frame">
              <img src="uploads/<?= htmlspecialchars($ava_file) ?>" class="design-avatar" alt="Аватарка">
            </div>
          <?php endif; ?>
        </div>

        <div class="portfolio-meta">
          <div class="portfolio-title">
            <?= htmlspecialchars($work['title'] ?? 'Без названия') ?>
            <?php if ($sizeText !== ''): ?>
              <span style="display:block; color:#6b6b7d; font-size:11px; margin-top:3px;">
                <?= htmlspecialchars($sizeText) ?> px
              </span>
            <?php endif; ?>
          </div>
          <div class="portfolio-price">
            <?= (int)($work['price_rub'] ?? 0) ?>₽/<?= (int)($work['price_uan'] ?? 0) ?>грн
          </div>
          <a href="order.php?service=<?= htmlspecialchars($work['category_key'] ?? 'preview') ?>" class="order-pill">
            ЗАКАЗАТЬ
          </a>
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
    item.style.display = (category === 'all' || item.classList.contains(category))
      ? 'flex' : 'none';
  });
}
</script>
</body>
</html>