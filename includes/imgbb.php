<?php
/**
 * Единая работа с ImgBB для ВСЕГО сайта.
 *
 * Почему файл появился: раньше в 5 местах (admin/index.php, admin/profile.php, admin_index.php,
 * order_flow.php, resources_lib.php) были свои копии загрузки, и все читали ключи только из
 * IMGBB_API_KEY / IMGBB_API_KEY2 / IMGBB_API_KEY3 по ОТДЕЛЬНОСТИ. Если в окружении ключи записаны
 * через запятую в одной переменной («ключ1,ключ2,ключ3») — сайт считал это ОДНИМ ключом,
 * ImgBB его отклонял, а код молча сохранял картинку на локальный диск. Диск контейнера стирается
 * при каждом деплое — отсюда «превью пропадают после пуша».
 *
 * Теперь ключи берутся отовсюду и режутся по , ; | пробелам и переводам строк:
 *   IMGBB_API_KEY, IMGBB_API_KEY2..IMGBB_API_KEY9, IMGBB_API_KEYS, IMGBB_KEYS, IMGBB_KEY
 *   (+ те же имена из таблицы настроек админки, если есть getSetting()).
 * Загрузка перебирает ключи по очереди, при ошибке пишет причину в error_log (Render → Logs).
 */

if (!function_exists('imgbbSplitKeys')) {
    /** «k1, k2;k3\nk4» → ['k1','k2','k3','k4'] (кавычки и пробелы убираются). */
    function imgbbSplitKeys(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;|]+/', $raw) ?: [] as $k) {
            $k = trim($k, " \t\n\r\0\x0B\"'`");
            if ($k !== '') { $out[] = $k; }
        }
        return $out;
    }
}

if (!function_exists('imgbbKeys')) {
    /** Все ключи ImgBB без дублей, в порядке приоритета. */
    function imgbbKeys(?PDO $pdo = null): array
    {
        $names = ['IMGBB_API_KEY', 'IMGBB_API_KEYS', 'IMGBB_KEYS', 'IMGBB_KEY'];
        for ($i = 2; $i <= 9; $i++) { $names[] = 'IMGBB_API_KEY' . $i; }

        $keys = [];
        foreach ($names as $n) {
            $vals = [];
            if ($pdo && function_exists('getSetting')) {
                try { $vals[] = (string)getSetting($pdo, $n, ''); } catch (Throwable $e) {}
            }
            if ($pdo && function_exists('getResSetting')) {
                try { $vals[] = (string)getResSetting($pdo, $n, ''); } catch (Throwable $e) {}
            }
            $env = getenv($n);
            $vals[] = ($env === false) ? '' : (string)$env;
            if (!empty($_ENV[$n])) { $vals[] = (string)$_ENV[$n]; }
            foreach ($vals as $v) {
                foreach (imgbbSplitKeys($v) as $k) { $keys[$k] = true; }
            }
        }
        return array_keys($keys);
    }
}

if (!function_exists('imgbbUploadRaw')) {
    /**
     * Загружает файл именно на ImgBB (перебор ключей, логи). Возвращает https-URL или ''.
     * Для остального кода используй imgbbUpload()/imageStoreUpload() — они выберут Cloudinary или ImgBB.
     */
    function imgbbUploadRaw(string $tmpPath, string $name = 'image', ?PDO $pdo = null, ?string &$error = null, int $timeout = 60, int $tries = 2): string
    {
        $error = null;
        if (!is_file($tmpPath) || !is_readable($tmpPath)) { $error = 'файл не найден'; error_log("ImgBB: file not found ($tmpPath)"); return ''; }
        $keys = imgbbKeys($pdo);
        if (!$keys) { $error = 'не задан ни один IMGBB_API_KEY'; error_log('ImgBB: no API keys set'); return ''; }

        $b64 = base64_encode((string)file_get_contents($tmpPath));
        foreach ($keys as $idx => $apiKey) {
            for ($try = 1; $try <= max(1, $tries); $try++) {            // 2 попытки на ключ: сеть/5xx бывают разовыми
                $ch = curl_init('https://api.imgbb.com/1/upload');
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT        => max(5, $timeout),
                    CURLOPT_POSTFIELDS     => ['key' => $apiKey, 'image' => $b64, 'name' => $name],
                ]);
                $res  = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $cerr = curl_error($ch);
                curl_close($ch);

                if ($res === false || $res === '') {
                    $error = 'нет ответа от ImgBB' . ($cerr ? " ($cerr)" : '');
                    error_log("ImgBB key#" . ($idx + 1) . " try $try: $error");
                    continue;                               // повтор на том же ключе
                }
                $data = json_decode($res, true);
                $url  = $data['data']['url'] ?? ($data['data']['display_url'] ?? '');
                if (!empty($data['success']) && $url !== '') { $error = null; return (string)$url; }

                $msg   = (string)($data['error']['message'] ?? ('HTTP ' . $code));
                $error = $msg;
                error_log("ImgBB key#" . ($idx + 1) . " (…" . substr($apiKey, -4) . ") HTTP $code: $msg");
                if ($code >= 500) { continue; }             // сбой на их стороне — повторим
                break;                                      // ключ неверный/лимит — сразу следующий ключ
            }
        }
        return '';
    }
}

if (!function_exists('imgbbUploadData')) {
    /** То же, но из строки с байтами картинки (скачанная аватарка и т.п.). */
    function imgbbUploadData(string $binary, string $name = 'image', ?PDO $pdo = null, ?string &$error = null, int $timeout = 20): string
    {
        if ($binary === '') { $error = 'пустые данные'; return ''; }
        $tmp = tempnam(sys_get_temp_dir(), 'ibb_');
        file_put_contents($tmp, $binary);
        $url = imgbbUpload($tmp, $name, $pdo, $error, $timeout, 1);
        @unlink($tmp);
        return $url;
    }
}

if (!function_exists('imgbbUpload')) {
    /**
     * Историческое имя (его вызывают все загрузки сайта). Теперь это «загрузить картинку в хранилище»:
     * Cloudinary, если настроен, иначе ImgBB — см. includes/image_store.php.
     */
    function imgbbUpload(string $tmpPath, string $name = 'image', ?PDO $pdo = null, ?string &$error = null, int $timeout = 60, int $tries = 2): string
    {
        require_once __DIR__ . '/image_store.php';
        return imageStoreUpload($tmpPath, $name, $pdo, $error, $timeout, $tries);
    }
}
