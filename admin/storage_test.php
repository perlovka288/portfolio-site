<?php
/**
 * Диагностика хранилища картинок (Cloudinary + ImgBB). Только для админа.
 * Открыть: /admin/storage_test.php  →  кнопка «Проверить загрузку».
 *
 * Показывает:
 *   1) какие ключи сайт реально видит (окружение Render + «Ключи и API»);
 *   2) пробную загрузку в Cloudinary и в ImgBB по отдельности — с ТОЧНОЙ причиной отказа;
 *   3) превью в портфолио/прайсе/аватарках, которые ссылаются на файл на сервере
 *      (такие файлы стираются при каждом деплое — их надо загрузить заново).
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/image_store.php';
require_once __DIR__ . '/../includes/imgbb.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tail4(string $s): string { return $s === '' ? '—' : '…' . substr($s, -4); }

$st   = imageStoreStatus($pdo);
$cred = cloudinaryCreds($pdo);
$keys = imgbbKeys($pdo);
$run  = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run']));
$results = [];

if ($run) {
    // крошечный валидный PNG 8×8
    $tmp = tempnam(sys_get_temp_dir(), 'st_');
    if (function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor(8, 8); imagefill($im, 0, 0, imagecolorallocate($im, 249, 115, 22)); imagepng($im, $tmp); imagedestroy($im);
    } else {
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }
    if ($cred) {
        $e = null; $t0 = microtime(true);
        $u = cloudinaryUploadImage($tmp, 'storage_test', $pdo, $e, 25);
        $results[] = ['Cloudinary', $u !== '', $u !== '' ? $u : $e, round((microtime(true) - $t0) * 1000)];
    } else {
        $results[] = ['Cloudinary', null, 'не настроен (нет cloud name / API key / API secret)', 0];
    }
    if ($keys) {
        $e = null; $t0 = microtime(true);
        $u = imgbbUploadRaw($tmp, 'storage_test', $pdo, $e, 25, 1);
        $results[] = ['ImgBB', $u !== '', $u !== '' ? $u : $e, round((microtime(true) - $t0) * 1000)];
    } else {
        $results[] = ['ImgBB', null, 'не настроен (нет ключей IMGBB_API_KEY)', 0];
    }
    $e = null; $u = imageStoreUpload($tmp, 'storage_test_all', $pdo, $e, 40, 1);
    $results[] = ['Итог (как загружает сайт)', $u !== '', $u !== '' ? $u : $e, 0];
    @unlink($tmp);
}

// ── Ссылки на файлы, которых нет на диске ──
$dead = [];
$uploadsDir = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
$scan = [
    ['portfolio',            'image',        'Портфолио — превью'],
    ['portfolio',            'avatar_image', 'Портфолио — аватарка'],
    ['prices',               'image',        'Прайс — обложка'],
    ['portfolio_categories', 'image',        'Категория портфолио'],
];
foreach ($scan as [$table, $col, $label]) {
    try {
        foreach ($pdo->query("SELECT id, {$col} AS v FROM {$table} WHERE {$col} IS NOT NULL AND {$col} <> ''") as $r) {
            $v = (string)$r['v'];
            if (preg_match('#^https?://#i', $v)) { continue; }
            $file = $uploadsDir . '/' . basename($v);
            if (!is_file($file)) { $dead[] = [$label, (int)$r['id'], $v]; }
        }
    } catch (Throwable $e) { /* нет такой таблицы/колонки — пропускаем */ }
}
?><!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Диагностика картинок</title>
<style>
body{margin:0;background:#080808;color:#e8e8ee;font:14px/1.55 Montserrat,Arial,sans-serif;padding:24px 14px}
.w{max-width:760px;margin:0 auto}
h1{font-size:20px;margin:0 0 4px}h2{font-size:13px;letter-spacing:.6px;text-transform:uppercase;color:#fdba74;margin:26px 0 10px}
.card{background:#111;border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:14px 16px;margin-bottom:10px}
.row{display:flex;justify-content:space-between;gap:12px;padding:5px 0;border-bottom:1px dashed rgba(255,255,255,.06)}.row:last-child{border:0}
.row b{color:#fff}.ok{color:#86efac}.bad{color:#fca5a5}.mut{color:#8a8a96}
button{background:linear-gradient(135deg,#fb923c,#f97316);color:#fff;border:0;border-radius:12px;padding:12px 22px;font:800 13px inherit;font-family:inherit;cursor:pointer}
a{color:#fb923c}code{background:#1a1a22;padding:1px 6px;border-radius:6px;word-break:break-all}
</style></head><body><div class="w">
<h1>🖼 Диагностика загрузки картинок</h1>
<div class="mut">Cloudinary — основное хранилище, ImgBB — запасное. Они работают вместе.</div>

<h2>Что сайт видит сейчас</h2>
<div class="card">
  <div class="row"><span>Cloudinary</span><b class="<?= $cred ? 'ok' : 'bad' ?>"><?= $cred ? 'настроен' : 'НЕ настроен' ?></b></div>
  <div class="row"><span>Cloud name</span><b><?= h($cred['cloud'] ?? '—') ?></b></div>
  <div class="row"><span>API key / secret</span><b><?= $cred ? h(tail4($cred['key']) . ' / ' . tail4($cred['secret'])) : '—' ?></b></div>
  <div class="row"><span>ImgBB ключей</span><b class="<?= $keys ? 'ok' : 'mut' ?>"><?= count($keys) ?></b></div>
  <div class="row"><span>Режим (IMAGE_STORAGE)</span><b><?= h($st['pref']) ?></b></div>
</div>

<form method="post"><button name="run" value="1">▶ Проверить загрузку</button></form>

<?php if ($run): ?>
<h2>Результат пробной загрузки</h2>
<?php foreach ($results as [$name, $okFlag, $msg, $ms]): ?>
  <div class="card">
    <div class="row"><b><?= h($name) ?></b>
      <span class="<?= $okFlag === true ? 'ok' : ($okFlag === null ? 'mut' : 'bad') ?>"><?= $okFlag === true ? '✅ работает' : ($okFlag === null ? '— пропущено' : '❌ ошибка') ?><?= $ms ? ' · ' . (int)$ms . ' мс' : '' ?></span></div>
    <div class="<?= $okFlag === true ? 'mut' : 'bad' ?>" style="margin-top:6px"><?= $okFlag === true ? '<a href="' . h($msg) . '" target="_blank" rel="noopener">открыть тестовую картинку</a>' : h($msg) ?></div>
  </div>
<?php endforeach; ?>
<div class="mut">Если Cloudinary пишет «Invalid Signature» или «Unknown API key» — перепиши ключи в «Ключи и API» заново (без пробелов).
Если ImgBB пишет «forbidden» — он блокирует IP Render, пользуйся Cloudinary.</div>
<?php endif; ?>

<h2>Превью, пропавшие после деплоя</h2>
<div class="card">
<?php if (!$dead): ?>
  <span class="ok">Таких нет — все картинки в базе лежат на Cloudinary/ImgBB ✅</span>
<?php else: ?>
  <div class="bad" style="margin-bottom:8px">Нашёл <?= count($dead) ?> шт. со ссылкой на файл на сервере, которого уже нет — на сайте они не покажутся. Загрузи эти картинки заново в админке:</div>
  <?php foreach ($dead as [$label, $id, $v]): ?>
    <div class="row"><span><?= h($label) ?> #<?= (int)$id ?></span><code><?= h($v) ?></code></div>
  <?php endforeach; ?>
<?php endif; ?>
</div>
<p><a href="index.php">← назад в админку</a></p>
</div></body></html>
