<?php
/**
 * Мини-кеш для редко меняющихся данных (настройки сайта, аватар, список портфолио…).
 *
 * Зачем: БД у сайта «через океан» (каждый запрос ≈ 20–150 мс), а одна страница делала
 * 10–20 таких запросов подряд — в том числе по одним и тем же данным (site_settings читалась
 * 6–8 раз за заход). Теперь такие данные берутся из памяти/файла и обновляются раз в TTL секунд.
 *
 * Хранилище: APCu (если установлен), иначе файл в системной tmp-папке (в Docker/Render —
 * работает без каких-либо доп. настроек). Кеш живёт на одном контейнере; при перезапуске
 * контейнера просто заполняется заново.
 *
 *   $v = kuiCache('ключ', 30, function () use ($pdo) { return ...запрос...; });
 *   kuiCacheForget('ключ');          // сбросить после записи в админке
 *   kuiSettingsAll($pdo)             // вся таблица site_settings одним запросом (кеш 30 с)
 *   kuiSettingGet($pdo, 'ключ')      // одна настройка (null, если нет)
 */
if (!function_exists('kuiCache')) {
    function kuiCacheKey(string $key): string
    {
        return 'kui_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
    }

    function kuiCache(string $key, int $ttl, callable $fn)
    {
        $k = kuiCacheKey($key);

        if (function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            $v = apcu_fetch($k, $ok);
            if ($ok) { return $v; }
            $v = $fn();
            if ($v !== null) { apcu_store($k, $v, $ttl); }
            return $v;
        }

        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $k . '.cache';
        if (is_file($file) && (time() - (int)@filemtime($file)) < $ttl) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $d = @unserialize($raw, ['allowed_classes' => false]);
                if (is_array($d) && array_key_exists('v', $d)) { return $d['v']; }
            }
        }

        $v = $fn();                                   // исключение из $fn не глушим — пусть видит вызывающий
        if ($v !== null) {
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, serialize(['v' => $v])) !== false) { @rename($tmp, $file); }
        }
        return $v;
    }

    function kuiCacheForget(string $key): void
    {
        $k = kuiCacheKey($key);
        if (function_exists('apcu_delete')) { @apcu_delete($k); }
        @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . $k . '.cache');
    }

    /** Вся таблица site_settings: [ключ => значение]. Колонка называется value (старое имя — setting_value). */
    function kuiSettingsAll(PDO $pdo): array
    {
        $rows = kuiCache('settings_all', 30, function () use ($pdo) {
            foreach (['value', 'setting_value'] as $col) {
                try {
                    $out = [];
                    foreach ($pdo->query("SELECT setting_key, {$col} AS v FROM site_settings") as $r) {
                        $out[(string)$r['setting_key']] = (string)$r['v'];
                    }
                    return $out;
                } catch (Throwable $e) { /* пробуем следующее имя колонки */ }
            }
            return null;                               // таблицы нет — не кешируем
        });
        return is_array($rows) ? $rows : [];
    }

    function kuiSettingGet(PDO $pdo, string $key): ?string
    {
        $all = kuiSettingsAll($pdo);
        return array_key_exists($key, $all) ? $all[$key] : null;
    }

    /** Аватар владельца сайта (users.avatar), кеш 60 с. */
    function kuiAdminAvatar(PDO $pdo): string
    {
        $v = kuiCache('admin_avatar', 60, function () use ($pdo) {
            $r = $pdo->query("SELECT avatar FROM users LIMIT 1")->fetch();
            return (string)($r['avatar'] ?? '');
        });
        return (string)$v;
    }

    /** Сбросить кеш настроек — вызывать сразу после любой записи в site_settings. */
    function kuiSettingsForget(): void { kuiCacheForget('settings_all'); }
}
