<?php
/**
 * Короткий ПРИВАТНЫЙ кэш браузера для разделов сайта → переход между разделами мгновенный.
 *
 * Как это работает:
 *  1. Раньше PHP слал «no-store», и предзагрузка (prefetch) выбрасывалась — страница грузилась
 *     заново (1–5 сек). Теперь разделы можно держать в кэше 30–60 сек (+ «stale-while-revalidate»).
 *  2. assets/kostlim-nav.js при входе прогревает ВСЕ разделы в фоне (заголовок X-Kui-Warm: 1).
 *  3. «Vary: Cookie» + кука kui_v: любое действие человека (POST, привязка Telegram и т.п.) меняет
 *     куку → браузер считает закэшированные копии устаревшими и берёт свежие. Поэтому после
 *     отправки заказа/смены профиля старых данных не видно.
 *  4. Редиректы, ошибки и страницы админа никогда не кэшируются.
 *
 * Отключить полностью: переменная окружения KUI_NO_PAGE_CACHE=1.
 */
if (!function_exists('kuiCachePolicy')) {
    /** [ttl, stale-while-revalidate, разрешённые GET-параметры] */
    function kuiCachePolicy(string $script): ?array
    {
        static $p = [
            'index.php'      => [60, 120, []],
            'price.php'      => [60, 120, []],
            'useful.php'     => [60, 120, []],
            'support.php'    => [60, 120, []],
            'profile.php'    => [30, 0,   ['view']],
            'order.php'      => [30, 0,   ['service']],
            'privat_pak.php' => [30, 0,   []],
        ];
        return $p[$script] ?? null;
    }

    /** Сбросить кэш разделов у этого браузера (вызывать после любого изменения данных человека). */
    function kuiBumpVersion(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) { return; }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        @setcookie('kui_v', (string)round(microtime(true) * 1000), ['expires' => time() + 31536000, 'path' => '/', 'secure' => $secure, 'samesite' => $secure ? 'None' : 'Lax']);
    }

    function kuiApplyCacheHeaders(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent() || getenv('KUI_NO_PAGE_CACHE')) { return; }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'POST') { kuiBumpVersion(); return; }          // действие человека → кэш разделов устарел
        if ($method !== 'GET') { return; }
        if (!empty($_SESSION['admin_logged'])) { return; }              // админу всегда свежие данные

        $script = basename((string)parse_url((string)($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH));
        $pol = kuiCachePolicy($script);
        if (!$pol) { return; }
        foreach (array_keys($_GET) as $k) { if (!in_array($k, $pol[2], true)) { return; } }   // ?tg_id=, ?token= и т.п. — не кэшируем

        [$ttl, $swr] = $pol;
        header_remove('Pragma');
        header('Cache-Control: private, max-age=' . $ttl . ($swr > 0 ? ', stale-while-revalidate=' . $swr : ''));
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT');
        header('Vary: Cookie');

        // Редирект/ошибку кэшировать нельзя: перед отправкой заголовков проверяем итоговый ответ
        header_register_callback(function () {
            $bad = http_response_code() !== 200;
            foreach (headers_list() as $h) { if (stripos($h, 'Location:') === 0) { $bad = true; break; } }
            if ($bad) {
                header('Cache-Control: no-store, no-cache, must-revalidate');
                header('Pragma: no-cache');
                header_remove('Expires');
            }
        });
    }
}
