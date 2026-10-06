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
require_once __DIR__ . '/includes/ppk_purchase.php';
require_once __DIR__ . '/includes/notifications_lib.php';
require_once __DIR__ . '/includes/notifications_bell.php';
require_once __DIR__ . '/includes/ppk_icons.php';

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
    <link rel="icon" type="image/png" href="/assets/img/logo-64.png" sizes="16x16">
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
    <?php include __DIR__ . '/includes/ui_head.php'; ?>
    <link rel="stylesheet" href="/assets/ppk-redesign.css?v=<?= @filemtime(__DIR__ . '/assets/ppk-redesign.css') ?: time() ?>">
    <?php @include __DIR__ . '/includes/icons_head.php'; ?>
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
    <!-- Баннер 1: профиль (animated-banner) -->
    <div class="rd-banner rd-banner--profile">
        <span class="rd-banner-fx" aria-hidden="true"></span>
        <span class="rd-banner-ov1" aria-hidden="true"></span><span class="rd-banner-ov2" aria-hidden="true"></span>
        <?php if ($isPackDesigner): ?>
            <div class="rd-banner-bell"><?php renderNotificationBell(); ?></div>
            <div class="rd-banner-in" style="flex-direction:column;align-items:flex-start;justify-content:flex-end">
                <div class="rd-prof">
                    <img src="<?= $ppkAva ?>" alt="" onerror="this.src='/assets/img/logo.webp'">
                    <div style="min-width:0">
                        <small>Приват Пак</small>
                        <h3><?= $ppkName ?></h3>
                        <?php if ($ppkHandle): ?><div class="h"><?= $ppkHandle ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="rd-chips">
                    <?php if ($isAdmin): ?><span class="hc hc--md hc--primary hc--default"><?= ppkIcon('bolt', 'ai--loop') ?>ADMIN</span><?php endif; ?>
                    <span class="hc hc--md hc--secondary hc--default"><?= ppkIcon('spark') ?>PPK</span>
                </div>
            </div>
        <?php else: ?>
            <div class="rd-banner-in" style="flex-direction:column;align-items:flex-start;justify-content:flex-end">
                <div class="rd-prof">
                    <img src="/assets/img/logo.webp" alt="">
                    <div><small>Закрытый раздел</small><h3>Приват Пак</h3></div>
                </div>
                <div class="rd-chips"><span class="hc hc--md hc--tertiary hc--default"><?= ppkIcon('lock', 'ai--loop') ?>Доступ после покупки пака</span></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Баннер 2: материалы (animated-banner с таймером).
         Хотите видео как в оригинале — положите assets/img/banner.mp4, оно подхватится само. -->
    <a class="rd-banner" href="support.php">
        <?php if (is_file(__DIR__ . '/assets/img/banner.mp4')): ?>
            <video aria-hidden="true" autoplay loop muted playsinline poster="/assets/img/KOSTLIM%20AI.jpg" src="/assets/img/banner.mp4"></video>
        <?php else: ?>
            <img class="rd-banner-img" src="/assets/img/kostlim-ai-banner.webp" alt="" aria-hidden="true" width="640" height="640" loading="lazy" decoding="async">
        <?php endif; ?>
        <span class="rd-banner-ov1" aria-hidden="true"></span><span class="rd-banner-ov2" aria-hidden="true"></span>
        <div class="rd-banner-in">
            <div class="rd-banner-txt">
                <h3>Материалы и инструменты</h3>
                <p>Исходники, шрифты, кисти и ИИ-тренажёр для дизайнеров пака.</p>
                <span class="rd-banner-cta">Поддержка <?= ppkIcon('arrow') ?></span>
            </div>
            <div class="rd-count" id="rdCount" aria-hidden="true" title="До конца месяца"><span><b data-u="d">00</b><i>:</i></span><span><b data-u="h">00</b><i>:</i></span><span><b data-u="m">00</b><i>:</i></span><span><b data-u="s">00</b></span></div>
        </div>
    </a>
</section>

<main class="kui-main kui-ppk-main">

