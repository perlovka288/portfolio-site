<?php
/**
 * «Тренировка общения с клиентом» — закрытый раздел для PPK/ADMIN.
 * Анкета (имя клиента / сложность / тема) → чат с ИИ-заказчиком →
 * сдача работы → оценка 0–100 → «Поделиться с Kostlim».
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/ppk_access.php';

$access = resolvePpkAccess($pdo);
$isAdmin = $access['isAdmin'];
$isPackDesigner = $access['isPackDesigner'];

if (!$isPackDesigner) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Доступ закрыт | Kostlim Design</title>
    <link rel="stylesheet" href="style.css">
    </head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center;padding:24px;">
        <div>
            <h1>🔒 Доступ закрыт</h1>
            <p>Тренажёр общения с клиентом доступен только участникам приватного пака (PPK).</p>
            <p><a href="privat_pak.php">← В Приват Пак</a></p>
        </div>
    </body></html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Тренажёр клиентов | Kostlim Design</title>
    <link rel="icon" type="image/png" href="/assets/img/logo.png" sizes="16x16">
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<style>
.trainer-wrap { max-width: 560px; margin: 0 auto; padding: 22px 20px 40px; }
.trainer-top { display:flex; align-items:center; gap:12px; margin-bottom: 18px; }
.trainer-back {
    display:inline-flex; align-items:center; gap:6px; background: var(--card); border:1px solid var(--border);
    color: var(--text); padding: 9px 14px; border-radius: 10px; font-size: 12.5px; font-weight: 700;
    text-decoration:none;
}
.trainer-back:hover { border-color: var(--border-accent); color: var(--accent2); }
.trainer-title { font-size: 19px; font-weight: 900; margin: 0 0 4px; }
.trainer-sub { color: var(--text2); font-size: 13px; margin-bottom: 20px; }

.trainer-new-btn {
    display:flex; align-items:center; justify-content:center; gap:8px; width:100%; box-sizing:border-box;
    background: linear-gradient(135deg, var(--accent2), var(--accent)); color:#fff; border:none;
    padding: 15px; border-radius: 14px; font-size: 13px; font-weight: 800; text-transform: uppercase;
    letter-spacing: .8px; cursor:pointer; box-shadow: var(--shadow-accent); transition: all var(--t);
    margin-bottom: 20px;
}
.trainer-new-btn:hover { transform: translateY(-2px); }

.trainer-session-row {
    display:flex; align-items:center; gap:12px; background: var(--card); border:1px solid var(--border);
    border-radius: 16px; padding: 14px 16px; margin-bottom: 10px; cursor:pointer; transition: all var(--t);
}
.trainer-session-row:hover { border-color: var(--border-accent); background: var(--accent-dim); }
.trainer-session-icon {
    width:38px; height:38px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center;
    background: var(--accent-dim); color: var(--accent); font-size:16px;
}
.trainer-session-title { font-size: 13.5px; font-weight: 800; }
.trainer-session-meta { font-size: 11.5px; color: var(--text2); margin-top: 2px; }
.trainer-empty { text-align:center; color: var(--text2); font-size: 13px; padding: 30px 0; }

/* Модалка анкеты */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.6); backdrop-filter: blur(3px); display:none; align-items:center; justify-content:center; z-index:1000; padding:16px; }
.modal-overlay.show { display:flex; }
.modal-card { background: var(--bg2, #0d0d0d); border:1px solid var(--border); border-radius:18px; padding:22px; max-width:420px; width:100%; max-height:88vh; overflow-y:auto; }
.modal-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
.modal-head h3 { margin:0; font-size:17px; }
.modal-close { background:none; border:none; color: var(--text2); font-size:18px; cursor:pointer; }
.trainer-setup-card label { display:block; font-size:12px; color: var(--text2); margin: 14px 0 6px; font-weight:700; }
.setup-row { display:flex; gap:8px; }
.setup-row input[type=text], .trainer-setup-card input[type=text]#setupTopicCustom {
    flex:1; width:100%; box-sizing:border-box; background: rgba(0,0,0,.15); border:1px solid var(--border);
    color: var(--text); padding:10px 12px; border-radius:10px; font-family:inherit; margin-top: 8px;
}
.mini-btn { background: rgba(255,255,255,.06); border:1px solid var(--border); color: var(--text); padding:9px 13px; border-radius:10px; cursor:pointer; font-size:12.5px; font-weight:700; white-space:nowrap; }
.mini-btn:hover { border-color: var(--border-accent); color: var(--accent2); }
.setup-pills { display:flex; gap:8px; flex-wrap:wrap; }
.setup-pill { background: rgba(255,255,255,.06); border:1px solid var(--border); color: var(--text2); padding:9px 15px; border-radius:999px; cursor:pointer; font-size:12.5px; font-weight:700; }
.setup-pill.active { background: linear-gradient(135deg, var(--accent2), var(--accent)); color:#fff; border-color:transparent; }
.trainer-start-btn {
    display:flex; align-items:center; justify-content:center; width:100%; box-sizing:border-box; margin-top:18px;
    background: linear-gradient(135deg, var(--accent2), var(--accent)); color:#fff; border:none; padding:14px;
    border-radius:12px; font-size:13px; font-weight:800; text-transform:uppercase; letter-spacing:.8px; cursor:pointer;
    box-shadow: var(--shadow-accent);
}
.trainer-start-btn:disabled { opacity:.6; cursor:default; }

/* Экран чата */
.trainer-chat-screen { position: fixed; inset:0; background: var(--bg, #080808); z-index:999; display:none; flex-direction:column; }
.trainer-chat-screen.show { display:flex; }
.trainer-chat-header { display:flex; align-items:center; gap:12px; padding:14px 16px; border-bottom:1px solid var(--border); flex-shrink:0; }
.trainer-chat-title { flex:1; display:flex; flex-direction:column; }
.trainer-chat-title span { font-size:11px; color: var(--text2); }
.trainer-diff-badge { font-size:10px; font-weight:900; text-transform:uppercase; padding:4px 10px; border-radius:999px; background: var(--accent-dim); color: var(--accent3); border:1px solid var(--border-accent); }
.trainer-chat-body { flex:1; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:10px; }
.trainer-msg { max-width:78%; padding:10px 14px; border-radius:14px; font-size:14px; line-height:1.5; word-wrap:break-word; }
.trainer-msg--client { align-self:flex-start; background: var(--card); border:1px solid var(--border); }
.trainer-msg--designer { align-self:flex-end; background: linear-gradient(135deg, var(--accent2), var(--accent)); color:#fff; }
.trainer-msg--typing { opacity:.6; font-style:italic; }
.trainer-msg-img { max-width:100%; border-radius:10px; display:block; margin-bottom:6px; }
.trainer-chat-footer { display:flex; align-items:center; gap:8px; padding:12px 16px; border-top:1px solid var(--border); flex-shrink:0; }
.trainer-chat-footer input[type=text] { flex:1; background: rgba(255,255,255,.06); border:1px solid var(--border); color: var(--text); padding:12px 15px; border-radius:999px; font-family:inherit; }
.chat-send-btn { flex-shrink:0; width:42px; height:42px; border-radius:50%; border:none; background: linear-gradient(135deg, var(--accent2), var(--accent)); color:#fff; font-size:16px; cursor:pointer; }
.trainer-submit-btn {
    display:flex; align-items:center; justify-content:center; gap:8px; margin:12px 16px;
    background: linear-gradient(135deg, var(--accent2), var(--accent)); color:#fff; border:none; padding:13px;
    border-radius:12px; font-size:12.5px; font-weight:800; text-transform:uppercase; letter-spacing:.6px; cursor:pointer;
}
.trainer-score-circle { width:96px; height:96px; border-radius:50%; margin:6px auto 16px; display:flex; align-items:center; justify-content:center; font-size:22px; font-weight:900; border:3px solid var(--accent); color:#fff; background: var(--accent-dim); }
</style>
</head>
<body>

<div class="trainer-wrap">
    <div class="trainer-top">
        <a href="privat_pak.php" class="trainer-back">← Приват Пак</a>
    </div>
    <h1 class="trainer-title">🎮 Тренировка общения с клиентом</h1>
    <p class="trainer-sub">Отыграй заказ от начала до сдачи — ИИ в роли требовательного заказчика.</p>

    <button type="button" class="trainer-new-btn" id="btnNewSession">+ Новый заказ</button>

    <div id="trainerSessionsList"></div>
</div>

<!-- Модалка анкеты -->
<div class="modal-overlay" id="setupModal">
    <div class="modal-card trainer-setup-card">
        <div class="modal-head">
            <h3>Новый тренировочный заказ</h3>
            <button type="button" class="modal-close" onclick="closeSetupModal()">✕</button>
        </div>
        <label>Имя клиента</label>
        <div class="setup-row">
            <input type="text" id="setupClientName" placeholder="Например: Даниил">
            <button type="button" class="mini-btn" id="btnRandomName">🎲 Случайно</button>
        </div>

        <label>Сложность</label>
        <div class="setup-pills" id="setupDifficulty">
            <button type="button" class="setup-pill active" data-val="easy">Легко</button>
            <button type="button" class="setup-pill" data-val="standard">Стандарт</button>
            <button type="button" class="setup-pill" data-val="hard">Сложно</button>
        </div>

        <label>Тема заказа</label>
        <div class="setup-pills" id="setupTopicPills">
            <button type="button" class="setup-pill active" data-val="Превью Standoff 2">Превью Standoff 2</button>
            <button type="button" class="setup-pill" data-val="Шапка Dota 2">Шапка Dota 2</button>
            <button type="button" class="setup-pill" data-val="Аватарка CS2">Аватарка CS2</button>
        </div>
        <input type="text" id="setupTopicCustom" placeholder="Или своя тема заказа...">

        <button type="button" class="trainer-start-btn" id="btnStartSession">Начать заказ →</button>
    </div>
</div>

<!-- Экран чата -->
<div class="trainer-chat-screen" id="chatScreen">
    <div class="trainer-chat-header">
        <button type="button" class="mini-btn" onclick="closeChatScreen()">← Назад</button>
        <div class="trainer-chat-title">
            <strong id="chatClientName">Клиент</strong>
            <span id="chatTopicLabel"></span>
        </div>
        <span id="chatDifficultyBadge" class="trainer-diff-badge"></span>
    </div>
    <div class="trainer-chat-body" id="chatBody"></div>
    <div class="trainer-chat-footer">
        <input type="file" id="submitFileInput" accept="image/*" style="display:none;">
        <button type="button" class="mini-btn" id="btnAttachSubmit" title="Прикрепить превью работы">📎</button>
        <input type="text" id="chatInput" placeholder="Написать клиенту...">
        <button type="button" class="chat-send-btn" id="btnSendMsg">➤</button>
    </div>
    <button type="button" class="trainer-submit-btn" id="btnSubmitWork">📤 Сдать работу</button>
</div>

<!-- Экран результата -->
<div class="modal-overlay" id="resultModal">
    <div class="modal-card">
        <div class="modal-head">
            <h3>Результат сдачи</h3>
            <button type="button" class="modal-close" onclick="closeResultModal()">✕</button>
        </div>
        <div class="trainer-score-circle" id="resultScoreCircle">–</div>
        <p id="resultReviewText" style="color:var(--text2);line-height:1.6;"></p>
        <button type="button" class="trainer-start-btn" id="btnShareAdmin">📨 Поделиться с Kostlim</button>
    </div>
</div>

<script>
const API = 'ai_trainer_api.php';
let currentSessionId = null;
let pendingSubmitFile = null;

function esc(s){ const d=document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
async function api(action, payload = {}, isFormData = false) {
    let opts;
    if (isFormData) {
        payload.append('action', action);
        opts = { method: 'POST', body: payload };
    } else {
        opts = { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action, ...payload}) };
    }
    const res = await fetch(API, opts);
    return res.json();
}

function openSetupModal(){ document.getElementById('setupModal').classList.add('show'); }
function closeSetupModal(){ document.getElementById('setupModal').classList.remove('show'); }
function closeChatScreen(){ document.getElementById('chatScreen').classList.remove('show'); loadSessions(); }
function closeResultModal(){ document.getElementById('resultModal').classList.remove('show'); }

document.getElementById('btnNewSession').onclick = openSetupModal;

document.querySelectorAll('#setupDifficulty .setup-pill').forEach(btn => {
    btn.onclick = () => { document.querySelectorAll('#setupDifficulty .setup-pill').forEach(b=>b.classList.remove('active')); btn.classList.add('active'); };
});
document.querySelectorAll('#setupTopicPills .setup-pill').forEach(btn => {
    btn.onclick = () => {
        document.querySelectorAll('#setupTopicPills .setup-pill').forEach(b=>b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('setupTopicCustom').value = '';
    };
});

const randomNames = ['Даниил','Марк','Артём','Илья','Тимур','Максим','Кирилл','Егор'];
document.getElementById('btnRandomName').onclick = () => {
    document.getElementById('setupClientName').value = randomNames[Math.floor(Math.random()*randomNames.length)];
};

document.getElementById('btnStartSession').onclick = async () => {
    const btn = document.getElementById('btnStartSession');
    const difficulty = document.querySelector('#setupDifficulty .setup-pill.active').dataset.val;
    const topicCustom = document.getElementById('setupTopicCustom').value.trim();
    const topic = topicCustom || document.querySelector('#setupTopicPills .setup-pill.active').dataset.val;
    const clientName = document.getElementById('setupClientName').value.trim() || randomNames[0];

    btn.disabled = true; btn.textContent = 'Клиент печатает...';
    let r;
    try {
        r = await api('start_session', { client_name: clientName, difficulty, topic });
    } catch (e) {
        btn.disabled = false; btn.textContent = 'Начать заказ →';
        alert('Ошибка сети, попробуйте ещё раз.');
        return;
    }
    btn.disabled = false; btn.textContent = 'Начать заказ →';
    if (!r.ok) { alert(r.error || 'Ошибка'); return; }

    currentSessionId = r.session_id;
    closeSetupModal();
    openChatWithHistory(r);
};

function openChatWithHistory(sessionData) {
    document.getElementById('chatClientName').textContent = sessionData.client_name;
    document.getElementById('chatTopicLabel').textContent = sessionData.topic;
    const diffLabels = {easy:'Легко', standard:'Стандарт', hard:'Сложно'};
    document.getElementById('chatDifficultyBadge').textContent = diffLabels[sessionData.difficulty] || sessionData.difficulty;
    const body = document.getElementById('chatBody');
    body.innerHTML = '';
    (sessionData.messages || []).forEach(addMessageBubble);
    document.getElementById('chatScreen').classList.add('show');
    body.scrollTop = body.scrollHeight;
}

function addMessageBubble(m) {
    const body = document.getElementById('chatBody');
    const div = document.createElement('div');
    div.className = 'trainer-msg trainer-msg--' + (m.role === 'client' ? 'client' : 'designer');
    let html = '';
    if (m.attachment_url) html += `<img src="${esc(m.attachment_url)}" class="trainer-msg-img">`;
    if (m.content) html += `<div>${esc(m.content).replace(/\n/g,'<br>')}</div>`;
    div.innerHTML = html;
    body.appendChild(div);
    body.scrollTop = body.scrollHeight;
}

async function sendMessage() {
    const input = document.getElementById('chatInput');
    const text = input.value.trim();
    if (!text || !currentSessionId) return;
    addMessageBubble({role:'designer', content:text});
    input.value = '';
    const typing = document.createElement('div');
    typing.className = 'trainer-msg trainer-msg--client trainer-msg--typing';
    typing.textContent = 'печатает…';
    document.getElementById('chatBody').appendChild(typing);
    document.getElementById('chatBody').scrollTop = 9e9;

    let r;
    try {
        r = await api('send_message', { session_id: currentSessionId, content: text });
    } catch (e) {
        r = { ok: false };
    }
    typing.remove();
    if (r.ok) addMessageBubble({role:'client', content: r.reply});
    else addMessageBubble({role:'client', content: '⚠️ Не удалось получить ответ, попробуй ещё раз.'});
}
document.getElementById('btnSendMsg').onclick = sendMessage;
document.getElementById('chatInput').addEventListener('keydown', e => { if (e.key === 'Enter') sendMessage(); });

document.getElementById('btnAttachSubmit').onclick = () => document.getElementById('submitFileInput').click();
document.getElementById('submitFileInput').onchange = (e) => {
    pendingSubmitFile = e.target.files[0] || null;
    if (pendingSubmitFile) document.getElementById('btnAttachSubmit').textContent = '✅';
};

document.getElementById('btnSubmitWork').onclick = async () => {
    if (!currentSessionId) return;
    if (!pendingSubmitFile) { alert('Прикрепи превью/файл работы (📎), потом жми «Сдать работу».'); return; }
    const btn = document.getElementById('btnSubmitWork');
    btn.disabled = true; btn.textContent = 'ИИ оценивает работу...';

    const fd = new FormData();
    fd.append('session_id', currentSessionId);
    fd.append('file', pendingSubmitFile);
    let r;
    try {
        r = await api('submit_work', fd, true);
    } catch (e) {
        r = { ok: false, error: 'Ошибка сети' };
    }

    btn.disabled = false; btn.textContent = '📤 Сдать работу';
    if (!r.ok) { alert(r.error || 'Ошибка оценки'); return; }

    addMessageBubble({role:'designer', content:'Сдал работу на проверку', attachment_url: r.attachment_url});
    document.getElementById('resultScoreCircle').textContent = r.score + '/100';
    document.getElementById('resultReviewText').textContent = r.review;
    document.getElementById('resultModal').classList.add('show');
    pendingSubmitFile = null;
    document.getElementById('btnAttachSubmit').textContent = '📎';
};

document.getElementById('btnShareAdmin').onclick = async () => {
    const btn = document.getElementById('btnShareAdmin');
    // FIX: раньше кнопка снова становилась кликабельной после успешной
    // отправки ("✅ Отправлено" можно было нажать ещё раз) — это и слало
    // повторное уведомление админу на каждый лишний клик. Теперь при
    // успехе кнопка остаётся заблокированной насовсем; разблокируем
    // обратно только если сама отправка не удалась (сетевая ошибка и т.п.).
    btn.disabled = true; btn.textContent = 'Отправка...';
    const r = await api('share_with_admin', { session_id: currentSessionId });
    if (r.ok) {
        btn.disabled = true;
        btn.textContent = '✅ Отправлено';
    } else {
        btn.disabled = false;
        btn.textContent = 'Ошибка, повторить';
    }
};

async function loadSessions() {
    const r = await api('list_sessions');
    const wrap = document.getElementById('trainerSessionsList');
    if (!r.ok || !r.sessions.length) { wrap.innerHTML = '<p class="trainer-empty">Пока нет тренировок — начни первую 👆</p>'; return; }
    wrap.innerHTML = r.sessions.map(s => `
        <div class="trainer-session-row" onclick="resumeSession(${s.id})">
            <div class="trainer-session-icon">🎮</div>
            <div>
                <div class="trainer-session-title">${esc(s.client_name)} — ${esc(s.topic)}</div>
                <div class="trainer-session-meta">${s.status === 'scored' ? '✅ Оценено: ' + s.score + '/100' : (s.status === 'submitted' ? '⏳ На проверке' : '💬 В процессе')}</div>
            </div>
        </div>
    `).join('');
}

async function resumeSession(id) {
    const r = await api('get_session', { session_id: id });
    if (!r.ok) return;
    currentSessionId = id;
    openChatWithHistory(r);
    if (r.status === 'scored') {
        document.getElementById('resultScoreCircle').textContent = r.score + '/100';
        document.getElementById('resultReviewText').textContent = r.review;
        document.getElementById('resultModal').classList.add('show');
        // Реоткрытие уже расшаренного результата — сразу показываем
        // «Отправлено» и не даём поделиться повторно (см. FIX выше).
        const shareBtn = document.getElementById('btnShareAdmin');
        if (r.shared_with_admin) {
            shareBtn.disabled = true;
            shareBtn.textContent = '✅ Отправлено';
        } else {
            shareBtn.disabled = false;
            shareBtn.textContent = '📨 Поделиться с Kostlim';
        }
    }
}

loadSessions();
</script>
</body>
</html>
