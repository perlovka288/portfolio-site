<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'auth.php';
require_once '../config/db.php';

// ── те же самые site_settings-хелперы, что и в index.php ──────────
function ensureSiteSettingsTable(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (setting_key VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    } catch (Throwable $e) {}
}
function getSetting(PDO $pdo, string $key, string $default = ''): string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    $val = $default;
    try {
        $stmt = $pdo->prepare("SELECT value FROM site_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && trim((string)$row['value']) !== '') $val = $row['value'];
    } catch (Throwable $e) {}
    $cache[$key] = $val;
    return $val;
}
function setSetting(PDO $pdo, string $key, string $value): void
{
    try {
        $pdo->prepare("INSERT INTO site_settings (setting_key, value, updated_at) VALUES (?, ?, NOW())
                       ON CONFLICT (setting_key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()")
            ->execute([$key, $value]);
    } catch (Throwable $e) {}
}
ensureSiteSettingsTable($pdo);

function uploadToImgBB(string $tmpPath, string $name = 'image'): string
{
    global $pdo;
    if (!is_file($tmpPath)) return '';
    $keys = array_filter([
        getSetting($pdo, 'IMGBB_API_KEY', getenv('IMGBB_API_KEY') ?: ''),
        getSetting($pdo, 'IMGBB_API_KEY2', getenv('IMGBB_API_KEY2') ?: ''),
        getSetting($pdo, 'IMGBB_API_KEY3', getenv('IMGBB_API_KEY3') ?: ''),
    ]);
    if (empty($keys)) return '';
    $b64 = base64_encode(file_get_contents($tmpPath));
    foreach ($keys as $apiKey) {
        $ch = curl_init('https://api.imgbb.com/1/upload');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_POSTFIELDS => ['key' => $apiKey, 'image' => $b64, 'name' => $name]]);
        $res = curl_exec($ch); curl_close($ch);
        if ($res === false || $res === '') continue;
        $data = json_decode($res, true); $url = $data['data']['url'] ?? '';
        if ($url !== '') return $url;
    }
    return '';
}

function imgSrc(string $val, string $baseUrl = '../uploads/'): string
{
    if ($val === '') return '';
    if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) return $val;
    $siteRoot = rtrim(getenv('SITE_URL') ?: '', '/');
    if ($siteRoot !== '') {
        $cleanBase = ltrim(str_replace('../', '', $baseUrl), '/');
        return $siteRoot . '/' . $cleanBase . $val;
    }
    return $baseUrl . $val;
}

$message = '';
$uploadDir = '../uploads/';

// ── Текущие данные ───────────────────────────────────────────────
$userRow = $pdo->query("SELECT * FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$currentUsername = $userRow['username'] ?? 'Kostlim';
$currentAvatar    = $userRow['avatar'] ?? '';
$currentEmail     = getSetting($pdo, 'ADMIN_EMAIL', 'jeffkostlim@gmail.com');
$currentTgId      = getSetting($pdo, 'ADMIN_TELEGRAM_ID', '1710365896');

// ── Сохранение профиля ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $newUsername = trim($_POST['username'] ?? '');
    $newEmail    = trim($_POST['admin_email'] ?? '');
    $newTgId     = trim($_POST['admin_tg_id'] ?? '');

    if ($newUsername !== '' && $newUsername !== $currentUsername) {
        try {
            $pdo->prepare("UPDATE users SET username = ? WHERE username = ?")->execute([$newUsername, $currentUsername]);
            $currentUsername = $newUsername;
        } catch (Throwable $e) {}
    }
    if ($newEmail !== '') { setSetting($pdo, 'ADMIN_EMAIL', $newEmail); $currentEmail = $newEmail; }

    if ($newTgId !== '' && $newTgId !== $currentTgId) {
        setSetting($pdo, 'ADMIN_TELEGRAM_ID', $newTgId);
        $currentTgId = $newTgId;
        $message = '✅ Профиль сохранён. ⚠️ TG ID администратора изменён — вход в панель теперь проверяется по новому ID.';
    } else {
        $message = '✅ Профиль сохранён.';
    }

    // Аватарка
    if (!empty($_FILES['avatar']['name'])) {
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','gif'], true) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['avatar']['tmp_name'];
            $url = uploadToImgBB($tmp, 'avatar_' . time());
            $newAvatarVal = $url;
            if ($newAvatarVal === '') {
                if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
                $filename = 'avatar_' . time() . '_' . uniqid() . '.' . $ext;
                if (is_writable($uploadDir) && move_uploaded_file($tmp, $uploadDir . $filename)) {
                    $newAvatarVal = $filename;
                }
            }
            if ($newAvatarVal !== '') {
                $pdo->prepare("UPDATE users SET avatar = ? WHERE username = ?")->execute([$newAvatarVal, $currentUsername]);
                $currentAvatar = $newAvatarVal;
                $message .= ' Аватарка обновлена.';
            } else {
                $message .= ' ⚠️ Не удалось загрузить аватарку.';
            }
        }
    }
}

