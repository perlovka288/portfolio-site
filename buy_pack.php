<?php
/**
 * Покупка Приват Пака — страница оплаты.
 * Сюда ведут кнопки «Купить пак» (прайс, Приват Пак, бот).
 * Оплатил → админу в Telegram приходит «клиент купил пак» с кнопкой «Одобрить» →
 * после одобрения доступ открывается на сайте, а бот присылает одноразовую ссылку в приватный чат.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/ppk_purchase.php';
require_once __DIR__ . '/includes/pay_lib.php';

$access = resolvePpkAccess($pdo);
$isAdmin = $access['isAdmin'];
$isPackDesigner = $access['isPackDesigner'];
$tgProfile = $access['tgProfile'];
$isLinked = !empty($tgProfile['tg_id']);
$tgId = $isLinked ? (string)$tgProfile['tg_id'] : '';

$prices = ppkPrices($pdo);
$methods = [];
if (isset($prices['UAH']) && payEnv('MONO_JAR_URL') !== '')  $methods[] = ['monobank', 'Monobank', 'Visa / Mastercard · Apple/Google Pay', ppkFormatMoney($prices['UAH'], 'UAH')];
if (isset($prices['RUB']))                                    $methods[] = ['donationalerts', 'DonationAlerts', 'Карты РФ/СНГ, СБП, ЮMoney', ppkFormatMoney($prices['RUB'], 'RUB')];
if (isset($prices['USD']) && payEnv('CRYPTO_KEY') !== '')    $methods[] = ['cryptobot', 'Crypto Bot', 'USDT, TON, BTC, ETH', ppkFormatMoney($prices['USD'], 'USD')];

$purchase = $isLinked ? (ppkOpenPurchase($pdo, $tgId) ?: null) : null;
$approved = ($isLinked && $isPackDesigner) ? ppkApprovedPurchase($pdo, $tgId) : null;
$inviteLink = ($approved && !$approved['invite_used'] && $approved['invite_link'] !== '') ? $approved['invite_link'] : '';
$botLink = 'https://t.me/kostlimdznbot';

function imgSrcBuy(?string $u): string { $u = trim((string)$u); return $u !== '' ? $u : '/assets/img/default_avatar.png'; }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Купить Приват Пак — Kostlim Design</title>
    <link rel="icon" type="image/png" href="/assets/img/logo-64.png" sizes="16x16">
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
    <?php include __DIR__ . '/includes/ui_head.php'; ?>
    <link rel="stylesheet" href="/assets/ppk-redesign.css?v=<?= @filemtime(__DIR__ . '/assets/ppk-redesign.css') ?: time() ?>">
    <style>
        .bp-wrap { max-width: 640px; margin: 0 auto; display: flex; flex-direction: column; gap: 14px; }
        .bp-price { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 2px; }
        .bp-price span { padding: 7px 14px; border-radius: 12px; font-weight: 800; background: rgba(255,122,0,.12); border: 1px solid rgba(255,122,0,.35); color: #ffb067; }
        .bp-m { display: flex; align-items: center; justify-content: space-between; gap: 12px; width: 100%; text-align: left; cursor: pointer;
            padding: 14px 16px; border-radius: 16px; color: #fff; font: inherit; background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.1);
            transition: border-color .2s, background .2s, transform .15s; }
        .bp-m:hover { border-color: rgba(255,122,0,.6); background: rgba(255,122,0,.08); transform: translateY(-1px); }
        .bp-m b { display: block; font-size: 15px; } .bp-m small { color: #9a9aa6; font-size: 12px; }
        .bp-m .sum { font-weight: 900; color: #ff9a3c; white-space: nowrap; }
        .bp-list { display: flex; flex-direction: column; gap: 10px; margin-top: 12px; }
        .bp-box { display: none; }
        .bp-box.show { display: block; }
        .bp-code { display: inline-block; padding: 3px 9px; border-radius: 8px; background: rgba(255,255,255,.08); font-family: monospace; font-weight: 700; user-select: all; }
        .bp-msg { font-size: 13px; margin-top: 8px; min-height: 18px; }
        .bp-steps { margin: 10px 0 0; padding-left: 18px; color: #c9c9cf; font-size: 13.5px; line-height: 1.7; }
        .bp-link { word-break: break-all; font-family: monospace; font-size: 13px; padding: 10px 12px; border-radius: 12px; background: rgba(255,255,255,.06); display: block; margin-top: 8px; color: #ffb067; }
    </style>
</head>
<body class="kui">
<?php
    $kuiActive = 'ppk';
    include __DIR__ . '/includes/ui_shell.php';
?>
<main class="kui-main kui-ppk-main">
<div class="bp-wrap">

<?php if ($isPackDesigner): ?>
    <div class="kui-card accent">
        <h2>✅ Приват Пак у тебя уже открыт</h2>
        <p>Весь раздел доступен: PSD-пак, шрифты, кисти, SD-гайд, тренажёр и планер.</p>
        <?php if ($inviteLink): ?>
            <p style="margin-top:12px"><b>Твоя одноразовая ссылка в приватный чат</b> (сработает один раз):</p>
            <a class="bp-link" href="<?= htmlspecialchars($inviteLink) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($inviteLink) ?></a>
        <?php endif; ?>
        <a class="kui-btn block" href="privat_pak.php" style="margin-top:14px">Открыть Приват Пак</a>
    </div>

<?php elseif (!$isLinked): ?>
    <div class="kui-card accent">
        <h2>🔒 Купить Приват Пак</h2>
        <p>Чтобы доступ привязался к тебе, сначала войди на сайт через Telegram — открой бота и перейди оттуда по кнопке на сайт.</p>
        <a class="kui-btn block" href="<?= $botLink ?>" target="_blank" rel="noopener" style="margin-top:12px">🤖 Открыть бота</a>
    </div>

<?php else: ?>
    <div class="kui-card accent">
        <h2>🛒 Приват Пак</h2>
        <p>PSD-исходники, шрифты, кисти и стили, гайд по Stable Diffusion, ИИ-тренажёр клиента и личный планер + закрытый Telegram-чат.</p>
        <?php if ($prices): ?>
            <div class="bp-price"><?php foreach ($prices as $c => $v): ?><span><?= htmlspecialchars(ppkFormatMoney($v, $c)) ?></span><?php endforeach; ?></div>
        <?php endif; ?>
    </div>

    <!-- 1. выбор способа -->
    <div class="kui-card bp-box" id="bpPick">
        <b>Выбери способ оплаты</b>
        <div class="bp-list">
            <?php foreach ($methods as [$key, $title, $desc, $sum]): ?>
                <button type="button" class="bp-m" data-m="<?= $key ?>"><span><b><?= htmlspecialchars($title) ?></b><small><?= htmlspecialchars($desc) ?></small></span><span class="sum"><?= htmlspecialchars($sum) ?></span></button>
            <?php endforeach; ?>
            <?php if (!$methods): ?>
                <p style="color:#c9c9cf">Автоматическая оплата пока не настроена — напиши дизайнеру <a href="https://t.me/Perlo_ovka" target="_blank" rel="noopener">@Perlo_ovka</a>, оплати удобным способом и нажми кнопку ниже.</p>
                <button type="button" class="kui-btn block" id="bpClaimOnly">Я оплатил(а)</button>
            <?php endif; ?>
        </div>
        <div class="bp-msg" id="bpErr" style="color:#ef4444"></div>
    </div>

    <!-- 2. ждём оплату -->
    <div class="kui-card bp-box" id="bpWait">
        <b id="bpWaitTitle">Оплати в открывшейся вкладке</b>
        <ol class="bp-steps">
            <li>Сумма: <b id="bpAmount">—</b></li>
            <li>Комментарий к платежу: <span class="bp-code" id="bpComment">—</span> <small style="color:#9a9aa6">(нужен, чтобы платёж привязался к тебе)</small></li>
            <li>После оплаты нажми «Я оплатил(а)» — дизайнер получит уведомление.</li>
        </ol>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
            <button type="button" class="kui-btn" id="bpClaim">Я оплатил(а)</button>
            <a class="kui-btn" id="bpReopen" href="#" target="_blank" rel="noopener" style="background:rgba(255,255,255,.08);box-shadow:none">Открыть оплату ещё раз</a>
        </div>
    </div>

    <!-- 3. заявка отправлена -->
    <div class="kui-card accent bp-box" id="bpDone">
        <h2>⏳ Заявка отправлена</h2>
        <p>Дизайнер проверит оплату и одобрит доступ. Как только это произойдёт — страница откроется сама, а бот пришлёт тебе <b>одноразовую ссылку</b> в приватный чат.</p>
        <p style="color:#9a9aa6;font-size:13px;margin-top:8px">Можно закрыть страницу — сообщение придёт в Telegram.</p>
    </div>
<?php endif; ?>

</div>
</main>
<script src="/assets/kostlim-ui.js?v=<?= @filemtime(__DIR__ . '/assets/kostlim-ui.js') ?: time() ?>"></script>
<?php if ($isLinked && !$isPackDesigner): ?>
<script>
(function () {
    var $ = function (id) { return document.getElementById(id); };
    var state = <?= json_encode($purchase['status'] ?? 'none') ?>;
    var poll = null;
    function show(id) { ['bpPick', 'bpWait', 'bpDone'].forEach(function (x) { var e = $(x); if (e) e.classList.toggle('show', x === id); }); }
    function post(body) {
        return fetch('buy_pack_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }
    function startPoll() {
        if (poll) return;
        poll = setInterval(function () {
            post({ action: 'status' }).then(function (r) {
                if (r.has_access || r.status === 'approved') { clearInterval(poll); location.reload(); }
                else if (r.status === 'paid' || r.status === 'claimed') { show('bpDone'); }
                else if (r.status === 'rejected') { clearInterval(poll); poll = null; show('bpPick'); $('bpErr').textContent = 'Платёж не подтверждён. Если ты оплатил(а) — напиши дизайнеру.'; }
            }).catch(function () {});
        }, 6000);
    }
    document.querySelectorAll('.bp-m').forEach(function (b) {
        b.onclick = function () {
            $('bpErr').textContent = '';
            // окно открываем сразу по клику (иначе мобильные браузеры блокируют), ссылку подставим после ответа
            var w = window.open('', '_blank');
            post({ action: 'pay', m: b.dataset.m }).then(function (r) {
                if (!r.ok) { if (w) w.close(); $('bpErr').textContent = r.error || 'Ошибка'; return; }
                if (r.already) { show('bpDone'); startPoll(); if (w) w.close(); return; }
                $('bpAmount').textContent = r.amount; $('bpComment').textContent = r.comment; $('bpReopen').href = r.url;
                if (w) { w.location = r.url; } else { location.href = r.url; }
                show('bpWait'); startPoll();
            }).catch(function () { if (w) w.close(); $('bpErr').textContent = 'Ошибка сети, попробуй ещё раз.'; });
        };
    });
    function claim(btn) {
        btn.disabled = true;
        post({ action: 'claim' }).then(function (r) {
            btn.disabled = false;
            if (r.ok) { show('bpDone'); startPoll(); } else { $('bpErr').textContent = r.error || 'Ошибка'; }
        }).catch(function () { btn.disabled = false; });
    }
    if ($('bpClaim')) $('bpClaim').onclick = function () { claim(this); };
    if ($('bpClaimOnly')) $('bpClaimOnly').onclick = function () { claim(this); };
    if (state === 'claimed' || state === 'paid') { show('bpDone'); startPoll(); }
    else { show('bpPick'); }
})();
</script>
<?php endif; ?>
</body>
</html>
