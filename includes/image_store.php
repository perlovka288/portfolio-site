<?php
/**
 * Единое хранилище файлов сайта: ТОЛЬКО Cloudinary (ImgBB полностью убран — он блокирует IP Render).
 *
 * Переменные окружения Render (или админка → «Ключи и API»):
 *   CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY, CLOUDINARY_API_SECRET
 *   (или одной строкой CLOUDINARY_URL=cloudinary://API_KEY:API_SECRET@CLOUD_NAME)
 *
 * Загрузка идёт на https://api.cloudinary.com/v1_1/{cloud}/auto/upload (resource_type=auto):
 * картинки, гифки, видео, архивы, документы — до 100 МБ за один запрос.
 * Подпись: sha1("folder=..&public_id=..&timestamp=.." . API_SECRET).
 *
 * Старые имена imgbbUpload()/uploadToImgBB()/uploadReceiptToImgBB() сохранены как обёртки
 * (чтобы не переписывать 20 файлов) — теперь они все ведут сюда.
 */

if (!defined('CLOUDINARY_MAX_BYTES')) { define('CLOUDINARY_MAX_BYTES', 100 * 1024 * 1024); }

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
                require_once __DIR__ . '/kui_cache.php';
                $v = kuiSettingGet($pdo, $key);
                if ($v !== null) { $db[$key] = trim($v); }
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

