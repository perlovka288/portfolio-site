<?php
require_once 'includes/session.php';
require_once 'config/db.php';
require_once 'includes/order_flow.php';

// AUTO-LINK: Если клиент перешёл с TG по нашей ссылке — привязываем его TG автоматически
processTgAutoLink($pdo);
ensureOrderFlowSchema($pdo);
ensurePromoSchema($pdo);

// ── AJAX: проверка промокода при вводе (галочка ✓ прямо в форме заказа) ──
if (isset($_GET['check_promo'])) {
    header('Content-Type: application/json; charset=utf-8');
    $codeInput = trim((string)$_GET['check_promo']);
    if ($codeInput === '') { echo json_encode(['valid' => false]); exit; }
    try {
        // Пытаемся понять, кто это, чтобы проверить "уже использовал ли он
        // этот код" — по session_id и, если сессия уже привязана к Telegram,
        // по tg_id (надёжнее, чем session_id, который меняется по браузерам).
        $sidForPromo = session_id();
        $chatIdForPromo = null;
        try {
            $lnkStmt = $pdo->prepare("SELECT tg_id FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1");
            $lnkStmt->execute([$sidForPromo]);
            $chatIdForPromo = (int)($lnkStmt->fetchColumn() ?: 0) ?: null;
        } catch (Throwable $e) {}

        $result = checkPromoCode($pdo, $codeInput, $chatIdForPromo, $sidForPromo);
        if ($result['valid']) {
            $promo = $result['promo'];
            echo json_encode([
                'valid'            => true,
                'code'             => $promo['code'],
                'discount_percent' => $promo['discount_percent'],
                'bonus_text'       => $promo['bonus_text'],
            ]);
        } else {
            $reasonText = match($result['reason']) {
                'already_used' => 'already_used',
                'expired'      => 'expired',
                'maxed_out'    => 'maxed_out',
                default        => 'not_found',
            };
            echo json_encode(['valid' => false, 'reason' => $reasonText]);
        }
    } catch (Throwable $e) {
        echo json_encode(['valid' => false]);
    }
    exit;
}

// 🌴 Режим "приём заказов выключен" (админ поставил на паузу через бота)
if (!isOrdersAvailable($pdo)) {
    $returnDate = getOrdersReturnDate($pdo);
    ?><!DOCTYPE html>
    <html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Приём заказов приостановлен | Kostlim Design</title>
    <style>
        body{background:#0a0a0f;color:#fff;font-family:Montserrat,Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px;text-align:center;}
        .box{max-width:440px;background:#111116;border:1px solid #1f1f2a;border-radius:20px;padding:40px 32px;}
        .box h1{font-size:20px;margin:0 0 12px;}
        .box p{color:#9a9aa6;line-height:1.6;font-size:14px;}
        .box a{display:inline-block;margin-top:20px;background:linear-gradient(135deg,#fb923c,#f97316);color:#fff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:800;font-size:13px;}
    </style></head><body>
    <div class="box">
        <div style="font-size:44px;margin-bottom:12px;">🌴</div>
        <h1>Приём заказов временно приостановлен</h1>
        <p>Дизайнер сейчас не принимает новые заказы<?= $returnDate !== '' ? ", вернусь: <b>{$returnDate}</b>" : '' ?>. Загляни чуть позже — форма снова откроется.</p>
        <a href="index.php">← На главную к портфолио</a>
    </div>
    </body></html><?php
    exit;
}

// ── Гарантируем существование таблицы правил ────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_rules (
        id SERIAL PRIMARY KEY,
        rule_key VARCHAR(100) UNIQUE NOT NULL,
        rule_text TEXT NOT NULL DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

define('ADMIN_TG_ID', '1710365896');

function uploadToCloudinary(string $filePath, string $folder = 'orders'): string {
    $cloudName = getenv('CLOUDINARY_CLOUD_NAME') ?: '';
    $apiKey    = getenv('CLOUDINARY_API_KEY')    ?: '';
    $apiSecret = getenv('CLOUDINARY_API_SECRET') ?: '';
    if ($cloudName === '' || $apiKey === '' || $apiSecret === '') {
        error_log('[uploadToCloudinary] CLOUDINARY_CLOUD_NAME/CLOUDINARY_API_KEY/CLOUDINARY_API_SECRET env vars are not set — upload skipped.');
        return '';
    }
    if (!is_file($filePath)) {
        error_log("[uploadToCloudinary] tmp file not found: {$filePath}");
        return '';
    }
    $timestamp = time();
    $sig = sha1("folder={$folder}&timestamp={$timestamp}{$apiSecret}");
    // resource_type=auto — чтобы не только картинки, но и исходники (psd/ai/zip/pdf и т.п.) грузились корректно
    $ch = curl_init("https://api.cloudinary.com/v1_1/{$cloudName}/auto/upload");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POSTFIELDS     => [
            'file'      => new CURLFile($filePath),
            'api_key'   => $apiKey,
            'timestamp' => $timestamp,
            'signature' => $sig,
            'folder'    => $folder,
        ],
    ]);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) {
        error_log("[uploadToCloudinary] curl error: {$curlErr}");
        return '';
    }
    $data = json_decode($resp, true);
    if (!isset($data['secure_url'])) {
        error_log("[uploadToCloudinary] Cloudinary API error (HTTP {$httpCode}): " . substr($resp, 0, 500));
        return '';
    }
    return $data['secure_url'];
}

/**
 * Локальный запасной вариант сохранения файла, если Cloudinary не настроен
 * (нет env-переменных) или его API временно недоступно/вернуло ошибку.
 * Раньше в обоих случаях файл просто пропадал (или мы вообще блокировали
 * отправку заказа), хотя место для хранения на сервере есть — тот же
 * /uploads/orders, что уже используют admin/large_upload.php и админка.
 * Возвращает ИМЯ ФАЙЛА (не URL) — admin/index.php::imgSrc() сам достроит
 * его до полного пути через '../uploads/orders/' + SITE_URL при показе.
 */
function uploadLocalFallback(string $tmpPath, string $origName): string {
    if (!is_file($tmpPath)) {
        return '';
    }
    $uploadDir = __DIR__ . '/uploads/orders/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        error_log("[uploadLocalFallback] uploads/orders/ отсутствует или недоступна для записи");
        return '';
    }
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $ext = preg_match('/^[a-z0-9]{1,10}$/', $ext) ? $ext : 'bin';
    $uniqueName = 'ref_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $destPath = $uploadDir . $uniqueName;
    if (!move_uploaded_file($tmpPath, $destPath)) {
        error_log("[uploadLocalFallback] move_uploaded_file не удался: {$tmpPath} -> {$destPath}");
        return '';
    }
    @chmod($destPath, 0644);
    return $uniqueName;
}

/**
 * Отправляет дизайнеру локально сохранённые (без Cloudinary) файлы заказа
 * НАСТОЯЩИМ документом, а не через sendPhoto — Telegram пережимает фото при
 * отправке через sendPhoto/sendMediaGroup, теряя качество. Несколько файлов
 * собираем в один zip-архив (папка с фото заказа) и грузим его целиком одним
 * файлом; если файл один — шлём его как есть. Грузим напрямую с диска
 * (multipart), поэтому публичный URL/домен для этого не нужен вообще.
 */
function sendLocalOrderFilesAsDocument(string $botToken, string $chatId, int $orderId, array $filenames): void {
    $dir = __DIR__ . '/uploads/orders/';
    $existing = [];
    foreach ($filenames as $fn) {
        $path = $dir . basename($fn);
        if (is_file($path)) $existing[] = $path;
    }
    if (empty($existing)) return;

    $zipPath = '';
    if (count($existing) > 1 && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        $candidate = sys_get_temp_dir() . '/order_' . $orderId . '_' . bin2hex(random_bytes(4)) . '.zip';
        if ($zip->open($candidate, ZipArchive::CREATE) === true) {
            foreach ($existing as $p) {
                $zip->addFile($p, basename($p));
            }
            $zip->close();
            $zipPath = $candidate;
        }
    }

    if ($zipPath !== '' && is_file($zipPath)) {
        $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendDocument");
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_POSTFIELDS => [
                'chat_id'  => $chatId,
                'document' => new CURLFile($zipPath, 'application/zip', "order_{$orderId}_files.zip"),
                'caption'  => "📁 Исходники к заказу #{$orderId} (" . count($existing) . " файл(ов), архив — без потери качества)",
            ],
        ]);
        $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
        if ($resp === false || (is_string($resp) && str_contains($resp, '"ok":false'))) {
            error_log("[sendLocalOrderFilesAsDocument] sendDocument (zip) failed for order #{$orderId}: " . ($err ?: substr((string)$resp, 0, 300)));
        }
        @unlink($zipPath);
        return;
    }

    // Не смогли собрать zip (нет расширения ZipArchive или всего один файл) —
    // шлём документом(-ами) по одному.
    foreach ($existing as $i => $path) {
        $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendDocument");
        $fields = [
            'chat_id'  => $chatId,
            'document' => new CURLFile($path, mime_content_type($path) ?: 'application/octet-stream', basename($path)),
        ];
        if ($i === 0) $fields['caption'] = "📁 Исходники к заказу #{$orderId} (" . count($existing) . " файл(ов))";
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POSTFIELDS => $fields]);
        $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
        if ($resp === false || (is_string($resp) && str_contains($resp, '"ok":false'))) {
            error_log("[sendLocalOrderFilesAsDocument] sendDocument failed for order #{$orderId} file {$path}: " . ($err ?: substr((string)$resp, 0, 300)));
        }
        if ($i < count($existing) - 1) usleep(300000);
    }
}

if (!empty($_GET['tg_id']) && $_GET['tg_id'] === ADMIN_TG_ID) {
    $_SESSION['admin_logged'] = true;
}

$bot_token   = getenv('BOT_TOKEN') ?: getenv('TELEGRAM_BOT_TOKEN') ?: "";
$my_chat_id  = getenv('ADMIN_ID')  ?: "1710365896";
$bot_link    = 'https://t.me/kostlimdznbot';
$support_tg  = 'https://t.me/Perlo_ovka';

$turnstile_site_key   = getenv('TURNSTILE_SITE_KEY')   ?: 'ТВОЙ_ПУБЛИЧНЫЙ_КЛЮЧ';
$turnstile_secret_key = getenv('TURNSTILE_SECRET_KEY') ?: 'ТВОЙ_СЕКРЕТНЫЙ_КЛЮЧ';

define('COOLDOWN_SECONDS', 300);

$selected_service = $_POST['service'] ?? $_GET['service'] ?? '';
$services = $pdo->query("SELECT title, category_key, price_uan, price_rub FROM prices ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// ── TG: статус привязки для текущей сессии ──────────────────────
$linkCode = null;
$isLinked = false;
try {
    $sid  = session_id();
    $stmt_lnk = $pdo->prepare("SELECT site_code, linked FROM tg_links WHERE session_id = ? ORDER BY id DESC LIMIT 1");
    $stmt_lnk->execute([$sid]);
    $linkRow = $stmt_lnk->fetch(PDO::FETCH_ASSOC);
    if ($linkRow && $linkRow['linked']) {
        $isLinked = true;
    } elseif ($linkRow) {
        $linkCode = $linkRow['site_code'];
    } else {
        $code = strtoupper(substr(md5(uniqid($sid, true)), 0, 6));
        $pdo->prepare("INSERT INTO tg_links (site_code, session_id, linked, created_at) VALUES (?, ?, FALSE, NOW())")->execute([$code, $sid]);
        $linkCode = $code;
    }
} catch (Throwable $e) {
    $linkCode = null;
}

// ── AJAX: проверить статус привязки (polling с order.php) ────────
if (isset($_GET['check_linked'])) {
    header('Content-Type: application/json');
    $sid_chk = session_id();
    try {
        $stmt_chk = $pdo->prepare("SELECT linked FROM tg_links WHERE session_id = ? ORDER BY id DESC LIMIT 1");
        $stmt_chk->execute([$sid_chk]);
        $row_chk = $stmt_chk->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['linked' => !empty($row_chk['linked'])]);
    } catch (Throwable $e) {
        echo json_encode(['linked' => false]);
    }
    exit;
}

$success_msg = '';
$error_msg   = '';
$order_id    = 0;

// После Post-Redirect-Get успешная отправка заказа приходит сюда уже как
// обычный GET (?sent=ID) — так повторное обновление страницы никогда не
// создаёт заказ повторно и не наталкивается на антиспам-задержку.
if (!empty($_GET['sent']) && ctype_digit((string)$_GET['sent'])) {
    $order_id = (int)$_GET['sent'];
    $success_msg = "🚀 Заказ #{$order_id} отправлен! Дизайнер посмотрит ТЗ и примет или отклонит заказ. Оплачивать сейчас не нужно: реквизиты придут после принятия заказа.";
}

$skip_rules = isset($_COOKIE['rules_skip']) && $_COOKIE['rules_skip'] === '1';
$rules_accepted = $skip_rules || (($_POST['rules_accepted'] ?? '') === '1');

// ── Загружаем правила из БД (сохранённые через админку) ──────────────────
$orderRulesHtml = '';
try {
    $rulesRow = $pdo->query("SELECT rule_text FROM site_rules WHERE rule_key = 'order_terms' LIMIT 1")->fetch();
    if ($rulesRow && !empty(trim($rulesRow['rule_text']))) {
        // Фильтруем разрешённые теги: <b>,<i>,<br>,<a>
        $orderRulesHtml = strip_tags($rulesRow['rule_text'], '<b><i><br><a>');
    }
} catch (Throwable $e) {}
// Если в БД пусто — используем дефолтный текст
if ($orderRulesHtml === '') {
    $orderRulesHtml = '<ul style="padding-left:20px;margin:0;line-height:1.7;font-size:13px;color:#e0e0ec;">'
        . '<li>Стандартный срок сдачи — <b>5 дней</b>.</li>'
        . '<li>Срочный заказ (24 часа): <b>+50%</b> к цене.</li>'
        . '<li>ТЗ должно быть <b>максимально подробным</b>.</li>'
        . '<li>По вопросам: <a href="https://t.me/Perlo_ovka" target="_blank" style="color:#f97316;font-weight:700;">@Perlo_ovka</a></li>'
        . '<li>Деньги не возвращаются.</li>'
        . '</ul>';
}

