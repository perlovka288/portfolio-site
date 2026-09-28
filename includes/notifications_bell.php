<?php
/**
 * Колокольчик уведомлений закрытого раздела (Блок 5.2 ТЗ).
 * Подключение (после того как $access = resolvePpkAccess($pdo) уже вызван,
 * и внутри <header>, где угодно):
 *   <?php renderNotificationBell(); ?>
 * Сам список/счётчик тянется через notifications_api.php по AJAX — HTML
 * тут только рисует иконку и пустой контейнер дропдауна.
 */
function renderNotificationBell(): void
{
    static $assetsPrinted = false;
    ?>
    <div class="pnb-wrap">
        <button type="button" class="pnb-bell" id="pnbBellBtn" title="Уведомления">
            🔔<span class="pnb-badge" id="pnbBadge" style="display:none;">0</span>
        </button>
        <div class="pnb-dropdown" id="pnbDropdown">
            <div class="pnb-head">Уведомления</div>
            <div class="pnb-list" id="pnbList"><div class="pnb-empty">Загрузка…</div></div>
        </div>
    </div>
    <?php if (!$assetsPrinted): $assetsPrinted = true; ?>
    <style>
        .pnb-wrap { position: relative; display: inline-block; }
        .pnb-bell {
            position: relative; background: var(--card); border: 1px solid var(--border); color: var(--text);
            width: 38px; height: 38px; border-radius: 50%; font-size: 16px; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
        }
        .pnb-bell:hover { border-color: rgba(249,115,22,.4); }
        .pnb-badge {
            position: absolute; top: -3px; right: -3px; background: #ef4444; color: #fff; font-size: 10px;
            font-weight: 800; min-width: 16px; height: 16px; border-radius: 999px; display: flex;
            align-items: center; justify-content: center; padding: 0 3px; line-height: 1;
        }
        .pnb-dropdown {
            display: none; position: absolute; top: 46px; right: 0; width: 320px; max-height: 400px;
            overflow-y: auto; background: var(--card); border: 1px solid var(--border); border-radius: 14px;
            box-shadow: 0 12px 30px rgba(0,0,0,.4); z-index: 200;
        }
        .pnb-dropdown.show { display: block; }
        .pnb-head { padding: 12px 16px; font-weight: 800; font-size: 13px; border-bottom: 1px solid var(--border); }
        .pnb-item { display: block; padding: 12px 16px; border-bottom: 1px solid var(--border); text-decoration: none; color: inherit; }
        .pnb-item:last-child { border-bottom: none; }
        .pnb-item:hover { background: rgba(249,115,22,.06); }
        .pnb-item.unread { background: rgba(249,115,22,.05); }
        .pnb-item-title { font-size: 12.5px; font-weight: 700; color: var(--text); margin-bottom: 3px; }
        .pnb-item-body { font-size: 12px; color: var(--text2); line-height: 1.4; }
        .pnb-item-time { font-size: 10.5px; color: var(--text2); opacity: .7; margin-top: 4px; }
        .pnb-empty { padding: 24px 16px; text-align: center; color: var(--text2); font-size: 12.5px; }
        @media (max-width: 420px) { .pnb-dropdown { width: 88vw; right: -8px; } }
    </style>
    <script>
    (function() {
        const bellBtn = document.getElementById('pnbBellBtn');
        const dropdown = document.getElementById('pnbDropdown');
        const badge = document.getElementById('pnbBadge');
        const list = document.getElementById('pnbList');
        let loaded = false;

        function timeAgo(iso) {
            const diff = (Date.now() - new Date(iso.replace(' ', 'T') + 'Z')) / 1000;
            if (diff < 60) return 'только что';
            if (diff < 3600) return Math.floor(diff / 60) + ' мин назад';
            if (diff < 86400) return Math.floor(diff / 3600) + ' ч назад';
            return Math.floor(diff / 86400) + ' дн назад';
        }
        function esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

        async function loadNotifications() {
            try {
                const res = await fetch('notifications_api.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action:'list'}) });
                const r = await res.json();
                if (!r.ok) return;
                badge.style.display = r.unread > 0 ? 'flex' : 'none';
                badge.textContent = r.unread > 9 ? '9+' : r.unread;
                if (!r.notifications.length) {
                    list.innerHTML = '<div class="pnb-empty">Пока ничего нет</div>';
                    return;
                }
                list.innerHTML = r.notifications.map(n => `
                    <a href="${esc(n.link || '#')}" class="pnb-item ${!n.read_at ? 'unread' : ''}">
                        <div class="pnb-item-title">${esc(n.title)}</div>
                        <div class="pnb-item-body">${esc(n.body)}</div>
                        <div class="pnb-item-time">${timeAgo(n.created_at)}</div>
                    </a>
                `).join('');
            } catch (e) {}
        }

        bellBtn.addEventListener('click', async (e) => {
            e.stopPropagation();
            dropdown.classList.toggle('show');
            if (dropdown.classList.contains('show')) {
                if (!loaded) { await loadNotifications(); loaded = true; }
                fetch('notifications_api.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action:'mark_read'}) })
                    .then(() => { badge.style.display = 'none'; });
            }
        });
        document.addEventListener('click', (e) => { if (!dropdown.contains(e.target)) dropdown.classList.remove('show'); });
        loadNotifications();
    })();
    </script>
    <?php endif;
}
