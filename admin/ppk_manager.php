<?php
/**
 * Админка Приват Пака: доступ (ручная выдача, ключи), покупки (одобрение),
 * участники приватного чата (кого знает бот), настройки (chat_id, цена, вебхук).
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/auth.php'; // существующая проверка авторизации админа в проекте
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/order_flow.php';
require_once __DIR__ . '/../includes/badges.php';
require_once __DIR__ . '/../includes/pack_role.php';
require_once __DIR__ . '/../includes/ppk_purchase.php';

ensurePpkManualSchema($pdo);
ensurePackRoleSchema($pdo);
ensurePpkPurchaseSchema($pdo);

const PPK_DEFAULT_INVITE = 'https://t.me/+7Gzs4aGinj5mY2Qy';

function pmH($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$message = '';
$adminTgId = getenv('ADMIN_ID') ?: '1710365896';
$token = ppkBotToken($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'grant') {
        $tgId = trim((string)($_POST['tg_id'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        if ($tgId !== '') {
            grantManualPpk($pdo, $tgId, $adminTgId, $note);
            $message = "✅ PPK выдан пользователю " . pmH($tgId) . ".";
        }
    } elseif ($action === 'revoke') {
        $tgId = trim((string)($_POST['tg_id'] ?? ''));
        if ($tgId !== '') {
            revokeManualPpk($pdo, $tgId);
            $message = "🗑 Ручной доступ снят у " . pmH($tgId) . ". (Если человек всё ещё в приватном чате — доступ по членству останется.)";
        }
    } elseif ($action === 'generate_keys') {
        $count = max(1, min(20, (int)($_POST['count'] ?? 1)));
        $codes = generatePpkKeys($pdo, $count);
        $message = 'Сгенерировано ключей: ' . count($codes) . '<br><code style="white-space:pre-line;display:block;margin-top:8px;">' . pmH(implode("\n", $codes)) . '</code>';

    } elseif ($action === 'save_settings') {
        $chat = trim((string)($_POST['PRIVATE_CHAT_ID'] ?? ''));
        $link = trim((string)($_POST['PRIVATE_CHAT_INVITE_LINK'] ?? ''));
        if ($chat !== '' && !preg_match('/^-?\d{5,}$/', $chat)) {
            $message = '⚠️ PRIVATE_CHAT_ID должен быть числом вида -1001234567890 (а не ссылкой). Напиши /id прямо в группе — бот ответит нужным числом.';
        } else {
            ppkSetSetting($pdo, 'PRIVATE_CHAT_ID', $chat);
            ppkSetSetting($pdo, 'PRIVATE_CHAT_INVITE_LINK', $link);
            foreach (['UAH', 'RUB', 'USD'] as $c) {
                $v = trim(str_replace(',', '.', (string)($_POST['PPK_PRICE_' . $c] ?? '')));
                ppkSetSetting($pdo, 'PPK_PRICE_' . $c, is_numeric($v) && (float)$v > 0 ? $v : '');
            }
            $message = '✅ Настройки сохранены.';
        }

    } elseif ($action === 'set_webhook') {
        // сохраняем текущий адрес вебхука, добавляя подписку на chat_member (иначе бот не узнает о входах/выходах)
        $info = ppkTg($token, 'getWebhookInfo');
        $url = (string)($info['result']['url'] ?? '');
        if ($url === '') { $url = ppkSiteUrl() . '/bot.php'; }
        $r = ppkTg($token, 'setWebhook', [
            'url' => $url,
            'allowed_updates' => json_encode(['message', 'edited_message', 'callback_query', 'chat_member', 'my_chat_member', 'channel_post', 'inline_query', 'pre_checkout_query']),
        ]);
        $message = !empty($r['ok'])
            ? '✅ Вебхук обновлён: ' . pmH($url) . '<br>Бот теперь получает события входа/выхода из чата (chat_member).'
            : '❌ Не вышло: ' . pmH($r['description'] ?? 'ошибка Telegram');

    } elseif ($action === 'check_user') {
        $uid = trim((string)($_POST['tg_id'] ?? ''));
        $chat = ppkChatId($pdo);
        if ($uid === '' || $chat === '') {
            $message = '⚠️ Укажи Telegram ID и сохрани PRIVATE_CHAT_ID.';
        } else {
            $r = ppkTg($token, 'getChatMember', ['chat_id' => $chat, 'user_id' => $uid]);
            if (!empty($r['ok'])) {
                $m = (array)$r['result'];
                packMemberUpsert($pdo, $uid, packStatusIsMember($m), (array)($m['user'] ?? []), (string)($m['status'] ?? ''), 'admin_check');
                $message = 'Статус ' . pmH($uid) . ': <b>' . pmH($m['status'] ?? '?') . '</b> — ' . (packStatusIsMember($m) ? '✅ в чате, доступ есть' : '❌ не в чате');
            } else {
                $message = '❌ Telegram: ' . pmH($r['description'] ?? 'ошибка') . ' (бот должен быть админом группы)';
            }
        }

    } elseif ($action === 'resync_members') {
        $chat = ppkChatId($pdo);
        $n = 0; $left = 0;
        if ($chat !== '') {
            $rows = $pdo->query("SELECT tg_id FROM pack_members ORDER BY updated_at ASC LIMIT 60")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $uid) {
                $r = ppkTg($token, 'getChatMember', ['chat_id' => $chat, 'user_id' => $uid]);
                if (empty($r['ok'])) continue;
                $m = (array)$r['result'];
                $is = packStatusIsMember($m);
                packMemberUpsert($pdo, (string)$uid, $is, (array)($m['user'] ?? []), (string)($m['status'] ?? ''), 'resync');
                $n++; if (!$is) $left++;
            }
        }
        $message = "🔄 Перепроверено: {$n}, из них вышли/удалены: {$left}.";

    } elseif ($action === 'approve_purchase') {
        $res = ppkApprove($pdo, (int)($_POST['id'] ?? 0));
        $message = nl2br(pmH($res['text']));
    } elseif ($action === 'reject_purchase') {
        $res = ppkReject($pdo, (int)($_POST['id'] ?? 0));
        $message = nl2br(pmH($res['text']));
    }
}

$settings = [
    'chat'  => ppkSiteSetting($pdo, 'PRIVATE_CHAT_ID'),
    'link'  => ppkSiteSetting($pdo, 'PRIVATE_CHAT_INVITE_LINK'),
    'UAH'   => ppkSiteSetting($pdo, 'PPK_PRICE_UAH'),
    'RUB'   => ppkSiteSetting($pdo, 'PPK_PRICE_RUB'),
    'USD'   => ppkSiteSetting($pdo, 'PPK_PRICE_USD'),
];
$grants    = $pdo->query("SELECT * FROM ppk_manual_grants ORDER BY granted_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$keys      = $pdo->query("SELECT * FROM ppk_activation_keys ORDER BY created_at DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
$purchases = $pdo->query("SELECT * FROM ppk_purchases ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
$members   = $pdo->query("SELECT * FROM pack_members WHERE is_member = TRUE ORDER BY updated_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$stLabel = ['created' => '🆕 создана', 'claimed' => '⏳ ждёт проверки', 'paid' => '💰 оплачена', 'approved' => '✅ одобрена', 'rejected' => '❌ отклонена'];
$inp = 'padding:9px 11px;border-radius:8px;border:1px solid var(--border);background:rgba(0,0,0,.15);color:var(--text);';
$dangerBtn = 'padding:6px 12px;background:rgba(239,68,68,.15);box-shadow:none;color:#ef4444;';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PPK — управление доступом | Админка</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../assets/admin-theme.css">
    <style>
        .pm-sec { margin-top: 34px; }
        .pm-sec h2 { margin-bottom: 10px; }
        .pm-tbl { width:100%; border-collapse:collapse; font-size:14px; }
        .pm-tbl th { text-align:left; color:var(--text2); font-weight:600; padding:6px 8px 6px 0; }
        .pm-tbl td { padding:8px 8px 8px 0; border-top:1px solid var(--border); vertical-align:middle; }
        .pm-row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        .pm-hint { color:var(--text2); font-size:12.5px; margin:6px 0 0; line-height:1.5; }
        .pm-scroll { overflow-x:auto; }
        .pm-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px; }
        .pm-grid label { display:block; font-size:12px; color:var(--text2); margin-bottom:4px; }
        .pm-grid input { width:100%; box-sizing:border-box; }
    </style>
</head>
<body style="padding:24px;max-width:960px;margin:0 auto;">
    <h1>🎨 Управление Приват Паком</h1>
    <p><a href="index.php">← В админ-панель</a></p>
    <?php if ($message): ?><div class="admin-flash" style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px;margin:16px 0;"><?= $message /* экранировано выше */ ?></div><?php endif; ?>

    <!-- ───── Настройки ───── -->
    <section class="pm-sec">
        <h2>⚙️ Настройки пака</h2>
        <form method="post">
            <input type="hidden" name="action" value="save_settings">
            <div class="pm-grid">
                <div><label>chat_id приватной группы (число)</label><input type="text" name="PRIVATE_CHAT_ID" value="<?= pmH($settings['chat'] !== '' ? $settings['chat'] : PPK_DEFAULT_CHAT_ID) ?>" placeholder="-1001234567890" style="<?= $inp ?>"></div>
                <div><label>Ссылка-приглашение (для справки)</label><input type="text" name="PRIVATE_CHAT_INVITE_LINK" value="<?= pmH($settings['link'] !== '' ? $settings['link'] : PPK_DEFAULT_INVITE) ?>" style="<?= $inp ?>"></div>
                <div><label>Цена, ₴ (Monobank)</label><input type="text" name="PPK_PRICE_UAH" value="<?= pmH($settings['UAH']) ?>" placeholder="например 500" style="<?= $inp ?>"></div>
                <div><label>Цена, ₽ (DonationAlerts)</label><input type="text" name="PPK_PRICE_RUB" value="<?= pmH($settings['RUB']) ?>" placeholder="например 1200" style="<?= $inp ?>"></div>
                <div><label>Цена, $ (Crypto Bot)</label><input type="text" name="PPK_PRICE_USD" value="<?= pmH($settings['USD']) ?>" placeholder="например 12" style="<?= $inp ?>"></div>
            </div>
            <p class="pm-hint">
                <b>chat_id</b> — это НЕ ссылка. Добавь бота в группу <b>админом</b> (права: «Приглашать пользователей» и «Блокировать участников»),
                затем напиши в группе <code>/id</code> — бот ответит числом вида <code>-100…</code>. Для групп с темами пиши /id в любой теме — id группы один.
                Цена нужна хотя бы в одной валюте — по ней строятся способы оплаты на странице покупки.
            </p>
            <button type="submit" class="btn-submit" style="margin-top:12px">Сохранить</button>
        </form>

        <div class="pm-row" style="margin-top:16px">
            <form method="post"><input type="hidden" name="action" value="set_webhook"><button type="submit" class="btn-submit">🔌 Подключить события чата (вебхук)</button></form>
            <form method="post" class="pm-row"><input type="hidden" name="action" value="check_user">
                <input type="text" name="tg_id" placeholder="Telegram ID — проверить в чате" style="<?= $inp ?>">
                <button type="submit" class="btn-submit">Проверить</button></form>
        </div>
        <p class="pm-hint">Кнопка «вебхук» оставляет прежний адрес бота и добавляет подписку на <code>chat_member</code> — без неё бот не узнаёт мгновенно, что человек вошёл или вышел. Нажми один раз после деплоя.</p>
    </section>

    <!-- ───── Покупки ───── -->
    <section class="pm-sec">
        <h2>🛒 Покупки пака</h2>
        <div class="pm-scroll"><table class="pm-tbl">
            <thead><tr><th>#</th><th>Клиент</th><th>Способ</th><th>Сумма</th><th>Статус</th><th>Ссылка</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($purchases as $p): ?>
                <tr>
                    <td><?= (int)$p['id'] ?></td>
                    <td><?= pmH(trim($p['tg_first_name'] . ' ' . ($p['tg_username'] !== '' ? '@' . $p['tg_username'] : ''))) ?><br><small style="color:var(--text2)"><?= pmH($p['tg_id']) ?></small></td>
                    <td><?= pmH($p['method'] ?: '—') ?></td>
                    <td><?= $p['amount'] > 0 ? pmH(ppkFormatMoney((float)$p['amount'], (string)$p['currency'])) : '—' ?></td>
                    <td><?= $stLabel[$p['status']] ?? pmH($p['status']) ?></td>
                    <td><?= $p['invite_link'] === '' ? '—' : ($p['invite_used'] ? '🔒 использована' : '🟢 ждёт входа') ?></td>
                    <td>
                        <?php if (in_array($p['status'], ['claimed', 'paid', 'created'], true)): ?>
                        <div class="pm-row">
                            <form method="post"><input type="hidden" name="action" value="approve_purchase"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button type="submit" class="btn-submit" style="padding:6px 12px">✅ Одобрить</button></form>
                            <form method="post" onsubmit="return confirm('Отклонить покупку?')"><input type="hidden" name="action" value="reject_purchase"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button type="submit" class="btn-submit" style="<?= $dangerBtn ?>">✕</button></form>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$purchases): ?><tr><td colspan="7" style="padding:16px 0;color:var(--text2);">Покупок пока нет.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <p class="pm-hint">Обычно одобряешь кнопкой в Telegram — здесь то же самое, если пропустил сообщение.</p>
    </section>

    <!-- ───── Участники чата ───── -->
    <section class="pm-sec">
        <h2>👥 Кого бот видит в приватном чате (<?= count($members) ?>)</h2>
        <form method="post" style="margin-bottom:10px"><input type="hidden" name="action" value="resync_members"><button type="submit" class="btn-submit">🔄 Перепроверить список</button></form>
        <div class="pm-scroll"><table class="pm-tbl">
            <thead><tr><th>TG ID</th><th>Имя</th><th>Ник</th><th>Откуда узнали</th><th>Обновлено</th></tr></thead>
            <tbody>
            <?php foreach ($members as $m): ?>
                <tr><td><?= pmH($m['tg_id']) ?></td><td><?= pmH($m['first_name']) ?></td><td><?= $m['username'] !== '' ? '@' . pmH($m['username']) : '—' ?></td><td><?= pmH($m['source']) ?></td><td><?= pmH($m['updated_at']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$members): ?><tr><td colspan="5" style="padding:16px 0;color:var(--text2);">Пока пусто — список наполняется, когда люди пишут в чат, вступают, или заходят на сайт.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <p class="pm-hint">Telegram не отдаёт боту полный список участников группы, поэтому бот запоминает людей по событиям. Но доступ это не ограничивает: когда человек входит на сайт, бот сразу спрашивает Telegram, состоит ли он в чате.</p>
    </section>

    <!-- ───── Ручная выдача ───── -->
    <section class="pm-sec">
        <h2>Выдать PPK вручную</h2>
        <form method="post" class="pm-row">
            <input type="hidden" name="action" value="grant">
            <input type="text" name="tg_id" placeholder="Telegram ID пользователя" required style="<?= $inp ?>">
            <input type="text" name="note" placeholder="Заметка (необязательно)" style="<?= $inp ?>flex:1;min-width:180px;">
            <button type="submit" class="btn-submit">Выдать PPK</button>
        </form>
    </section>

    <section class="pm-sec">
        <h2>Активные ручные выдачи</h2>
        <div class="pm-scroll"><table class="pm-tbl">
            <thead><tr><th>TG ID</th><th>Заметка</th><th>Выдано</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($grants as $g): ?>
                <tr>
                    <td><?= pmH($g['tg_id']) ?></td>
                    <td><?= pmH($g['note']) ?></td>
                    <td><?= pmH($g['granted_at']) ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Снять PPK?')">
                            <input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="tg_id" value="<?= pmH($g['tg_id']) ?>">
                            <button type="submit" class="btn-submit" style="<?= $dangerBtn ?>">✕</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$grants): ?><tr><td colspan="4" style="padding:16px 0;color:var(--text2);">Пока никому не выдавалось вручную.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>

    <section class="pm-sec">
        <h2>Одноразовые ключи активации</h2>
        <form method="post" class="pm-row" style="margin-bottom:16px">
            <input type="hidden" name="action" value="generate_keys">
            <input type="number" name="count" value="1" min="1" max="20" style="width:70px;<?= $inp ?>">
            <button type="submit" class="btn-submit">Сгенерировать</button>
        </form>
        <div class="pm-scroll"><table class="pm-tbl">
            <thead><tr><th>Код</th><th>Статус</th><th>Кем погашен</th></tr></thead>
            <tbody>
            <?php foreach ($keys as $k): ?>
                <tr>
                    <td style="font-family:monospace;"><?= pmH($k['code']) ?></td>
                    <td><?= $k['is_used'] ? '✅ использован' : '🟢 свободен' ?></td>
                    <td><?= pmH($k['used_by_tg_id'] ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
</body>
</html>
