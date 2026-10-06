<?php
/**
 * Админка → «Приват Пак»: пользователи сайта/бота (ник, тег, аватар, ID, доступ),
 * покупки (одобрение), чат пака и настройки (chat_id, цены, вебхук), ручные выдачи и ключи.
 * Оформление и боковое меню — те же, что у admin/index.php (assets/admin-lite.css + KUI-оболочка).
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/auth.php'; // существующая проверка авторизации админа в проекте
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/order_flow.php';
require_once __DIR__ . '/../includes/badges.php';
require_once __DIR__ . '/../includes/pack_role.php';
require_once __DIR__ . '/../includes/ppk_purchase.php';
require_once __DIR__ . '/../includes/kui_cache.php';

ensurePpkManualSchema($pdo);
ensurePackRoleSchema($pdo);
ensurePpkPurchaseSchema($pdo);

const PPK_DEFAULT_INVITE = 'https://t.me/+7Gzs4aGinj5mY2Qy';

function pmH($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$adminTgId = (string)(getenv('ADMIN_ID') ?: '1710365896');
$token = ppkBotToken($pdo);
$message = '';
$msgType = '';
$sec = '';   // какую вкладку страницы открыть после действия

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'grant') {
        $sec = (string)($_POST['sec'] ?? 'access');
        $tgId = trim((string)($_POST['tg_id'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        if ($tgId !== '') {
            grantManualPpk($pdo, $tgId, $adminTgId, $note !== '' ? $note : 'Выдано из админки');
            $message = "✅ Доступ выдан: " . $tgId; $msgType = 'success';
        }
    } elseif ($action === 'revoke') {
        $sec = (string)($_POST['sec'] ?? 'access');
        $tgId = trim((string)($_POST['tg_id'] ?? ''));
        if ($tgId !== '') {
            revokeManualPpk($pdo, $tgId);
            $message = "✅ Ручной доступ снят у " . $tgId . ". Если человек всё ещё в приватном чате, доступ по членству останется."; $msgType = 'success';
        }
    } elseif ($action === 'generate_keys') {
        $sec = 'access';
        $count = max(1, min(20, (int)($_POST['count'] ?? 1)));
        $codes = generatePpkKeys($pdo, $count);
        $message = '✅ Сгенерировано ключей: ' . count($codes) . ' — ' . implode('  ', $codes); $msgType = 'success';

    } elseif ($action === 'save_settings') {
        $sec = 'chat';
        $chat = trim((string)($_POST['PRIVATE_CHAT_ID'] ?? ''));
        $link = trim((string)($_POST['PRIVATE_CHAT_INVITE_LINK'] ?? ''));
        if ($chat !== '' && !preg_match('/^-?\d{5,}$/', $chat)) {
            $message = '❌ chat_id должен быть числом вида -1001234567890, а не ссылкой. Напиши /id прямо в группе — бот ответит нужным числом.'; $msgType = 'error';
        } else {
            ppkSetSetting($pdo, 'PRIVATE_CHAT_ID', $chat);
            ppkSetSetting($pdo, 'PRIVATE_CHAT_INVITE_LINK', $link);
            foreach (['UAH', 'RUB', 'USD'] as $c) {
                $v = trim(str_replace(',', '.', (string)($_POST['PPK_PRICE_' . $c] ?? '')));
                ppkSetSetting($pdo, 'PPK_PRICE_' . $c, is_numeric($v) && (float)$v > 0 ? $v : '');
            }
            $message = '✅ Настройки сохранены.'; $msgType = 'success';
        }

    } elseif ($action === 'set_webhook') {
        $sec = 'chat';
        $info = ppkTg($token, 'getWebhookInfo');
        $url = (string)($info['result']['url'] ?? '');
        if ($url === '') { $url = ppkSiteUrl() . '/bot.php'; }
        $r = ppkTg($token, 'setWebhook', [
            'url' => $url,
            'allowed_updates' => json_encode(['message', 'edited_message', 'callback_query', 'chat_member', 'my_chat_member', 'channel_post', 'inline_query', 'pre_checkout_query']),
        ]);
        if (!empty($r['ok'])) { $message = '✅ Вебхук обновлён (' . $url . '). Бот теперь получает события входа и выхода из чата.'; $msgType = 'success'; }
        else { $message = '❌ Не вышло: ' . ($r['description'] ?? 'ошибка Telegram'); $msgType = 'error'; }

    } elseif ($action === 'check_user') {
        $sec = 'chat';
        $uid = trim((string)($_POST['tg_id'] ?? ''));
        $chat = ppkChatId($pdo);
        if ($uid === '') { $message = '❌ Укажи Telegram ID.'; $msgType = 'error'; }
        else {
            $r = ppkTg($token, 'getChatMember', ['chat_id' => $chat, 'user_id' => $uid]);
            if (!empty($r['ok'])) {
                $m = (array)$r['result'];
                $in = packStatusIsMember($m);
                packMemberUpsert($pdo, $uid, $in, (array)($m['user'] ?? []), (string)($m['status'] ?? ''), 'admin_check');
                $message = ($in ? '✅' : '❌') . " {$uid}: статус «" . ($m['status'] ?? '?') . "» — " . ($in ? 'в чате, доступ есть' : 'не в чате');
                $msgType = $in ? 'success' : 'error';
            } else {
                $message = '❌ Telegram: ' . ($r['description'] ?? 'ошибка') . ' (бот должен быть админом группы)'; $msgType = 'error';
            }
        }

    } elseif ($action === 'resync_members') {
        $sec = 'chat';
        $chat = ppkChatId($pdo);
        $n = 0; $left = 0;
        $rows = $pdo->query("SELECT tg_id FROM pack_members ORDER BY updated_at ASC LIMIT 60")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $uid) {
            $r = ppkTg($token, 'getChatMember', ['chat_id' => $chat, 'user_id' => $uid]);
            if (empty($r['ok'])) continue;
            $m = (array)$r['result'];
            $is = packStatusIsMember($m);
            packMemberUpsert($pdo, (string)$uid, $is, (array)($m['user'] ?? []), (string)($m['status'] ?? ''), 'resync');
            $n++; if (!$is) $left++;
        }
        $message = "✅ Перепроверено: {$n}, из них вышли или удалены: {$left}."; $msgType = 'success';

    } elseif ($action === 'scan_chat') {
        $sec = 'users';
        $scan = ppkScanChat($pdo);
        if (!$scan['ok']) { $message = '❌ ' . $scan['error']; $msgType = 'error'; }
        else {
            $message = "✅ Проверено людей: {$scan['checked']} (в чате: {$scan['in_chat']}, не в чате: {$scan['left']}"
                     . ($scan['errors'] ? ", ошибок: {$scan['errors']}" : '') . "). По данным Telegram в чате {$scan['tg_count']}, бот определил {$scan['known']}."
                     . ($scan['remaining'] > 0 ? " Ещё не проверено: {$scan['remaining']} — нажми кнопку ещё раз." : '');
            $msgType = 'success';
        }
    } elseif ($action === 'ask_checkin') {
        $sec = 'users';
        $r = ppkAskCheckin($pdo);
        $message = $r['ok'] ? '✅ Сообщение с кнопкой «Я в чате» отправлено в приватную группу. Как только участники нажмут её, они появятся в списке.'
                            : '❌ Не удалось отправить в группу: ' . ($r['error'] ?: 'ошибка Telegram') . ' (бот должен быть в группе и иметь право писать)';
        $msgType = $r['ok'] ? 'success' : 'error';
    } elseif ($action === 'approve_purchase') {
        $sec = 'purchases';
        $res = ppkApprove($pdo, (int)($_POST['id'] ?? 0));
        $message = ($res['ok'] ? '✅ ' : '❌ ') . str_replace("\n", ' · ', $res['text']); $msgType = $res['ok'] ? 'success' : 'error';
    } elseif ($action === 'reject_purchase') {
        $sec = 'purchases';
        $res = ppkReject($pdo, (int)($_POST['id'] ?? 0));
        $message = $res['text']; $msgType = $res['ok'] ? 'success' : 'error';
    }
  } catch (Throwable $e) {
    error_log('ppk_manager POST error: ' . $e->getMessage());
    $message = '❌ Ошибка: ' . $e->getMessage(); $msgType = 'error';
  }
}

kuiCacheForget('settings_all');
$settings = [
    'chat' => ppkSiteSetting($pdo, 'PRIVATE_CHAT_ID'),
    'link' => ppkSiteSetting($pdo, 'PRIVATE_CHAT_INVITE_LINK'),
    'UAH'  => ppkSiteSetting($pdo, 'PPK_PRICE_UAH'),
    'RUB'  => ppkSiteSetting($pdo, 'PPK_PRICE_RUB'),
    'USD'  => ppkSiteSetting($pdo, 'PPK_PRICE_USD'),
];

/** Безопасный SELECT: если таблицы/колонки нет — пустой список, а не белый экран. */
function pmRows(PDO $pdo, string $sql): array
{
    try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { error_log('ppk_manager query: ' . $e->getMessage()); return []; }
}