<?php if ($isPackDesigner): ?>

    <h2 class="kui-h2">Материалы</h2>
    <div class="rd-svc-grid">
        <a href="resources.php" class="rd-svc rd-svc--o" title="Все ресурсы пака в одном разделе">
            <h3 class="rd-svc-title">PSD-паки, шрифты, кисти и SD</h3>
            <span class="rd-svc-more"><span>Открыть</span><?= ppkIcon('arrow') ?></span>
            <img class="rd-svc-img" src="/assets/img/PSD.webp" alt="" width="158" height="158" loading="lazy" decoding="async">
            
        </a>
        <a href="useful.php" class="rd-svc rd-svc--d" title="Статьи и гайды от Kostlim, с комментариями">
            <h3 class="rd-svc-title">Полезности</h3>
            <span class="rd-svc-more"><span>Открыть</span><?= ppkIcon('arrow') ?></span>
            <img class="rd-svc-img" src="/assets/img/MAT.webp" alt="" width="158" height="158" loading="lazy" decoding="async">
            
        </a>
    </div>

    <h2 class="kui-h2">Инструменты</h2>
    <div class="rd-svc-grid">
        <a href="ai_trainer.php" class="rd-svc rd-svc--g" title="Отыграй заказ от анкеты до сдачи — ИИ в роли заказчика">
            <h3 class="rd-svc-title">Тренировка общения с клиентом</h3>
            <span class="rd-svc-more"><span>Открыть</span><?= ppkIcon('arrow') ?></span>
            <img class="rd-svc-img" src="/assets/img/TREN.webp" alt="" width="158" height="158" loading="lazy" decoding="async">
            
        </a>
        <a href="planner.php" class="rd-svc rd-svc--a" title="Учёт заказов: статус, дедлайн, сумма">
            <h3 class="rd-svc-title">Личный планер клиентов</h3>
            <span class="rd-svc-more"><span>Открыть</span><?= ppkIcon('arrow') ?></span>
            <img class="rd-svc-img" src="/assets/img/PLANER.webp" alt="" width="158" height="158" loading="lazy" decoding="async">
            
        </a>
    </div>

    <?php if ($isAdmin): ?>
    <h2 class="kui-h2">Для администратора</h2>
    <div class="rd-svc-grid">
        <a href="admin/ppk_manager.php" class="rd-svc rd-svc--d" title="Ручная выдача роли, ключи активации">
            <h3 class="rd-svc-title">Управление доступом PPK</h3>
            <span class="rd-svc-more"><span>Открыть</span><?= ppkIcon('arrow') ?></span>
            <img class="rd-svc-img" src="/assets/img/DOSTUP.webp" alt="" width="158" height="158" loading="lazy" decoding="async">
            <span class="hc hc--sm hc--primary hc--default"><?= ppkIcon('bolt', 'ai--loop') ?>ADMIN</span>
        </a>
        <a href="admin/ai_trainer_review.php" class="rd-svc rd-svc--o" title="Что прислали дизайнеры на проверку">
            <h3 class="rd-svc-title">Результаты тренажёра</h3>
            <span class="rd-svc-more"><span>Открыть</span><?= ppkIcon('arrow') ?></span>
            <img class="rd-svc-img" src="/assets/img/RESULT.webp" alt="" width="158" height="158" loading="lazy" decoding="async">
            <span class="hc hc--sm hc--primary hc--default"><?= ppkIcon('bolt', 'ai--loop') ?>ADMIN</span>
        </a>
    </div>
    <?php endif; ?>

    <?php
        // Одноразовая ссылка в приватный чат — показываем владельцу, пока она не использована
        $__ppkBuy = (!$isAdmin && !empty($tgProfile['tg_id'])) ? ppkApprovedPurchase($pdo, (string)$tgProfile['tg_id']) : null;
        if ($__ppkBuy && !$__ppkBuy['invite_used'] && $__ppkBuy['invite_link'] !== ''):
    ?>
    <div class="kui-card accent">
        <b>🔗 Вступить в приватный Telegram-чат</b>
        <p style="margin:6px 0 10px;color:#c9c9cf;font-size:13.5px">Ссылка одноразовая: после вступления она перестанет работать.</p>
        <a class="kui-btn block" href="<?= htmlspecialchars($__ppkBuy['invite_link']) ?>" target="_blank" rel="noopener">Войти в чат</a>
    </div>
    <?php endif; ?>

    <div class="kui-card kui-ppk-note">Материалы обновляются в приватном Telegram-канале — если чего-то не хватает, напишите в поддержку.</div>

<?php else: ?>

    <div class="kui-card accent kui-ppk-lock">
        <div class="rd-lock-ic"><?= ppkIcon('lock', 'ai--loop') ?></div>
        <h2>Доступно владельцам пака</h2>
        <p>После покупки пака открывается всё это в одном месте:</p>
        <div class="kui-ppk-chips">
            <span>PSD-исходники</span><span>Шрифты</span><span>Кисти и стили</span><span>Гайд по Stable Diffusion</span><span>ИИ-тренажёр клиента</span><span>Личный планер заказов</span>
        </div>
        <a href="buy_pack.php" class="kui-btn block">🛒 Купить пак</a>
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
<script>
// Таймер в баннере — обратный отсчёт до конца месяца (Д : Ч : М : С)
(function () {
    var box = document.getElementById('rdCount');
    if (!box) return;
    var el = {};
    box.querySelectorAll('b[data-u]').forEach(function (b) { el[b.dataset.u] = b; });
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function tick() {
        var now = new Date(), end = new Date(now.getFullYear(), now.getMonth() + 1, 1, 0, 0, 0);
        var t = Math.max(0, Math.floor((end - now) / 1000));
        el.d.textContent = pad(Math.floor(t / 86400));
        el.h.textContent = pad(Math.floor(t % 86400 / 3600));
        el.m.textContent = pad(Math.floor(t % 3600 / 60));
        el.s.textContent = pad(t % 60);
    }
    tick(); setInterval(tick, 1000);
})();
</script>
</body>
</html>