if (isset($_POST['accept_rules'])) {
    if (!empty($_POST['dont_ask'])) {
        setcookie('rules_skip', '1', time() + (3600 * 24 * 365), '/');
    }
    $rules_accepted = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['accept_rules']) && !$rules_accepted) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['accept_rules'])) {

    $user_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $user_ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $user_ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }

    // Cooldown check — skip for admin and for additional order slots (2, 3)
    $sessionTgId = (string)($_SESSION['tg_chat_id'] ?? '');
    $adminTgId   = getenv('ADMIN_ID') ?: ADMIN_TG_ID;
    $isAdminOrder = (!empty($_SESSION['admin_logged']) || $sessionTgId === $adminTgId);
    $orderSlot = (int)($_POST['order_slot'] ?? 1);
    $isExtraSlot = ($orderSlot >= 2); // Слоты 2 и 3 — без кулдауна

    if (!$isAdminOrder && !$isExtraSlot) {
        try {
            $stmt = $pdo->prepare("SELECT created_at FROM orders WHERE client_ip = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user_ip]);
            $last_order = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($last_order) {
                $seconds_passed = time() - strtotime($last_order['created_at']);
                if ($seconds_passed < COOLDOWN_SECONDS) {
                    $minutes_left = ceil((COOLDOWN_SECONDS - $seconds_passed) / 60);
                    $error_msg = "⏳ Слишком много заявок. Подождите ещё {$minutes_left} мин. перед новым заказом.";
                    goto render_page;
                }
            }
        } catch (PDOException $e) {}
    }

    $captcha_token = $_POST['cf-turnstile-response'] ?? '';
    if (empty($captcha_token)) {
        $error_msg = '⚠️ Пройдите проверку (Turnstile). Обновите страницу и попробуйте снова.';
        goto render_page;
    }
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['secret' => $turnstile_secret_key, 'response' => $captcha_token, 'remoteip' => $user_ip]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $cf_result = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (empty($cf_result['success'])) {
        $error_msg = '⚠️ Капча не прошла. Обновите страницу и попробуйте ещё раз.';
        goto render_page;
    }

    $telegram_raw = trim($_POST['telegram'] ?? '');
    $tg_clean = ltrim(str_replace(['https://t.me/', 'http://t.me/', '@'], '', $telegram_raw), '@');
    try {
        $bl_stmt = $pdo->prepare("SELECT reason FROM blacklist WHERE telegram = ? OR ip = ? LIMIT 1");
        $bl_stmt->execute([$tg_clean, $user_ip]);
        $bl = $bl_stmt->fetch(PDO::FETCH_ASSOC);
        if ($bl) {
            $error_msg = '🚫 Оформление заказов с вашего аккаунта или адреса недоступно.';
            goto render_page;
        }
    } catch (PDOException $e) {}

    $username    = $_POST['username'] ?? '';
    $service_key = $_POST['service']  ?? '';
    $details     = $_POST['details']  ?? '';
    $cooperation = !empty($_POST['cooperation']) ? 1 : 0;
    $requestedUrgency = ($_POST['requested_urgency'] ?? 'normal') === 'urgent' ? 'urgent' : 'normal';

    $example_imgs   = [];

    if (!empty($_FILES['example_photos']['name'][0])) {
        $uploadedCount = count(array_filter($_FILES['example_photos']['tmp_name'], fn($t) => !empty($t)));
        if ($uploadedCount > 40) {
            $error_msg = '⚠️ Максимум 40 файлов за один заказ. Пришли до 40 файлов и попробуй ещё раз.';
            goto render_page;
        }
        $cloudinaryConfigured = getenv('CLOUDINARY_CLOUD_NAME') && getenv('CLOUDINARY_API_KEY') && getenv('CLOUDINARY_API_SECRET');
        if ($uploadedCount > 0 && !$cloudinaryConfigured) {
            // Cloudinary не настроен (нет env-переменных) — не блокируем заказ,
            // как раньше, а сохраняем файлы прямо на сервер в uploads/orders/.
            error_log('[order.php] Cloudinary env vars missing — falling back to local storage for this order\'s files.');
        }
        foreach ($_FILES['example_photos']['tmp_name'] as $i => $tmp) {
            if (empty($tmp) || $_FILES['example_photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $origName = (string)($_FILES['example_photos']['name'][$i] ?? 'file');
            $url = $cloudinaryConfigured ? uploadToCloudinary($tmp, 'orders/ref') : '';
            if ($url !== '') {
                $example_imgs[] = $url;
                continue;
            }
            // Либо Cloudinary не настроен, либо его API вернуло ошибку/недоступно —
            // в обоих случаях пробуем сохранить файл локально, а не терять его молча.
            $localName = uploadLocalFallback($tmp, $origName);
            if ($localName !== '') $example_imgs[] = $localName;
        }
    }
    // Референсы из архива (JSON массив URL)
    if (empty($example_imgs) && !empty($_POST['refs_urls'])) {
        $decoded = json_decode($_POST['refs_urls'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $u) {
                $u = filter_var(trim($u), FILTER_VALIDATE_URL);
                if ($u && str_contains($u, 'cloudinary.com')) $example_imgs[] = $u;
            }
        }
    }

    $example_img_json = json_encode($example_imgs);

    try {
    $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS requested_urgency VARCHAR(10) NOT NULL DEFAULT 'normal'");
    $stmt = $pdo->prepare("INSERT INTO orders
     (username, telegram, service_key, details, screenshot, example_photo, status, payment_status, cooperation, requested_urgency, client_ip, session_id, created_at)
     VALUES (?, ?, ?, ?, '', ?, 'pending', 'not_requested', ?, ?, ?, ?, NOW()) RETURNING id");
     $stmt->execute([$username, $telegram_raw, $service_key, $details, $example_img_json, $cooperation, $requestedUrgency, $user_ip, session_id()]);
        $order_id = (int)$stmt->fetchColumn();
        if ($order_id <= 0) {
            $order_id = (int)$pdo->lastInsertId();
        }

        addOrderMessage($pdo, $order_id, 'client', 'Заказ отправлен на рассмотрение.');
        $success_msg = "🚀 Заказ #{$order_id} отправлен! Дизайнер посмотрит ТЗ и примет или отклонит заказ. Оплачивать сейчас не нужно: реквизиты придут после принятия заказа.";

        // Уведомить клиента в TG — ищем chat_id тремя способами
        $client_chat_id = null;
        try {
            $sid_now = session_id();

            // Метод 0 (приоритет): tg_chat_id из сессии (автопривязка через ?tg_token=...)
            if (!empty($_SESSION['tg_chat_id'])) {
                $client_chat_id = (int)$_SESSION['tg_chat_id'];
                // Сразу пишем в orders
                if ($client_chat_id) {
                    $pdo->prepare("UPDATE orders SET client_chat_id=? WHERE id=?")->execute([$client_chat_id, $order_id]);
                }
            }

            // Метод 1: по session_id текущей сессии
            if ($sid_now !== '') {
                $lnk = $pdo->prepare("
                    SELECT NULLIF(CAST(tg_id AS VARCHAR),'') AS chat_id
                    FROM tg_links WHERE session_id = ? AND linked = TRUE
                    ORDER BY id DESC LIMIT 1
                ");
                $lnk->execute([$sid_now]);
                $lnk_row = $lnk->fetch(PDO::FETCH_ASSOC);
                if (!empty($lnk_row['chat_id']) && is_numeric($lnk_row['chat_id'])) {
                    $client_chat_id = (int)$lnk_row['chat_id'];
                }
            }

            // Метод 2: по telegram username из формы
            if (!$client_chat_id && !empty($telegram_raw)) {
                $tg_clean = ltrim(trim(str_replace(['https://t.me/', 'http://t.me/', 't.me/'], '', $telegram_raw)), '@');
                if ($tg_clean !== '') {
                    $lnk2 = $pdo->prepare("
                        SELECT NULLIF(CAST(tg_id AS VARCHAR),'') AS chat_id
                        FROM tg_links WHERE (tg_username = ? OR tg_username = ?) AND linked = TRUE
                        ORDER BY id DESC LIMIT 1
                    ");
                    $lnk2->execute([$tg_clean, '@' . $tg_clean]);
                    $lnk2_row = $lnk2->fetch(PDO::FETCH_ASSOC);
                    if (!empty($lnk2_row['chat_id']) && is_numeric($lnk2_row['chat_id'])) {
                        $client_chat_id = (int)$lnk2_row['chat_id'];
                    }
                }
            }

            // Метод 3: telegram поле выглядит как числовой ID
            if (!$client_chat_id && !empty($telegram_raw) && is_numeric(trim($telegram_raw))) {
                $client_chat_id = (int)trim($telegram_raw);
            }

            // Метод 4: getChat через Telegram API (работает если пользователь писал боту)
            if (!$client_chat_id && !empty($telegram_raw)) {
                $tg_clean = ltrim(trim(str_replace(['https://t.me/', 'http://t.me/', 't.me/'], '', $telegram_raw)), '@');
                if ($tg_clean !== '') {
                    $ch = curl_init("https://api.telegram.org/bot{$bot_token}/getChat");
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 5,
                        CURLOPT_POSTFIELDS => ['chat_id' => '@' . $tg_clean],
                    ]);
                    $resp = curl_exec($ch);
                    curl_close($ch);
                    $data = json_decode((string)$resp, true);
                    if (!empty($data['ok']) && !empty($data['result']['id'])) {
                        $client_chat_id = (int)$data['result']['id'];
                        // Сохраняем в tg_links для будущих заказов
                        try {
                            $pdo->prepare("
                                UPDATE tg_links SET tg_id = CAST(? AS VARCHAR)
                                WHERE tg_username = ? AND (tg_id IS NULL OR tg_id = '')
                            ")->execute([$client_chat_id, $tg_clean]);
                        } catch (Throwable $e) {}
                    }
                }
            }

            if ($client_chat_id) {
                $pdo->prepare("UPDATE orders SET client_chat_id = ? WHERE id = ?")->execute([$client_chat_id, $order_id]);
            }
        } catch (Throwable $e) {}

        if ($client_chat_id) {
            $pr = $pdo->prepare("SELECT title FROM prices WHERE category_key = ? LIMIT 1");
            $pr->execute([$service_key]);
            $srv_title = (string)($pr->fetchColumn() ?: $service_key);
            tgEscapeSend($bot_token, $client_chat_id,
                "✅ *Заказ \#{$order_id} создан\!*\n\n🎨 Услуга: " . tgEsc($srv_title) . "\n📋 Статус: ожидает рассмотрения\n\nОплата пока не нужна\. Как только дизайнер примет заказ — сюда придут реквизиты\.",
                __DIR__ . '/assets/notify/status.jpg'
            );
        }

        // ── Промокод ──────────────────────────────────────────────────
        // Проверяем ЕЩЁ РАЗ на сервере (JS-галочка в форме — только подсказка
        // для клиента, доверять ей при сохранении заказа нельзя). Делаем это
        // ДО формирования уведомления админу, чтобы промокод и итоговая цена
        // со скидкой сразу попали в само сообщение о новом заказе, а не только
        // куда-то отдельно.
        $promoCodeInput  = trim((string)($_POST['promo_code'] ?? ''));
        $appliedPromo    = null;
        $promoAdminLine  = '';
        if ($promoCodeInput !== '') {
            try {
                $promoCheck = checkPromoCode($pdo, $promoCodeInput, $client_chat_id, session_id());
                if ($promoCheck['valid']) {
                    $promoRow = $promoCheck['promo'];
                    $pdo->prepare("UPDATE orders SET promo_code = ? WHERE id = ?")->execute([$promoRow['code'], $order_id]);
                    $pdo->prepare("UPDATE promo_codes SET uses_count = uses_count + 1 WHERE code = ?")->execute([$promoRow['code']]);
                    $appliedPromo = $promoRow;

                    $bonusStr = $promoRow['bonus_text'] !== ''
                        ? $promoRow['bonus_text']
                        : ((int)$promoRow['discount_percent'] > 0 ? 'скидка ' . (int)$promoRow['discount_percent'] . '%' : 'бонус');

                    $promoAdminLine = "🎁 <b>Промокод:</b> " . htmlspecialchars($promoRow['code'])
                        . ((int)$promoRow['discount_percent'] > 0 ? " (−" . (int)$promoRow['discount_percent'] . "%)" : '') . "\n";

                    if ($client_chat_id) {
                        tgEscapeSend($bot_token, $client_chat_id,
                            "🎁 Ваш промокод \"" . tgEsc($promoRow['code']) . "\" сработал и дал вам бонус в виде: " . tgEsc($bonusStr) . "\n\nАдминистратор учтёт это при оформлении заказа \#{$order_id}\.",
                            __DIR__ . '/assets/notify/promo.jpg'
                        );
                    }
                } elseif ($promoCheck['reason'] === 'already_used' && $client_chat_id) {
                    // Клиент как-то обошёл проверку в форме (например, отключил
                    // JS) — код просто не применяем, заказ всё равно создаётся.
                    $promoAdminLine = "⚠️ <b>Промокод:</b> клиент пытался повторно использовать код \"" . htmlspecialchars($promoCodeInput) . "\" — уже был применён к его прошлому заказу.\n";
                }
            } catch (Throwable $e) {}
        }

        // ✅ ИСПРАВЛЕНО: Объединяем ТЕКСТ + МЕДИА в ОДНО сообщение в Telegram
        if (!empty($my_chat_id)) {
            // Единая функция расчёта: сначала +50% за срочность (если клиент
            // попросил срочный), затем скидка по промокоду — порядок именно
            // такой, см. computeOrderPriceWithPromo().
            $orderForPrice = [
                'service_key'       => $service_key,
                'status'            => 'pending',
                'requested_urgency' => $requestedUrgency,
                'promo_code'        => $appliedPromo['code'] ?? null,
            ];
            $priceCalc     = computeOrderPriceWithPromo($pdo, $orderForPrice);
            $service_title = $pdo->prepare("SELECT title FROM prices WHERE category_key = ? LIMIT 1");
            $service_title->execute([$service_key]);
            $service_title = (string)($service_title->fetchColumn() ?: $service_key);
            $p_rub         = $priceCalc['final_rub'];
            $p_uan         = $priceCalc['final_uan'];

            // ✅ Формируем ПОЛНЫЙ текст ТЗ
            $slotLabel = $orderSlot >= 2 ? " [ЗАКАЗ №{$orderSlot}]" : "";
            $full_msg_text  = "🔔 <b>НОВЫЙ ЗАКАЗ #{$order_id}{$slotLabel}</b>\n\n";
            $full_msg_text .= "👤 <b>Клиент:</b> " . htmlspecialchars($username) . "\n";
            $full_msg_text .= "📞 <b>Контакт:</b> " . htmlspecialchars($telegram_raw) . "\n";
            $full_msg_text .= "🎨 <b>Услуга:</b> " . htmlspecialchars($service_title) . "\n";
            // Пожелание клиента по срочности — просто информация для решения,
            // финально ставит срочно/обычно всё равно админ через кнопки ниже.
            $full_msg_text .= "⏱ <b>Клиент просит:</b> " . ($requestedUrgency === 'urgent' ? "⚡ СРОЧНЫЙ (24Ч, +50%)" : "ОБЫЧНЫЙ (5 ДНЕЙ)") . "\n";
            if ($cooperation) {
                $full_msg_text .= "💼 <b>Сотрудничество:</b> Да\n";
                $full_msg_text .= "💰 <b>Стоимость:</b> 0₽ / 0₴\n";
            } elseif ($appliedPromo) {
                $full_msg_text .= "💰 <b>Стоимость:</b> <s>{$priceCalc['base_rub']}₽ / {$priceCalc['base_uan']}₴</s> → <b>{$p_rub}₽ / {$p_uan}₴</b>\n";
            } else {
                $full_msg_text .= "💰 <b>Стоимость:</b> {$p_rub}₽ / {$p_uan}₴\n";
            }
            $full_msg_text .= $promoAdminLine;
            $full_msg_text .= "\n📝 <b>ТЕХНИЧЕСКОЕ ЗАДАНИЕ:</b>\n";
            $full_msg_text .= "<pre>" . htmlspecialchars($details) . "</pre>\n";
            $full_msg_text .= "🌐 <b>IP:</b> <code>{$user_ip}</code>";

            $clean_tg = str_replace(['@', 'https://t.me/'], '', $telegram_raw);
            // Новая структура (вместо 6-7 кнопок одним полотном): сначала
            // только "Принять заказ" / "Отклонить" / "Написать клиенту",
            // а конкретные варианты (обычный/срочный/сотрудничество/очередь,
            // без объяснения/причина/бан) открываются подменю по клику —
            // см. orderTopMenuKeyboard() в bot.php.
            $keyboard = ['inline_keyboard' => [
                [
                    ['text' => '🟢 Принять заказ', 'callback_data' => "adm_menu_accept_{$order_id}"],
                    ['text' => '🔴 Отклонить',      'callback_data' => "adm_menu_decline_{$order_id}"],
                ],
                [
                    ['text' => '💬 Написать клиенту', 'url' => "https://t.me/{$clean_tg}"],
                ],
            ]];

            // ✅ Сначала ВСЕГДА отправляем полный текст заказа с кнопками —
            // раньше он клеился как caption к первому фото, и если отправка
            // фото падала (например при локальном фолбэке без Cloudinary —
            // filename не является валидным URL для sendPhoto), пропадала
            // вообще вся информация по заказу. Теперь текст гарантированно
            // уходит отдельным сообщением независимо от судьбы фото.
            $ch = curl_init("https://api.telegram.org/bot{$bot_token}/sendMessage");
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_POSTFIELDS => http_build_query([
                    'chat_id'      => $my_chat_id,
                    'text'         => $full_msg_text,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => json_encode($keyboard),
                ])]);
            $mainMsgResp = curl_exec($ch); $mainMsgErr = curl_error($ch); curl_close($ch);
            if ($mainMsgResp === false || (is_string($mainMsgResp) && str_contains($mainMsgResp, '"ok":false'))) {
                error_log("[order.php] sendMessage (order info) for order #{$order_id} failed: " . ($mainMsgErr ?: substr((string)$mainMsgResp, 0, 300)));
            }

            // Разделяем референсы: полноценные https-ссылки (Cloudinary или
            // refs_urls из архива) — можно слать альбомом по URL как раньше;
            // голые имена файлов (локальный фолбэк без Cloudinary) — это
            // путь на диске, а не URL, sendPhoto/sendMediaGroup с ним не
            // сработает вообще.
            $cloud_refs = [];
            $local_refs = [];
            foreach ($example_imgs as $ref) {
                if ($ref === '') continue;
                if (preg_match('~^https?://~i', $ref)) {
                    $cloud_refs[] = $ref;
                } else {
                    $local_refs[] = $ref;
                }
            }

            if (!empty($cloud_refs)) {
                // Несколько фото — sendMediaGroup через URL, БАТЧАМИ по 10
                // (жёсткий лимит Telegram на альбом — при вызове с 11+ элементами
                // Telegram API отклоняет ВЕСЬ запрос целиком, и раньше это тихо
                // проглатывалось, потому что curl_exec() результат не проверялся).
                $batches = array_chunk($cloud_refs, 10);
                foreach ($batches as $bi => $batch) {
                    if (count($batch) === 1) {
                        $ch = curl_init("https://api.telegram.org/bot{$bot_token}/sendPhoto");
                        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                            CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $my_chat_id, 'photo' => $batch[0]])]);
                        $spResp = curl_exec($ch); $spErr = curl_error($ch); curl_close($ch);
                        if ($spResp === false || (is_string($spResp) && str_contains($spResp, '"ok":false'))) {
                            error_log("[order.php] sendPhoto for order #{$order_id} failed: " . ($spErr ?: substr((string)$spResp, 0, 300)));
                        }
                    } else {
                        $mediaPayload = array_map(fn($url) => ['type' => 'photo', 'media' => $url], $batch);
                        $ch = curl_init("https://api.telegram.org/bot{$bot_token}/sendMediaGroup");
                        curl_setopt_array($ch, [
                            CURLOPT_POST           => true,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT        => 30,
                            CURLOPT_POSTFIELDS     => [
                                'chat_id' => $my_chat_id,
                                'media'   => json_encode($mediaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ],
                        ]);
                        $mgResp = curl_exec($ch);
                        $mgErr  = curl_error($ch);
                        curl_close($ch);
                        if ($mgResp === false || (is_string($mgResp) && str_contains($mgResp, '"ok":false'))) {
                            error_log("[order.php] sendMediaGroup batch " . ($bi + 1) . "/" . count($batches) . " for order #{$order_id} failed: " . ($mgErr ?: substr((string)$mgResp, 0, 300)));
                        }
                    }
                    if ($bi < count($batches) - 1) usleep(400000); // пауза между альбомами — не упереться в rate-limit
                }
            }

            if (!empty($local_refs)) {
                // Локальные файлы (без Cloudinary) — шлём НАСТОЯЩИМ файлом,
                // а не sendPhoto: Telegram пережимает фото при отправке через
                // sendPhoto/sendMediaGroup, из-за чего терялось качество.
                // Несколько файлов — собираем в один zip-архив (папку с фото
                // заказа) и грузим его целиком; если файл один — шлём как есть.
                sendLocalOrderFilesAsDocument($bot_token, $my_chat_id, $order_id, $local_refs);
            }
        }

        // ── Post-Redirect-Get ──────────────────────────────────────────
        // Раньше успешная отправка рендерилась ПРЯМО в ответе на этот же
        // POST-запрос. Из-за этого если человек случайно обновлял страницу
        // браузер повторно слал ТОТ ЖЕ POST, что создавало вторую попытку
        // заказа и упиралось в антиспам-задержку — вместо экрана "Заказ
        // отправлен" человек внезапно видел "слишком много заявок, подождите
        // 5 минут", хотя первый заказ уже прекрасно создался. Редирект на
        // GET решает это полностью: повторное обновление просто повторяет
        // GET, ничего заново не создавая.
        $redirectUrl = $_SERVER['PHP_SELF'] . '?service=' . urlencode($service_key) . '&sent=' . $order_id;
        header('Location: ' . $redirectUrl);
        exit;

    } catch (PDOException $e) {
        $error_msg = "❌ Ошибка БД: " . $e->getMessage();
    }
}

