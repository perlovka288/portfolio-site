# Шрифты и материалы → Cloudinary

Что изменилось (3 файла, структура как в репозитории):
- includes/resources_lib.php — новая uploadPackResourceFileCloudinary(); загрузка файла из формы идёт
  Cloudinary → Google Drive. Локальное сохранение в uploads/ убрано (оно стиралось при деплое).
- admin/resources.php — то же для старой админ-страницы.
- download.php — файлы с res.cloudinary.com отдаются как скачивание (Content-Disposition: attachment).

Установка: скопировать файлы в корень репозитория с заменой, commit + push.

Нужно, чтобы в Render → Environment (или админка → «Ключи и API») были:
CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY, CLOUDINARY_API_SECRET
Проверка: /admin/storage_test.php

ВАЖНО: уже загруженные шрифты (например «тест») лежат в uploads/ и после деплоя пропали —
откройте материал → «Редактировать» → выберите файл заново (режим «Файл»), он уйдёт в Cloudinary.
Лимит Cloudinary на бесплатном тарифе для одного файла ~10 МБ; для больших — режим «Ссылка».
