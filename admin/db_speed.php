<?php
/**
 * Диагностика скорости базы: сколько миллисекунд занимает ОДИН запрос из PHP-сервера до БД.
 * Открывать только админу: /admin/db_speed.php (запускай на боевом сайте).
 *
 * Ориентиры: до 5 мс — сервер и БД в одном регионе (отлично); 15–40 мс — соседние регионы;
 * 80–150 мс — «через океан»: страница с 10–20 запросами тормозит на 1–3 секунды.
 */
require_once __DIR__ . '/auth.php';          // не админ → редирект на вход
require_once __DIR__ . '/../config/db.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$host = getenv('DB_HOST') ?: '';
if ($host === '' && ($u = getenv('DATABASE_URL') ?: getenv('POSTGRES_URL'))) { $host = (string)(parse_url($u)['host'] ?? ''); }
$hostMasked = $host !== '' ? preg_replace('/^[^.]{0,6}/', '••••', $host) : '—';
$port = getenv('DB_PORT') ?: '5432';

$times = [];
for ($i = 0; $i < 12; $i++) {
    $t = microtime(true);
    $pdo->query('SELECT 1')->fetchColumn();
    $times[] = (microtime(true) - $t) * 1000;
}
$first = $times[0];
$rest  = array_slice($times, 1);
$avg   = array_sum($rest) / count($rest);
$min   = min($rest);
$verdict = $avg < 6 ? 'Отлично: сервер и база рядом.' : ($avg < 45 ? 'Нормально: регионы соседние.' : 'Медленно: между сервером и базой большое расстояние (другой материк?). Перенесите базу или сервис в один регион.');

$ver = '';
try { $ver = (string)$pdo->query('SHOW server_version')->fetchColumn(); } catch (Throwable $e) {}
$region = getenv('RENDER_REGION') ?: (getenv('FLY_REGION') ?: (getenv('RAILWAY_REGION') ?: '—'));
?><!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Скорость базы</title>
<style>
body{margin:0;background:#080808;color:#eee;font:15px/1.6 system-ui,sans-serif;padding:32px 20px}
.c{max-width:560px;margin:0 auto;background:#0d0d0d;border:1px solid rgba(255,255,255,.09);border-radius:18px;padding:24px}
h1{margin:0 0 16px;font-size:20px} .row{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06)}
.row span:first-child{color:#8a8a8a} .big{font-size:34px;font-weight:900;color:#fb923c} .v{margin-top:16px;padding:12px 14px;border-radius:12px;background:rgba(249,115,22,.08);border:1px solid rgba(249,115,22,.25)}
a{color:#fb923c}
</style></head><body><div class="c">
<h1>Скорость базы данных</h1>
<div class="big"><?= number_format($avg, 1) ?> мс <span style="font-size:14px;color:#8a8a8a;font-weight:500">на один запрос</span></div>
<div class="v"><?= htmlspecialchars($verdict) ?></div>
<div style="margin-top:14px">
<div class="row"><span>Лучший / первый запрос</span><span><?= number_format($min, 1) ?> / <?= number_format($first, 1) ?> мс</span></div>
<div class="row"><span>Хост БД</span><span><?= htmlspecialchars($hostMasked) ?>:<?= htmlspecialchars($port) ?></span></div>
<div class="row"><span>Версия PostgreSQL</span><span><?= htmlspecialchars($ver ?: '—') ?></span></div>
<div class="row"><span>Регион сервиса (если задан)</span><span><?= htmlspecialchars($region) ?></span></div>
<div class="row"><span>Постоянное соединение</span><span><?= getenv('DB_PERSISTENT') === '0' ? 'выкл' : 'вкл' ?></span></div>
</div>
<p style="color:#8a8a8a;font-size:13px;margin-top:16px">Страница главной делает 10–20 запросов — умножь на это число. Первый запрос включает установку соединения (с кешем соединения он быстрее). Обнови страницу пару раз и смотри на среднее.</p>
<p style="margin:0"><a href="index.php">← в админку</a></p>
</div></body></html>
