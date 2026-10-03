<?php
/**
 * Единое хранилище картинок сайта: Cloudinary (основное) + ImgBB (запасное). Работают ВМЕСТЕ — не принял один, пробуем другой.
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

if (!function_exists('kuiCfgSrc')) {
    /**
     * Значение настройки + откуда оно взято: 'env' (окружение Render) | 'admin' (таблица site_settings) | ''.
     * @return array{0:string,1:string}
     */
    function kuiCfgSrc(string $key, ?PDO $pdo = null): array
    {
        static $db = [];
        foreach ([getenv($key), $_SERVER[$key] ?? false, $_ENV[$key] ?? false] as $env) {
            if ($env !== false && $env !== null && trim((string)$env) !== '') { return [trim((string)$env), 'env']; }
        }
        if (!$pdo) { global $pdo; }
        if (!($pdo instanceof PDO)) { return ['', '']; }
        if (!array_key_exists($key, $db)) {
            $db[$key] = '';
            try {
                $st = $pdo->prepare("SELECT value FROM site_settings WHERE setting_key = ? LIMIT 1");
                $st->execute([$key]);
                $v = $st->fetchColumn();
                if ($v !== false) { $db[$key] = trim((string)$v); }
            } catch (Throwable $e) {}
        }
        return [$db[$key], $db[$key] !== '' ? 'admin' : ''];
    }
}

if (!function_exists('kuiCfg')) {
    /** Значение настройки: сначала окружение (Render), потом таблица site_settings (админка). */
    function kuiCfg(string $key, ?PDO $pdo = null): string
    {
        return kuiCfgSrc($key, $pdo)[0];
    }
}

if (!function_exists('kuiCfgFirst')) {
    /**
     * Первое непустое значение из нескольких возможных имён настройки (на случай опечаток в названии переменной).
     * @return array{0:string,1:string,2:string} [значение, источник, имя]
     */
    function kuiCfgFirst(array $names, ?PDO $pdo = null): array
    {
        foreach ($names as $n) {
            [$v, $src] = kuiCfgSrc($n, $pdo);
            if ($v !== '') { return [$v, $src, $n]; }
        }
        return ['', '', ''];
    }
}

