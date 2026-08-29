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
   браузер отрисует новую страницу.

   Правка ТЗ ("долгая загрузка при переходе между страницами"):
   1) Префетчим все 3 раздела в фоне сразу после загрузки текущей страницы
      (через <link rel="prefetch">) — к моменту, когда человек реально
      нажмёт на вкладку, ответ уже с высокой вероятностью в кэше браузера,
      и переход ощущается почти мгновенным.
   2) Дополнительно префетчим ЛЮБУЮ внутреннюю ссылку по hover/touchstart —
      это срабатывает раньше самого клика, так что первая загрузка ссылки
      начинается уже на naведении/касании, а не на клике.
   3) Если человек кликает по уже активной вкладке (той же странице,
      где уже находится) — не гоняем полную перезагрузку и не показываем
      лоадер зря. */
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

    function resolveUrl(href) {
        try { return new URL(href, window.location.href); } catch (e) { return null; }
    }

    function isSamePage(href) {
        var u = resolveUrl(href);
        if (!u) return false;
        return u.pathname === window.location.pathname && u.search === window.location.search;
    }

    // ── Префетч ── добавляет <link rel="prefetch"> один раз на URL.
    var prefetched = {};
    function prefetch(href) {
        if (!href || prefetched[href]) return;
        var u = resolveUrl(href);
        if (!u || u.origin !== window.location.origin) return;
        prefetched[href] = true;
        var link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = href;
        document.head.appendChild(link);
    }

    // Сразу префетчим 3 основных раздела (когда браузер освободится).
    var mainSections = ['index.php', 'profile.php?view=orders', 'support.php'];
    var kickoffPrefetch = function () {
        mainSections.forEach(function (href) {
            if (!isSamePage(href)) prefetch(href);
        });
    };
    if ('requestIdleCallback' in window) {
        requestIdleCallback(kickoffPrefetch, { timeout: 2000 });
    } else {
        setTimeout(kickoffPrefetch, 800);
    }

    // Префетч любой внутренней ссылки при наведении/касании — раньше клика.
    document.addEventListener('mouseenter', function (e) {
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a || a.target === '_blank') return;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || /^(https?:|mailto:|tel:|javascript:)/i.test(href)) return;
        prefetch(href);
    }, true);
    document.addEventListener('touchstart', function (e) {
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a || a.target === '_blank') return;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || /^(https?:|mailto:|tel:|javascript:)/i.test(href)) return;
        prefetch(href);
    }, { capture: true, passive: true });

    document.addEventListener('click', function (e) {
        var a = e.target.closest('a[href]');
        if (!a || a.target === '_blank') return;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || /^(https?:|mailto:|tel:|javascript:)/i.test(href)) return;
        // Клик по вкладке текущей же страницы — не показываем лоадер поверх
        // уже открытой страницы (браузер и так почти ничего не будет делать).
        if (isSamePage(href)) return;
        showKNavLoader();
    }, true);
})();
</script>
