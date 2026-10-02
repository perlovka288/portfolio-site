<?php
/**
 * Мост «Ключи и API (админка) → окружение процесса».
 *
 * ПРИЧИНА, по которой ключи из админки «не работали»: вкладка «Ключи и API» сохраняла значения в таблицу
 * site_settings, но читал их только сам admin/index.php (через getSetting()). Все остальные файлы
 * (order.php, bot.php, ai_widget, resources, загрузчики картинок…) берут ключи через getenv() —
 * поэтому введённое в админке до них просто не доходило.
 *
 * Теперь при каждом подключении к БД (config/db.php) значения из site_settings подкладываются в
 * окружение процесса (putenv/$_ENV/$_SERVER). Любой код, который делает getenv('КЛЮЧ'), видит значение из админки.
 *   • Пустые значения игнорируются (пусто = «использовать окружение Render»).
 *   • Ключи доступа/входа (BOT_TOKEN, ADMIN_TELEGRAM_ID, Turnstile) НЕ перекрывают окружение — только
 *     подставляются, если в окружении пусто. Так опечатка в админке не сможет заблокировать вход.
 *   • Наборы ключей KEYSET_* (несколько ключей, активный/резервные) превращаются в обычные переменные.
 */
if (!function_exists('kuiSettingsBridge')) {
    function kuiSettingsBridge(PDO $pdo): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        $rows = [];
        try {
            foreach ($pdo->query("SELECT setting_key, value FROM site_settings") as $r) { $rows[(string)$r['setting_key']] = (string)$r['value']; }
        } catch (Throwable $e) { return; }          // таблицы ещё нет — ничего страшного

        $protect = ['BOT_TOKEN', 'TELEGRAM_BOT_TOKEN', 'ADMIN_TELEGRAM_ID', 'ADMIN_ID', 'TURNSTILE_SECRET_KEY', 'TURNSTILE_SITE_KEY'];
        $put = function (string $k, string $v) use ($protect): void {
            if ($v === '') { return; }
            $cur = getenv($k);
            if (in_array($k, $protect, true) && $cur !== false && trim((string)$cur) !== '') { return; }
            putenv($k . '=' . $v); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        };

        // 1) наборы ключей: {"active":0,"keys":[{"v":"…","on":true,"label":"…"}]}
        $sets = ['KEYSET_IMGBB' => ['IMGBB_API_KEY', true], 'KEYSET_GEMINI' => ['GEMINI_API_KEY', false], 'KEYSET_YT' => ['YT_API_KEY', false]];
        foreach ($sets as $setKey => [$envName, $joinAll]) {
            if (empty($rows[$setKey])) { continue; }
            $d = json_decode($rows[$setKey], true);
            if (!is_array($d) || empty($d['keys']) || !is_array($d['keys'])) { continue; }
            $keys = []; $active = (int)($d['active'] ?? 0);
            foreach ($d['keys'] as $i => $k) {
                $v = trim((string)($k['v'] ?? ''));
                if ($v === '' || (array_key_exists('on', $k) && !$k['on'])) { continue; }
                if ($i === $active) { array_unshift($keys, $v); } else { $keys[] = $v; }   // активный — первым
            }
            if (!$keys) { continue; }
            $put($envName, $joinAll ? implode(',', array_unique($keys)) : $keys[0]);
            if ($setKey === 'KEYSET_IMGBB') { foreach (['IMGBB_API_KEYS', 'IMGBB_KEYS', 'IMGBB_KEY'] as $x) { putenv($x . '='); } }
        }
        // 2) обычные поля (и слоты IMGBB_API_KEY2/3, которые админка сохраняла раньше)
        foreach ($rows as $k => $v) {
            if (strpos($k, 'KEYSET_') === 0 || $k === 'ai_system_prompt' || $k === 'IMGBB_FALLBACK' || $k === 'IMAGE_STORAGE') { continue; }
            if (isset($sets['KEYSET_IMGBB']) && !empty($rows['KEYSET_IMGBB']) && strpos($k, 'IMGBB_') === 0) { continue; }
            if (isset($rows['KEYSET_GEMINI']) && $rows['KEYSET_GEMINI'] !== '' && $k === 'GEMINI_API_KEY') { continue; }
            if (isset($rows['KEYSET_YT']) && $rows['KEYSET_YT'] !== '' && $k === 'YT_API_KEY') { continue; }
            if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $k)) { $put($k, trim($v)); }
        }
        // служебные флаги читает kuiCfg() сам
        foreach (['IMGBB_FALLBACK', 'IMAGE_STORAGE'] as $k) { if (isset($rows[$k]) && $rows[$k] !== '') { putenv($k . '=' . $rows[$k]); $_ENV[$k] = $rows[$k]; } }
    }
}
