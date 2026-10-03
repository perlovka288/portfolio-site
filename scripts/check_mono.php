<?php
// scripts/check_mono.php — крон (Render Cron Job, каждую минуту): php scripts/check_mono.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/pay_lib.php';
ensurePaySchema($pdo);
echo "mono: " . checkMonobankAll($pdo) . " | donationalerts: " . checkDonationAlerts($pdo) . PHP_EOL;
