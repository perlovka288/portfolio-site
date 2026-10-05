<?php
/**
 * Покупка Приват Пака: заказ → оплата → уведомление админу → «Одобрить» →
 * доступ на сайте + одноразовая ссылка в приватный Telegram-чат.
 *
 * Поток:
 *   1. Клиент жмёт «Купить пак» (прайс / Приват Пак / бот) → buy_pack.php
 *   2. Платит (Monobank / DonationAlerts / Crypto Bot) и жмёт «Я оплатил(а)»
 *      (если платёж виден автоматически — статус станет «оплачен» сам)
 *   3. Админу в Telegram приходит «🛒 Клиент купил пак» с кнопками ✅ / ❌
 *   4. ✅ → ppk_manual_grants (доступ на сайте) + createChatInviteLink
 *      (member_limit=1 — ссылка одноразовая) → бот отправляет ссылку клиенту
 *   5. Когда клиент вошёл по ссылке — она отзывается (revokeChatInviteLink)
 *
 * Настройки (site_settings, правятся на странице admin/ppk_manager.php):
 *   PPK_PRICE_UAH / PPK_PRICE_RUB / PPK_PRICE_USD — цена пака
 *   PRIVATE_CHAT_ID                               — chat_id приватной группы (уже есть в «Ключи и API»)
 */
require_once __DIR__ . '/ppk_access.php';   // ppkSiteSetting(), pack_role.php, badges.php

// ───────────── окружение ─────────────
function ppkEnv(string $k, string $d = ''): string { $v = getenv($k); return ($v === false || $v === '') ? $d : (string)$v; }

function ppkBotToken(PDO $pdo): string
{
    return ppkSiteSetting($pdo, 'BOT_TOKEN') ?: ppkEnv('TELEGRAM_BOT_TOKEN', ppkEnv('BOT_TOKEN'));
}
function ppkAdminId(): string { return ppkEnv('ADMIN_ID', '1710365896'); }
function ppkSiteUrl(): string
{
    $u = ppkEnv('SITE_URL');
    if ($u === '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $u = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return rtrim($u, '/');
}
function ppkChatId(PDO $pdo): string
{
    return ppkResolveChatId(ppkSiteSetting($pdo, 'PRIVATE_CHAT_ID'));
}

function ppkSetSetting(PDO $pdo, string $key, string $value): void
{
    // site_settings в проекте создаётся в нескольких местах по-разному: где-то есть колонка updated_at,
    // где-то нет; в старых базах значение лежало в setting_value. Раньше запись падала с фаталом
    // (HTTP 500 при сохранении настроек). Теперь пробуем все варианты и только потом сдаёмся.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (setting_key VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL DEFAULT '')");
    } catch (Throwable $e) {}
    $variants = [
        "INSERT INTO site_settings (setting_key, value, updated_at) VALUES (?, ?, NOW()) ON CONFLICT (setting_key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()",
        "INSERT INTO site_settings (setting_key, value) VALUES (?, ?) ON CONFLICT (setting_key) DO UPDATE SET value = EXCLUDED.value",
        "INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value",
    ];
    $last = null; $ok = false;
    foreach ($variants as $sql) {
        try { $pdo->prepare($sql)->execute([$key, $value]); $ok = true; break; }
        catch (Throwable $e) { $last = $e; }
    }
    if (!$ok) { throw new RuntimeException('не удалось записать настройку ' . $key . ': ' . ($last ? $last->getMessage() : '')); }
    require_once __DIR__ . '/kui_cache.php';
    if (function_exists('kuiCacheForget')) { kuiCacheForget('settings_all'); }
}

/** Вызов Bot API. Возвращает декодированный ответ ([ok=>false,...] при сбое). */
function ppkTg(string $token, string $method, array $params = []): array
{
    if ($token === '') return ['ok' => false, 'description' => 'BOT_TOKEN не задан'];
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
        CURLOPT_POSTFIELDS => http_build_query($params),
    ]);
    $res = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    $d = json_decode((string)$res, true);
    if ($err !== '' || !is_array($d)) { return ['ok' => false, 'description' => $err !== '' ? $err : 'пустой ответ Telegram']; }
    if (empty($d['ok'])) {
        $m = "ppkTg {$method}: " . ($d['description'] ?? 'error');
        function_exists('botLog') ? botLog($m) : error_log($m);
    }
    return $d;
}

