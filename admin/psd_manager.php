<?php
/**
 * psd_manager.php — Управление PSD файлами портфолио и публикация в приват-пак
 */

define('PRIVATE_PACK_CHAT_ID', getenv('PRIVATE_CHAT_ID') ?: '-1003781426510');
define('TELEGRAM_DOC_MAX_BYTES', 45 * 1024 * 1024); // Максимальный размер документа для Telegram
require_once __DIR__ . '/google_drive_helper.php';

/**
 * Сохранить загруженные PSD файлы и записать в БД
 */
function savePortfolioPsdFiles(PDO $pdo, int $portfolio_id): array
{
    $messages = [];
    $uploadDir = __DIR__ . '/../uploads/psd/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    if (!is_writable($uploadDir)) {
        return ['success' => false, 'messages' => ['❌ Папка uploads/psd/ недоступна для записи']];
    }

    if (empty($_FILES['psd_files']['name'][0])) {
        return ['success' => true, 'messages' => [], 'has_files' => false];
    }

    deletePortfolioPsdFiles($pdo, $portfolio_id);

    $insertStmt = $pdo->prepare(
        "INSERT INTO portfolio_psd (portfolio_id, psd_file, original_name, file_size) VALUES (?, ?, ?, ?)"
    );

    $uploaded = 0;
    foreach ($_FILES['psd_files']['name'] as $i => $name) {
        if ($i >= 3) {
            $messages[] = '⚠️ Можно загрузить не более 3 PSD файлов.';
            break;
        }
        if (($_FILES['psd_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = (int)($_FILES['psd_files']['error'][$i] ?? 0);
            // Если файл слишком большой для PHP, сообщаем об этом
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { $messages[] = '⚠️ Файл «' . basename((string)$name) . '» слишком большой для загрузки через веб-форму. Используй команду /upload ID в боте.'; }
            continue;
        }

        $tmp = $_FILES['psd_files']['tmp_name'][$i];
        $origName = basename((string)$name);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $fileSize = (int)$_FILES['psd_files']['size'][$i];
        $uniqueName = $portfolio_id . '_' . time() . '_' . $i . '.' . ($ext ?: 'psd');
        $destPath = $uploadDir . $uniqueName;

        if (move_uploaded_file($tmp, $destPath)) {
            $insertStmt->execute([$portfolio_id, $uniqueName, $origName, $fileSize]);
            $uploaded++;
        } else {
            $messages[] = "❌ Не удалось сохранить: {$origName}";
        }
    }

    if ($uploaded > 0) {
        $messages[] = "✅ Загружено PSD: {$uploaded}";
    }

    return ['success' => $uploaded > 0, 'messages' => $messages, 'has_files' => $uploaded > 0];
}

function deletePortfolioPsdFiles(PDO $pdo, int $portfolio_id, bool $deleteFromDisk = true): bool
{
    try {
        $stmt = $pdo->prepare("SELECT psd_file FROM portfolio_psd WHERE portfolio_id = ?");
        $stmt->execute([$portfolio_id]);
        $files = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($deleteFromDisk) {
            foreach ($files as $file) {
                $path = __DIR__ . '/../uploads/psd/' . basename((string)$file);
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        $pdo->prepare("DELETE FROM portfolio_psd WHERE portfolio_id = ?")->execute([$portfolio_id]);
        return true;
    } catch (Exception $e) {
        error_log('[PSD] delete error: ' . $e->getMessage());
        return false;
    }
}

function getPortfolioPsdFiles(PDO $pdo, int $portfolio_id): array
{
    try {
        $stmt = $pdo->prepare("SELECT * FROM portfolio_psd WHERE portfolio_id = ? ORDER BY id ASC");
        $stmt->execute([$portfolio_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function hasPortfolioPsdFiles(PDO $pdo, int $portfolio_id): bool
{
    return count(getPortfolioPsdFiles($pdo, $portfolio_id)) > 0;
}

/**
 * Публикация в приват-пак: то же превью что в канале + PSD файлы
 *
 * @param string|null $watermarkedPhotoPath Локальный путь к JPEG с водяным знаком (как в канале)
 */
function publishPortfolioToPrivatePack(
    PDO $pdo,
    string $token,
    int $portfolio_id,
    string $title,
    int $priceRub,
    int $priceUan,
    ?string $watermarkedPhotoPath = null
): array {
    $chatId = PRIVATE_PACK_CHAT_ID;
    $psdFiles = getPortfolioPsdFiles($pdo, $portfolio_id);

    if (empty($psdFiles) && $watermarkedPhotoPath === null) {
        return ['success' => false, 'message' => 'Нет PSD и превью для приват-пака'];
    }

    $siteUrl = rtrim(getenv('SITE_URL') ?: 'https://kostlimdzn.kesug.com/', '/') . '/';
    $caption = "🎨 *Псд к превью \"{$title}\"*\n\n"
        . "💰 {$priceRub} ₽ / {$priceUan} ₴\n"
        . "📁 Исходники для приват-пака\n"
        . "🚀 [Заказать дизайн]({$siteUrl})";

    $photoSent = false;

    // 1) То же фото что в публичном канале
    if ($watermarkedPhotoPath && is_file($watermarkedPhotoPath)) {
        $ch = curl_init("https://api.telegram.org/bot{$token}/sendPhoto");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_POSTFIELDS => [
                'chat_id' => $chatId,
                'photo' => new CURLFile($watermarkedPhotoPath, 'image/jpeg', 'preview.jpg'),
                'caption' => $caption,
                'parse_mode' => 'Markdown',
            ],
        ]);
        $resp = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        $photoSent = (bool)($resp['ok'] ?? false);
        if (!$photoSent) {
            error_log('[PSD Pack] Photo send failed: ' . json_encode($resp));
        }
    }

    if (!$photoSent && !empty($psdFiles)) {
        sendTelegramPlain($token, $chatId, $caption);
    }

    // 2) Подготовка клавиатуры
    $keyboard = ['inline_keyboard' => []];
    $needLinks = false;

    // Проверяем внешнюю ссылку (Google Диск и т.д.)
    $stmt = $pdo->prepare("SELECT psd_external_link FROM portfolio WHERE id = ? LIMIT 1");
    $stmt->execute([$portfolio_id]);
    $extLink = $stmt->fetchColumn();

    if (!empty($extLink)) {
        $keyboard['inline_keyboard'][] = [
            ['text' => '☁️ Скачать из Cloud (Google Drive / MEGA)', 'url' => $extLink]
        ];
    }

    // 3) PSD файлы, загруженные на сервер
    foreach ($psdFiles as $idx => $psd) {
        $filePath = __DIR__ . '/../uploads/psd/' . basename((string)$psd['psd_file']);
        $origName = $psd['original_name'] ?: basename((string)$psd['psd_file']);
        $fileSize = (int)$psd['file_size'];

        // Если файл слишком большой для Telegram, загружаем на Google Диск
        if ($fileSize > TELEGRAM_DOC_MAX_BYTES && is_file($filePath)) {
            $externalUrl = uploadToGoogleDrive($filePath, $origName);
            if ($externalUrl) {
                $keyboard['inline_keyboard'][] = [
                    ['text' => "☁️ {$origName} (Google Drive)", 'url' => $externalUrl]
                ];
                // Удаляем локальный файл после успешной загрузки на Google Drive
                @unlink($filePath);
                continue; // Переходим к следующему файлу
            }
        }

        // Если файл не был загружен на Google Диск (либо меньше лимита Telegram, либо ошибка GDrive)
        if ($fileSize > 0 && is_file($filePath)) {
            // Пытаемся отправить в Telegram как документ
            $ch = curl_init("https://api.telegram.org/bot{$token}/sendDocument");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_POSTFIELDS => [
                    'chat_id'  => $chatId,
                    'document' => new CURLFile($filePath, null, $origName),
                    'caption'  => ($idx === 0 && !$photoSent) ? $caption : "📄 " . mdEscape($origName),
                    'parse_mode' => 'Markdown',
                ],
            ]);
            $resp = json_decode((string)curl_exec($ch), true);
            curl_close($ch);
            if (!($resp['ok'] ?? false)) { // Если отправка в Telegram не удалась
                $needLinks = true;
                $keyboard['inline_keyboard'][] = [
                    ['text' => "📥 {$origName}", 'url' => $siteUrl . 'admin/psd_download.php?id=' . (int)$psd['id']],
                ];
            }
        } else { // Если файл не существует или 0 размера
            $needLinks = true;
            $sizeText = $fileSize > 0 ? ' (' . round($fileSize / 1024 / 1024, 1) . ' MB)' : '';
            $keyboard['inline_keyboard'][] = [
                ['text' => "📥 {$origName}{$sizeText}", 'url' => $siteUrl . 'admin/psd_download.php?id=' . (int)$psd['id']],
            ];
        }
    }

    if ($needLinks && !empty($keyboard['inline_keyboard'])) {
        sendTelegramPlain($token, $chatId, "📥 *Скачать исходники:*\n_{$title}_", $keyboard);
    }

    return ['success' => true, 'message' => '📦 Опубликовано в приват-пак'];
}

/** @deprecated используй publishPortfolioToPrivatePack */
function sendPsdToPrivateChat(string $token, string $chat_id, int $portfolio_id, string $title, PDO $pdo): array
{
    return publishPortfolioToPrivatePack($pdo, $token, $portfolio_id, $title, 0, 0, null);
}

function sendTelegramPlain(string $token, string $chatId, string $text, ?array $keyboard = null): void
{
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true,
    ];
    if ($keyboard) {
        $params['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    }
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => http_build_query($params),
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Создать водяной знак и вернуть путь к temp-файлу (для канала и приват-пака)
 */
function buildWatermarkedPhotoForPortfolio(PDO $pdo, string $uploadDir, array $case): ?string
{
    $imgVal = (string)($case['image'] ?? '');
    if (str_starts_with($imgVal, 'http://') || str_starts_with($imgVal, 'https://')) {
        $mainPath = downloadImageToTemp($imgVal);
        $downloaded = true;
    } else {
        $mainPath = $uploadDir . basename($imgVal);
        $downloaded = false;
    }
    if (!is_file($mainPath)) {
        return null;
    }

    $category = [];
    $catKey = (string)($case['category_key'] ?? '');
    if ($catKey !== '') {
        try {
            $stmt = $pdo->prepare('SELECT width_px, height_px, is_design FROM portfolio_categories WHERE category_key = ? LIMIT 1');
            $stmt->execute([$catKey]);
            $category = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}
    }

    $avatarVal = (string)($case['avatar_image'] ?? '');
    if ($avatarVal === '' && empty($category['is_design'])) {
        try {
            $stmt = $pdo->query('SELECT avatar FROM users LIMIT 1');
            $avatarVal = (string)($stmt->fetchColumn() ?: '');
        } catch (Throwable $e) {}
    }

    if (str_starts_with($avatarVal, 'http://') || str_starts_with($avatarVal, 'https://')) {
        $avatarPath = downloadImageToTemp($avatarVal);
        $avatarDownloaded = true;
    } else {
        $avatarPath = $avatarVal !== '' ? $uploadDir . basename($avatarVal) : '';
        $avatarDownloaded = false;
    }

    if (!function_exists('createWatermarkedImage')) {
        if ($downloaded && is_file($mainPath)) {
            @unlink($mainPath);
        }
        if ($avatarDownloaded && $avatarPath !== '' && is_file($avatarPath)) {
            @unlink($avatarPath);
        }
        return is_file($mainPath) ? $mainPath : null;
    }

    $photoPath = createWatermarkedImage(
        $mainPath,
        $avatarPath,
        (string)($case['title'] ?? ''),
        (int)($case['price_rub'] ?? 0),
        (int)($case['price_uan'] ?? 0),
        $category
    );

    if ($downloaded && is_file($mainPath) && $photoPath !== $mainPath) {
        @unlink($mainPath);
    }
    if ($avatarDownloaded && $avatarPath !== '' && is_file($avatarPath)) {
        @unlink($avatarPath);
    }

    return ($photoPath && is_file($photoPath)) ? $photoPath : null;
}

function downloadImageToTemp(string $url): string
{
    if ($url === '') {
        return '';
    }
    $tmp = tempnam(sys_get_temp_dir(), 'imgdl_') . '.jpg';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
    $data = curl_exec($ch);
    curl_close($ch);
    if ($data && file_put_contents($tmp, $data)) {
        return $tmp;
    }
    return '';
}
