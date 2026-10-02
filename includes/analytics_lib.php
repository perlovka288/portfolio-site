<?php
/**
 * Собственная лёгкая аналитика сайта (без внешних сервисов и без хранения личных данных):
 *   site_visits — просмотры страниц (анонимный идентификатор браузера kui_vid, путь, время);
 *   site_online — «сейчас на сайте»: браузер шлёт пульс раз в 30 сек, онлайн = пульс был не позже 70 сек назад.
 * Пишет track.php, читает admin/analytics_api.php → виджеты в админке («Обзор»).
 * Время хранится в UTC, дни/месяцы считаются по Киеву.
 */
if (!function_exists('ensureAnalyticsSchema')) {
    function ensureAnalyticsSchema__run(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_visits (
            id BIGSERIAL PRIMARY KEY,
            vid VARCHAR(32) NOT NULL,
            path VARCHAR(160) NOT NULL DEFAULT '/',
            created_at TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'UTC'))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_site_visits_created ON site_visits (created_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_site_visits_vid ON site_visits (vid, created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_online (
            vid VARCHAR(32) PRIMARY KEY,
            path VARCHAR(160) NOT NULL DEFAULT '/',
            last_seen TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'UTC'))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_site_online_seen ON site_online (last_seen)");
    }
    function ensureAnalyticsSchema(PDO $pdo): void
    {
        if (!function_exists('kuiSchemaDone')) { require_once __DIR__ . '/schema_once.php'; }
        if (kuiSchemaDone('ensureAnalyticsSchema')) { return; }
        ensureAnalyticsSchema__run($pdo);
        kuiSchemaMark('ensureAnalyticsSchema');
    }
}

