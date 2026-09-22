# Kostlim Design — доработки: план внедрения

Коротко о подходе: часть механики у вас уже реализована и работает
(проверка участия в приватной группе через `getChatMember` — `includes/pack_role.php`,
раздел ресурсов с вкладками PSD/Шрифты/Кисти/SD — `resources.php`, ИИ на Gemini —
`ai_support.php`/`tg_ai.php`). Ниже — **только то, чего не было**, плюс точечные
правки багов в существующих файлах (по каждой — что именно исправляет и почему).

Новые файлы **не переписывают** ваши большие `index.php` / `admin/index.php` /
`profile.php` — они лежат рядом и подключаются. Изменения в существующих файлах —
это конкретные небольшие вставки/замены, описанные ниже построчно.

---

## Шаг 1. Скопировать новые файлы

Распакуйте архив поверх репозитория (пути внутри архива совпадают со структурой
проекта):

```
database/2026_09_kostlim_upgrade.sql
includes/badges.php
includes/telegram_links.php
includes/ppk_nav_modal.php
assets/kostlim-upgrade.css
ai_trainer.php
ai_trainer_api.php
planner.php
planner_api.php
activate_ppk_key.php
admin/ppk_manager.php
admin/ai_trainer_review.php
```

## Шаг 2. Прогнать SQL-миграцию

```bash
psql "$DATABASE_URL" -f database/2026_09_kostlim_upgrade.sql
```
(или через `database/run_migration.php`, если так удобнее в вашей текущей схеме
деплоя). Миграция идемпотентна — повторный запуск ничего не сломает.

## Шаг 3. Проверить переменные окружения

Для тренажёра нужен уже используемый в проекте `GEMINI_API_KEY` (тот же, что
у `ai_support.php`). Если он уже задан на проде — ничего дополнительно делать
не нужно. `BOT_TOKEN` / `PRIVATE_CHAT_ID` берутся так же, как сейчас — из
`site_settings` (вкладка "Ключи и API" в админке) либо из env-переменных.

---

## Шаг 4. Точечные правки существующих файлов

### 4.1. `includes/pack_role.php` — учитывать ручную выдачу PPK

Сейчас `isPackDesigner()` проверяет только Telegram-группу. Чтобы ручная выдача
роли (админка, п. 4.5) и погашенные ключи (модалка PPK) тоже давали доступ,
добавьте в конец файла (после существующей функции `isPackDesigner`):

```php
require_once __DIR__ . '/badges.php';

function isPackDesignerFull(PDO $pdo, string $token, string $groupChatId, string $tgId, bool $isAdmin = false): bool
{
    if ($isAdmin) return true;
    if (hasManualPpkGrant($pdo, $tgId)) return true;
    return checkPackMembership($pdo, $token, $groupChatId, $tgId);
}
```

Дальше используйте `isPackDesignerFull()` вместо `isPackDesigner()` в местах,
которые должны видеть и ручную выдачу тоже (см. 4.2–4.4). Сама `isPackDesigner()`
не тронута — обратная совместимость сохранена везде, где она уже вызывается.

### 4.2. `resources.php` — учесть ручную выдачу + починить баг скачивания

**а) Доступ.** Замените строку:
```php
$isPackDesigner = isPackDesigner($pdo, $botTokenForRoleCheck, $packGroupChatIdForRoleCheck, (string)($tgProfile['tg_id'] ?? ''), $isAdmin);
```
на:
```php
require_once __DIR__ . '/includes/badges.php';
ensurePpkManualSchema($pdo);
$isPackDesigner = isPackDesignerFull($pdo, $botTokenForRoleCheck, $packGroupChatIdForRoleCheck, (string)($tgProfile['tg_id'] ?? ''), $isAdmin);
```

**б) Баг «скачать → дублирующая вкладка с сайтом».** Причина: у шрифтов/кистей
`file_url` — это ссылка Google Drive (`webViewLink`), которая открывает страницу
предпросмотра Drive, а не сразу файл; а если загрузка на Drive не удалась
(`uploadToGoogleDrive` вернул `null`), поле сохраняется пустым — тогда
`href=""` буквально открывает **эту же страницу** заново, отсюда и ощущение
«дублирующей вкладки с сайтом». Правка — в `resources.php`, оба места с
`href="<?= htmlspecialchars($r['file_url']) ?>"` (секции Fonts и Brushes):