if (!function_exists('cloudinaryExtFromMime')) {
    function cloudinaryExtFromMime(string $mime): string
    {
        static $map = [
            'application/zip' => 'zip', 'application/x-zip-compressed' => 'zip', 'application/pdf' => 'pdf',
            'application/x-rar-compressed' => 'rar', 'application/vnd.rar' => 'rar', 'application/x-7z-compressed' => '7z',
            'application/postscript' => 'ai', 'image/vnd.adobe.photoshop' => 'psd', 'application/x-photoshop' => 'psd',
            'text/plain' => 'txt', 'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ];
        return $map[strtolower($mime)] ?? '';
    }
}

if (!function_exists('cloudinaryUploadFile')) {
    /**
     * Загрузка ЛЮБОГО файла (до 100 МБ) в Cloudinary через /auto/upload.
     * Возвращает secure_url (прямая https-ссылка) или '' (причина — в $error).
     * $origName — исходное имя файла: нужно, чтобы у архивов/документов сохранилось расширение в ссылке.
     */
    function cloudinaryUploadFile(string $path, string $name = 'file', ?PDO $pdo = null, ?string &$error = null, int $timeout = 300, string $folder = 'kostlim', string $origName = ''): string
    {
        $error = null;
        $c = cloudinaryCreds($pdo);
        if (!$c) { $error = 'Cloudinary не настроен (CLOUDINARY_CLOUD_NAME / API_KEY / API_SECRET)'; error_log('Cloudinary: not configured'); return ''; }
        if (!is_file($path) || !is_readable($path)) { $error = 'файл не найден'; return ''; }
        $size = (int)@filesize($path);
        if ($size <= 0) { $error = 'пустой файл'; return ''; }
        if ($size > CLOUDINARY_MAX_BYTES) { $error = 'файл больше 100 МБ'; return ''; }

        @set_time_limit(0);
        $mime = function_exists('mime_content_type') ? (string)@mime_content_type($path) : '';
        if ($mime === '') { $mime = 'application/octet-stream'; }
        $isMedia = (bool)preg_match('#^(image|video|audio)/#', $mime);

        $publicId = preg_replace('/[^A-Za-z0-9_-]+/', '_', $name) . '_' . bin2hex(random_bytes(4));
        if (!$isMedia) {
            // «raw»-файлы (zip/pdf/psd…) Cloudinary отдаёт по public_id как есть — расширение должно быть в нём
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if ($ext === '' || !preg_match('/^[a-z0-9]{1,8}$/', $ext)) { $ext = cloudinaryExtFromMime($mime); }
            if ($ext !== '') { $publicId .= '.' . $ext; }
        }
        $base = rtrim((string)(getenv('KUI_CLOUDINARY_BASE') ?: 'https://api.cloudinary.com'), '/');
        $url  = $base . '/v1_1/' . rawurlencode($c['cloud']) . '/auto/upload';

        foreach (['sha1', 'sha256'] as $algo) {     // если аккаунт требует SHA-256 («Invalid Signature») — повтор
            $ts     = time();
            $toSign = "folder={$folder}&public_id={$publicId}" . ($algo === 'sha256' ? '&signature_algorithm=sha256' : '') . "&timestamp={$ts}";
            $fields = [
                'file' => new CURLFile($path, $mime, $publicId), 'api_key' => $c['key'], 'timestamp' => $ts,
                'signature' => hash($algo, $toSign . $c['secret']), 'folder' => $folder, 'public_id' => $publicId,
            ];
            if ($algo === 'sha256') { $fields['signature_algorithm'] = 'sha256'; }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => max(30, $timeout),   // большие файлы: по умолчанию 5 минут
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
            if (stripos($error, 'signature') === false) { break; }
        }
        if (stripos($error, 'cloud_name') !== false || stripos($error, 'Invalid cloud') !== false) { $error .= ' — проверь Cloud name (он на главной странице Cloudinary Dashboard)'; }
        elseif (stripos($error, 'api_key') !== false || stripos($error, 'Unknown API key') !== false) { $error .= ' — проверь API key'; }
        elseif (stripos($error, 'signature') !== false) { $error .= ' — скорее всего неверный API secret (скопируй заново, без пробелов)'; }
        elseif (stripos($error, 'too large') !== false || stripos($error, 'File size') !== false) { $error .= ' — превышен лимит размера файла на вашем тарифе Cloudinary'; }
        return '';
    }
}

if (!function_exists('cloudinaryUploadImage')) {
    /** Историческое имя: теперь та же загрузка через /auto/upload. */
    function cloudinaryUploadImage(string $path, string $name = 'image', ?PDO $pdo = null, ?string &$error = null, int $timeout = 60, string $folder = 'kostlim'): string
    {
        return cloudinaryUploadFile($path, $name, $pdo, $error, $timeout, $folder);
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

if (!function_exists('kuiCompressForWeb')) {
    /**
     * Сжатие картинок для показа на сайте (портфолио, прайс, аватарки, чеки).
     * Зачем: оригинал 1920×1080 весит 1–4 МБ; Cloudinary при КАЖДОМ новом размере/формате заново
     * «собирает» превью из этого тяжёлого оригинала (первый заход — медленно), а там, где превью не
     * применяются, браузер качает оригинал целиком. После сжатия оригинал ≈ 200–400 КБ, визуально без потерь.
     *   • длинная сторона > 2400 px → уменьшаем до 2400 (1920×1080 не трогаем);
     *   • фото без прозрачности → JPEG 85, progressive; PNG с прозрачностью → остаётся PNG;
     *   • GIF/SVG и файлы < 300 КБ не трогаем; результат берём, только если он минимум на 15% легче.
     * @return array{0:string,1:bool} [путь, это временный файл?]
     */
    function kuiCompressForWeb(string $path): array
    {
        $size = (int)@filesize($path);
        if ($size < 300 * 1024 || !function_exists('imagecreatefromstring') || !function_exists('getimagesize')) { return [$path, false]; }
        $info = @getimagesize($path);
        if (!$info || empty($info[0]) || empty($info[1])) { return [$path, false]; }
        $type = (int)($info[2] ?? 0);
        if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) { return [$path, false]; }   // GIF/WebP/прочее — как есть
        [$w, $h] = [(int)$info[0], (int)$info[1]];
        if (($w * $h) > 40000000 || $size > 48 * 1024 * 1024) { return [$path, false]; }           // не рискуем памятью

        $img = @imagecreatefromstring((string)@file_get_contents($path));
        if (!$img) { return [$path, false]; }

        // Поворот по EXIF (фото с телефона), иначе после пересохранения картинка «ляжет на бок»
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $o = (int)($exif['Orientation'] ?? 1);
            if ($o === 3) { $r = imagerotate($img, 180, 0); }
            elseif ($o === 6) { $r = imagerotate($img, -90, 0); }
            elseif ($o === 8) { $r = imagerotate($img, 90, 0); }
            if (!empty($r)) { imagedestroy($img); $img = $r; $w = imagesx($img); $h = imagesy($img); }
        }

        $k  = min(1.0, 2400 / max($w, $h));
        $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));

        // Есть ли реальная прозрачность (только для PNG): смотрим уменьшенную копию 48×48
        $hasAlpha = false;
        if ($type === IMAGETYPE_PNG) {
            $probe = imagecreatetruecolor(48, 48);
            imagealphablending($probe, false); imagesavealpha($probe, true);
            imagefill($probe, 0, 0, imagecolorallocatealpha($probe, 0, 0, 0, 0));
            imagecopyresampled($probe, $img, 0, 0, 0, 0, 48, 48, $w, $h);
            for ($y = 0; $y < 48 && !$hasAlpha; $y++) {
                for ($x = 0; $x < 48; $x++) { if (((imagecolorat($probe, $x, $y) >> 24) & 0x7F) > 8) { $hasAlpha = true; break; } }
            }
            imagedestroy($probe);
        }

        $dst = imagecreatetruecolor($nw, $nh);
        if ($hasAlpha) { imagealphablending($dst, false); imagesavealpha($dst, true); imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127)); }
        else { imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); }
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);

        $tmp = tempnam(sys_get_temp_dir(), 'kweb_');
        if ($hasAlpha) { $ok = imagepng($dst, $tmp, 9); }
        else { imageinterlace($dst, true); $ok = imagejpeg($dst, $tmp, 85); }
        imagedestroy($dst);

        $newSize = (int)@filesize($tmp);
        if (!$ok || $newSize <= 0 || $newSize > $size * 0.85) { @unlink($tmp); return [$path, false]; }   // выгоды нет — оставляем оригинал
        return [$tmp, true];
    }
}