if (!function_exists('kuiAnalyticsRecord')) {
    /** Записать просмотр ($hb=false) или пульс ($hb=true) и обновить «онлайн». */
    function kuiAnalyticsRecord(PDO $pdo, string $vid, string $path, bool $hb): void
    {
        if (!$hb) {   // тот же человек, та же страница в пределах 8 сек — один просмотр (двойной клик, bfcache)
            $st = $pdo->prepare("SELECT 1 FROM site_visits WHERE vid = ? AND path = ? AND created_at > (NOW() AT TIME ZONE 'UTC') - INTERVAL '8 seconds' LIMIT 1");
            $st->execute([$vid, $path]);
            if (!$st->fetchColumn()) {
                $pdo->prepare("INSERT INTO site_visits (vid, path) VALUES (?, ?)")->execute([$vid, $path]);
            }
        }
        $pdo->prepare("INSERT INTO site_online (vid, path, last_seen) VALUES (?, ?, NOW() AT TIME ZONE 'UTC')
                       ON CONFLICT (vid) DO UPDATE SET path = EXCLUDED.path, last_seen = EXCLUDED.last_seen")->execute([$vid, $path]);
        if (mt_rand(1, 150) === 1) {   // изредка чистим старое
            $pdo->exec("DELETE FROM site_online WHERE last_seen < (NOW() AT TIME ZONE 'UTC') - INTERVAL '1 day'");
            $pdo->exec("DELETE FROM site_visits WHERE created_at < (NOW() AT TIME ZONE 'UTC') - INTERVAL '800 days'");
        }
    }
}

if (!function_exists('kuiAnalyticsOnline')) {
    /** @return array{count:int,top:array<int,array{path:string,n:int}>} */
    function kuiAnalyticsOnline(PDO $pdo): array
    {
        $win = "last_seen > (NOW() AT TIME ZONE 'UTC') - INTERVAL '70 seconds'";
        $count = (int)$pdo->query("SELECT COUNT(*) FROM site_online WHERE $win")->fetchColumn();
        $top = [];
        foreach ($pdo->query("SELECT path, COUNT(*) n FROM site_online WHERE $win GROUP BY path ORDER BY n DESC, path LIMIT 4") as $r) {
            $top[] = ['path' => (string)$r['path'], 'n' => (int)$r['n']];
        }
        return ['count' => $count, 'top' => $top];
    }
}

if (!function_exists('kuiAnalyticsReport')) {
    /** Отчёт за период: график + итоги + сравнение с предыдущим таким же периодом. $range: 7d | 30d | 12m */
    function kuiAnalyticsReport(PDO $pdo, string $range = '7d'): array
    {
        $tzName = 'Europe/Kiev';                                  // «Киев»: старое имя работает и в PHP, и в PostgreSQL
        try { $tz = new DateTimeZone($tzName); } catch (Throwable $e) {
            try { $tzName = 'Europe/Kyiv'; $tz = new DateTimeZone($tzName); } catch (Throwable $e2) { $tzName = 'UTC'; $tz = new DateTimeZone('UTC'); }
        }
        if ($tzName !== 'UTC') {                                   // та же зона должна быть и в БД, иначе считаем по UTC
            try {
                $chk = $pdo->prepare("SELECT 1 FROM pg_timezone_names WHERE name = ? LIMIT 1");
                $chk->execute([$tzName]);
                if (!$chk->fetchColumn()) { $tzName = 'UTC'; $tz = new DateTimeZone('UTC'); }
            } catch (Throwable $e) { $tzName = 'UTC'; $tz = new DateTimeZone('UTC'); }
        }
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $tz);
        $monthly = ($range === '12m');
        $n = $monthly ? 12 : ($range === '30d' ? 30 : 7);

        $first = $monthly ? $now->modify('first day of this month')->setTime(0, 0)->modify('-11 months')
                          : $now->setTime(0, 0)->modify('-' . ($n - 1) . ' days');
        $end   = $monthly ? $now->modify('first day of next month')->setTime(0, 0) : $now->setTime(0, 0)->modify('+1 day');
        $prevFirst = $monthly ? $first->modify('-12 months') : $first->modify('-' . $n . ' days');

        $u = fn(DateTimeImmutable $d): string => $d->setTimezone($utc)->format('Y-m-d H:i:s');
        $local  = "(created_at AT TIME ZONE 'UTC') AT TIME ZONE '" . $tzName . "'";
        $bucket = $monthly ? "date_trunc('month', $local)::date" : "($local)::date";

        $st = $pdo->prepare("SELECT $bucket AS d, COUNT(*) AS v, COUNT(DISTINCT vid) AS u
                             FROM site_visits WHERE created_at >= ? AND created_at < ? GROUP BY 1 ORDER BY 1");
        $st->execute([$u($first), $u($end)]);
        $byDay = [];
        foreach ($st as $r) { $byDay[(string)$r['d']] = ['v' => (int)$r['v'], 'u' => (int)$r['u']]; }

        $series = [];
        $cur = $first;
        for ($i = 0; $i < $n; $i++) {
            $key = $cur->format('Y-m-d');
            $series[] = ['label' => $monthly ? $cur->format('m.Y') : $cur->format('d.m'), 'date' => $key,
                         'visits' => $byDay[$key]['v'] ?? 0, 'unique' => $byDay[$key]['u'] ?? 0];
            $cur = $monthly ? $cur->modify('+1 month') : $cur->modify('+1 day');
        }

        $tot = function (DateTimeImmutable $a, DateTimeImmutable $b) use ($pdo, $u): array {
            $s = $pdo->prepare("SELECT COUNT(*) v, COUNT(DISTINCT vid) u FROM site_visits WHERE created_at >= ? AND created_at < ?");
            $s->execute([$u($a), $u($b)]);
            $r = $s->fetch(PDO::FETCH_ASSOC) ?: ['v' => 0, 'u' => 0];
            $orders = 0;
            try {
                $o = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE created_at >= ? AND created_at < ?");
                $o->execute([$u($a), $u($b)]);
                $orders = (int)$o->fetchColumn();
            } catch (Throwable $e) {}
            $uniq = (int)$r['u'];
            return ['visits' => (int)$r['v'], 'unique' => $uniq, 'orders' => $orders, 'conversion' => $uniq > 0 ? round($orders * 100 / $uniq, 2) : 0.0];
        };
        $cur = $tot($first, $end);
        $prev = $tot($prevFirst, $first);
        $delta = function ($c, $p): ?float { return ($p > 0) ? round(($c - $p) * 100 / $p, 2) : null; };

        return [
            'range'  => $range, 'series' => $series, 'totals' => $cur, 'prev' => $prev,
            'delta'  => ['visits' => $delta($cur['visits'], $prev['visits']), 'unique' => $delta($cur['unique'], $prev['unique']),
                         'conversion' => ($prev['conversion'] > 0) ? round($cur['conversion'] - $prev['conversion'], 2) : null],
        ];
    }
}
