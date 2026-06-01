<?php
session_start();
require_once 'config/db.php';

define('ADMIN_TG_ID', '1710365896');
if (!empty($_GET['tg_id']) && $_GET['tg_id'] === ADMIN_TG_ID) {
    $_SESSION['admin_logged'] = true;
}
$isAdmin = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;

// AJAX сохранение всего прайса одним запросом
if ($isAdmin && isset($_POST['save_all_inline'])) {
    header('Content-Type: application/json');
    $items = json_decode($_POST['items'] ?? '[]', true);
    foreach ($items as $item) {
        $id = (int)($item['id'] ?? 0);
        if (!$id) continue;
        // Фичи: строки → через |
        $featuresRaw = $item['features'] ?? '';
        $featuresArr = array_filter(array_map('trim', explode("\n", $featuresRaw)));
        $features    = implode('|', $featuresArr);
        $pdo->prepare("UPDATE prices SET title=?, description=?, price_rub=?, price_uan=?, features=? WHERE id=?")
            ->execute([
                $item['title']     ?? '',
                $item['desc']      ?? '',
                (int)($item['rub'] ?? 0),
                (int)($item['uan'] ?? 0),
                $features,
                $id
            ]);
    }
    echo json_encode(['ok' => true]);
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
body::before {
    content: '';
    position: fixed; top: -100px; left: 50%; transform: translateX(-50%);
    width: 600px; height: 350px;
    background: radial-gradient(ellipse, rgba(249,115,22,0.10) 0%, transparent 70%);
    pointer-events: none; z-index: 0;
}
.price-page { position: relative; z-index: 1; }

/* Кнопка режима редактирования */
.edit-mode-btn {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--card); border: 1px solid var(--border);
    color: var(--text2); padding: 10px 18px; border-radius: 9px;
    font-size: 12px; font-weight: 800; cursor: pointer;
    font-family: inherit; text-transform: uppercase; letter-spacing: .5px;
    transition: all .22s;
}
.edit-mode-btn:hover {
    border-color: rgba(249,115,22,.4);
    color: var(--accent); background: rgba(249,115,22,.08);
}
.edit-mode-btn.active {
    background: linear-gradient(135deg, #fb923c, #f97316);
    border-color: transparent; color: #fff;
    box-shadow: 0 0 20px rgba(249,115,22,.4);
}
.edit-mode-btn svg { width: 14px; height: 14px; }

/* Полоска уведомления */
.edit-banner {
    display: none;
    background: rgba(249,115,22,0.10);
    border: 1px solid rgba(249,115,22,0.3);
    border-radius: 10px; padding: 11px 18px;
    color: var(--accent2); font-size: 12px; font-weight: 700;
    text-align: center; margin-bottom: 22px;
    align-items: center; justify-content: center; gap: 14px;
    flex-wrap: wrap;
}
.edit-banner.show { display: flex; }

/* Сохранить кнопка в баннере */
.save-all-btn {
    display: inline-flex; align-items: center; gap: 7px;
    background: linear-gradient(135deg, #fb923c, #f97316);
    border: none; border-radius: 8px; padding: 9px 20px;
    color: #fff; font-size: 12px; font-weight: 800;
    text-transform: uppercase; letter-spacing: .7px;
    cursor: pointer; font-family: inherit;
    box-shadow: 0 4px 14px rgba(249,115,22,.3);
    transition: all .2s;
}
.save-all-btn:hover { opacity: .88; transform: translateY(-1px); }
.save-all-btn svg { width: 13px; height: 13px; }

/* Редактируемые поля */
.ef {
    display: inline; cursor: default;
}
.edit-mode .ef {
    cursor: text;
    border-bottom: 1.5px dashed rgba(249,115,22,.45);
    border-radius: 3px;
    padding: 1px 3px;
    transition: background .15s, border-color .15s;
    outline: none;
}
.edit-mode .ef:focus {
    background: rgba(249,115,22,.1);
    border-color: var(--accent);
    border-bottom-style: solid;
    box-shadow: 0 2px 8px rgba(249,115,22,.2);
}
.edit-mode .service-card {
    border-color: rgba(249,115,22,.2);
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
        <?php if ($isAdmin): ?>
        <button class="edit-mode-btn" id="editToggle" onclick="toggleEditMode()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Редактировать
        </button>
        <?php endif; ?>
        <a href="https://t.me/kostlimdznbot" target="_blank" class="nav-link nav-bot">
            <span class="icon"></span>
            Бот для заказов
        </a>
        <a href="order.php" class="nav-link" style="background:linear-gradient(135deg,var(--accent2),var(--accent));color:#fff;border-color:transparent;box-shadow:0 0 16px rgba(249,115,22,.3);">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
            К заказу
        </a>
    </div>
</header>

<main class="container price-page" id="priceMain">
    <div class="price-head">
        <h1>Прайс-лист</h1>
        <p>Все услуги подтягиваются из админ-панели и сразу доступны в Telegram-боте.</p>
    </div>

    <?php if ($isAdmin): ?>
    <div class="edit-banner" id="editBanner">
        <span>✏️ Режим редактирования — кликай на любой текст или цену и меняй прямо здесь</span>
        <button class="save-all-btn" onclick="saveAll()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Сохранить всё
        </button>
        <button class="save-all-btn" style="background:#1e1e2a;box-shadow:none;border:1px solid #2a2a38;" onclick="toggleEditMode()">Отмена</button>
    </div>
    <?php endif; ?>

    <section class="price-grid-local">
    <?php foreach ($services as $service): ?>
    <article class="service-card" data-id="<?= (int)$service['id'] ?>">
        <div class="service-cover">
            <?php $coverSrc = imgSrc($service['image'] ?? ''); ?>
            <?php if ($coverSrc !== ''): ?>
            <img src="<?= htmlspecialchars($coverSrc) ?>" alt="<?= htmlspecialchars($service['title']) ?>"
                 onerror="this.parentElement.innerHTML='<div class=\'service-cover-placeholder\'><svg width=\'32\' height=\'32\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'1.5\'><rect x=\'3\' y=\'3\' width=\'18\' height=\'18\' rx=\'3\'/><circle cx=\'8.5\' cy=\'8.5\' r=\'1.5\'/><polyline points=\'21 15 16 10 5 21\'/></svg><span>Нет фото</span></div>'">
            <?php else: ?>
            <div class="service-cover-placeholder">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                <span>Нет фото</span>
            </div>
            <?php endif; ?>
        </div>

        <div class="service-body">
            <h2><span class="ef" data-field="title" contenteditable="false"><?= htmlspecialchars($service['title']) ?></span></h2>
            <p><span class="ef" data-field="desc" contenteditable="false"><?= htmlspecialchars($service['description'] ?? '') ?></span></p>

            <?php
            $features = array_filter(array_map('trim', explode('|', (string)($service['features'] ?? ''))));
            $featuresRaw = implode("\n", $features);
            ?>
            <!-- Фичи: в обычном режиме — список, в режиме редактирования — textarea -->
            <ul class="service-features features-view">
                <?php foreach ($features as $feature): ?>
                <li><?= htmlspecialchars($feature) ?></li>
                <?php endforeach; ?>
                <?php if (empty($features)): ?><li style="opacity:.4;font-style:italic;">Нет фич</li><?php endif; ?>
            </ul>
            <textarea class="features-edit ef" data-field="features" style="display:none;width:100%;background:#0e0e16;border:1px solid rgba(249,115,22,.4);color:#e0e0ec;border-radius:8px;padding:10px;font-size:12px;font-family:inherit;resize:vertical;min-height:80px;box-sizing:border-box;line-height:1.6;" placeholder="Каждая фича с новой строки&#10;Например:&#10;PSD-файл&#10;2 правки&#10;Быстрая сдача"><?= htmlspecialchars($featuresRaw) ?></textarea>

            <div class="service-footer">
                <div class="service-price">
                    <span class="ef" data-field="rub" contenteditable="false"><?= (int)$service['price_rub'] ?></span> ₽
                    <small><span class="ef" data-field="uan" contenteditable="false"><?= (int)$service['price_uan'] ?></span> ₴</small>
                </div>
                <a href="order.php?service=<?= htmlspecialchars($service['category_key']) ?>" class="service-order">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    Заказать
                </a>
            </div>
        </div>
    </article>
    <?php endforeach; ?>
    </section>
</main>

<footer><div class="container">© <?= date('Y') ?> Kostlim Design</div></footer>

<?php if ($isAdmin): ?>
<script>
let editActive = false;

function toggleEditMode() {
    editActive = !editActive;
    const main   = document.getElementById('priceMain');
    const banner = document.getElementById('editBanner');
    const btn    = document.getElementById('editToggle');

    if (editActive) {
        main.classList.add('edit-mode');
        banner.classList.add('show');
        btn.classList.add('active');
        // Включаем contenteditable у текстовых полей (не textarea)
        document.querySelectorAll('.ef[data-field]:not(textarea)').forEach(el => el.setAttribute('contenteditable', 'true'));
        // Показываем textarea фич, скрываем ul
        document.querySelectorAll('.features-view').forEach(ul => ul.style.display = 'none');
        document.querySelectorAll('.features-edit').forEach(ta => { ta.style.display = 'block'; ta.setAttribute('contenteditable', 'false'); });
    } else {
        main.classList.remove('edit-mode');
        banner.classList.remove('show');
        btn.classList.remove('active');
        document.querySelectorAll('.ef[data-field]:not(textarea)').forEach(el => el.setAttribute('contenteditable', 'false'));
        // Восстанавливаем ul из textarea и скрываем textarea
        document.querySelectorAll('.service-card').forEach(card => {
            const ta = card.querySelector('.features-edit');
            const ul = card.querySelector('.features-view');
            if (!ta || !ul) return;
            const lines = ta.value.split('\n').map(s=>s.trim()).filter(Boolean);
            ul.innerHTML = lines.length
                ? lines.map(l => `<li>${l.replace(/</g,'&lt;')}</li>`).join('')
                : '<li style="opacity:.4;font-style:italic;">Нет фич</li>';
            ul.style.display = '';
            ta.style.display = 'none';
        });
    }
}

async function saveAll() {
    const cards = document.querySelectorAll('.service-card[data-id]');
    const items = [];
    cards.forEach(card => {
        const id = card.dataset.id;
        const get = (field) => (card.querySelector(`.ef[data-field="${field}"]`)?.innerText || '').trim();
        const features = (card.querySelector('.features-edit')?.value || '').trim();
        items.push({ id, title: get('title'), desc: get('desc'), rub: get('rub'), uan: get('uan'), features });
    });

    const fd = new FormData();
    fd.append('save_all_inline', '1');
    fd.append('items', JSON.stringify(items));

    const res  = await fetch(location.href, { method: 'POST', body: fd });
    const data = await res.json();

    const toast = document.createElement('div');
    toast.textContent = data.ok ? '✅ Прайс сохранён!' : '❌ Ошибка сохранения';
    Object.assign(toast.style, {
        position:'fixed', bottom:'30px', left:'50%', transform:'translateX(-50%)',
        background: data.ok ? 'linear-gradient(135deg,#fb923c,#f97316)' : '#ef4444',
        color:'#fff', padding:'11px 24px', borderRadius:'10px',
        fontWeight:'800', fontSize:'13px', fontFamily:'inherit',
        boxShadow:'0 0 22px rgba(249,115,22,.6)', zIndex:'9999', transition:'opacity .4s'
    });
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.opacity='0'; setTimeout(()=>toast.remove(),400); }, 2500);

    if (data.ok) toggleEditMode();
}
</script>
<?php endif; ?>
</body>
</html>