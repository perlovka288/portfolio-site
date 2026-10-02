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
require_once __DIR__ . '/includes/notifications_lib.php';
require_once __DIR__ . '/includes/notifications_bell.php';

ensureNotificationsSchema($pdo);
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Приват Пак — Kostlim Design</title>
    <link rel="icon" type="image/png" href="/assets/img/logo.png" sizes="16x16">
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
    <?php include __DIR__ . '/includes/ui_head.php'; ?>
</head>
<body class="kui">
<?php
    // ── KUI: оболочка (меню ПК / шапка + нижнее меню) ──
    $kuiActive = 'ppk';
    $isLinked  = !empty($tgProfile);
    include __DIR__ . '/includes/ui_shell.php';

    $ppkName   = htmlspecialchars((string)($tgProfile['tg_first_name'] ?? 'Вы'));
    $ppkHandle = !empty($tgProfile['tg_username']) ? '@' . htmlspecialchars((string)$tgProfile['tg_username']) : '';
    $ppkAva    = htmlspecialchars(imgSrcPpk($tgProfile['tg_photo_url'] ?? ''));
    $ppkLetter = htmlspecialchars(mb_strtoupper(mb_substr((string)($tgProfile['tg_first_name'] ?? 'K'), 0, 1)));
?>

<section class="kui-hero-row kui-ppk-hero-row">
    <div class="kui-hero kui-ppk-hero">
        <?php if ($isPackDesigner): ?>
            <div class="kui-ppk-bell"><?php renderNotificationBell(); ?></div>
            <div class="kui-hero-head">
                <img class="kui-hero-ava" src="<?= $ppkAva ?>" alt="" onerror="this.src='/assets/img/logo.png'">
                <div>
                    <small>🔒 Приват Пак</small>
                    <h2><?= $ppkName ?></h2>
                    <?php if ($ppkHandle): ?><div class="kui-ppk-handle"><?= $ppkHandle ?></div><?php endif; ?>
                </div>
            </div>
            <div class="kui-ppk-badges">
                <?php if ($isAdmin): ?><span class="kui-ppk-badge admin">⚡ Admin</span><?php endif; ?>
                <span class="kui-ppk-badge">🎨 PPK</span>
            </div>
        <?php else: ?>
            <div class="kui-hero-head">
                <img class="kui-hero-ava" src="/assets/img/logo.png" alt="">
                <div><small>Закрытый раздел</small><h2>Приват Пак</h2></div>
            </div>
            <div class="kui-ppk-badges"><span class="kui-ppk-badge">🔒 Доступ после покупки пака</span></div>
        <?php endif; ?>
    </div>
    <div class="kui-promo kui-ppk-promo">
        <p><b>Материалы и инструменты</b>для дизайнеров пака: исходники, шрифты, кисти и ИИ-тренажёр</p>
        <a class="kui-btn" href="support.php">Поддержка</a>
    </div>
</section>

<main class="kui-main kui-ppk-main">

<?php if ($isPackDesigner): ?>

    <h2 class="kui-h2">Материалы</h2>
    <div class="kui-ppk-grid">
        <a href="resources.php" class="kui-ppk-tile">
            <span class="kui-ppk-ic">📁</span>
            <span class="kui-ppk-txt"><b>PSD-паки, шрифты, кисти и SD</b><small>Все ресурсы пака в одном разделе</small></span>
            <span class="kui-ppk-go">›</span>
        </a>
        <a href="useful.php" class="kui-ppk-tile">
            <span class="kui-ppk-ic">📚</span>
            <span class="kui-ppk-txt"><b>Полезности</b><small>Статьи и гайды от Kostlim, с комментариями</small></span>
            <span class="kui-ppk-go">›</span>
        </a>
    </div>

    <h2 class="kui-h2">Инструменты</h2>
    <div class="kui-ppk-grid">
        <a href="ai_trainer.php" class="kui-ppk-tile primary">
            <span class="kui-ppk-ic">🎮</span>
            <span class="kui-ppk-txt"><b>Тренировка общения с клиентом</b><small>Отыграй заказ от анкеты до сдачи — ИИ в роли заказчика</small></span>
            <span class="kui-ppk-go">›</span>
        </a>
        <a href="planner.php" class="kui-ppk-tile">
            <span class="kui-ppk-ic">🗂</span>
            <span class="kui-ppk-txt"><b>Личный планер клиентов</b><small>Учёт заказов: статус, дедлайн, сумма</small></span>
            <span class="kui-ppk-go">›</span>
        </a>
    </div>

    <?php if ($isAdmin): ?>
    <h2 class="kui-h2">Для администратора</h2>
    <div class="kui-ppk-grid">
        <a href="admin/ppk_manager.php" class="kui-ppk-tile">
            <span class="kui-ppk-ic">🛠</span>
            <span class="kui-ppk-txt"><b>Управление доступом PPK</b><small>Ручная выдача роли, ключи активации</small></span>
            <span class="kui-ppk-tag">ADMIN</span>
        </a>
        <a href="admin/ai_trainer_review.php" class="kui-ppk-tile">
            <span class="kui-ppk-ic">📨</span>
            <span class="kui-ppk-txt"><b>Результаты тренажёра</b><small>Что прислали дизайнеры на проверку</small></span>
            <span class="kui-ppk-tag">ADMIN</span>
        </a>
    </div>
    <?php endif; ?>

    <div class="kui-card kui-ppk-note">Материалы обновляются в приватном Telegram-канале — если чего-то не хватает, напишите в поддержку.</div>

<?php else: ?>

    <div class="kui-card accent kui-ppk-lock">
        <div class="kui-ppk-lock-ic">🔒</div>
        <h2>Доступно владельцам пака</h2>
        <p>После покупки пака открывается всё это в одном месте:</p>
        <div class="kui-ppk-chips">
            <span>PSD-исходники</span><span>Шрифты</span><span>Кисти и стили</span><span>Гайд по Stable Diffusion</span><span>ИИ-тренажёр клиента</span><span>Личный планер заказов</span>
        </div>
        <a href="https://t.me/Perlo_ovka" target="_blank" rel="noopener" class="kui-btn block">🛒 Приобрести пак</a>
    </div>

    <div class="kui-card kui-ppk-key">
        <b>Уже купили пак и получили код?</b>
        <div class="kui-ppk-key-row">
            <input class="kui-field" type="text" id="ppkKeyInput" placeholder="PPK-XXXX-XXXX" autocomplete="off">
            <button class="kui-btn" type="button" id="ppkKeyBtn">Активировать</button>
        </div>
        <div class="kui-ppk-key-msg" id="ppkKeyMsg"></div>
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
</main>
<script src="/assets/kostlim-ui.js?v=<?= @filemtime(__DIR__ . '/assets/kostlim-ui.js') ?: time() ?>"></script>
</body>
</html>
