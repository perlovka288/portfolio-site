<?php
require_once __DIR__ . '/../config/db.php';

$sqlFile = __DIR__ . '/migrate_appeals_to_messages.sql';
if (!file_exists($sqlFile)) {
    echo "Migration file not found: $sqlFile\n";
    exit(1);
}

$sql = file_get_contents($sqlFile);
if ($sql === false) {
    echo "Unable to read migration file\n";
    exit(1);
}

try {
    // Execute entire SQL script
    $pdo->beginTransaction();
    $pdo->exec($sql);
    $pdo->commit();
    echo "Migration executed successfully.\n";
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(2);
}

// Quick sanity check: show counts
try {
    $c1 = $pdo->query("SELECT count(*) FROM appeals")->fetchColumn();
    $c2 = $pdo->query("SELECT count(*) FROM appeals_messages")->fetchColumn();
    echo "Appeals: $c1, Appeals messages: $c2\n";
} catch (Exception $e) {
    // ignore
}
