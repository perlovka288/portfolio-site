<?php
/**
 * Оптимизация фото портфолио и прайса: скачать из Cloudinary → сжать → залить заново → заменить ссылку в БД.
 *
 * Вызывается из админки (вкладка «Портфолио» → «Оптимизировать фото») по одному фото за запрос,
 * чтобы не упираться в тайм-аут PHP и показывать прогресс.
 *
 * POST (X-Requested-With: XMLHttpRequest):
 *   action=scan                          → список того, что ещё не оптимизировано
 *   action=process&table=…&id=…&col=…    → оптимизировать ОДНО фото
 *   action=rollback                      → вернуть старые ссылки для всего, что оптимизировали
 *
 * Безопасность: только админ; таблицы/колонки — белый список; ссылка берётся из БД (не от клиента),
 * только https://res.cloudinary.com/…; старые файлы в Cloudinary НЕ удаляются (поэтому откат возможен).
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/image_store.php';
require_once __DIR__ . '/../includes/kui_cache.php';

function optJson(array $data, int $code = 200): void
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
set_exception_handler(function (Throwable $e) {
    error_log('optimize_images: ' . $e->getMessage());
    optJson(['ok' => false, 'error' => 'Ошибка сервера: ' . $e->getMessage()], 500);
});

// ── доступ: только админ (те же условия, что в admin/auth.php, но ответ JSON, а не редирект) ──
$adminTg = getenv('ADMIN_TELEGRAM_ID') ?: (getenv('ADMIN_ID') ?: '');
$isAdmin = (isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true)
    || (!empty($_SESSION['_tg_verified_id']) && $adminTg !== '' && (string)$_SESSION['_tg_verified_id'] === (string)$adminTg);
if (!$isAdmin) { optJson(['ok' => false, 'error' => 'Нет доступа'], 401); }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
    optJson(['ok' => false, 'error' => 'Неверный запрос'], 400);
}
@set_time_limit(180);
@ini_set('memory_limit', '512M');

$pdo->exec("CREATE TABLE IF NOT EXISTS kui_img_optimize_log (
    id SERIAL PRIMARY KEY,
    tbl VARCHAR(32) NOT NULL,
    row_id INT NOT NULL,
    col VARCHAR(32) NOT NULL,
    old_url TEXT NOT NULL,
    new_url TEXT NOT NULL DEFAULT '',
    old_bytes BIGINT NOT NULL DEFAULT 0,
    new_bytes BIGINT NOT NULL DEFAULT 0,
    status VARCHAR(16) NOT NULL DEFAULT 'optimized',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

// Что и где оптимизируем (белый список): таблица => [колонки, колонка-название]
const OPT_TARGETS = [
    'portfolio' => ['cols' => ['image', 'avatar_image'], 'label' => 'title'],
    'prices'    => ['cols' => ['image'],                 'label' => 'title'],
];

function optIsCloudinary(string $url): bool
{
    $p = parse_url($url);
    return $p && ($p['scheme'] ?? '') === 'https' && ($p['host'] ?? '') === 'res.cloudinary.com';
}

/** Уже обработано: эта ссылка — результат оптимизации, либо её проверяли и выигрыша нет. */
function optAlreadyDone(PDO $pdo, string $tbl, int $id, string $col, string $url): bool
{
    $st = $pdo->prepare("SELECT 1 FROM kui_img_optimize_log
        WHERE tbl = ? AND row_id = ? AND col = ?
          AND ((status = 'optimized' AND new_url = ?) OR (status = 'skip' AND old_url = ?))
        LIMIT 1");
    $st->execute([$tbl, $id, $col, $url, $url]);
    return (bool)$st->fetchColumn();
}

/** Скачивает файл во временный путь (до 40 МБ). Возвращает [путь|null, ошибка|null]. */
function optDownload(string $url): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'kopt_');
    $fh  = fopen($tmp, 'wb');
    $written = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 90,
        CURLOPT_USERAGENT      => 'KostlimImageOptimizer/1.0',
        CURLOPT_WRITEFUNCTION  => function ($ch, $data) use ($fh, &$written) {
            $written += strlen($data);
            if ($written > 40 * 1024 * 1024) { return 0; }       // слишком большой — обрываем
            return fwrite($fh, $data);
        },
    ]);
    $ok   = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if (!$ok || $code !== 200 || $written <= 0) { @unlink($tmp); return [null, 'не скачалось (HTTP ' . $code . ($err ? ', ' . $err : '') . ')']; }
    return [$tmp, null];
}

$action = (string)($_POST['action'] ?? '');

