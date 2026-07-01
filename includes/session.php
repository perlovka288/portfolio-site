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
    // Без явного lifetime кука сессии истекает при закрытии браузера/WebView
    // Telegram — из-за этого при каждом новом заходе слетала привязка TG
    // (аватарка, лайки "с одного профиля") пока человек не проходил
    // auto-link заново. Держим куку живой год.
    $oneYear = 60 * 60 * 24 * 365;
    session_set_cookie_params([
        'lifetime' => $oneYear,
        'path'     => '/',
        'domain'   => $p['domain'],
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => $isSecure ? 'None' : 'Lax', // None требует Secure
    ]);
    session_start();
    // session_set_cookie_params влияет только на будущие session_start(),
    // а сама PHP session garbage collection может убить данные раньше —
    // продлеваем и gc_maxlifetime на бэкенде.
    @ini_set('session.gc_maxlifetime', (string)$oneYear);
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

    $photo = _fetchTgAvatarForSite($pdo, (string)$tg_id);

    // 2. Обновляем/создаём запись в tg_links чтобы заказы привязывались
    try {
        // Проверяем есть ли уже запись для этой сессии
        $ex = $pdo->prepare("SELECT id FROM tg_links WHERE session_id=? ORDER BY id DESC LIMIT 1");
        $ex->execute([$sid]);
        $existRow = $ex->fetch(PDO::FETCH_ASSOC);

        if ($existRow) {
            // Обновляем существующую
            $pdo->prepare("UPDATE tg_links SET tg_id=CAST(? AS VARCHAR), tg_username=?, tg_first_name=?, tg_photo_url=COALESCE(NULLIF(?, ''), tg_photo_url), linked=TRUE WHERE session_id=?")
                ->execute([(string)$tg_id, $uname ? ltrim($uname, '@') : '', $fname, $photo, $sid]);
        } else {
            // Создаём новую — сначала генерируем site_code
            $code = strtoupper(substr(md5(uniqid($sid.$tg_id, true)), 0, 6));
            $pdo->prepare("INSERT INTO tg_links (site_code, session_id, tg_id, tg_username, tg_first_name, tg_photo_url, linked, created_at)
                           VALUES (?, ?, CAST(? AS VARCHAR), ?, ?, ?, TRUE, NOW())
                           ON CONFLICT (site_code) DO NOTHING")
                ->execute([$code, $sid, (string)$tg_id, $uname ? ltrim($uname, '@') : '', $fname, $photo]);
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

/**
 * Ленивое обновление аватарки: если tg_photo_url пуст или это временная
 * ссылка api.telegram.org (протухает через час) — тянем свежую аватарку
 * из Telegram, заливаем в Cloudinary и сохраняем в tg_links для ЛЮБОЙ
 * страницы (используется и в index.php, и в profile.php — раньше это
 * умел делать только profile.php, из-за чего на главной аватарка
 * не подгружалась, пока человек не заходил в профиль).
 */
function ensureTgAvatarFresh(PDO $pdo, string $sid, string $tg_id, string $currentPhoto): string {
    if ($tg_id === '') return $currentPhoto;

    $needsRefresh = ($currentPhoto === '' || str_starts_with($currentPhoto, 'https://api.telegram.org'));
    if (!$needsRefresh) return $currentPhoto;

    try {
        $botToken = getenv('BOT_TOKEN') ?: getenv('TELEGRAM_BOT_TOKEN') ?: '8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg';

        $ch = curl_init("https://api.telegram.org/bot{$botToken}/getUserProfilePhotos?user_id={$tg_id}&limit=1");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_SSL_VERIFYPEER => false]);
        $photosResp = curl_exec($ch);
        curl_close($ch);
        $photosData = $photosResp ? json_decode($photosResp, true) : null;
        $fileId = $photosData['result']['photos'][0][0]['file_id'] ?? '';
        if ($fileId === '') return $currentPhoto;

        $ch = curl_init("https://api.telegram.org/bot{$botToken}/getFile?file_id={$fileId}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_SSL_VERIFYPEER => false]);
        $fileResp = curl_exec($ch);
        curl_close($ch);
        $fileData = $fileResp ? json_decode($fileResp, true) : null;
        $filePath = $fileData['result']['file_path'] ?? '';
        if ($filePath === '') return $currentPhoto;

        $tgUrl = "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
        $newPhotoUrl = $tgUrl; // fallback — прямая ссылка TG (протухнет, но лучше чем ничего)

        $tmpFile = tempnam(sys_get_temp_dir(), 'tgava_');
        $ch = curl_init($tgUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_SSL_VERIFYPEER => false]);
        $imgData = curl_exec($ch);
        curl_close($ch);

        if ($imgData !== false && strlen((string)$imgData) > 100) {
            file_put_contents($tmpFile, $imgData);
            $cloudName   = getenv('CLOUDINARY_CLOUD_NAME') ?: 'ds6buwmpj';
            $cloudKey    = getenv('CLOUDINARY_API_KEY')    ?: '146292462848227';
            $cloudSecret = getenv('CLOUDINARY_API_SECRET') ?: 'Kx5xzQOIbjzLa4bWUUl11IBx0Ok';
            $ts  = time();
            $sig = sha1("folder=avatars&public_id=tg_{$tg_id}&timestamp={$ts}{$cloudSecret}");
            $cch = curl_init("https://api.cloudinary.com/v1_1/{$cloudName}/image/upload");
            curl_setopt_array($cch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_POSTFIELDS     => [
                    'file'      => new CURLFile($tmpFile),
                    'api_key'   => $cloudKey,
                    'timestamp' => $ts,
                    'signature' => $sig,
                    'folder'    => 'avatars',
                    'public_id' => "tg_{$tg_id}",
                ],
            ]);
            $cResp = curl_exec($cch);
            curl_close($cch);
            @unlink($tmpFile);
            $cData = $cResp ? json_decode($cResp, true) : null;
            if (!empty($cData['secure_url'])) {
                $newPhotoUrl = $cData['secure_url'];
            }
        }

        $pdo->prepare("UPDATE tg_links SET tg_photo_url = ? WHERE session_id = ?")->execute([$newPhotoUrl, $sid]);
        return $newPhotoUrl;
    } catch (Throwable $e) {
        error_log('ensureTgAvatarFresh error: ' . $e->getMessage());
        return $currentPhoto;
    }
}

