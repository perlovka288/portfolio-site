<?php
/**
 * Бэкенд ИИ-тренажёра клиентов (AJAX, JSON).
 * Действия (POST action=...):
 *   start_session   — создать сессию, ИИ генерирует ТЗ и первое приветствие
 *   send_message    — сообщение дизайнера -> ответ ИИ-клиента (с учётом истории)
 *   submit_work     — сдача файла на проверку -> оценка 0..100 + отзыв
 *   share_with_admin— переслать админу (Telegram) карточку результата
 *   list_sessions   — список сессий текущего пользователя
 *   get_session     — одна сессия с полной историей сообщений
 *
 * ИИ: Gemini (тот же способ вызова, что в ai_support.php/tg_ai.php проекта).
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/ppk_access.php';

function jexit(array $data): void { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

// ── Доступ: ADMIN или PPK (авто-проверка ИЛИ ручная выдача) ──
$access = resolvePpkAccess($pdo);
if (!$access['isPackDesigner']) jexit(['ok' => false, 'error' => 'Доступ только для PPK/ADMIN']);
$tgId = $access['tgId'];
$tgProfile = $access['tgProfile'];

ensureTrainerSchema($pdo);

function ensureTrainerSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS trainer_sessions (
            id SERIAL PRIMARY KEY,
            tg_id VARCHAR(64) NOT NULL,
            designer_name VARCHAR(150) NOT NULL DEFAULT '',
            client_name VARCHAR(100) NOT NULL DEFAULT '',
            difficulty VARCHAR(20) NOT NULL DEFAULT 'standard',
            topic VARCHAR(255) NOT NULL DEFAULT '',
            brief TEXT NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            score INT,
            review TEXT NOT NULL DEFAULT '',
            shared_with_admin BOOLEAN NOT NULL DEFAULT FALSE,
            admin_reaction VARCHAR(20) NOT NULL DEFAULT '',
            admin_comment TEXT NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS trainer_messages (
            id SERIAL PRIMARY KEY,
            session_id INT NOT NULL REFERENCES trainer_sessions(id) ON DELETE CASCADE,
            role VARCHAR(10) NOT NULL,
            content TEXT NOT NULL DEFAULT '',
            attachment_url TEXT NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");
    } catch (Throwable $e) {
        error_log('ensureTrainerSchema error: ' . $e->getMessage());
    }
}

/**
 * Разбирает GEMINI_API_KEY из окружения в массив валидных ключей.
 * ВАЖНО: в Render переменную заводят как "ключ1,ключ2,ключ3" (несколько
 * ключей через запятую для ротации квоты). Раньше эта строка целиком
 * подставлялась в заголовок/URL запроса как ОДИН ключ — Google получал
 * мусор вида "AIza...,AIza...,AIza..." и закономерно отвечал
 * HTTP 401 (invalid authentication credentials). Здесь строка режется
 * по запятой, каждый кусок чистится от пробелов/пустых элементов.
 */
function getGeminiApiKeys(): array
{
    $raw = getenv('GEMINI_API_KEY') ?: '';
    if ($raw === '') return [];
    $keys = array_map('trim', explode(',', $raw));
    $keys = array_values(array_filter($keys, static fn($k) => $k !== ''));
    return $keys;
}

/**
 * Единый низкоуровневый вызов Gemini generateContent с РОТАЦИЕЙ ключей:
 * ключи перебираются в случайном порядке (чтобы не долбить всегда в
 * один и тот же и размазывать квоту), и если очередной ключ вернул
 * ошибку авторизации/квоты (401/403/429 или сообщение про invalid key
 * / quota), автоматически пробуется следующий, а не падает сразу.
 * Возвращает ['response' => string|null, 'http' => int, 'curl_err' => string, 'key_used' => string|null].
 */
