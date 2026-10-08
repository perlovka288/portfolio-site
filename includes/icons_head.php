<?php
/**
 * Подключение набора иконок вместо эмодзи (assets/kostlim-icons.{css,js}).
 * Вставляется в <head> каждой страницы сайта и админки. Скрипт должен стоять в <head>:
 * он заменяет эмодзи по мере появления контента, чтобы они не мигали.
 */
$__keiRoot = dirname(__DIR__) . '/assets/';
$__keiV = max((int)@filemtime($__keiRoot . 'kostlim-icons.js'), (int)@filemtime($__keiRoot . 'kostlim-icons.css')) ?: 1;
?>
<link rel="stylesheet" href="/assets/kostlim-icons.css?v=<?= $__keiV ?>">
<script src="/assets/kostlim-icons.js?v=<?= $__keiV ?>"></script>
<?php
// Админка: единый iOS-стиль настроек (assets/kostlim-admin-ios.{css,js}) — на всех страницах /admin/* и admin_index.php.
$__sn = (string)($_SERVER['SCRIPT_NAME'] ?? '');
if (strpos($__sn, '/admin/') !== false || basename($__sn) === 'admin_index.php'):
    $__iosV = max((int)@filemtime($__keiRoot . 'kostlim-admin-ios.js'), (int)@filemtime($__keiRoot . 'kostlim-admin-ios.css')) ?: 1;
?>
<link rel="stylesheet" href="/assets/kostlim-admin-ios.css?v=<?= $__iosV ?>">
<script src="/assets/kostlim-admin-ios.js?v=<?= $__iosV ?>" defer></script>
<?php endif; ?>
