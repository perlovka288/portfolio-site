# Новый интерфейс KUI — как переносить остальные страницы

Готово: **index.php** (главная). Общие файлы:

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
| profile.php | `profile` (или `orders` при `?view=orders`) | аватар в центре нижнего меню ведёт сюда |
| price.php | `price` | |
| order.php | `orders` | форма: `.kui-field`, `.kui-btn`, карточки `.kui-card` |
| privat_pak.php | `ppk` | доступ по-прежнему проверяется твоей логикой |
| support.php | `support` | там уже стоит `$aiWidgetHideFab = true` |
| useful.php / resources.php / planner.php | `useful` / `ppk` | |
| admin/* | `admin` | отдельным шагом: у админки свой `header.php`/`footer.php`, сделаем админ-вариант оболочки |

## Компоненты
`.kui-card` (+`.accent`) · `.kui-row` · `.kui-grid` · `.kui-btn` (+`.ghost`, `.block`) · `.kui-field` · `.kui-badge` · `.kui-h1` · `.kui-h2` (заголовки с «пером»).
Цвета — только токены из `style.css` (`--accent`, `--card`, `--border`…).

## ИИ-иконка
Любой элемент с `data-open-ai-chat` открывает существующий чат (`includes/ai_widget.php`). Не забудь подключить `ai_widget.php` на странице.
