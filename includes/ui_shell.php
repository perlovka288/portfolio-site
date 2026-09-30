<?php
/**
 * KUI shell — общая оболочка интерфейса для ВСЕХ страниц:
 *   • ПК (≥900px): боковое меню слева
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

$kuiName  = ($tgProfile['tg_first_name'] ?? '') ?: (!empty($tgProfile['tg_username']) ? '@' . $tgProfile['tg_username'] : 'Гость');
$kuiPhoto = '/assets/img/logo.png';
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
            'ai'      => '<path d="M12 2l1.8 6.2L20 10l-6.2 1.8L12 18l-1.8-6.2L4 10l6.2-1.8zM19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z"/>',
        ];
        return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($i[$n] ?? '') . '</svg>';
    }
}

// Пункты бокового меню (ПК) и шторки «Ещё» (телефон)
$kuiSide = [
    ['home',   'Работы',      'index.php',                              'works'],
    ['price',  'Прайс',       'price.php',                              'price'],
    ['orders', 'Заказы',      'profile.php?view=orders#orders-section', 'orders'],
    ['reviews','Отзывы',      'index.php#reviews',                      'reviews'],
    ['useful', 'Полезное',    'useful.php',                             'useful'],
    ['ppk',    'Приват Пак',  $kuiPpkHref,                              'ppk'],
    ['support','Поддержка',   'support.php',                            'support'],
];
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

<!-- KUI: боковое меню (только ПК) -->
<aside class="kui-side" aria-label="Разделы">
    <a class="kui-side-logo" href="index.php"><img src="/assets/img/logo.png" alt="Kostlim Design"></a>
    <a class="kui-side-prof" href="profile.php">
        <img src="<?= htmlspecialchars($kuiPhoto) ?>" alt="" onerror="this.src='/assets/img/logo.png'">
        <span><?= htmlspecialchars($kuiName) ?></span>
        <?php if ($kuiBadge): ?><em><?= $kuiBadge ?></em><?php endif; ?>
    </a>
    <?php foreach ($kuiSide as $it): ?>
        <a class="kui-side-link<?= $kuiActive === $it[0] ? ' on' : '' ?>" href="<?= htmlspecialchars($it[2]) ?>"<?= ($it[0] === 'ppk' && $kuiPpkOnclick) ? ' onclick="' . htmlspecialchars($kuiPpkOnclick) . '"' : '' ?>>
            <i class="kui-glass"></i><?= kuiIcon($it[3]) ?><span><?= $it[1] ?></span>
        </a>
    <?php endforeach; ?>
    <div class="kui-side-sp"></div>
    <button type="button" class="kui-side-link" data-open-ai-chat><?= kuiIcon('ai') ?><span>ИИ-помощник</span></button>
    <?php if ($isAdmin): ?><a class="kui-side-link<?= $kuiActive === 'admin' ? ' on' : '' ?>" href="admin/index.php"><?= kuiIcon('admin') ?><span>Админ-панель</span></a><?php endif; ?>
</aside>

<!-- KUI: верхняя панель: ИИ · логотип по центру · Прайс + Telegram -->
<header class="kui-top">
    <button type="button" class="kui-ic kui-ai" data-open-ai-chat aria-label="ИИ-помощник"><?= kuiIcon('ai') ?></button>
    <a class="kui-logo" href="index.php" aria-label="Kostlim Design"><img src="/assets/img/logo.png" alt=""></a>
    <div class="kui-top-r">
        <a class="kui-pill" href="price.php">Прайс</a>
        <a class="kui-ic" href="https://t.me/designkostlim" target="_blank" rel="noopener" aria-label="Telegram"><?= kuiIcon('tg') ?></a>
    </div>
</header>

<!-- KUI: нижнее меню (только телефон) -->
<nav class="kui-nav" aria-label="Навигация">
    <a class="<?= $kuiActive === 'home' ? 'on' : '' ?>" href="index.php"><i class="kui-glass"></i><?= kuiIcon('works') ?>Работы</a>
    <a class="<?= $kuiActive === 'price' ? 'on' : '' ?>" href="price.php"><i class="kui-glass"></i><?= kuiIcon('price') ?>Прайс</a>
    <a class="kui-me<?= $kuiActive === 'profile' ? ' on' : '' ?>" href="profile.php" aria-label="Профиль">
        <img src="<?= htmlspecialchars($kuiPhoto) ?>" alt="" onerror="this.src='/assets/img/logo.png'">
        <?php if ($kuiBadge): ?><em><?= $kuiBadge ?></em><?php endif; ?>
    </a>
    <a class="<?= $kuiActive === 'orders' ? 'on' : '' ?>" href="profile.php?view=orders#orders-section"><i class="kui-glass"></i><?= kuiIcon('orders') ?>Заказы</a>
    <button type="button" data-kui-more><?= kuiIcon('more') ?>Ещё</button>
</nav>

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