function tgEsc(string $text): string {
    return str_replace(
        ['_','*','[',']','(',')', '~','`','>','#','+','-','=','|','{','}','.','!'],
        ['\_','\*','\[','\]','\(','\)','\~','\`','\>','\#','\+','\-','\=','\|','\{','\}','\.', '\!'],
        $text
    );
}
function tgEscapeSend(string $token, int $chat_id, string $text, string $photoPath = ''): void {
    if ($photoPath !== '' && is_file($photoPath)) {
        $ch = curl_init("https://api.telegram.org/bot{$token}/sendPhoto");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'chat_id'    => $chat_id,
            'caption'    => $text,
            'parse_mode' => 'MarkdownV2',
            'photo'      => new CURLFile($photoPath),
        ]);
        curl_exec($ch);
        curl_close($ch);
        return;
    }
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'chat_id'    => $chat_id,
        'text'       => $text,
        'parse_mode' => 'MarkdownV2',
    ]));
    curl_exec($ch);
    curl_close($ch);
}


function slotFormFields(int $slot, array $services, string $selectedService, string $turnstileSiteKey, bool $showIdentitySave = false, string $submitLabel = ''): string {
    $s = $slot;
    if ($submitLabel === '') $submitLabel = "Отправить заказ №{$s}";
    // Если ни одна услуга не совпала с $selectedService — активной делаем первую
    // карточку (раньше это был <select>, где браузер сам подсвечивал первый
    // <option> по умолчанию; с чипсами это нужно явно посчитать в PHP).
    $activeServiceKey = $selectedService;
    if ($activeServiceKey === '' || !in_array($activeServiceKey, array_column($services, 'category_key'), true)) {
        $activeServiceKey = $services[0]['category_key'] ?? '';
    }
    ob_start(); ?>
        <div class="wizard-progress">
            <div class="wizard-progress-step active" data-progress-step="1">
                <span class="wizard-progress-num">1</span>
                <span class="wizard-progress-label">Услуга и ТЗ</span>
            </div>
            <div class="wizard-progress-line"></div>
            <div class="wizard-progress-step" data-progress-step="2">
                <span class="wizard-progress-num">2</span>
                <span class="wizard-progress-label">Параметры</span>
            </div>
            <div class="wizard-progress-line"></div>
            <div class="wizard-progress-step" data-progress-step="3">
                <span class="wizard-progress-num">3</span>
                <span class="wizard-progress-label">Подтверждение</span>
            </div>
        </div>

        <div class="wizard-step" data-step="1">
        <div class="mb16">
            <?php if ($showIdentitySave): ?>
            <div class="order-label-row">
                <label class="order-label" style="margin-bottom:0;">Ваше имя / никнейм</label>
                <label class="save-identity-toggle" title="Запомнить имя и Telegram на этом устройстве">
                    <input type="checkbox" id="remember-identity-cb" checked>
                    <span class="save-identity-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    </span>
                </label>
            </div>
            <?php else: ?>
            <label class="order-label">Ваше имя / никнейм</label>
            <?php endif; ?>
            <input type="text" name="username" <?= $showIdentitySave ? 'id="remember-username"' : '' ?> required placeholder="Например: Влад" class="order-input">
        </div>
        <div class="mb16">
            <label class="order-label">Контакт (Telegram @username — обязательно)</label>
            <input type="text" name="telegram" <?= $showIdentitySave ? 'id="remember-telegram"' : '' ?> required placeholder="@username" class="order-input">
        </div>
        <div class="mb16">
            <label class="order-label">Что вас интересует?</label>
            <input type="hidden" name="service" id="s<?= $s ?>_service" value="<?= htmlspecialchars($activeServiceKey) ?>">
            <div class="service-chip-grid" data-slot="<?= $s ?>">
                <?php foreach ($services as $sv): ?>
                <button type="button" class="service-chip <?= ($activeServiceKey === $sv['category_key']) ? 'active' : '' ?>" data-value="<?= htmlspecialchars($sv['category_key']) ?>">
                    <span class="service-chip-title"><?= htmlspecialchars($sv['title']) ?></span>
                    <span class="service-chip-price"><?= $sv['price_uan'] ?> ₴ · <?= $sv['price_rub'] ?> ₽</span>
                    <svg class="service-chip-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="mb16">
            <label class="order-label">Детали заказа (ТЗ, пожелания)</label>
            <div class="tz-textarea-wrap">
                <textarea name="details" id="s<?= $s ?>_details" required placeholder="Опиши цвета, персонажей, текст, стиль..." class="order-textarea tz-textarea"></textarea>
                <button type="button" class="ai-tz-help-btn" onclick="window.openAiWidgetPanel && window.openAiWidgetPanel('tz','s<?= $s ?>_details')">✨ AI</button>
            </div>
        </div>
        <div class="file-upload-block mb22" data-slot="<?= $s ?>">
            <input type="file" name="example_photos[]" accept="image/*,.psd,.ai,.pdf,.zip,.rar,.7z,.fig,.sketch,.cdr,.eps" multiple id="s<?= $s ?>_refs" class="file-input-hidden">
            <div class="file-dropzone" id="s<?= $s ?>_refs_dropzone">
                <div class="file-label-row">
                    <span class="file-label-title">🖼️ Референсы и исходники</span>
                    <span class="file-count-badge" id="s<?= $s ?>_refs_count">0 / 40</span>
                    <label for="s<?= $s ?>_refs" class="file-choose-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        Выбрать файлы
                    </label>
                </div>
                <div class="file-dropzone-hint">или перетащи файлы сюда</div>
            </div>
            <div class="file-name-display" id="s<?= $s ?>_refs_name">Файлы не выбраны</div>
            <div class="file-preview-list" id="s<?= $s ?>_refs_preview"></div>
            <div class="file-hint">Зажми Ctrl (Win) или Cmd (Mac) чтобы выбрать несколько · до 40 шт.</div>
        </div>
        <div class="wizard-nav">
            <button type="button" class="wizard-btn-next" data-wizard-next>Далее →</button>
        </div>
        </div><!-- /wizard-step 1 -->

        <div class="wizard-step" data-step="2" style="display:none;">
        <div class="mb16">
            <div class="coop-card">
                <div class="coop-card-head">
                    <div class="coop-card-icon">🤝</div>
                    <div class="coop-card-title">Сотрудничество / Бартер</div>
                    <span class="coop-card-badge">0 ₽ / 0 ₴</span>
                </div>
                <label class="coop-toggle-row">
                    <span>Если приму такой заказ — оплата не потребуется</span>
                    <span class="coop-toggle">
                        <input type="checkbox" name="cooperation" value="1">
                        <span class="coop-toggle-track"><span class="coop-toggle-thumb"></span></span>
                    </span>
                </label>
            </div>
        </div>
        <div class="mb16">
            <label class="order-label">Срок выполнения (пожелание — финальное решение за дизайнером)</label>
            <div class="urgency-select-row">
                <label class="urgency-option">
                    <input type="radio" name="requested_urgency" value="normal" checked>
                    <span class="urgency-option-card">
                        <div class="u-title">Обычный</div>
                        <div class="u-sub">5 дней</div>
                    </span>
                </label>
                <label class="urgency-option urgent">
                    <input type="radio" name="requested_urgency" value="urgent">
                    <span class="urgency-option-card">
                        <div class="u-title">Срочный</div>
                        <div class="u-sub">24ч, +50%</div>
                    </span>
                </label>
            </div>
        </div>
        <div class="mb16">
            <label class="order-label">Промокод (необязательно)</label>
            <div class="promo-input-row">
                <input type="text" name="promo_code" id="s<?= $s ?>_promo" class="order-input promo-input" placeholder="Есть промокод? Впиши его сюда" autocomplete="off">
                <span class="promo-apply-btn" id="s<?= $s ?>_promo_check"></span>
            </div>
            <div class="promo-hint" id="s<?= $s ?>_promo_hint"></div>
        </div>
        <div class="turnstile-wrap">
            <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($turnstileSiteKey) ?>" data-theme="dark" data-size="normal"></div>
        </div>
        <div class="wizard-nav">
            <button type="button" class="wizard-btn-back" data-wizard-prev>← Назад</button>
            <button type="button" class="wizard-btn-next" data-wizard-next>Далее →</button>
        </div>
        </div><!-- /wizard-step 2 -->

        <div class="wizard-step" data-step="3" style="display:none;">
        <div class="confirm-timeline">
            <div class="confirm-timeline-item done">
                <div class="confirm-timeline-icon">📄</div>
                <div class="confirm-timeline-body">
                    <div class="confirm-timeline-title">ТЗ отправлено</div>
                    <div class="confirm-timeline-sub">Заказ сформирован и передан дизайнеру</div>
                </div>
            </div>
            <div class="confirm-timeline-item">
                <div class="confirm-timeline-icon">⏳</div>
                <div class="confirm-timeline-body">
                    <div class="confirm-timeline-title">Ожидание подтверждения</div>
                    <div class="confirm-timeline-sub">Дизайнер проверяет детали ТЗ</div>
                </div>
            </div>
            <div class="confirm-timeline-item">
                <div class="confirm-timeline-icon">💳</div>
                <div class="confirm-timeline-body">
                    <div class="confirm-timeline-title">Оплата и реквизиты</div>
                    <div class="confirm-timeline-sub">После принятия заказа реквизиты и форма загрузки чека откроются в боте и профиле</div>
                </div>
            </div>
        </div>
        <div class="order-summary-card">
            <div class="order-summary-row"><span>Услуга</span><b class="js-summary-service">—</b></div>
            <div class="order-summary-row"><span>Срочность</span><b class="js-summary-urgency">Обычный (5 дней)</b></div>
            <div class="order-summary-row order-summary-total"><span>Итого</span><b class="js-summary-price">—</b></div>
        </div>
        <div class="wizard-nav" style="margin-bottom:10px;">
            <button type="button" class="wizard-btn-back" data-wizard-prev>← Назад</button>
        </div>
        <div style="display:grid;gap:8px;margin-top:0;">
            <button type="submit" class="order-submit" style="margin-top:0;"><?= htmlspecialchars($submitLabel) ?></button>
            <button type="button" class="btn-archive-small" onclick="archiveSlot(<?= $s ?>)">📦 Архивировать заказ</button>
        </div>
        </div><!-- /wizard-step 3 -->
    <?php
    return ob_get_clean();
}

