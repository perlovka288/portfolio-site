<?php
/**
 * Короткий приватный кэш браузера для «навигационных» страниц (главная, прайс, полезное, поддержка).
 *
 * Зачем: PHP по умолчанию шлёт «no-store/no-cache» — поэтому предзагрузка разделов (prefetch) не
 * помогала: браузер выбрасывал ответ и при клике грузил страницу заново (1–3 сек). Теперь ответ
 * можно положить в кэш на 20–60 секунд — переход по уже «прогретому» разделу открывается мгновенно
 * (прогрев делает assets/kostlim-nav.js).
 *
 * НЕ кэшируем: админку, профиль, заказ, любые запросы с параметрами (?tg_id=…, ?service=…),
 * POST-запросы, и сессию администратора (ему всегда нужны свежие данные после правок).
 * Отключить полностью: переменная окружения KUI_NO_PAGE_CACHE=1.
 */
if (!function_exists('kuiApplyCacheHeaders')) {
    function kuiApplyCacheHeaders(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent() || getenv('KUI_NO_PAGE_CACHE')) { return; }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { return; }
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if (strpos($uri, '?') !== false) { return; }
        if (!empty($_SESSION['admin_logged'])) { return; }

        $script = basename((string)parse_url((string)($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH));
        $ttl = ['index.php' => 20, 'price.php' => 60, 'useful.php' => 60, 'support.php' => 60][$script] ?? 0;
        if ($ttl <= 0) { return; }

        header_remove('Pragma');
        header('Cache-Control: private, max-age=' . $ttl);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT');
    }
}
