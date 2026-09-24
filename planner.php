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
    <link rel="stylesheet" href="assets/kostlim-upgrade.css?v=<?= @filemtime(__DIR__ . '/assets/kostlim-upgrade.css') ?: time() ?>">
<style>
.planner-wrap { max-width: 1100px; margin: 0 auto; padding: 22px 20px 50px; }
.planner-top { display:flex; align-items:center; gap:12px; margin-bottom: 18px; }
.trainer-back {
    display:inline-flex; align-items:center; gap:6px; background: var(--card); border:1px solid var(--border);
    color: var(--text); padding: 9px 14px; border-radius: 10px; font-size: 12.5px; font-weight: 700; text-decoration:none;
}
.trainer-back:hover { border-color: var(--border-accent); color: var(--accent2); }
.planner-title { font-size: 19px; font-weight: 900; margin: 0 0 4px; }
.planner-sub { color: var(--text2); font-size: 13px; margin-bottom: 20px; }

/* ── Калькулятор сверху (Блок 3.3 ТЗ) ── */
.planner-calc { display:flex; gap:12px; flex-wrap:wrap; margin-bottom: 18px; }
.planner-calc-card {
    flex:1; min-width:180px; background: var(--card); border:1px solid var(--border); border-radius:14px;
    padding: 14px 18px;
}
.planner-calc-card .n { font-size: 22px; font-weight: 900; color: var(--accent); }
.planner-calc-card .l { font-size: 11.5px; color: var(--text2); text-transform:uppercase; letter-spacing:.05em; margin-top:2px; }

.planner-toolbar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom: 18px; }
.planner-add-btn {
    display:flex; align-items:center; justify-content:center; gap:8px;
    background: linear-gradient(135deg, var(--accent2), var(--accent)); color:#fff; border:none;
    padding: 13px 22px; border-radius: 12px; font-size: 12.5px; font-weight: 800; text-transform: uppercase;
    letter-spacing: .7px; cursor:pointer; box-shadow: var(--shadow-accent);
}
.planner-add-btn:hover { transform: translateY(-1px); }
.planner-status-manage-btn {
    background: var(--card); border:1px solid var(--border); color: var(--text2);
    padding: 12px 18px; border-radius: 12px; font-size: 12.5px; font-weight: 700; cursor:pointer; font-family:inherit;
}
.planner-status-manage-btn:hover { border-color: rgba(249,115,22,.35); color: var(--accent); }

.planner-table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 16px; }
.planner-table { width: 100%; border-collapse: collapse; min-width: 1020px; }
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
/* Кастомные статусы (Блок 3.2 ТЗ) получают собственный фон-плашку через
   inline style, который выставляет JS (см. applyStatusColor()) — он не
   трогает базовую тёмную тему коробки select выше. */
.p-status { font-weight:700; }
.planner-del-btn { background: rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.3); color:#ef4444; width:28px; height:28px; border-radius:8px; cursor:pointer; font-size:13px; }
.planner-empty { text-align:center; color: var(--text2); font-size: 13px; padding: 30px 0; }
.p-tz-link { color: var(--accent2); }
.p-prepay { width:auto !important; cursor:pointer; }