if (!function_exists('imageStoreConfigured')) {
    function imageStoreConfigured(?PDO $pdo = null): bool { return cloudinaryCreds($pdo) !== null; }
}

if (!function_exists('imageStoreStatus')) {
    /** Что настроено сейчас (для страницы диагностики admin/storage_test.php). */
    function imageStoreStatus(?PDO $pdo = null): array
    {
        $c = cloudinaryCreds($pdo);
        return ['cloudinary' => $c !== null, 'cloud_name' => $c['cloud'] ?? '', 'imgbb_keys' => 0, 'pref' => 'cloudinary'];
    }
}

if (!function_exists('imageStoreUpload')) {
    /**
     * Главная функция сайта: загрузить файл в Cloudinary (auto/upload, до 100 МБ).
     * Тяжёлые картинки (>8 МБ или >3000 px) предварительно ужимаются. '' = не удалось (причина — в $error).
     * $tries — сколько раз повторить при сетевой ошибке/5xx.
     */
    function imageStoreUpload(string $path, string $name = 'image', ?PDO $pdo = null, ?string &$error = null, int $timeout = 60, int $tries = 2, string $folder = 'kostlim', string $origName = ''): string
    {
        $error = null;
        if (cloudinaryCreds($pdo) === null) { $error = 'не настроен Cloudinary (CLOUDINARY_CLOUD_NAME / API_KEY / API_SECRET)'; error_log('ImageStore: Cloudinary not configured'); return ''; }
        [$use, $isTmp] = kuiPrepareImage($path);
        // Картинки для показа на сайте (папка по умолчанию) — сжимаем; архивы клиентов (orders/…) остаются как есть
        if ($folder === 'kostlim') {
            [$web, $webTmp] = kuiCompressForWeb($use);
            if ($webTmp) { if ($isTmp) { @unlink($use); } $use = $web; $isTmp = true; }
        }
        $result = '';
        for ($try = 1; $try <= max(1, $tries); $try++) {
            $e = null;
            $result = cloudinaryUploadFile($use, $name, $pdo, $e, $timeout, $folder, $origName);
            if ($result !== '') { break; }
            $error = (string)$e;
            if (stripos($error, 'нет ответа') === false) { break; }   // повторяем только сетевые сбои
        }
        if ($isTmp) { @unlink($use); }
        if ($result !== '') { $error = null; }
        return $result;
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
    function kuiImgSrcset(string $url, array $widths = [480, 900]): string
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