// ═══════════ scan ═══════════
if ($action === 'scan') {
    $items = [];
    foreach (OPT_TARGETS as $tbl => $cfg) {
        $cols = implode(', ', $cfg['cols']);
        try { $rows = $pdo->query("SELECT id, {$cfg['label']} AS label, {$cols} FROM {$tbl} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { continue; }
        foreach ($rows as $r) {
            foreach ($cfg['cols'] as $col) {
                $url = trim((string)($r[$col] ?? ''));
                if ($url === '' || !optIsCloudinary($url)) { continue; }
                if (optAlreadyDone($pdo, $tbl, (int)$r['id'], $col, $url)) { continue; }
                $items[] = ['table' => $tbl, 'id' => (int)$r['id'], 'col' => $col,
                            'label' => trim((string)($r['label'] ?? '')) ?: ($tbl . ' #' . $r['id']) . ($col === 'avatar_image' ? ' (аватарка)' : '')];
            }
        }
    }
    optJson(['ok' => true, 'items' => $items]);
}

// ═══════════ process ═══════════
if ($action === 'process') {
    $tbl = (string)($_POST['table'] ?? '');
    $col = (string)($_POST['col'] ?? '');
    $id  = (int)($_POST['id'] ?? 0);
    if (!isset(OPT_TARGETS[$tbl]) || !in_array($col, OPT_TARGETS[$tbl]['cols'], true) || $id <= 0) {
        optJson(['ok' => false, 'error' => 'Неверные параметры'], 400);
    }
    $st = $pdo->prepare("SELECT {$col} FROM {$tbl} WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $old = trim((string)$st->fetchColumn());
    if ($old === '' || !optIsCloudinary($old)) { optJson(['ok' => true, 'status' => 'skip', 'note' => 'не Cloudinary-ссылка']); }
    if (optAlreadyDone($pdo, $tbl, $id, $col, $old)) { optJson(['ok' => true, 'status' => 'skip', 'note' => 'уже оптимизировано']); }

    $logSkip = function (int $bytes) use ($pdo, $tbl, $id, $col, $old) {
        $pdo->prepare("INSERT INTO kui_img_optimize_log (tbl, row_id, col, old_url, old_bytes, status) VALUES (?, ?, ?, ?, ?, 'skip')")
            ->execute([$tbl, $id, $col, $old, $bytes]);
    };

    [$orig, $dlErr] = optDownload($old);
    if ($orig === null) { optJson(['ok' => false, 'error' => $dlErr]); }
    $oldBytes = (int)filesize($orig);

    [$small, $isTmp] = kuiCompressForWeb($orig);
    if (!$isTmp) {                                   // сжатие ничего не даёт (уже лёгкое / не JPEG-PNG)
        @unlink($orig);
        $logSkip($oldBytes);
        optJson(['ok' => true, 'status' => 'skip', 'old_bytes' => $oldBytes, 'note' => 'уже лёгкое']);
    }
    $newBytes = (int)filesize($small);

    $upErr = null;
    $new = imageStoreUpload($small, 'opt_' . $tbl . '_' . $id . '_' . $col, $pdo, $upErr, 120, 2);
    @unlink($small); @unlink($orig);
    if ($new === '') { optJson(['ok' => false, 'error' => 'не залилось: ' . ($upErr ?: 'неизвестная ошибка')]); }

    // Меняем ссылку, только если за это время её не поменяли руками в админке
    $up = $pdo->prepare("UPDATE {$tbl} SET {$col} = ? WHERE id = ? AND {$col} = ?");
    $up->execute([$new, $id, $old]);
    if ($up->rowCount() < 1) { optJson(['ok' => false, 'error' => 'картинку успели заменить вручную — пропущено']); }

    $pdo->prepare("INSERT INTO kui_img_optimize_log (tbl, row_id, col, old_url, new_url, old_bytes, new_bytes, status)
                   VALUES (?, ?, ?, ?, ?, ?, ?, 'optimized')")->execute([$tbl, $id, $col, $old, $new, $oldBytes, $newBytes]);

    foreach (['home_works', 'home_prices', 'home_categories'] as $k) { kuiCacheForget($k); }
    optJson(['ok' => true, 'status' => 'optimized', 'old_bytes' => $oldBytes, 'new_bytes' => $newBytes]);
}

// ═══════════ rollback ═══════════
if ($action === 'rollback') {
    $rows = $pdo->query("SELECT id, tbl, row_id, col, old_url, new_url FROM kui_img_optimize_log WHERE status = 'optimized' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $restored = 0;
    foreach ($rows as $r) {
        if (!isset(OPT_TARGETS[$r['tbl']]) || !in_array($r['col'], OPT_TARGETS[$r['tbl']]['cols'], true)) { continue; }
        $up = $pdo->prepare("UPDATE {$r['tbl']} SET {$r['col']} = ? WHERE id = ? AND {$r['col']} = ?");
        $up->execute([$r['old_url'], (int)$r['row_id'], $r['new_url']]);
        if ($up->rowCount() > 0) { $restored++; }
        $pdo->prepare("UPDATE kui_img_optimize_log SET status = 'rolled_back' WHERE id = ?")->execute([(int)$r['id']]);
    }
    foreach (['home_works', 'home_prices', 'home_categories'] as $k) { kuiCacheForget($k); }
    optJson(['ok' => true, 'restored' => $restored]);
}

optJson(['ok' => false, 'error' => 'Неизвестное действие'], 400);
