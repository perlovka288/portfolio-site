# Новый интерфейс KUI — как переносить остальные страницы

Готово: **index.php** (главная), **profile.php** (профиль + «Мои заказы»), **order.php** (правила заказа → форма заказа), **admin/index.php** (админ-панель: заказы «Активные» + «Архив», вид список/плитка), **price.php** (прайс), **privat_pak.php** (Приват Пак), вкладка «Ключи и API» в админке (iOS-стиль). Общие файлы:

| Файл | Что делает |
|---|---|
| `assets/kostlim-ui.css` | весь стиль (телефон + ПК ≥900px), всё под `body.kui` |
| `assets/kostlim-ui.js` | шторка «Ещё», свайп/стрелки между фильтрами |
| `includes/ui_head.php` | подключает CSS (в `<head>` последним) |
| `includes/ui_shell.php` | боковое меню (ПК), шапка ИИ·лого·Прайс·TG, нижнее меню, шторка «Ещё» |
| `includes/ui_page_start.php` / `ui_page_end.php` | обёртка для обычной страницы |
| `templates/kui_page_template.php` | рабочая заготовка страницы с примерами компонентов |

## Перенос страницы за 4 шага
1. `<meta viewport>` → добавить `, viewport-fit=cover`.
2. В `<head>` в самом конце: `<?php include __DIR__ . '/includes/ui_head.php'; ?>`.
3. `<body class="... kui">`, а старую шапку (`header.php` / `header-compact` / `section_tabs`) заменить на  
   `$kuiActive = 'price'; $kuiTitle = 'Прайс'; include __DIR__ . '/includes/ui_page_start.php';`
4. В конце контента: `include __DIR__ . '/includes/ui_page_end.php';`

Старая шапка, быстрые кнопки, круглая ИИ-кнопка и `.section-tabs` на страницах с `kui` скрываются CSS автоматически — ломать ничего не нужно.

## Порядок и значения `$kuiActive`
| Страница | `$kuiActive` | Заметки |
|---|---|---|
| profile.php ✅ | `profile` (или `orders` при `?view=orders`) | готово |
| price.php | `price` | |
| order.php ✅ | `orders` | готово: шаги «1 Правила → 2 Заказ», `includes/ui_identity.php` подставляет профиль в меню |
| privat_pak.php | `ppk` | доступ по-прежнему проверяется твоей логикой |
| support.php | `support` | там уже стоит `$aiWidgetHideFab = true` |
| useful.php / resources.php / planner.php | `useful` / `ppk` | |
| admin/index.php ✅ | — | готово: `includes/ui_admin_shell.php` + `assets/kostlim-admin.js` (нижнее меню и шторка «Ещё» строятся из твоих вкладок `.admin-tab`) |
| admin/profile.php, resources.php, psd_manager.php, ppk_manager.php… | — | тот же приём: `<body class="kui kui-admin">` + `include ui_admin_shell.php` (вкладок там нет — меню покажет только шапку) |

## Компоненты
`.kui-card` (+`.accent`) · `.kui-row` · `.kui-grid` · `.kui-btn` (+`.ghost`, `.block`) · `.kui-field` · `.kui-badge` · `.kui-h1` · `.kui-h2` (заголовки с «пером»).
Цвета — только токены из `style.css` (`--accent`, `--card`, `--border`…).

## ИИ-иконка
Любой элемент с `data-open-ai-chat` открывает существующий чат (`includes/ai_widget.php`). Не забудь подключить `ai_widget.php` на странице.


