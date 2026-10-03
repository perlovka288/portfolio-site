<?php
/**
 * Совместимость со старым кодом. ImgBB полностью убран (он блокирует IP хостинга Render):
 * все загрузки теперь идут в Cloudinary — см. includes/image_store.php.
 * Имена функций оставлены, чтобы не переписывать вызовы по всему сайту.
 */
require_once __DIR__ . '/image_store.php';

if (!function_exists('imgbbSplitKeys')) {
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
/** ImgBB отключён — ключей нет. */
if (!function_exists('imgbbKeys')) { function imgbbKeys(?PDO $pdo = null): array { return []; } }

if (!function_exists('imgbbUploadRaw')) {
    function imgbbUploadRaw(string $tmpPath, string $name = 'image', ?PDO $pdo = null, ?string &$error = null, int $timeout = 60, int $tries = 2): string
    {
        $error = 'ImgBB отключён — используется Cloudinary';
        return '';
    }
}

if (!function_exists('imgbbUpload')) {
    /** Загрузить файл в хранилище (Cloudinary). Возвращает https-ссылку или ''. */
    function imgbbUpload(string $tmpPath, string $name = 'image', ?PDO $pdo = null, ?string &$error = null, int $timeout = 60, int $tries = 2): string
    {
        return imageStoreUpload($tmpPath, $name, $pdo, $error, max($timeout, 60), $tries);
    }
}

if (!function_exists('imgbbUploadData')) {
    /** То же, но из строки с байтами (скачанная аватарка и т.п.). */
    function imgbbUploadData(string $binary, string $name = 'image', ?PDO $pdo = null, ?string &$error = null, int $timeout = 20): string
    {
        if ($binary === '') { $error = 'пустые данные'; return ''; }
        $tmp = tempnam(sys_get_temp_dir(), 'cld_');
        file_put_contents($tmp, $binary);
        $url = imageStoreUpload($tmp, $name, $pdo, $error, max($timeout, 30), 1);
        @unlink($tmp);
        return $url;
    }
}
