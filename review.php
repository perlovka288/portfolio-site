<?php
require_once 'includes/session.php';
require_once 'config/db.php';

$bot_token = getenv('BOT_TOKEN') ?: '8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg';

// Создаём таблицу если нет
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reviews (
        id SERIAL PRIMARY KEY,
        order_id INT NOT NULL DEFAULT 0,
        tg_username VARCHAR(128) NOT NULL DEFAULT '',
        tg_first_name VARCHAR(255) NOT NULL DEFAULT '',
        tg_photo_url TEXT DEFAULT NULL,
        rating SMALLINT NOT NULL DEFAULT 5,
        text TEXT NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        approved BOOLEAN NOT NULL DEFAULT TRUE
    )");
} catch (Throwable $e) {}

// TG профиль из сессии
$sid = session_id();
$tgProfile = null;
try {
    $stmt = $pdo->prepare("SELECT tg_username, tg_first_name, tg_photo_url, tg_id FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1");
    $stmt->execute([$sid]);
    $tgProfile = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {}

$success = false;
$error = '';
$preOrderId = (int)($_GET['order'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating   = max(1, min(5, (int)($_POST['rating'] ?? 5)));
    $text     = trim($_POST['text'] ?? '');
    $order_id = (int)($_POST['order_id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');

    if (mb_strlen($text) < 10) {
        $error = 'Напишите хотя бы пару слов об опыте работы.';
    } else {
        $tg_username  = $tgProfile['tg_username']  ?? '';
        $tg_first_name = $tgProfile['tg_first_name'] ?? $name;
        $tg_photo_url = $tgProfile['tg_photo_url']  ?? '';
        $tg_id_val    = (string)($tgProfile['tg_id'] ?? '');

        // Cooldown 5 minutes for non-admins
        $isAdminReview = ($tg_id_val === '1710365896' || $tg_id_val === (getenv('ADMIN_ID') ?: '1710365896'));
        if (!$isAdminReview) {
            try {
                $cdStmt = $pdo->prepare("SELECT created_at FROM reviews WHERE tg_username = ? ORDER BY id DESC LIMIT 1");
                $cdStmt->execute([$tg_username]);
                $lastReview = $cdStmt->fetch(PDO::FETCH_ASSOC);
                if ($lastReview) {
                    $secsPassed = time() - strtotime($lastReview['created_at']);
                    if ($secsPassed < 300) {
                        $minsLeft = ceil((300 - $secsPassed) / 60);
                        $error = "⏳ Подождите ещё {$minsLeft} мин. перед следующим отзывом.";
                    }
                }
            } catch (Throwable $e) {}
        }

        if (!$error) try {
            $pdo->prepare("INSERT INTO reviews (order_id, tg_username, tg_first_name, tg_photo_url, rating, text) VALUES (?,?,?,?,?,?)")
                ->execute([$order_id, $tg_username, $tg_first_name, $tg_photo_url, $rating, $text]);
            
            $lastId = $pdo->lastInsertId();
            
            // Отправляем уведомление админу через CURL в админку (или напрямую в ТГ)
            $admin_id = getenv('ADMIN_ID') ?: "1710365896";
            $msg = "⭐ *Новый отзыв!* (Заказ #{$order_id})\n\n";
            $msg .= "👤 От: " . ($tg_first_name ?: "@".$tg_username) . "\n" . str_repeat('⭐', $rating) . "\n\n";
            $msg .= "💬 " . $text;
            
            $ch = curl_init("https://api.telegram.org/bot{$bot_token}/sendMessage");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'chat_id' => $admin_id,
                'text' => $msg,
                'parse_mode' => 'Markdown'
            ]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch); curl_close($ch);

            $success = true;
        } catch (Throwable $e) {
            $error = 'Ошибка сохранения. Попробуйте ещё раз.';
        }
        } // end if(!$error)
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оставить отзыв — Kostlim Design</title>
<?php
// Dynamic favicon from site avatar
$_favicon_url = '';
try {
    $_fav_row = $pdo->query("SELECT avatar FROM users LIMIT 1")->fetch();
    if (!empty($_fav_row['avatar'])) {
        $v = $_fav_row['avatar'];
        if (str_starts_with($v, 'http://') || str_starts_with($v, 'https://')) {
            $_favicon_url = $v;
        } else {
            $_favicon_url = '/' . ltrim('uploads/' . $v, '/');
        }
    }
} catch (Throwable $e) {}
?>
<?php if ($_favicon_url): ?>
<link rel="icon" type="image/png" href="<?= htmlspecialchars($_favicon_url) ?>">
<?php else: ?>
<link rel="icon" type="image/png" href="https://i.imgur.com/w9NThbA.png">
<?php endif; ?>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background:#08080b; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
        .review-wrap { width:100%; max-width:520px; }
        .review-card { background:#111116; border:1px solid #20202c; border-radius:20px; padding:32px 28px; }
        .review-header { text-align:center; margin-bottom:28px; }
        .review-header h1 { font-size:22px; font-weight:900; margin:0 0 6px; }
        .review-header p { color:#8a8a96; font-size:13px; margin:0; }
        .tg-profile-chip { display:flex; align-items:center; gap:12px; background:rgba(249,115,22,.08); border:1px solid rgba(249,115,22,.2); border-radius:12px; padding:12px 16px; margin-bottom:20px; }
        .tg-ava { width:40px; height:40px; border-radius:50%; object-fit:cover; border:2px solid rgba(249,115,22,.4); flex-shrink:0; }
        .tg-ava-fallback { width:40px; height:40px; border-radius:50%; background:linear-gradient(135deg,#f97316,#ea580c); display:flex; align-items:center; justify-content:center; font-size:16px; font-weight:900; color:#fff; flex-shrink:0; }
        .star-row { display:flex; gap:8px; justify-content:center; margin-bottom:24px; }
        .star-btn { background:none; border:none; font-size:36px; cursor:pointer; color:#2a2a38; transition:color .15s, transform .15s; padding:0; line-height:1; }
        .star-btn:hover, .star-btn.active { color:#f97316; text-shadow:0 0 15px rgba(249,115,22,0.5); transform:scale(1.15); }
        label { display:block; font-size:12px; font-weight:800; color:#8a8a96; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; }
        input, textarea { width:100%; background:#171720; color:#fff; border:1px solid #2a2a38; border-radius:10px; padding:12px 14px; font-family:Montserrat,Arial,sans-serif; font-size:13px; outline:none; box-sizing:border-box; transition:.2s; }
        input:focus, textarea:focus { border-color:#f97316; box-shadow:0 0 0 3px rgba(249,115,22,.15); }
        textarea { min-height:100px; resize:vertical; }
        .btn-submit { width:100%; margin-top:20px; border:none; border-radius:12px; padding:14px; background:linear-gradient(135deg,#fb923c,#f97316); color:#fff; font-weight:900; font-size:14px; cursor:pointer; font-family:Montserrat,sans-serif; letter-spacing:.5px; box-shadow:0 8px 24px rgba(249,115,22,.3); transition:.2s; }
        .btn-submit:hover { transform:translateY(-1px); box-shadow:0 12px 30px rgba(249,115,22,.4); }
        .msg-error { background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.3); border-radius:10px; padding:12px; color:#fca5a5; font-size:13px; margin-bottom:16px; }
        .msg-success-wrap { text-align:center; padding:20px 0; }
        .msg-success-wrap .icon { font-size:56px; margin-bottom:16px; }
        .back-link { display:inline-flex; align-items:center; gap:6px; color:#8a8a96; text-decoration:none; font-size:13px; font-weight:700; margin-bottom:20px; transition:.15s; }
        .back-link:hover { color:#fff; }
    </style>
</head>
<body>
<div class="review-wrap">
    <a href="index.php#reviews" class="back-link">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        Назад
    </a>

    <div class="review-card">
        <?php if ($success): ?>
            <div class="msg-success-wrap">
                <div class="icon">🎉</div>
                <h2 style="margin:0 0 8px;font-size:20px;font-weight:900;">Спасибо за отзыв!</h2>
                <p style="color:#8a8a96;margin:0 0 24px;">Ваш отзыв опубликован на сайте.</p>
                <a href="index.php#reviews" style="display:inline-flex;align-items:center;gap:8px;background:rgba(249,115,22,.15);border:1px solid rgba(249,115,22,.3);color:#fb923c;padding:11px 24px;border-radius:10px;text-decoration:none;font-weight:800;font-size:13px;">Посмотреть отзывы</a>
            </div>
        <?php elseif (!$tgProfile): ?>
            <div class="msg-success-wrap">
                <div class="icon">🔐</div>
                <h2 style="margin:0 0 8px;font-size:20px;font-weight:900;">Нужна привязка Telegram</h2>
                <p style="color:#8a8a96;margin:0 0 24px;line-height:1.6;">Чтобы оставить отзыв, необходимо привязать свой Telegram к сайту. Это защищает от спама и позволяет подтянуть вашу аватарку.</p>
                <a href="index.php?open_tg=1" class="btn-submit" style="text-decoration:none; display:inline-flex; justify-content:center; align-items:center;">Привязать Telegram</a>
            </div>
        <?php else: ?>
            <div class="review-header">
                <h1>✍️ Оставить отзыв</h1>
                <p>Ваш отзыв помогает другим клиентам</p>
            </div>

            <?php if ($tgProfile): ?>
                <div class="tg-profile-chip">
                    <?php if (!empty($tgProfile['tg_photo_url'])): ?>
                        <img src="<?= htmlspecialchars($tgProfile['tg_photo_url']) ?>" class="tg-ava" onerror="this.style.display='none'">
                    <?php else: ?>
                        <div class="tg-ava-fallback"><?= mb_strtoupper(mb_substr($tgProfile['tg_first_name'] ?: '?', 0, 1)) ?></div>
                    <?php endif; ?>
                    <div>
                        <div style="font-size:14px;font-weight:800;color:#fff;"><?= htmlspecialchars($tgProfile['tg_first_name'] ?: 'Пользователь') ?></div>
                        <?php if (!empty($tgProfile['tg_username'])): ?>
                            <div style="font-size:12px;color:#8a8a96;">@<?= htmlspecialchars($tgProfile['tg_username']) ?></div>
                        <?php endif; ?>
                    </div>
                    <span style="margin-left:auto;font-size:11px;background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.3);color:#86efac;border-radius:6px;padding:3px 8px;font-weight:700;">TG привязан</span>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="msg-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <?php if ($preOrderId): ?>
                    <input type="hidden" name="order_id" value="<?= $preOrderId ?>">
                <?php endif; ?>

                <!-- Звёзды -->
                <label style="text-align:center;display:block;margin-bottom:10px;">Ваша оценка</label>
                <div class="star-row" id="stars">
                    <?php for($i=1;$i<=5;$i++): ?>
                        <button type="button" class="star-btn <?= $i <= 5 ? 'active' : '' ?>" data-val="<?= $i ?>" onclick="setStar(<?= $i ?>)">★</button>
                    <?php endfor; ?>
                </div>
                <input type="hidden" name="rating" id="ratingInput" value="5">

                <div style="margin-bottom:4px;">
                    <label>Ваш отзыв</label>
                    <textarea name="text" placeholder="Расскажите об опыте работы с дизайнером..." required minlength="10"></textarea>
                </div>

                <button type="submit" class="btn-submit">Опубликовать отзыв ⭐</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
function setStar(val) {
    document.getElementById('ratingInput').value = val;
    document.querySelectorAll('.star-btn').forEach(function(btn) {
        btn.classList.toggle('active', parseInt(btn.dataset.val) <= val);
    });
}
// Ховер
document.querySelectorAll('.star-btn').forEach(function(btn) {
    btn.addEventListener('mouseenter', function() {
        var val = parseInt(this.dataset.val);
        document.querySelectorAll('.star-btn').forEach(function(b) {
            b.style.color = parseInt(b.dataset.val) <= val ? '#f59e0b' : '#2a2a38';
        });
    });
    btn.addEventListener('mouseleave', function() {
        var cur = parseInt(document.getElementById('ratingInput').value);
        document.querySelectorAll('.star-btn').forEach(function(b) {
            b.style.color = parseInt(b.dataset.val) <= cur ? '#f59e0b' : '#2a2a38';
        });
    });
});
</script>
</body>
</html>