$grants    = pmRows($pdo, "SELECT * FROM ppk_manual_grants ORDER BY granted_at DESC");
$keys      = pmRows($pdo, "SELECT * FROM ppk_activation_keys ORDER BY created_at DESC LIMIT 40");
$purchases = pmRows($pdo, "SELECT * FROM ppk_purchases ORDER BY id DESC LIMIT 40");
$members   = pmRows($pdo, "SELECT * FROM pack_members WHERE is_member = TRUE ORDER BY updated_at DESC LIMIT 200");
$linkRows  = pmRows($pdo, "SELECT DISTINCT ON (tg_id) tg_id, tg_username, tg_first_name, tg_photo_url, created_at
                           FROM tg_links WHERE linked = TRUE AND tg_id IS NOT NULL AND tg_id <> '' ORDER BY tg_id, id DESC");
$allPackMembers = pmRows($pdo, "SELECT * FROM pack_members");
$cacheIn   = pmRows($pdo, "SELECT tg_id FROM pack_membership_cache WHERE is_member = TRUE");

// ── собираем единый список людей: сайт (tg_links) + всё, что знает бот о чате ──
$grantMap = []; foreach ($grants as $g) { $grantMap[(string)$g['tg_id']] = $g; }
$inChat = []; foreach ($cacheIn as $r) { $inChat[(string)$r['tg_id']] = true; }
foreach ($allPackMembers as $r) { if (!empty($r['is_member'])) $inChat[(string)$r['tg_id']] = true; else unset($inChat[(string)$r['tg_id']]); }

$users = [];
foreach ($linkRows as $r) {
    $id = (string)$r['tg_id'];
    $users[$id] = ['id' => $id, 'name' => trim((string)$r['tg_first_name']), 'username' => ltrim((string)$r['tg_username'], '@'),
                   'photo' => (string)$r['tg_photo_url'], 'seen' => (string)$r['created_at'], 'site' => true];
}
foreach ($allPackMembers as $r) {
    $id = (string)$r['tg_id'];
    if (isset($users[$id])) {
        if ($users[$id]['name'] === '')     $users[$id]['name'] = trim((string)$r['first_name']);
        if ($users[$id]['username'] === '') $users[$id]['username'] = ltrim((string)$r['username'], '@');
    } else {
        $users[$id] = ['id' => $id, 'name' => trim((string)$r['first_name']), 'username' => ltrim((string)$r['username'], '@'),
                       'photo' => '', 'seen' => (string)$r['updated_at'], 'site' => false];
    }
}
foreach ($grantMap as $id => $g) { // выдан доступ, а профиля нет ни на сайте, ни в чате
    $id = (string)$id; // числовые ключи массива PHP превращает в int — приводим обратно
    if (!isset($users[$id])) $users[$id] = ['id' => $id, 'name' => '', 'username' => '', 'photo' => '', 'seen' => (string)$g['granted_at'], 'site' => false];
}
foreach ($users as $id => &$u) {
    $id = (string)$id; // ключ-число становится int, а сравниваем со строкой
    $tags = [];
    if ($id === $adminTgId) $tags['admin'] = 'ADMIN';
    if (isset($grantMap[$id])) {
        $g = $grantMap[$id];
        if (strpos((string)$g['granted_by'], 'key:') === 0) $tags['key'] = '🔑 ключ';
        elseif (strpos((string)$g['note'], 'Покупка') === 0)  $tags['buy'] = '🛒 покупка';
        else                                                  $tags['hand'] = '✋ вручную';
    }
    if (isset($inChat[$id])) $tags['chat'] = '💬 в чате';
    $u['tags'] = $tags;
    $u['access'] = !empty($tags);
    $u['manual'] = isset($grantMap[$id]);
}
unset($u);
uasort($users, fn($a, $b) => strcmp($b['seen'], $a['seen']));
$users = array_slice($users, 0, 1500, true);

// В списке — только люди пака: в приватном чате, с ключом/покупкой/ручной выдачей или админ
$users = array_filter($users, fn($u) => $u['access']);
foreach ($users as &$__u) { $__u['state'] = $__u['site'] ? 'ok' : 'wait'; }
unset($__u);
$lastScan = json_decode(ppkSiteSetting($pdo, 'PPK_LAST_SCAN') ?: '[]', true) ?: [];
$tgCount = (int)($lastScan['tg_count'] ?? 0);
$waitCount = count(array_filter($users, fn($u) => $u['state'] === 'wait'));
$withAccess = count(array_filter($users, fn($u) => $u['access']));
$pendingBuys = count(array_filter($purchases, fn($p) => in_array($p['status'], ['claimed', 'paid'], true)));
$freeKeys = count(array_filter($keys, fn($k) => empty($k['is_used'])));

if ($sec === '') { $sec = $pendingBuys > 0 ? 'purchases' : 'users'; }

$stLabel = ['created' => ['🆕 создана', ''], 'claimed' => ['⏳ ждёт проверки', 'pending'], 'paid' => ['💰 оплачена', 'in_progress'], 'approved' => ['✅ одобрена', 'ready'], 'rejected' => ['❌ отклонена', 'declined']];

/** Иконки бокового меню — те же, что в admin/index.php. */
$ic = function (string $inner): string { return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' . $inner . '</svg>'; };
$nav = [
    ['overview',   'Обзор',        'index.php?tab=overview',   '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>', 'Контент и цены'],
    ['portfolio',  'Портфолио',    'index.php?tab=portfolio',  '<rect x="2" y="7" width="20" height="15" rx="2"/><path d="M16 2l-4 5-4-5"/>', null],
    ['categories', 'Категории',    'index.php?tab=categories', '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>', null],
    ['price',      'Прайс',        'index.php?tab=price',      '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>', null],
    ['promo',      'Промокоды',    'index.php?tab=promo',      '<path d="M20.59 13.41L11 22a2 2 0 0 1-2.83 0l-6.17-6.17a2 2 0 0 1 0-2.83L11.59 3.41A2 2 0 0 1 13 2.83L20.59 13.41z"/><circle cx="7.5" cy="7.5" r="1.5"/>', null],
    ['orders',     'Заказы',       'index.php?tab=orders',     '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>', 'Клиенты'],
    ['appeals',    'Обращения',    'index.php?tab=appeals',    '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>', null],
    ['reviews',    'Отзывы',       'index.php?tab=reviews',    '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>', null],
    ['commands',   'Команды',      'index.php?tab=commands',   '<polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>', 'Бот и сайт'],
    ['rules',      'Правила',      'index.php?tab=rules',      '<path d="M21 21H3V3h18v18zm-3-10H6"/>', null],
    ['ai-prompt',  'ИИ-промпт',    'index.php?tab=ai-prompt',  '<rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/>', null],
    ['resources',  'Ресурсы пака', 'resources.php',            '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>', null],
    ['ppk',        'Приват Пак',   'ppk_manager.php',          '<path d="M12 2l8 3v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5z"/><path d="M9 12l2 2 4-4"/>', null],
    ['keys',       'Ключи и API',  'index.php?tab=keys',       '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>', 'Система'],
    ['logs',       'Логи',         'index.php?tab=logs',       '<path d="M12 9v4M12 17h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>', null],
];
$kuiAdminTitle = 'Приват Пак';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Приват Пак | Kostlim Admin</title>
    <link rel="icon" type="image/png" href="/assets/notify/fav.png" sizes="16x16">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../assets/admin-lite.css?v=<?= @filemtime(__DIR__ . '/../assets/admin-lite.css') ?: time() ?>">
    <style>
        .pm-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px; }
        .pm-tab { display:inline-flex; align-items:center; gap:8px; padding:10px 16px; border-radius:999px; background:#111116; border:1px solid #242432;
            color:#d8d8e8; font:800 13px Montserrat,sans-serif; cursor:pointer; transition:.2s; }
        .pm-tab:hover { border-color:#f97316; background:rgba(249,115,22,.08); }
        .pm-tab.on { background:linear-gradient(135deg,#f97316,#ea580c); border-color:transparent; color:#fff; box-shadow:0 10px 24px rgba(249,115,22,.28); }
        .pm-tab b { background:#f97316; color:#fff; border-radius:999px; padding:1px 8px; font-size:11px; }
        .pm-tab.on b { background:rgba(0,0,0,.28); }
        .pm-sec { display:none; } .pm-sec.on { display:block; }
        .pm-stats { grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); }

        .pm-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; border:none; border-radius:10px; padding:10px 16px; cursor:pointer;
            background:linear-gradient(135deg,#fb923c,#f97316); color:#fff; font:900 12px Montserrat,sans-serif; letter-spacing:.6px; text-transform:uppercase;
            box-shadow:0 8px 22px rgba(249,115,22,.28); transition:.2s; white-space:nowrap; }
        .pm-btn:hover { transform:translateY(-1px); box-shadow:0 0 26px rgba(249,115,22,.5); }
        .pm-btn.ghost { background:#1e1e2a; border:1px solid #2a2a38; box-shadow:none; color:#d8d8e8; }
        .pm-btn.ghost:hover { border-color:#f97316; color:#fff; }
        .pm-btn.danger { background:rgba(239,68,68,.14); border:1px solid rgba(239,68,68,.4); color:#fca5a5; box-shadow:none; }
        .pm-btn.sm { padding:7px 12px; font-size:11px; border-radius:8px; }
        .pm-row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .pm-row form { margin:0; display:contents; }
        .pm-row > input { flex:1 1 220px; min-width:0; width:auto; }
        .pm-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:12px; }
        .pm-hint { color:#8a8a96; font-size:12.5px; line-height:1.55; margin:10px 0 0; border-left:2px solid #f97316; padding:8px 10px; background:rgba(255,255,255,.03); border-radius:7px; }
        .pm-hint code, .pm-code { background:#1a1a24; border:1px solid #2a2a38; border-radius:6px; padding:1px 7px; font-family:monospace; color:#fdba74; }
        .pm-h3 { font-size:14px; margin:20px 0 10px; color:#d8d8e8; }
        .pm-panel-head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
        .pm-panel-head h2 { margin:0; }

        /* список пользователей */
        .pu-tools { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
        .pu-tools input { flex:1 1 260px; }
        .pu-chips { display:flex; gap:6px; flex-wrap:wrap; }
        .pu-chip { padding:9px 14px; border-radius:999px; border:1px solid #2a2a38; background:#14141c; color:#c8c8d8; font:800 12px Montserrat,sans-serif; cursor:pointer; transition:.2s; }
        .pu-chip.on, .pu-chip:hover { border-color:#f97316; background:rgba(249,115,22,.14); color:#fff; }
        .pu-list { display:flex; flex-direction:column; gap:8px; }
        .pu-row { display:flex; align-items:center; gap:14px; background:#14141c; border:1px solid #23232f; border-radius:14px; padding:12px 16px; transition:.18s; }
        .pu-row:hover { border-color:rgba(249,115,22,.5); background:#17171f; }
        .pu-ava { position:relative; width:46px; height:46px; flex:0 0 46px; border-radius:50%; background:linear-gradient(135deg,#f97316,#7c2d12); display:grid; place-items:center;
            font-weight:900; font-size:18px; color:#fff; overflow:hidden; border:2px solid #f97316; }
        .pu-ava img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
        .pu-main { flex:1 1 220px; min-width:0; }
        .pu-name { font-weight:900; font-size:14px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .pu-tag { color:#fb923c; font-size:12.5px; font-weight:700; }
        .pu-tag.none { color:#5b5b68; font-weight:600; }
        .pu-id { flex:0 0 auto; font-family:monospace; font-size:12.5px; color:#c8c8d8; background:#1a1a24; border:1px solid #2a2a38; border-radius:8px; padding:6px 10px; cursor:copy; user-select:all; }
        .pu-badges { display:flex; gap:5px; flex-wrap:wrap; flex:1 1 170px; }
        .pu-b { border-radius:999px; padding:4px 10px; font-size:11px; font-weight:800; background:#191924; color:#d8d8e8; }
        .pu-b.admin { background:linear-gradient(135deg,#f97316,#ea580c); color:#fff; }
        .pu-b.key, .pu-b.buy, .pu-b.hand { background:rgba(249,115,22,.16); color:#fdba74; }
        .pu-b.chat { background:rgba(59,130,246,.16); color:#93c5fd; }
        .pu-b.none { background:#14141c; color:#6b6b78; border:1px solid #23232f; }
        .pu-act { flex:0 0 auto; display:flex; gap:6px; }
        .pu-act form { margin:0; }
        .pu-b.okk { background:rgba(34,197,94,.16); color:#86efac; }
        .pu-b.wait { background:rgba(251,191,36,.14); color:#fbbf24; cursor:help; }
        .pu-scan { background:#14141c; border:1px solid #23232f; border-radius:12px; padding:12px 14px; font-size:13px; color:#c8c8d8; line-height:1.6; margin-bottom:14px; }
        .pu-empty { text-align:center; color:#8a8a96; padding:30px 0; }
        @media (max-width:760px) {
            .pu-row { flex-wrap:wrap; gap:10px 12px; }
            .pu-main { flex:1 1 calc(100% - 70px); }
            .pu-badges, .pu-id { flex:1 1 auto; }
        }
        .adm-link-wrap { display:flex; gap:8px; flex-wrap:wrap; }
        table { min-width:640px; }
    </style>
    <?php include __DIR__ . '/../includes/ui_head.php'; ?>
</head>
<body class="kui kui-admin">
<?php include __DIR__ . '/../includes/ui_admin_shell.php'; ?>

<main class="admin-shell">
    <div class="admin-top">
        <div class="admin-title">
            <h1>🛡 Приват Пак</h1>
            <p>Пользователи, доступ, покупки и приватный Telegram-чат пака.</p>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice <?= pmH($msgType) ?>"><?= pmH($message) ?></div>
    <?php endif; ?>

    <div class="admin-board">
        <nav class="admin-tabs" aria-label="Разделы">
            <?php foreach ($nav as [$key, $label, $href, $icon, $group]): ?>
                <?php if ($group): ?><div class="admin-tab-group-label"><?= pmH($group) ?></div><?php endif; ?>
                <a href="<?= pmH($href) ?>" class="admin-tab<?= $key === 'ppk' ? ' active' : '' ?>" data-tab="<?= pmH($key) ?>" style="text-decoration:none;"><?= $ic($icon) ?> <?= pmH($label) ?><?php if ($key === 'ppk' && $pendingBuys > 0): ?> <span style="background:#fff;color:#ea580c;border-radius:999px;padding:1px 7px;font-size:10px;margin-left:4px;"><?= (int)$pendingBuys ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="admin-content">
            <section class="stats-grid pm-stats">
                <div class="stat-card"><span>Участников пака</span><strong><?= count($users) ?></strong></div>
                <div class="stat-card accent"><span>Доступ есть</span><strong><?= count($users) - $waitCount ?></strong></div>
                <div class="stat-card warn"><span>Не входили на сайт</span><strong><?= $waitCount ?></strong></div>
                <div class="stat-card"><span>В чате по Telegram</span><strong><?= $tgCount ?: '—' ?></strong></div>
                <div class="stat-card"><span>Покупок ждут</span><strong><?= $pendingBuys ?></strong></div>
            </section>

            <div class="pm-tabs" id="pmTabs">
                <button type="button" class="pm-tab" data-s="users">👥 Пользователи</button>
                <button type="button" class="pm-tab" data-s="purchases">🛒 Покупки<?php if ($pendingBuys): ?> <b><?= $pendingBuys ?></b><?php endif; ?></button>
                <button type="button" class="pm-tab" data-s="chat">💬 Чат и настройки</button>
                <button type="button" class="pm-tab" data-s="access">🔑 Доступ и ключи</button>
            </div>

            <!-- ═════ Пользователи (участники пака) ═════ -->
            <section class="panel pm-sec" data-sec="users">
                <div class="pm-panel-head">
                    <h2>👥 Участники пака (<?= count($users) ?>)</h2>
                    <div class="pm-row">
                        <form method="post"><input type="hidden" name="action" value="scan_chat"><button type="submit" class="pm-btn" id="scanBtn">🔍 Просканировать чат</button></form>
                        <form method="post" onsubmit="return confirm('Отправить в приватную группу сообщение с кнопкой «Я в чате»?')"><input type="hidden" name="action" value="ask_checkin"><button type="submit" class="pm-btn ghost">📣 Попросить отметиться</button></form>
                    </div>
                </div>

                <div class="pu-scan">
                    <?php if ($lastScan): ?>
                        <b>Последнее сканирование:</b> <?= pmH(date('d.m H:i', (int)($lastScan['t'] ?? time()))) ?> ·
                        Telegram видит в чате: <b><?= (int)$tgCount ?></b> ·
                        бот определил: <b><?= (int)($lastScan['known'] ?? 0) ?></b>
                        <?php $gap = max(0, $tgCount - (int)($lastScan['known'] ?? 0)); if ($gap > 0): ?>
                            · <span style="color:#fdba74">не определены: <b><?= $gap ?></b></span> (в это число входят боты)
                        <?php else: ?> · <span style="color:#86efac">все определены ✅</span><?php endif; ?>
                    <?php else: ?>
                        Список пока не собирался. Нажми <b>«Просканировать чат»</b>: бот спросит у Telegram про каждого известного человека, в чате он или нет.
                    <?php endif; ?>
                </div>

                <div class="pu-tools">
                    <input type="text" id="puSearch" placeholder="Поиск: ник, @тег или ID" autocomplete="off">
                    <div class="pu-chips" id="puChips">
                        <button type="button" class="pu-chip on" data-f="all">Все</button>
                        <button type="button" class="pu-chip" data-f="ok">✅ Доступ есть</button>
                        <button type="button" class="pu-chip" data-f="wait">⏳ Не входили на сайт</button>
                        <button type="button" class="pu-chip" data-f="chat">💬 В чате</button>
                    </div>
                </div>
                <div class="pu-list" id="puList">
                <?php foreach ($users as $u):
                    $letter = mb_strtoupper(mb_substr($u['name'] !== '' ? $u['name'] : ($u['username'] !== '' ? $u['username'] : '?'), 0, 1));
                    $hay = mb_strtolower($u['name'] . ' ' . $u['username'] . ' ' . $u['id']);
                ?>
                    <div class="pu-row" data-hay="<?= pmH($hay) ?>" data-state="<?= pmH($u['state']) ?>" data-chat="<?= isset($u['tags']['chat']) ? '1' : '0' ?>">
                        <div class="pu-ava"><?= pmH($letter) ?><?php if ($u['photo'] !== ''): ?><img src="<?= pmH($u['photo']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()"><?php endif; ?></div>
                        <div class="pu-main">
                            <div class="pu-name"><?= pmH($u['name'] !== '' ? $u['name'] : 'Без имени') ?></div>
                            <?php if ($u['username'] !== ''): ?><a class="pu-tag" href="https://t.me/<?= pmH($u['username']) ?>" target="_blank" rel="noopener" style="text-decoration:none">@<?= pmH($u['username']) ?></a>
                            <?php else: ?><span class="pu-tag none">тега нет</span><?php endif; ?>
                        </div>
                        <div class="pu-id" title="Нажми, чтобы скопировать" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?= pmH($u['id']) ?>');this.style.borderColor='#22c55e'"><?= pmH($u['id']) ?></div>
                        <div class="pu-badges">
                            <?php foreach ($u['tags'] as $k => $t): ?><span class="pu-b <?= pmH($k) ?>"><?= pmH($t) ?></span><?php endforeach; ?>
                            <?php if ($u['state'] === 'ok'): ?><span class="pu-b okk">✅ доступ есть</span>
                            <?php else: ?><span class="pu-b wait" title="Человек в чате (или ему выдан доступ), но ещё не входил на сайт через Telegram — доступ откроется сам при первом входе">⏳ ещё не входил на сайт</span><?php endif; ?>
                        </div>
                        <div class="pu-act">
                            <?php if ($u['id'] === $adminTgId): ?>
                            <?php elseif ($u['manual']): ?>
                                <form method="post" onsubmit="return confirm('Снять ручной доступ?')"><input type="hidden" name="action" value="revoke"><input type="hidden" name="sec" value="users"><input type="hidden" name="tg_id" value="<?= pmH($u['id']) ?>"><button type="submit" class="pm-btn danger sm">Снять</button></form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
                <div class="pu-empty" id="puEmpty" style="display:none">Никого не нашли.</div>
                <?php if (!$users): ?><div class="pu-empty">Пока никого. Нажми «Просканировать чат» — бот проверит всех, кого знает, или «Попросить отметиться» — участники сами нажмут кнопку в чате.</div><?php endif; ?>

                <p class="pm-hint"><b>Как это работает.</b> Telegram не отдаёт боту полный список участников группы, поэтому «сканирование» проверяет через Telegram каждого человека, которого бот знает (входил на сайт, купил, получил ключ, писал в чат), и показывает число участников по версии Telegram. Кто в чате, но нигде не светился, в список не попадёт, пока не нажмёт кнопку из «Попросить отметиться», не напишет в чат или не зайдёт на сайт. Дальше бот подхватывает входы и выходы сам (после «Подключить события чата» во вкладке «Чат и настройки»).</p>
                <p class="pm-hint">«✅ доступ есть» — человек уже входил на сайт через Telegram, и ему открыт Приват Пак. «⏳ ещё не входил» — он в чате или ему выдан доступ, но на сайте пока не был: доступ откроется сам при первом входе.</p>
            </section>

            <!-- ═════ Покупки ═════ -->
            <section class="panel pm-sec" data-sec="purchases">
                <div class="pm-panel-head"><h2>🛒 Покупки пака</h2></div>
                <div class="admin-table-wrap"><table>
                    <thead><tr><th>#</th><th>Клиент</th><th>Способ</th><th>Сумма</th><th>Статус</th><th>Ссылка в чат</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($purchases as $p): [$stText, $stCls] = $stLabel[$p['status']] ?? [$p['status'], '']; ?>
                        <tr>
                            <td><?= (int)$p['id'] ?></td>
                            <td><b><?= pmH(trim($p['tg_first_name'] . ' ' . ($p['tg_username'] !== '' ? '@' . $p['tg_username'] : ''))) ?></b><br><small style="color:#8a8a96"><?= pmH($p['tg_id']) ?></small></td>
                            <td><?= pmH($p['method'] ?: '—') ?></td>
                            <td><?= $p['amount'] > 0 ? pmH(ppkFormatMoney((float)$p['amount'], (string)$p['currency'])) : '—' ?></td>
                            <td><span class="status <?= pmH($stCls) ?>"><?= pmH($stText) ?></span></td>
                            <td><?= $p['invite_link'] === '' ? '—' : ($p['invite_used'] ? '🔒 использована' : '🟢 ждёт входа') ?></td>
                            <td>
                                <?php if (in_array($p['status'], ['created', 'claimed', 'paid'], true)): ?>
                                <div class="pm-row" style="flex-wrap:nowrap">
                                    <form method="post"><input type="hidden" name="action" value="approve_purchase"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button type="submit" class="pm-btn sm">✅ Одобрить</button></form>
                                    <form method="post" onsubmit="return confirm('Отклонить покупку?')"><input type="hidden" name="action" value="reject_purchase"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button type="submit" class="pm-btn danger sm">✕</button></form>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$purchases): ?><tr><td colspan="7" style="padding:22px;color:#8a8a96">Покупок пока нет.</td></tr><?php endif; ?>
                    </tbody>
                </table></div>
                <p class="pm-hint">Обычно одобряешь кнопкой в Telegram. Здесь то же самое, если пропустил сообщение. После «Одобрить» открывается доступ на сайте, а клиенту уходит одноразовая ссылка в приватный чат.</p>
            </section>

            <!-- ═════ Чат и настройки ═════ -->
            <section class="panel pm-sec" data-sec="chat">
                <div class="pm-panel-head"><h2>⚙️ Настройки пака</h2></div>
                <form method="post">
                    <input type="hidden" name="action" value="save_settings">
                    <div class="pm-grid">
                        <div><label>chat_id приватной группы</label><input type="text" name="PRIVATE_CHAT_ID" value="<?= pmH($settings['chat'] !== '' ? $settings['chat'] : PPK_DEFAULT_CHAT_ID) ?>" placeholder="-1001234567890"></div>
                        <div><label>Ссылка-приглашение (для справки)</label><input type="text" name="PRIVATE_CHAT_INVITE_LINK" value="<?= pmH($settings['link'] !== '' ? $settings['link'] : PPK_DEFAULT_INVITE) ?>"></div>
                        <div><label>Цена, ₴ (Monobank)</label><input type="text" name="PPK_PRICE_UAH" value="<?= pmH($settings['UAH']) ?>" placeholder="например 500"></div>
                        <div><label>Цена, ₽ (DonationAlerts)</label><input type="text" name="PPK_PRICE_RUB" value="<?= pmH($settings['RUB']) ?>" placeholder="например 1200"></div>
                        <div><label>Цена, $ (Crypto Bot)</label><input type="text" name="PPK_PRICE_USD" value="<?= pmH($settings['USD']) ?>" placeholder="например 12"></div>
                    </div>
                    <p class="pm-hint"><b>chat_id</b> — это число, а не ссылка. Бот должен быть админом группы с правами «Приглашать пользователей» и «Блокировать участников». Цена нужна хотя бы в одной валюте: по ней строятся способы оплаты на странице покупки.</p>
                    <div style="margin-top:14px"><button type="submit" class="pm-btn">💾 Сохранить</button></div>
                </form>

                <h3 class="pm-h3">Подключение и проверка</h3>
                <div class="pm-row">
                    <form method="post"><input type="hidden" name="action" value="set_webhook"><button type="submit" class="pm-btn">🔌 Подключить события чата</button></form>
                    <form method="post" style="display:contents"><input type="hidden" name="action" value="check_user">
                        <input type="text" name="tg_id" placeholder="Telegram ID — проверить, в чате ли человек"><button type="submit" class="pm-btn ghost">Проверить</button></form>
                </div>
                <p class="pm-hint">«Подключить события чата» оставляет прежний адрес бота и добавляет подписку на <code>chat_member</code>. Без неё бот не узнаёт сразу, что человек вошёл или вышел. Нажми один раз после деплоя. «Проверить» спрашивает у Telegram статус человека в группе.</p>

                <h3 class="pm-h3">💬 Кого бот видит в чате (<?= count($members) ?>)</h3>
                <form method="post" style="margin-bottom:12px"><input type="hidden" name="action" value="resync_members"><button type="submit" class="pm-btn ghost sm">🔄 Перепроверить список</button></form>
                <div class="admin-table-wrap"><table>
                    <thead><tr><th>TG ID</th><th>Имя</th><th>Ник</th><th>Откуда узнали</th><th>Обновлено</th></tr></thead>
                    <tbody>
                    <?php foreach ($members as $m): ?>
                        <tr><td><span class="pm-code"><?= pmH($m['tg_id']) ?></span></td><td><?= pmH($m['first_name']) ?></td><td><?= $m['username'] !== '' ? '@' . pmH($m['username']) : '—' ?></td><td><?= pmH($m['source']) ?></td><td><?= pmH($m['updated_at']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$members): ?><tr><td colspan="5" style="padding:22px;color:#8a8a96">Пока пусто: список наполняется, когда люди пишут в чат, вступают или заходят на сайт.</td></tr><?php endif; ?>
                    </tbody>
                </table></div>
                <p class="pm-hint">Telegram не отдаёт боту полный список участников группы, поэтому бот запоминает людей по событиям. На доступ это не влияет: когда человек входит на сайт, бот сразу спрашивает Telegram, состоит ли он в чате.</p>
            </section>

            <!-- ═════ Доступ и ключи ═════ -->
            <section class="panel pm-sec" data-sec="access">
                <div class="pm-panel-head"><h2>✋ Выдать доступ вручную</h2></div>
                <form method="post" class="pm-row">
                    <input type="hidden" name="action" value="grant"><input type="hidden" name="sec" value="access">
                    <input type="text" name="tg_id" placeholder="Telegram ID пользователя" required>
                    <input type="text" name="note" placeholder="Заметка (необязательно)">
                    <button type="submit" class="pm-btn">Выдать</button>
                </form>

                <h3 class="pm-h3">Активные ручные выдачи (<?= count($grants) ?>)</h3>
                <div class="admin-table-wrap"><table>
                    <thead><tr><th>TG ID</th><th>Откуда</th><th>Заметка</th><th>Выдано</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($grants as $g):
                        $src = strpos((string)$g['granted_by'], 'key:') === 0 ? '🔑 ключ' : (strpos((string)$g['note'], 'Покупка') === 0 ? '🛒 покупка' : '✋ вручную'); ?>
                        <tr>
                            <td><span class="pm-code"><?= pmH($g['tg_id']) ?></span></td>
                            <td><?= $src ?></td>
                            <td><?= pmH($g['note']) ?></td>
                            <td><?= pmH($g['granted_at']) ?></td>
                            <td><form method="post" onsubmit="return confirm('Снять доступ?')" style="margin:0"><input type="hidden" name="action" value="revoke"><input type="hidden" name="sec" value="access"><input type="hidden" name="tg_id" value="<?= pmH($g['tg_id']) ?>"><button type="submit" class="pm-btn danger sm">Снять</button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$grants): ?><tr><td colspan="5" style="padding:22px;color:#8a8a96">Пока никому не выдавалось.</td></tr><?php endif; ?>
                    </tbody>
                </table></div>

                <h3 class="pm-h3">🔑 Одноразовые ключи активации</h3>
                <form method="post" class="pm-row" style="margin-bottom:14px">
                    <input type="hidden" name="action" value="generate_keys">
                    <input type="number" name="count" value="1" min="1" max="20" style="flex:0 0 90px;width:90px">
                    <button type="submit" class="pm-btn">Сгенерировать</button>
                </form>
                <div class="admin-table-wrap"><table>
                    <thead><tr><th>Код</th><th>Статус</th><th>Кем погашен</th></tr></thead>
                    <tbody>
                    <?php foreach ($keys as $k): ?>
                        <tr>
                            <td><span class="pm-code"><?= pmH($k['code']) ?></span></td>
                            <td><span class="status <?= $k['is_used'] ? 'declined' : 'ready' ?>"><?= $k['is_used'] ? 'использован' : 'свободен' ?></span></td>
                            <td><?= pmH($k['used_by_tg_id'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$keys): ?><tr><td colspan="3" style="padding:22px;color:#8a8a96">Ключей ещё нет.</td></tr><?php endif; ?>
                    </tbody>
                </table></div>
            </section>
        </div>
    </div>
</main>

<script>
(function () {
    var start = <?= json_encode($sec) ?>;
    var tabs = document.querySelectorAll('.pm-tab'), secs = document.querySelectorAll('.pm-sec');
    function show(s) {
        tabs.forEach(function (t) { t.classList.toggle('on', t.dataset.s === s); });
        secs.forEach(function (x) { x.classList.toggle('on', x.dataset.sec === s); });
        try { history.replaceState(null, '', '#' + s); } catch (e) {}
    }
    tabs.forEach(function (t) { t.onclick = function () { show(t.dataset.s); }; });
    var h = (location.hash || '').slice(1);
    show(<?= $message !== '' ? 'start' : "(['users','purchases','chat','access'].indexOf(h) > -1 ? h : start)" ?>);

    var sb = document.getElementById('scanBtn');
    if (sb && sb.form) sb.form.addEventListener('submit', function () { sb.disabled = true; sb.textContent = '⏳ Сканирую… до 30 секунд'; });

    // поиск и фильтры по пользователям
    var q = document.getElementById('puSearch'), chips = document.querySelectorAll('.pu-chip'), rows = document.querySelectorAll('.pu-row'), empty = document.getElementById('puEmpty');
    var f = 'all';
    function apply() {
        var s = (q.value || '').trim().toLowerCase().replace(/^@/, ''), shown = 0;
        rows.forEach(function (r) {
            var ok = (!s || r.dataset.hay.indexOf(s) > -1) &&
                     (f === 'all' || (f === 'ok' && r.dataset.state === 'ok') || (f === 'wait' && r.dataset.state === 'wait') || (f === 'chat' && r.dataset.chat === '1'));
            r.style.display = ok ? '' : 'none'; if (ok) shown++;
        });
        if (empty) empty.style.display = (rows.length && !shown) ? '' : 'none';
    }
    if (q) q.addEventListener('input', apply);
    chips.forEach(function (c) { c.onclick = function () { f = c.dataset.f; chips.forEach(function (x) { x.classList.toggle('on', x === c); }); apply(); }; });
})();
</script>
<script src="/assets/kostlim-admin.js?v=<?= @filemtime(__DIR__ . '/../assets/kostlim-admin.js') ?: time() ?>"></script>
</body>
</html>