function _fetchTgAvatarForSite(PDO $pdo, string $tg_id): string {
    if ($tg_id === '') return '';
    try {
        $old = $pdo->prepare("SELECT tg_photo_url FROM tg_links WHERE tg_id = ? AND tg_photo_url IS NOT NULL AND tg_photo_url <> '' ORDER BY id DESC LIMIT 1");
        $old->execute([$tg_id]);
        $cached = (string)($old->fetchColumn() ?: '');
        if ($cached !== '' && !str_starts_with($cached, 'https://api.telegram.org')) {
            return $cached;
        }
    } catch (Throwable $e) {}

    $botToken = getenv('BOT_TOKEN') ?: getenv('TELEGRAM_BOT_TOKEN') ?: '';
    if ($botToken === '') return '';

    try {
        $ch = curl_init("https://api.telegram.org/bot{$botToken}/getUserProfilePhotos?user_id={$tg_id}&limit=1");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_SSL_VERIFYPEER => false]);
        $photosResp = curl_exec($ch);
        curl_close($ch);
        $photosData = $photosResp ? json_decode($photosResp, true) : null;
        $fileId = $photosData['result']['photos'][0][0]['file_id'] ?? '';
        if ($fileId === '') return '';

        $ch = curl_init("https://api.telegram.org/bot{$botToken}/getFile?file_id={$fileId}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_SSL_VERIFYPEER => false]);
        $fileResp = curl_exec($ch);
        curl_close($ch);
        $fileData = $fileResp ? json_decode($fileResp, true) : null;
        $filePath = $fileData['result']['file_path'] ?? '';
        if ($filePath === '') return '';

        $tgUrl = "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) ?: 'jpg';
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) $ext = 'jpg';

        $dir = dirname(__DIR__) . '/uploads/avatars/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $localName = 'tg_' . preg_replace('/\D+/', '', $tg_id) . '_' . time() . '.' . $ext;
        $localPath = $dir . $localName;

        $img = '';
        $ch = curl_init($tgUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_SSL_VERIFYPEER => false]);
        $img = (string)curl_exec($ch);
        curl_close($ch);
        if ($img !== '' && strlen($img) > 100 && @file_put_contents($localPath, $img) !== false) {
            return 'uploads/avatars/' . $localName;
        }

        return $tgUrl;
    } catch (Throwable $e) {
        error_log('fetch tg avatar error: ' . $e->getMessage());
        return '';
    }
}