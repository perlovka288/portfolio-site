<?php
/**
 * upload_token.php — одноразовые ссылки для загрузки больших файлов (до 300 МБ)
 */

define('UPLOAD_TOKEN_MAX_BYTES', 300 * 1024 * 1024);
define('UPLOAD_TOKEN_TTL_SECONDS', 7200); // 2 часа

function ensureUploadTokenTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS upload_tokens (
        id SERIAL PRIMARY KEY,
        token VARCHAR(64) NOT NULL UNIQUE,
        purpose VARCHAR(30) NOT NULL DEFAULT 'psd_upload',
        portfolio_id INT DEFAULT NULL,
        file_slot INT NOT NULL DEFAULT 0,
        chat_id BIGINT NOT NULL,
        admin_tg_id BIGINT NOT NULL,
        original_name TEXT DEFAULT '',
        stored_file TEXT DEFAULT '',
        file_size BIGINT NOT NULL DEFAULT 0,
        max_size BIGINT NOT NULL DEFAULT " . UPLOAD_TOKEN_MAX_BYTES . ",
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        expires_at TIMESTAMP NOT NULL,
        used_at TIMESTAMP DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_upload_tokens_status ON upload_tokens(status, expires_at)");
}

function generateUploadTokenString(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * @return array{ok:bool, token?:string, url?:string, error?:string}
 */
function createUploadToken(
    PDO $pdo,
    string $purpose,
    int $chatId,
    int $adminTgId,
    ?int $portfolioId = null,
    int $fileSlot = 0,
    int $ttlSeconds = UPLOAD_TOKEN_TTL_SECONDS
): array {
    ensureUploadTokenTable($pdo);

    $allowed = ['psd_upload', 'preview_upload'];
    if (!in_array($purpose, $allowed, true)) {
        $purpose = 'psd_upload';
    }

    $token = generateUploadTokenString();
    $expires = date('Y-m-d H:i:s', time() + $ttlSeconds);

    $stmt = $pdo->prepare("
        INSERT INTO upload_tokens (token, purpose, portfolio_id, file_slot, chat_id, admin_tg_id, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$token, $purpose, $portfolioId, $fileSlot, $chatId, $adminTgId, $expires]);

    $siteUrl = rtrim(getenv('SITE_URL') ?: 'https://kostlimdzn.kesug.com/', '/') . '/';
    $url = $siteUrl . 'admin/large_upload.php?token=' . urlencode($token);

    return ['ok' => true, 'token' => $token, 'url' => $url];
}

function getUploadToken(PDO $pdo, string $token): ?array
{
    ensureUploadTokenTable($pdo);
    $stmt = $pdo->prepare("SELECT * FROM upload_tokens WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function isUploadTokenValid(array $row): bool
{
    if (($row['status'] ?? '') !== 'pending') {
        return false;
    }
    $expires = strtotime((string)($row['expires_at'] ?? ''));
    return $expires > time();
}

function completeUploadToken(PDO $pdo, string $token, string $storedFile, string $originalName, int $fileSize): bool
{
    $stmt = $pdo->prepare("
        UPDATE upload_tokens
        SET status = 'completed', stored_file = ?, original_name = ?, file_size = ?, used_at = NOW()
        WHERE token = ? AND status = 'pending'
    ");
    $stmt->execute([$storedFile, $originalName, $fileSize, $token]);
    return $stmt->rowCount() > 0;
}

function attachUploadedFileToPortfolio(PDO $pdo, array $tokenRow, string $storedFile, string $originalName, int $fileSize): void
{
    $portfolioId = (int)($tokenRow['portfolio_id'] ?? 0);
    if ($portfolioId <= 0) {
        return;
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS portfolio_psd (
            id SERIAL PRIMARY KEY,
            portfolio_id INT NOT NULL,
            psd_file TEXT NOT NULL,
            original_name TEXT NOT NULL DEFAULT '',
            file_size BIGINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $slot = (int)($tokenRow['file_slot'] ?? 0);
        $check = $pdo->prepare("SELECT id FROM portfolio_psd WHERE portfolio_id = ? AND psd_file = ? LIMIT 1");
        $check->execute([$portfolioId, $storedFile]);
        if ($check->fetchColumn()) {
            return;
        }

        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM portfolio_psd WHERE portfolio_id = ?");
        $cntStmt->execute([$portfolioId]);
        $cnt = (int)$cntStmt->fetchColumn();
        if ($cnt >= 3) {
            return;
        }

        $insert = $pdo->prepare(
            "INSERT INTO portfolio_psd (portfolio_id, psd_file, original_name, file_size) VALUES (?, ?, ?, ?)"
        );
        $insert->execute([$portfolioId, $storedFile, $originalName, $fileSize]);
    } catch (Throwable $e) {
        error_log('[upload_token] portfolio_psd attach error: ' . $e->getMessage());
    }
}

function notifyUploadCompleteTelegram(
    string $botToken,
    array $tokenRow,
    string $originalName,
    int $fileSize
): void {
    $chatId = (string)($tokenRow['chat_id'] ?? '');
    if ($chatId === '') {
        return;
    }

    $purpose = ($tokenRow['purpose'] ?? '') === 'preview_upload' ? 'превью' : 'PSD';
    $sizeMb = round($fileSize / 1024 / 1024, 1);
    $portfolioId = (int)($tokenRow['portfolio_id'] ?? 0);

    $text = "✅ *Файл загружен через браузер*\n\n";
    $text .= "📁 Тип: *{$purpose}*\n";
    $text .= "📄 Имя: `" . str_replace('`', '', $originalName) . "`\n";
    $text .= "💾 Размер: *{$sizeMb} МБ*\n";
    if ($portfolioId > 0) {
        $text .= "🎨 Портфолио ID: *#{$portfolioId}*\n";
    }
    $text .= "\n_Ссылка больше не действует (одноразовая)._";

    $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ]),
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function getAllowedUploadExtensions(string $purpose): array
{
    if ($purpose === 'preview_upload') {
        return ['jpg', 'jpeg', 'png', 'webp', 'gif', 'psd'];
    }
    return ['psd', 'zip', 'rar', '7z', 'psb'];
}

function getUploadStorageDir(string $purpose): string
{
    if ($purpose === 'preview_upload') {
        return __DIR__ . '/../uploads/portfolio_preview/';
    }
    return __DIR__ . '/../uploads/psd/';
}