if (!function_exists('cloudinaryCreds')) {
    /**
     * Данные Cloudinary из окружения/админки. Прощает типичные ошибки при вставке:
     *  • пробелы/кавычки/переводы строк вокруг значения;
     *  • в любое поле вставлена целая строка cloudinary://KEY:SECRET@CLOUD или «CLOUDINARY_URL=…»;
     *  • в cloud name вставлена ссылка вида https://res.cloudinary.com/<cloud>/… или «cloud_name=<cloud>»;
     *  • API key и API secret перепутаны местами (ключ — только цифры, секрет — буквы/цифры).
     * @return array{cloud:string,key:string,secret:string}|null
     */
    function cloudinaryCreds(?PDO $pdo = null): ?array
    {
        $clean = static function (string $v): string { return trim($v, " \t\n\r\0\x0B\"'`"); };
        // Принимаем и «правильные» имена, и частые варианты (CLOUDINARY_NAME, CLOUDINARY_KEY, CLOUDINARY_SECRET…)
        $cloudR = kuiCfgFirst(['CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_NAME', 'CLOUDINARY_CLOUD', 'CLOUD_NAME'], $pdo);
        $keyR   = kuiCfgFirst(['CLOUDINARY_API_KEY', 'CLOUDINARY_KEY', 'CLOUDINARY_APIKEY'], $pdo);
        $secR   = kuiCfgFirst(['CLOUDINARY_API_SECRET', 'CLOUDINARY_SECRET', 'CLOUDINARY_APISECRET'], $pdo);
        $cloud = $clean($cloudR[0]);
        $key   = $clean($keyR[0]);
        $sec   = $clean($secR[0]);
        $url   = $clean(kuiCfg('CLOUDINARY_URL', $pdo));

        // cloudinary://KEY:SECRET@CLOUD — может лежать в любом из полей
        foreach ([$url, $cloud, $key, $sec] as $cand) {
            if (preg_match('#cloudinary://([^:\s]+):([^@\s]+)@([A-Za-z0-9_-]+)#', $cand, $m)) {
                [$key, $sec, $cloud] = [$m[1], $m[2], $m[3]];
                break;
            }
        }
        // cloud name, вставленный как ссылка или «cloud_name=xxx»
        if ($cloud !== '') {
            if (preg_match('#res\.cloudinary\.com/([A-Za-z0-9_-]+)#', $cloud, $m)) { $cloud = $m[1]; }
            elseif (preg_match('#cloud_name\s*[=:]\s*([A-Za-z0-9_-]+)#i', $cloud, $m)) { $cloud = $m[1]; }
            elseif (preg_match('#^CLOUDINARY_CLOUD_NAME\s*=\s*(.+)$#i', $cloud, $m)) { $cloud = $clean($m[1]); }
        }
        // ключ и секрет перепутаны: у Cloudinary ключ — всегда число
        if ($key !== '' && $sec !== '' && !ctype_digit($key) && ctype_digit($sec)) { [$key, $sec] = [$sec, $key]; }

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
        $base = rtrim((string)(getenv('KUI_CLOUDINARY_BASE') ?: 'https://api.cloudinary.com'), '/');
        $mime = function_exists('mime_content_type') ? (string)@mime_content_type($path) : '';
        if (strpos($mime, 'image/') !== 0) { $mime = 'image/jpeg'; }

        // Сначала стандартная подпись SHA-1; если аккаунт требует SHA-256 («Invalid Signature») — повторяем с ней.
        foreach (['sha1', 'sha256'] as $algo) {
            $ts  = time();
            $toSign = "folder={$folder}&public_id={$publicId}" . ($algo === 'sha256' ? "&signature_algorithm=sha256" : '') . "&timestamp={$ts}";
            $sig = hash($algo, $toSign . $c['secret']);
            $fields = [
                'file' => new CURLFile($path, $mime, $publicId), 'api_key' => $c['key'], 'timestamp' => $ts,
                'signature' => $sig, 'folder' => $folder, 'public_id' => $publicId,
            ];
            if ($algo === 'sha256') { $fields['signature_algorithm'] = 'sha256'; }

            $ch = curl_init("{$base}/v1_1/" . rawurlencode($c['cloud']) . "/image/upload");
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => max(5, $timeout),
                CURLOPT_POSTFIELDS     => $fields,
            ]);
            $res  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);

            if ($res === false || $res === '') { $error = 'нет ответа от Cloudinary' . ($cerr ? " ({$cerr})" : ''); error_log("Cloudinary: $error"); return ''; }
            $data = json_decode($res, true);
            if (!empty($data['secure_url'])) { $error = null; return (string)$data['secure_url']; }
            $error = (string)($data['error']['message'] ?? ('HTTP ' . $code));
            error_log("Cloudinary HTTP $code ($algo): $error");
            if (stripos($error, 'signature') === false) { break; }   // повтор имеет смысл только при проблеме с подписью
        }
        // человеческие подсказки к самым частым ошибкам
        if (stripos($error, 'cloud_name') !== false || stripos($error, 'Invalid cloud') !== false) { $error .= ' — проверь Cloud name (он виден на главной странице Cloudinary Dashboard)'; }
        elseif (stripos($error, 'api_key') !== false || stripos($error, 'Unknown API key') !== false) { $error .= ' — проверь API key'; }
        elseif (stripos($error, 'signature') !== false) { $error .= ' — скорее всего неверный API secret (скопируй заново, без пробелов)'; }
        elseif (stripos($error, 'too large') !== false) { $error .= ' — у бесплатного Cloudinary лимит 10 МБ на картинку'; }
        return '';
    }
}

if (!function_exists('kuiPrepareImage')) {
    /**
     * Тяжёлые картинки (PNG 4K и т.п.) Cloudinary (лимит 10 МБ) и ImgBB отклоняют, из-за чего «превью не грузится».
     * Если файл больше ~8 МБ или шире 3000 px — уменьшаем через GD во временный файл.
     * @return array{0:string,1:bool} [путь, это временный файл?]
     */
    function kuiPrepareImage(string $path): array
    {
        $size = @filesize($path);
        if (!$size || !function_exists('imagecreatefromstring') || !function_exists('getimagesize')) { return [$path, false]; }
        $info = @getimagesize($path);
        if (!$info || empty($info[0]) || empty($info[1])) { return [$path, false]; }
        [$w, $h] = [(int)$info[0], (int)$info[1]];
        if ($size <= 8 * 1024 * 1024 && max($w, $h) <= 3000) { return [$path, false]; }
        if ($size > 64 * 1024 * 1024 || ($w * $h) > 60000000) { return [$path, false]; }   // не рискуем памятью

        $img = @imagecreatefromstring((string)file_get_contents($path));
        if (!$img) { return [$path, false]; }
        $k = min(1.0, 3000 / max($w, $h));
        $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
        $dst = imagecreatetruecolor($nw, $nh);
        $hasAlpha = ($info[2] ?? 0) === IMAGETYPE_PNG || ($info[2] ?? 0) === IMAGETYPE_WEBP || ($info[2] ?? 0) === IMAGETYPE_GIF;
        if ($hasAlpha) { imagealphablending($dst, false); imagesavealpha($dst, true); imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127)); }
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);

        $tmp = tempnam(sys_get_temp_dir(), 'kimg_');
        $ok = false;
        if ($hasAlpha) {
            $ok = imagepng($dst, $tmp, 7);
            if ($ok && filesize($tmp) > 9 * 1024 * 1024) { $ok = false; }   // PNG всё ещё тяжёлый — пробуем JPEG ниже
        }
        if (!$ok) {
            if ($hasAlpha) { $bg = imagecreatetruecolor($nw, $nh); imagefill($bg, 0, 0, imagecolorallocate($bg, 17, 17, 17)); imagecopy($bg, $dst, 0, 0, 0, 0, $nw, $nh); imagedestroy($dst); $dst = $bg; }
            $ok = imagejpeg($dst, $tmp, 88);
        }
        imagedestroy($dst);
        if (!$ok || !is_file($tmp) || filesize($tmp) === 0) { @unlink($tmp); return [$path, false]; }
        return [$tmp, true];
    }
}

