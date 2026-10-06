<?php
/**
 * KUI shell — общая оболочка интерфейса для ВСЕХ страниц:
 *   • ПК (≥900px): то же меню-док, но вертикальной панелью слева (старое боковое меню убрано)
 *   • Телефон: оранжевая шапка (ИИ · логотип по центру · Прайс/TG) + нижнее меню
 *     с аватаром в центре и шторкой «Ещё»
 *
 * Использование (сразу после <body class="... kui">):
 *   $kuiActive = 'home';   // home | price | orders | profile | support | ppk | useful | admin
 *   include __DIR__ . '/includes/ui_shell.php';
 *
 * Ожидает (если есть): $isAdmin, $isPackDesigner, $isLinked, $tgProfile, функцию imgSrc().
 * Всё необязательное — без этих переменных оболочка работает как для гостя.
 */
$kuiActive       = $kuiActive ?? 'home';
$isAdmin         = !empty($isAdmin);
$isPackDesigner  = !empty($isPackDesigner);
$isLinked        = !empty($isLinked);
$tgProfile       = (isset($tgProfile) && is_array($tgProfile)) ? $tgProfile : [];
$ppkHasAccess    = $isAdmin || $isPackDesigner;

// FIX (ключи/покупка «не открывают доступ»): страницы считали роль по-разному — главная
// смотрела только членство в группе, support.php не считал вовсе, поэтому человек с ключом
// видел замок. Теперь оболочка сама перепроверяет роль единой функцией resolvePpkAccess
// (админ + ручные выдачи/ключи/покупки + участие в группе), если страница её не определила.
if (!$ppkHasAccess && !empty($tgProfile['tg_id']) && isset($pdo) && $pdo instanceof PDO) {
    try {
        require_once __DIR__ . '/ppk_access.php';
        $__acc = resolvePpkAccess($pdo);
        if (!empty($__acc['isPackDesigner'])) { $isPackDesigner = true; $ppkHasAccess = true; }
        if (!empty($__acc['isAdmin'])) { $isAdmin = true; $ppkHasAccess = true; }
    } catch (Throwable $__e) {}
}

$kuiName  = ($tgProfile['tg_first_name'] ?? '') ?: (!empty($tgProfile['tg_username']) ? '@' . $tgProfile['tg_username'] : 'Гость');
$kuiPhoto = '/assets/img/logo.webp';
if (!empty($tgProfile['tg_photo_url']) && function_exists('imgSrc')) {
    $kuiPhoto = imgSrc((string)$tgProfile['tg_photo_url']);
}
$kuiBadge = $isAdmin ? 'ADMIN' : ($isPackDesigner ? 'PPK' : '');

// Приват Пак: есть доступ — страница, нет — модалка ppkPreviewModal (см. ppk_nav_modal.php)
$kuiPpkHref    = $ppkHasAccess ? 'privat_pak.php' : '#';
$kuiPpkOnclick = $ppkHasAccess ? '' : "document.getElementById('ppkPreviewModal').classList.add('show');return false;";

if (!function_exists('kuiIcon')) {
    function kuiIcon(string $n): string {
        static $i = [
            'works'   => '<rect x="3" y="3" width="18" height="18" rx="4"/><path d="M8 14l3-3 2 2 3-4"/>',
            'price'   => '<path d="M12 2v20M17 6H9.5a3 3 0 000 6h5a3 3 0 010 6H6"/>',
            'orders'  => '<path d="M6 2h12l2 4v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6z"/><path d="M4 6h16M9 10a3 3 0 006 0"/>',
            'reviews' => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
            'support' => '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>',
            'ppk'     => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/>',
            'useful'  => '<path d="M4 4h12a4 4 0 014 4v12H8a4 4 0 01-4-4z"/><path d="M8 8h8M8 12h6"/>',
            'admin'   => '<path d="M12 2l8 3v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5z"/>',
            'more'    => '<path d="M4 7h16M4 12h16M4 17h16"/>',
            'tg'      => '<path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>',
            'plus'    => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
            'ai'      => '<path d="M12 2l1.8 6.2L20 10l-6.2 1.8L12 18l-1.8-6.2L4 10l6.2-1.8zM19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z"/>',
        ];
        // Анимированные иконки из набора (assets/kostlim-icons.js): замочек пака, самолётик Telegram, меню «Ещё».
        // Играют анимацию при наведении/нажатии на пункт меню. Обёртка display:contents — раскладку меню не меняет.
        static $a = [
            'lock'   => '<rect x="9" y="18" width="22" height="16" rx="3"/><path class="k-shackle" d="M14 18V13a6 6 0 0112 0v5"/><circle class="k-key" cx="20" cy="26" r="2" fill="currentColor" stroke="none"/>',
            'unlock' => '<rect x="9" y="18" width="22" height="16" rx="3"/><path class="k-shackle2" d="M14 18V13a6 6 0 0112 0v2"/><circle class="k-key2" cx="20" cy="26" r="2" fill="currentColor" stroke="none"/>',
            'tg'     => '<g class="k-plane"><path d="M34 6L16 20l-6-2L34 6z"/><path d="M34 6L22 34l-6-14"/><line x1="16" y1="20" x2="22" y2="34"/></g>',
            'more'   => '<line class="k-m1" x1="10" y1="12" x2="30" y2="12" stroke-width="2.5"/><line class="k-m2" x1="10" y1="20" x2="30" y2="20" stroke-width="2.5"/><line class="k-m3" x1="10" y1="28" x2="30" y2="28" stroke-width="2.5"/>',
        ];
        $an = $n;
        if ($n === 'ppk') { $an = !empty($GLOBALS['ppkHasAccess']) ? 'unlock' : 'lock'; }
        if (isset($a[$an])) {
            return '<span class="kei kei-a kei-nav kei-' . $an . '" aria-hidden="true"><svg viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $a[$an] . '</svg></span>';
        }
        return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($i[$n] ?? '') . '</svg>';
    }
}

