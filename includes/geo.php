<?php
/** includes/geo.php — определение страны и валюты по IP */
function geoClientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
    }
    return '0.0.0.0';
}

function detectCountry(): string {
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['geo_country'])) {
        return $_SESSION['geo_country'];
    }
    $cc = strtoupper(trim($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));   // если сайт за Cloudflare
    if (!preg_match('/^[A-Z]{2}$/', $cc) || in_array($cc, ['XX', 'T1'], true)) {
        $cc = '';
        $ip = geoClientIp();
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $ch = curl_init('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,countryCode');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_CONNECTTIMEOUT => 2]);
            $j = json_decode((string)curl_exec($ch), true);
            curl_close($ch);
            if (($j['status'] ?? '') === 'success' && !empty($j['countryCode'])) $cc = strtoupper($j['countryCode']);
        }
    }
    if ($cc !== '' && session_status() === PHP_SESSION_ACTIVE) $_SESSION['geo_country'] = $cc;
    return $cc;
}

function currencyForCountry(string $cc): string {
    $cc = strtoupper($cc);
    if ($cc === 'UA') return 'UAH';
    if ($cc === 'KZ') return 'KZT';
    if (in_array($cc, ['RU', 'BY', 'AM', 'AZ', 'GE', 'KG', 'MD', 'TJ', 'TM', 'UZ'], true)) return 'RUB';
    if (in_array($cc, ['AT','BE','HR','CY','EE','FI','FR','DE','GR','IE','IT','LV','LT','LU','MT','NL','PT','SK','SI','ES'], true)) return 'EUR';
    return 'USD';
}
