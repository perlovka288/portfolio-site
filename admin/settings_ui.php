<?php
/**
 * «Ключи и API» — интерфейс в стиле iOS «Настройки»: секции-карточки, тумблеры, сегменты, выпадающие списки,
 * менеджер нескольких ключей (активный/резервные), маскирование, копирование, индикаторы статуса.
 * Подключается внутри <section data-panel="keys"> из admin/index.php (там уже есть $pdo, $API_KEY_FIELDS, getSetting()).
 * Логика сохранения: admin/index.php (save_api_keys), проверка статусов: admin/api_status.php, поведение: assets/kostlim-settings.js
 */
require_once __DIR__ . '/../includes/imgbb.php';
require_once __DIR__ . '/../includes/image_store.php';

$kEff = function (string $k) use ($pdo): string {                    // значение, которое реально действует сейчас
    $db = getSetting($pdo, $k, '');
    if ($db !== '') { return $db; }
    $e = getenv($k);
    return ($e === false) ? '' : trim((string)$e);
};
$kSrc = function (string $k) use ($pdo): string {                    // откуда оно: админка / окружение / нет
    if (getSetting($pdo, $k, '') !== '') { return 'admin'; }
    $e = getenv($k);
    return ($e !== false && trim((string)$e) !== '') ? 'env' : '';
};
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

/** Одна строка-поле как в iOS: слева название, справа значение. */
$row = function (string $key, string $label, array $o = []) use ($kEff, $kSrc, $h): void {
    $val = $kEff($key); $secret = !empty($o['secret']); $src = $kSrc($key);
    ?>
    <div class="ios-row" data-row="<?= $h($key) ?>">
        <label class="ios-label" for="k-<?= $h($key) ?>"><?= $label ?>
            <?php if (!empty($o['hint'])): ?><small><?= $h($o['hint']) ?></small><?php endif; ?>
            <?php if ($src === 'env'): ?><small class="ios-src">из окружения Render</small><?php endif; ?>
        </label>
        <div class="ios-ctl">
            <?php if (!empty($o['select'])): ?>
                <?php $opts = $o['select']; if ($val !== '' && !isset($opts[$val])) { $opts = [$val => $val] + $opts; }
                      $selVal = ($val !== '') ? $val : (string)($o['default'] ?? array_key_first($opts)); ?>
                <select class="ios-in ios-select" name="<?= $h($key) ?>" id="k-<?= $h($key) ?>" data-orig="<?= $h($selVal) ?>">
                    <?php foreach ($opts as $v => $t): ?><option value="<?= $h($v) ?>" <?= ($selVal === (string)$v) ? 'selected' : '' ?>><?= $h($t) ?></option><?php endforeach; ?>
                </select>
            <?php else: ?>
                <input class="ios-in<?= $secret ? ' ios-mask' : '' ?>" type="text" name="<?= $h($key) ?>" id="k-<?= $h($key) ?>"
                       value="<?= $h($val) ?>" data-orig="<?= $h($val) ?>" placeholder="<?= $h($o['ph'] ?? 'не задано') ?>" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">
                <?php if ($secret): ?><button type="button" class="ios-mini" data-eye title="Показать / скрыть">👁</button><?php endif; ?>
                <button type="button" class="ios-mini" data-copy title="Копировать">⧉</button>
            <?php endif; ?>
        </div>
    </div>
    <?php
};
/** Тумблер. */
$switch = function (string $key, string $label, bool $on, string $hint = '') use ($h): void { ?>
    <div class="ios-row ios-row-switch">
        <label class="ios-label" for="k-<?= $h($key) ?>"><?= $label ?><?php if ($hint): ?><small><?= $h($hint) ?></small><?php endif; ?></label>
        <div class="ios-ctl"><input type="checkbox" class="ios-switch" id="k-<?= $h($key) ?>" data-bool="<?= $h($key) ?>" data-orig="<?= $on ? '1' : '0' ?>" <?= $on ? 'checked' : '' ?>></div>
    </div>
<?php };
/** Индикатор статуса сервиса. */
$status = function (string $svc, string $title, array $fields, bool $configured) use ($h): void { ?>
    <div class="ios-row ios-row-status">
        <div class="ios-label"><?= $h($title) ?><small class="ios-st-msg"><?= $configured ? 'Не проверено' : 'Не настроено' ?></small></div>
        <div class="ios-ctl">
            <span class="ios-dot <?= $configured ? 'unknown' : 'unset' ?>" data-dot></span>
            <button type="button" class="ios-btn" data-check="<?= $h($svc) ?>" data-fields="<?= $h(json_encode($fields)) ?>">Проверить</button>
        </div>
    </div>
<?php };
/** Менеджер нескольких ключей. */
$keyset = function (string $setKey, string $svc, string $title, array $seed) use ($pdo, $h): void {
    $raw = getSetting($pdo, $setKey, '');
    $state = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($state) || empty($state['keys'])) {
        $keys = [];
        foreach ($seed as $v) { if ($v !== '') { $keys[] = ['v' => $v, 'on' => true, 'label' => '', 'src' => 'env']; } }
        $state = ['active' => 0, 'keys' => $keys];
    }
    ?>
    <div class="ios-keys" data-keyset="<?= $h($setKey) ?>" data-svc="<?= $h($svc) ?>" data-state="<?= $h(json_encode($state, JSON_UNESCAPED_UNICODE)) ?>" data-orig="<?= $h(json_encode($state, JSON_UNESCAPED_UNICODE)) ?>">
        <div class="ios-keys-head"><b><?= $h($title) ?></b><small>Первый отмеченный ● — активный, остальные — резерв: сайт сам переключится, если активный откажет.</small></div>
        <div class="ios-keylist"></div>
        <div class="ios-keys-foot">
            <button type="button" class="ios-btn ghost" data-addkey>＋ Добавить ключ</button>
            <button type="button" class="ios-btn" data-checkall>Проверить все</button>
        </div>
    </div>
