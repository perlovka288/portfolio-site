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

// ── Быстрая статистика для карточки профиля ───────────────────────
$quickStats = ['total' => 0, 'active' => 0, 'ready' => 0, 'revenue_rub' => 0, 'revenue_uan' => 0];
try {
    $qs = $pdo->query("
        SELECT COUNT(*) AS total,
               COUNT(*) FILTER (WHERE status IN ('pending','awaiting_payment','in_progress','urgent')) AS active,
               COUNT(*) FILTER (WHERE status = 'ready') AS ready
        FROM orders
    ")->fetch(PDO::FETCH_ASSOC);
    if ($qs) $quickStats = array_merge($quickStats, array_map('intval', $qs));
    $rev = $pdo->query("
        SELECT COALESCE(SUM(CASE WHEN o.cooperation THEN 0 ELSE p.price_rub END),0) AS rub,
               COALESCE(SUM(CASE WHEN o.cooperation THEN 0 ELSE p.price_uan END),0) AS uan
        FROM orders o LEFT JOIN prices p ON p.category_key = o.service_key WHERE o.status = 'ready'
    ")->fetch(PDO::FETCH_ASSOC);
    if ($rev) { $quickStats['revenue_rub'] = (int)$rev['rub']; $quickStats['revenue_uan'] = (int)$rev['uan']; }
} catch (Throwable $e) {}

$openAppealsQuick = 0;
try { $openAppealsQuick = (int)$pdo->query("SELECT COUNT(*) FROM appeals WHERE status = 'open'")->fetchColumn(); } catch (Throwable $e) {}
$pendingReviewsQuick = 0;
try { $pendingReviewsQuick = (int)$pdo->query("SELECT COUNT(*) FROM reviews WHERE approved = FALSE")->fetchColumn(); } catch (Throwable $e) {}
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
    body::before {
        content:''; position:fixed; top:-120px; left:50%; transform:translateX(-50%);
        width:700px; height:400px; background:radial-gradient(ellipse at center,rgba(249,115,22,0.13) 0%,transparent 70%);
        pointer-events:none; z-index:0;
    }
    .profile-shell { max-width: 760px; margin: 0 auto; padding: 32px 24px 60px; position:relative; z-index:1; }
    .back-link { color: #8a8a96; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 18px; }
    .back-link:hover { color: #f97316; }
    .notice { border: 1px solid rgba(34,197,94,.45); background: rgba(34,197,94,.10); color: #86efac; border-radius: 12px; padding: 14px 16px; margin-bottom: 18px; font-weight: 700; font-size: 13px; }

    /* Hero */
    .profile-hero {
        background: linear-gradient(180deg,#14141c,#111116); border: 1px solid rgba(255,255,255,.06); border-radius: 22px;
        padding: 28px; display: flex; align-items: center; gap: 22px; margin-bottom: 22px;
        box-shadow: 0 0 30px rgba(249,115,22,.08), 0 20px 50px rgba(0,0,0,.35); position: relative; overflow: hidden;
    }
    .profile-hero::before { content:''; position:absolute; top:-40px; right:-40px; width:200px; height:200px; background:radial-gradient(circle,rgba(249,115,22,0.10) 0%,transparent 70%); pointer-events:none; }
    .profile-hero-ava { width: 92px; height: 92px; border-radius: 50%; object-fit: cover; border: 3px solid #f97316; background: #0b0b10; box-shadow: 0 0 24px rgba(249,115,22,.25); flex-shrink: 0; }
    .profile-hero-info { flex: 1; min-width: 0; }
    .profile-hero-name { font-size: 22px; font-weight: 900; margin: 0 0 4px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .profile-hero-badge { font-size: 10.5px; font-weight: 900; text-transform: uppercase; letter-spacing: .5px; color: #fb923c; background: rgba(249,115,22,.15); border: 1px solid rgba(249,115,22,.35); border-radius: 6px; padding: 3px 8px; }
    .profile-hero-sub { color: #8a8a96; font-size: 12.5px; margin-bottom: 14px; }
    .profile-hero-stats { display: flex; gap: 10px; flex-wrap: wrap; }
    .profile-hero-stat { background: rgba(0,0,0,.25); border: 1px solid #20202c; border-radius: 10px; padding: 8px 14px; text-align: center; min-width: 74px; }
    .profile-hero-stat strong { display: block; font-size: 18px; }
    .profile-hero-stat span { font-size: 10px; color: #8a8a96; text-transform: uppercase; letter-spacing: .4px; }

    /* Quick links */
    .quicklinks-title { font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; color: #8a8a96; margin: 0 0 12px; }
    .quicklinks-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px,1fr)); gap: 10px; margin-bottom: 26px; }
    .quicklink { display: flex; flex-direction: column; gap: 6px; background: #111116; border: 1px solid #20202c; border-radius: 12px; padding: 14px; text-decoration: none; color: #d8d8e8; transition: .18s; position: relative; }
    .quicklink:hover { border-color: rgba(249,115,22,.5); background: rgba(249,115,22,.06); transform: translateY(-1px); }
    .quicklink .ql-icon { font-size: 18px; }
    .quicklink .ql-label { font-size: 12.5px; font-weight: 800; }
    .quicklink .ql-badge { position: absolute; top: 10px; right: 10px; background: #f97316; color: #fff; border-radius: 999px; font-size: 10px; font-weight: 800; padding: 1px 7px; }

    /* Form panels */
    .panel-title { font-size: 13px; font-weight: 900; text-transform: uppercase; letter-spacing: .6px; color: #d8d8e8; margin: 0 0 16px; display: flex; align-items: center; gap: 8px; }
    .panel { background: #111116; border: 1px solid #20202c; border-radius: 16px; padding: 24px; margin-bottom: 18px; }
    label { display: block; color: #d9d9e4; font-size: 12px; font-weight: 800; margin: 14px 0 6px; text-transform: uppercase; letter-spacing: .5px; }
    label:first-of-type { margin-top: 0; }
    input:not([type="file"]) { width: 100%; box-sizing: border-box; background: #171720; color: #fff; border: 1px solid #2a2a38; border-radius: 9px; padding: 11px 12px; outline: none; font-family: Montserrat,sans-serif; font-size: 13px; }
    input:focus { border-color: #f97316; }
    input[type="file"] { color: #d8d8e8; font-size: 12px; }
    .two-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .hint { color: #8a8a96; font-size: 11px; margin-top: 6px; line-height: 1.5; }
    .warn-hint { color: #fdba74; font-size: 11px; margin-top: 8px; line-height: 1.5; background: rgba(249,115,22,.08); border-left: 2px solid #f97316; padding: 8px 10px; border-radius: 6px; }
    button.btn-save { width: 100%; margin-top: 22px; border: none; border-radius: 10px; padding: 13px 16px; background: linear-gradient(135deg,#fb923c,#f97316); color: #fff; font-weight: 900; cursor: pointer; text-transform: uppercase; font-family: Montserrat,sans-serif; letter-spacing: 1px; font-size: 13px; }
    button.btn-save:hover { opacity: .92; }
    @media (max-width: 600px) {
        .profile-hero { flex-direction: column; text-align: center; }
        .profile-hero-stats { justify-content: center; }
        .two-cols { grid-template-columns: 1fr; }
    }
</style>
</head>
<body>
<div class="profile-shell">
    <a href="index.php" class="back-link">← Назад в админку</a>

    <?php if ($message !== ''): ?>
        <div class="notice"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- ── HERO ── -->
    <div class="profile-hero">
        <img src="<?= htmlspecialchars($avatarSrc ?: 'https://i.imgur.com/w9NThbA.png') ?>" class="profile-hero-ava" onerror="this.src='https://i.imgur.com/w9NThbA.png'" alt="">
        <div class="profile-hero-info">
            <div class="profile-hero-name"><?= htmlspecialchars($currentUsername) ?> <span class="profile-hero-badge">admin</span></div>
            <div class="profile-hero-sub"><?= htmlspecialchars($currentEmail) ?> · TG ID <?= htmlspecialchars($currentTgId) ?></div>
            <div class="profile-hero-stats">
                <div class="profile-hero-stat"><strong><?= (int)$quickStats['total'] ?></strong><span>Заказов</span></div>
                <div class="profile-hero-stat"><strong style="color:#60a5fa;"><?= (int)$quickStats['active'] ?></strong><span>Активных</span></div>
                <div class="profile-hero-stat"><strong style="color:#4ade80;"><?= (int)$quickStats['ready'] ?></strong><span>Готово</span></div>
                <div class="profile-hero-stat"><strong style="color:#fdba74;"><?= number_format($quickStats['revenue_rub'],0,'.',' ') ?>₽</strong><span>Заработано</span></div>
            </div>
        </div>
    </div>

    <!-- ── БЫСТРЫЕ ССЫЛКИ ── -->
    <div class="quicklinks-title">Быстрые ссылки</div>
    <div class="quicklinks-grid">
        <a href="index.php#orders" class="quicklink" onclick="localStorage.setItem('admin_active_tab','orders')">
            <span class="ql-icon">🧾</span><span class="ql-label">Заказы</span>
            <?php if ($quickStats['active'] > 0): ?><span class="ql-badge"><?= (int)$quickStats['active'] ?></span><?php endif; ?>
        </a>
        <a href="index.php" class="quicklink" onclick="localStorage.setItem('admin_active_tab','appeals')">
            <span class="ql-icon">📩</span><span class="ql-label">Обращения</span>
            <?php if ($openAppealsQuick > 0): ?><span class="ql-badge"><?= $openAppealsQuick ?></span><?php endif; ?>
        </a>
        <a href="index.php" class="quicklink" onclick="localStorage.setItem('admin_active_tab','reviews')">
            <span class="ql-icon">⭐</span><span class="ql-label">Отзывы</span>
            <?php if ($pendingReviewsQuick > 0): ?><span class="ql-badge"><?= $pendingReviewsQuick ?></span><?php endif; ?>
        </a>
        <a href="index.php" class="quicklink" onclick="localStorage.setItem('admin_active_tab','keys')">
            <span class="ql-icon">🔑</span><span class="ql-label">Ключи и API</span>
        </a>
        <a href="index.php" class="quicklink" onclick="localStorage.setItem('admin_active_tab','rules')">
            <span class="ql-icon">📋</span><span class="ql-label">Правила заказа</span>
        </a>
        <a href="index.php" class="quicklink" onclick="localStorage.setItem('admin_active_tab','ai-prompt')">
            <span class="ql-icon">🤖</span><span class="ql-label">ИИ-промпт</span>
        </a>
    </div>

    <!-- ── РЕДАКТИРОВАНИЕ ПРОФИЛЯ ── -->
    <form method="POST" enctype="multipart/form-data">
        <div class="panel">
            <div class="panel-title">🖼️ Имя и аватарка</div>
            <label>Отображаемое имя</label>
            <input type="text" name="username" value="<?= htmlspecialchars($currentUsername) ?>">
            <label>Новая аватарка</label>
            <input type="file" name="avatar" accept="image/*">
            <div class="hint">Эта аватарка — водяной знак на постах в Telegram-канале. Форматы: jpg, png, webp, gif.</div>
        </div>

        <div class="panel">
            <div class="panel-title">🔐 Данные администратора</div>
            <div class="two-cols">
                <div>
                    <label>Email администратора</label>
                    <input type="email" name="admin_email" value="<?= htmlspecialchars($currentEmail) ?>">
                </div>
                <div>
                    <label>Telegram ID администратора</label>
                    <input type="text" name="admin_tg_id" value="<?= htmlspecialchars($currentTgId) ?>">
                </div>
            </div>
            <div class="warn-hint">⚠️ TG ID — тот же, что проверяется при входе через Telegram Mini App (см. auth.php). Меняй только если точно знаешь свой новый Telegram ID — иначе можно случайно потерять доступ в панель.</div>
        </div>

        <button type="submit" name="save_profile" class="btn-save">💾 Сохранить профиль</button>
    </form>
</div>
</body>
</html>