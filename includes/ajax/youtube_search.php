<?php
/**
 * /includes/ajax/youtube_search.php
 * AJAX-обработчик запуска YouTube-поиска.
 * Принимает POST: keyword, region, max_results
 * Возвращает JSON.
 */

session_start();

// Проверка авторизации (как в остальных admin-файлах)
if (empty($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// ── Конфиг ────────────────────────────────────────────────────────────────
$configFile = __DIR__ . '/../../config/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
}

// API ключ: сначала из константы, потом из env
$ytApiKey = defined('YT_API_KEY') ? YT_API_KEY : (getenv('YT_API_KEY') ?: '');

if (!$ytApiKey) {
    echo json_encode(['success' => false, 'error' => 'YT_API_KEY не задан в config.php или переменных окружения']);
    exit;
}

// ── Подключение классов ────────────────────────────────────────────────────
require_once __DIR__ . '/../classes/YouTubeParser.php';
require_once __DIR__ . '/../classes/ContactExtractor.php';

// ── Подключение БД ─────────────────────────────────────────────────────────
$dbFile = __DIR__ . '/../db.php';
if (!file_exists($dbFile)) {
    echo json_encode(['success' => false, 'error' => 'db.php не найден']);
    exit;
}
require_once $dbFile;

// Получаем PDO — поддерживаем и объект Database, и прямой $pdo
if (class_exists('Database')) {
    $db  = new Database();
    $pdo = $db->getConnection();
} elseif (isset($pdo)) {
    // $pdo уже определён в db.php
} else {
    echo json_encode(['success' => false, 'error' => 'Не удалось подключиться к БД']);
    exit;
}

// ── Входные параметры ──────────────────────────────────────────────────────
$keyword    = trim($_POST['keyword']    ?? '');
$region     = strtoupper(trim($_POST['region']     ?? 'RU'));
$maxResults = (int)($_POST['max_results'] ?? 50);

if ($keyword === '') {
    echo json_encode(['success' => false, 'error' => 'Ключевое слово не указано']);
    exit;
}

$maxResults = max(10, min(100, $maxResults));
$allowedRegions = ['RU', 'UA', 'US', 'GB', 'DE', 'KZ', 'BY', 'PL'];
if (!in_array($region, $allowedRegions, true)) $region = 'RU';

// ── Парсинг ────────────────────────────────────────────────────────────────
$parser = new YouTubeParser($ytApiKey);

// 1. Найти channel_id через поиск видео
$channelIds = $parser->searchChannelsByKeyword($keyword, $region, $maxResults);
if (!$channelIds) {
    echo json_encode(['success' => true, 'found' => 0, 'saved' => 0, 'skipped' => 0, 'message' => 'YouTube не вернул видео по запросу']);
    exit;
}

// 2. Получить статистику каналов
$channels = $parser->getChannelStats($channelIds);

// 3. Фильтр по подписчикам
$channels = $parser->filterBySubscribers($channels, 500, 20000);

// 4. Сохранить в БД
$saved   = 0;
$skipped = 0;

foreach ($channels as $ch) {
    // Проверка дубликата
    $stmtCheck = $pdo->prepare('SELECT id FROM channels WHERE channel_id = :cid LIMIT 1');
    $stmtCheck->execute([':cid' => $ch['channel_id']]);
    if ($stmtCheck->fetchColumn()) {
        $skipped++;
        continue;
    }

    // Парсим контакты
    $contacts = ContactExtractor::extract($ch['description'] ?? '');

    // URL последнего видео (опционально, можно убрать для скорости)
    $videoUrl = null;
    // $videoUrl = $parser->getLastVideoUrl($ch['channel_id']); // раскомментировать если нужно

    $stmtInsert = $pdo->prepare('
        INSERT INTO channels
            (channel_id, channel_name, channel_url, video_url, preview_url,
             contacts_tg, contacts_email, contacts_other, subscriber_count, status)
        VALUES
            (:channel_id, :channel_name, :channel_url, :video_url, :preview_url,
             :contacts_tg, :contacts_email, :contacts_other, :subscriber_count, \'active\')
    ');
    $stmtInsert->execute([
        ':channel_id'      => $ch['channel_id'],
        ':channel_name'    => $ch['channel_name'],
        ':channel_url'     => $ch['channel_url'],
        ':video_url'       => $videoUrl,
        ':preview_url'     => $ch['preview_url'],
        ':contacts_tg'     => $contacts['tg'],
        ':contacts_email'  => $contacts['email'],
        ':contacts_other'  => $contacts['other'],
        ':subscriber_count'=> $ch['subscriber_count'],
    ]);
    $saved++;
}

echo json_encode([
    'success' => true,
    'found'   => count($channelIds),
    'filtered'=> count($channels),
    'saved'   => $saved,
    'skipped' => $skipped,
    'message' => "Найдено каналов: " . count($channels) . ". Новых сохранено: $saved. Дублей пропущено: $skipped.",
]);