/* Дедлайн с подсветкой при приближении (Блок 3.3 ТЗ) */
.p-deadline.deadline-soon { border-color: #f59e0b !important; box-shadow: 0 0 0 1px rgba(245,158,11,.35); }
.p-deadline.deadline-overdue { border-color: #ef4444 !important; box-shadow: 0 0 0 1px rgba(239,68,68,.4); }

/* Модалка управления кастомными статусами */
.status-list-row { display:flex; align-items:center; gap:8px; padding:6px 0; }
.status-swatch { width:16px; height:16px; border-radius:4px; flex-shrink:0; }
.status-list-row span { flex:1; font-size:13px; }
</style>
</head>
<body>

<div class="planner-wrap">
    <div class="planner-top">
        <a href="privat_pak.php" class="trainer-back">← Приват Пак</a>
    </div>
    <h1 class="planner-title">🗂 Личный планер клиентов</h1>
    <p class="planner-sub">Учёт своих заказов вне сайта — только вы видите эти записи.</p>

    <div class="planner-calc">
        <div class="planner-calc-card"><div class="n" id="calcEarned">0 $</div><div class="l">Заработано за месяц</div></div>
        <div class="planner-calc-card"><div class="n" id="calcInProgress">0</div><div class="l">В работе заказов</div></div>
    </div>

    <div class="planner-toolbar">
        <button type="button" class="planner-add-btn" id="btnAddRow">+ Добавить клиента</button>
        <button type="button" class="planner-status-manage-btn" id="btnManageStatuses">🎨 Свои статусы</button>
    </div>

    <div class="planner-table-wrap">
        <table class="planner-table" id="plannerTable">
            <thead><tr>
                <th>Клиент</th><th>Контакт</th><th>Статус</th><th>Дедлайн</th><th>Оплата</th><th>Предоплата</th><th>Сумма</th><th>Ссылка на ТЗ</th><th>Заметки</th><th></th>
            </tr></thead>
            <tbody id="plannerBody"></tbody>
        </table>
    </div>
    <p class="planner-empty" id="plannerEmptyHint" style="display:none;">Пока нет записей — добавьте первого клиента 👆</p>
</div>

<!-- Модалка управления кастомными статусами -->
<div class="modal-overlay" id="statusModal">
    <div class="modal-card">
        <div class="modal-head"><h3>🎨 Свои статусы</h3><button type="button" class="modal-close" onclick="document.getElementById('statusModal').classList.remove('show')">✕</button></div>
        <div id="statusListWrap"></div>
        <div class="setup-row" style="margin-top:14px;">
            <input type="text" id="newStatusName" placeholder="Название статуса" style="flex:1;background:rgba(0,0,0,.15);border:1px solid var(--border);color:var(--text);padding:9px 11px;border-radius:8px;font-family:inherit;">
            <input type="color" id="newStatusColor" value="#FF7A00" style="width:44px;height:38px;border:1px solid var(--border);border-radius:8px;background:none;cursor:pointer;padding:2px;">
        </div>
        <button type="button" class="save-all-btn" style="width:100%;margin-top:10px;" id="btnCreateStatus">Добавить статус</button>
    </div>
</div>

<template id="rowTemplate">
    <tr data-id="">
        <td><input type="text" class="p-client" placeholder="Имя клиента"></td>
        <td><input type="text" class="p-contact" placeholder="@ник / телефон"></td>
        <td><select class="p-status"></select></td>
        <td><input type="date" class="p-deadline"></td>
        <td>
            <select class="p-payment">
                <option value="">—</option>
                <option value="Cryptobot">Cryptobot</option>
                <option value="Карта">Карта</option>
                <option value="PayPal">PayPal</option>
            </select>
        </td>
        <td style="text-align:center;"><input type="checkbox" class="p-prepay"></td>
        <td><input type="number" class="p-amount" placeholder="0" step="0.01"></td>
        <td><input type="text" class="p-tz-link" placeholder="Ссылка на ТЗ"></td>
        <td><input type="text" class="p-notes" placeholder="Заметка"></td>
        <td><button type="button" class="planner-del-btn p-delete" title="Удалить">✕</button></td>
    </tr>
</template>

<script>
const API = 'planner_api.php';
let STATUSES = []; // [{id,name,color,builtin}]

async function api(action, payload = {}) {
    const res = await fetch(API, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action, ...payload}) });
    return res.json();
}

function statusOptionsHtml() {
    return STATUSES.map(s => `<option value="${s.id}">${escHtml(s.name)}</option>`).join('');
}
function escHtml(s){ const d=document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

function applyStatusColor(select) {
    const st = STATUSES.find(s => s.id === select.value);
    select.style.color = st ? st.color : '';
}

function updateDeadlineHighlight(input) {
    input.classList.remove('deadline-soon', 'deadline-overdue');
    if (!input.value) return;
    const days = Math.ceil((new Date(input.value + 'T00:00:00') - new Date(new Date().toDateString())) / 86400000);
    if (days < 0) input.classList.add('deadline-overdue');
    else if (days <= 2) input.classList.add('deadline-soon');
}

function buildRow(row) {
    const tpl = document.getElementById('rowTemplate').content.cloneNode(true);
    const tr = tpl.querySelector('tr');
    tr.dataset.id = row.id || '';
    tr.querySelector('.p-client').value = row.client_name || '';
    tr.querySelector('.p-contact').value = row.contact || '';
    const statusSel = tr.querySelector('.p-status');
    statusSel.innerHTML = statusOptionsHtml();
    statusSel.value = row.status || 'in_progress';
    applyStatusColor(statusSel);
    tr.querySelector('.p-deadline').value = row.deadline || '';
    updateDeadlineHighlight(tr.querySelector('.p-deadline'));
    tr.querySelector('.p-payment').value = row.payment_method || '';
    tr.querySelector('.p-prepay').checked = !!row.prepayment;
    tr.querySelector('.p-amount').value = row.amount || '';
    tr.querySelector('.p-tz-link').value = row.tz_link || '';
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
    statusSel.addEventListener('change', () => applyStatusColor(statusSel));
    tr.querySelector('.p-deadline').addEventListener('change', (e) => updateDeadlineHighlight(e.target));
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
        payment_method: tr.querySelector('.p-payment').value,
        prepayment: tr.querySelector('.p-prepay').checked,
        amount: parseFloat(tr.querySelector('.p-amount').value || '0'),
        tz_link: tr.querySelector('.p-tz-link').value.trim(),
        notes: tr.querySelector('.p-notes').value.trim(),
    };
    try {
        const r = await api('save_row', payload);
        if (r.ok && !tr.dataset.id) tr.dataset.id = r.id;
        if (!r.ok) console.error('planner save_row error:', r.error);
        refreshCalc();
    } catch (e) { console.error('planner save_row network error:', e); }
}

