<?php
/** includes/pay_lib.php — вся логика оплаты (Postgres + PDO) */
require_once __DIR__ . '/order_flow.php';   // computeOrderPriceWithPromo(), getOrderServiceTitle()

function payEnv(string $k, string $d = ''): string { $v = getenv($k); return ($v === false || $v === '') ? $d : (string)$v; }
function payBotToken(): string { return payEnv('BOT_TOKEN', payEnv('TELEGRAM_BOT_TOKEN')); }
function paySiteUrl(): string {
    $u = payEnv('SITE_URL');
    if ($u === '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $u = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return rtrim($u, '/');
}
function paySecret(): string { return payEnv('PAY_SECRET', payBotToken() . '|kostlim-pay'); }
function payToken(int $id): string { return substr(hash_hmac('sha256', 'pay:' . $id, paySecret()), 0, 32); }
function payTokenOk(int $id, string $t): bool { return $t !== '' && hash_equals(payToken($id), $t); }
/** Ссылка, которую нужно класть в кнопку «Оплатить на сайте» в Telegram */
function payLink(int $id): string { return paySiteUrl() . '/pay.php?id=' . $id . '&t=' . payToken($id); }
function paySuccessLink(int $id): string { return paySiteUrl() . '/success.php?id=' . $id . '&t=' . payToken($id); }

function payCurrencies(): array {
    return [
        'UAH' => ['symbol' => '₴', 'name' => 'Гривны',  'flag' => '🇺🇦', 'dec' => 0],
        'RUB' => ['symbol' => '₽', 'name' => 'Рубли',   'flag' => '🇷🇺', 'dec' => 0],
        'KZT' => ['symbol' => '₸', 'name' => 'Тенге',   'flag' => '🇰🇿', 'dec' => 0],
        'USD' => ['symbol' => '$', 'name' => 'Доллары', 'flag' => '🇺🇸', 'dec' => 2],
        'EUR' => ['symbol' => '€', 'name' => 'Евро',    'flag' => '🇪🇺', 'dec' => 2],
    ];
}
function payMethods(): array {
    return [
        'monobank'       => ['title' => 'Monobank',          'icon' => '🐈‍⬛', 'desc' => 'Банка Monobank · Visa / Mastercard · Apple/Google Pay', 'currencies' => ['UAH']],
        'donationalerts' => ['title' => 'DonationAlerts',    'icon' => '💸', 'desc' => 'Карты РФ/СНГ, СБП, ЮMoney, PayPal',                       'currencies' => ['RUB', 'KZT']],
        'cryptobot'      => ['title' => 'Crypto Bot (USDT)', 'icon' => '🪙', 'desc' => 'USDT, TON, BTC, ETH или карта внутри бота',               'currencies' => ['USD', 'EUR']],
    ];
}
function payMethodConfigured(string $m): bool {
    return match ($m) {
        'monobank'  => payEnv('MONO_JAR_URL') !== '',
        'cryptobot' => payEnv('CRYPTO_KEY') !== '',
        'donationalerts' => true,
        default => false,
    };
}
function payMethodTitle(string $m): string { return payMethods()[$m]['title'] ?? $m; }

function ensurePaySchema(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    foreach ([
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS pay_currency VARCHAR(8)",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS pay_amount NUMERIC(12,2)",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS pay_method VARCHAR(20)",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS pay_invoice_id VARCHAR(64)",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS paid_at TIMESTAMP",
    ] as $sql) { try { $pdo->exec($sql); } catch (Throwable $e) {} }
}

function payGetOrder(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM orders WHERE id = ?"); $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC); return $r ?: null;
}
function payIsPaid(array $o): bool { return !empty($o['paid_at']); }
/** Оплачивать можно заказ, который дизайнер принял (статус awaiting_payment в вашем боте) */
function payIsPayable(array $o): bool {
    return strtolower((string)($o['status'] ?? '')) === 'awaiting_payment' && empty($o['paid_at']);
}

function payRate(string $k, float $d): float { $v = (float)str_replace(',', '.', payEnv($k)); return $v > 0 ? $v : $d; }

/** Суммы заказа во всех валютах. Базовые цены — из вашей computeOrderPriceWithPromo() (₴ и ₽). */
function payAmounts(PDO $pdo, array $o): array {
    $uan = 0.0; $rub = 0.0;
    try { $c = computeOrderPriceWithPromo($pdo, $o); $uan = (float)($c['final_uan'] ?? 0); $rub = (float)($c['final_rub'] ?? 0); } catch (Throwable $e) {}
    $usd = payRate('USD_UAH', 41.5); $eur = payRate('EUR_UAH', 48.5); $kzt = payRate('KZT_PER_RUB', 6.2);
    return [
        'UAH' => round($uan),
        'RUB' => round($rub),
        'KZT' => (float)ceil($rub * $kzt),
        'USD' => ceil($uan / $usd * 100) / 100,
        'EUR' => ceil($uan / $eur * 100) / 100,
    ];
}
function payFormat(float $a, string $cur): string {
    $c = payCurrencies()[$cur] ?? ['symbol' => $cur, 'dec' => 2];
    if ($cur === 'USD' || $cur === 'EUR') return $c['symbol'] . number_format($a, 2, '.', '');
    return number_format($a, (int)$c['dec'], '.', ' ') . ' ' . $c['symbol'];
}

function payTg($chat, string $html): void {
    $tok = payBotToken(); if ($tok === '' || !$chat) return;
    $ch = curl_init("https://api.telegram.org/bot{$tok}/sendMessage");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'])]);
    curl_exec($ch); curl_close($ch);
}

/** Идемпотентно проводит оплату так же, как приём чека в боте: статус → in_progress/urgent, дедлайн, referral. */
function markOrderPaid(PDO $pdo, int $id, string $method, string $cur, float $amount): bool {
    ensurePaySchema($pdo);
    $o = payGetOrder($pdo, $id);
    if (!$o || !empty($o['paid_at'])) return false;
    $isUrgent = !empty($o['is_urgent']);
    $newStatus = $isUrgent ? 'urgent' : 'in_progress';
    $deadline = function_exists('calculateOrderDeadline') ? calculateOrderDeadline($isUrgent) : date('Y-m-d H:i:s', time() + ($isUrgent ? 24 : 120) * 3600);
    $st = $pdo->prepare("UPDATE orders SET
            status = CASE WHEN status = 'awaiting_payment' THEN ? ELSE status END,
            deadline = CASE WHEN status = 'awaiting_payment' THEN ? ELSE deadline END,
            started_at = COALESCE(started_at, NOW()),
            payment_status = 'receipt_received', payment_received_at = NOW(),
            pay_method = ?, pay_currency = ?, pay_amount = ?, paid_at = NOW()
          WHERE id = ? AND paid_at IS NULL RETURNING id");
    $st->execute([$newStatus, $deadline, $method, $cur, $amount, $id]);
    if (!$st->fetchColumn()) return false;

    if (!empty($o['client_chat_id']) && function_exists('awardReferralBonusIfApplicable')) {
        try { awardReferralBonusIfApplicable($pdo, (string)$o['client_chat_id'], $id); } catch (Throwable $e) { error_log('[pay] referral: ' . $e->getMessage()); }
    }
    $title = ''; try { $title = getOrderServiceTitle($pdo, $o); } catch (Throwable $e) {}
    $sum = payFormat($amount, $cur);
    payTg(payEnv('ADMIN_ID', '1710365896'),
        "💰 <b>Заказ #{$id} ОПЛАЧЕН!</b>\n\n🎨 Услуга: " . htmlspecialchars($title) . "\n💵 Сумма: <b>{$sum}</b> ({$cur})\n💳 Система: " . htmlspecialchars(payMethodTitle($method)) . "\n📅 Дедлайн: " . date('d.m.Y H:i', strtotime($deadline)) . "\n\nЗаказ автоматически переведён в работу.");
    if (!empty($o['client_chat_id'])) {
        payTg($o['client_chat_id'], "✅ <b>Оплата заказа #{$id} получена!</b>\nСумма: {$sum}\nДизайнер приступает к работе.\n📅 Дедлайн: " . date('d.m.Y H:i', strtotime($deadline)) . "\n\n" . paySuccessLink($id));
    }
    return true;
}

function payThrottle(string $key, int $sec): bool {
    $f = sys_get_temp_dir() . '/pay_thr_' . md5($key);
    $last = is_file($f) ? (int)@file_get_contents($f) : 0;
    if (time() - $last < $sec) return false;
    @file_put_contents($f, (string)time());
    return true;
}
/** Ищет номер заказа в комментарии: "Order #31", "Order_31", "Заказ 31" */
function payParseOrderId(string $text): int {
    return preg_match('/(?:order|заказ|zakaz)\s*[#№_\-]?\s*(\d{1,9})\b/iu', $text, $m) ? (int)$m[1] : 0;
}
function payExpected(PDO $pdo, array $o, string $cur): float {
    if (($o['pay_currency'] ?? '') === $cur && (float)($o['pay_amount'] ?? 0) > 0) return (float)$o['pay_amount'];
    return (float)(payAmounts($pdo, $o)[$cur] ?? 0);
}
function payHttp(string $url, array $headers = [], ?string $post = null, int $timeout = 15): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => $headers]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $r = curl_exec($ch); curl_close($ch);
    $j = json_decode((string)$r, true);
    return is_array($j) ? $j : null;
}

