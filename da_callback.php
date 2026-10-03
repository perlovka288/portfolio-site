<?php
// da_callback.php — ВРЕМЕННАЯ страница: обменивает code DonationAlerts на токен. После получения токена УДАЛИТЕ файл.
$code = trim((string)($_GET['code'] ?? ''));
$cid  = getenv('DONATE_CLIENT_ID') ?: '21592';
$sec  = getenv('DONATE_KEY') ?: '';
header('Content-Type: text/html; charset=utf-8');
echo '<body style="font-family:Arial;max-width:700px;margin:40px auto;line-height:1.5">';
if ($code === '') { exit('<h2>Нет кода</h2><p>Откройте ссылку авторизации DonationAlerts заново.</p>'); }
if ($sec === '') { exit('<h2>Не задан DONATE_KEY</h2><p>Добавьте Secret приложения в Render → Environment (DONATE_KEY) и повторите.</p>'); }
$ch = curl_init('https://www.donationalerts.com/oauth/token');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'authorization_code', 'client_id' => $cid, 'client_secret' => $sec,
        'redirect_uri' => 'https://kostlimdzn.shop/da_callback', 'code' => $code])]);
$r = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
$j = json_decode((string)$r, true);
if (!empty($j['access_token'])) {
    echo '<h2>✅ Готово</h2><p>Скопируйте в Render → Environment:</p>';
    echo '<p><b>DA_ACCESS_TOKEN</b><br><textarea rows="4" style="width:100%">' . htmlspecialchars($j['access_token']) . '</textarea></p>';
    if (!empty($j['refresh_token'])) echo '<p><b>DA_REFRESH_TOKEN</b> (на будущее)<br><textarea rows="3" style="width:100%">' . htmlspecialchars($j['refresh_token']) . '</textarea></p>';
    echo '<p style="color:#b00">После этого удалите файл da_callback.php с сайта.</p>';
} else {
    echo '<h2>❌ Ошибка</h2><pre>' . htmlspecialchars($err ?: (string)$r) . '</pre>';
    echo '<p>invalid_grant — код устарел: откройте ссылку авторизации заново. invalid_client — проверьте DONATE_CLIENT_ID и DONATE_KEY.</p>';
}
