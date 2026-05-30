<?php
require_once __DIR__ . '/../config/db.php';

$categories = [
    ['preview', 'Превью YouTube', 1280, 720, 0, 10],
    ['avatar', 'Аватарки', 1024, 1024, 0, 20],
    ['banner', 'Шапки и баннеры', 1590, 400, 0, 30],
    ['design', 'Оформление: шапка + ава', 1590, 400, 1, 40],
];

$stmt = $pdo->prepare("
    INSERT INTO portfolio_categories (category_key, title, width_px, height_px, is_design, sort_order)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        title = VALUES(title),
        width_px = VALUES(width_px),
        height_px = VALUES(height_px),
        is_design = VALUES(is_design),
        sort_order = VALUES(sort_order)
");

foreach ($categories as $category) {
    $stmt->execute($category);
}

echo "Portfolio categories seeded\n";
