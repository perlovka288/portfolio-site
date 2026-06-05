<?php

if (!defined('DA_CLIENT_ID')) {
    define('DA_CLIENT_ID', '19265');
}
if (!defined('DA_CLIENT_SECRET')) {
    define('DA_CLIENT_SECRET', 'M5j94BIZtxifvDcC1RPO3zqtMh3cMBqx2t06HS2X');
}
if (!defined('DA_REDIRECT_URI')) {
    define('DA_REDIRECT_URI', 'https://portfolio-site-boo5.onrender.com/');
}
if (!defined('DA_SCOPE')) {
    define('DA_SCOPE', 'oauth-donation-index');
}
if (!defined('DA_TOKEN_URL')) {
    define('DA_TOKEN_URL', 'https://www.donationalerts.com/oauth/token');
}
if (!defined('DA_DONATIONS_URL')) {
    define('DA_DONATIONS_URL', 'https://www.donationalerts.com/api/v1/alerts/donations');
}

function daFetchJson(string $url, array $fields = [], array $headers = []): ?array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if (!empty($fields)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        if (!array_filter($headers, fn($h) => stripos($h, 'Content-Type:') !== false)) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
    }

    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false || $response === '') {
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

function daEnsureTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS da_tokens (
        id SERIAL PRIMARY KEY,
        access_token TEXT NOT NULL,
        refresh_token TEXT NOT NULL,
        updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payouts (
        id SERIAL PRIMARY KEY,
        payout_id TEXT NOT NULL UNIQUE,
        amount_gross NUMERIC(12,2) NOT NULL DEFAULT 0,
        amount_net NUMERIC(12,2) NOT NULL DEFAULT 0,
        payout_date TIMESTAMP WITH TIME ZONE NOT NULL,
        created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
    )");
}

function daStoreTokens(PDO $pdo, array $tokens): void
{
    daEnsureTables($pdo);
    $accessToken = trim((string)($tokens['access_token'] ?? ''));
    $refreshToken = trim((string)($tokens['refresh_token'] ?? ''));
    if ($accessToken === '' || $refreshToken === '') {
        return;
    }

    $pdo->prepare("DELETE FROM da_tokens")->execute();
    $stmt = $pdo->prepare("INSERT INTO da_tokens (access_token, refresh_token, updated_at) VALUES (?, ?, NOW())");
    $stmt->execute([$accessToken, $refreshToken]);
}

function daGetStoredTokens(PDO $pdo): ?array
{
    daEnsureTables($pdo);
    try {
        $stmt = $pdo->query("SELECT access_token, refresh_token, updated_at FROM da_tokens ORDER BY updated_at DESC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function daExchangeAuthorizationCode(PDO $pdo, string $code): bool
{
    $result = daFetchJson(DA_TOKEN_URL, [
        'grant_type' => 'authorization_code',
        'client_id' => DA_CLIENT_ID,
        'client_secret' => DA_CLIENT_SECRET,
        'code' => $code,
        'redirect_uri' => DA_REDIRECT_URI,
    ]);

    if (!is_array($result) || empty($result['access_token']) || empty($result['refresh_token'])) {
        return false;
    }

    daStoreTokens($pdo, $result);
    return true;
}

function daRefreshAccessToken(PDO $pdo): ?string
{
    $stored = daGetStoredTokens($pdo);
    if (empty($stored['refresh_token'])) {
        return null;
    }

    $result = daFetchJson(DA_TOKEN_URL, [
        'grant_type' => 'refresh_token',
        'client_id' => DA_CLIENT_ID,
        'client_secret' => DA_CLIENT_SECRET,
        'refresh_token' => $stored['refresh_token'],
    ]);

    if (!is_array($result) || empty($result['access_token']) || empty($result['refresh_token'])) {
        return null;
    }

    daStoreTokens($pdo, $result);
    return $result['access_token'];
}

function daAccessTokenNeedsRefresh(array $stored): bool
{
    if (empty($stored['updated_at'])) {
        return true;
    }
    $updated = strtotime($stored['updated_at']);
    if ($updated === false) {
        return true;
    }
    return ($updated + 3300) <= time();
}

function daEnsureAccessToken(PDO $pdo): ?string
{
    $stored = daGetStoredTokens($pdo);
    if (!$stored || empty($stored['access_token'])) {
        return null;
    }

    if (daAccessTokenNeedsRefresh($stored)) {
        return daRefreshAccessToken($pdo);
    }

    return $stored['access_token'];
}

function daGetAuthorizeUrl(): string
{
    return 'https://www.donationalerts.com/oauth/authorize?response_type=code&client_id=' . urlencode(DA_CLIENT_ID)
        . '&redirect_uri=' . urlencode(DA_REDIRECT_URI)
        . '&scope=' . urlencode(DA_SCOPE);
}

function daGetDonations(PDO $pdo, int $limit = 100): array
{
    $accessToken = daEnsureAccessToken($pdo);
    if (!$accessToken) {
        return [];
    }

    $result = daFetchJson(DA_DONATIONS_URL . '?limit=' . intval($limit), [], [
        'Authorization: Bearer ' . $accessToken,
    ]);
    if (!is_array($result)) {
        return [];
    }

    if (!empty($result['data']) && is_array($result['data'])) {
        return $result['data'];
    }
    if (!empty($result['items']) && is_array($result['items'])) {
        return $result['items'];
    }
    if (!empty($result['alerts']) && is_array($result['alerts'])) {
        return $result['alerts'];
    }
    return [];
}

function daGetCurrentMonthDonationTotalUsd(array $donations): float
{
    $monthStart = new DateTime('first day of this month 00:00:00');
    $total = 0.0;
    foreach ($donations as $donation) {
        $currency = strtoupper(trim((string)($donation['currency'] ?? '')));
        $amount = (float)($donation['amount'] ?? 0);
        $created = $donation['created_at'] ?? $donation['createdAt'] ?? $donation['created_at'] ?? null;
        if ($currency !== 'USD' || $amount <= 0 || empty($created)) {
            continue;
        }
        try {
            $createdAt = new DateTime((string)$created);
        } catch (Throwable $e) {
            continue;
        }
        if ($createdAt >= $monthStart) {
            $total += $amount;
        }
    }
    return round($total, 2);
}

function daScanDonationsForPaidOrders(PDO $pdo, array $donations): int
{
    $updated = 0;
    foreach ($donations as $donation) {
        $message = trim((string)($donation['message'] ?? $donation['text'] ?? ''));
        if ($message === '') {
            continue;
        }
        if (preg_match('/#\s*(\d+)/u', $message, $matches)) {
            $orderId = (int)$matches[1];
            if ($orderId <= 0) {
                continue;
            }
            $stmt = $pdo->prepare("UPDATE orders SET status = 'paid' WHERE id = ? AND status != 'paid'");
            $stmt->execute([$orderId]);
            if ($stmt->rowCount() > 0) {
                $updated++;
            }
        }
    }
    return $updated;
}

function daGetCurrentMonthPayoutStats(PDO $pdo): array
{
    daEnsureTables($pdo);
    try {
        $stmt = $pdo->prepare("SELECT amount_gross FROM payouts WHERE payout_date >= DATE_TRUNC('month', CURRENT_DATE)");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['gross' => 0.0, 'count' => 0, 'commission' => 0.0, 'net' => 0.0];
    }

    $totalGross = 0.0;
    $count = 0;
    foreach ($rows as $row) {
        $totalGross += (float)($row['amount_gross'] ?? 0);
        $count++;
    }
    $commission = round($count * 2.00, 2);
    $totalNet = round($totalGross - $commission, 2);
    return ['gross' => round($totalGross, 2), 'count' => $count, 'commission' => $commission, 'net' => $totalNet];
}