function geminiCall(string $model, array $payload, int $timeout = 25): array
{
    $keys = getGeminiApiKeys();
    if (!$keys) {
        return ['response' => null, 'http' => 0, 'curl_err' => 'no_key', 'key_used' => null];
    }
    shuffle($keys);

    $last = ['response' => null, 'http' => 0, 'curl_err' => '', 'key_used' => null];
    foreach ($keys as $key) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($key);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $last = ['response' => $response, 'http' => $httpCode, 'curl_err' => $err, 'key_used' => $key];

        if ($err !== '') {
            error_log('geminiCall curl error (key ...' . substr($key, -4) . '): ' . $err);
            continue; // сетевая ошибка — пробуем следующий ключ
        }

        if ($httpCode === 401 || $httpCode === 403 || $httpCode === 429) {
            error_log('geminiCall auth/quota HTTP ' . $httpCode . ' for key ...' . substr($key, -4) . ', пробуем следующий ключ');
            continue; // невалидный ключ или исчерпана квота — пробуем следующий
        }

        $data = json_decode((string)$response, true);
        $apiErrorMsg = $data['error']['message'] ?? null;
        if ($apiErrorMsg !== null && (stripos($apiErrorMsg, 'API key') !== false || stripos($apiErrorMsg, 'quota') !== false || stripos($apiErrorMsg, 'authenticat') !== false)) {
            error_log('geminiCall API error for key ...' . substr($key, -4) . ': ' . $apiErrorMsg . ' — пробуем следующий ключ');
            continue;
        }

        return $last; // успех (или ошибка, не связанная с ключом, — нет смысла перебирать дальше)
    }
    return $last; // все ключи исчерпаны/невалидны — возвращаем последний результат для диагностики
}

/** Единый вызов Gemini text-only (тот же шаблон, что в проекте). */
function geminiText(string $systemPrompt, array $historyTurns, string $userText): string
{
    if (!getGeminiApiKeys()) return '⚠️ ИИ временно недоступен (не настроен GEMINI_API_KEY).';

    $contents = [];
    foreach ($historyTurns as $turn) {
        $contents[] = ['role' => $turn['role'], 'parts' => [['text' => $turn['text']]]];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $userText]]];

    $payload = [
        'contents'          => $contents,
        'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
        // ВАЖНО: gemini-2.5-flash — "thinking"-модель, часть maxOutputTokens
        // уходит на внутренние рассуждения ДО текста ответа. С низким лимитом
        // (было 400) бюджет иногда съедался целиком на "размышления", и в
        // ответе оставался пустой text — отсюда "ИИ не отвечает" в чате.
        // thinkingBudget=0 отключает эту фазу (не нужна для ролевого чата),
        // а maxOutputTokens подняли с запасом.
        'generationConfig'  => [
            'temperature' => 0.9,
            'maxOutputTokens' => 1024,
            'thinkingConfig' => ['thinkingBudget' => 0],
        ],
    ];

    $result = geminiCall('gemini-2.5-flash', $payload, 25);
    $response = $result['response'];
    $httpCode = $result['http'];

    if ($response === null) {
        $reason = $result['curl_err'] === 'no_key' ? 'не настроен GEMINI_API_KEY' : ('ошибка связи: ' . $result['curl_err']);
        error_log('geminiText: все ключи не сработали — ' . $reason);
        return '⚠️ Ошибка связи с ИИ, попробуй ещё раз.';
    }

    $data = json_decode((string)$response, true);
    $text = trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
    if ($text !== '') return $text;

    // ДИАГНОСТИКА: раньше тут молча возвращали "…". Логируем сырой ответ
    // целиком (видно в логах Render), а в сам чат отдаём короткую причину,
    // чтобы не гадать вслепую — этого достаточно, чтобы понять, в чём дело:
    // неверный/просроченный ключ, исчерпана квота, промпт заблокирован
    // фильтром безопасности (finishReason=SAFETY), и т.п.
    error_log('geminiText EMPTY reply. HTTP=' . $httpCode . ' raw=' . substr((string)$response, 0, 1500));
    $finishReason = $data['candidates'][0]['finishReason'] ?? null;
    $apiErrorMsg  = $data['error']['message'] ?? null;
    if ($apiErrorMsg) return '⚠️ Ошибка Gemini API (HTTP ' . $httpCode . '): ' . $apiErrorMsg;
    if ($finishReason) return '⚠️ Пустой ответ ИИ (finishReason: ' . $finishReason . '). Смотри логи сервера для деталей.';
    return '⚠️ Пустой ответ ИИ (HTTP ' . $httpCode . '), причина неизвестна — смотри логи сервера.';
}

