<?php
/**
 * Навигация по трём разделам мини-аппы/сайта, встроенная в единый
 * компактный хедер (логотип + меню в одном блоке):
 * Главная (index.php) / Заказы (profile.php?view=orders — раздел заказов,
 * БЕЗ карточки профиля) / Поддержка (support.php).
 *
 * Профиль пользователя (аватар/имя/ADMIN) открывается только по клику на
 * саму плашку профиля (.tg-user-chip), которая ведёт на profile.php без
 * параметра view — см. profile.php: $viewOrders прячет profile-hero.
 *
 * Подключение (внутри <header>, под логотипом):
 *   $sectionTabsActive = 'home' | 'orders' | 'support';
 *   include __DIR__ . '/section_tabs.php';
 */
$sectionTabsActive = $sectionTabsActive ?? 'home';
?>
<nav class="section-tabs" aria-label="Разделы">
    <a href="index.php" class="section-tab <?= $sectionTabsActive === 'home' ? 'active' : '' ?>">Главная</a>
    <a href="profile.php?view=orders#orders-section" class="section-tab <?= $sectionTabsActive === 'orders' ? 'active' : '' ?>">Заказы</a>
    <a href="support.php" class="section-tab <?= $sectionTabsActive === 'support' ? 'active' : '' ?>">Поддержка</a>
</nav>
<script>
/* Лёгкий индикатор загрузки (3 оранжевые точки) при переходе между
   разделами — страницы здесь классические (полная перезагрузка), поэтому
   показываем оверлей сразу по клику на внутреннюю ссылку, ещё до того как
   браузер отрисует новую страницу. */
(function () {
    if (window.__knavLoaderInit) return;
    window.__knavLoaderInit = true;

    function showKNavLoader() {
        if (document.getElementById('knav-loader')) return;
        var overlay = document.createElement('div');
        overlay.id = 'knav-loader';
        overlay.className = 'knav-loader';
        overlay.innerHTML = '<div class="dot-flashing"></div>';
        document.body.appendChild(overlay);
        requestAnimationFrame(function () { overlay.classList.add('visible'); });
    }

    document.addEventListener('click', function (e) {
        var a = e.target.closest('a[href]');
        if (!a || a.target === '_blank') return;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || /^(https?:|mailto:|tel:|javascript:)/i.test(href)) return;
        showKNavLoader();
    }, true);
})();
</script>
