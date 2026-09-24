<?php
/**
 * Личный планер клиентов — модуль органайзера для владельцев пака (PPK).
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/ppk_access.php';

$access = resolvePpkAccess($pdo);
$isPackDesigner = $access['isPackDesigner'];

if (!$isPackDesigner) {
    http_response_code(403);
    ?>
    <!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Доступ закрыт | Kostlim Design</title><link rel="stylesheet" href="style.css"></head>
    <body style="display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center;padding:24px;">
        <div><h1>🔒 Доступ закрыт</h1><p>Личный планер клиентов доступен только владельцам PPK.</p><p><a href="privat_pak.php">← В Приват Пак</a></p></div>
    </body></html>
    <?php exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Планер клиентов | Kostlim Design</title>
    <link rel="icon" type="image/png" href="/assets/img/logo.png" sizes="16x16">
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<style>
.planner-wrap { max-width: 920px; margin: 0 auto; padding: 22px 20px 50px; }
.planner-top { display:flex; align-items:center; gap:12px; margin-bottom: 18px; }
.trainer-back {
    display:inline-flex; align-items:center; gap:6px; background: var(--card); border:1px solid var(--border);
    color: var(--text); padding: 9px 14px; border-radius: 10px; font-size: 12.5px; font-weight: 700; text-decoration:none;
}
.trainer-back:hover { border-color: var(--border-accent); color: var(--accent2); }
.planner-title { font-size: 19px; font-weight: 900; margin: 0 0 4px; }
.planner-sub { color: var(--text2); font-size: 13px; margin-bottom: 20px; }
.planner-add-btn {
    display:flex; align-items:center; justify-content:center; gap:8px;
    background: linear-gradient(135deg, var(--accent2), var(--accent)); color:#fff; border:none;
    padding: 13px 22px; border-radius: 12px; font-size: 12.5px; font-weight: 800; text-transform: uppercase;
    letter-spacing: .7px; cursor:pointer; box-shadow: var(--shadow-accent); margin-bottom: 18px;
}
.planner-add-btn:hover { transform: translateY(-1px); }
.planner-table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 16px; }
.planner-table { width: 100%; border-collapse: collapse; min-width: 720px; }
.planner-table th {
    text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
    color: var(--text2); padding: 12px 12px; border-bottom: 1px solid var(--border); background: var(--card);
}
.planner-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); }
.planner-table input, .planner-table select {
    width: 100%; box-sizing: border-box; background: transparent; border: 1px solid transparent;
    color: var(--text); padding: 7px 8px; border-radius: 8px; font-family: inherit; font-size: 13px;
}
.planner-table input:focus, .planner-table select:focus { border-color: var(--border-accent); background: rgba(0,0,0,.15); outline: none; }
/* FIX: раньше select был почти без стилей "коробки" (только option'ы) —
   в Chrome/Windows это давало стандартный системный синий фон вместо темы
   сайта. Отключаем нативный вид и рисуем свою стрелку в акцентном цвете. */
.planner-table select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-color: #0D0D0D;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='13' height='13' viewBox='0 0 24 24' fill='none' stroke='%23FF7A00' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 8px center; padding-right: 26px;
}
.planner-table select option { background: #0D0D0D; color: #F4F4F4; }
/* Кастомные статусы (Блок 3 ТЗ) получают собственный фон-плашку через
   inline style — см. renderStatusOptions() ниже; она не трогает базовую
   тёмную тему коробки select выше. */
.planner-del-btn { background: rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.3); color:#ef4444; width:28px; height:28px; border-radius:8px; cursor:pointer; font-size:13px; }
.planner-empty { text-align:center; color: var(--text2); font-size: 13px; padding: 30px 0; }
</style>
</head>
<body>

<div class="planner-wrap">
    <div class="planner-top">
        <a href="privat_pak.php" class="trainer-back">← Приват Пак</a>
    </div>
    <h1 class="planner-title">🗂 Личный планер клиентов</h1>
    <p class="planner-sub">Учёт своих заказов вне сайта — только вы видите эти записи.</p>

    <button type="button" class="planner-add-btn" id="btnAddRow">+ Добавить клиента</button>

    <div class="planner-table-wrap">
        <table class="planner-table" id="plannerTable">
            <thead><tr>
                <th>Клиент</th><th>Контакт</th><th>Статус</th><th>Дедлайн</th><th>Сумма</th><th>Заметки</th><th></th>
            </tr></thead>
            <tbody id="plannerBody"></tbody>
        </table>
    </div>
    <p class="planner-empty" id="plannerEmptyHint" style="display:none;">Пока нет записей — добавьте первого клиента 👆</p>
</div>

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
        <td><button type="button" class="planner-del-btn p-delete" title="Удалить">✕</button></td>
    </tr>
</template>

<script>
const API = 'planner_api.php';
async function api(action, payload = {}) {
    const res = await fetch(API, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action, ...payload}) });
    return res.json();
}

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
    tr.querySelectorAll('input, select').forEach(el => {
        el.addEventListener('input', scheduleSave);
        el.addEventListener('change', scheduleSave);
    });
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
    try {
        const r = await api('save_row', payload);
        if (r.ok && !tr.dataset.id) tr.dataset.id = r.id;
        if (!r.ok) console.error('planner save_row error:', r.error);
    } catch (e) { console.error('planner save_row network error:', e); }
}

async function deleteRow(tr) {
    if (!confirm('Удалить запись?')) return;
    if (tr.dataset.id) await api('delete_row', { id: tr.dataset.id });
    tr.remove();
    toggleEmptyHint();
}

function toggleEmptyHint() {
    const hasRows = document.getElementById('plannerBody').children.length > 0;
    document.getElementById('plannerEmptyHint').style.display = hasRows ? 'none' : 'block';
}

document.getElementById('btnAddRow').onclick = () => {
    document.getElementById('plannerBody').appendChild(buildRow({}));
    toggleEmptyHint();
};

(async function loadPlanner() {
    try {
        const r = await api('list_rows');
        const body = document.getElementById('plannerBody');
        if (r.ok && r.rows.length) {
            r.rows.forEach(row => body.appendChild(buildRow(row)));
        }
    } catch (e) { console.error('planner list_rows network error:', e); }
    toggleEmptyHint();
})();
</script>
</body>
</html>
