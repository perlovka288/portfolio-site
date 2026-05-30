<?php
session_start();
require_once 'config/db.php';

$stmt = $pdo->query("SELECT * FROM prices ORDER BY id ASC");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kostlim Design | Прайс-лист</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .price-page { padding: 42px 24px 70px; }
        .price-head { text-align: center; margin-bottom: 34px; }
        .price-head h1 { font-size: clamp(28px, 4vw, 48px); margin-bottom: 10px; }
        .price-head p { color: #8a8a96; }
        .price-grid-local { display: grid; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); gap: 22px; }
        .service-card { position: relative; overflow: hidden; background: #111116; border: 1px solid #222230; border-radius: 16px; min-height: 360px; display: flex; flex-direction: column; }
        .service-card:hover { border-color: rgba(169, 88, 81, .7); transform: translateY(-3px); transition: .2s ease; }
        .service-image { width: 100%; aspect-ratio: 16 / 9; background: #171720; overflow: hidden; }
        .service-image img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .service-body { padding: 20px; display: flex; flex-direction: column; flex: 1; }
        .service-body h2 { font-size: 19px; margin-bottom: 10px; }
        .service-body p { color: #a5a5b2; line-height: 1.55; font-size: 14px; margin-bottom: 16px; }
        .service-body ul { list-style: none; padding: 0; margin: 0 0 20px; color: #d6d6df; font-size: 13px; }
        .service-body li { margin-bottom: 8px; }
        .service-body li::before { content: '✦'; color: #a95851; margin-right: 8px; }
        .service-footer { margin-top: auto; border-top: 1px solid #252532; padding-top: 16px; display: flex; align-items: center; justify-content: space-between; gap: 14px; }
        .service-price { font-size: 20px; font-weight: 900; color: #00ffa3; line-height: 1.35; white-space: nowrap; }
        .service-order { text-decoration: none; color: #fff; background: #a95851; border-radius: 10px; padding: 11px 16px; font-weight: 900; text-transform: uppercase; font-size: 12px; }
    </style>
</head>
<body>
<header>
    <div class="header-left"><a href="index.php" class="nav-link">← На главную</a></div>
    <div class="brand-title"><h1>KOSTLIM</h1><span>DESIGN</span></div>
    <div class="header-right"><a href="order.php" class="nav-link" style="background:#a95851;">🤖 К заказу</a></div>
</header>

<main class="container price-page">
    <div class="price-head">
        <h1>Прайс-лист</h1>
        <p>Все услуги подтягиваются из админ-панели и сразу доступны в Telegram-боте.</p>
    </div>

    <section class="price-grid-local">
        <?php foreach ($services as $service): ?>
            <article class="service-card">
                <div class="service-image">
                    <?php if (!empty($service['image'])): ?>
                        <img src="uploads/<?= htmlspecialchars($service['image']) ?>" alt="<?= htmlspecialchars($service['title']) ?>">
                    <?php endif; ?>
                </div>
                <div class="service-body">
                    <h2><?= htmlspecialchars($service['title']) ?></h2>
                    <p><?= htmlspecialchars($service['description'] ?? '') ?></p>
                    <ul>
                        <?php foreach (explode('|', (string)($service['features'] ?? '')) as $feature): ?>
                            <?php if (trim($feature) !== ''): ?>
                                <li><?= htmlspecialchars(trim($feature)) ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                    <div class="service-footer">
                        <div class="service-price"><?= (int)$service['price_rub'] ?> ₽<br><?= (int)$service['price_uan'] ?> ₴</div>
                        <a href="order.php?service=<?= htmlspecialchars($service['category_key']) ?>" class="service-order">Заказать</a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
</main>
</body>
</html>
