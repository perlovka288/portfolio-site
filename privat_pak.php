<?php
/**
 * Приват Пак (PPK) — единый хаб закрытого раздела.
 * Визуально — те же реальные классы, что и в support.php (support-wrap,
 * support-admin-card, support-action-btn и т.д.), поэтому стиль 1-в-1
 * совпадает с остальным сайтом без выдумывания новых классов "на глаз".
 *
 * Для тех, у кого нет доступа — тут же превью-модалка с описанием пака,
 * кнопкой покупки и полем активации ключа (вместо голого 403).
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/ppk_access.php';

$access = resolvePpkAccess($pdo);
$isAdmin = $access['isAdmin'];
$isPackDesigner = $access['isPackDesigner'];
$tgProfile = $access['tgProfile'];

function imgSrcPpk(?string $url): string
{
    $url = trim((string)$url);
    return $url !== '' ? $url : '/assets/img/default_avatar.png';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Приват Пак | Kostlim Design</title>
    <link rel="icon" type="image/png" href="/assets/img/logo.png" sizes="16x16">
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<style>
/* Тот же язык, что и support.php — используем родные классы сайта, плюс
   несколько новых, названных так же аккуратно (pp- префикс), чтобы не
   пересекаться ни с чем существующим. */
body::before {
    content:'';position:fixed;top:-120px;left:50%;transform:translateX(-50%);
    width:700px;height:400px;background:radial-gradient(ellipse at center,rgba(249,115,22,0.13) 0%,transparent 70%);
    pointer-events:none;z-index:0;
}
.support-wrap { max-width: 560px; margin: 0 auto; padding: 26px 20px 70px; position: relative; z-index: 1; }
.support-title { font-size: 20px; font-weight: 900; margin-bottom: 4px; display:flex; align-items:center; gap:8px; }
.support-sub { color: var(--text2); font-size: 13px; margin-bottom: 26px; }
.support-admin-card {
    display: flex; align-items: center; gap: 14px;
    background: var(--card); border: 1px solid var(--border);
    border-radius: 20px; padding: 18px 20px; margin-bottom: 22px;
}
.support-admin-ava, .support-admin-ava-fallback {
    width: 56px; height: 56px; border-radius: 50%; object-fit: cover;
    border: 2px solid var(--border-accent); flex-shrink: 0;
}
.support-admin-ava-fallback {
    background: linear-gradient(135deg, var(--accent2), var(--accent));
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; font-weight: 900; color: #fff;
}
.support-admin-name { font-size: 15px; font-weight: 800; color: var(--text); }
.support-admin-handle { font-size: 12px; color: var(--text2); }
.pp-badges { margin-left: auto; display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
.role-badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 9px; font-weight: 900; letter-spacing: .5px; text-transform: uppercase;
    padding: 3px 8px; border-radius: 6px; border: 1px solid var(--border-accent);
}
.role-badge--admin { color: #4ade80; background: rgba(74,222,128,.12); border-color: rgba(74,222,128,.35); }
.role-badge--ppk { color: var(--accent3); background: var(--accent-dim); border-color: var(--border-accent); }

.support-actions { display: flex; flex-direction: column; gap: 10px; margin-bottom: 22px; }
.support-action-btn {
    display: flex; align-items: center; gap: 12px;
    background: var(--card); border: 1px solid var(--border);
    border-radius: 16px; padding: 16px 18px;
    color: var(--text); font-size: 14px; font-weight: 700;
    transition: all var(--t); cursor: pointer; font-family: inherit; text-align: left; width: 100%;
    text-decoration: none;
}
.support-action-btn:hover { border-color: var(--border-accent); background: var(--accent-dim); transform: translateY(-1px); }
.support-action-icon {
    width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 18px;
    background: var(--accent-dim); color: var(--accent);
}
.support-action-text { display: flex; flex-direction: column; gap: 2px; }
.support-action-sub { font-size: 11.5px; font-weight: 500; color: var(--text2); }
.support-action-btn.primary {
    background: linear-gradient(135deg, var(--accent2), var(--accent));
    border-color: transparent; color: #fff;
    box-shadow: inset 0 1px rgba(255,255,255,.22), var(--shadow-accent);
}
.support-action-btn.primary .support-action-icon { background: rgba(255,255,255,.18); color: #fff; }
.support-action-btn.primary .support-action-sub { color: rgba(255,255,255,.8); }
.support-action-badge {
    margin-left: auto; flex-shrink: 0; font-size: 9px; font-weight: 900; text-transform: uppercase;
    background: rgba(255,255,255,.15); padding: 3px 7px; border-radius: 6px;
}

.pp-section-label { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; color: var(--text2); margin: 22px 0 10px; }

.support-note {
    background: var(--accent-dim); border: 1px solid var(--border-accent);
    border-radius: 14px; padding: 14px 16px; color: var(--text2); font-size: 12.5px; line-height: 1.6;
}

/* ── Состояние "нет доступа" ── */
.pp-locked-card {
    background: var(--card); border: 1px solid var(--border); border-radius: 20px;
    padding: 26px 22px; text-align: center; margin-bottom: 20px;
}
.pp-locked-card .pp-lock-icon { font-size: 40px; margin-bottom: 10px; }
.pp-locked-card h2 { margin: 0 0 8px; font-size: 18px; }
.pp-locked-card p { color: var(--text2); font-size: 13px; line-height: 1.6; margin: 0 0 18px; }
.pp-buy-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; box-sizing: border-box; background: linear-gradient(135deg, var(--accent2), var(--accent));
    color: #fff; padding: 15px; border-radius: 12px; border: none; font-size: 13px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 1px; text-decoration: none; box-shadow: var(--shadow-accent);
    transition: all var(--t);
}
.pp-buy-btn:hover { transform: translateY(-2px); }
.pp-key-row { display: flex; gap: 8px; margin-top: 16px; }
.pp-key-row input {
    flex: 1; background: rgba(0,0,0,.15); border: 1px solid var(--border); color: var(--text);
    padding: 11px 13px; border-radius: 10px; font-family: inherit; font-size: 13px;
}
.pp-key-row button {
    background: var(--card2, rgba(255,255,255,.06)); border: 1px solid var(--border); color: var(--text);
    padding: 0 16px; border-radius: 10px; cursor: pointer; font-weight: 700; font-size: 12.5px; white-space: nowrap;
}
.pp-key-msg { font-size: 12px; margin-top: 8px; min-height: 16px; }
</style>
</head>
<body>

<header class="header-compact">
    <div class="brand-title"><a href="index.php"><img src="/assets/img/logo.png" class="brand-logo-img" alt="Kostlim Design" style="height:34px;width:auto;max-width:140px;display:block;margin:0 auto;"></a></div>
</header>

<div class="quick-actions-grid">
    <a href="index.php" class="quick-action-btn quick-action-btn-wide" title="На главную">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        На главную
    </a>
</div>

<div class="support-wrap">
    <div class="support-title">🔒 Приват Пак</div>
    <div class="support-sub">Материалы и инструменты для дизайнеров пака.</div>

    <?php if ($isPackDesigner): ?>

        <div class="support-admin-card">
            <?php $ava = imgSrcPpk($tgProfile['tg_photo_url'] ?? ''); ?>
            <img src="<?= htmlspecialchars($ava) ?>" class="support-admin-ava" alt="Аватар" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
            <div class="support-admin-ava-fallback" style="display:none;"><?= mb_strtoupper(mb_substr((string)($tgProfile['tg_first_name'] ?? 'K'), 0, 1)) ?></div>
            <div>
                <div class="support-admin-name"><?= htmlspecialchars($tgProfile['tg_first_name'] ?? 'Вы') ?></div>
                <?php if (!empty($tgProfile['tg_username'])): ?><div class="support-admin-handle">@<?= htmlspecialchars($tgProfile['tg_username']) ?></div><?php endif; ?>
            </div>
            <div class="pp-badges">
                <?php if ($isAdmin): ?><span class="role-badge role-badge--admin">⚡ Admin</span><?php endif; ?>
                <span class="role-badge role-badge--ppk">🎨 PPK</span>
            </div>
        </div>

        <div class="pp-section-label">Материалы</div>
        <div class="support-actions">
            <a href="resources.php" class="support-action-btn">
                <span class="support-action-icon">📁</span>
                <span class="support-action-text">
                    PSD-паки, шрифты, кисти и SD
                    <span class="support-action-sub">Все ресурсы пака в одном разделе</span>
                </span>
            </a>
        </div>

        <div class="pp-section-label">Инструменты</div>
        <div class="support-actions">
            <a href="ai_trainer.php" class="support-action-btn primary">
                <span class="support-action-icon">🎮</span>
                <span class="support-action-text">
                    Тренировка общения с клиентом
                    <span class="support-action-sub">Отыграй заказ от анкеты до сдачи — ИИ в роли заказчика</span>
                </span>
            </a>
            <a href="planner.php" class="support-action-btn">
                <span class="support-action-icon">🗂</span>
                <span class="support-action-text">
                    Личный планер клиентов
                    <span class="support-action-sub">Учёт заказов: статус, дедлайн, сумма</span>
                </span>
            </a>
            <?php if ($isAdmin): ?>
            <a href="admin/ppk_manager.php" class="support-action-btn">
                <span class="support-action-icon">🛠</span>
                <span class="support-action-text">
                    Управление доступом PPK
                    <span class="support-action-sub">Ручная выдача роли, ключи активации</span>
                </span>
                <span class="support-action-badge">ADMIN</span>
            </a>
            <a href="admin/ai_trainer_review.php" class="support-action-btn">
                <span class="support-action-icon">📨</span>
                <span class="support-action-text">
                    Результаты тренажёра
                    <span class="support-action-sub">Что прислали дизайнеры на проверку</span>
                </span>
                <span class="support-action-badge">ADMIN</span>
            </a>
            <?php endif; ?>
        </div>

        <div class="support-note">Материалы обновляются в приватном Telegram-канале — если чего-то не хватает, напишите в поддержку.</div>

    <?php else: ?>

        <div class="pp-locked-card">
            <div class="pp-lock-icon">🔒</div>
            <h2>Доступно владельцам пака</h2>
            <p>PSD-исходники, шрифты, кисти и стили, гайд по Stable Diffusion, ИИ-тренажёр общения с клиентом и личный планер заказов — всё в одном месте после покупки пака.</p>
            <a href="https://t.me/Perlo_ovka" target="_blank" class="pp-buy-btn">🛒 Приобрести пак</a>
        </div>

        <div class="support-note">
            Уже купили пак и получили код?
            <div class="pp-key-row">
                <input type="text" id="ppkKeyInput" placeholder="PPK-XXXX-XXXX">
                <button type="button" id="ppkKeyBtn">Активировать</button>
            </div>
            <div class="pp-key-msg" id="ppkKeyMsg"></div>
        </div>

        <script>
        document.getElementById('ppkKeyBtn').onclick = async function () {
            var input = document.getElementById('ppkKeyInput');
            var msg = document.getElementById('ppkKeyMsg');
            this.disabled = true;
            try {
                const res = await fetch('activate_ppk_key.php', {
                    method: 'POST', headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({ code: input.value })
                });
                const r = await res.json();
                msg.style.color = r.ok ? '#4ade80' : '#ef4444';
                msg.textContent = r.ok ? 'Готово! Обновляем страницу…' : (r.error || 'Ошибка');
                if (r.ok) setTimeout(() => location.reload(), 1000);
            } catch (e) {
                msg.style.color = '#ef4444';
                msg.textContent = 'Ошибка сети, попробуйте ещё раз.';
            }
            this.disabled = false;
        };
        </script>

    <?php endif; ?>
</div>

</body>
</html>