/** Сообщение админу (HTML). $markup — inline_keyboard или null. Возвращает message_id (0 — не вышло). */
function ppkNotifyAdmin(string $html, ?array $markup = null, ?PDO $pdoForToken = null): int
{
    global $pdo;
    $db = $pdoForToken ?: ($pdo ?? null);
    $token = $db instanceof PDO ? ppkBotToken($db) : ppkEnv('TELEGRAM_BOT_TOKEN', ppkEnv('BOT_TOKEN'));
    $p = ['chat_id' => ppkAdminId(), 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'];
    if ($markup) { $p['reply_markup'] = json_encode(['inline_keyboard' => $markup], JSON_UNESCAPED_UNICODE); }
    $r = ppkTg($token, 'sendMessage', $p);
    return (int)($r['result']['message_id'] ?? 0);
}

// ───────────── схема ─────────────
function ensurePpkPurchaseSchema__run(PDO $pdo): bool
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ppk_purchases (
            id SERIAL PRIMARY KEY,
            tg_id VARCHAR(64) NOT NULL,
            tg_username VARCHAR(128) NOT NULL DEFAULT '',
            tg_first_name VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'created',   -- created | claimed | paid | approved | rejected
            method VARCHAR(20) NOT NULL DEFAULT '',
            currency VARCHAR(8) NOT NULL DEFAULT '',
            amount NUMERIC(12,2) NOT NULL DEFAULT 0,
            invoice_id VARCHAR(64) NOT NULL DEFAULT '',
            invite_link TEXT NOT NULL DEFAULT '',
            invite_used BOOLEAN NOT NULL DEFAULT FALSE,
            invite_used_at TIMESTAMP,
            admin_msg_id BIGINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            claimed_at TIMESTAMP,
            approved_at TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ppk_purchases_tg ON ppk_purchases (tg_id)");
        return true;
    } catch (Throwable $e) {
        error_log('ensurePpkPurchaseSchema error: ' . $e->getMessage());
        return false;
    }
}
function ensurePpkPurchaseSchema(PDO $pdo): void
{
    if (!function_exists('kuiSchemaDone')) { require_once __DIR__ . '/schema_once.php'; }
    if (kuiSchemaDone('ensurePpkPurchaseSchema')) { return; }
    if (ensurePpkPurchaseSchema__run($pdo)) { kuiSchemaMark('ensurePpkPurchaseSchema'); }
}

// ───────────── цена ─────────────
function ppkPrices(PDO $pdo): array
{
    $out = [];
    foreach (['UAH', 'RUB', 'USD'] as $c) {
        $v = (float)str_replace(',', '.', ppkSiteSetting($pdo, 'PPK_PRICE_' . $c));
        if ($v > 0) $out[$c] = $v;
    }
    return $out;
}
function ppkFormatMoney(float $a, string $cur): string
{
    $sym = ['UAH' => '₴', 'RUB' => '₽', 'USD' => '$', 'EUR' => '€', 'KZT' => '₸'][$cur] ?? $cur;
    if ($cur === 'USD' || $cur === 'EUR') return $sym . number_format($a, 2, '.', '');
    return number_format($a, 0, '.', ' ') . ' ' . $sym;
}