async function deleteRow(tr) {
    if (!confirm('Удалить запись?')) return;
    if (tr.dataset.id) await api('delete_row', { id: tr.dataset.id });
    tr.remove();
    toggleEmptyHint();
    refreshCalc();
}

function toggleEmptyHint() {
    const hasRows = document.getElementById('plannerBody').children.length > 0;
    document.getElementById('plannerEmptyHint').style.display = hasRows ? 'none' : 'block';
}

// Калькулятор пересчитывается по факту сохранённых на сервере данных
// (учитывает месяц фактической оплаты, а не просто текущее состояние формы).
async function refreshCalc() {
    const r = await api('list_rows');
    if (!r.ok) return;
    STATUSES = r.statuses || STATUSES;
    document.getElementById('calcEarned').textContent = (r.calc?.earned_month ?? 0) + ' $';
    document.getElementById('calcInProgress').textContent = r.calc?.in_progress_count ?? 0;
}

document.getElementById('btnAddRow').onclick = () => {
    document.getElementById('plannerBody').appendChild(buildRow({}));
    toggleEmptyHint();
};

// ── Управление кастомными статусами ──
function renderStatusList() {
    const wrap = document.getElementById('statusListWrap');
    wrap.innerHTML = STATUSES.map(s => `
        <div class="status-list-row">
            <span class="status-swatch" style="background:${s.color}"></span>
            <span>${escHtml(s.name)}</span>
            ${s.builtin ? '<span style="font-size:11px;color:var(--text2);">встроенный</span>' : `<button type="button" class="mini-btn" data-del-status="${s.id.replace('custom:', '')}">Удалить</button>`}
        </div>
    `).join('');
}
document.getElementById('btnManageStatuses').onclick = () => {
    renderStatusList();
    document.getElementById('statusModal').classList.add('show');
};
document.getElementById('statusListWrap').addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-del-status]');
    if (!btn) return;
    if (!confirm('Удалить статус? Заказы с этим статусом вернутся в «В работе».')) return;
    const r = await api('delete_status', { id: btn.dataset.delStatus });
    if (r.ok) {
        STATUSES = r.statuses;
        renderStatusList();
        document.querySelectorAll('.p-status').forEach(sel => {
            const cur = sel.value;
            sel.innerHTML = statusOptionsHtml();
            sel.value = STATUSES.some(s => s.id === cur) ? cur : 'in_progress';
            applyStatusColor(sel);
        });
        refreshCalc();
    }
});
document.getElementById('btnCreateStatus').onclick = async () => {
    const name = document.getElementById('newStatusName').value.trim();
    const color = document.getElementById('newStatusColor').value;
    if (!name) return;
    const r = await api('create_status', { name, color });
    if (r.ok) {
        STATUSES = r.statuses;
        document.getElementById('newStatusName').value = '';
        renderStatusList();
        document.querySelectorAll('.p-status').forEach(sel => {
            const cur = sel.value;
            sel.innerHTML = statusOptionsHtml();
            sel.value = cur;
            applyStatusColor(sel);
        });
    } else if (r.error) {
        alert(r.error);
    }
};

(async function loadPlanner() {
    try {
        const r = await api('list_rows');
        if (r.ok) {
            STATUSES = r.statuses || [];
            const body = document.getElementById('plannerBody');
            r.rows.forEach(row => body.appendChild(buildRow(row)));
            document.getElementById('calcEarned').textContent = (r.calc?.earned_month ?? 0) + ' $';
            document.getElementById('calcInProgress').textContent = r.calc?.in_progress_count ?? 0;
        }
    } catch (e) { console.error('planner list_rows network error:', e); }
    toggleEmptyHint();
})();
</script>
</body>
</html>
