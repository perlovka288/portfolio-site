<?php
/**
 * includes/payment_methods.php
 * Блок «Способы оплаты» с указанием валют, в которых работает каждая система.
 *
 * Использование:
 *   require_once __DIR__ . '/payment_methods.php';
 *   echo renderPaymentMethodsInfo('UAH');   // 'UAH' | 'RUB' | 'USD' | 'KZT' | ...
 *
 * Все данные — в функции getPaymentMethodsMatrix(). Если какая-то система
 * добавит/уберёт валюту — правится только там.
 */

/** Символы и названия валют */
function getCurrencyMeta(): array {
    return [
        'UAH' => ['symbol' => '₴', 'name' => 'Гривны',            'flag' => '🇺🇦'],
        'RUB' => ['symbol' => '₽', 'name' => 'Рубли',             'flag' => '🇷🇺'],
        'USD' => ['symbol' => '$', 'name' => 'Доллары',           'flag' => '🇺🇸'],
        'EUR' => ['symbol' => '€', 'name' => 'Евро',              'flag' => '🇪🇺'],
        'KZT' => ['symbol' => '₸', 'name' => 'Тенге',             'flag' => '🇰🇿'],
        'BYN' => ['symbol' => 'Br','name' => 'Белорусские рубли', 'flag' => '🇧🇾'],
        'PLN' => ['symbol' => 'zł','name' => 'Злотые',            'flag' => '🇵🇱'],
        'TRY' => ['symbol' => '₺', 'name' => 'Лиры',              'flag' => '🇹🇷'],
        'USDT'=> ['symbol' => '₮', 'name' => 'USDT (крипта)',     'flag' => '🪙'],
    ];
}

/**
 * Матрица: система → валюты и чем именно можно платить.
 * 'main'  — валюты, в которых платёж проходит напрямую, без конвертации.
 * 'other' — валюты, которыми тоже можно платить (с конвертацией на стороне банка/сервиса).
 * ВАЖНО: список валют DonationAlerts сверьте в своём кабинете — он может меняться.
 */
function getPaymentMethodsMatrix(): array {
    return [
        'monobank' => [
            'title' => 'Monobank',
            'icon'  => '🐈‍⬛',
            'badge' => 'Украина',
            'main'  => ['UAH'],
            'other' => [],
            'ways'  => ['Карта Visa / Mastercard', 'Apple Pay / Google Pay'],
            'note'  => 'Только гривны ₴.',
        ],
        'cryptobot' => [
            'title' => 'Crypto Bot',
            'icon'  => '🪙',
            'badge' => 'Весь мир',
            'main'  => ['USD', 'EUR'],
            'other' => [],
            'ways'  => ['USDT / TON / BTC / ETH', 'Карта внутри @CryptoBot', 'P2P-обмен на USDT'],
            'note'  => 'Доллары $ и евро €.',
        ],
        'donationalerts' => [
            'title' => 'DonationAlerts',
            'icon'  => '💸',
            'badge' => 'Россия / СНГ',
            'main'  => ['RUB', 'KZT', 'BYN'],
            'other' => ['UAH', 'PLN', 'TRY'],
            'ways'  => ['Карты РФ / СНГ', 'СБП', 'ЮMoney', 'PayPal'],
            'note'  => 'Рубли ₽, тенге ₸, белорусские рубли и другие валюты СНГ.',
        ],
    ];
}

/** Какая система по умолчанию рекомендуется для валюты */
function getRecommendedMethod(string $currency): string {
    return match (strtoupper($currency)) {
        'UAH'                     => 'monobank',
        'RUB', 'BYN', 'KZT'       => 'donationalerts',
        default                   => 'cryptobot',
    };
}

/** Короткий чипс «₴ Гривны» */
function renderCurrencyChip(string $code, bool $main = true): string {
    $meta = getCurrencyMeta();
    $m = $meta[$code] ?? ['symbol' => '', 'name' => $code, 'flag' => ''];
    $cls = $main ? 'pm-chip pm-chip-main' : 'pm-chip pm-chip-other';
    return '<span class="' . $cls . '" data-cur="' . htmlspecialchars($code) . '">'
         . $m['flag'] . ' ' . htmlspecialchars($code) . ' ' . htmlspecialchars($m['symbol'])
         . '</span>';
}

/** Главная функция: отдаёт готовый HTML блока */
function renderPaymentMethodsInfo(string $activeCurrency = 'UAH'): string {
    $matrix      = getPaymentMethodsMatrix();
    $meta        = getCurrencyMeta();
    $activeCurrency = strtoupper($activeCurrency);
    $recommended = getRecommendedMethod($activeCurrency);

    ob_start(); ?>
    <div class="pm-block" id="pmBlock" data-active-currency="<?= htmlspecialchars($activeCurrency) ?>">
        <div class="pm-head">
            <h3 class="pm-title">💳 Способы оплаты</h3>
            <p class="pm-sub">Выберите систему под вашу валюту — внизу видно, чем и где можно заплатить.</p>
        </div>

        <div class="pm-list">
        <?php foreach ($matrix as $key => $m):
            $supports = in_array($activeCurrency, array_merge($m['main'], $m['other']), true);
            $isRec    = ($key === $recommended); ?>
            <div class="pm-card <?= $isRec ? 'pm-recommended' : '' ?> <?= $supports ? '' : 'pm-dim' ?>"
                 data-method="<?= htmlspecialchars($key) ?>"
                 data-currencies="<?= htmlspecialchars(implode(',', array_merge($m['main'], $m['other']))) ?>">
                <div class="pm-card-top">
                    <span class="pm-icon"><?= $m['icon'] ?></span>
                    <div class="pm-card-name">
                        <b><?= htmlspecialchars($m['title']) ?></b>
                        <small><?= htmlspecialchars($m['badge']) ?></small>
                    </div>
                    <span class="pm-rec-tag" <?= $isRec ? '' : 'hidden' ?>>Рекомендуем</span>
                </div>

                <div class="pm-row">
                    <span class="pm-row-label">Платите в:</span>
                    <div class="pm-chips">
                        <?php foreach ($m['main'] as $c)  echo renderCurrencyChip($c, true); ?>
                    </div>
                </div>

                <?php if (!empty($m['other'])): ?>
                <div class="pm-row">
                    <span class="pm-row-label">Также:</span>
                    <div class="pm-chips">
                        <?php foreach ($m['other'] as $c) echo renderCurrencyChip($c, false); ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="pm-row">
                    <span class="pm-row-label">Чем платить:</span>
                    <ul class="pm-ways">
                        <?php foreach ($m['ways'] as $w): ?><li><?= htmlspecialchars($w) ?></li><?php endforeach; ?>
                    </ul>
                </div>

                <p class="pm-note"><?= htmlspecialchars($m['note']) ?></p>
            </div>
        <?php endforeach; ?>
        </div>

        <div class="pm-region-hint">
            <b>Быстрая подсказка:</b>
            🇺🇦 гривны → Monobank · 🇷🇺 рубли → DonationAlerts · 🇰🇿 тенге / 🇧🇾 BYN → DonationAlerts ·
            🇺🇸🇪🇺 доллары и евро → Crypto Bot
        </div>
    </div>
    <?php
    return ob_get_clean();
}