// ───────────── Crypto Bot ─────────────
function cryptoBase(): string { return payEnv('CRYPTO_TESTNET') === '1' ? 'https://testnet-pay.crypt.bot/api/' : 'https://pay.crypt.bot/api/'; }
function cryptoCreateInvoice(int $orderId, string $fiat, float $amount): ?array {
    $q = http_build_query([
        'currency_type' => 'fiat', 'fiat' => $fiat, 'amount' => number_format($amount, 2, '.', ''),
        'accepted_assets' => 'USDT,TON,BTC,ETH,USDC', 'description' => "Order #{$orderId}", 'payload' => (string)$orderId,
        'paid_btn_name' => 'callback', 'paid_btn_url' => paySuccessLink($orderId), 'allow_comments' => 'false', 'expires_in' => 3600,
    ]);
    $r = payHttp(cryptoBase() . 'createInvoice', ['Crypto-Pay-API-Token: ' . payEnv('CRYPTO_KEY'), 'Content-Type: application/x-www-form-urlencoded'], $q);
    return (!empty($r['ok']) && !empty($r['result'])) ? $r['result'] : null;
}
function checkCryptoInvoice(PDO $pdo, array $o): bool {
    if (empty($o['pay_invoice_id'])) return false;
    $r = payHttp(cryptoBase() . 'getInvoices?invoice_ids=' . urlencode($o['pay_invoice_id']), ['Crypto-Pay-API-Token: ' . payEnv('CRYPTO_KEY')]);
    $res = $r['result'] ?? null; if (!$res) return false;
    $items = $res['items'] ?? $res;
    $inv = $items[0] ?? null;
    if ($inv && ($inv['status'] ?? '') === 'paid') {
        return markOrderPaid($pdo, (int)$o['id'], 'cryptobot', (string)($o['pay_currency'] ?: 'USD'), (float)$o['pay_amount']);
    }
    return false;
}

