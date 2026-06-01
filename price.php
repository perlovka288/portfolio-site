<?php
session_start();
require_once 'config/db.php';

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
    <style>
        .price-page { padding: 42px 24px 70px; }
        .price-head { text-align: center; margin-bottom: 34px; }
        .price-head h1 { font-size: clamp(28px, 4vw, 48px); margin-bottom: 10px; }
        .price-head p { color: #8a8a96; }

        .price-grid-local {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(290px, 1fr));
            gap: 22px;
        }

        /* ===== КАРТОЧКА УСЛУГИ ===== */
        .service-card {
            position: relative;
            overflow: hidden;
            background: #111116;
            border: 1px solid #222230;
            border-radius: 16px;
            display: flex;
            flex-direction: column;
            transition: border-color .2s, transform .2s;
        }
        .service-card:hover {
            border-color: rgba(169, 88, 81, .7);
            transform: translateY(-3px);
        }

        /* ===== ОБЛОЖКА ===== */
        .service-cover {
            width: 100%;
            aspect-ratio: 16 / 9;
            overflow: hidden;
            background: #171720;
            position: relative;
            flex-shrink: 0;
        }
        .service-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform .35s ease;
        }
        .service-card:hover .service-cover img {
            transform: scale(1.04);
        }

        /* Плейсхолдер когда нет обложки */
        .service-cover-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: linear-gradient(135deg, #171720 0%, #1e1e2c 100%);
            color: #3a3a4e;
        }
        .service-cover-placeholder svg {
            opacity: .45;
        }
        .service-cover-placeholder span {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: .4;
        }

        /* ===== ТЕЛО КАРТОЧКИ ===== */
        .service-body {
            padding: 20px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .service-body h2 {
            font-size: 18px;
            margin: 0 0 10px;
            color: #fff;
            line-height: 1.3;
        }
        .service-body p {
            color: #a5a5b2;
            line-height: 1.55;
            font-size: 14px;
            margin: 0 0 14px;
            flex-grow: 0;
        }
        .service-features {
            list-style: none;
            padding: 0;
            margin: 0 0 18px;
            color: #d6d6df;
            font-size: 13px;
            display: grid;
            gap: 7px;
        }
        .service-features li {
            display: flex;
            align-items: baseline;
            gap: 8px;
        }
        .service-features li::before {
            content: '✦';
            color: #a95851;
            flex-shrink: 0;
            font-size: 10px;
        }

        /* ===== ФУТЕР КАРТОЧКИ ===== */
        .service-footer {
            margin-top: auto;
            border-top: 1px solid #252532;
            padding-top: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }
        .service-price {
            font-size: 20px;
            font-weight: 900;
            color: #00ffa3;
            line-height: 1.35;
            white-space: nowrap;
        }
        .service-price small {
            display: block;
            font-size: 13px;
            color: #6ddfb0;
            font-weight: 700;
        }
        .service-order {
            text-decoration: none;
            color: #fff;
            background: linear-gradient(180deg, #d87973, #a84445);
            border-radius: 10px;
            padding: 11px 18px;
            font-weight: 900;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: .5px;
            white-space: nowrap;
            transition: opacity .2s, transform .2s;
            box-shadow: 0 6px 18px rgba(169,88,81,.3);
        }
        .service-order:hover {
            opacity: .88;
            transform: translateY(-1px);
        }

        @media (max-width: 680px) {
            .price-grid-local { grid-template-columns: 1fr; }
        }
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

                <!-- Обложка услуги -->
                <div class="service-cover">
                    <?php if (!empty($service['image'])): ?>
                        <img
                            src="uploads/<?= htmlspecialchars($service['image']) ?>"
                            alt="<?= htmlspecialchars($service['title']) ?>"
                            loading="lazy"
                        >
                    <?php else: ?>
                        <div class="service-cover-placeholder">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                <circle cx="8.5" cy="8.5" r="1.5"/>
                                <polyline points="21 15 16 10 5 21"/>
                            </svg>
                            <span>Обложка не загружена</span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Тело карточки -->
                <div class="service-body">
                    <h2><?= htmlspecialchars($service['title']) ?></h2>

                    <?php if (!empty(trim($service['description'] ?? ''))): ?>
                        <p><?= htmlspecialchars($service['description']) ?></p>
                    <?php endif; ?>

                    <?php
                        $features = array_filter(array_map('trim', explode('|', (string)($service['features'] ?? ''))));
                    ?>
                    <?php if (!empty($features)): ?>
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
                            Заказать
                        </a>
                    </div>
                </div>

            </article>
        <?php endforeach; ?>
    </section>
</main>
</body>
</html>