## Скорость и окна (v5)
| Что | Где | Зачем |
|---|---|---|
| `includes/schema_once.php` | обёртки `ensure*Schema()` | CREATE/ALTER TABLE раньше гонялись на КАЖДОМ запросе (секунды). Теперь — один раз после деплоя. Выключить: `KUI_SCHEMA_ALWAYS=1` |
| `includes/ui_cache.php` | `startSafeSession()`, `price.php` | короткий кэш браузера (20–60 с) для главной/прайса/полезного/поддержки → переход по «прогретому» разделу мгновенный. Выключить: `KUI_NO_PAGE_CACHE=1` |
| `assets/kostlim-nav.js` | `ui_head.php` | прогрев разделов в фоне + полоса загрузки; стеклянный индикатор — cross-document View Transitions |
| `assets/kostlim-lock.js` | `ui_head.php` | пока открыто окно/шторка, страница замирает (без скролла и размытия), двигается только окно |
| `assets/kostlim-modal.css` | `ui_head.php` | базовые стили окон там, где их не было (профиль, заказ, прайс) |
| `includes/imgbb.php` | все загрузки картинок | ключи ImgBB можно писать через запятую: `IMGBB_API_KEY=k1,k2,k3`; ошибки пишутся в Render → Logs |

## Скорость v6 — что именно сделано
- **Все разделы прогреваются сразу при входе** (`assets/kostlim-nav.js`): меню → кэш браузера; пока человек активен, кэш освежается сам.
- **Кэш не показывает старое после твоих действий**: `Vary: Cookie` + кука `kui_v` (меняется при любом POST и привязке Telegram) — `includes/ui_cache.php`.
- **Прогрев не мешает кликам**: запросы с `X-Kui-Warm` читают сессию без блокировки (`read_and_close`).
- **Сервер быстрее**: постоянное соединение с БД (`config/db.php`, выключить: `DB_PERSISTENT=0`), OPcache и gzip (`Dockerfile`, `.htaccess`), схема БД проверяется раз на контейнер (`includes/schema_once.php`).
- **Картинки только на ImgBB**: `includes/imgbb.php` (ключи через запятую). Локальный диск для картинок больше не используется — при сбое ImgBB показывается причина. Не картинки (PDF, PSD, ZIP) ImgBB принять не может: они идут в Cloudinary/Google Drive/на диск, как раньше.

## Ключи и API (v7)
- **Хранилище картинок: Cloudinary (ImgBB не нужен).** `includes/image_store.php`: Cloudinary → при отказе ImgBB (если настроен и включён тумблер). Ключи: Render env `CLOUDINARY_CLOUD_NAME/API_KEY/API_SECRET` (или `CLOUDINARY_URL`) либо админка → «Ключи и API».
- **Мост настроек** `includes/settings_bridge.php`: значения из админки (таблица `site_settings`) подкладываются в окружение при подключении к БД → их видит любой `getenv()`. Пустое поле не затирает Render; `BOT_TOKEN`, `ADMIN_TELEGRAM_ID`, Turnstile не перекрывают окружение (защита от блокировки входа).
- **Наборы ключей** `KEYSET_IMGBB / KEYSET_GEMINI / KEYSET_YT`: несколько ключей, активный первым, остальные — резерв.
- **Статусы** `admin/api_status.php`: Telegram, Cloudinary, ImgBB, Gemini, YouTube, Turnstile → «Онлайн / Ключ неверный / Ошибка подключения / Не настроено».
- UI: `admin/settings_ui.php` + `assets/kostlim-settings.js` (секции-карточки, тумблеры, сегменты, маски, копирование).

## Аналитика (v8)
- **Сбор**: `assets/kostlim-track.js` (в `ui_head.php`) → `track.php` → таблицы `site_visits` (просмотры) и `site_online` (пульс каждые 30 сек; онлайн = пульс ≤70 сек назад). Анонимная кука `kui_vid`. Не считаются: боты, администратор, `/admin`, дубли за 8 сек.
- **Выдача**: `admin/analytics_api.php?range=7d|30d|12m` и `?online=1` (только админ). Дни/месяцы — по Киеву (если зоны нет в БД — по UTC).
- **Виджеты** во вкладке «Обзор»: `admin/analytics_ui.php` + `assets/kostlim-analytics.js` (онлайн с пульсом, 3 метрики с динамикой, график с тултипом). Конверсия = заказы / уникальные посетители за период.
- **Картинки**: `kuiImgOpt/kuiImgSrcset/kuiImgBlur` (в `includes/image_store.php`) — для ссылок Cloudinary автоматически AVIF/WebP, адаптивные размеры и размытая заглушка.