if (!function_exists('imageStoreConfigured')) {
    function imageStoreConfigured(?PDO $pdo = null): bool
    {
        require_once __DIR__ . '/imgbb.php';
        return cloudinaryCreds($pdo) !== null || count(imgbbKeys($pdo)) > 0;
    }
}

if (!function_exists('imageStoreStatus')) {
    /** Что настроено сейчас (для страницы диагностики admin/storage_test.php). */
    function imageStoreStatus(?PDO $pdo = null): array
    {
        require_once __DIR__ . '/imgbb.php';
        $c = cloudinaryCreds($pdo);
        return [
            'cloudinary' => $c !== null,
            'cloud_name' => $c['cloud'] ?? '',
            'imgbb_keys' => count(imgbbKeys($pdo)),
            'pref'       => strtolower(kuiCfg('IMAGE_STORAGE', $pdo)) ?: 'auto',
        ];
    }
}

if (!function_exists('imageStoreUpload')) {
    /**
     * Главная функция: загрузить картинку в основное хранилище (Cloudinary), при отказе — в запасное (ImgBB).
     * Обе площадки работают вместе: если одна не приняла файл, сразу пробуем другую. '' = не удалось ни там, ни там.
     */
    function imageStoreUpload(string $path, string $name = 'image', ?PDO $pdo = null, ?string &$error = null, int $timeout = 60, int $tries = 2): string
    {
        require_once __DIR__ . '/imgbb.php';
        $error = null;
        $pref  = strtolower(kuiCfg('IMAGE_STORAGE', $pdo));
        $hasC  = cloudinaryCreds($pdo) !== null;
        $hasI  = count(imgbbKeys($pdo)) > 0;
        if (!$hasC && !$hasI) { $error = 'не настроено хранилище картинок (Cloudinary или ImgBB)'; error_log('ImageStore: no storage configured'); return ''; }

        [$use, $isTmp] = kuiPrepareImage($path);          // слишком тяжёлые картинки уменьшаем
        $order = ($pref === 'imgbb') ? ['imgbb', 'cloudinary'] : ['cloudinary', 'imgbb'];   // auto/cloudinary → Cloudinary первым
        // тумблер «ImgBB как запасное»: выключен (0) и Cloudinary настроен → ImgBB не трогаем
        if ($pref !== 'imgbb' && $hasC && kuiCfg('IMGBB_FALLBACK', $pdo) === '0') { $order = ['cloudinary']; }
        $errors = [];
        $result = '';
        foreach ($order as $svc) {
            if ($svc === 'cloudinary' && $hasC) {
                $e = null; $u = cloudinaryUploadImage($use, $name, $pdo, $e, $timeout);
                if ($u !== '') { $result = $u; break; }
                $errors[] = 'Cloudinary: ' . $e;
            } elseif ($svc === 'imgbb' && $hasI) {
                $e = null; $u = imgbbUploadRaw($use, $name, $pdo, $e, $timeout, $tries);
                if ($u !== '') { $result = $u; break; }
                $errors[] = 'ImgBB: ' . $e;
            }
        }
        if ($isTmp) { @unlink($use); }
        if ($result !== '') { $error = null; return $result; }
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

if (!function_exists('kuiLocalMissing')) {
    /**
     * true — в базе лежит ИМЯ локального файла (не ссылка), а самого файла на сервере уже нет
     * (папка uploads/ на Render стирается при деплое). Такое превью лучше заменить заглушкой,
     * чем показывать битую картинку.
     */
    function kuiLocalMissing(string $val): bool
    {
        if ($val === '' || preg_match('#^(https?:)?//#i', $val) || strpos($val, 'data:') === 0) { return false; }
        $file = dirname(__DIR__) . '/uploads/' . basename($val);
        return !is_file($file);
    }
}
