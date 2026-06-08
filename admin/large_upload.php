<?php
/**
 * large_upload.php — секретная одноразовая страница загрузки больших файлов (до 300 МБ)
 * Доступ только по токену из Telegram-бота.
 */

@ini_set('upload_max_filesize', '320M');
@ini_set('post_max_size', '330M');
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '600');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/upload_token.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$success = '';
$tokenRow = null;

if ($token !== '') {
    $tokenRow = getUploadToken($pdo, $token);
    if (!$tokenRow) {
        $error = 'Ссылка недействительна.';
    } elseif (!isUploadTokenValid($tokenRow)) {
        $error = 'Ссылка уже использована или истекла. Запроси новую в боте: /upload';
    }
} else {
    $error = 'Токен не указан.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenRow && isUploadTokenValid($tokenRow)) {
    $purpose = (string)($tokenRow['purpose'] ?? 'psd_upload');
    $maxSize = (int)($tokenRow['max_size'] ?? UPLOAD_TOKEN_MAX_BYTES);
    $allowed = getAllowedUploadExtensions($purpose);
    $uploadDir = getUploadStorageDir($purpose);

    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    if (empty($_FILES['file']['name']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = 'Выберите файл для загрузки.';
    } elseif (($_FILES['file']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errCode = (int)$_FILES['file']['error'];
        $error = $errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE
            ? 'Файл слишком большой для сервера. Проверь лимиты PHP на хостинге.'
            : 'Ошибка загрузки (код ' . $errCode . ').';
    } else {
        $origName = basename((string)$_FILES['file']['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $fileSize = (int)$_FILES['file']['size'];

        if (!in_array($ext, $allowed, true)) {
            $error = 'Недопустимый тип файла. Разрешено: ' . implode(', ', $allowed);
        } elseif ($fileSize <= 0) {
            $error = 'Пустой файл.';
        } elseif ($fileSize > $maxSize) {
            $error = 'Файл больше лимита ' . round($maxSize / 1024 / 1024) . ' МБ.';
        } elseif (!is_writable($uploadDir)) {
            $error = 'Папка загрузки недоступна для записи.';
        } else {
            $portfolioId = (int)($tokenRow['portfolio_id'] ?? 0);
            $slot = (int)($tokenRow['file_slot'] ?? 0);
            $uniqueName = ($portfolioId > 0 ? $portfolioId . '_' : 'tok_')
                . time() . '_' . $slot . '_' . bin2hex(random_bytes(4)) . '.' . ($ext ?: 'bin');
            $destPath = $uploadDir . $uniqueName;

            if (move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
                if (completeUploadToken($pdo, $token, $uniqueName, $origName, $fileSize)) {
                    attachUploadedFileToPortfolio($pdo, $tokenRow, $uniqueName, $origName, $fileSize);

                    $botToken = getenv('BOT_TOKEN') ?: getenv('TELEGRAM_BOT_TOKEN') ?: '';
                    if ($botToken !== '') {
                        notifyUploadCompleteTelegram($botToken, $tokenRow, $origName, $fileSize);
                    }

                    $success = 'Файл успешно загружен! Можешь вернуться в Telegram — бот уже отправил подтверждение.';
                    $tokenRow['status'] = 'completed';
                } else {
                    @unlink($destPath);
                    $error = 'Не удалось завершить загрузку (токен уже использован).';
                }
            } else {
                $error = 'Не удалось сохранить файл на сервер.';
            }
        }
    }
}

$purposeLabel = ($tokenRow['purpose'] ?? '') === 'preview_upload' ? 'превью / изображение' : 'PSD / исходник';
$maxMb = round(((int)($tokenRow['max_size'] ?? UPLOAD_TOKEN_MAX_BYTES)) / 1024 / 1024);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Загрузка файла | Kostlim</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #0d0d12; color: #e8e8f0; font-family: 'Segoe UI', sans-serif; padding: 20px; }
        .card { width: 100%; max-width: 520px; background: #111116; border: 1px solid #262633; border-radius: 16px; padding: 28px; }
        h1 { margin: 0 0 8px; font-size: 20px; }
        .sub { color: #8a8a93; font-size: 13px; margin-bottom: 22px; }
        .ok { background: rgba(0,255,163,.1); border: 1px solid #00ffa3; color: #00ffa3; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        .err { background: rgba(239,68,68,.1); border: 1px solid #ef4444; color: #ef4444; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        .drop { border: 2px dashed #3a3a4a; border-radius: 12px; padding: 28px; text-align: center; margin-bottom: 18px; }
        input[type=file] { width: 100%; color: #aaa; }
        button { width: 100%; padding: 14px; border: none; border-radius: 8px; background: #a95851; color: #fff;
            font-weight: 800; cursor: pointer; text-transform: uppercase; letter-spacing: .5px; }
        button:disabled { opacity: .5; cursor: not-allowed; }
        .meta { font-size: 12px; color: #666; margin-top: 14px; line-height: 1.6; }
    </style>
</head>
<body>
<div class="card">
    <h1>📤 Загрузка файла</h1>
    <div class="sub">Одноразовая ссылка · до <?= (int)$maxMb ?> МБ · <?= htmlspecialchars($purposeLabel) ?></div>

    <?php if ($success): ?>
        <div class="ok"><?= htmlspecialchars($success) ?></div>
    <?php elseif ($error && !$tokenRow): ?>
        <div class="err"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($tokenRow && ($tokenRow['status'] ?? '') === 'completed'): ?>
        <div class="ok">Эта ссылка уже использована. Запроси новую в боте командой <code>/upload</code>.</div>
    <?php else: ?>
        <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <div class="drop">
                <div style="font-size:36px;margin-bottom:8px;">📂</div>
                <div style="color:#8a8a93;font-size:13px;margin-bottom:12px;">Выбери файл на устройстве</div>
                <input type="file" name="file" required>
            </div>
            <button type="submit">Загрузить на сервер</button>
        </form>
        <div class="meta">
            🔒 Ссылка действует ограниченное время и работает один раз.<br>
            После загрузки бот пришлёт подтверждение в Telegram.
            <?php if ((int)($tokenRow['portfolio_id'] ?? 0) > 0): ?>
                <br>🎨 Привязано к портфолио #<?= (int)$tokenRow['portfolio_id'] ?>.
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