// ───────────── покупки ─────────────
function ppkGetPurchase(PDO $pdo, int $id): ?array
{
    ensurePpkPurchaseSchema($pdo);
    $st = $pdo->prepare("SELECT * FROM ppk_purchases WHERE id = ?"); $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Текущая «открытая» покупка человека (created/claimed/paid) — чтобы не плодить дубли. */
function ppkOpenPurchase(PDO $pdo, string $tgId): ?array
{
    ensurePpkPurchaseSchema($pdo);
    $st = $pdo->prepare("SELECT * FROM ppk_purchases WHERE tg_id = ? AND status IN ('created','claimed','paid') ORDER BY id DESC LIMIT 1");
    $st->execute([$tgId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Последняя одобренная покупка человека (для показа его одноразовой ссылки на сайте). */
function ppkApprovedPurchase(PDO $pdo, string $tgId): ?array
{
    if ($tgId === '') return null;
    ensurePpkPurchaseSchema($pdo);
    $st = $pdo->prepare("SELECT * FROM ppk_purchases WHERE tg_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1");
    $st->execute([$tgId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ppkCreatePurchase(PDO $pdo, string $tgId, array $profile = []): array
{
    $open = ppkOpenPurchase($pdo, $tgId);
    if ($open) return $open;
    $st = $pdo->prepare("INSERT INTO ppk_purchases (tg_id, tg_username, tg_first_name) VALUES (?, ?, ?) RETURNING id");
    $st->execute([$tgId, (string)($profile['tg_username'] ?? ''), (string)($profile['tg_first_name'] ?? '')]);
    return ppkGetPurchase($pdo, (int)$st->fetchColumn());
}

function ppkWho(array $p): string
{
    $name = trim((string)$p['tg_first_name']);
    $u = trim((string)$p['tg_username']);
    return htmlspecialchars(($name !== '' ? $name : 'Клиент') . ($u !== '' ? " (@{$u})" : '')) . ' · <code>' . htmlspecialchars((string)$p['tg_id']) . '</code>';
}

/** Клиент оплатил (нажал «Я оплатил» или платёж найден автоматически) → уведомляем админа. */
function ppkMarkPaid(PDO $pdo, int $id, string $status, string $method = '', string $currency = '', float $amount = 0): bool
{
    ensurePpkPurchaseSchema($pdo);
    $set = ['status = ?', 'claimed_at = COALESCE(claimed_at, NOW())']; $args = [$status];
    if ($method !== '')   { $set[] = 'method = ?';   $args[] = $method; }
    if ($currency !== '') { $set[] = 'currency = ?'; $args[] = $currency; }
    if ($amount > 0)      { $set[] = 'amount = ?';   $args[] = number_format($amount, 2, '.', ''); }
    $args[] = $id;
    $st = $pdo->prepare("UPDATE ppk_purchases SET " . implode(', ', $set) . " WHERE id = ? AND status IN ('created','claimed') RETURNING admin_msg_id");
    $st->execute($args);
    $prevMsg = $st->fetchColumn();
    if ($prevMsg === false) return false;     // уже оплачен/одобрен/отклонён — повторно не шлём
    if ((int)$prevMsg > 0) return false;      // админу уже ушло сообщение (двойной клик / автопроверка после «Я оплатил») — не дублируем

    $p = ppkGetPurchase($pdo, $id);
    $how = $status === 'paid' ? '✅ платёж найден автоматически' : '⏳ клиент нажал «Я оплатил» — проверь поступление';
    $sum = $p['amount'] > 0 ? ppkFormatMoney((float)$p['amount'], (string)$p['currency']) : '—';
    $msgId = ppkNotifyAdmin(
        "🛒 <b>Клиент купил Приват Пак!</b>\n\n👤 " . ppkWho($p)
        . "\n💳 Способ: " . htmlspecialchars((string)($p['method'] ?: '—'))
        . "\n💵 Сумма: <b>{$sum}</b>\n🧾 Покупка #{$id}\n{$how}\n\nОдобрить — человек получит доступ на сайте и одноразовую ссылку в приватный чат.",
        [[
            ['text' => '✅ Одобрить доступ', 'callback_data' => "ppk_ok_{$id}"],
            ['text' => '❌ Отклонить',       'callback_data' => "ppk_no_{$id}"],
        ]],
        $pdo
    );
    if ($msgId > 0) { $pdo->prepare("UPDATE ppk_purchases SET admin_msg_id = ? WHERE id = ?")->execute([$msgId, $id]); }
    return true;
}

// ───────────── одноразовая ссылка ─────────────
/** @return array{ok:bool, link?:string, error?:string} */
function ppkCreateOneTimeInvite(PDO $pdo, int $purchaseId, int $ttlHours = 72): array
{
    $chat = ppkChatId($pdo);
    if ($chat === '' || preg_match('~^https?://t\.me/~i', $chat)) {
        return ['ok' => false, 'error' => 'PRIVATE_CHAT_ID не задан или указан ссылкой — нужен числовой id группы (напиши /id в группе)'];
    }
    $r = ppkTg(ppkBotToken($pdo), 'createChatInviteLink', [
        'chat_id'      => $chat,
        'name'         => 'PPK #' . $purchaseId,
        'member_limit' => 1,                       // ← одноразовая: после одного входа ссылка умирает
        'expire_date'  => time() + $ttlHours * 3600,
    ]);
    $link = (string)($r['result']['invite_link'] ?? '');
    if ($link === '') {
        return ['ok' => false, 'error' => (string)($r['description'] ?? 'не удалось создать ссылку') . ' (бот должен быть админом группы с правом «Приглашать пользователей»)'];
    }
    return ['ok' => true, 'link' => $link];
}

/**
 * Одобрить покупку: доступ на сайте + одноразовая ссылка → клиенту в Telegram.
 * Идемпотентно: повторное нажатие не выдаёт вторую ссылку.
 * @return array{ok:bool, text:string}
 */
function ppkApprove(PDO $pdo, int $id): array
{
    $p = ppkGetPurchase($pdo, $id);
    if (!$p) return ['ok' => false, 'text' => 'Покупка не найдена'];
    if ($p['status'] === 'approved') return ['ok' => true, 'text' => 'Уже одобрено ранее'];
    if ($p['status'] === 'rejected') return ['ok' => false, 'text' => 'Эта покупка была отклонена'];

    // атомарно «занимаем» одобрение — защита от двойного клика
    $st = $pdo->prepare("UPDATE ppk_purchases SET status = 'approved', approved_at = NOW() WHERE id = ? AND status <> 'approved' RETURNING id");
    $st->execute([$id]);
    if (!$st->fetchColumn()) return ['ok' => true, 'text' => 'Уже одобрено ранее'];

    $tgId = (string)$p['tg_id'];
    grantManualPpk($pdo, $tgId, ppkAdminId(), 'Покупка #' . $id);
    if (!hasManualPpkGrant($pdo, $tgId)) {
        $pdo->prepare("UPDATE ppk_purchases SET status = 'claimed', approved_at = NULL WHERE id = ?")->execute([$id]);
        return ['ok' => false, 'text' => 'Не удалось записать доступ в БД — попробуй ещё раз'];
    }

    $inv = ppkCreateOneTimeInvite($pdo, $id);
    if ($inv['ok']) {
        $pdo->prepare("UPDATE ppk_purchases SET invite_link = ? WHERE id = ?")->execute([$inv['link'], $id]);
    }

    $site = ppkSiteUrl() . '/privat_pak.php';
    $msg = "🎉 <b>Оплата подтверждена — добро пожаловать в Приват Пак!</b>\n\n"
         . "✅ Доступ на сайте открыт: {$site}\n";
    if ($inv['ok']) {
        $msg .= "\n🔗 Твоя <b>одноразовая</b> ссылка в приватный чат:\n" . $inv['link']
              . "\n\n⚠️ Ссылка работает только для одного входа и действует 3 дня — не пересылай её никому.";
    } else {
        $msg .= "\nСсылку в чат пришлю отдельно — дизайнер уже в курсе.";
    }
    $sent = ppkTg(ppkBotToken($pdo), 'sendMessage', [
        'chat_id' => $tgId, 'text' => $msg, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true',
    ]);

    $lines = ['✅ Доступ на сайте выдан.'];
    $lines[] = $inv['ok'] ? '🔗 Одноразовая ссылка создана.' : '⚠️ Ссылку создать не удалось: ' . $inv['error'];
    if (!empty($sent['ok'])) {
        $lines[] = '📨 Клиенту отправлено.';
    } else {
        $lines[] = '⚠️ Не удалось написать клиенту (' . ($sent['description'] ?? '?') . ') — вероятно, он не запускал бота.'
                 . ($inv['ok'] ? "\nСсылка тоже видна ему на сайте в разделе Приват Пак. Копия: " . $inv['link'] : '');
    }
    return ['ok' => true, 'text' => implode("\n", $lines)];
}

function ppkReject(PDO $pdo, int $id): array
{
    $p = ppkGetPurchase($pdo, $id);
    if (!$p) return ['ok' => false, 'text' => 'Покупка не найдена'];
    if ($p['status'] === 'approved') return ['ok' => false, 'text' => 'Уже одобрена — отклонить нельзя'];
    $pdo->prepare("UPDATE ppk_purchases SET status = 'rejected' WHERE id = ?")->execute([$id]);
    ppkTg(ppkBotToken($pdo), 'sendMessage', [
        'chat_id' => (string)$p['tg_id'],
        'text'    => "❌ Платёж за Приват Пак не подтверждён. Если ты оплатил(а) — напиши дизайнеру, всё быстро решим.",
    ]);
    return ['ok' => true, 'text' => '❌ Отклонено, клиент уведомлён.'];
}

/**
 * Событие chat_member: человек вошёл по нашей ссылке → помечаем использованной
 * и отзываем её (на случай, если Telegram ещё не погасил сам).
 */
function ppkHandleInviteJoin(PDO $pdo, array $cm): void
{
    $link = (string)($cm['invite_link']['invite_link'] ?? '');
    $newStatus = (string)($cm['new_chat_member']['status'] ?? '');
    if ($link === '' || !in_array($newStatus, ['member', 'restricted'], true)) return;
    ensurePpkPurchaseSchema($pdo);
    $st = $pdo->prepare("UPDATE ppk_purchases SET invite_used = TRUE, invite_used_at = NOW() WHERE invite_link = ? AND invite_used = FALSE RETURNING id, tg_id");
    $st->execute([$link]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;

    ppkTg(ppkBotToken($pdo), 'revokeChatInviteLink', ['chat_id' => ppkChatId($pdo), 'invite_link' => $link]);

    $joiner = (string)($cm['new_chat_member']['user']['id'] ?? '');
    $u = $cm['new_chat_member']['user'] ?? [];
    $uname = trim((string)($u['first_name'] ?? '') . ' ' . (isset($u['username']) ? '@' . $u['username'] : ''));
    $note = "🔗 Покупка #{$row['id']}: по одноразовой ссылке вошёл <b>" . htmlspecialchars($uname) . "</b> (<code>{$joiner}</code>). Ссылка отозвана.";
    if ($joiner !== '' && $joiner !== (string)$row['tg_id']) {
        $note .= "\n⚠️ Это НЕ покупатель (<code>{$row['tg_id']}</code>) — ссылку кто-то передал. Проверь.";
    }
    ppkNotifyAdmin($note, null, $pdo);
}

// ───────────── автопроверка оплаты (по комментарию «PPK #id») ─────────────
function ppkParsePurchaseId(string $text): int
{
    return preg_match('/\bppk[\s#_\-]*(\d{1,9})\b/iu', $text, $m) ? (int)$m[1] : 0;
}

/** Если платёж виден в банке/DonationAlerts/Crypto Bot — вернёт [method,currency,amount], иначе null. */
function ppkAutoDetectPayment(PDO $pdo, array $p): ?array
{
    if (!function_exists('payHttp')) { require_once __DIR__ . '/pay_lib.php'; }
    $id = (int)$p['id'];
    $prices = ppkPrices($pdo);

    // Crypto Bot — по сохранённому invoice_id
    if ($p['method'] === 'cryptobot' && $p['invoice_id'] !== '') {
        $r = payHttp(cryptoBase() . 'getInvoices?invoice_ids=' . urlencode((string)$p['invoice_id']), ['Crypto-Pay-API-Token: ' . payEnv('CRYPTO_KEY')]);
        $items = $r['result']['items'] ?? ($r['result'] ?? []);
        if (!empty($items[0]) && ($items[0]['status'] ?? '') === 'paid') return ['cryptobot', 'USD', (float)($prices['USD'] ?? 0)];
    }
    // Monobank — выписка за 3 дня
    if (payEnv('MONO_KEY') !== '' && isset($prices['UAH'])) {
        $from = time() - 3 * 86400;
        $r = payHttp('https://api.monobank.ua/personal/statement/' . payEnv('MONO_ACCOUNT', '0') . "/{$from}/" . time(), ['X-Token: ' . payEnv('MONO_KEY')]);
        if (is_array($r) && !isset($r['errorDescription'])) {
            foreach ($r as $it) {
                if (!is_array($it) || ($it['amount'] ?? 0) <= 0) continue;
                if (ppkParsePurchaseId(($it['comment'] ?? '') . ' ' . ($it['description'] ?? '')) !== $id) continue;
                if (($it['amount'] / 100) + 1 >= $prices['UAH']) return ['monobank', 'UAH', $prices['UAH']];
            }
        }
    }
    // DonationAlerts
    if (payEnv('DA_ACCESS_TOKEN') !== '' && isset($prices['RUB'])) {
        $r = payHttp('https://www.donationalerts.com/api/v1/alerts/donations', ['Authorization: Bearer ' . payEnv('DA_ACCESS_TOKEN')]);
        foreach (($r['data'] ?? []) as $d) {
            if (ppkParsePurchaseId((string)($d['message'] ?? '')) !== $id) continue;
            if (strtoupper((string)($d['currency'] ?? '')) === 'RUB' && (float)($d['amount'] ?? 0) + 1 >= $prices['RUB']) return ['donationalerts', 'RUB', $prices['RUB']];
        }
    }
    return null;
}
