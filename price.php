<?php
session_start();
require_once 'config/db.php';

// ── Resolve image src: полный https:// URL или uploads/ + имя файла ──
function imgSrc(string $val, string $base = 'uploads/'): string {
    if ($val === '') return '';
    if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) return $val;
    return $base . $val;
}

$stmt     = $pdo->query("SELECT * FROM prices ORDER BY id ASC");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kostlim Design | Прайс-лист</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<header>
    <div class="header-left">
        <a href="index.php" class="nav-link">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
            </svg>
            На главную
        </a>
    </div>

    <div class="brand-title"><h1>KOSTLIM</h1><span>DESIGN</span></div>

    <div class="header-right">
        <a href="https://t.me/kostlimdznbot" target="_blank" class="nav-link">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="6" width="20" height="14" rx="3"/>
                <circle cx="9" cy="13" r="1.5" fill="currentColor" stroke="none"/>
                <circle cx="15" cy="13" r="1.5" fill="currentColor" stroke="none"/>
                <path d="M8 6V4a4 4 0 0 1 8 0v2"/>
            </svg>
            Бот для заказов
        </a>
        <a href="order.php" class="nav-link" style="background:linear-gradient(135deg,var(--accent2),var(--accent));color:#fff;border-color:transparent;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/>
                <path d="M16 10a4 4 0 0 1-8 0"/>
            </svg>
            К заказу
        </a>
    </div>
</header>

<main class="container price-page">
    <div class="price-head">
        <h1>Прайс-лист</h1>
        <p>Все услуги подтягиваются из админ-панели и сразу доступны в Telegram-боте.</p>
    </div>

    <section class="price-grid-local">
    <?php foreach ($services as $service): ?>
    <article class="service-card">
        <div class="service-cover">
            <?php $coverSrc = imgSrc($service['image'] ?? ''); ?>
            <?php if ($coverSrc !== ''): ?>
            <img src="<?= htmlspecialchars($coverSrc) ?>"
                 alt="<?= htmlspecialchars($service['title']) ?>"
                 onerror="this.parentElement.innerHTML='<div class=\'service-cover-placeholder\'><svg width=\'32\' height=\'32\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'1.5\'><rect x=\'3\' y=\'3\' width=\'18\' height=\'18\' rx=\'3\'/><circle cx=\'8.5\' cy=\'8.5\' r=\'1.5\'/><polyline points=\'21 15 16 10 5 21\'/></svg><span>Нет фото</span></div>'">
            <?php else: ?>
            <div class="service-cover-placeholder">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/>
                    <polyline points="21 15 16 10 5 21"/>
                </svg>
                <span>Нет фото</span>
            </div>
            <?php endif; ?>
        </div>

        <div class="service-body">
            <h2><?= htmlspecialchars($service['title']) ?></h2>
            <?php if (!empty($service['description'])): ?>
            <p><?= htmlspecialchars($service['description']) ?></p>
            <?php endif; ?>

            <?php
            $features = array_filter(array_map('trim', explode('|', (string)($service['features'] ?? ''))));
            if (!empty($features)):
            ?>
            <ul class="service-features">
                <?php foreach ($features as $feature): ?>
                <li><?= htmlspecialchars($feature) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <div class="service-footer">
                <div class="service-price">
                    <?= (int)$service['price_rub'] ?> ₽
                    <small><?= (int)$service['price_uan'] ?> ₴</small>
                </div>
                <a href="order.php?service=<?= htmlspecialchars($service['category_key']) ?>" class="service-order">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                    </svg>
                    Заказать
                </a>
            </div>
        </div>
    </article>
    <?php endforeach; ?>
    </section>
</main>

<footer>
    <div class="container">© <?= date('Y') ?> Kostlim Design</div>
</footer>

</body>
</html>