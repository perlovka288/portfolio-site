<?php
/**
 * Запуск сессии с правильными параметрами куков для Safari/iOS.
 *
 * Safari блокирует куки без SameSite=None + Secure при переходах
 * из внешних приложений (Telegram → сайт).
 *
 * ВАЖНО для Render/Heroku/любого reverse-proxy:
 * Apache внутри контейнера видит HTTP, но реальный запрос — HTTPS.
 * Определяем это через X-Forwarded-Proto.
 */
function startSafeSession(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    // На Render HTTPS терминируется на прокси — смотрим X-Forwarded-Proto
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
             || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on');

    $p = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $p['lifetime'],
        'path'     => '/',
        'domain'   => $p['domain'],
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => $isSecure ? 'None' : 'Lax', // None требует Secure
    ]);
    session_start();
}

startSafeSession();

/**
 * AUTO-LINK: Обработка ?tg_token=... при переходе с Telegram.
 * Вызывается при каждом запросе — если токен есть в URL, привязываем TG к сессии.
 * Работает прозрачно — клиент ничего не замечает.
 */
function processTgAutoLink(PDO $pdo): void {
    $token = trim($_GET['tg_token'] ?? $_SESSION['_tg_token_pending'] ?? '');
    if ($token === '') return;

    // Сохраняем токен в сессию чтобы работало даже если редирект убрал GET параметр
    $_SESSION['_tg_token_pending'] = $token;

    // Если уже привязан — не делаем ничего
    if (!empty($_SESSION['_tg_linked'])) return;

    $sid = session_id();

    try {
        // Создаём таблицу tg_auto_links если вдруг нет
        $pdo->exec("CREATE TABLE IF NOT EXISTS tg_auto_links (
            id            SERIAL PRIMARY KEY,
            token         VARCHAR(64) NOT NULL UNIQUE,
            tg_id         BIGINT NOT NULL,
            tg_username   VARCHAR(120) DEFAULT '',
            tg_first_name VARCHAR(120) DEFAULT '',
            tg_last_name  VARCHAR(120) DEFAULT '',
            used          BOOLEAN DEFAULT FALSE,
            created_at    TIMESTAMP DEFAULT NOW(),
            expires_at    TIMESTAMP DEFAULT NOW() + INTERVAL '72 hours'
        )");

        // Fallback: прямой tg_id в токене
        if (str_starts_with($token, 'tgid_')) {
            $tg_id = (int)substr($token, 5);
            if ($tg_id > 0) {
                _saveTgToSession($pdo, $sid, $tg_id, '', '');
            }
            return;
        }

        // Полноценный токен
        $stmt = $pdo->prepare("SELECT tg_id, tg_username, tg_first_name
                               FROM tg_auto_links
                               WHERE token=? AND expires_at > NOW()
                               LIMIT 1");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        _saveTgToSession($pdo, $sid, (int)$row['tg_id'], $row['tg_username'], $row['tg_first_name']);

    } catch (Throwable $e) {
        // Тихо игнорируем — не ломаем сайт из-за TG
        error_log('TG autolink error: ' . $e->getMessage());
    }
}

function _saveTgToSession(PDO $pdo, string $sid, int $tg_id, string $uname, string $fname): void {
    // 1. Сохраняем в сессию
    $_SESSION['tg_chat_id']   = $tg_id;
    $_SESSION['tg_username']  = $uname;
    $_SESSION['_tg_linked']   = true;

    // 2. Обновляем/создаём запись в tg_links чтобы заказы привязывались
    try {
        // Проверяем есть ли уже запись для этой сессии
        $ex = $pdo->prepare("SELECT id FROM tg_links WHERE session_id=? ORDER BY id DESC LIMIT 1");
        $ex->execute([$sid]);
        $existRow = $ex->fetch(PDO::FETCH_ASSOC);

        if ($existRow) {
            // Обновляем существующую
            $pdo->prepare("UPDATE tg_links SET tg_id=CAST(? AS VARCHAR), tg_username=?, tg_first_name=?, linked=TRUE WHERE session_id=?")
                ->execute([(string)$tg_id, $uname ? ltrim($uname, '@') : '', $fname, $sid]);
        } else {
            // Создаём новую — сначала генерируем site_code
            $code = strtoupper(substr(md5(uniqid($sid.$tg_id, true)), 0, 6));
            $pdo->prepare("INSERT INTO tg_links (site_code, session_id, tg_id, tg_username, tg_first_name, linked, created_at)
                           VALUES (?, ?, CAST(? AS VARCHAR), ?, ?, TRUE, NOW())
                           ON CONFLICT (site_code) DO NOTHING")
                ->execute([$code, $sid, (string)$tg_id, $uname ? ltrim($uname, '@') : '', $fname]);
        }

        // 3. Привязываем client_chat_id к заказам где указан username
        if ($uname !== '') {
            $pdo->prepare("UPDATE orders SET client_chat_id=?
                           WHERE (client_chat_id IS NULL OR client_chat_id='')
                             AND (telegram=? OR telegram=? OR telegram=? OR telegram=?)")
                ->execute([
                    (string)$tg_id,
                    '@'.$uname, $uname,
                    'https://t.me/'.$uname, 't.me/'.$uname,
                ]);
        }
    } catch (Throwable $e) {
        error_log('TG autolink saveTgToSession error: ' . $e->getMessage());
    }
}
