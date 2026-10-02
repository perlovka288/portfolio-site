<?php
/**
 * Единое хранилище картинок сайта.  ГЛАВНОЕ: достаточно ОДНОГО сервиса — Cloudinary (ImgBB не нужен).
 *
 * Как включить Cloudinary (любой из способов):
 *   А) Render → Environment:  CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY, CLOUDINARY_API_SECRET
 *      (или одной строкой CLOUDINARY_URL=cloudinary://API_KEY:API_SECRET@CLOUD_NAME)
 *   Б) Админка → «Ключи и API» → блок «☁️ Cloudinary» (значения хранятся в БД, деплой их не стирает)
 *
 * Выбор сервиса (необязательно): IMAGE_STORAGE = auto (по умолчанию) | cloudinary | imgbb
 *   auto = Cloudinary, если он настроен; иначе ImgBB. Если основной сервис не принял файл,
 *   а второй настроен — пробуем второй. Если настроен только один — работает он один.
 *
 * Все старые вызовы imgbbUpload()/uploadToImgBB()/uploadReceiptToImgBB() теперь идут сюда.
 */

if (!function_exists('kuiCfg')) {
    /** Значение настройки: сначала окружение (Render), потом таблица site_settings (админка). */
    function kuiCfg(string $key, ?PDO $pdo = null): string
    {
        static $db = [];
        $env = getenv($key);
        if ($env !== false && trim((string)$env) !== '') { return trim((string)$env); }
        if (!$pdo) { global $pdo; }
        if (!($pdo instanceof PDO)) { return ''; }
        if (!array_key_exists($key, $db)) {
            $db[$key] = '';
            try {
                $st = $pdo->prepare("SELECT value FROM site_settings WHERE setting_key = ? LIMIT 1");
                $st->execute([$key]);
                $v = $st->fetchColumn();
                if ($v !== false) { $db[$key] = trim((string)$v); }
            } catch (Throwable $e) {}
        }
        return $db[$key];
    }
}

if (!function_exists('cloudinaryCreds')) {
    /** @return array{cloud:string,key:string,secret:string}|null */
    function cloudinaryCreds(?PDO $pdo = null): ?array
    {
        $cloud = kuiCfg('CLOUDINARY_CLOUD_NAME', $pdo);
        $key   = kuiCfg('CLOUDINARY_API_KEY', $pdo);
        $sec   = kuiCfg('CLOUDINARY_API_SECRET', $pdo);
        $url   = kuiCfg('CLOUDINARY_URL', $pdo);
        if (($cloud === '' || $key === '' || $sec === '') && preg_match('#^cloudinary://([^:]+):([^@]+)@(.+)$#', $url, $m)) {
            [$key, $sec, $cloud] = [$m[1], $m[2], $m[3]];
        }
        return ($cloud !== '' && $key !== '' && $sec !== '') ? ['cloud' => $cloud, 'key' => $key, 'secret' => $sec] : null;
    }
}

if (!function_exists('cloudinaryUploadImage')) {
    /** Загрузка на Cloudinary. Возвращает постоянный https-URL или '' (причина — в $error). */
    function cloudinaryUploadImage(string $path, string $name = 'image', ?PDO $pdo = null, ?string &$error = null, int $timeout = 40, string $folder = 'kostlim'): string
    {
        $c = cloudinaryCreds($pdo);
        if (!$c) { $error = 'Cloudinary не настроен'; return ''; }
        if (!is_file($path) || !is_readable($path)) { $error = 'файл не найден'; return ''; }

        $publicId = preg_replace('/[^A-Za-z0-9_-]+/', '_', $name) . '_' . bin2hex(random_bytes(4));
        $ts = time();
        // подпись: параметры по алфавиту + секрет (file/api_key/resource_type в подпись не входят)
        $sig = sha1("folder={$folder}&public_id={$publicId}&timestamp={$ts}" . $c['secret']);
        $base = rtrim((string)(getenv('KUI_CLOUDINARY_BASE') ?: 'https://api.cloudinary.com'), '/');

        $ch = curl_init("{$base}/v1_1/{$c['cloud']}/image/upload");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => max(5, $timeout),
            CURLOPT_POSTFIELDS     => [
                'file' => new CURLFile($path), 'api_key' => $c['key'], 'timestamp' => $ts,
                'signature' => $sig, 'folder' => $folder, 'public_id' => $publicId,
            ],
        ]);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($res === false || $res === '') { $error = 'нет ответа от Cloudinary' . ($cerr ? " ({$cerr})" : ''); error_log("Cloudinary: $error"); return ''; }
        $data = json_decode($res, true);
        if (!empty($data['secure_url'])) { return (string)$data['secure_url']; }
        $error = (string)($data['error']['message'] ?? ('HTTP ' . $code));
        error_log("Cloudinary HTTP $code: $error");
        return '';
    }
}

