<?php
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'portfolio_db';
$user = getenv('DB_USER') ?: 'postgres';
$pass = getenv('DB_PASS') ?: '';
$port = getenv('DB_PORT') ?: '5432';

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$db",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
        ]
    );
    $pdo->exec("SET NAMES 'UTF8'");
} catch (PDOException $e) {
    die('DB Error: ' . $e->getMessage());
}