```php
require_once __DIR__ . '/includes/telegram_links.php';
```
(добавить в начало файла, рядом с остальными `require_once`), а в шаблоне
заменить:
```php
<a class="service-card" href="<?= htmlspecialchars($r['file_url']) ?>" target="_blank" ...>
```
на:
```php
<?php $dl = driveDirectDownloadUrl((string)$r['file_url']); ?>
<?php if ($dl === ''): ?>
<div class="service-card" style="opacity:.5;cursor:not-allowed;">
    <div class="service-cover-placeholder" style="aspect-ratio:16/9;"><span style="font-size:28px;">🔤</span></div>
    <div class="res-caption"><h3><?= htmlspecialchars($r['title']) ?></h3><span>Файл не загружен, сообщите админу</span></div>
</div>
<?php else: ?>
<a class="service-card" href="<?= htmlspecialchars($dl) ?>" target="_blank" rel="noopener" ...>
<?php endif; ?>
```
(закрывающий `</a>` соответственно обернуть тем же `if/else`). Это одновременно
чинит и «пустую ссылку», и открывает прямую загрузку файла вместо страницы
предпросмотра Google Drive.

**в) Ссылка на пост в TG с темами (форум-топики).** В форме PSD замените
`trim((string)($_POST['telegram_url'] ?? ''))` на нормализацию:
```php
require_once __DIR__ . '/includes/telegram_links.php';
$tgCheck = normalizeTelegramPostUrl((string)($_POST['telegram_url'] ?? ''));
if (!$tgCheck['ok']) { $message = '❌ ' . $tgCheck['error']; /* не сохранять пост */ }
else { $data['telegram_url'] = $tgCheck['url']; }
```
Ссылки вида `t.me/c/<chat_id>/<topic_id>/<msg_id>` (тема) и без темы —
`t.me/c/<chat_id>/<msg_id>` — обе проходят валидацию как есть; подробности и
почему не нужно парсить ID вручную — в комментарии внутри
`includes/telegram_links.php`.

**г) Поле «Категория».** В форме добавления PSD-поста (`<form id="form-psd">`)
добавьте после поля `title`:
```html
<input type="text" name="category" placeholder="Категория (например: Standoff 2)">
```
и в обработчике `add_resource` добавьте `'category' => trim((string)($_POST['category'] ?? ''))`
в массив `$data` — колонка `category` уже создаётся миграцией из шага 2.
(Отображение категории как фильтра сверху списка — по желанию; при текущем
объёме постов можно просто выводить `category` рядом с названием в `res-caption`.)

### 4.3. `index.php` — несколько плашек одновременно (главная правка ТЗ)

Строки ~914–918 сейчас:
```php
<?php if ($isAdmin): ?>
    <span class="tg-admin-tag">admin</span>
<?php elseif ($isPackDesigner): ?>
    <span class="tg-admin-tag" title="Designer PPK">PPK</span>
<?php endif; ?>
```
Замените на (убираем `elseif`, чтобы обе плашки могли показаться вместе):
```php
<?php if ($isAdmin): ?><span class="tg-admin-tag">ADMIN</span><?php endif; ?>
<?php if ($isPackDesigner): ?><span class="tg-admin-tag" title="Designer PPK">PPK</span><?php endif; ?>
```
Это и есть тот самый пункт ТЗ: «у главного админа — сразу ADMIN и PPK, у
обычных владельцев пака — только PPK» — раньше `elseif` физически не давал
показать вторую плашку никому.

Там же, где вычисляется `$isPackDesigner` в `index.php`, замените
`isPackDesigner(...)` на `isPackDesignerFull(...)` (см. 4.1), чтобы ручная
выдача роли тоже отражалась плашкой.

### 4.4. `profile.php` — та же плашка в шапке профиля

В `profile.php` сейчас нет `$isPackDesigner` вообще. Добавьте рядом с местом,
где считается `$isAdmin` (см. `grep -n "isAdmin" profile.php`):
```php
require_once __DIR__ . '/includes/pack_role.php';
require_once __DIR__ . '/includes/badges.php';
ensurePpkManualSchema($pdo);
$botTokenForRole  = getSiteSetting($pdo, 'BOT_TOKEN') ?: (getenv('TELEGRAM_BOT_TOKEN') ?: getenv('BOT_TOKEN') ?: '');
$groupChatForRole = getSiteSetting($pdo, 'PRIVATE_CHAT_ID') ?: (getenv('PRIVATE_CHAT_ID') ?: '');
$isPackDesigner   = isPackDesignerFull($pdo, $botTokenForRole, $groupChatForRole, (string)($profile['tg_id'] ?? ''), $isAdmin);
```
(если в файле уже есть функция `getSiteSetting` — не дублируйте, используйте её).

Затем в блоке `.profile-name` (строка ~1057) добавьте после плашки admin:
```php
<?php if ($isPackDesigner): ?><span style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:0.5px;color:#fb923c;background:rgba(249,115,22,0.15);border:1px solid rgba(249,115,22,0.35);border-radius:5px;padding:2px 7px;vertical-align:middle;margin-left:6px;">PPK</span><?php endif; ?>
```

### 4.5. `includes/section_tabs.php` — пункт меню «Приват Пак (PPK)»

