# Новый интерфейс KUI — как переносить остальные страницы

Готово: **index.php** (главная), **profile.php** (профиль + «Мои заказы»), **order.php** (правила заказа → форма заказа), **admin/index.php** (админ-панель: заказы «Активные» + «Архив», вид список/плитка), **price.php** (прайс). Общие файлы:

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