/** Вызов Gemini с изображением (для оценки сдачи работы). */
function geminiWithImage(string $systemPrompt, string $userText, string $imagePath, string $mime): string
{
    if (!getGeminiApiKeys()) return json_encode(['score' => 70, 'review' => 'ИИ недоступен (нет ключа), выставлена условная оценка.']);

    $imgData = base64_encode((string)file_get_contents($imagePath));
    $payload = [
        'contents' => [[
            'role' => 'user',
            'parts' => [
                ['text' => $userText],
                ['inline_data' => ['mime_type' => $mime, 'data' => $imgData]],
            ],
        ]],
        'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
        'generationConfig' => [
            'temperature' => 0.4,
            'maxOutputTokens' => 1024,
            'thinkingConfig' => ['thinkingBudget' => 0],
        ],
    ];

    $result = geminiCall('gemini-2.5-flash', $payload, 40);
    $response = $result['response'];
    if ($response === null) {
        error_log('geminiWithImage: все ключи не сработали — ' . $result['curl_err']);
        return '';
    }

    $data = json_decode((string)$response, true);
    $text = trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
    if ($text === '') {
        error_log('geminiWithImage EMPTY reply. raw=' . substr((string)$response, 0, 1500));
    }
    return $text;
}

function difficultyPersona(string $level): string
{
    return match ($level) {
        'easy'  => 'Клиент дружелюбный, лояльный, легко соглашается с идеями дизайнера, почти не придирается, максимум 1 несущественная правка.',
        'hard'  => 'Клиент придирчивый и требовательный: часто просит правки, сомневается, сравнивает с конкурентами, торгуется по цене, но остаётся вежливым (без грубости и оскорблений). До 3-4 раундов правок.',
        default => 'Клиент обычный, среднего уровня требовательности: иногда просит 1-2 уточнения или небольшую правку, в целом адекватен.',
    };
}

$input = $_POST;
$rawJson = null;
if (empty($input)) {
    $rawJson = json_decode(file_get_contents('php://input'), true) ?: [];
    $input = $rawJson;
}
$action = $input['action'] ?? '';

