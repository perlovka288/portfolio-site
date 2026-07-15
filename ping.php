<?php
// Лёгкая заглушка для keep-alive пинга (Render), НЕ обращается к БД.
// Настройте cron-job.org (или другой пингер) на этот файл вместо price.php,
// чтобы держать Render "разбуженным", но не расходовать compute-часы Neon впустую.

header('Content-Type: text/plain; charset=utf-8');
http_response_code(200);
echo 'OK ' . date('Y-m-d H:i:s');

