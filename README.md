# Платёжный контур kostlimdzn: гео + оплата + экран успеха

## Файлы (кладутся в корень репозитория, структура совпадает)
includes/geo.php, includes/pay_lib.php
geoip.php, pay.php, pay_go.php, pay_status.php, pay_webhook.php, success.php
assets/pay.css, assets/geo.js
scripts/check_mono.php

## 1. Переменные окружения (Render → Environment)
Уже есть у вас:   BOT_TOKEN или TELEGRAM_BOT_TOKEN, ADMIN_ID
Добавить:
  SITE_URL          https://kostlimdzn.shop
  PAY_SECRET        любая длинная случайная строка (подписывает ссылки на оплату)
  MONO_JAR_URL      https://send.monobank.ua/jar/ВАШ_ID_БАНКИ
  MONO_KEY          токен Monobank API (api.monobank.ua)
  MONO_ACCOUNT      id банки из /personal/client-info -> jars[].id  (или 0 — основной счёт)
  CRYPTO_KEY        токен из @CryptoBot -> Crypto Pay -> Create App
  DA_DONATION_URL   https://www.donationalerts.com/r/andrewkostdzn
  DA_ACCESS_TOKEN   OAuth-токен DonationAlerts (scope oauth-donation-index) — для автоопределения платежа
Курсы (необязательно, есть значения по умолчанию):
  USD_UAH=41.5  EUR_UAH=48.5  KZT_PER_RUB=6.2

## 2. Кнопка в Telegram «Оплатить на сайте»
В bot.php / includes/order_flow.php найдите кнопку 'Оплатить на сайте' (сейчас её url ведёт в профиль) и замените url на:
  payLink($orderId)
(функция из includes/pay_lib.php; подключите require_once __DIR__.'/pay_lib.php' там, где строится клавиатура).

## 3. Автопроверка платежей
- Crypto Bot: работает сразу (страница сама опрашивает счёт). Плюс по желанию вебхук: https://ВАШ-САЙТ/pay_webhook.php
- Monobank: проверка выписки банки каждые 60 сек, пока открыта страница оплаты. Для надёжности добавьте Render Cron Job (каждую минуту): php scripts/check_mono.php
- DonationAlerts: нужен DA_ACCESS_TOKEN. Без него остаётся «Скинуть чек в ТГ».

## 4. Гео
На страницы с ценами добавьте перед </body>:  <script src="/assets/geo.js"></script>
(при первом заходе определит страну по IP -> валюту -> вызовет вашу switchCurrency()).
Страна: сначала заголовок Cloudflare CF-IPCountry, иначе ip-api.com.

## Допущения (проверьте)
- БД PostgreSQL (как в order.php: RETURNING id, ADD COLUMN IF NOT EXISTS). Новые колонки создаются сами.
- Оплаченным считается orders.payment_status = 'paid'. Если админ-бот у вас ждёт другое значение — поменяйте в markOrderPaid().
- «Можно платить» = статус заказа не pending/declined/rejected/cancelled... (список в payIsPayable()).
- Параметры ?a= и ?t= у банки Monobank и ?amount=&message= у DonationAlerts — «лучшее усилие»: если площадка их проигнорирует, на странице оплаты показана сумма и комментарий для ручного ввода.