// Пункты шторки «Ещё» (на ПК открывается рядом с боковой панелью, на телефоне — снизу)
$kuiMore = [
    ['reviews','Отзывы',      'index.php#reviews',   'reviews'],
    ['useful', 'Полезное',    'useful.php',          'useful'],
    ['ppk',    'Приват Пак',  $kuiPpkHref,           'ppk'],
    ['support','Поддержка',   'support.php',         'support'],
];
if ($isAdmin) { $kuiMore[] = ['admin', 'Админ-панель', 'admin/index.php', 'admin']; }
?>
<!-- KUI: legacy-загрузчик переходов (скрыт) + модалка Приват Пака -->
<div class="kui-legacy" hidden><?php $sectionTabsActive = $kuiActive; $sectionTabsShowPpk = false; include __DIR__ . '/section_tabs.php'; ?></div>
<?php $__sec = $sectionTabsActive; include __DIR__ . '/ppk_nav_modal.php'; ?>


<!-- KUI: верхняя панель: ИИ · логотип по центру · Прайс + Telegram -->
<header class="kui-top">
    <button type="button" class="kui-ic kui-ai" data-open-ai-chat aria-label="ИИ-помощник"><?= kuiIcon('ai') ?></button>
    <a class="kui-logo" href="index.php" aria-label="Kostlim Design"><img src="/assets/img/logo.webp" alt=""></a>
    <div class="kui-top-r">
        <a class="kui-pill" href="price.php">Прайс</a>
        <a class="kui-ic" href="https://t.me/designkostlim" target="_blank" rel="noopener" aria-label="Telegram"><?= kuiIcon('tg') ?></a>
    </div>
</header>

<!-- KUI: нижний блок — строка-промпт ИИ (бегущая рамка) + док-навигация под ней -->
<div class="kd-stack">
    <form class="kd-prompt" id="kdPrompt" action="#" autocomplete="off">
        <div class="kd-beam"><div class="kd-box">
            <div class="kd-row">
                <button type="button" class="kd-chip kd-at" data-kd="attach" title="Прикрепить превью — оценю CTR" aria-label="Прикрепить фото">
                    <svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="2.6"/><path d="M10.6 5.6v3a1.9 1.9 0 003.8 0V8a6.4 6.4 0 10-2.5 5.1"/></svg>
                </button>
                <textarea id="kdInput" class="kd-text" rows="1" maxlength="2000" placeholder="Спроси ИИ: превью, цена, заказ…" enterkeyhint="send" aria-label="Вопрос ИИ-помощнику"></textarea>
                <button type="submit" class="kd-send" aria-label="Отправить">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                </button>
            </div>
            <div class="kd-chips"><div>
                <button type="button" class="kd-chip" data-kd="ideas">💡 Идеи для превью</button>
                <button type="button" class="kd-chip" data-kd="ctr">📊 Оценить CTR</button>
                <button type="button" class="kd-chip" data-kd="price">💰 Прайс</button>
            </div></div>
        </div></div>
    </form>

    <nav class="kui-nav" aria-label="Навигация">
        <a class="<?= $kuiActive === 'home' ? 'on' : '' ?>" href="index.php"><i class="kui-glass"></i><?= kuiIcon('works') ?><span class="kd-l">Работы</span></a>
        <a class="<?= $kuiActive === 'price' ? 'on' : '' ?>" href="price.php"><i class="kui-glass"></i><?= kuiIcon('price') ?><span class="kd-l">Прайс</span></a>
        <a class="kui-me<?= $kuiActive === 'profile' ? ' on' : '' ?>" href="profile.php" aria-label="Профиль">
            <img src="<?= htmlspecialchars($kuiPhoto) ?>" alt="" onerror="this.src='/assets/img/logo.webp'">
            <?php if ($kuiBadge): ?><em><?= $kuiBadge ?></em><?php endif; ?>
            <span class="kd-l">Профиль</span>
        </a>
        <a class="<?= $kuiActive === 'orders' ? 'on' : '' ?>" href="profile.php?view=orders#orders-section"><i class="kui-glass"></i><?= kuiIcon('orders') ?><span class="kd-l">Заказы</span></a>
        <button type="button" data-kui-more><?= kuiIcon('more') ?><span class="kd-l">Ещё</span></button>
    </nav>