Добавьте четвёртый пункт в `<nav class="section-tabs">`, перед `</nav>`:
```php
<?php
$ppkHasAccess = $ppkHasAccess ?? false;
include __DIR__ . '/ppk_nav_modal.php';
?>
```
Во всех местах, где подключается `section_tabs.php` (главная, поддержка,
заказы), перед `include` нужно посчитать `$ppkHasAccess` так же, как
`$isPackDesigner` считается в `resources.php` (или переиспользовать уже
посчитанный `$isPackDesigner`, если он есть на странице):
```php
$ppkHasAccess = $isAdmin || $isPackDesigner;
```
Если на какой-то странице роль ещё не вычислялась — добавьте вычисление, как
в 4.4 выше.

Не забудьте подключить `assets/kostlim-upgrade.css` вторым `<link>`
(после `style.css`) на всех страницах, где используется модалка/бейджи:
```html
<link rel="stylesheet" href="assets/kostlim-upgrade.css?v=<?= @filemtime(__DIR__ . '/assets/kostlim-upgrade.css') ?: time() ?>">
```

### 4.6. Ссылки в меню админки на новые страницы

В существующем меню `admin/index.php` (там, где перечислены остальные разделы
админки, например рядом со ссылкой на `resources.php`) добавьте две ссылки:
```html
<a href="ppk_manager.php" class="admin-nav-link">🎨 PPK — доступ и ключи</a>
<a href="ai_trainer_review.php" class="admin-nav-link">🎮 Тренажёр — результаты</a>
```
(класс `admin-nav-link` — подставьте фактический класс пунктов вашего меню
в `admin/index.php`, чтобы визуально не выделялись).

### 4.7. Табы категорий ресурсов — уже в едином стиле

`resources.php` уже использует `.res-tab-btn` со своей активной подсветкой —
отдельно приводить к `.section-tab`/`.qa-star` с главной не требуется, стили
визуально совпадают (тот же `--accent`, тот же радиус). Если хочется 1-в-1
переиспользовать классы главной, замените `.res-tab-btn` на `.section-tab` в
разметке — CSS уже общий.

---

## Шаг 5. Что нужно донести руками (не код)

- **Видео/превью в модалке PPK** (`includes/ppk_nav_modal.php`) ссылается на
  `/assets/img/ppk_preview.mp4` и постер `ppk_preview_poster.jpg` — загрузите
  свои файлы с этими именами (или поменяйте пути в файле на нужные).
- **Кнопка «Приобрести пак»** сейчас ведёт на `https://t.me/Perlo_ovka` (как в
  вашем текущем `ai_support.php`) — поменяйте на актуальный контакт/бота для
  продажи, если он другой.

## Шаг 6. Проверка после деплоя

1. Зайти под главным аккаунтом-админом → на главной в плашке профиля должны
   показаться **ADMIN и PPK одновременно**.
2. Зайти обычным участником приватной группы → должна показаться только **PPK**.
3. Зайти пользователем без доступа → клик по «Приват Пак» в меню открывает
   модалку с описанием и кнопкой «Приобрести пак», без 403-страницы.
4. В `admin/ppk_manager.php` выдать PPK по TG ID вручную → у этого пользователя
   должен открыться `resources.php`, `ai_trainer.php`, `planner.php` без
   членства в Telegram-группе.
5. Сгенерировать ключ там же → ввести его в модалке PPK под непривязанным (но
   вошедшим через Telegram) пользователем → доступ должен появиться сразу.
6. В `resources.php` добавить шрифт с реально загружаемым файлом → кнопка
   должна сразу скачивать файл, а не открывать вкладку Google Drive/сайта.
7. Добавить PSD-пост со ссылкой на сообщение в теме (топике) канала → кнопка
   «Открыть в Telegram» должна вести ровно на этот пост внутри темы.
8. `ai_trainer.php`: пройти анкету → получить ТЗ от ИИ-клиента → пару
   сообщений → «Сдать работу» с картинкой → получить оценку 0–100 и отзыв →
   «Поделиться с Kostlim» → результат должен прийти админу в Telegram и
   появиться в `admin/ai_trainer_review.php`.
9. `planner.php`: добавить/отредактировать/удалить строку клиента, обновить
   страницу — данные должны сохраниться (автосохранение по изменению поля).

## Известные ограничения / что стоит учесть

- Оценка тренажёра работает только для сдачи **изображением** (jpg/png/webp) —
  так проще: она разбирает превью визуально через мультимодальный запрос к
  Gemini. Если нужно принимать PSD/архивы «как есть» без анализа содержимого —
  это отдельная (более простая) ветка логики, могу добавить отдельно.
- Все новые таблицы и файлы рассчитаны на то, что в проекте уже есть
  `config/db.php` (PostgreSQL/Neon) и `includes/session.php`/`tg_links` —
  как в существующих `resources.php`/`ai_support.php`. Если структура
  `tg_links` у вас отличается от предположенной (`tg_id`, `session_id`,
  `linked`), поправьте `SELECT`-запросы в новых файлах под неё.
- Код не тестировался на вашем реальном сервере/БД/токенах — прогоните чек-лист
  из шага 6 на тестовом окружении перед раскаткой на прод.
