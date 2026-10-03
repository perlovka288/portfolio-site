<?php
// geoip.php — отдаёт {"country":"UA","currency":"UAH"}
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/geo.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$cc = detectCountry();
echo json_encode(['country' => $cc, 'currency' => currencyForCountry($cc)]);
