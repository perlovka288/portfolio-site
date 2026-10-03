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

if (empty($_SESSION['st_csrf'])) { $_SESSION['st_csrf'] = bin2hex(random_bytes(16)); }
$saveMsg = ''; $saveOk = null; $formCloud = '';

// ── Сохранение ключей Cloudinary прямо отсюда (пишет в site_settings, ошибки не скрывает) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_cloud'])) {
    if (!hash_equals((string)$_SESSION['st_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $saveOk = false; $saveMsg = 'Сессия устарела — обнови страницу и повтори.';
    } else {
        $clean = static function (string $v): string { return trim($v, " \t\n\r\0\x0B\"'`"); };
        $saved = cloudinaryCreds($pdo) ?: ['cloud' => '', 'key' => '', 'secret' => ''];
        $pick  = static function (string $a, string $b) use ($clean): string { return $clean((string)($_POST[$a] ?? $_POST[$b] ?? '')); };
        $in = [
            'CLOUDINARY_CLOUD_NAME' => $pick('cld_cloud', 'cloud'),
            'CLOUDINARY_API_KEY'    => $pick('cld_key', 'key'),
            'CLOUDINARY_API_SECRET' => $pick('cld_sec', 'secret'),
        ];
        $formCloud = $in['CLOUDINARY_CLOUD_NAME'];
        // поле «вставь всю строку» главнее остальных
        $in['__paste'] = $clean((string)($_POST['cld_url'] ?? ''));
        // если вставили целую строку cloudinary://KEY:SECRET@CLOUD — разбираем её
        foreach ($in as $v) {
            if (preg_match('#cloudinary://([^:\s]+):([^@\s]+)@([A-Za-z0-9_-]+)#', $v, $m)) {
                $in = ['CLOUDINARY_CLOUD_NAME' => $m[3], 'CLOUDINARY_API_KEY' => $m[1], 'CLOUDINARY_API_SECRET' => $m[2]];
                break;
            }
        }
        unset($in['__paste']);
        // пустые поля не затирают уже сохранённое (можно поменять только Cloud name)
        if ($in['CLOUDINARY_CLOUD_NAME'] === '') { $in['CLOUDINARY_CLOUD_NAME'] = $saved['cloud']; }
        if ($in['CLOUDINARY_API_KEY']    === '') { $in['CLOUDINARY_API_KEY']    = $saved['key']; }
        if ($in['CLOUDINARY_API_SECRET'] === '') { $in['CLOUDINARY_API_SECRET'] = $saved['secret']; }
        // cloud name не должен быть ссылкой/цифрами-ключом
        if (preg_match('#res\.cloudinary\.com/([A-Za-z0-9_-]+)#', $in['CLOUDINARY_CLOUD_NAME'], $mm)) { $in['CLOUDINARY_CLOUD_NAME'] = $mm[1]; }
        if ($in['CLOUDINARY_CLOUD_NAME'] === '' || $in['CLOUDINARY_API_KEY'] === '' || $in['CLOUDINARY_API_SECRET'] === '') {
            $saveOk = false; $saveMsg = 'Заполни Cloud name, API key и API secret (или вставь целую строку cloudinary://… в верхнее поле).';
        } elseif (!ctype_digit($in['CLOUDINARY_API_KEY'])) {
            $saveOk = false; $saveMsg = 'API key у Cloudinary состоит только из цифр (15 знаков). Похоже, в это поле попал секрет или Cloud name.';
        } else {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (setting_key VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
                $up = $pdo->prepare("INSERT INTO site_settings (setting_key, value, updated_at) VALUES (?, ?, NOW())
                                     ON CONFLICT (setting_key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()");
                foreach ($in as $k => $v) { $up->execute([$k, $v]); }
                header('Location: storage_test.php?saved=1');
                exit;
            } catch (Throwable $e) {
                $saveOk = false; $saveMsg = 'Не удалось записать в базу: ' . $e->getMessage();
            }
        }
    }
}

$st   = imageStoreStatus($pdo);
$cred = cloudinaryCreds($pdo);
$keys = imgbbKeys($pdo);
$run  = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run'])) || isset($_GET['saved']);
$results = [];

// откуда сайт берёт каждое значение Cloudinary
$srcRows = [];
foreach ([
    ['Cloud name', ['CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_NAME', 'CLOUDINARY_CLOUD', 'CLOUD_NAME']],
    ['API key',    ['CLOUDINARY_API_KEY', 'CLOUDINARY_KEY', 'CLOUDINARY_APIKEY']],
    ['API secret', ['CLOUDINARY_API_SECRET', 'CLOUDINARY_SECRET', 'CLOUDINARY_APISECRET']],
    ['CLOUDINARY_URL', ['CLOUDINARY_URL']],
] as [$lbl, $names]) {
    [$v, $src, $nm] = kuiCfgFirst($names, $pdo);
    $srcRows[] = [$lbl, $v !== '', $src === 'env' ? 'окружение Render (' . $nm . ')' : ($src === 'admin' ? 'админка / база (' . $nm . ')' : 'не найдено')];
}
// что похожее на Cloudinary вообще есть в окружении и в базе (только имена — для поиска опечаток)
$seenNames = [];
foreach (array_keys(getenv() ?: []) as $n) { if (preg_match('/CLOUD|IMGBB|IMAGE_STORAGE/i', (string)$n)) { $seenNames[] = 'окружение: ' . $n; } }
try {
    foreach ($pdo->query("SELECT setting_key, LENGTH(value) AS l FROM site_settings WHERE setting_key ILIKE '%cloud%' OR setting_key ILIKE '%imgbb%' OR setting_key ILIKE 'image_storage'") as $r) {
        $seenNames[] = 'база: ' . $r['setting_key'] . ((int)$r['l'] === 0 ? ' (пустое)' : ' (' . (int)$r['l'] . ' симв.)');
    }
} catch (Throwable $e) {}

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
.fl{display:block;font-size:12px;color:#8a8a96;margin-bottom:10px}.fl input{display:block;width:100%;box-sizing:border-box;margin-top:4px;background:#0b0b0f;border:1px solid rgba(255,255,255,.12);border-radius:10px;color:#fff;padding:11px 12px;font:14px inherit;font-family:inherit;outline:none}.fl input:focus{border-color:#fb923c}
input.mask{-webkit-text-security:disc;text-security:disc}
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

<h2>Откуда берутся значения</h2>
<div class="card">
<?php foreach ($srcRows as [$lbl, $has, $where]): ?>
  <div class="row"><span><?= h($lbl) ?></span><b class="<?= $has ? 'ok' : 'mut' ?>"><?= h($where) ?></b></div>
<?php endforeach; ?>
<?php if ($seenNames): ?>
  <div class="mut" style="margin-top:8px;font-size:12px">Найдено в системе: <?= h(implode(' · ', $seenNames)) ?></div>
<?php endif; ?>
</div>

<h2>Подключить Cloudinary</h2>
<div class="card">
  <div class="mut" style="margin-bottom:10px">Нужен именно <b>Cloudinary</b> (cloudinary.com → Dashboard → «API Keys»), а не Cloudflare — это разные сервисы, ключи от Cloudflare сюда не подходят. Бесплатного тарифа хватает.</div>
  <?php if ($saveMsg !== ''): ?><div class="bad" style="margin-bottom:10px"><?= h($saveMsg) ?></div><?php endif; ?>
  <form method="post" autocomplete="off" data-lpignore="true" data-1p-ignore="true">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['st_csrf']) ?>">
    <label class="fl">Быстрый способ — вставь целую строку <code>CLOUDINARY_URL</code> (Dashboard → «API Keys» → «Copy to clipboard»)
      <input class="mask" name="cld_url" value="" placeholder="cloudinary://123456789012345:секрет@имя-облака" autocomplete="off" autocapitalize="off" spellcheck="false" readonly onfocus="this.removeAttribute('readonly')" data-lpignore="true" data-1p-ignore="true" data-form-type="other"></label>
    <div class="mut" style="margin:2px 0 12px;font-size:12px">…или заполни три поля ниже:</div>
    <label class="fl">Cloud name (имя облака, в Dashboard сверху слева)<input name="cld_cloud" value="<?= h($formCloud !== '' ? $formCloud : ($cred['cloud'] ?? '')) ?>" placeholder="например, dxyz123abc" autocomplete="off" autocapitalize="off" spellcheck="false" readonly onfocus="this.removeAttribute('readonly')" data-lpignore="true" data-1p-ignore="true" data-form-type="other"></label>
    <label class="fl">API key (только цифры)<input name="cld_key" value="" inputmode="numeric" placeholder="<?= $cred ? h('сейчас ' . tail4($cred['key']) . ' — введи заново, чтобы заменить') : '123456789012345' ?>" autocomplete="off" autocapitalize="off" spellcheck="false" readonly onfocus="this.removeAttribute('readonly')" data-lpignore="true" data-1p-ignore="true" data-form-type="other"></label>
    <label class="fl">API secret<input class="mask" name="cld_sec" value="" placeholder="<?= $cred ? h('сейчас ' . tail4($cred['secret']) . ' — введи заново, чтобы заменить') : 'секрет из Dashboard' ?>" autocomplete="off" autocapitalize="off" spellcheck="false" readonly onfocus="this.removeAttribute('readonly')" data-lpignore="true" data-1p-ignore="true" data-form-type="other"></label>
    <div class="mut" style="margin:6px 0 10px;font-size:12px">Пустые поля не затирают уже сохранённое. Менеджер паролей браузера сюда больше ничего не подставит (поля не «парольные»).</div>
    <button name="save_cloud" value="1">💾 Сохранить и проверить</button>
  </form>
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
Если ImgBB пишет «forbidden» — он блокирует IP Render (это не ключ): работает Cloudinary либо ImgBB через прокси (переменная IMGBB_PROXY).</div>
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