render_page:
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Заполнить ТЗ для работы | Kostlim Design</title>
<link rel="icon" type="image/png" href="/assets/notify/fav.png" sizes="16x16">
<link rel="apple-touch-icon" href="/assets/notify/fav.png">
<link rel="stylesheet" href="style.css">
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<script>
// ── TG Banner polling ──
var bannerPollInterval = null;

function copyBannerCode() {
    var el = document.getElementById('bannerCode');
    if (!el) return;
    var text = el.textContent.trim();
    navigator.clipboard.writeText(text).then(function() {
        var btn = document.querySelector('.tg-banner-copy');
        if (btn) { btn.textContent = '✅ Скопировано'; setTimeout(function(){ btn.textContent = 'Копировать'; }, 2000); }
    }).catch(function() {
        var tmp = document.createElement('textarea');
        tmp.value = text; document.body.appendChild(tmp);
        tmp.select(); document.execCommand('copy'); document.body.removeChild(tmp);
        var btn = document.querySelector('.tg-banner-copy');
        if (btn) { btn.textContent = '✅ Скопировано'; setTimeout(function(){ btn.textContent = 'Копировать'; }, 2000); }
    });
}

function startBannerPolling() {
    var w = document.getElementById('bannerWaiting');
    if (w) w.classList.add('show');
    if (bannerPollInterval) clearInterval(bannerPollInterval);
    bannerPollInterval = setInterval(checkBannerLinked, 3000);
}