</div>

<!-- KUI: ПК (≥900px) — боковое меню-сайдбар: узкая рейка, при наведении раскрывается (стили — assets/kostlim-dock.css, в конце) -->
<?php
$ksbItem = static function (string $key, string $label, string $sub, string $href, string $icon, string $onclick = '', bool $ext = false) use ($kuiActive): string {
    $on = ($kuiActive === $key) ? ' on' : '';
    return '<a class="ksb-link' . $on . '" href="' . htmlspecialchars($href) . '"' . ($onclick !== '' ? ' onclick="' . htmlspecialchars($onclick) . '"' : '') . ($ext ? ' target="_blank" rel="noopener"' : '') . ' title="' . htmlspecialchars($label) . '">'
        . kuiIcon($icon) . '<span class="ksb-t"><b>' . htmlspecialchars($label) . '</b>' . ($sub !== '' ? '<small>' . htmlspecialchars($sub) . '</small>' : '') . '</span></a>';
};
?>
<aside class="kui-side ksb" id="kuiSide" aria-label="Меню сайта">
    <a class="ksb-logo" href="index.php" aria-label="Kostlim Design">
        <img src="/assets/img/logo.webp" alt="">
        <span class="ksb-t"><b>Kostlim Design</b><small>Дизайн соцсетей</small></span>
    </a>
    <div class="ksb-body">
        <a class="ksb-cta" href="order.php" title="Сделать заказ"><?= kuiIcon('plus') ?><span class="ksb-t"><b>Сделать заказ</b></span></a>

        <div class="ksb-grp"><span>Меню</span></div>
        <?= $ksbItem('home',   'Работы',  'Портфолио',        'index.php', 'works') ?>
        <?= $ksbItem('price',  'Прайс',   'Услуги и цены',    'price.php', 'price') ?>
        <?= $ksbItem('orders', 'Заказы',  'Мои заказы',       'profile.php?view=orders#orders-section', 'orders') ?>
        <?= $ksbItem('',       'Отзывы',  'Что говорят клиенты', 'index.php#reviews', 'reviews') ?>

        <div class="ksb-grp"><span>Для дизайнеров</span></div>
        <?= $ksbItem('useful', 'Полезное',    'Гайды и материалы',     'useful.php', 'useful') ?>
        <?= $ksbItem('ppk',    'Приват Пак',  'Исходники и шаблоны',   $kuiPpkHref, 'ppk', $kuiPpkOnclick) ?>

        <div class="ksb-grp"><span>Связь</span></div>
        <?= $ksbItem('support', 'Поддержка', 'Ответим на вопросы', 'support.php', 'support') ?>
        <button type="button" class="ksb-link" data-open-ai-chat title="ИИ-помощник"><?= kuiIcon('ai') ?><span class="ksb-t"><b>ИИ-помощник</b><small>Превью, цена, заказ</small></span></button>
        <?= $ksbItem('', 'Telegram', '@designkostlim', 'https://t.me/designkostlim', 'tg', '', true) ?>
        <?php if ($isAdmin): ?>
            <div class="ksb-grp"><span>Владелец</span></div>
            <?= $ksbItem('admin', 'Админ-панель', 'Заказы, прайс, ключи', 'admin/index.php', 'admin') ?>
        <?php endif; ?>
    </div>
    <a class="ksb-user<?= $kuiActive === 'profile' ? ' on' : '' ?>" href="profile.php" title="Профиль">
        <span class="ksb-ava"><img src="<?= htmlspecialchars($kuiPhoto) ?>" alt="" onerror="this.src='/assets/img/logo.webp'"></span>
        <span class="ksb-t"><b><?= htmlspecialchars($kuiName) ?></b><small><?= $kuiBadge ? htmlspecialchars($kuiBadge) . ' · ' : '' ?>Профиль</small></span>
    </a>
</aside>

<!-- KUI: шторка «Ещё» -->
<div class="kui-more" id="kuiMore">
    <div class="kui-more-sheet">
        <div class="kui-grab"></div>
        <button type="button" class="kui-more-row" data-open-ai-chat><span><?= kuiIcon('ai') ?>ИИ-помощник</span><small>Спроси про заказ</small></button>
        <?php foreach ($kuiMore as $it): ?>
            <a class="kui-more-row" href="<?= htmlspecialchars($it[2]) ?>"<?= ($it[0] === 'ppk' && $kuiPpkOnclick) ? ' onclick="' . htmlspecialchars($kuiPpkOnclick) . '"' : '' ?>><span><?= kuiIcon($it[3]) ?><?= $it[1] ?></span><small>›</small></a>
        <?php endforeach; ?>
    </div>
</div>

<?php
// ИИ-чат нужен строке-промпту на КАЖДОЙ странице: подключаем виджет здесь, если страница не подключила его сама раньше.
// (Большая плавающая иконка на kui-страницах скрыта стилями, поэтому её не показываем.)
if (empty($GLOBALS['__kuiAiWidgetDone'])) { $aiWidgetHideFab = true; include __DIR__ . '/ai_widget.php'; }
?>