$avatarSrc = imgSrc($currentAvatar, '../uploads/');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Мой профиль — Kostlim Admin</title>
<link rel="icon" type="image/png" href="/assets/notify/fav.png" sizes="16x16">
<link rel="stylesheet" href="../style.css">
<style>
    * { scrollbar-width: thin; scrollbar-color: #f97316 #111116; }
    body { background: #08080b; color: #fff; font-family: Montserrat, Arial, sans-serif; }
    .profile-shell { max-width: 620px; margin: 0 auto; padding: 32px 24px; }
    .back-link { color: #8a8a96; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 18px; }
    .back-link:hover { color: #f97316; }
    h1 { font-size: 24px; margin: 0 0 22px; }
    .panel { background: #111116; border: 1px solid #20202c; border-radius: 16px; padding: 24px; }
    .notice { border: 1px solid rgba(34,197,94,.45); background: rgba(34,197,94,.10); color: #86efac; border-radius: 12px; padding: 14px 16px; margin-bottom: 18px; font-weight: 700; font-size: 13px; }
    .avatar-row { display: flex; align-items: center; gap: 18px; margin-bottom: 22px; }
    .avatar-row img { width: 84px; height: 84px; border-radius: 50%; object-fit: cover; border: 3px solid #f97316; background: #0b0b10; }
    label { display: block; color: #d9d9e4; font-size: 12px; font-weight: 800; margin: 14px 0 6px; text-transform: uppercase; letter-spacing: .5px; }
    input:not([type="file"]) { width: 100%; box-sizing: border-box; background: #171720; color: #fff; border: 1px solid #2a2a38; border-radius: 9px; padding: 11px 12px; outline: none; font-family: Montserrat,sans-serif; font-size: 13px; }
    input:focus { border-color: #f97316; }
    input[type="file"] { color: #d8d8e8; font-size: 12px; }
    .hint { color: #8a8a96; font-size: 11px; margin-top: 6px; line-height: 1.5; }
    .warn-hint { color: #fdba74; font-size: 11px; margin-top: 6px; line-height: 1.5; background: rgba(249,115,22,.08); border-left: 2px solid #f97316; padding: 8px 10px; border-radius: 6px; }
    button { width: 100%; margin-top: 22px; border: none; border-radius: 10px; padding: 13px 16px; background: linear-gradient(135deg,#fb923c,#f97316); color: #fff; font-weight: 900; cursor: pointer; text-transform: uppercase; font-family: Montserrat,sans-serif; letter-spacing: 1px; font-size: 13px; }
    button:hover { opacity: .92; }
</style>
</head>
<body>
<div class="profile-shell">
    <a href="index.php" class="back-link">← Назад в админку</a>
    <h1>👤 Мой профиль</h1>

    <?php if ($message !== ''): ?>
        <div class="notice"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="panel">
        <div class="avatar-row">
            <img src="<?= htmlspecialchars($avatarSrc ?: 'https://i.imgur.com/w9NThbA.png') ?>" onerror="this.src='https://i.imgur.com/w9NThbA.png'" alt="">
            <div>
                <div style="font-weight:800;font-size:15px;"><?= htmlspecialchars($currentUsername) ?></div>
                <div style="color:#8a8a96;font-size:12px;">Эта аватарка — водяной знак на постах в Telegram-канале.</div>
            </div>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <label>Отображаемое имя</label>
            <input type="text" name="username" value="<?= htmlspecialchars($currentUsername) ?>">

            <label>Новая аватарка</label>
            <input type="file" name="avatar" accept="image/*">

            <label>Email администратора</label>
            <input type="email" name="admin_email" value="<?= htmlspecialchars($currentEmail) ?>">

            <label>Telegram ID администратора</label>
            <input type="text" name="admin_tg_id" value="<?= htmlspecialchars($currentTgId) ?>">
            <div class="warn-hint">⚠️ Это тот же ID, что проверяется при входе через Telegram Mini App (см. auth.php). Меняй только если точно знаешь свой новый Telegram ID — иначе можно случайно потерять доступ в панель.</div>

            <button type="submit" name="save_profile">💾 Сохранить профиль</button>
        </form>
    </div>
</div>
</body>
</html>