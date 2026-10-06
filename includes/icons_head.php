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
