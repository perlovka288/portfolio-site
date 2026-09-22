<?php
/**
 * Личный планер клиентов — модуль органайзера для владельцев пака (PPK).
 * Таблица заказов: Клиент / Контакт / Статус / Дедлайн / Сумма / Заметки.
 * Данные приватные для каждого owner_tg_id — планер одного PPK не виден
 * другому (только сам себе и админу через отдельный SQL при необходимости).
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/pack_role.php';
require_once __DIR__ . '/includes/badges.php';

$sid = session_id();
$tgProfile = [];
try {
    $stmt = $pdo->prepare("SELECT tg_id, tg_first_name FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1");
    $stmt->execute([$sid]);
    $tgProfile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$tgId = (string)($tgProfile['tg_id'] ?? '');
$adminTgEnv = getenv('ADMIN_ID') ?: '1710365896';
$isAdmin = (isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true) || ($tgId !== '' && $tgId === $adminTgEnv);

ensurePpkManualSchema($pdo);
$botToken  = getSiteSettingSafe($pdo, 'BOT_TOKEN') ?: (getenv('TELEGRAM_BOT_TOKEN') ?: getenv('BOT_TOKEN') ?: '');
$groupChat = getSiteSettingSafe($pdo, 'PRIVATE_CHAT_ID') ?: (getenv('PRIVATE_CHAT_ID') ?: '');
$isPackDesigner = $isAdmin || hasManualPpkGrant($pdo, $tgId) || ($tgId !== '' && isPackDesigner($pdo, $botToken, $groupChat, $tgId, $isAdmin));

function getSiteSettingSafe(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $stmt = $pdo->prepare("SELECT value FROM site_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false && $val !== null && $val !== '' ? (string)$val : $default;
    } catch (Throwable $e) { return $default; }
}

if (!$isPackDesigner) {
    http_response_code(403);
    ?>
    <!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Доступ закрыт | Kostlim Design</title><link rel="stylesheet" href="style.css"></head>
    <body style="display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center;padding:24px;">
        <div><h1>🔒 Доступ закрыт</h1><p>Личный планер клиентов доступен только владельцам PPK.</p><p><a href="index.php">← На главную</a></p></div>
    </body></html>
    <?php exit;
}
if ($tgId === '') $tgId = 'admin_local_' . $sid;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Планер клиентов | Kostlim Design</title>
    <link rel="icon" type="image/png" href="/assets/img/logo.png" sizes="16x16">
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
    <link rel="stylesheet" href="assets/kostlim-upgrade.css?v=<?= @filemtime(__DIR__ . '/assets/kostlim-upgrade.css') ?: time() ?>">
</head>
<body>

<header>
    <div class="header-left">
        <a href="index.php" class="nav-link">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            На главную
        </a>
    </div>
    <div class="brand-title"><a href="index.php"><img src="/assets/img/logo.png" class="brand-logo-img" alt="Kostlim Design" style="height:40px;width:auto;max-width:160px;display:block;"></a></div>
    <div class="header-right"><?= renderRoleBadges(['ADMIN' => $isAdmin, 'PPK' => $isPackDesigner]) ?></div>
</header>

<main class="container planner-page">
    <div class="price-head">
        <h1>🗂 Личный планер клиентов</h1>
        <p>Учёт своих заказов вне сайта — только вы видите эти записи.</p>
    </div>

    <button type="button" class="save-all-btn" id="btnAddRow" style="margin-bottom:20px;">+ Добавить клиента</button>

    <div class="planner-table-wrap">
        <table class="planner-table" id="plannerTable">
            <thead><tr>
                <th>Клиент</th><th>Контакт</th><th>Статус</th><th>Дедлайн</th><th>Сумма</th><th>Заметки</th><th></th>
            </tr></thead>
            <tbody id="plannerBody"></tbody>
        </table>
    </div>
</main>

<template id="rowTemplate">
    <tr data-id="">
        <td><input type="text" class="p-client" placeholder="Имя клиента"></td>
        <td><input type="text" class="p-contact" placeholder="@ник / телефон"></td>
        <td>
            <select class="p-status">
                <option value="in_progress">В работе</option>
                <option value="revision">Правки</option>
                <option value="paid">Оплачено</option>
            </select>
        </td>
        <td><input type="date" class="p-deadline"></td>
        <td><input type="number" class="p-amount" placeholder="0" step="0.01"></td>
        <td><input type="text" class="p-notes" placeholder="Заметка"></td>
        <td><button type="button" class="res-del-btn p-delete" title="Удалить">✕</button></td>
    </tr>
</template>

<script>
const API = 'planner_api.php';
async function api(action, payload = {}) {
    const res = await fetch(API, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action, ...payload}) });
    return res.json();
}

const statusColors = { in_progress: '#60a5fa', revision: '#fb923c', paid: '#4ade80' };

function buildRow(row) {
    const tpl = document.getElementById('rowTemplate').content.cloneNode(true);
    const tr = tpl.querySelector('tr');
    tr.dataset.id = row.id || '';
    tr.querySelector('.p-client').value = row.client_name || '';
    tr.querySelector('.p-contact').value = row.contact || '';
    tr.querySelector('.p-status').value = row.status || 'in_progress';
    tr.querySelector('.p-deadline').value = row.deadline || '';
    tr.querySelector('.p-amount').value = row.amount || '';
    tr.querySelector('.p-notes').value = row.notes || '';

    let saveTimeout;
    const scheduleSave = () => {
        clearTimeout(saveTimeout);
        saveTimeout = setTimeout(() => saveRow(tr), 500);
    };
    tr.querySelectorAll('input, select').forEach(el => el.addEventListener('input', scheduleSave));
    tr.querySelectorAll('input, select').forEach(el => el.addEventListener('change', scheduleSave));
    tr.querySelector('.p-delete').onclick = () => deleteRow(tr);
    return tr;
}

async function saveRow(tr) {
    const payload = {
        id: tr.dataset.id || null,
        client_name: tr.querySelector('.p-client').value.trim(),
        contact: tr.querySelector('.p-contact').value.trim(),
        status: tr.querySelector('.p-status').value,
        deadline: tr.querySelector('.p-deadline').value,
        amount: parseFloat(tr.querySelector('.p-amount').value || '0'),
        notes: tr.querySelector('.p-notes').value.trim(),
    };
    const r = await api('save_row', payload);
    if (r.ok && !tr.dataset.id) tr.dataset.id = r.id;
}

async function deleteRow(tr) {
    if (!confirm('Удалить запись?')) return;
    if (tr.dataset.id) await api('delete_row', { id: tr.dataset.id });
    tr.remove();
}

document.getElementById('btnAddRow').onclick = () => {
    document.getElementById('plannerBody').appendChild(buildRow({}));
};

(async function loadPlanner() {
    const r = await api('list_rows');
    const body = document.getElementById('plannerBody');
    if (r.ok && r.rows.length) {
        r.rows.forEach(row => body.appendChild(buildRow(row)));
    }
})();
</script>
</body>
</html>
