<?php
/**
 * Запуск сессии с правильными параметрами куков для Safari/iOS.
 *
 * Safari блокирует куки без SameSite=None + Secure при переходах
 * из внешних приложений (Telegram → сайт).
 *
 * ВАЖНО для Render/Heroku/любого reverse-proxy:
 * Apache внутри контейнера видит HTTP, но реальный запрос — HTTPS.
 * Определяем это через X-Forwarded-Proto.
 */
function startSafeSession(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    // На Render HTTPS терминируется на прокси — смотрим X-Forwarded-Proto
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
             || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on');

    $p = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $p['lifetime'],
        'path'     => '/',
        'domain'   => $p['domain'],
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => $isSecure ? 'None' : 'Lax', // None требует Secure
    ]);
    session_start();
}

startSafeSession();
