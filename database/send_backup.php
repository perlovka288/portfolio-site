<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../bot.php';

$token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
$admin_id = getenv('ADMIN_ID') ?: '';
if ($token === '') {
    echo "TELEGRAM_BOT_TOKEN not set in environment.\n";
}
if ($admin_id === '') {
    echo "ADMIN_ID not set in environment.\n";
}

try {
    adminSendDbBackup($pdo, $token, $admin_id);
    echo "Backup trigger executed. Check your Telegram for the file and check bot_debug.log for details.\n";
} catch (Throwable $e) {
    echo "Backup failed: " . $e->getMessage() . "\n";
}
