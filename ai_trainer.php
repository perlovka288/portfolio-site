<?php
/**
 * «Тренировка общения с клиентом» — закрытый раздел для PPK/ADMIN.
 * Анкета (имя клиента / сложность / тема) → чат с ИИ-заказчиком →
 * сдача работы → оценка 0–100 → «Поделиться с Kostlim».
 *
 * Бэкенд запросов к ИИ и БД — в ai_trainer_api.php (AJAX, JSON).
 * Использует тот же Gemini API и паттерн ключей, что и ai_support.php.
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
    $stmt = $pdo->prepare("SELECT tg_id, tg_username, tg_first_name, tg_photo_url FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1");
    $stmt->execute([$sid]);
    $tgProfile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$adminTgEnv = getenv('ADMIN_ID') ?: '1710365896';
$isAdmin = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
if (!$isAdmin && !empty($tgProfile['tg_id']) && (string)$tgProfile['tg_id'] === $adminTgEnv) {
    $isAdmin = true;
}

$tgId = (string)($tgProfile['tg_id'] ?? '');
$isPackDesigner = false;
if ($isAdmin || $tgId !== '') {
    ensurePpkManualSchema($pdo);
    $botTokenForRoleCheck        = getSiteSetting($pdo, 'BOT_TOKEN') ?: (getenv('TELEGRAM_BOT_TOKEN') ?: getenv('BOT_TOKEN') ?: '');
    $packGroupChatIdForRoleCheck = getSiteSetting($pdo, 'PRIVATE_CHAT_ID') ?: (getenv('PRIVATE_CHAT_ID') ?: '');
    $isPackDesigner = $isAdmin
        || hasManualPpkGrant($pdo, $tgId)
        || isPackDesigner($pdo, $botTokenForRoleCheck, $packGroupChatIdForRoleCheck, $tgId, $isAdmin);
}

if (!$isPackDesigner) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Доступ закрыт | Kostlim Design</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="assets/kostlim-upgrade.css">
    </head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center;padding:24px;">
        <div>
            <h1>🔒 Доступ закрыт</h1>
            <p>Тренажёр общения с клиентом доступен только участникам приватного пака (PPK).</p>
            <p><a href="index.php">← На главную</a></p>
        </div>
    </body></html>
    <?php
    exit;
}

function getSiteSetting(PDO $pdo, string $key, string $default = ''): string
{
    if (!function_exists('getSiteSettingImpl')) {
        try {
            $stmt = $pdo->prepare("SELECT value FROM site_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            return $val !== false && $val !== null && $val !== '' ? (string)$val : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
    return $default;
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

<main class="container trainer-page">
    <div class="price-head">
        <h1>🎮 Тренировка общения с клиентом</h1>
        <p>Отыграй заказ от начала до сдачи — ИИ в роли требовательного заказчика.</p>
    </div>

    <!-- Список прошлых/текущих сессий -->
    <div id="trainerSessionsList" class="trainer-sessions-list"></div>

    <button type="button" class="save-all-btn" id="btnNewSession" style="margin:0 auto 20px;display:block;">+ Новый заказ</button>
</main>

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

        <button type="button" class="save-all-btn" id="btnStartSession" style="width:100%;margin-top:16px;">Начать заказ →</button>
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
        <span id="chatDifficultyBadge" class="role-badge role-badge--ppk"></span>
    </div>
    <div class="trainer-chat-body" id="chatBody"></div>
    <div class="trainer-chat-footer">
        <input type="file" id="submitFileInput" accept="image/*" style="display:none;">
        <button type="button" class="mini-btn" id="btnAttachSubmit" title="Прикрепить превью работы">📎</button>
        <input type="text" id="chatInput" placeholder="Написать клиенту...">
        <button type="button" class="chat-send-btn" id="btnSendMsg">➤</button>
    </div>
    <button type="button" class="save-all-btn" id="btnSubmitWork" style="margin:12px 16px;">📤 Сдать работу</button>
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
        <button type="button" class="save-all-btn" id="btnShareAdmin" style="width:100%;">📨 Поделиться с Kostlim</button>
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
    const r = await api('start_session', { client_name: clientName, difficulty, topic });
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

    const r = await api('send_message', { session_id: currentSessionId, content: text });
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
    const r = await api('submit_work', fd, true);

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
    btn.disabled = true; btn.textContent = 'Отправка...';
    const r = await api('share_with_admin', { session_id: currentSessionId });
    btn.disabled = false;
    btn.textContent = r.ok ? '✅ Отправлено' : 'Ошибка, повторить';
};

async function loadSessions() {
    const r = await api('list_sessions');
    const wrap = document.getElementById('trainerSessionsList');
    if (!r.ok || !r.sessions.length) { wrap.innerHTML = '<p style="text-align:center;color:var(--text2);">Пока нет тренировок — начни первую 👆</p>'; return; }
    wrap.innerHTML = r.sessions.map(s => `
        <div class="service-card trainer-session-card" onclick="resumeSession(${s.id})">
            <div class="res-caption">
                <h3>${esc(s.client_name)} — ${esc(s.topic)}</h3>
                <span>${s.status === 'scored' ? '✅ Оценено: ' + s.score + '/100' : (s.status === 'submitted' ? '⏳ На проверке' : '💬 В процессе')}</span>
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
    }
}

loadSessions();
</script>
</body>
</html>
