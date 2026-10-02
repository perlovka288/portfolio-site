<?php
/**
 * Проверка статуса ключа/сервиса для вкладки «Ключи и API».
 * POST: service = telegram | cloudinary | imgbb | gemini | youtube | turnstile
 *       + значения (key / token / cloud / secret) — проверяем ТО, ЧТО ВВЕДЕНО (даже до сохранения).
 * Ответ: {state: online|invalid|error|unset, msg: "..."}   Только для админа.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['admin_logged'])) { http_response_code(403); echo json_encode(['state' => 'error', 'msg' => 'Нет доступа']); exit; }

function kuiHttp(string $url, array $o = []): array
{
    $ch = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 9, CURLOPT_CONNECTTIMEOUT => 6];
    if (!empty($o['post'])) { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = $o['post']; }
    if (!empty($o['userpwd'])) { $opt[CURLOPT_USERPWD] = $o['userpwd']; }
    if (!empty($o['headers'])) { $opt[CURLOPT_HTTPHEADER] = $o['headers']; }
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch);
    $res = ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => (string)$body, 'err' => curl_error($ch), 'json' => null];
    curl_close($ch);
    $res['json'] = json_decode($res['body'], true);
    return $res;
}
function kuiOut(string $state, string $msg): void { echo json_encode(['state' => $state, 'msg' => $msg], JSON_UNESCAPED_UNICODE); exit; }
function kuiNet(array $r): void { if ($r['code'] === 0) { kuiOut('error', 'Нет соединения с сервисом' . ($r['err'] ? ' (' . $r['err'] . ')' : '')); } }

$svc = (string)($_POST['service'] ?? '');
$val = trim((string)($_POST['key'] ?? ''));

switch ($svc) {
    case 'telegram':
        if ($val === '') { kuiOut('unset', 'Токен не задан'); }
        $r = kuiHttp('https://api.telegram.org/bot' . rawurlencode($val) . '/getMe'); kuiNet($r);
        if (!empty($r['json']['ok'])) { kuiOut('online', 'Бот @' . ($r['json']['result']['username'] ?? '?') . ' отвечает'); }
        kuiOut('invalid', 'Telegram отклонил токен');
    case 'cloudinary':
        $cloud = trim((string)($_POST['cloud'] ?? '')); $sec = trim((string)($_POST['secret'] ?? ''));
        if ($cloud === '' || $val === '' || $sec === '') { kuiOut('unset', 'Заполни cloud name, API key и API secret'); }
        $r = kuiHttp('https://api.cloudinary.com/v1_1/' . rawurlencode($cloud) . '/ping', ['userpwd' => $val . ':' . $sec]); kuiNet($r);
        if (($r['json']['status'] ?? '') === 'ok') { kuiOut('online', 'Cloudinary «' . $cloud . '» подключён'); }
        kuiOut('invalid', (string)($r['json']['error']['message'] ?? 'Cloudinary отклонил данные (HTTP ' . $r['code'] . ')'));
    case 'imgbb':
        if ($val === '') { kuiOut('unset', 'Ключ не задан'); }
        // 1×1 gif, живёт 60 секунд — безопасная проверка
        $r = kuiHttp('https://api.imgbb.com/1/upload', ['post' => ['key' => $val, 'expiration' => 60, 'image' => 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']]); kuiNet($r);
        if (!empty($r['json']['success'])) { kuiOut('online', 'ImgBB принимает загрузки'); }
        $m = (string)($r['json']['error']['message'] ?? ('HTTP ' . $r['code']));
        if (stripos($m, 'forbidden') !== false) { kuiOut('error', 'ImgBB блокирует IP вашего сервера («' . $m . '»). Используйте Cloudinary.'); }
        kuiOut(stripos($m, 'invalid') !== false ? 'invalid' : 'error', $m);
    case 'gemini':
        if ($val === '') { kuiOut('unset', 'Ключ не задан'); }
        $r = kuiHttp('https://generativelanguage.googleapis.com/v1beta/models?pageSize=1&key=' . rawurlencode($val)); kuiNet($r);
        if ($r['code'] === 200) { kuiOut('online', 'Gemini отвечает'); }
        kuiOut(in_array($r['code'], [400, 401, 403], true) ? 'invalid' : 'error', (string)($r['json']['error']['message'] ?? 'HTTP ' . $r['code']));
    case 'youtube':
        if ($val === '') { kuiOut('unset', 'Ключ не задан'); }
        $r = kuiHttp('https://www.googleapis.com/youtube/v3/videoCategories?part=snippet&regionCode=US&key=' . rawurlencode($val)); kuiNet($r);
        if ($r['code'] === 200) { kuiOut('online', 'YouTube API отвечает'); }
        kuiOut(in_array($r['code'], [400, 401, 403], true) ? 'invalid' : 'error', (string)($r['json']['error']['message'] ?? 'HTTP ' . $r['code']));
    case 'turnstile':
        if ($val === '') { kuiOut('unset', 'Secret key не задан'); }
        $r = kuiHttp('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['post' => ['secret' => $val, 'response' => 'kui-check']]); kuiNet($r);
        $codes = (array)($r['json']['error-codes'] ?? []);
        if (in_array('invalid-input-secret', $codes, true)) { kuiOut('invalid', 'Turnstile не принял secret'); }
        kuiOut('online', 'Secret key принят');
}
kuiOut('error', 'Неизвестный сервис');
