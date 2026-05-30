<?php
require_once __DIR__ . '/../config/db.php';

$sql = file_get_contents(__DIR__ . '/seed_prices_utf8.sql');
$pdo->exec($sql);

echo "Prices seeded\n";

foreach ($pdo->query("SELECT id, category_key, title, price_rub, price_uan FROM prices ORDER BY id") as $row) {
    echo "{$row['id']} | {$row['category_key']} | {$row['title']} | {$row['price_rub']}/{$row['price_uan']}\n";
}
