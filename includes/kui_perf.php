<?php
/**
 * Диагностика скорости страницы (только для админа): открой любую страницу с ?perf=1,
 * например  https://твой-сайт/index.php?perf=1  — внизу слева появится панель:
 * сколько всего PHP думал, сколько из этого ушло на базу, сколько запросов и какие самые медленные.
 * Подключается ТОЛЬКО при ?perf=1 + вход админа (см. config/db.php), обычных посетителей не касается.
 * В этом режиме соединение с БД не «постоянное», поэтому время подключения показывается отдельно.
 */
class KuiPerf
{
    public static float $connectMs = 0.0;
    /** @var array<int, array{0: float, 1: string}> */
    public static array $q = [];

    public static function add(string $sql, float $ms): void
    {
        self::$q[] = [$ms, $sql];
    }

    public static function boot(): void
    {
        register_shutdown_function([self::class, 'report']);
    }

    public static function report(): void
    {
        foreach (headers_list() as $h) {
            $low = strtolower($h);
            if (strpos($low, 'location:') === 0) { return; }
            if (strpos($low, 'content-type:') === 0 && strpos($low, 'text/html') === false) { return; }
        }
        $start = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $total = (microtime(true) - $start) * 1000;
        $count = count(self::$q);
        $dbMs  = 0.0;
        foreach (self::$q as $row) { $dbMs += $row[0]; }
        $other = max(0.0, $total - $dbMs - self::$connectMs);
        $avg   = $count > 0 ? $dbMs / $count : 0.0;

        $sorted = self::$q;
        usort($sorted, function ($a, $b) { return $b[0] <=> $a[0]; });
        $top = array_slice($sorted, 0, 8);

        if ($dbMs + self::$connectMs > $total * 0.5) {
            $verdict = 'Основное время уходит на БАЗУ (запросы и подключение).';
        } else {
            $verdict = 'База — не главное. Остальное: PHP, внешние запросы (Telegram/Cloudinary), сборка страницы.';
        }

        $rows = '';
        foreach ($top as $row) {
            $sql = preg_replace('/\s+/', ' ', trim($row[1]));
            if (function_exists('mb_substr')) { $sql = mb_substr($sql, 0, 110); } else { $sql = substr($sql, 0, 110); }
            $rows .= '<div style="display:flex;gap:10px;padding:3px 0;border-top:1px solid rgba(255,255,255,.07)"><b style="color:#fb923c;min-width:58px">'
                . number_format($row[0], 1) . ' мс</b><span style="color:#aaa;word-break:break-all">' . htmlspecialchars($sql) . '</span></div>';
        }

        echo '<div id="kui-perf" style="position:fixed;left:10px;bottom:10px;z-index:2147483647;max-width:min(560px,94vw);max-height:70vh;overflow:auto;'
            . 'background:#0d0d0d;color:#eee;border:1px solid rgba(249,115,22,.5);border-radius:14px;padding:12px 14px;font:12px/1.5 ui-monospace,Menlo,Consolas,monospace;box-shadow:0 12px 40px rgba(0,0,0,.7)">'
            . '<div style="display:flex;justify-content:space-between;gap:12px"><b style="color:#fb923c">PERF</b>'
            . '<a href="#" onclick="document.getElementById(\'kui-perf\').remove();return false" style="color:#888;text-decoration:none">закрыть</a></div>'
            . '<div>PHP всего: <b>' . number_format($total, 0) . ' мс</b></div>'
            . '<div>Подключение к БД: <b>' . number_format(self::$connectMs, 0) . ' мс</b> (в обычном режиме соединение переиспользуется)</div>'
            . '<div>Запросы к БД: <b>' . $count . ' шт, ' . number_format($dbMs, 0) . ' мс</b> (в среднем ' . number_format($avg, 1) . ' мс)</div>'
            . '<div>Остальное: <b>' . number_format($other, 0) . ' мс</b></div>'
            . '<div style="margin:6px 0;color:#fdba74">' . htmlspecialchars($verdict) . '</div>'
            . '<div style="color:#888">Самые медленные запросы:</div>' . $rows
            . '</div>';
    }
}

class KuiPerfStmt extends PDOStatement
{
    protected function __construct() {}

    public function execute(?array $params = null): bool
    {
        $t = microtime(true);
        $r = parent::execute($params);
        KuiPerf::add((string)$this->queryString, (microtime(true) - $t) * 1000);
        return $r;
    }
}

class KuiPerfPDO extends PDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $t = microtime(true);
        $r = ($fetchMode === null) ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        KuiPerf::add($query, (microtime(true) - $t) * 1000);
        return $r;
    }

    public function exec(string $statement): int|false
    {
        $t = microtime(true);
        $r = parent::exec($statement);
        KuiPerf::add($statement, (microtime(true) - $t) * 1000);
        return $r;
    }
}
