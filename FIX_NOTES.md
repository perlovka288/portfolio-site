# Что изменено в этой версии

1. **ImgBB полностью заменён на Cloudinary** (includes/image_store.php, includes/imgbb.php, includes/order_flow.php, upload_proxy.php, order.php)
   - Все загрузки (аватарки, референсы ТЗ, скриншоты, чеки, превью, редактор) идут в `https://api.cloudinary.com/v1_1/{cloud}/auto/upload` (resource_type=auto: картинки, гифки, видео, архивы, документы).
   - Подпись: `sha1("folder=..&public_id=..&timestamp=.." . API_SECRET)`; `api_key`, `timestamp`, `signature` уходят в multipart/form-data. При «Invalid Signature» автоматически повторяется с SHA-256.
   - Возвращается `secure_url` (прямая https-ссылка). У архивов/документов в ссылке сохраняется расширение файла.
   - Лимит файла 100 МБ: Dockerfile и .htaccess (`upload_max_filesize 100M`, `post_max_size 500M`, `max_execution_time 300`, `max_input_time 300`), таймаут cURL 5 минут, upload_proxy.php принимает любые файлы до 100 МБ.
   - Старые имена функций (`imgbbUpload`, `uploadToImgBB`, `uploadReceiptToImgBB`) оставлены как обёртки на Cloudinary — вызовы по сайту менять не пришлось. ImgBB-ключи больше не читаются.
   - Ключи: `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET` (Render → Environment или админка → «Ключи и API»).
   - Старые ссылки ibb.co в базе продолжают открываться как раньше (мы только перестали заливать на ImgBB).
2. **pay.php** — новый дизайн выбора оплаты (карточки способов с радио-индикатором, чипы валют ₴ ₽ ₸ $ €, плавное появление). assets/pay.css переписан под реальную разметку страницы.
3. **success.php** — чек-билет в цветах сайта: номер заказа, статус, услуга, клиент, Telegram, дата, способ оплаты, сумма в оплаченной валюте + эквиваленты во всех валютах, скидка по промокоду, штрихкод, конфетти, кнопка печати.
4. **Monobank**: ссылка на банку кодирует пробел как `%20` (раньше `+` попадал в комментарий), парсер номера заказа терпим к `+`/`#`/`_`.
5. **Иконки способов оплаты**: assets/img/Mono.png, Donate.png, CB.png (имена с учётом регистра). Если файла нет — показывается текстовый значок.
