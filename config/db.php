<?php
$host = getenv('DB_HOST') ?: 'sql113.infinityfree.com';
$db   = getenv('DB_NAME') ?: 'if0_42054781_portfolio_db';
$user = getenv('DB_USER') ?: 'if0_42054781';
$pass = getenv('DB_PASS') ?: 'zK0B2jMfGj1MX';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user, $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
        ]
    );
} catch (PDOException $e) {
    die('DB Error: ' . $e->getMessage());
}