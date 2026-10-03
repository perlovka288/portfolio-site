# Что изменено в этой версии

1. **Загрузка картинок** (includes/image_store.php, includes/imgbb.php, admin/storage_test.php)
   - Cloudinary читает ключи из Render/админки и из запасных имён (CLOUDINARY_NAME, CLOUDINARY_KEY, CLOUDINARY_SECRET, CLOUDINARY_URL).
   - ImgBB: браузерные заголовки, поддержка прокси `IMGBB_PROXY`, при «forbidden» не перебирает ключи зря.
   - Тумблер «ImgBB как запасное» теперь реально работает.
   - /admin/storage_test.php: форма «Подключить Cloudinary» (сохраняет в базу и сразу проверяет), показывает откуда взято каждое значение.
2. **Меню на ПК** — тот же док, но вертикально слева; старый боковой сайдбар удалён (includes/ui_shell.php, assets/kostlim-dock.css, assets/kostlim-ui.css).
3. **Превью в стиле TravelCard** — assets/kostlim-cards.css (портфолио и прайс), подключён в includes/ui_head.php; в index.php цена выводится в две строки.