// ───────────── Monobank (выписка банки) ─────────────
function checkMonobankAll(PDO $pdo): int {
    $tok = payEnv('MONO_KEY'); if ($tok === '') return 0;
    $acc = payEnv('MONO_ACCOUNT', '0');   // id банки из /personal/client-info → jars[].id ; '0' = основной счёт
    $from = time() - 3 * 86400;
    $r = payHttp("https://api.monobank.ua/personal/statement/{$acc}/{$from}/" . time(), ['X-Token: ' . $tok]);
    if (!is_array($r) || isset($r['errorDescription'])) return 0;
    $n = 0;
    foreach ($r as $it) {
        if (!is_array($it) || ($it['amount'] ?? 0) <= 0) continue;
        $id = payParseOrderId(($it['comment'] ?? '') . ' ' . ($it['description'] ?? ''));
        if ($id <= 0) continue;
        $o = payGetOrder($pdo, $id); if (!$o || payIsPaid($o)) continue;
        $need = payExpected($pdo, $o, 'UAH');
        if ($need > 0 && ($it['amount'] / 100) + 1 >= $need && markOrderPaid($pdo, $id, 'monobank', 'UAH', $need)) $n++;
    }
    return $n;
}

// ───────────── DonationAlerts ─────────────
function checkDonationAlerts(PDO $pdo): int {
    $tok = payEnv('DA_ACCESS_TOKEN'); if ($tok === '') return 0;   // OAuth-токен со scope oauth-donation-index
    $r = payHttp('https://www.donationalerts.com/api/v1/alerts/donations', ['Authorization: Bearer ' . $tok]);
    $n = 0;
    foreach (($r['data'] ?? []) as $d) {
        $id = payParseOrderId((string)($d['message'] ?? ''));
        if ($id <= 0) continue;
        $o = payGetOrder($pdo, $id); if (!$o || payIsPaid($o)) continue;
        $cur = strtoupper((string)($d['currency'] ?? ''));
        $need = payExpected($pdo, $o, $cur);
        if ($need > 0 && (float)($d['amount'] ?? 0) + 1 >= $need && markOrderPaid($pdo, $id, 'donationalerts', $cur, $need)) $n++;
    }
    return $n;
}
