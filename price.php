<?php
session_start();
require_once 'config/db.php';

// ── Auto-login via TG ID ──
define('ADMIN_TG_ID', '1710365896');
if (!empty($_GET['tg_id']) && $_GET['tg_id'] === ADMIN_TG_ID) {
    $_SESSION['admin_logged'] = true;
}
$isAdmin = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;

// ── AJAX inline-edit ──
if ($isAdmin && isset($_POST['inline_edit_price'])) {
    header('Content-Type: application/json');
    $id      = (int)($_POST['id'] ?? 0);
    $field   = $_POST['field'] ?? '';
    $value   = trim($_POST['value'] ?? '');
    $allowed = ['title', 'price_rub', 'price_uan', 'description', 'features'];
    if ($id > 0 && in_array($field, $allowed, true)) {
        $pdo->prepare("UPDATE prices SET {$field} = ? WHERE id = ?")->execute([$value, $id]);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

function imgSrc(string $val, string $base = 'uploads/'): string {
    if ($val === '') return '';
    if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) return $val;
    return $base . $val;
}

$stmt     = $pdo->query("SELECT * FROM prices ORDER BY id ASC");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kostlim Design | Прайс-лист</title>
<link rel="stylesheet" href="style.css">
<style>
/* ── Инлайн-редактирование ── */
.editable-field {
    position: relative;
    display: inline-block;
    cursor: default;
}
.editable-field .pencil-btn {
    display: none;
    position: absolute;
    top: -6px; right: -24px;
    width: 19px; height: 19px;
    background: var(--accent);
    border: none; border-radius: 5px;
    cursor: pointer; align-items: center; justify-content: center;
    box-shadow: 0 0 10px rgba(249,115,22,0.5);
    z-index: 10; padding: 0;
}
.editable-field:hover .pencil-btn { display: inline-flex; }
.pencil-btn svg { width: 10px; height: 10px; color: #fff; }

.edit-input-inline {
    background: var(--card3);
    border: 1px solid var(--accent);
    color: var(--text);
    border-radius: 6px;
    padding: 4px 8px;
    font-size: inherit;
    font-family: inherit;
    font-weight: inherit;
    width: 100%;
    box-shadow: 0 0 10px rgba(249,115,22,0.2);
    outline: none;
}

/* ── Фоновое свечение ── */
body::before {
    content: '';
    position: fixed;
    top: -100px; left: 50%;
    transform: translateX(-50%);
    width: 600px; height: 350px;
    background: radial-gradient(ellipse, rgba(249,115,22,0.10) 0%, transparent 70%);
    pointer-events: none; z-index: 0;
}

.price-page { position: relative; z-index: 1; }

/* ── Admin bar ── */
.admin-notice {
    background: rgba(249,115,22,0.10);
    border: 1px solid rgba(249,115,22,0.3);
    border-radius: 10px; padding: 10px 18px;
    color: var(--accent2); font-size: 12px; font-weight: 700;
    text-align: center; margin-bottom: 20px;
}
</style>
</head>
<body>

<header>
    <div class="header-left">
        <a href="index.php" class="nav-link">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
            </svg>
            На главную
        </a>
    </div>

    <div class="brand-title"><h1>KOSTLIM</h1><span>DESIGN</span></div>

    <div class="header-right">
        <a href="https://t.me/kostlimdznbot" target="_blank" class="nav-link nav-bot">
            <span class="icon"></span>
            Бот для заказов
        </a>
        <a href="order.php" class="nav-link" style="background:linear-gradient(135deg,var(--accent2),var(--accent));color:#fff;border-color:transparent;box-shadow:0 0 18px rgba(249,115,22,0.3);">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/>
                <path d="M16 10a4 4 0 0 1-8 0"/>
            </svg>
            К заказу
        </a>
    </div>
</header>

<main class="container price-page">
    <div class="price-head">
        <h1>Прайс-лист</h1>
        <p>Все услуги подтягиваются из админ-панели и сразу доступны в Telegram-боте.</p>
    </div>

    <?php if ($isAdmin): ?>
    <div class="admin-notice">
        ✏️ Режим администратора — кликай на карандаш рядом с любым текстом или ценой чтобы изменить прямо здесь
    </div>
    <?php endif; ?>

    <section class="price-grid-local">
    <?php foreach ($services as $service): ?>
    <article class="service-card" data-id="<?= (int)$service['id'] ?>">
        <div class="service-cover">
            <?php $coverSrc = imgSrc($service['image'] ?? ''); ?>
            <?php if ($coverSrc !== ''): ?>
            <img src="<?= htmlspecialchars($coverSrc) ?>"
                 alt="<?= htmlspecialchars($service['title']) ?>"
                 onerror="this.parentElement.innerHTML='<div class=\'service-cover-placeholder\'><svg width=\'32\' height=\'32\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'1.5\'><rect x=\'3\' y=\'3\' width=\'18\' height=\'18\' rx=\'3\'/><circle cx=\'8.5\' cy=\'8.5\' r=\'1.5\'/><polyline points=\'21 15 16 10 5 21\'/></svg><span>Нет фото</span></div>'">
            <?php else: ?>
            <div class="service-cover-placeholder">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/>
                    <polyline points="21 15 16 10 5 21"/>
                </svg>
                <span>Нет фото</span>
            </div>
            <?php endif; ?>
        </div>

        <div class="service-body">
            <h2>
                <?php if ($isAdmin): ?>
                <span class="editable-field" data-id="<?= (int)$service['id'] ?>" data-field="title">
                    <span class="field-text"><?= htmlspecialchars($service['title']) ?></span>
                    <button class="pencil-btn" onclick="startEdit(this)" title="Редактировать">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                </span>
                <?php else: ?>
                <?= htmlspecialchars($service['title']) ?>
                <?php endif; ?>
            </h2>

            <?php if (!empty($service['description'])): ?>
            <p>
                <?php if ($isAdmin): ?>
                <span class="editable-field" data-id="<?= (int)$service['id'] ?>" data-field="description">
                    <span class="field-text"><?= htmlspecialchars($service['description']) ?></span>
                    <button class="pencil-btn" onclick="startEdit(this)" title="Редактировать">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                </span>
                <?php else: ?>
                <?= htmlspecialchars($service['description']) ?>
                <?php endif; ?>
            </p>
            <?php endif; ?>

            <?php
            $features = array_filter(array_map('trim', explode('|', (string)($service['features'] ?? ''))));
            if (!empty($features)):
            ?>
            <ul class="service-features">
                <?php foreach ($features as $feature): ?>
                <li><?= htmlspecialchars($feature) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <div class="service-footer">
                <div class="service-price">
                    <?php if ($isAdmin): ?>
                    <span class="editable-field" data-id="<?= (int)$service['id'] ?>" data-field="price_rub">
                        <span class="field-text"><?= (int)$service['price_rub'] ?></span>
                        <button class="pencil-btn" onclick="startEdit(this)" title="Редактировать">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                    </span> ₽
                    <small>
                        <span class="editable-field" data-id="<?= (int)$service['id'] ?>" data-field="price_uan">
                            <span class="field-text"><?= (int)$service['price_uan'] ?></span>
                            <button class="pencil-btn" onclick="startEdit(this)" title="Редактировать">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                        </span> ₴
                    </small>
                    <?php else: ?>
                    <?= (int)$service['price_rub'] ?> ₽
                    <small><?= (int)$service['price_uan'] ?> ₴</small>
                    <?php endif; ?>
                </div>
                <a href="order.php?service=<?= htmlspecialchars($service['category_key']) ?>" class="service-order">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                    </svg>
                    Заказать
                </a>
            </div>
        </div>
    </article>
    <?php endforeach; ?>
    </section>
</main>

<footer>
    <div class="container">© <?= date('Y') ?> Kostlim Design</div>
</footer>

<?php if ($isAdmin): ?>
<script>
// Тост-уведомление
function showToast(msg, ok = true) {
    const t = document.createElement('div');
    Object.assign(t.style, {
        position:'fixed', bottom:'30px', left:'50%', transform:'translateX(-50%)',
        background: ok ? '#f97316' : '#ef4444', color:'#fff',
        padding:'10px 22px', borderRadius:'9px', fontWeight:'800',
        fontSize:'13px', boxShadow:'0 0 20px rgba(249,115,22,.6)',
        zIndex:'9999', transition:'opacity .4s', fontFamily:'inherit'
    });
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity='0'; setTimeout(()=>t.remove(),400); }, 2000);
}