function checkBannerLinked() {
    fetch('order.php?check_linked=1')
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.linked) {
                clearInterval(bannerPollInterval);
                var banner = document.getElementById('tgBanner');
                if (banner) {
                    banner.innerHTML = '<div class="tg-banner-icon" style="background:rgba(34,197,94,0.15);border-color:rgba(34,197,94,0.4);"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div><div class="tg-banner-body"><div class="tg-banner-title" style="color:#86efac;">✅ Telegram привязан! Уведомления придут в бот</div></div>';
                    banner.classList.add('tg-banner-linked');
                    banner.style.borderColor = 'rgba(34,197,94,0.4)';
                }
            }
        })
        .catch(function(){});
}
</script>
<style>
:root {
    --or: #f97316;
    --or2: #fb923c;
    --or-glow: 0 0 18px rgba(249,115,22,0.45), 0 0 40px rgba(249,115,22,0.18);
    --or-glow-sm: 0 0 10px rgba(249,115,22,0.5);
}
body::before {
    content: '';
    position: fixed;
    top: -80px; left: 50%;
    transform: translateX(-50%);
    width: 600px; height: 320px;
    background: radial-gradient(ellipse, rgba(249,115,22,0.12) 0%, transparent 70%);
    pointer-events: none; z-index: 0;
}
.order-wrap { max-width: 560px; margin: 50px auto; padding: 0 20px; position: relative; z-index: 1; }
.order-card { background: #111116; border: 1px solid #1f1f2a; padding: 32px; border-radius: 18px; box-shadow: 0 20px 60px rgba(0,0,0,.5); }
.order-back { color: var(--or); text-decoration: none; font-size: 13px; font-weight: 700; display:inline-flex; align-items:center; gap:6px; margin-bottom: 18px; transition: opacity .2s; }
.order-back:hover { opacity: .75; }
.order-back svg { width:13px; height:13px; }
.order-title { text-align: center; font-size: 19px; font-weight: 900; text-transform: uppercase; letter-spacing: 1.5px; color: #fff; margin-bottom: 26px; }
.order-label { display: block; color: #8a8a96; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
.order-input, .order-select, .order-textarea {
    width: 100%; background: #16161f; border: 1px solid #262633; color: #fff;
    padding: 12px 14px; border-radius: 9px; font-size: 13px; font-family: inherit;
    transition: border-color .2s, box-shadow .2s; outline: none; box-sizing: border-box;
}
.order-input:focus, .order-select:focus, .order-textarea:focus {
    border-color: var(--or);
    box-shadow: 0 0 0 3px rgba(249,115,22,.14), var(--or-glow-sm);
}
.order-textarea { height: 110px; resize: vertical; }
.order-select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238a8a96' stroke-width='2.5'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; cursor: pointer;
}
.file-upload-block { border: 1.5px dashed #2a2a3a; border-radius: 12px; padding: 16px 18px; transition: border-color .2s, box-shadow .2s; background: rgba(249,115,22,0.025); }
.file-upload-block:hover { border-color: rgba(249,115,22,0.45); box-shadow: 0 0 14px rgba(249,115,22,0.12); }
.file-upload-block input[type="file"] { display: none; }
.file-label-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.file-label-title { color: #d8d8e0; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; flex: 1; }
.file-choose-btn {
    display: inline-flex; align-items: center; gap: 7px;
    background: linear-gradient(135deg, var(--or2), var(--or));
    border: none; border-radius: 8px; padding: 9px 16px; color: #fff;
    font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .7px;
    cursor: pointer; transition: all .2s; box-shadow: 0 4px 14px rgba(249,115,22,0.3);
    font-family: inherit; white-space: nowrap;
}
.file-choose-btn:hover { transform: translateY(-1px); box-shadow: var(--or-glow); }
.file-choose-btn svg { width: 13px; height: 13px; flex-shrink: 0; }
.file-name-display { font-size: 11px; color: #666678; font-style: italic; margin-top: 8px; min-height: 16px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.file-name-display.has-file { color: #86efac; font-style: normal; font-weight: 700; }
.file-hint { color: #555568; font-size: 10px; margin-top: 6px; line-height: 1.5; }
.file-dropzone { border-radius: 10px; transition: background .15s; }
.file-dropzone.dragover { background: rgba(249,115,22,0.08); box-shadow: inset 0 0 0 1.5px rgba(249,115,22,0.5); }
.file-dropzone-hint { font-size: 10px; color: #4a4a5c; margin-top: 6px; }
.file-preview-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.file-preview-item { position: relative; width: 64px; display: flex; flex-direction: column; align-items: center; gap: 4px; }
.file-preview-thumb { width: 64px; height: 64px; border-radius: 8px; background: #1a1a24; border: 1px solid #2a2a3a; display: flex; align-items: center; justify-content: center; font-size: 22px; overflow: hidden; }
.file-preview-thumb img { width: 100%; height: 100%; object-fit: cover; }
.file-preview-name { font-size: 9px; color: #666678; max-width: 64px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: center; }
.file-preview-remove { position: absolute; top: -6px; right: -6px; width: 18px; height: 18px; border-radius: 50%; border: none; background: #ef4444; color: #fff; font-size: 10px; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(0,0,0,.4); }
.file-preview-remove:hover { background: #dc2626; }

/* ── Wizard: 3-step progress + nav ── */
.wizard-progress { display: flex; align-items: center; margin-bottom: 22px; }
.wizard-progress-step { display: flex; flex-direction: column; align-items: center; gap: 6px; flex-shrink: 0; }
.wizard-progress-num {
    width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 900; background: #1a1a24; border: 1.5px solid #2a2a3a; color: #555568;
    transition: all .25s;
}
.wizard-progress-step.active .wizard-progress-num { border-color: #f97316; color: #fff; background: linear-gradient(135deg,#fb923c,#f97316); box-shadow: 0 0 14px rgba(249,115,22,.4); }
.wizard-progress-step.done .wizard-progress-num { border-color: #86efac; color: #86efac; background: rgba(134,239,172,.08); }
.wizard-progress-label { font-size: 9.5px; color: #666678; text-align: center; max-width: 70px; line-height: 1.3; }
.wizard-progress-step.active .wizard-progress-label { color: #fff; font-weight: 700; }
.wizard-progress-line { flex: 1; height: 1.5px; background: #2a2a3a; margin: 0 4px 20px; }
.wizard-step { animation: wizardFadeIn .28s ease; }
@keyframes wizardFadeIn { from { opacity: 0; transform: translateX(8px); } to { opacity: 1; transform: translateX(0); } }
.wizard-nav { display: flex; gap: 10px; margin-top: 6px; }
.wizard-btn-next, .wizard-btn-back {
    flex: 1; border-radius: 10px; padding: 13px; font-weight: 900; font-size: 12.5px; letter-spacing: .3px;
    cursor: pointer; border: none; font-family: inherit; transition: transform .15s, box-shadow .15s;
}
.wizard-btn-next { background: linear-gradient(135deg,#fb923c,#f97316); color: #fff; box-shadow: var(--or-glow); }
.wizard-btn-next:hover { transform: translateY(-1px); }
.wizard-btn-back { background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1); color: #8a8a96; flex: 0 0 auto; padding: 13px 18px; }
.wizard-btn-back:hover { background: rgba(255,255,255,.1); }
.wizard-confirm-note {
    background: rgba(249,115,22,.06); border: 1px solid rgba(249,115,22,.2); border-radius: 12px;
    padding: 14px 16px; font-size: 12px; line-height: 1.6; color: #c9c9d4; margin: 4px 0 14px;
}
.turnstile-wrap { display: flex; justify-content: center; margin-bottom: 18px; }
.order-submit {
    width: 100%; background: linear-gradient(135deg, var(--or2), var(--or));
    color: #fff; border: none; padding: 15px; border-radius: 10px; font-weight: 900; cursor: pointer;
    text-transform: uppercase; font-size: 13px; letter-spacing: 1.5px;
    box-shadow: var(--or-glow); transition: opacity .2s, transform .2s, box-shadow .2s;
    font-family: inherit; margin-top: 6px;
}
.order-submit:hover { opacity: .92; transform: translateY(-2px); box-shadow: 0 0 30px rgba(249,115,22,.65), 0 8px 28px rgba(249,115,22,.3); }
.req-block { background: #0e0e16; border: 1px solid #1e1e2c; border-radius: 14px; padding: 20px; margin-bottom: 22px; }
.req-block h3 { color: var(--or); font-size: 12px; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 14px; text-shadow: var(--or-glow-sm); }
.req-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.req-row:last-child { margin-bottom: 0; }
.req-icon { width: 34px; height: 34px; border-radius: 9px; background: #1a1a28; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; font-weight: 900; color: var(--or); border: 1px solid rgba(249,115,22,0.2); }
.req-info { flex: 1; min-width: 0; }
.req-info span { display: block; font-size: 9px; color: #666678; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; }
.req-val { color: #e0e0ec; font-size: 12px; font-family: monospace; word-break: break-all; }
.req-link { color: var(--or); text-decoration: none; font-size: 12px; font-weight: 700; }
.req-link:hover { text-shadow: var(--or-glow-sm); }
.copy-btn { background: #1a1a28; border: 1px solid #2a2a3a; color: #8a8a96; padding: 5px 10px; border-radius: 7px; cursor: pointer; font-size: 10px; font-weight: 800; transition: color .2s, border-color .2s, box-shadow .2s; white-space: nowrap; font-family: inherit; flex-shrink: 0; }
.copy-btn:hover { color: var(--or); border-color: var(--or); box-shadow: var(--or-glow-sm); }
.rules-card { background: #111116; border: 1px solid #1f1f2a; padding: 32px; border-radius: 18px; box-shadow: 0 20px 60px rgba(0,0,0,.5); }
.rules-agree-btn { display: block; background: linear-gradient(135deg, var(--or2), var(--or)); color: #fff; text-align: center; text-decoration: none; padding: 14px 16px; border-radius: 9px; font-weight: 900; text-transform: uppercase; font-size: 12px; letter-spacing: 1px; box-shadow: var(--or-glow); transition: opacity .2s, transform .2s; }
.rules-agree-btn:hover { opacity: .9; transform: translateY(-1px); }
.rules-agree-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
    box-shadow: none;
    filter: grayscale(0.5);
    transform: none;
}
@keyframes rulesReadyPulse {
    0%   { box-shadow: 0 0 0 0 rgba(249,115,22,.55); }
    70%  { box-shadow: 0 0 0 10px rgba(249,115,22,0); }
    100% { box-shadow: 0 0 0 0 rgba(249,115,22,0); }
}
.rules-agree-btn.is-ready { animation: rulesReadyPulse 1s ease-out; }
/* ── Стилизованный чекбокс согласия с анимацией ── */
.agree-check-row { display: flex; align-items: center; gap: 12px; cursor: pointer; user-select: none; }
.agree-check-row input[type="checkbox"] { position: absolute; opacity: 0; width: 0; height: 0; }
.agree-check-box {
    position: relative;
    flex-shrink: 0;
    width: 24px; height: 24px;
    border-radius: 7px;
    border: 2px solid #2a2a3a;
    background: #171720;
    transition: border-color .2s, background .2s, transform .15s;
}
.agree-check-box svg {
    position: absolute; inset: 0; margin: auto;
    width: 15px; height: 15px;
    stroke: #fff; fill: none; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round;
    stroke-dasharray: 20; stroke-dashoffset: 20;
    transition: stroke-dashoffset .25s ease .05s;
}
.agree-check-row input[type="checkbox"]:checked ~ .agree-check-box {
    background: linear-gradient(135deg, var(--or2), var(--or));
    border-color: var(--or);
    box-shadow: var(--or-glow-sm);
    transform: scale(1.06);
}
.agree-check-row input[type="checkbox"]:checked ~ .agree-check-box svg { stroke-dashoffset: 0; }
.agree-check-row input[type="checkbox"]:focus-visible ~ .agree-check-box { outline: 2px solid var(--or); outline-offset: 2px; }
.agree-check-label { color: #c8c8d4; font-size: 13px; font-weight: 700; }

/* ── Тумблер "Сотрудничество" (вместо белого нативного чекбокса) ── */
.coop-toggle-row { display:flex; align-items:center; justify-content:space-between; gap:14px; cursor:pointer; width:100%; }
.coop-toggle-row span:first-child { color:#c8c8d4; font-size:13px; font-weight:700; line-height:1.4; }
.coop-toggle { position:relative; flex-shrink:0; width:46px; height:26px; }
.coop-toggle input { position:absolute; opacity:0; width:0; height:0; }
.coop-toggle-track {
    position:absolute; inset:0; background:#1c1c26; border:2px solid #2a2a3a;
    border-radius:999px; transition:background .2s, border-color .2s;
}
.coop-toggle-thumb {
    position:absolute; top:2px; left:2px; width:18px; height:18px;
    background:#5a5a68; border-radius:50%; transition:transform .2s ease, background .2s;
}
.coop-toggle input:checked ~ .coop-toggle-track { background:linear-gradient(135deg, var(--or2), var(--or)); border-color:var(--or); box-shadow:var(--or-glow-sm); }
.coop-toggle input:checked ~ .coop-toggle-track .coop-toggle-thumb { transform:translateX(20px); background:#fff; }
.coop-toggle input:focus-visible ~ .coop-toggle-track { outline:2px solid var(--or); outline-offset:2px; }

/* ── Выбор срочности прямо в форме (запрос клиента, финальное решение — за админом) ── */
.urgency-select-row { display:flex; gap:10px; margin-bottom:16px; }
.urgency-option { flex:1; display:block; position:relative; }
.urgency-option input { position:absolute; opacity:0; width:0; height:0; }
.urgency-option-card {
    display:block; text-align:center; padding:14px 10px; border-radius:12px;
    border:2px solid #2a2a3a; background:#171720; cursor:pointer;
    transition:border-color .18s, background .18s, transform .15s;
}
.urgency-option-card .u-title { font-weight:900; font-size:13px; text-transform:uppercase; letter-spacing:.4px; color:#c8c8d4; }
.urgency-option-card .u-sub { font-size:11px; color:#6a6a76; margin-top:4px; }
.urgency-option input:checked ~ .urgency-option-card {
    border-color: var(--or); background: linear-gradient(135deg, rgba(251,146,60,.12), rgba(249,115,22,.08));
    transform: translateY(-1px);
}
.urgency-option input:checked ~ .urgency-option-card .u-title { color: var(--or); }
.urgency-option.urgent input:checked ~ .urgency-option-card { border-color:#ef4444; background:linear-gradient(135deg, rgba(239,68,68,.14), rgba(239,68,68,.06)); }
.urgency-option.urgent input:checked ~ .urgency-option-card .u-title { color:#ef4444; }
.urgency-option input:focus-visible ~ .urgency-option-card { outline:2px solid var(--or); outline-offset:2px; }
.msg-success { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.35); color: #86efac; padding: 14px 16px; border-radius: 10px; text-align: center; margin-bottom: 20px; font-weight: 700; font-size: 13px; }
.msg-error { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.35); color: #fca5a5; padding: 14px 16px; border-radius: 10px; text-align: center; margin-bottom: 20px; font-size: 13px; }
.mb16 { margin-bottom: 16px; }
.mb22 { margin-bottom: 22px; }

/* ══ TG BANNER ══ */
.tg-banner {
    display: flex;
    align-items: center;
    gap: 14px;
    background: linear-gradient(135deg, rgba(249,115,22,0.1), rgba(249,115,22,0.04));
    border: 1px solid rgba(249,115,22,0.45);
    border-radius: 13px;
    padding: 14px 16px;
    margin-bottom: 22px;
    flex-wrap: wrap;
    position: relative;
}
.tg-banner-linked {
    background: linear-gradient(135deg, rgba(34,197,94,0.08), rgba(34,197,94,0.03));
    border-color: rgba(34,197,94,0.35);
}
.tg-banner-icon {
    width: 42px; height: 42px; flex-shrink: 0;
    background: rgba(249,115,22,0.15);
    border: 1px solid rgba(249,115,22,0.4);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
}
.tg-banner-body { flex: 1; min-width: 0; }
.tg-banner-title {
    color: #fdba74;
    font-size: 12px;
    font-weight: 800;
    margin-bottom: 6px;
    line-height: 1.4;
}
.tg-banner-code-row {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    margin-bottom: 4px;
}
.tg-banner-code {
    font-family: monospace;
    font-size: 14px;
    font-weight: 900;
    color: #fb923c;
    letter-spacing: 2px;
    background: rgba(0,0,0,0.3);
    padding: 3px 10px;
    border-radius: 6px;
    border: 1px solid rgba(249,115,22,0.25);
    user-select: all;
}
.tg-banner-copy {
    background: rgba(249,115,22,0.18);
    border: 1px solid rgba(249,115,22,0.4);
    border-radius: 6px;
    padding: 4px 10px;
    color: #fdba74;
    font-size: 11px;
    font-weight: 800;
    cursor: pointer;
    transition: .2s;
    font-family: inherit;
}
.tg-banner-copy:hover { background: rgba(249,115,22,0.35); color: #fff; }
.tg-banner-hint {
    color: #666678;
    font-size: 10px;
    margin-top: 2px;
}
.tg-banner-btn {
    display: inline-flex; align-items: center; gap: 6px;
    background: linear-gradient(135deg, #fb923c, #f97316);
    color: #fff;
    text-decoration: none;
    padding: 9px 16px;
    border-radius: 8px;
    font-weight: 900;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    box-shadow: 0 0 14px rgba(249,115,22,0.4);
    transition: opacity .2s, transform .2s;
    white-space: nowrap;
    flex-shrink: 0;
}
.tg-banner-btn:hover { opacity: .9; transform: translateY(-1px); }
.tg-banner-waiting {
    display: none;
    align-items: center;
    gap: 6px;
    color: #fdba74;
    font-size: 11px;
    font-weight: 700;
    width: 100%;
    margin-top: 6px;
}
.tg-banner-waiting.show { display: flex; }
.tg-spinner-sm {
    width: 12px; height: 12px;
    border: 2px solid rgba(249,115,22,0.3);
    border-top-color: #f97316;
    border-radius: 50%;
    animation: spin .8s linear infinite;
    flex-shrink: 0;
}
@keyframes spin { to { transform: rotate(360deg); } }
@media(max-width:520px) {
    .tg-banner { flex-direction: column; align-items: flex-start; gap: 10px; }
    .tg-banner-btn { width: 100%; justify-content: center; }
    .tg-banner-code { font-size: 12px; letter-spacing: 1px; }
}
</style>
</head>
<body>

<div class="order-wrap">

<?php if (!empty($success_msg)): ?>
<?php
    // Определяем, не сам ли админ сейчас тестирует форму — ему одному даём
    // крестик-пропуск оценки, чтобы не мог накрутить себе рейтинг сайта.
    $isAdminTesting = false;
    try {
        $adminTgEnv = getenv('ADMIN_TELEGRAM_ID') ?: getenv('ADMIN_ID') ?: '';
        if ($adminTgEnv !== '') {
            $sidCheck = session_id();
            $linkCheck = $pdo->prepare("SELECT tg_id FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1");
            $linkCheck->execute([$sidCheck]);
            $tgIdCheck = (string)$linkCheck->fetchColumn();
            if ($tgIdCheck !== '' && $tgIdCheck === (string)$adminTgEnv) {
                $isAdminTesting = true;
            }
        }
    } catch (Throwable $e) {}
?>
<!-- Модальное окно: заказ отправлен + обязательная оценка удобства сайта -->
<div id="notify-modal" style="
    position:fixed;inset:0;z-index:9999;
    display:flex;align-items:center;justify-content:center;
    background:rgba(0,0,0,.75);backdrop-filter:blur(6px);
    animation:fadeInBg .3s ease;
">
    <div style="
        background:linear-gradient(145deg,#13131a,#1a1a24);
        border:1px solid rgba(249,115,22,.35);
        border-radius:20px;padding:32px 28px;
        max-width:400px;width:calc(100% - 32px);
        box-shadow:0 24px 80px rgba(0,0,0,.6),0 0 60px rgba(249,115,22,.1);
        text-align:center;
        animation:slideUp .35s cubic-bezier(.34,1.56,.64,1);
        position:relative;
    ">
        <!-- Закрыть (доступно сразу только админу — обычным клиентам нужно сначала оценить) -->
        <button id="notify-modal-x" onclick="<?= $isAdminTesting ? 'closeNotifyModal()' : 'return false;' ?>" style="
            position:absolute;top:14px;right:14px;
            background:rgba(255,255,255,.07);border:none;border-radius:50%;
            width:30px;height:30px;cursor:<?= $isAdminTesting ? 'pointer' : 'not-allowed' ?>;color:<?= $isAdminTesting ? '#8a8a96' : '#3a3a44' ?>;font-size:16px;
            display:flex;align-items:center;justify-content:center;
        " <?= $isAdminTesting ? '' : 'title="Сначала оцени сайт ниже 🙂"' ?>>✕</button>

        <!-- Конфетти эмодзи -->
        <div style="font-size:48px;margin-bottom:12px;line-height:1;">🎉</div>

        <div style="font-size:18px;font-weight:900;color:#fff;margin-bottom:6px;">
            Заказ #<?= (int)$order_id ?> отправлен!
        </div>
        <div style="font-size:13px;color:#8a8a96;margin-bottom:24px;line-height:1.6;">
            Дизайнер уже получил уведомление и скоро приступит к работе.
        </div>

        <!-- Оценка удобства сайта (обязательная — заменяет старый блок подписки) -->
        <div style="
            background:rgba(249,115,22,.06);
            border:1px solid rgba(249,115,22,.2);
            border-radius:14px;padding:20px 18px;margin-bottom:16px;
        ">
            <div style="font-size:13px;font-weight:800;color:#fff;margin-bottom:4px;">
                Насколько удобно пользоваться сайтом?
            </div>
            <div style="font-size:11px;color:#8a8a96;margin-bottom:14px;">
                Пара секунд — и мы станем удобнее 🙂
            </div>
            <div id="rating-stars" style="display:flex;justify-content:center;gap:8px;margin-bottom:16px;">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <button type="button" class="rating-star" data-star="<?= $i ?>" onclick="setRating(<?= $i ?>)" style="
                    background:none;border:none;cursor:pointer;font-size:32px;line-height:1;
                    color:#2a2a3a;transition:color .15s, transform .15s;padding:2px;
                ">★</button>
                <?php endfor; ?>
            </div>
            <button type="button" id="rating-ok-btn" onclick="submitRating(<?= (int)$order_id ?>)" disabled style="
                width:100%;border:none;border-radius:10px;padding:12px;cursor:not-allowed;
                background:#23232f;color:#5a5a68;font-weight:900;font-size:13px;
                text-transform:uppercase;letter-spacing:.5px;transition:.2s;
            ">Оцените, чтобы продолжить</button>
        </div>

        <div style="font-size:11px;color:#555568;line-height:1.5;margin-bottom:16px;">
            💬 Статус заказа и реквизиты придут в Telegram, как только дизайнер примет заказ.
        </div>

        <div style="display:flex;gap:10px;">
            <a href="<?= htmlspecialchars($bot_link) ?>" target="_blank" rel="noopener" style="
                flex:1;display:flex;align-items:center;justify-content:center;gap:6px;
                background:linear-gradient(135deg,#fb923c,#f97316);color:#fff;
                border-radius:10px;padding:11px;font-weight:900;font-size:12px;text-decoration:none;
            ">✈️ В бот</a>
            <a href="/profile.php" style="
                flex:1;display:flex;align-items:center;justify-content:center;gap:6px;
                background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:#c9c9d4;
                border-radius:10px;padding:11px;font-weight:900;font-size:12px;text-decoration:none;
            ">👤 Личный кабинет</a>
        </div>
    </div>
</div>

<style>
@keyframes fadeInBg  { from{opacity:0} to{opacity:1} }
@keyframes slideUp   { from{opacity:0;transform:translateY(30px) scale(.95)} to{opacity:1;transform:translateY(0) scale(1)} }
.rating-star.active { color: #f97316 !important; transform: scale(1.12); }
</style>

<script>
var __currentRating = 0;
function setRating(n) {
    __currentRating = n;
    document.querySelectorAll('.rating-star').forEach(function(btn) {
        btn.classList.toggle('active', parseInt(btn.dataset.star, 10) <= n);
    });
    var okBtn = document.getElementById('rating-ok-btn');
    okBtn.disabled = false;
    okBtn.style.cursor = 'pointer';
    okBtn.style.background = 'linear-gradient(135deg,#fb923c,#f97316)';
    okBtn.style.color = '#fff';
    okBtn.textContent = 'ОК';
}
function submitRating(orderId) {
    if (__currentRating < 1) return;
    var okBtn = document.getElementById('rating-ok-btn');
    okBtn.disabled = true;
    okBtn.textContent = 'Спасибо!';
    fetch('/rate_site.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ rating: __currentRating, order_id: orderId }),
    }).catch(function(){}).finally(function() {
        setTimeout(closeNotifyModal, 400);
    });
}
function closeNotifyModal() {
    var m = document.getElementById('notify-modal');
    if (m) { m.style.opacity='0'; m.style.transition='opacity .2s'; setTimeout(function(){ m.remove(); }, 200); }
}
<?php if ($isAdminTesting): ?>
// Админу разрешаем закрыть кликом по фону — это его собственный тест, не реальный клиент
document.getElementById('notify-modal').addEventListener('click', function(e) {
    if (e.target === this) closeNotifyModal();
});
<?php endif; ?>
</script>
<?php endif; ?>
<?php if (!empty($error_msg)): ?>
<div class="msg-error"><?= htmlspecialchars($error_msg) ?></div>
<?php endif; ?>

<?php if (!$rules_accepted): ?>

<form method="POST" class="rules-card">
    <?php if ($selected_service): ?>
        <input type="hidden" name="service" value="<?= htmlspecialchars($selected_service) ?>">
    <?php endif; ?>
    <div style="text-align:center; margin-bottom:22px;">
        <a href="index.php" class="order-back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            На главную к портфолио
        </a>
        <h2 style="color:#fff; margin:14px 0 8px; text-transform:uppercase; letter-spacing:1px; font-size:20px;">Правила заказа</h2>
        <p id="rules-hint" style="color:#8a8a96; margin:0; line-height:1.55; font-size:13px;">Ознакомьтесь с правилами и подтвердите согласие.</p>
    </div>
    <div id="rules-scroll" style="margin-bottom: 20px; padding-right: 2px; border-bottom: 1px solid #1f1f2a; padding-bottom: 20px;">
        <!-- Правила из БД (редактируются в Админке → Правила). Прокрутка отключена — весь текст виден сразу. -->
        <div style="color:#e0e0ec; font-size:13px; line-height:1.65;">
            <?= $orderRulesHtml ?>
        </div>
    </div>
    <div style="margin-bottom: 16px;">
        <label class="agree-check-row">
            <input type="checkbox" name="agree_rules" id="agree-checkbox" value="1" required>
            <span class="agree-check-box">
                <svg viewBox="0 0 24 24"><polyline points="4 12 10 18 20 6"/></svg>
            </span>
            <span class="agree-check-label">Я прочитал(а) и согласен(на) с правилами</span>
        </label>
    </div>
    <div style="margin-bottom: 20px;">
        <label class="agree-check-row">
            <input type="checkbox" name="dont_ask" value="1">
            <span class="agree-check-box">
                <svg viewBox="0 0 24 24"><polyline points="4 12 10 18 20 6"/></svg>
            </span>
            <span class="agree-check-label" style="color:#8a8a96;">Больше не спрашивать</span>
        </label>
    </div>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
        <button type="submit" id="agree-btn" name="accept_rules" class="rules-agree-btn" style="border:none; cursor:pointer; font-family:inherit;">Согласиться</button>
        <a href="index.php" style="background:#171720; color:#fff; text-align:center; text-decoration:none; padding:14px 16px; border-radius:9px; font-weight:900; text-transform:uppercase; border:1px solid #2a2a38; font-size:12px; letter-spacing:.8px; display:block;">Отказаться</a>
    </div>
</form>


<?php else: ?>

<style>
/* ═══ ТАБЫ ═══ */
.slots-wrap { max-width: 560px; margin: 0 auto; padding: 0 20px 40px; }
.order-card  { background:#111116; border:1px solid #1f1f2a; border-radius:18px; padding:28px; box-shadow:0 20px 60px rgba(0,0,0,.5); margin-bottom:10px; }

.slot-tabs-bar { display:flex; gap:6px; margin-bottom:10px; }
.slot-pill { flex:1; padding:9px 10px; border-radius:10px; border:1px solid #2a2a38; background:#0e0e16; color:#555568; font-size:11px; font-weight:800; cursor:pointer; font-family:inherit; text-align:center; transition:.15s; text-transform:uppercase; letter-spacing:.5px; }
.slot-pill.active { background:rgba(249,115,22,.15); border-color:#f97316; color:#f97316; }
.slot-pill:hover:not(.active) { border-color:#3a3a4a; color:#8a8a96; }

.slot-panel { display:none; }
.slot-panel.active { display:block; }

.add-order-btn {
    width:100%; background:linear-gradient(135deg,rgba(249,115,22,.15),rgba(249,115,22,.06));
    border:1.5px dashed rgba(249,115,22,.55); color:#f97316; border-radius:12px;
    padding:13px; font-size:13px; font-weight:900; cursor:pointer; font-family:inherit;
    letter-spacing:.5px; transition:.2s; display:flex; align-items:center; justify-content:center; gap:8px;
}
.add-order-btn:hover { background:rgba(249,115,22,.22); box-shadow:0 0 18px rgba(249,115,22,.25); }
.add-order-btn:disabled { opacity:.35; cursor:not-allowed; box-shadow:none; }

.btn-archive-small {
    width:100%; background:#16161f; border:1px solid #2a2a38; color:#8a8a96;
    border-radius:10px; padding:11px; font-size:11px; font-weight:800;
    cursor:pointer; font-family:inherit; text-transform:uppercase; letter-spacing:.5px; transition:.2s;
}
.btn-archive-small:hover { border-color:#f97316; color:#f97316; }

.order-label-row { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:6px; }
.order-label-row .order-label { margin-bottom:0; }

.promo-input-row { position:relative; display:flex; align-items:center; }
.promo-input { padding-right:40px !important; height:auto; }
.promo-check { position:absolute; right:12px; font-size:18px; pointer-events:none; }
.promo-hint { font-size:12px; margin-top:6px; min-height:1px; }
.promo-hint.valid { color:#4ade80; }
.promo-hint.invalid { color:#fb7185; }
.ai-tz-help-btn {
    position: absolute; right: 8px; bottom: 8px;
    background: rgba(249,115,22,.15); border: 1px solid rgba(249,115,22,.4); color: #fdba74;
    font-size: 11px; font-weight: 900; border-radius: 8px; padding: 7px 11px; cursor: pointer;
    font-family: inherit; white-space: nowrap; transition: .15s; box-shadow: 0 2px 10px rgba(0,0,0,.35);
}
.ai-tz-help-btn:hover { background: rgba(249,115,22,.28); border-color: #f97316; transform: translateY(-1px); }
.tz-textarea-wrap { position: relative; }
.tz-textarea { padding-bottom: 42px !important; }
.slot-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; padding-bottom:14px; border-bottom:1px solid #1f1f2a; }
.slot-header-title { font-size:14px; font-weight:900; color:#f97316; text-transform:uppercase; letter-spacing:1px; }
.btn-remove-slot { background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.2); color:#fca5a5; border-radius:7px; padding:6px 12px; font-size:11px; font-weight:800; cursor:pointer; font-family:inherit; transition:.15s; }
.btn-remove-slot:hover { background:rgba(239,68,68,.2); }

/* ── П.1: компактная иконка "запомнить данные" вместо строки текста ── */
.save-identity-toggle { display:inline-flex; align-items:center; cursor:pointer; user-select:none; flex-shrink:0; }
.save-identity-toggle input { position:absolute; opacity:0; width:0; height:0; }
.save-identity-icon {
    width:26px; height:26px; border-radius:7px; display:flex; align-items:center; justify-content:center;
    background:#1a1a24; border:1.5px solid #2a2a3a; color:#555568; transition:.18s;
}
.save-identity-icon svg { width:13px; height:13px; }
.save-identity-toggle input:checked ~ .save-identity-icon { background:rgba(249,115,22,.15); border-color:var(--or); color:var(--or); box-shadow:var(--or-glow-sm); }
.save-identity-toggle input:focus-visible ~ .save-identity-icon { outline:2px solid var(--or); outline-offset:2px; }

/* ── П.2: карточки услуг вместо <select> ── */
.service-chip-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.service-chip {
    position:relative; text-align:left; background:#16161f; border:1.5px solid #262633; border-radius:11px;
    padding:11px 30px 11px 12px; cursor:pointer; font-family:inherit; transition:border-color .18s, background .18s, transform .12s;
}
.service-chip:hover { border-color:#3a3a4c; }
.service-chip-title { display:block; font-size:12px; font-weight:800; color:#d8d8e0; line-height:1.35; }
.service-chip-price { display:block; font-size:11px; color:#8a8a96; margin-top:3px; font-weight:700; }
.service-chip.active {
    border-color:var(--or); background:linear-gradient(135deg, rgba(251,146,60,.14), rgba(249,115,22,.06));
    box-shadow: var(--or-glow-sm); transform: translateY(-1px);
}
.service-chip.active .service-chip-title { color:#fff; }
.service-chip.active .service-chip-price { color:#fdba74; }
.service-chip-check {
    position:absolute; top:9px; right:9px; width:15px; height:15px; opacity:0; transform:scale(.6);
    transition:.18s; color:var(--or);
}
.service-chip.active .service-chip-check { opacity:1; transform:scale(1); }
@media(max-width:420px){ .service-chip-grid{ grid-template-columns:1fr; } }

/* ── П.4: счётчик файлов в шапке дропзоны ── */
.file-count-badge {
    font-size:10.5px; font-weight:800; color:#8a8a96; background:#1a1a24; border:1px solid #2a2a3a;
    border-radius:20px; padding:3px 10px; white-space:nowrap; flex-shrink:0;
}
.file-count-badge.has-files { color:var(--or); border-color:rgba(249,115,22,.4); background:rgba(249,115,22,.08); }

/* ── П.5: блок "Сотрудничество" отдельной карточкой ── */
.coop-card { background:#0e0e16; border:1px solid #1e1e2c; border-radius:14px; padding:16px 18px; }
.coop-card-head { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
.coop-card-icon { width:30px; height:30px; border-radius:9px; background:rgba(249,115,22,.12); border:1px solid rgba(249,115,22,.3); display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0; }
.coop-card-title { flex:1; font-size:12px; font-weight:900; text-transform:uppercase; letter-spacing:.6px; color:#e0e0ec; }
.coop-card-badge { font-size:10.5px; font-weight:800; color:#8a8a96; background:#16161f; border:1px solid #262633; border-radius:20px; padding:3px 10px; white-space:nowrap; }
.coop-card .coop-toggle-row span:first-child { font-size:12px; color:#8a8a96; font-weight:600; }

/* ── П.6: поле промокода — встроенная кнопка/индикатор применения ── */
.promo-apply-btn {
    position:absolute; right:5px; width:30px; height:30px; border-radius:7px;
    background:#1a1a24; border:1px solid #2a2a3a; color:#555568;
    display:flex; align-items:center; justify-content:center; pointer-events:none;
    transition:.18s; font-size:14px;
}
.promo-apply-btn.valid { background:rgba(34,197,94,.15); border-color:rgba(34,197,94,.5); color:#4ade80; }
.promo-apply-btn.invalid { background:rgba(239,68,68,.12); border-color:rgba(239,68,68,.4); color:#fb7185; }
.promo-hint.valid {
    display:inline-flex; align-items:center; gap:6px; color:#4ade80; background:rgba(34,197,94,.1);
    border:1px solid rgba(34,197,94,.3); border-radius:20px; padding:4px 10px; font-weight:800;
}

/* ── П.8: таймлайн + карточка итога на шаге 3 ── */
.confirm-timeline { display:grid; gap:0; margin-bottom:18px; }
.confirm-timeline-item { display:flex; gap:12px; position:relative; padding-bottom:22px; }
.confirm-timeline-item:last-child { padding-bottom:0; }
.confirm-timeline-item:not(:last-child)::before {
    content:''; position:absolute; left:15px; top:32px; bottom:0; width:1.5px; background:#262633;
}
.confirm-timeline-icon {
    width:32px; height:32px; border-radius:50%; background:#1a1a24; border:1.5px solid #2a2a3a;
    display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0; z-index:1;
}
.confirm-timeline-item.done .confirm-timeline-icon { border-color:#4ade80; background:rgba(74,222,128,.1); }
.confirm-timeline-body { padding-top:4px; }
.confirm-timeline-title { font-size:12.5px; font-weight:800; color:#e0e0ec; }
.confirm-timeline-sub { font-size:11px; color:#6a6a76; margin-top:2px; line-height:1.4; }

.order-summary-card { background:#0e0e16; border:1px solid #1e1e2c; border-radius:14px; padding:16px 18px; margin-bottom:16px; }
.order-summary-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:7px 0; font-size:12.5px; color:#8a8a96; border-bottom:1px solid #1a1a24; }
.order-summary-row:last-child { border-bottom:none; }
.order-summary-row b { color:#e0e0ec; font-weight:800; text-align:right; }
.order-summary-total { margin-top:2px; padding-top:11px; border-top:1px solid #262633 !important; }
.order-summary-total span { color:#fdba74; font-weight:800; font-size:11px; text-transform:uppercase; letter-spacing:.5px; }
.order-summary-total b { color:var(--or); font-size:15px; }

/* ── П.7: карточка Turnstile в стиле сайта ── */
.turnstile-wrap > div { border-radius:12px; overflow:hidden; }
</style>

<div class="slots-wrap">

<!-- ═══ ПИЛЮЛИ-ТАБЫ (видны когда > 1 слота) ═══ -->
<div class="slot-tabs-bar" id="slots-tabs-bar" style="display:none;">
    <button class="slot-pill active" id="pill-1" onclick="switchSlot(1)">📋 Заказ №1</button>
</div>

<!-- ═══ СЛОТ 1 ═══ -->
<div class="slot-panel active" id="slot-panel-1">
<div class="order-card">

    <!-- TG Баннер -->
    <?php if (!$isLinked): ?>
    <div class="tg-banner" id="tgBanner">
        <div class="tg-banner-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fb923c" stroke-width="2.2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        </div>
        <div class="tg-banner-body">
            <div class="tg-banner-title">Привяжи Telegram — получай уведомления о заказе</div>
            <?php if ($linkCode): ?>
            <div class="tg-banner-code-row">
                <span class="tg-banner-code" id="bannerCode">/customer_<?= htmlspecialchars($linkCode) ?></span>
                <button class="tg-banner-copy" onclick="copyBannerCode()">Копировать</button>
            </div>
            <div class="tg-banner-hint">1. Скопируй код &nbsp;→&nbsp; 2. Открой бот &nbsp;→&nbsp; 3. Отправь код в чат</div>
            <?php endif; ?>
        </div>
        <a href="https://t.me/kostlimdznbot?start=link_<?= htmlspecialchars($linkCode ?? '') ?>" target="_blank" class="tg-banner-btn" id="bannerOpenBtn" onclick="startBannerPolling()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
            Открыть бот
        </a>
        <div class="tg-banner-waiting" id="bannerWaiting"><span class="tg-spinner-sm"></span> Ожидаю…</div>
    </div>
    <?php else: ?>
    <div class="tg-banner tg-banner-linked">
        <div class="tg-banner-icon" style="background:rgba(34,197,94,0.15);border-color:rgba(34,197,94,0.4);">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="tg-banner-body"><div class="tg-banner-title" style="color:#86efac;">✅ Telegram привязан — уведомления придут в бот</div></div>
    </div>
    <?php endif; ?>

    <a href="index.php" class="order-back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        На главную к портфолио
    </a>
    <div class="order-title">📋 Заполнить ТЗ для работы</div>
    <div class="req-block"><h3>Оплата после принятия</h3><div class="req-row"><div class="req-info"><span>Сначала отправь ТЗ. Если дизайнер примет заказ, реквизиты и загрузка чека появятся в профиле и придут в Telegram.</span></div></div></div>
<form id="form-slot-1" action="" method="POST" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="rules_accepted" value="1">
        <input type="hidden" name="order_slot" value="1">
        <?php echo slotFormFields(1, $services, $selected_service, $turnstile_site_key, true, "Отправить заказ Kostlim'у"); ?>
    </form>

</div><!-- .order-card -->

<!-- Кнопка добавить заказ -->
<div style="margin-top:10px;">
    <button type="button" id="add-order-btn" class="add-order-btn" onclick="addSlot()">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Добавить ещё заказ
    </button>
</div>

</div><!-- #slot-panel-1 -->

<!-- ═══ СЛОТ 2 ═══ -->
<div class="slot-panel" id="slot-panel-2" style="display:none;">
<div class="order-card">
    <div class="slot-header">
        <div class="slot-header-title">📋 Заказ №2</div>
        <button class="btn-remove-slot" onclick="removeSlot(2)">✕ Удалить</button>
    </div>
    <form id="form-slot-2" action="" method="POST" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="rules_accepted" value="1">
        <input type="hidden" name="order_slot" value="2">
        <?php echo slotFormFields(2, $services, $selected_service, $turnstile_site_key); ?>
    </form>
</div>
</div>

<!-- ═══ СЛОТ 3 ═══ -->
<div class="slot-panel" id="slot-panel-3" style="display:none;">
<div class="order-card">
    <div class="slot-header">
        <div class="slot-header-title">📋 Заказ №3</div>
        <button class="btn-remove-slot" onclick="removeSlot(3)">✕ Удалить</button>
    </div>
    <form id="form-slot-3" action="" method="POST" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="rules_accepted" value="1">
        <input type="hidden" name="order_slot" value="3">
        <?php echo slotFormFields(3, $services, $selected_service, $turnstile_site_key); ?>
    </form>
</div>
</div>

<!-- Кнопка архива -->
<div id="archive-btn-wrap" style="display:none;margin-top:10px;">
    <button type="button" onclick="openArchiveList()" style="width:100%;background:#16161f;border:1px solid rgba(249,115,22,.3);color:#fdba74;border-radius:10px;padding:11px;font-size:11px;font-weight:800;cursor:pointer;font-family:inherit;text-transform:uppercase;letter-spacing:.5px;">
        📋 Архивированные заказы (<span id="archive-count">0</span>)
    </button>
</div>

</div><!-- .slots-wrap -->

<script>
// Данные об услугах (цены/названия) — для карточки итога на шаге 3 и клика по чипсам.
var SERVICES_DATA = <?= json_encode(array_column($services, null, 'category_key'), JSON_UNESCAPED_UNICODE) ?>;

// ─── П.2: клик по карточке услуги (замена <select>) ───
document.addEventListener('click', function(e) {
    var chip = e.target.closest('.service-chip');
    if (!chip) return;
    var grid = chip.closest('.service-chip-grid');
    if (!grid) return;
    grid.querySelectorAll('.service-chip').forEach(function(c) { c.classList.remove('active'); });
    chip.classList.add('active');
    var hidden = document.getElementById('s' + grid.dataset.slot + '_service');
    if (hidden) hidden.value = chip.dataset.value;
});

// ─── File input labels ───
document.addEventListener('DOMContentLoaded', function() {
    // Защита от повторной отправки формы двойным кликом/медленной сетью —
    // раньше это иногда приводило к тому, что второй запрос попадал под
    // антиспам-задержку сразу после того как первый уже успешно создал заказ.
    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function() {
            var btn = form.querySelector('button[type="submit"]');
            if (btn && !btn.disabled) {
                btn.disabled = true;
                btn.dataset.origText = btn.textContent;
                btn.textContent = '⏳ Отправляю...';
                btn.style.opacity = '.7';
                btn.style.cursor = 'not-allowed';
            }
        });
    });

    // Слот 1
    var s1Screenshot = document.getElementById('s1_screenshot'); if (s1Screenshot) s1Screenshot.addEventListener('change', function() {
        var el = document.getElementById('s1_screenshot_name');
        el.textContent = this.files[0] ? '✅ ' + this.files[0].name : 'Файл не выбран';
        el.classList.toggle('has-file', !!this.files[0]);
    });
    // Слоты 2 и 3 — скриншот
    [2,3].forEach(function(n) {
        var sc = document.getElementById('s'+n+'_screenshot');
        if (sc) sc.addEventListener('change', function() {
            var el = document.getElementById('s'+n+'_screenshot_name');
            el.textContent = this.files[0] ? '✅ ' + this.files[0].name : 'Файл не выбран';
            el.classList.toggle('has-file', !!this.files[0]);
        });
    });

    // Референсы/исходники: drag-and-drop, превью, удаление по одному, лимит 40
    [1,2,3].forEach(function(n) { initRefsDropzone(n); });

    // Wizard: показать шаг 1 во всех формах заказа при загрузке
    document.querySelectorAll('form[id^="form-slot-"]').forEach(function(form) {
        wizardShowStep(form, 1);
    });

    updateArchiveBtn();
});

// ─── Wizard: 3-шаговый мастер оформления заказа ───
function wizardValidateStep(stepEl) {
    var fields = stepEl.querySelectorAll('input[required], select[required], textarea[required]');
    for (var i = 0; i < fields.length; i++) {
        if (!fields[i].checkValidity()) {
            fields[i].reportValidity();
            fields[i].focus();
            return false;
        }
    }
    return true;
}

function wizardShowStep(form, n) {
    var steps = form.querySelectorAll('.wizard-step');
    steps.forEach(function(st) {
        st.style.display = (parseInt(st.dataset.step, 10) === n) ? 'block' : 'none';
    });
    var progress = form.querySelectorAll('.wizard-progress-step');
    progress.forEach(function(p) {
        var ps = parseInt(p.dataset.progressStep, 10);
        p.classList.toggle('active', ps === n);
        p.classList.toggle('done', ps < n);
    });
    form.dataset.wizardStep = n;
    if (n === 3) updateOrderSummary(form);
    if (n > 1) {
        var card = form.closest('.order-card');
        if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// ─── П.8: карточка итога заказа на шаге "Подтверждение" ───
function updateOrderSummary(form) {
    var slot = (form.id || '').replace('form-slot-', '') || '1';
    var serviceInput = document.getElementById('s' + slot + '_service') || form.querySelector('[name="service"]');
    var svc = serviceInput ? SERVICES_DATA[serviceInput.value] : null;
    var urgentInput = form.querySelector('input[name="requested_urgency"]:checked');
    var isUrgent = !!(urgentInput && urgentInput.value === 'urgent');
    var coopInput = form.querySelector('input[name="cooperation"]');
    var isCoop = !!(coopInput && coopInput.checked);

    var elService = form.querySelector('.js-summary-service');
    var elUrgency = form.querySelector('.js-summary-urgency');
    var elPrice   = form.querySelector('.js-summary-price');

    if (elService) elService.textContent = svc ? svc.title : '—';
    if (elUrgency) elUrgency.textContent = isUrgent ? '⚡ Срочно (24ч, +50%)' : 'Обычно (5 дней)';
    if (elPrice) {
        if (isCoop) {
            elPrice.textContent = '0 ₽ / 0 ₴ (сотрудничество)';
        } else if (svc) {
            var mult = isUrgent ? 1.5 : 1;
            var rub = Math.round(parseFloat(svc.price_rub) * mult);
            var uan = Math.round(parseFloat(svc.price_uan) * mult);
            elPrice.textContent = rub + ' ₽ / ' + uan + ' ₴';
        } else {
            elPrice.textContent = '—';
        }
    }
}

document.addEventListener('click', function(e) {
    var nextBtn = e.target.closest('[data-wizard-next]');
    if (nextBtn) {
        var form = nextBtn.closest('form');
        if (!form) return;
        var cur = parseInt(form.dataset.wizardStep || '1', 10);
        var curStepEl = form.querySelector('.wizard-step[data-step="' + cur + '"]');
        if (curStepEl && !wizardValidateStep(curStepEl)) return;
        wizardShowStep(form, cur + 1);
        return;
    }
    var prevBtn = e.target.closest('[data-wizard-prev]');
    if (prevBtn) {
        var form2 = prevBtn.closest('form');
        if (!form2) return;
        var cur2 = parseInt(form2.dataset.wizardStep || '1', 10);
        wizardShowStep(form2, Math.max(1, cur2 - 1));
    }
});


var REFS_MAX_FILES = 40;
var FILE_ICON_EXT = { psd:'🎨', ai:'🎨', eps:'🎨', cdr:'🎨', fig:'🧩', sketch:'🧩', pdf:'📄', zip:'🗜️', rar:'🗜️', '7z':'🗜️' };

function initRefsDropzone(slot) {
    var input = document.getElementById('s' + slot + '_refs');
    var dropzone = document.getElementById('s' + slot + '_refs_dropzone');
    var nameEl = document.getElementById('s' + slot + '_refs_name');
    var countEl = document.getElementById('s' + slot + '_refs_count');
    var previewEl = document.getElementById('s' + slot + '_refs_preview');
    if (!input || !dropzone || !previewEl) return;

    function currentFiles() { return Array.from(input.files || []); }

    function setInputFiles(files) {
        var dt = new DataTransfer();
        files.slice(0, REFS_MAX_FILES).forEach(function(f) { dt.items.add(f); });
        input.files = dt.files;
    }

    function renderPreview() {
        var files = currentFiles();
        previewEl.innerHTML = '';
        files.forEach(function(file, idx) {
            var item = document.createElement('div');
            item.className = 'file-preview-item';

            var thumb = document.createElement('div');
            thumb.className = 'file-preview-thumb';
            if (file.type && file.type.indexOf('image/') === 0) {
                var img = document.createElement('img');
                img.src = URL.createObjectURL(file);
                img.onload = function() { URL.revokeObjectURL(img.src); };
                thumb.appendChild(img);
            } else {
                var ext = (file.name.split('.').pop() || '').toLowerCase();
                thumb.textContent = FILE_ICON_EXT[ext] || '📎';
            }
            item.appendChild(thumb);

            var nm = document.createElement('span');
            nm.className = 'file-preview-name';
            nm.textContent = file.name;
            item.appendChild(nm);

            var rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'file-preview-remove';
            rm.setAttribute('aria-label', 'Удалить файл');
            rm.textContent = '✕';
            rm.onclick = function() {
                var next = currentFiles();
                next.splice(idx, 1);
                setInputFiles(next);
                refreshLabel();
                renderPreview();
            };
            item.appendChild(rm);

            previewEl.appendChild(item);
        });
    }

    function refreshLabel() {
        var files = currentFiles();
        if (files.length > 0) {
            nameEl.textContent = '✅ ' + files.length + ' / ' + REFS_MAX_FILES + ' файл(ов)';
            nameEl.classList.add('has-file');
        } else {
            nameEl.textContent = 'Файлы не выбраны';
            nameEl.classList.remove('has-file');
        }
        if (countEl) {
            countEl.textContent = 'Загружено: ' + files.length + ' / ' + REFS_MAX_FILES;
            countEl.classList.toggle('has-files', files.length > 0);
        }
    }

    function addFiles(newFiles) {
        var existing = currentFiles();
        var incoming = Array.from(newFiles);
        var merged = existing.concat(incoming);
        if (merged.length > REFS_MAX_FILES) {
            showToastMsg('⚠️ Максимум ' + REFS_MAX_FILES + ' файлов, лишние не добавлены', '#ef4444');
            merged = merged.slice(0, REFS_MAX_FILES);
        }
        setInputFiles(merged);
        refreshLabel();
        renderPreview();
    }

    input.addEventListener('change', function() {
        var picked = currentFiles();
        if (picked.length > REFS_MAX_FILES) {
            showToastMsg('⚠️ Максимум ' + REFS_MAX_FILES + ' файлов, лишние не добавлены', '#ef4444');
            picked = picked.slice(0, REFS_MAX_FILES);
            setInputFiles(picked);
        }
        refreshLabel();
        renderPreview();
    });

    ['dragenter','dragover'].forEach(function(evt) {
        dropzone.addEventListener(evt, function(e) {
            e.preventDefault(); e.stopPropagation();
            dropzone.classList.add('dragover');
        });
    });
    ['dragleave','drop'].forEach(function(evt) {
        dropzone.addEventListener(evt, function(e) {
            e.preventDefault(); e.stopPropagation();
            dropzone.classList.remove('dragover');
        });
    });
    dropzone.addEventListener('drop', function(e) {
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
            addFiles(e.dataTransfer.files);
        }
    });

    refreshLabel();

    var ownerForm = input.closest('form');
    if (ownerForm) {
        ownerForm.addEventListener('reset', function() {
            setTimeout(function() { refreshLabel(); renderPreview(); }, 0);
        });
    }
}

// ─── Таб-система ───
var _slots = [1];
var _currentSlot = 1;

function switchSlot(n) {
    _currentSlot = n;
    // Панели
    [1,2,3].forEach(function(i) {
        var p = document.getElementById('slot-panel-' + i);
        if (p) p.style.display = (i === n) ? 'block' : 'none';
    });
    // Пилюли
    document.querySelectorAll('.slot-pill').forEach(function(p) { p.classList.remove('active'); });
    var pill = document.getElementById('pill-' + n);
    if (pill) pill.classList.add('active');
}

function addSlot() {
    var next = null;
    for (var i = 2; i <= 3; i++) {
        if (_slots.indexOf(i) === -1) { next = i; break; }
    }
    if (!next) return;
    _slots.push(next);

    // Показать панель
    var panel = document.getElementById('slot-panel-' + next);
    if (panel) panel.style.display = 'block';

    // Показать таббар
    var bar = document.getElementById('slots-tabs-bar');
    if (bar) bar.style.display = 'flex';

    // Добавить пилюлю
    if (!document.getElementById('pill-' + next)) {
        var pill = document.createElement('button');
        pill.className = 'slot-pill';
        pill.id = 'pill-' + next;
        var slotNum = next;
        pill.textContent = '📋 Заказ №' + next;
        pill.onclick = function() { switchSlot(slotNum); };
        bar.appendChild(pill);
    }

    // Показать пилюлю слота 1 если её нет
    if (!document.getElementById('pill-1')) {
        var bar2 = document.getElementById('slots-tabs-bar');
        var pill1 = document.createElement('button');
        pill1.className = 'slot-pill';
        pill1.id = 'pill-1';
        pill1.textContent = '📋 Заказ №1';
        pill1.onclick = function() { switchSlot(1); };
        bar2.insertBefore(pill1, bar2.firstChild);
    }

    updateAddBtn();
    switchSlot(next);
}

function removeSlot(n) {
    var idx = _slots.indexOf(n);
    if (idx !== -1) _slots.splice(idx, 1);

    var panel = document.getElementById('slot-panel-' + n);
    if (panel) panel.style.display = 'none';

    var form = document.getElementById('form-slot-' + n);
    if (form) { form.reset(); wizardShowStep(form, 1); }

    var pill = document.getElementById('pill-' + n);
    if (pill) pill.remove();

    if (_slots.length <= 1) {
        var bar = document.getElementById('slots-tabs-bar');
        if (bar) bar.style.display = 'none';
    }

    updateAddBtn();
    switchSlot(1);
}

function updateAddBtn() {
    var btn = document.getElementById('add-order-btn');
    if (!btn) return;
    btn.disabled = _slots.length >= 3;
    btn.style.opacity = _slots.length >= 3 ? '.4' : '1';
}

// ─── Архив ───
var ARCHIVE_KEY = 'kostlim_order_archive';
function getArchive() { try { return JSON.parse(localStorage.getItem(ARCHIVE_KEY) || '[]'); } catch(e) { return []; } }
function saveArchive(arr) { localStorage.setItem(ARCHIVE_KEY, JSON.stringify(arr)); }

function updateArchiveBtn() {
    var archive = getArchive();
    var wrap = document.getElementById('archive-btn-wrap');
    var cnt = document.getElementById('archive-count');
    if (wrap) wrap.style.display = archive.length > 0 ? 'block' : 'none';
    if (cnt) cnt.textContent = archive.length;
}

async function uploadFileToCloudinary(file) {
    var fd = new FormData();
    fd.append('file', file);
    try {
        var resp = await fetch('upload_proxy.php', { method: 'POST', body: fd });
        var data = await resp.json();
        return data.ok ? data.url : null;
    } catch(e) { return null; }
}

// Грузит файлы пачками по `concurrency` штук параллельно (не все 40 разом и не строго
// по одному), чтобы не упереться в лимиты сервера/сети при архивировании крупного заказа.
async function uploadFilesBatched(files, concurrency, onProgress) {
    var urls = [];
    var done = 0;
    for (var i = 0; i < files.length; i += concurrency) {
        var chunk = files.slice(i, i + concurrency);
        var results = await Promise.all(chunk.map(function(f) { return uploadFileToCloudinary(f); }));
        results.forEach(function(u) { if (u) urls.push(u); });
        done += chunk.length;
        if (onProgress) onProgress(Math.min(done, files.length), files.length);
    }
    return urls;
}

function showToastMsg(msg, color) {
    var old = document.getElementById('order-toast');
    if (old) old.remove();
    var t = document.createElement('div');
    t.id = 'order-toast';
    t.textContent = msg;
    Object.assign(t.style, { position:'fixed', bottom:'30px', left:'50%', transform:'translateX(-50%)', background: color||'#f97316', color:'#fff', padding:'12px 24px', borderRadius:'10px', fontWeight:'800', fontSize:'13px', boxShadow:'0 4px 20px rgba(0,0,0,.4)', zIndex:'9999', fontFamily:'inherit', whiteSpace:'nowrap', transition:'opacity .4s' });
    document.body.appendChild(t);
    setTimeout(function() { t.style.opacity='0'; setTimeout(function(){ t.remove(); }, 400); }, 3000);
}

// ── Проверка промокода прямо в поле (галочка ✓, если код существует) ──
(function() {
    var promoTimers = {};
    document.querySelectorAll('.promo-input').forEach(function(input) {
        var id        = input.id; // sN_promo
        var checkIcon = document.getElementById(id + '_check');
        var hint      = document.getElementById(id.replace('_promo', '_promo_hint'));
        input.addEventListener('input', function() {
            var val = input.value.trim();
            clearTimeout(promoTimers[id]);
            if (checkIcon) { checkIcon.textContent = ''; checkIcon.className = 'promo-apply-btn'; }
            if (hint) { hint.textContent = ''; hint.className = 'promo-hint'; }
            if (val === '') return;
            if (checkIcon) checkIcon.textContent = '⏳';
            promoTimers[id] = setTimeout(function() {
                fetch('order.php?check_promo=' + encodeURIComponent(val))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.valid) {
                            if (checkIcon) { checkIcon.textContent = '✅'; checkIcon.className = 'promo-apply-btn valid'; }
                            if (hint) {
                                var bonusStr = data.bonus_text || (data.discount_percent ? ('скидка ' + data.discount_percent + '%') : 'бонус');
                                hint.textContent = '✅ ' + bonusStr;
                                hint.className = 'promo-hint valid';
                            }
                        } else {
                            if (checkIcon) { checkIcon.textContent = '❌'; checkIcon.className = 'promo-apply-btn invalid'; }
                            var reasonMsgs = {
                                already_used: 'Этот промокод вы уже использовали ранее',
                                expired:      'Срок действия промокода истёк',
                                maxed_out:    'Лимит активаций промокода исчерпан',
                                not_found:    'Такого промокода нет или он уже не активен',
                            };
                            if (hint) { hint.textContent = reasonMsgs[data.reason] || 'Такого промокода нет или он уже не активен'; hint.className = 'promo-hint invalid'; }
                        }
                    })
                    .catch(function() {});
            }, 450);
        });
    });
})();


async function archiveSlot(slot) {
    var form = document.getElementById('form-slot-' + slot);
    if (!form) { showToastMsg('❌ Форма не найдена', '#ef4444'); return; }

    var data = {};
    form.querySelectorAll('input[name], select[name], textarea[name]').forEach(function(el) {
        if (el.type === 'file' || el.type === 'hidden') return;
        if (el.type === 'checkbox') { data[el.name] = el.checked ? '1' : ''; return; }
        data[el.name] = el.value;
    });

    if (!data.username && !data.telegram && !data.details) {
        showToastMsg('⚠️ Заполни хотя бы имя, контакт или ТЗ', '#ef4444'); return;
    }

    showToastMsg('⏳ Загружаю фото...', '#6366f1');

    var scInput = document.getElementById('s'+slot+'_screenshot');
    if (scInput && scInput.files[0]) {
        var url = await uploadFileToCloudinary(scInput.files[0]);
        if (url) data._screenshot_url = url;
    }
    var rfInput = document.getElementById('s'+slot+'_refs');
    if (rfInput && rfInput.files.length > 0) {
        var files = Array.from(rfInput.files).slice(0, REFS_MAX_FILES);
        var urls = await uploadFilesBatched(files, 4, function(done, total) {
            showToastMsg('⏳ Загружаю файлы... ' + done + '/' + total, '#6366f1');
        });
        if (urls.length) data._refs_urls = urls;
    }

    var archive = getArchive();
    archive.push({ id: Date.now(), slot: slot, savedAt: new Date().toLocaleString('ru'), data: data });
    saveArchive(archive);
    updateArchiveBtn();
    showToastMsg('📦 Заказ архивирован!', '#f97316');
}

function openArchiveList() { renderArchiveList(); document.getElementById('archive-modal').style.display = 'flex'; }
function closeArchiveModal() { document.getElementById('archive-modal').style.display = 'none'; }

function renderArchiveList() {
    var archive = getArchive();
    var c = document.getElementById('archive-items');
    if (!c) return;
    if (!archive.length) { c.innerHTML = '<div style="text-align:center;color:#555568;padding:30px;font-size:13px;">Архив пуст</div>'; return; }
    c.innerHTML = archive.map(function(item, i) {
        var d = item.data || {};
        var photos = '';
        if (d._screenshot_url || (d._refs_urls && d._refs_urls.length)) {
            photos = '<div style="font-size:10px;color:#34d399;margin-top:4px;">📸 ' +
                (d._screenshot_url ? 'Чек ✓' : '') +
                (d._screenshot_url && d._refs_urls && d._refs_urls.length ? ' | ' : '') +
                (d._refs_urls && d._refs_urls.length ? 'Референсы: ' + d._refs_urls.length + ' шт ✓' : '') + '</div>';
        } else {
            photos = '<div style="font-size:10px;color:#555568;margin-top:4px;">Без фото</div>';
        }
        return '<div style="background:#0e0e16;border:1px solid #1e1e2c;border-radius:12px;padding:14px;margin-bottom:10px;">' +
            '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">' +
                '<div style="font-size:12px;font-weight:800;color:#fdba74;">📦 ' + item.savedAt + '</div>' +
                '<div style="display:flex;gap:6px;">' +
                    '<button onclick="loadArchiveItem('+i+')" style="background:rgba(249,115,22,.15);border:1px solid rgba(249,115,22,.3);color:#fdba74;border-radius:7px;padding:5px 10px;font-size:10px;font-weight:800;cursor:pointer;font-family:inherit;">✏️ Загрузить</button>' +
                    '<button onclick="deleteArchiveItem('+i+')" style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:#fca5a5;border-radius:7px;padding:5px 10px;font-size:10px;font-weight:800;cursor:pointer;font-family:inherit;">🗑️</button>' +
                '</div>' +
            '</div>' +
            '<div style="font-size:11px;color:#8a8a96;">👤 '+(d.username||'—')+' | 🎨 '+(d.service||'—')+'</div>' +
            '<div style="font-size:11px;color:#666678;margin-top:2px;">' + ((d.details||'').substring(0,80) + ((d.details||'').length > 80 ? '...' : '')) + '</div>' +
            photos + '</div>';
    }).join('');
}

function loadArchiveItem(i) {
    var item = getArchive()[i];
    if (!item) return;
    var form = document.getElementById('form-slot-1');
    if (form) {
        Object.keys(item.data).forEach(function(name) {
            if (name.startsWith('_')) return;
            var el = form.querySelector('[name="'+name+'"]');
            if (!el) return;
            if (el.type === 'checkbox') { el.checked = item.data[name] === '1'; return; }
            el.value = item.data[name];
        });

        // Убираем старые скрытые поля с фото архива перед повторной вставкой,
        // чтобы при загрузке одного и того же архивного заказа несколько раз
        // не копились дублирующиеся hidden-инпуты
        var oldScreenshot = form.querySelector('[name="screenshot_url"]');
        if (oldScreenshot) oldScreenshot.remove();
        var oldRefs = form.querySelector('[name="refs_urls"]');
        if (oldRefs) oldRefs.remove();
        if (item.data._screenshot_url) {
            var inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'screenshot_url'; inp.value = item.data._screenshot_url;
            form.appendChild(inp);
            var el = document.getElementById('s1_screenshot_name');
            if (el) { el.innerHTML = '✅ Чек: <a href="'+item.data._screenshot_url+'" target="_blank" style="color:#f97316;">открыть</a>'; el.classList.add('has-file'); }
        }
        if (item.data._refs_urls && item.data._refs_urls.length) {
            var inp2 = document.createElement('input');
            inp2.type = 'hidden'; inp2.name = 'refs_urls'; inp2.value = JSON.stringify(item.data._refs_urls);
            form.appendChild(inp2);
            var el2 = document.getElementById('s1_refs_name');
            if (el2) { el2.textContent = '✅ ' + item.data._refs_urls.length + ' референс(а) сохранены'; el2.classList.add('has-file'); }
        }
    }
    switchSlot(1);
    closeArchiveModal();
    showToastMsg('✅ Загружено!', '#22c55e');
}

function deleteArchiveItem(i) {
    var a = getArchive(); a.splice(i,1); saveArchive(a); updateArchiveBtn(); renderArchiveList();
}

function clearAllArchive() {
    if (!confirm('Удалить все архивированные заказы?')) return;
    localStorage.removeItem(ARCHIVE_KEY); updateArchiveBtn(); renderArchiveList();
}

function copyText(text, msg) {
    navigator.clipboard.writeText(text).then(function() { showToastMsg('✅ ' + msg, '#f97316'); });
}

// ─── Запомнить имя/контакт (галочка "Использовать по умолчанию") ───
var REMEMBER_KEY = 'kostlim_order_identity';
function loadRememberedIdentity() {
    var usernameEl = document.getElementById('remember-username');
    var telegramEl = document.getElementById('remember-telegram');
    var cb = document.getElementById('remember-identity-cb');
    if (!usernameEl || !telegramEl || !cb) return;
    try {
        var raw = localStorage.getItem(REMEMBER_KEY);
        if (raw) {
            var data = JSON.parse(raw);
            if (data.username) usernameEl.value = data.username;
            if (data.telegram) telegramEl.value = data.telegram;
            cb.checked = true;
        }
        // Если сохранённых данных ещё нет (первый визит) — оставляем
        // галочку в состоянии по умолчанию (checked), ничего не трогаем.
    } catch(e) {}
}
function saveRememberedIdentity() {
    var usernameEl = document.getElementById('remember-username');
    var telegramEl = document.getElementById('remember-telegram');
    var cb = document.getElementById('remember-identity-cb');
    if (!usernameEl || !telegramEl || !cb) return;
    if (cb.checked) {
        localStorage.setItem(REMEMBER_KEY, JSON.stringify({ username: usernameEl.value, telegram: telegramEl.value }));
    } else {
        localStorage.removeItem(REMEMBER_KEY);
    }
}
document.addEventListener('DOMContentLoaded', function() {
    loadRememberedIdentity();
    var usernameEl = document.getElementById('remember-username');
    var telegramEl = document.getElementById('remember-telegram');
    var cb = document.getElementById('remember-identity-cb');
    if (usernameEl) usernameEl.addEventListener('input', saveRememberedIdentity);
    if (telegramEl) telegramEl.addEventListener('input', saveRememberedIdentity);
    if (cb) cb.addEventListener('change', saveRememberedIdentity);
});
</script>


</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Кнопка "Согласиться" активна по умолчанию — прокрутка правил
    // до конца больше не требуется. Чекбокс согласия отмечается
    // отдельно и просто подсвечивает кнопку анимацией.
    const agreeBtn = document.getElementById('agree-btn');
    const checkbox = document.getElementById('agree-checkbox');
    if (agreeBtn && checkbox) {
        agreeBtn.classList.toggle('is-ready', checkbox.checked);
        checkbox.addEventListener('change', function() {
            agreeBtn.classList.toggle('is-ready', checkbox.checked);
        });
    }
});

</script>

<!-- ══ МОДАЛКА АРХИВА ══ -->
<div id="archive-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.75);backdrop-filter:blur(5px);align-items:center;justify-content:center;">
    <div style="background:#13131a;border:1px solid rgba(249,115,22,.25);border-radius:20px;padding:28px;width:540px;max-width:95vw;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.6);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <div style="font-size:16px;font-weight:900;color:#fff;">📋 Архивированные заказы</div>
            <button onclick="closeArchiveModal()" style="background:rgba(255,255,255,.07);border:none;border-radius:50%;width:30px;height:30px;cursor:pointer;color:#8a8a96;font-size:16px;display:flex;align-items:center;justify-content:center;">✕</button>
        </div>
        <div style="font-size:11px;color:#555568;margin-bottom:16px;line-height:1.5;">
            Фото не сохраняются в архиве — только текстовые данные. При загрузке заполни фото заново.
        </div>
        <div id="archive-items" style="overflow-y:auto;flex:1;padding-right:4px;scrollbar-width:thin;"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:16px;">
            <button onclick="clearAllArchive()" style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:#fca5a5;border-radius:10px;padding:11px;font-size:11px;font-weight:800;cursor:pointer;font-family:inherit;">🗑️ Очистить всё</button>
            <button onclick="closeArchiveModal()" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:11px;color:#8a8a96;font-size:12px;font-weight:800;cursor:pointer;font-family:inherit;">Закрыть</button>
        </div>
    </div>
</div>
<script>
document.getElementById('archive-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeArchiveModal();
});
</script>
<?php endif; ?>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script>
(function(){
    try {
        var tg = window.Telegram && window.Telegram.WebApp;
        if (!tg || !tg.initData) return;
        tg.ready();
        if (typeof tg.expand === 'function') tg.expand();

        // ── Автоподстановка имени/контакта из Telegram ──────────────────
        // Открыто как Mini App (кнопка "web_app" в bot.php) — значит
        // tg.initDataUnsafe.user уже содержит имя и username, за которые
        // ручается Telegram (initData подписан и проверяется на сервере
        // в tg_webapp_auth.php). Подставляем их во все поля "Ваше имя" и
        // "Контакт" на странице (основной слот + доп. заказы), чтобы
        // человеку не нужно было вводить их вручную.
        var tgUser = (tg.initDataUnsafe && tg.initDataUnsafe.user) || null;
        if (tgUser) {
            var displayName = tgUser.first_name || tgUser.username || '';
            var handle       = tgUser.username ? ('@' + tgUser.username) : '';
            document.querySelectorAll('input[name="username"]').forEach(function(el){
                if (!el.value && displayName) {
                    el.value = displayName;
                    el.readOnly = true;
                    el.classList.add('order-input--tg-verified');
                }
            });
            document.querySelectorAll('input[name="telegram"]').forEach(function(el){
                if (!el.value && handle) {
                    el.value = handle;
                    el.readOnly = true;
                    el.classList.add('order-input--tg-verified');
                }
            });
        }

        fetch('/tg_webapp_auth.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'text/plain' },
            body: tg.initData
        }).catch(function(){});
    } catch (e) {}
})();
</script>
<?php
// На странице заказа большая плавающая иконка ИИ не нужна — помощник
// вызывается точечно кнопкой "Помочь составить ТЗ" в блоке деталей заказа.
$aiWidgetHideFab = true;
include __DIR__ . '/includes/ai_widget.php';
?>
</body>
</html>