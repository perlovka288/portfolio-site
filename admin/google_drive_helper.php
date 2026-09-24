<?php
/**
 * google_drive_helper.php — Загрузка файлов на Google Drive через Service Account
 */

function uploadToGoogleDrive(string $filePath, string $fileName): ?string {
    $r = uploadToGoogleDriveDetailed($filePath, $fileName);
    return $r['url'] ?? null;
}

/**
 * То же самое, что uploadToGoogleDrive(), но возвращает ещё file id и
 * оригинальное имя файла — нужно для прямого скачивания с заголовком
 * Content-Disposition: attachment через download.php (Блок 2.2 ТЗ),
 * вместо открытия webViewLink-превью Google Drive в пустой вкладке.
 *
 * @return array{id:string,url:string,name:string}|null
 */
function uploadToGoogleDriveDetailed(string $filePath, string $fileName): ?array {
    $keyFile = __DIR__ . '/gdrive_key.json';
    $folderId = getenv('GDRIVE_FOLDER_ID') ?: '1U3rLkAbezkc7SSAp7rh9RGCvTQkD7QaK'; // Замени на ID своей папки

    if (!is_file($keyFile)) {
        error_log("[GDrive] Ошибка: файл ключа gdrive_key.json не найден.");
        return null;
    }

    $keyData = json_decode(file_get_contents($keyFile), true);
    $accessToken = getGDriveAccessToken($keyData);

    if (!$accessToken) return null;

    // 1. Загрузка файла
    $metadata = [
        'name' => $fileName,
        'parents' => [$folderId]
    ];

    $boundary = "-------" . md5(time());
    $content = "--" . $boundary . "\r\n";
    $content .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $content .= json_encode($metadata) . "\r\n";
    $content .= "--" . $boundary . "\r\n";
    $content .= "Content-Type: application/octet-stream\r\n\r\n";
    $content .= file_get_contents($filePath) . "\r\n";
    $content .= "--" . $boundary . "--";

    $ch = curl_init("https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,webViewLink");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken",
        "Content-Type: multipart/related; boundary=$boundary",
        "Content-Length: " . strlen($content)
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $content);

    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($response['id'])) {
        $fileId = $response['id'];
        // 2. Делаем файл публичным (чтение для всех по ссылке)
        makeGDriveFilePublic($fileId, $accessToken);
        return [
            'id'   => $fileId,
            'url'  => $response['webViewLink'] ?? "https://drive.google.com/uc?id=$fileId",
            'name' => $fileName,
        ];
    }

    error_log("[GDrive] Ошибка загрузки: " . json_encode($response));
    return null;
}

/**
 * Скачивает содержимое файла с Google Drive по его id (через тот же
 * сервисный аккаунт, что и загрузка) — используется download.php, чтобы
 * гарантированно отдать файл пользователю как вложение, а не как
 * страницу-превью Google Drive.
 *
 * @return array{data:string,mime:string}|null
 */
function downloadFromGoogleDrive(string $fileId): ?array {
    $keyFile = __DIR__ . '/gdrive_key.json';
    if (!is_file($keyFile)) return null;
    $keyData = json_decode(file_get_contents($keyFile), true);
    $accessToken = getGDriveAccessToken($keyData);
    if (!$accessToken) return null;

    $ch = curl_init("https://www.googleapis.com/drive/v3/files/$fileId?alt=media");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken"]);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($code !== 200 || $raw === false) return null;

    $headers = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $mime = 'application/octet-stream';
    if (preg_match('/Content-Type:\s*([^\r\n]+)/i', $headers, $m)) {
        $mime = trim($m[1]);
    }
    return ['data' => $body, 'mime' => $mime];
}

function getGDriveAccessToken(array $keyData): ?string {
    $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $now = time();
    $payload = base64_encode(json_encode([
        'iss' => $keyData['client_email'],
        'scope' => 'https://www.googleapis.com/auth/drive',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600
    ]));

    $signature = '';
    openssl_sign("$header.$payload", $signature, $keyData['private_key'], 'SHA256');
    $jwt = "$header.$payload." . base64_encode($signature);

    $ch = curl_init("https://oauth2.googleapis.com/token");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));

    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    return $res['access_token'] ?? null;
}

function makeGDriveFilePublic(string $fileId, string $accessToken): void {
    $ch = curl_init("https://www.googleapis.com/drive/v3/files/$fileId/permissions");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'role' => 'reader',
        'type' => 'anyone'
    ]));
    curl_exec($ch);
    curl_close($ch);
}