if (!function_exists('imageStoreConfigured')) {
    function imageStoreConfigured(?PDO $pdo = null): bool
    {
        require_once __DIR__ . '/imgbb.php';
        return cloudinaryCreds($pdo) !== null || count(imgbbKeys($pdo)) > 0;
    }
}

if (!function_exists('imageStoreUpload')) {
    /** Главная функция: загрузить картинку в основное хранилище (с запасным). '' = не удалось. */
    function imageStoreUpload(string $path, string $name = 'image', ?PDO $pdo = null, ?string &$error = null, int $timeout = 60, int $tries = 2): string
    {
        require_once __DIR__ . '/imgbb.php';
        $error = null;
        $pref  = strtolower(kuiCfg('IMAGE_STORAGE', $pdo));
        $hasC  = cloudinaryCreds($pdo) !== null;
        $hasI  = count(imgbbKeys($pdo)) > 0;
        if (!$hasC && !$hasI) { $error = 'не настроено хранилище картинок (Cloudinary или ImgBB)'; error_log('ImageStore: no storage configured'); return ''; }

        $order = ($pref === 'imgbb') ? ['imgbb', 'cloudinary'] : ['cloudinary', 'imgbb'];   // auto/cloudinary → Cloudinary первым
        // переключатель «ImgBB как запасной» (админка → Ключи и API): выключен — ImgBB не используем, если есть Cloudinary
        if ($hasC && $pref !== 'imgbb' && kuiCfg('IMGBB_FALLBACK', $pdo) === '0') { $hasI = false; }
        $errors = [];
        foreach ($order as $svc) {
            if ($svc === 'cloudinary' && $hasC) {
                $e = null; $u = cloudinaryUploadImage($path, $name, $pdo, $e, $timeout);
                if ($u !== '') { $error = null; return $u; }
                $errors[] = 'Cloudinary: ' . $e;
            } elseif ($svc === 'imgbb' && $hasI) {
                $e = null; $u = imgbbUploadRaw($path, $name, $pdo, $e, $timeout, $tries);
                if ($u !== '') { $error = null; return $u; }
                $errors[] = 'ImgBB: ' . $e;
            }
        }
        $error = implode('; ', $errors);
        return '';
    }
}

if (!function_exists('kuiImgOpt')) {
    /** Cloudinary-ссылка → автоформат (AVIF/WebP) + авто-качество + ограничение ширины. Остальные ссылки — как есть. */
    function kuiImgOpt(string $url, int $w = 900): string
    {
        if (strpos($url, 'res.cloudinary.com') === false || strpos($url, '/upload/') === false) { return $url; }
        if (preg_match('#/upload/[^/]*(f_auto|q_auto|w_\d+|e_blur)#', $url)) { return $url; }   // уже оптимизирована
        return preg_replace('#/upload/#', '/upload/f_auto,q_auto,c_limit,w_' . max(100, $w) . '/', $url, 1);
    }
    /** srcset для адаптивных размеров (экономит трафик на телефоне). '' — если не Cloudinary. */
    function kuiImgSrcset(string $url, array $widths = [480, 800, 1200]): string
    {
        if (kuiImgOpt($url, 100) === $url) { return ''; }
        return implode(', ', array_map(fn($w) => kuiImgOpt($url, $w) . ' ' . $w . 'w', $widths));
    }
    /** Крошечная размытая заглушка (~1 КБ), показывается, пока грузится настоящая картинка. */
    function kuiImgBlur(string $url): string
    {
        if (strpos($url, 'res.cloudinary.com') === false || strpos($url, '/upload/') === false) { return ''; }
        if (preg_match('#/upload/[^/]*(f_auto|q_auto|w_\d+|e_blur)#', $url)) { return ''; }
        return preg_replace('#/upload/#', '/upload/w_24,q_20,e_blur:600,f_auto/', $url, 1);
    }
}
