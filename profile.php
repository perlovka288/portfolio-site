<?php
session_start();
require_once 'config/db.php';

$adminTgId = getenv('ADMIN_ID') ?: '1710365896';
$isAdmin   = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;

$sid     = session_id();
$profile = null;
$orders  = [];

// ── Обработка отмены заказа ──────────────────────────────────
$cancelMsg = '';
if (isset($_POST['cancel_order'])) {
    $cancelId = (int)($_POST['order_id'] ?? 0);
    try {
        // Проверяем что заказ принадлежит этому пользователю и его можно отменить
        $stmt = $pdo->prepare("
            SELECT id, client_chat_id, telegram, status FROM orders WHERE id = ? LIMIT 1
        ");
        $stmt->execute([$cancelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && in_array($row['status'], ['pending', 'in_progress'])) {
            // Проверяем привязку сессии
            $linkStmt = $pdo->prepare("SELECT tg_id, tg_username FROM tg_links WHERE session_id = ? ORDER BY id DESC LIMIT 1");
            $linkStmt->execute([$sid]);
            $linkRow = $linkStmt->fetch(PDO::FETCH_ASSOC);
            $canCancel = false;
            if ($linkRow) {
                $tgId = (string)($linkRow['tg_id'] ?? '');
                $tgUser = ltrim($linkRow['tg_username'] ?? '', '@');
                if (($tgId !== '' && (string)$row['client_chat_id'] === $tgId) ||
                    (!empty($tgUser) && (rtrim($row['telegram'],'@') === $tgUser || '@'.$tgUser === $row['telegram']))) {
                    $canCancel = true;
                }
            }
            if ($canCancel) {
                $pdo->prepare("UPDATE orders SET status = 'declined' WHERE id = ?")->execute([$cancelId]);
                $cancelMsg = 'cancel_ok';
            }
        }
    } catch (Throwable $e) {}
    header('Location: ' . $_SERVER['PHP_SELF'] . '?cancelled=' . $cancelId);
    exit;
}

// ── Обработка отправки обращения ─────────────────────────────
$appealMsg = '';
if (isset($_POST['send_appeal'])) {
    $appealOrderId = (int)($_POST['appeal_order_id'] ?? 0);
    $appealSubject = trim($_POST['appeal_subject'] ?? '');
    $appealText    = trim($_POST['appeal_message'] ?? '');
    $appealUsername = '';
    $appealTelegram = '';
    try {
        $linkStmt = $pdo->prepare("SELECT tg_id, tg_username, tg_first_name FROM tg_links WHERE session_id = ? ORDER BY id DESC LIMIT 1");
        $linkStmt->execute([$sid]);
        $linkRow = $linkStmt->fetch(PDO::FETCH_ASSOC);
        if ($linkRow) {
            $appealTelegram = $linkRow['tg_id'] ?? '';
            $tgFirstName  = trim((string)($linkRow['tg_first_name'] ?? ''));
            $tgUsername   = trim((string)($linkRow['tg_username'] ?? ''));
            if ($tgFirstName !== '' && $tgUsername !== '') {
                $appealUsername = $tgFirstName . ' (@' . ltrim($tgUsername, '@') . ')';
            } elseif ($tgFirstName !== '') {
                $appealUsername = $tgFirstName;
            } elseif ($tgUsername !== '') {
                $appealUsername = '@' . ltrim($tgUsername, '@');
            }
        }
        if ($appealOrderId > 0 && $appealSubject !== '' && $appealText !== '') {
            // создаём запись-обращение (поток) и сохраняем первое сообщение как сообщение клиента
            try {
                $ins = $pdo->prepare("INSERT INTO appeals (order_id, username, telegram, subject, status, created_at) VALUES (?, ?, ?, ?, 'open', NOW()) RETURNING id");
                $ins->execute([$appealOrderId, $appealUsername, $appealTelegram, $appealSubject]);
                $aid = (int)$ins->fetchColumn();
                if ($aid > 0) {
                    $m = $pdo->prepare("INSERT INTO appeals_messages (appeal_id, author, message, created_at) VALUES (?, 'client', ?, NOW())");
                    $m->execute([$aid, $appealText]);
                }
                $appealMsg = 'ok';
            } catch (Throwable $e) { $appealMsg = 'err'; }

// ── Уведомление админу о новом обращении ──────────────────
$_tgToken  = getenv('TELEGRAM_BOT_TOKEN') ?: '8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg';
$_adminId  = getenv('ADMIN_ID') ?: '1710365896';
$_siteUrl  = 'https://portfolio-site-boo5.onrender.com/admin/index.php?order=' . $appealOrderId;
$_tgText   = "📩 <b>Новое обращение!</b>\n\n"
    . "👤 Клиент: <b>" . htmlspecialchars($appealUsername ?: 'Клиент') . "</b>\n"
    . "📋 Заказ: <b><a href=\"" . $_siteUrl . "\">#" . $appealOrderId . "</a></b>\n"
    . "📌 Тема: <b>" . htmlspecialchars($appealSubject) . "</b>\n\n"
    . "💬 <i>" . htmlspecialchars(mb_substr($appealText, 0, 300)) . (mb_strlen($appealText) > 300 ? '...' : '') . "</i>\n\n"
    . "🔗 <a href=\"" . $_siteUrl . "\">Открыть админ-панель → Обращения</a>";
$_ch = curl_init('https://api.telegram.org/bot' . $_tgToken . '/sendMessage');
curl_setopt_array($_ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_POSTFIELDS     => ['chat_id' => $_adminId, 'text' => $_tgText, 'parse_mode' => 'HTML'],
]);
curl_exec($_ch);
curl_close($_ch);
unset($_ch, $_tgToken, $_adminId, $_siteUrl, $_tgText);
// ──────────────────────────────────────────────────────────
        } else {
            $appealMsg = 'err';
        }
    } catch (Throwable $e) { $appealMsg = 'err'; }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?order=' . $appealOrderId . '&appeal=' . $appealMsg);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT tg_id, tg_username, tg_first_name, tg_photo_url, linked, created_at
        FROM tg_links
        WHERE session_id = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$sid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && $row['linked'] && $row['linked'] !== 'f') {
        $profile = $row;
        if (!$isAdmin && !empty($row['tg_id']) && (string)$row['tg_id'] === $adminTgId) {
            $isAdmin = true;
        }

        $tg_id       = $row['tg_id'] ?? '';
        $tg_username = $row['tg_username'] ?? '';

        $params  = [];
        $clauses = [];
        if ($tg_id !== '') {
            $clauses[] = 'client_chat_id = ?';
            $params[]  = $tg_id;
        }
        if ($tg_username !== '') {
            $clauses[] = 'telegram = ?';
            $params[]  = '@' . ltrim($tg_username, '@');
            $clauses[] = 'telegram = ?';
            $params[]  = ltrim($tg_username, '@');
        }

        if (!empty($clauses)) {
            $sql = "SELECT id, service_key, status, details, created_at, screenshot, example_photo
                    FROM orders
                    WHERE " . implode(' OR ', $clauses) . "
                    ORDER BY created_at DESC
                    LIMIT 50";
            $ostmt = $pdo->prepare($sql);
            $ostmt->execute($params);
            $orders = $ostmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Загружаем обращения пользователя (ответы на них)
        $userAppeals = [];
        try {
            if ($tg_id !== '') {
                $astmt = $pdo->prepare("SELECT * FROM appeals WHERE telegram = ? ORDER BY id DESC");
                $astmt->execute([$tg_id]);
                $userAppeals = $astmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {}
    }
} catch (Throwable $e) {}

try {
    $settings     = $pdo->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) { $settings = []; }
$themePreset  = $settings['theme_preset']  ?? 'onyx';
$themeShape   = $settings['theme_shape']   ?? 'soft';
$themeDensity = $settings['theme_density'] ?? 'normal';
$themeEffects = $settings['theme_effects'] ?? 'glow';

function profileStatusLabel(string $s): string {
    return match($s) {
        'pending'     => 'Ожидает',
        'in_progress' => 'В работе',
        'urgent'      => 'Срочный',
        'ready'       => 'Готов',
        'declined'    => 'Отклонён',
        default       => ucfirst($s),
    };
}
function profileStatusColor(string $s): string {
    return match($s) {
        'pending'     => '#fb923c',
        'in_progress' => '#60a5fa',
        'urgent'      => '#f43f5e',
        'ready'       => '#4ade80',
        'declined'    => '#6b7280',
        default       => '#8a8a96',
    };
}
function profileStatusEmoji(string $s): string {
    return match($s) {
        'pending'     => '🕐',
        'in_progress' => '🚀',
        'urgent'      => '⚡',
        'ready'       => '✅',
        'declined'    => '❌',
        default       => '📦',
    };
}

$displayName = $profile ? (
    !empty($profile['tg_first_name']) ? $profile['tg_first_name'] :
    (!empty($profile['tg_username'])  ? '@' . $profile['tg_username'] : 'Гость')
) : 'Гость';

$activeOrders   = array_filter($orders, fn($o) => in_array($o['status'], ['pending','in_progress','urgent']));
$finishedOrders = array_filter($orders, fn($o) => in_array($o['status'], ['ready','declined']));

// Разворачиваемый заказ из GET
$expandedOrderId = (int)($_GET['order'] ?? 0);
$appealStatus    = $_GET['appeal'] ?? '';
$cancelledId     = (int)($_GET['cancelled'] ?? 0);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Профиль | Kostlim Design</title>
<link rel="stylesheet" href="style.css">
<style>
body::before {
    content:'';position:fixed;top:-120px;left:50%;transform:translateX(-50%);
    width:700px;height:400px;background:radial-gradient(ellipse at center,rgba(249,115,22,0.13) 0%,transparent 70%);
    pointer-events:none;z-index:0;
}
.profile-wrap { max-width:760px;margin:0 auto;padding:40px 20px 80px;position:relative;z-index:1; }
.profile-hero {
    background:var(--card);border:1px solid var(--border);border-radius:24px;padding:32px 28px;
    display:flex;align-items:center;gap:24px;margin-bottom:32px;
    box-shadow:0 0 40px rgba(0,0,0,0.3);position:relative;overflow:hidden;
}
.profile-hero::before {
    content:'';position:absolute;top:-40px;right:-40px;width:200px;height:200px;
    background:radial-gradient(circle,rgba(34,197,94,0.08) 0%,transparent 70%);pointer-events:none;
}
.profile-ava-wrap { position:relative;flex-shrink:0; }
.profile-ava { width:88px;height:88px;border-radius:50%;object-fit:cover;border:3px solid rgba(34,197,94,0.5);box-shadow:0 0 24px rgba(34,197,94,0.2);display:block; }
.profile-ava-fallback {
    width:88px;height:88px;border-radius:50%;background:linear-gradient(135deg,rgba(34,197,94,0.2),rgba(34,197,94,0.05));
    border:3px solid rgba(34,197,94,0.4);display:flex;align-items:center;justify-content:center;
    font-size:32px;font-weight:900;color:#86efac;text-transform:uppercase;
}
.profile-tg-badge {
    position:absolute;bottom:2px;right:2px;width:24px;height:24px;background:#0088cc;
    border-radius:50%;border:2px solid var(--bg);display:flex;align-items:center;justify-content:center;
}
.profile-info { flex:1;min-width:0; }
.profile-name { font-size:22px;font-weight:900;color:var(--text);margin:0 0 4px;text-transform:uppercase;letter-spacing:0.5px; }
.profile-username { color:#86efac;font-size:13px;font-weight:700;margin-bottom:10px; }
.profile-meta { display:flex;gap:16px;flex-wrap:wrap; }
.profile-stat { display:flex;flex-direction:column;align-items:center;background:rgba(0,0,0,0.25);border:1px solid var(--border);border-radius:10px;padding:8px 16px;min-width:70px; }
.profile-stat-num { font-size:20px;font-weight:900;color:var(--text); }
.profile-stat-label { font-size:10px;color:var(--text2);text-transform:uppercase;letter-spacing:0.5px;margin-top:2px; }
.profile-actions { display:flex;flex-direction:column;gap:8px;flex-shrink:0; }
.profile-action-btn { display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:10px;font-size:12px;font-weight:800;text-decoration:none;border:none;cursor:pointer;transition:.2s;font-family:inherit;white-space:nowrap; }
.btn-catalog { background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);color:#c0c0d0; }
.btn-catalog:hover { background:rgba(255,255,255,0.1); }
.btn-order { background:linear-gradient(135deg,#fb923c,#f97316);color:#fff;box-shadow:0 0 16px rgba(249,115,22,0.3); }
.btn-order:hover { opacity:.88;transform:translateY(-1px); }
.btn-bot { background:rgba(0,136,204,0.15);border:1px solid rgba(0,136,204,0.3);color:#60c8f5; }
.btn-bot:hover { background:rgba(0,136,204,0.25); }

.orders-section { margin-bottom:28px; }
.orders-section-title { font-size:13px;font-weight:900;text-transform:uppercase;letter-spacing:1.5px;color:var(--text2);margin:0 0 14px;display:flex;align-items:center;gap:8px; }
.orders-section-title::after { content:'';flex:1;height:1px;background:var(--border); }

/* Карточка заказа */
.order-card { background:var(--card);border:1px solid var(--border);border-radius:16px;margin-bottom:12px;overflow:hidden;transition:border-color .2s,box-shadow .2s; }
.order-card:hover { border-color:var(--border-accent);box-shadow:0 0 20px rgba(249,115,22,0.1); }
.order-card-header { padding:18px 20px;display:flex;align-items:flex-start;gap:16px;cursor:pointer;user-select:none; }
.order-card-header:hover { background:rgba(255,255,255,0.02); }
.order-card-emoji { font-size:22px;flex-shrink:0;margin-top:2px; }
.order-card-body { flex:1;min-width:0; }
.order-card-title { font-size:14px;font-weight:800;color:var(--text);margin:0 0 4px; }
.order-card-meta { font-size:12px;color:var(--text2);margin-bottom:4px; }
.order-card-details { font-size:12px;color:var(--text2);line-height:1.55;max-height:44px;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical; }
.order-status-badge { display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:800;border:1px solid;white-space:nowrap;flex-shrink:0; }
.order-expand-arrow { flex-shrink:0;color:var(--text2);transition:transform .25s;margin-top:4px; }
.order-card-header[aria-expanded="true"] .order-expand-arrow { transform:rotate(180deg); }

/* Развёрнутое тело */
.order-card-expanded { border-top:1px solid var(--border);padding:18px 20px;display:none; }
.order-card-expanded.open { display:block; }
.order-detail-block { background:rgba(0,0,0,0.2);border-radius:10px;padding:12px 14px;margin-bottom:12px;font-size:13px;color:var(--text2);line-height:1.6;white-space:pre-wrap;word-break:break-word; }
.order-actions-row { display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px; }
.btn-cancel-order {
    display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;
    background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;
    font-size:12px;font-weight:800;cursor:pointer;font-family:inherit;transition:.2s;
}
.btn-cancel-order:hover { background:rgba(239,68,68,0.2);border-color:#ef4444; }
.btn-appeal-toggle {
    display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;
    background:rgba(249,115,22,0.1);border:1px solid rgba(249,115,22,0.3);color:#fdba74;
    font-size:12px;font-weight:800;cursor:pointer;font-family:inherit;transition:.2s;
}
.btn-appeal-toggle:hover { background:rgba(249,115,22,0.2);border-color:#f97316; }

/* Форма обращения */
.appeal-form-wrap { background:rgba(249,115,22,0.05);border:1px solid rgba(249,115,22,0.2);border-radius:12px;padding:16px;margin-top:4px;display:none; }
.appeal-form-wrap.open { display:block; }
.appeal-form-wrap label { display:block;color:#d9d9e4;font-size:11px;font-weight:800;margin:10px 0 5px;text-transform:uppercase;letter-spacing:.5px; }
.appeal-form-wrap input, .appeal-form-wrap textarea {
    width:100%;background:#0e0e14;color:#fff;border:1px solid #2a2a38;border-radius:8px;
    padding:10px 12px;outline:none;font-family:Montserrat,sans-serif;font-size:13px;transition:.2s;box-sizing:border-box;
}
.appeal-form-wrap input:focus, .appeal-form-wrap textarea:focus { border-color:#f97316;box-shadow:0 0 0 3px rgba(249,115,22,0.15); }
.appeal-form-wrap textarea { min-height:80px;resize:vertical; }
.btn-appeal-submit {
    margin-top:10px;border:none;border-radius:9px;padding:10px 20px;
    background:linear-gradient(135deg,#fb923c,#f97316);color:#fff;font-weight:800;
    cursor:pointer;font-family:Montserrat,sans-serif;font-size:13px;
    box-shadow:0 6px 18px rgba(249,115,22,0.3);transition:.2s;
}
.btn-appeal-submit:hover { opacity:.88;transform:translateY(-1px); }

/* Ответы на обращения */
.appeal-reply-block { background:rgba(34,197,94,0.06);border-left:3px solid #22c55e;border-radius:0 10px 10px 0;padding:12px 14px;margin-bottom:8px; }
.appeal-reply-subject { font-size:12px;font-weight:800;color:#d8d8e8;margin-bottom:4px; }
.appeal-reply-text { font-size:13px;color:#86efac;line-height:1.6;white-space:pre-wrap;word-break:break-word; }
.appeal-reply-meta { font-size:11px;color:#6b7280;margin-top:6px; }
.appeal-pending-block { background:rgba(249,115,22,0.05);border-left:3px solid #f97316;border-radius:0 10px 10px 0;padding:10px 14px;margin-bottom:8px; }
.appeal-pending-text { font-size:12px;color:#fdba74; }

/* Уведомления */
.profile-notice { border-radius:12px;padding:13px 16px;margin-bottom:18px;font-weight:700;font-size:13px; }
.profile-notice.ok { background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.35);color:#86efac; }
.profile-notice.err { background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.35);color:#fca5a5; }
.profile-notice.info { background:rgba(249,115,22,0.1);border:1px solid rgba(249,115,22,0.35);color:#fdba74; }

.empty-state { text-align:center;padding:40px 20px;color:var(--text2); }
.empty-state-icon { font-size:40px;margin-bottom:12px; }
.empty-state p { font-size:14px;margin:0 0 16px; }
.empty-state a { display:inline-flex;align-items:center;gap:7px;padding:10px 22px;background:linear-gradient(135deg,#fb923c,#f97316);color:#fff;border-radius:30px;text-decoration:none;font-size:13px;font-weight:800;box-shadow:0 0 16px rgba(249,115,22,0.3); }
.not-linked-card { background:var(--card);border:1px solid var(--border);border-radius:20px;padding:40px 28px;text-align:center;max-width:420px;margin:60px auto; }
.not-linked-card h2 { color:var(--text);font-size:18px;margin:16px 0 8px; }
.not-linked-card p { color:var(--text2);font-size:13px;margin:0 0 20px; }

/* Раздел ответов на обращения */
.appeals-answers-section { margin-bottom:28px; }
.appeal-answered-card { background:var(--card);border:1px solid rgba(34,197,94,0.25);border-radius:14px;padding:16px 18px;margin-bottom:10px; }
.appeal-answered-card-title { font-size:13px;font-weight:800;color:#d8d8e8;margin-bottom:6px; }
.appeal-answered-card-order { font-size:11px;color:#8a8a96;margin-bottom:10px; }

@media(max-width:600px){
    .profile-hero{flex-direction:column;align-items:flex-start;padding:22px 18px;}
    .profile-actions{flex-direction:row;width:100%;}
    .profile-action-btn{flex:1;justify-content:center;}
    .profile-meta{gap:10px;}
    .order-card-header{flex-wrap:wrap;}
    .order-actions-row{flex-direction:column;}
}
</style>
</head>
<body class="theme-<?= htmlspecialchars($themePreset) ?> shape-<?= htmlspecialchars($themeShape) ?> density-<?= htmlspecialchars($themeDensity) ?> effects-<?= htmlspecialchars($themeEffects) ?>">

<header>
    <div class="header-left" style="display:flex;align-items:center;gap:10px;">
        <img src="https://i.imgur.com/w9NThbA.png" class="avatar-mini" alt="Kostlim" onerror="this.src='https://i.imgur.com/w9NThbA.png'">
        <a href="https://t.me/designkostlim" target="_blank" class="tg-glow-btn" title="Telegram">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        </a>
    </div>
    <div class="brand-title"><a href="index.php" style="text-decoration:none;color:inherit;"><h1>KOSTLIM</h1><span>DESIGN</span></a></div>
    <div class="header-right" style="display:flex;align-items:center;gap:10px;">
        <a href="price.php" class="nav-link nav-price">Прайс</a>
        <?php if ($profile): ?>
        <span class="tg-user-chip" style="cursor:default;">
            <?php if (!empty($profile['tg_photo_url'])): ?>
                <img src="<?= htmlspecialchars($profile['tg_photo_url']) ?>" class="tg-user-ava" alt="аватар" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <span class="tg-user-ava-fallback" style="display:none;"><?= mb_strtoupper(mb_substr($displayName, 0, 1)) ?></span>
            <?php else: ?>
                <span class="tg-user-ava-fallback"><?= mb_strtoupper(mb_substr($displayName, 0, 1)) ?></span>
            <?php endif; ?>
            <span class="tg-user-name"><?= htmlspecialchars($displayName) ?></span>
        </span>
        <?php endif; ?>
    </div>
</header>

<div class="profile-wrap">

<?php if (!$profile): ?>
<div class="not-linked-card">
    <div style="font-size:48px;">🔗</div>
    <h2>Профиль не найден</h2>
    <p>Ты ещё не привязал Telegram к этому сайту. Нажми кнопку «Привязать TG» на главной странице — это займёт 30 секунд.</p>
    <a href="index.php" class="profile-action-btn btn-order" style="text-decoration:none;display:inline-flex;justify-content:center;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        На главную
    </a>
</div>

<?php else: ?>

<?php if ($cancelledId > 0): ?>
<div class="profile-notice info">✅ Заказ #<?= $cancelledId ?> отменён и убран из очереди.</div>
<?php endif; ?>
<?php if ($appealStatus === 'ok'): ?>
<div class="profile-notice ok">✅ Обращение отправлено дизайнеру! Ответ появится в этом разделе.</div>
<?php elseif ($appealStatus === 'err'): ?>
<div class="profile-notice err">❌ Не удалось отправить обращение. Заполни все поля.</div>
<?php endif; ?>

<!-- ── HERO-КАРТОЧКА ── -->
<div class="profile-hero">
    <div class="profile-ava-wrap">
        <?php if (!empty($profile['tg_photo_url'])): ?>
            <img src="<?= htmlspecialchars($profile['tg_photo_url']) ?>" class="profile-ava" alt="аватар" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <div class="profile-ava-fallback" style="display:none;"><?= mb_strtoupper(mb_substr($displayName, 0, 1)) ?></div>
        <?php else: ?>
            <div class="profile-ava-fallback"><?= mb_strtoupper(mb_substr($displayName, 0, 1)) ?></div>
        <?php endif; ?>
        <div class="profile-tg-badge">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        </div>
    </div>
    <div class="profile-info">
        <div class="profile-name">
            <?= htmlspecialchars($displayName) ?>
            <?php if ($isAdmin): ?><span style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:0.5px;color:#fb923c;background:rgba(249,115,22,0.15);border:1px solid rgba(249,115,22,0.35);border-radius:5px;padding:2px 7px;vertical-align:middle;margin-left:6px;">admin</span><?php endif; ?>
        </div>
        <?php if (!empty($profile['tg_username'])): ?>
            <div class="profile-username">@<?= htmlspecialchars($profile['tg_username']) ?></div>
        <?php endif; ?>
        <div class="profile-meta">
            <div class="profile-stat"><div class="profile-stat-num"><?= count($orders) ?></div><div class="profile-stat-label">Всего</div></div>
            <div class="profile-stat"><div class="profile-stat-num" style="color:#60a5fa;"><?= count($activeOrders) ?></div><div class="profile-stat-label">Активных</div></div>
            <div class="profile-stat"><div class="profile-stat-num" style="color:#4ade80;"><?= count(array_filter($orders, fn($o) => $o['status'] === 'ready')) ?></div><div class="profile-stat-label">Готовых</div></div>
        </div>
    </div>
    <div class="profile-actions">
        <a href="index.php" class="profile-action-btn btn-catalog">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            В каталог
        </a>
        <a href="order.php" class="profile-action-btn btn-order">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
            Новый заказ
        </a>
        <a href="https://t.me/kostlimdznbot" target="_blank" class="profile-action-btn btn-bot">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
            Открыть бот
        </a>
    </div>
</div>

<!-- ── АКТИВНЫЕ ЗАКАЗЫ ── -->
<div class="orders-section">
    <div class="orders-section-title"><span>⚡ Активные заказы</span></div>
    <?php if (empty($activeOrders)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">📭</div>
        <p>Нет активных заказов</p>
        <a href="order.php">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
            Оформить заказ
        </a>
    </div>
    <?php else: ?>
        <?php foreach ($activeOrders as $order):
            $color = profileStatusColor($order['status']);
            $label = profileStatusLabel($order['status']);
            $emoji = profileStatusEmoji($order['status']);
            $date  = date('d.m.Y H:i', strtotime($order['created_at']));
            $oid   = (int)$order['id'];
            $isExpanded = ($expandedOrderId === $oid);
            // Обращения по этому заказу
            $orderAppeals = array_filter($userAppeals ?? [], fn($a) => (int)$a['order_id'] === $oid);
        ?>
        <div class="order-card" id="order-<?= $oid ?>">
            <div class="order-card-header" onclick="toggleOrder(<?= $oid ?>)" aria-expanded="<?= $isExpanded ? 'true' : 'false' ?>" id="hdr-<?= $oid ?>">
                <div class="order-card-emoji"><?= $emoji ?></div>
                <div class="order-card-body">
                    <div class="order-card-title">Заказ #<?= $oid ?> — <?= htmlspecialchars($order['service_key']) ?></div>
                    <div class="order-card-meta"><?= $date ?></div>
                    <?php if (!empty($order['details'])): ?>
                    <div class="order-card-details" id="preview-<?= $oid ?>"><?= htmlspecialchars($order['details']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="order-status-badge" style="color:<?= $color ?>;border-color:<?= $color ?>22;background:<?= $color ?>11;">
                    <?= $emoji ?> <?= htmlspecialchars($label) ?>
                </div>
                <svg class="order-expand-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            </div>

            <div class="order-card-expanded <?= $isExpanded ? 'open' : '' ?>" id="exp-<?= $oid ?>">
                <?php if (!empty($order['details'])): ?>
                <div class="order-detail-block"><?= htmlspecialchars($order['details']) ?></div>
                <?php endif; ?>

                <div class="order-actions-row">
                    <!-- Кнопка отмены только если pending или in_progress -->
                    <form method="POST" onsubmit="return confirm('Отменить заказ #<?= $oid ?>? Это действие нельзя отменить.');" style="margin:0;">
                        <input type="hidden" name="order_id" value="<?= $oid ?>">
                        <button type="submit" name="cancel_order" class="btn-cancel-order">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
                            Отменить заказ
                        </button>
                    </form>
                    <button type="button" class="btn-appeal-toggle" onclick="toggleAppeal(<?= $oid ?>)">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        Связаться с дизайнером
                    </button>
                </div>

                <!-- Форма обращения -->
                <div class="appeal-form-wrap" id="appeal-form-<?= $oid ?>">
                    <form method="POST">
                        <input type="hidden" name="appeal_order_id" value="<?= $oid ?>">
                        <label>Тема обращения</label>
                        <input type="text" name="appeal_subject" required placeholder="Например: уточнение по заказу" maxlength="200">
                        <label>Сообщение</label>
                        <textarea name="appeal_message" required rows="4" placeholder="Опиши вопрос или пожелание подробно..."></textarea>
                        <button type="submit" name="send_appeal" class="btn-appeal-submit">
                            📤 Отправить обращение
                        </button>
                    </form>
                </div>

                <!-- Ответы на обращения по этому заказу -->
                <?php if (!empty($orderAppeals)): ?>
                <div style="margin-top:14px;">
                    <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#8a8a96;margin-bottom:8px;">💬 Обращения по заказу</div>
                        <?php foreach ($orderAppeals as $ap): ?>
                            <div style="margin-bottom:10px;border-radius:8px;padding:10px;background:#0b0b0f;">
                                <div style="font-size:13px;font-weight:800;margin-bottom:6px;">📩 <?= htmlspecialchars($ap['subject'] ?? '') ?></div>
                                <?php
                                    $mstmt = $pdo->prepare("SELECT author, message, created_at FROM appeals_messages WHERE appeal_id = ? ORDER BY id ASC");
                                    $mstmt->execute([(int)$ap['id']]);
                                    $msgs = $mstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                                ?>
                                <?php if (empty($msgs)): ?>
                                    <div style="color:#8a8a96;">Сообщений пока нет.</div>
                                <?php else: ?>
                                    <?php foreach ($msgs as $m): ?>
                                        <?php if (($m['author'] ?? '') === 'admin'): ?>
                                            <div style="background:rgba(34,197,94,.06);padding:8px;border-radius:6px;color:#d8d8e8;margin-bottom:6px;"><strong>Ответ администратора</strong> · <?= date('d.m.Y H:i', strtotime($m['created_at'])) ?><div style="margin-top:6px;white-space:pre-wrap;"><?= nl2br(htmlspecialchars($m['message'])) ?></div></div>
                                        <?php else: ?>
                                            <div style="background:rgba(249,115,22,.04);padding:8px;border-radius:6px;color:#d8d8e8;margin-bottom:6px;"><strong>Сообщение клиента</strong> · <?= date('d.m.Y H:i', strtotime($m['created_at'])) ?><div style="margin-top:6px;white-space:pre-wrap;"><?= nl2br(htmlspecialchars($m['message'])) ?></div></div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ── ЗАВЕРШЁННЫЕ ЗАКАЗЫ ── -->
<?php if (!empty($finishedOrders)): ?>
<div class="orders-section">
    <div class="orders-section-title"><span>📁 История</span></div>
    <?php foreach ($finishedOrders as $order):
        $color = profileStatusColor($order['status']);
        $label = profileStatusLabel($order['status']);
        $emoji = profileStatusEmoji($order['status']);
        $date  = date('d.m.Y', strtotime($order['created_at']));
        $oid   = (int)$order['id'];
        $isExpanded = ($expandedOrderId === $oid);
        $orderAppeals = array_filter($userAppeals ?? [], fn($a) => (int)$a['order_id'] === $oid);
    ?>
    <div class="order-card" id="order-<?= $oid ?>" style="opacity:.7;">
        <div class="order-card-header" onclick="toggleOrder(<?= $oid ?>)" aria-expanded="<?= $isExpanded ? 'true' : 'false' ?>" id="hdr-<?= $oid ?>">
            <div class="order-card-emoji"><?= $emoji ?></div>
            <div class="order-card-body">
                <div class="order-card-title">Заказ #<?= $oid ?> — <?= htmlspecialchars($order['service_key']) ?></div>
                <div class="order-card-meta"><?= $date ?></div>
                <?php if (!empty($order['details'])): ?>
                <div class="order-card-details"><?= htmlspecialchars(mb_substr($order['details'], 0, 100)) ?></div>
                <?php endif; ?>
            </div>
            <div class="order-status-badge" style="color:<?= $color ?>;border-color:<?= $color ?>22;background:<?= $color ?>11;">
                <?= $emoji ?> <?= htmlspecialchars($label) ?>
            </div>
            <svg class="order-expand-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
        </div>
        <div class="order-card-expanded <?= $isExpanded ? 'open' : '' ?>" id="exp-<?= $oid ?>">
            <?php if (!empty($order['details'])): ?>
            <div class="order-detail-block"><?= htmlspecialchars($order['details']) ?></div>
            <?php endif; ?>
            <div class="order-actions-row">
                <button type="button" class="btn-appeal-toggle" onclick="toggleAppeal(<?= $oid ?>)">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    Написать дизайнеру
                </button>
            </div>
            <div class="appeal-form-wrap" id="appeal-form-<?= $oid ?>">
                <form method="POST">
                    <input type="hidden" name="appeal_order_id" value="<?= $oid ?>">
                    <label>Тема</label>
                    <input type="text" name="appeal_subject" required placeholder="Тема обращения" maxlength="200">
                    <label>Сообщение</label>
                    <textarea name="appeal_message" required rows="3" placeholder="Твой вопрос..."></textarea>
                    <button type="submit" name="send_appeal" class="btn-appeal-submit">📤 Отправить</button>
                </form>
            </div>

            <?php if (!empty($orderAppeals)): ?>
            <div style="margin-top:14px;">
                <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#8a8a96;margin-bottom:8px;">💬 Обращения</div>
                <?php foreach ($orderAppeals as $ap): ?>
                    <div style="margin-bottom:10px;border-radius:8px;padding:10px;background:#0b0b0f;">
                        <div style="font-size:13px;font-weight:800;margin-bottom:6px;">📩 <?= htmlspecialchars($ap['subject'] ?? '') ?></div>
                        <?php
                            $mstmt = $pdo->prepare("SELECT author, message, created_at FROM appeals_messages WHERE appeal_id = ? ORDER BY id ASC");
                            $mstmt->execute([(int)$ap['id']]);
                            $msgs = $mstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        ?>
                        <?php if (empty($msgs)): ?>
                            <div style="color:#8a8a96;">Сообщений пока нет.</div>
                        <?php else: ?>
                            <?php foreach ($msgs as $m): ?>
                                <?php if (($m['author'] ?? '') === 'admin'): ?>
                                    <div style="background:rgba(34,197,94,.06);padding:8px;border-radius:6px;color:#d8d8e8;margin-bottom:6px;"><strong>Ответ администратора</strong> · <?= date('d.m.Y H:i', strtotime($m['created_at'])) ?><div style="margin-top:6px;white-space:pre-wrap;"><?= nl2br(htmlspecialchars($m['message'])) ?></div></div>
                                <?php else: ?>
                                    <div style="background:rgba(249,115,22,.04);padding:8px;border-radius:6px;color:#d8d8e8;margin-bottom:6px;"><strong>Сообщение клиента</strong> · <?= date('d.m.Y H:i', strtotime($m['created_at'])) ?><div style="margin-top:6px;white-space:pre-wrap;"><?= nl2br(htmlspecialchars($m['message'])) ?></div></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>

</div>

<footer><div class="container">© <?= date('Y') ?> Kostlim Design</div></footer>

<style>
.tg-user-chip { display:inline-flex;align-items:center;gap:7px;padding:5px 12px 5px 5px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:30px;text-decoration:none;color:#86efac;font-size:12px;font-weight:700; }
.tg-user-ava { width:26px;height:26px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1.5px solid rgba(34,197,94,0.4); }
.tg-user-ava-fallback { width:26px;height:26px;border-radius:50%;background:rgba(34,197,94,0.2);border:1.5px solid rgba(34,197,94,0.4);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;color:#86efac;flex-shrink:0; }
.tg-user-name { overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:120px; }
</style>

<script>
function toggleOrder(id) {
    const hdr = document.getElementById('hdr-' + id);
    const exp = document.getElementById('exp-' + id);
    const isOpen = exp.classList.contains('open');
    exp.classList.toggle('open', !isOpen);
    hdr.setAttribute('aria-expanded', !isOpen ? 'true' : 'false');
}

function toggleAppeal(id) {
    const form = document.getElementById('appeal-form-' + id);
    form.classList.toggle('open');
}

// Авто-раскрыть заказ если в URL есть ?order=ID
(function() {
    const params = new URLSearchParams(location.search);
    const oid = params.get('order');
    if (oid) {
        const exp = document.getElementById('exp-' + oid);
        const hdr = document.getElementById('hdr-' + oid);
        if (exp) { exp.classList.add('open'); }
        if (hdr) { hdr.setAttribute('aria-expanded', 'true'); }
        // Скролл к заказу
        const card = document.getElementById('order-' + oid);
        if (card) setTimeout(() => card.scrollIntoView({ behavior: 'smooth', block: 'center' }), 150);

        // Если только что отправили обращение — открыть форму
        const appeal = params.get('appeal');
        if (appeal === 'err') {
            const form = document.getElementById('appeal-form-' + oid);
            if (form) form.classList.add('open');
        }
    }
})();
</script>
</body>
</html>