<?php
class Database {
    private static $pdo = null;

    public static function getConnection(): PDO {
        if (self::$pdo === null) {
            $host = getenv('DB_HOST') ?: 'ep-broad-mode-ape68llm-pooler.c-7.us-east-1.aws.neon.tech';
            $db   = getenv('DB_NAME') ?: 'neondb';
            $user = getenv('DB_USER') ?: 'neondb_owner';
            $pass = getenv('DB_PASS') ?: '';
            $port = getenv('DB_PORT') ?: '5432';

            self::$pdo = new PDO(
                "pgsql:host=$host;port=$port;dbname=$db;sslmode=require",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => true,
                ]
            );
            self::$pdo->exec("SET NAMES 'UTF8'");
        }
        return self::$pdo;
    }
}

// Обратная совместимость — $pdo доступен везде как раньше
$pdo = Database::getConnection();