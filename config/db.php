<?php
class Database {
    private static $pdo = null;

    public static function getConnection(): PDO {
        if (self::$pdo === null) {
            // Подключение берётся ТОЛЬКО из окружения хостинга (никаких зашитых в код хостов).
            // Поддерживаются оба варианта: одна строка DATABASE_URL / POSTGRES_URL
            // (Render, Railway, Supabase, Neon, Heroku…) или отдельные DB_HOST / DB_NAME / DB_USER / DB_PASS / DB_PORT.
            $host = getenv('DB_HOST') ?: '';
            $db   = getenv('DB_NAME') ?: '';
            $user = getenv('DB_USER') ?: '';
            $pass = getenv('DB_PASS') ?: '';
            $port = getenv('DB_PORT') ?: '5432';
            $url  = getenv('DATABASE_URL') ?: (getenv('POSTGRES_URL') ?: '');
            if ($host === '' && $url !== '') {
                $u = parse_url($url);
                if ($u && !empty($u['host'])) {
                    $host = $u['host'];
                    $port = (string)($u['port'] ?? $port);
                    $db   = ltrim((string)($u['path'] ?? ''), '/');
                    $user = rawurldecode((string)($u['user'] ?? ''));
                    $pass = rawurldecode((string)($u['pass'] ?? ''));
                }
            }
            if ($host === '' || $db === '') {
                throw new RuntimeException('Не заданы параметры БД: укажите DATABASE_URL или DB_HOST/DB_NAME/DB_USER/DB_PASS в переменных окружения хостинга.');
            }
            // SSL: по умолчанию require (как было). Для приватной сети хостинга (*.internal, localhost)
            // шифрование обычно не нужно и не поддерживается — там автоматически prefer. Переопределить: DB_SSLMODE=disable|prefer|require.
            $sslmode = getenv('DB_SSLMODE') ?: ((preg_match('/(\.internal$|^localhost$|^127\.|^[a-z0-9-]+$)/i', $host)) ? 'prefer' : 'require');

            self::$pdo = new PDO(
                "pgsql:host=$host;port=$port;dbname=$db;sslmode=$sslmode",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => true,
                    // Постоянное соединение: не открываем новое TLS-подключение к Neon на КАЖДЫЙ запрос
                    // (это 0.3–0.8 сек на странице). Отключить: DB_PERSISTENT=0 в окружении.
                    PDO::ATTR_PERSISTENT         => (getenv('DB_PERSISTENT') !== '0'),
                    PDO::ATTR_TIMEOUT            => 10,
                ]
            );
            self::$pdo->exec("SET NAMES 'UTF8'");
        }
        return self::$pdo;
    }
}

// Обратная совместимость — $pdo доступен везде как раньше
$pdo = Database::getConnection();
// Значения из админки («Ключи и API») подкладываются в окружение — их увидит любой getenv() (includes/settings_bridge.php)
require_once __DIR__ . '/../includes/settings_bridge.php';
kuiSettingsBridge($pdo);