switch ($action) {

case 'start_session': {
    $clientName = trim((string)($input['client_name'] ?? 'Клиент'));
    $difficulty = in_array($input['difficulty'] ?? '', ['easy','standard','hard'], true) ? $input['difficulty'] : 'standard';
    $topic      = trim((string)($input['topic'] ?? 'Дизайн-заказ'));

    $briefPrompt = "Ты — заказчик по имени {$clientName}, который хочет заказать у дизайнера: «{$topic}». "
        . difficultyPersona($difficulty) . " "
        . "Напиши ПЕРВОЕ сообщение дизайнеру: поздоровайся, кратко представься и сформулируй подробное техническое задание (стиль, цвета/референсы, что должно быть на макете, дедлайн). "
        . "Пиши как реальный человек в мессенджере: коротко, без markdown и звёздочек, можно эмодзи. Не упоминай, что ты ИИ.";

    $brief = geminiText($briefPrompt, [], "Напиши первое сообщение с ТЗ.");

    $stmt = $pdo->prepare("INSERT INTO trainer_sessions (tg_id, client_name, difficulty, topic, brief) VALUES (?,?,?,?,?) RETURNING id");
    $stmt->execute([$tgId, $clientName, $difficulty, $topic, $brief]);
    $sessionId = (int)$stmt->fetchColumn();

    $pdo->prepare("INSERT INTO trainer_messages (session_id, role, content) VALUES (?, 'client', ?)")->execute([$sessionId, $brief]);

    jexit(['ok' => true, 'session_id' => $sessionId, 'client_name' => $clientName, 'difficulty' => $difficulty, 'topic' => $topic,
        'messages' => [['role' => 'client', 'content' => $brief]]]);
}

case 'send_message': {
    $sessionId = (int)($input['session_id'] ?? 0);
    $content   = trim((string)($input['content'] ?? ''));
    if ($sessionId <= 0 || $content === '') jexit(['ok' => false, 'error' => 'Пустое сообщение']);

    $stmt = $pdo->prepare("SELECT * FROM trainer_sessions WHERE id = ? AND tg_id = ? LIMIT 1");
    $stmt->execute([$sessionId, $tgId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) jexit(['ok' => false, 'error' => 'Сессия не найдена']);

    $pdo->prepare("INSERT INTO trainer_messages (session_id, role, content) VALUES (?, 'designer', ?)")->execute([$sessionId, $content]);

    $histStmt = $pdo->prepare("SELECT role, content FROM trainer_messages WHERE session_id = ? ORDER BY id ASC");
    $histStmt->execute([$sessionId]);
    $rows = $histStmt->fetchAll(PDO::FETCH_ASSOC);
    // ВАЖНО: первая запись в истории — это открывающее сообщение ИИ-клиента
    // (то самое ТЗ, роль 'client'/'model'). Если отправить его Gemini как
    // первый элемент contents, диалог начинается с роли model без
    // предшествующего user — некоторые модели на это отвечают пустым
    // текстом. Поэтому убираем его из истории и кладём текст ТЗ в
    // systemPrompt как контекст, а contents строим только из реальных
    // пар (дизайнер → клиент), начиная с первого сообщения дизайнера.
    array_shift($rows);
    $turns = [];
    foreach ($rows as $r) {
        $turns[] = ['role' => $r['role'] === 'client' ? 'model' : 'user', 'text' => $r['content']];
    }
    array_pop($turns); // последнее сообщение дизайнера уйдёт отдельным userText

    $systemPrompt = "Ты играешь роль заказчика «{$session['client_name']}» по теме «{$session['topic']}». "
        . difficultyPersona($session['difficulty']) . " "
        . "Своё первое сообщение дизайнеру (с ТЗ) ты уже отправил, вот оно: «{$session['brief']}». "
        . "Отвечай коротко (2-5 предложений), как в мессенджере, без markdown, оставайся в характере на протяжении всего диалога.";

    $reply = geminiText($systemPrompt, $turns, $content);
    $pdo->prepare("INSERT INTO trainer_messages (session_id, role, content) VALUES (?, 'client', ?)")->execute([$sessionId, $reply]);
    $pdo->prepare("UPDATE trainer_sessions SET updated_at = NOW() WHERE id = ?")->execute([$sessionId]);

    jexit(['ok' => true, 'reply' => $reply]);
}

case 'submit_work': {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    if ($sessionId <= 0 || empty($_FILES['file']['tmp_name'])) jexit(['ok' => false, 'error' => 'Прикрепите файл сдачи']);

    $stmt = $pdo->prepare("SELECT * FROM trainer_sessions WHERE id = ? AND tg_id = ? LIMIT 1");
    $stmt->execute([$sessionId, $tgId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) jexit(['ok' => false, 'error' => 'Сессия не найдена']);

    $allowedExt = ['jpg','jpeg','png','webp'];
    $ext = strtolower(pathinfo((string)$_FILES['file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) jexit(['ok' => false, 'error' => 'Поддерживаются только изображения (jpg/png/webp) для оценки превью']);

    $dir = __DIR__ . '/uploads/trainer_submits/';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $filename = 'sub_' . $sessionId . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir . $filename)) jexit(['ok' => false, 'error' => 'Не удалось сохранить файл']);
    $publicUrl = '/uploads/trainer_submits/' . $filename;

    $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $scorePrompt = "Ты — арт-директор, оцениваешь сдачу дизайнера по ТЗ заказчика. "
        . "ТЗ было: «{$session['brief']}». Тема: «{$session['topic']}». Уровень сложности клиента: {$session['difficulty']}. "
        . "Посмотри на приложенное изображение — это сданная дизайнером работа. "
        . "Оцени объективно и по-доброму, БЕЗ мелких неадекватных придирок (не занижай за мелочи вроде положения текста на пиксель). "
        . "Ответь СТРОГО в формате JSON без markdown и пояснений: {\"score\": <число от 0 до 100>, \"review\": \"<отзыв клиента на 2-4 предложения от первого лица, в характере клиента>\"}";

    $raw = geminiWithImage($scorePrompt, 'Оцени эту работу по ТЗ выше.', $dir . $filename, $mimeMap[$ext]);
    $clean = trim(preg_replace('~^```json|```$~m', '', $raw));
    $parsed = json_decode($clean, true);
    $score = is_array($parsed) && isset($parsed['score']) ? max(0, min(100, (int)$parsed['score'])) : 75;
    $review = is_array($parsed) && !empty($parsed['review']) ? (string)$parsed['review'] : 'Спасибо, работа принята!';

    $pdo->prepare("INSERT INTO trainer_messages (session_id, role, content, attachment_url) VALUES (?, 'designer', 'Сдал работу на проверку', ?)")
        ->execute([$sessionId, $publicUrl]);
    $pdo->prepare("UPDATE trainer_sessions SET status = 'scored', score = ?, review = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$score, $review, $sessionId]);

    jexit(['ok' => true, 'score' => $score, 'review' => $review, 'attachment_url' => $publicUrl]);
}

case 'share_with_admin': {
    $sessionId = (int)($input['session_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM trainer_sessions WHERE id = ? AND tg_id = ? LIMIT 1");
    $stmt->execute([$sessionId, $tgId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) jexit(['ok' => false, 'error' => 'Сессия не найдена']);

    // FIX (дублирование карточек/уведомлений при повторном/двойном клике
    // «Поделиться с Kostlim»): UPDATE ... WHERE shared_with_admin = FALSE —
    // атомарно "занимает" расшаривание, так что даже при гонке двух
    // одновременных запросов Telegram-уведомление уйдёт максимум один раз,
    // а не по одному на каждый клик/запрос.
    $claim = $pdo->prepare("UPDATE trainer_sessions SET shared_with_admin = TRUE WHERE id = ? AND shared_with_admin = FALSE");
    $claim->execute([$sessionId]);
    $firstShare = $claim->rowCount() > 0;

    if (!$firstShare) {
        // Уже было расшарено раньше (повторный клик/двойной запрос) —
        // подтверждаем без повторной отправки в Telegram.
        jexit(['ok' => true, 'already_shared' => true]);
    }

    $adminId = getenv('ADMIN_ID') ?: '';
    $token = ppkSiteSetting($pdo, 'BOT_TOKEN') ?: (getenv('TELEGRAM_BOT_TOKEN') ?: getenv('BOT_TOKEN') ?: '');
    if ($token && $adminId) {
        $name = $tgProfile['tg_first_name'] ?? $tgId;
        $text = "🎮 Результат тренажёра клиентов\n"
            . "Дизайнер: {$name}\n"
            . "Клиент: {$session['client_name']} · Тема: {$session['topic']} · Сложность: {$session['difficulty']}\n"
            . "Оценка: {$session['score']}/100\n"
            . "Отзыв ИИ-клиента: {$session['review']}";
        @file_get_contents("https://api.telegram.org/bot{$token}/sendMessage?chat_id={$adminId}&text=" . urlencode($text));
    }
    jexit(['ok' => true]);
}

case 'list_sessions': {
    $stmt = $pdo->prepare("SELECT id, client_name, topic, difficulty, status, score FROM trainer_sessions WHERE tg_id = ? ORDER BY updated_at DESC LIMIT 50");
    $stmt->execute([$tgId]);
    jexit(['ok' => true, 'sessions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'get_session': {
    $sessionId = (int)($input['session_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM trainer_sessions WHERE id = ? AND tg_id = ? LIMIT 1");
    $stmt->execute([$sessionId, $tgId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) jexit(['ok' => false]);

    $msgStmt = $pdo->prepare("SELECT role, content, attachment_url FROM trainer_messages WHERE session_id = ? ORDER BY id ASC");
    $msgStmt->execute([$sessionId]);
    jexit(['ok' => true, 'client_name' => $session['client_name'], 'topic' => $session['topic'], 'difficulty' => $session['difficulty'],
        'status' => $session['status'], 'score' => $session['score'], 'review' => $session['review'],
        'shared_with_admin' => (bool)$session['shared_with_admin'],
        'messages' => $msgStmt->fetchAll(PDO::FETCH_ASSOC)]);
}

default:
    jexit(['ok' => false, 'error' => 'Неизвестное действие']);
}