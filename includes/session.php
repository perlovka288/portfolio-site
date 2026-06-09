<?php
/**
 * Запуск сессии с правильными параметрами куков для Safari/iOS.
 *
 * Проблема: Safari блокирует куки без SameSite=None + Secure при переходах
 * из внешних приложений (Telegram → сайт). Из-за этого session_id() меняется
 * после редиректа из TG, и привязка аккаунта не находится в БД.
 *
 * Решение: устанавливаем SameSite=None + Secure до session_start().
 * SameSite=None требует HTTPS — поэтому нужен редирект на HTTPS в .htaccess.
 */
function startSafeSession(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return; // уже запущена
    }
    $p = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $p['lifetime'],
        'path'     => '/',
        'domain'   => $p['domain'],
        'secure'   => true,      // обязательно для SameSite=None
        'httponly' => true,
        'samesite' => 'None',    // позволяет куки при кросс-контекстных переходах
    ]);
    session_start();
}

startSafeSession();