<?php };

$hasCloud  = cloudinaryCreds($pdo) !== null;
$hasTg     = $kEff('BOT_TOKEN') !== '' || $kEff('TELEGRAM_BOT_TOKEN') !== '';
$hasTurn   = $kEff('TURNSTILE_SECRET_KEY') !== '';
$imgbbSeed = array_values(array_unique(array_filter(imgbbKeys($pdo))));
$stor      = strtolower($kEff('IMAGE_STORAGE')); if (!in_array($stor, ['cloudinary', 'imgbb'], true)) { $stor = 'auto'; }
$fb        = getSetting($pdo, 'IMGBB_FALLBACK', '') !== '0';
?>
<div class="ios-head">
    <h2>🔑 Ключи и API</h2>
    <p>Всё, что введено здесь, сразу применяется ко всему сайту (боту, заказам, загрузкам) — переменные окружения на Render больше не обязательны. Пустое поле = использовать значение из Render.</p>
</div>

<form id="api-keys-form" class="ios-form" onsubmit="return false;" autocomplete="off">

<details class="ios-sec" open>
    <summary><span class="ios-ic" style="background:#0a84ff">☁️</span><span class="ios-t">Хранилище картинок</span><span class="ios-chev">›</span></summary>
    <div class="ios-card">
        <div class="ios-row ios-row-seg">
            <div class="ios-label">Куда сохранять<small>Авто = Cloudinary, если подключён, иначе ImgBB</small></div>
            <div class="ios-ctl">
                <div class="ios-seg" data-seg="IMAGE_STORAGE" data-orig="<?= $h($stor) ?>">
                    <button type="button" data-v="auto" class="<?= $stor === 'auto' ? 'on' : '' ?>">Авто</button>
                    <button type="button" data-v="cloudinary" class="<?= $stor === 'cloudinary' ? 'on' : '' ?>">Cloudinary</button>
                    <button type="button" data-v="imgbb" class="<?= $stor === 'imgbb' ? 'on' : '' ?>">ImgBB</button>
                </div>
                <input type="hidden" name="IMAGE_STORAGE" value="<?= $h($stor) ?>" data-orig="<?= $h($stor) ?>">
            </div>
        </div>
        <?php $row('CLOUDINARY_CLOUD_NAME', 'Cloudinary · cloud name', ['ph' => 'например, dxyz123']); ?>
        <?php $row('CLOUDINARY_API_KEY', 'Cloudinary · API key', ['secret' => true]); ?>
        <?php $row('CLOUDINARY_API_SECRET', 'Cloudinary · API secret', ['secret' => true]); ?>
        <?php $status('cloudinary', 'Статус Cloudinary', ['cloud' => 'CLOUDINARY_CLOUD_NAME', 'key' => 'CLOUDINARY_API_KEY', 'secret' => 'CLOUDINARY_API_SECRET'], $hasCloud); ?>
        <?php $switch('IMGBB_FALLBACK', 'ImgBB как запасное хранилище', $fb, 'Если Cloudinary откажет — попробовать ImgBB'); ?>
        <?php $keyset('KEYSET_IMGBB', 'imgbb', 'Ключи ImgBB', $imgbbSeed); ?>
    </div>