function startEdit(btn) {
    const wrap = btn.closest('.editable-field');
    const textEl = wrap.querySelector('.field-text');
    const field = wrap.dataset.field;
    const id    = wrap.dataset.id;
    const current = textEl.textContent.trim();

    const inp = document.createElement('input');
    inp.type  = 'text';
    inp.value = current;
    inp.className = 'edit-input-inline';

    textEl.style.display = 'none';
    btn.style.display = 'none';
    wrap.appendChild(inp);
    inp.focus();
    inp.select();

    const save = async () => {
        const newVal = inp.value.trim();
        if (newVal === current) { cancel(); return; }
        const fd = new FormData();
        fd.append('inline_edit_price', '1');
        fd.append('id',    id);
        fd.append('field', field);
        fd.append('value', newVal);
        const res = await fetch(location.href, { method:'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            textEl.textContent = newVal;
            showToast('✅ Сохранено!');
        } else {
            showToast('❌ Ошибка сохранения', false);
        }
        cancel();
    };
    const cancel = () => {
        inp.remove();
        textEl.style.display = '';
        btn.style.display = '';
    };
    inp.addEventListener('blur', save);
    inp.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); inp.blur(); }
        if (e.key === 'Escape') { inp.removeEventListener('blur', save); cancel(); }
    });
}
</script>
<?php endif; ?>
</body>
</html>