</details>

<details class="ios-sec" open>
    <summary><span class="ios-ic" style="background:#30a8e8">✈️</span><span class="ios-t">Telegram</span><span class="ios-chev">›</span></summary>
    <div class="ios-card">
        <?php $row('BOT_TOKEN', 'Токен бота', ['secret' => true]); ?>
        <?php $status('telegram', 'Статус бота', ['key' => 'BOT_TOKEN'], $hasTg); ?>
        <?php $row('PORTFOLIO_CHANNEL_CHAT', 'Канал портфолио', ['ph' => '@designkostlim']); ?>
        <?php $row('PRIVATE_CHAT_ID', 'Приватный чат PSD-паков', ['hint' => 'Только числовой ID вида -1001234567890. Узнать: команда /id в этой группе.']); ?>
        <?php $row('PRIVATE_CHAT_INVITE_LINK', 'Ссылка-приглашение в приватный чат', ['hint' => 't.me/+… — только для показа клиентам']); ?>
        <?php $row('ADMIN_TELEGRAM_ID', 'Telegram ID администратора'); ?>
    </div>
</details>

<details class="ios-sec">
    <summary><span class="ios-ic" style="background:#8e8e93">🌐</span><span class="ios-t">Сайт и контакты</span><span class="ios-chev">›</span></summary>
    <div class="ios-card">
        <?php $row('SITE_URL', 'Публичный URL сайта', ['ph' => 'https://…']); ?>
        <?php $row('ADMIN_EMAIL', 'Email администратора'); ?>
    </div>
</details>

<details class="ios-sec">
    <summary><span class="ios-ic" style="background:#af52de">✨</span><span class="ios-t">ИИ и сервисы</span><span class="ios-chev">›</span></summary>
    <div class="ios-card">
        <?php $keyset('KEYSET_GEMINI', 'gemini', 'Ключи Gemini (ИИ-помощник)', [$kEff('GEMINI_API_KEY')]); ?>
        <?php $row('GEMINI_MODEL', 'Модель Gemini', ['select' => ['gemini-2.0-flash' => 'gemini-2.0-flash', 'gemini-2.5-flash' => 'gemini-2.5-flash', 'gemini-2.5-pro' => 'gemini-2.5-pro', 'gemini-1.5-flash' => 'gemini-1.5-flash'], 'default' => 'gemini-2.0-flash']); ?>
        <?php $keyset('KEYSET_YT', 'youtube', 'Ключи YouTube API', [$kEff('YT_API_KEY')]); ?>
        <?php $row('GDRIVE_FOLDER_ID', 'Google Drive · ID папки'); ?>
    </div>
</details>

<details class="ios-sec">
    <summary><span class="ios-ic" style="background:#34c759">💳</span><span class="ios-t">Реквизиты для оплаты</span><span class="ios-chev">›</span></summary>
    <div class="ios-card">
        <?php $row('PAYMENT_REQUISITES_RUB', 'Карта · рубли ₽'); ?>
        <?php $row('PAYMENT_REQUISITES_UAH', 'Карта · гривны ₴'); ?>
        <?php $row('PAYMENT_REQUISITES_MONO', 'Монобанк'); ?>
        <?php $row('PAYMENT_REQUISITES_CRYPTO', 'Криптовалюта'); ?>
    </div>
</details>

<details class="ios-sec">
    <summary><span class="ios-ic" style="background:#ff9f0a">🛡</span><span class="ios-t">Защита от ботов (Turnstile)</span><span class="ios-chev">›</span></summary>
    <div class="ios-card">
        <?php $row('TURNSTILE_SITE_KEY', 'Site key'); ?>
        <?php $row('TURNSTILE_SECRET_KEY', 'Secret key', ['secret' => true]); ?>
        <?php $status('turnstile', 'Статус Turnstile', ['key' => 'TURNSTILE_SECRET_KEY'], $hasTurn); ?>
    </div>
</details>

<div class="ios-savebar"><span id="ios-dirty">Изменений нет</span><button type="button" class="ios-btn primary" id="keys-submit-btn" disabled>Сохранить</button></div>
</form>
