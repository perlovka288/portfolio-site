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
require_once __DIR__ . '/includes/resources_lib.php'; // getResSetting() — для редактируемых промптов сложности (Блок 4.1 ТЗ)

function jexit(array $data): void { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

// ── Доступ: ADMIN или PPK (авто-проверка ИЛИ ручная выдача) ──
$access = resolvePpkAccess($pdo);
if (!$access['isPackDesigner']) jexit(['ok' => false, 'error' => 'Доступ только для PPK/ADMIN']);
$tgId = $access['tgId'];
$tgProfile = $access['tgProfile'];

ensureTrainerSchema($pdo);
ensureResourcesSchema($pdo); // гарантирует site_settings для редактируемых промптов (Блок 4.1 ТЗ)

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
        // FIX: промпты сложности (Блок 4.1) теперь полноценные, с плейсхолдерами
        // {CLIENT_NAME}/{RANDOM_BUDGET}/{HALF_BUDGET} и маркером оплаты
        // [PAYMENT_SUCCESS:...] — раньше бюджета в БД не было вообще, и
        // плейсхолдер просто не на что было подставлять.
        $pdo->exec("ALTER TABLE trainer_sessions ADD COLUMN IF NOT EXISTS budget INT NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE trainer_sessions ADD COLUMN IF NOT EXISTS paid_amount INT NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE trainer_sessions ADD COLUMN IF NOT EXISTS payment_type VARCHAR(60) NOT NULL DEFAULT ''");
        // Оценка «по пунктам»: что сделано хорошо / за что сняты баллы + настроение клиента.
        $pdo->exec("ALTER TABLE trainer_sessions ADD COLUMN IF NOT EXISTS pros TEXT NOT NULL DEFAULT '[]'");
        $pdo->exec("ALTER TABLE trainer_sessions ADD COLUMN IF NOT EXISTS cons TEXT NOT NULL DEFAULT '[]'");
        $pdo->exec("ALTER TABLE trainer_sessions ADD COLUMN IF NOT EXISTS sentiment VARCHAR(10) NOT NULL DEFAULT ''");
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
    if (!getGeminiApiKeys()) return ''; // без ключа честно сообщаем об ошибке, а не рисуем "условную" оценку

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
            'maxOutputTokens' => 1800,
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


/** Переписка дизайнера с ИИ-клиентом одним текстом — для анализатора «Сдать работу». */
function buildTrainerTranscript(PDO $pdo, int $sessionId, int $limit = 60): string
{
    $stmt = $pdo->prepare("SELECT role, content FROM trainer_messages WHERE session_id = ? ORDER BY id ASC");
    $stmt->execute([$sessionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = array_slice($rows, -$limit);
    $lines = [];
    foreach ($rows as $r) {
        [$txt] = extractPaymentMarker((string)$r['content']);
        $txt = trim($txt);
        if ($txt === '') continue;
        $lines[] = ($r['role'] === 'client' ? 'Клиент: ' : 'Дизайнер: ') . $txt;
    }
    return implode("\n", $lines);
}

/** Достаёт JSON-объект из ответа модели, даже если он обёрнут в ```json или сопровождён текстом. */
function parseModelJson(string $raw): ?array
{
    $raw = trim($raw);
    $start = strpos($raw, '{');
    $end = strrpos($raw, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    $data = json_decode(substr($raw, $start, $end - $start + 1), true);
    return is_array($data) ? $data : null;
}

function cleanPointsList($list, int $max = 5): array
{
    if (!is_array($list)) return [];
    $out = [];
    foreach ($list as $item) {
        $s = trim(is_string($item) ? $item : '');
        if ($s === '') continue;
        $out[] = mb_substr($s, 0, 160);
        if (count($out) >= $max) break;
    }
    return $out;
}

function sentimentForScore(int $score): string
{
    return $score >= 80 ? 'green' : ($score >= 50 ? 'yellow' : 'red');
}

function difficultyPersona(PDO $pdo, string $level): string
{
    // Блок 4.1 ТЗ: редактируется в админке (admin/ai_trainer_review.php) —
    // хранится в site_settings, значения по умолчанию совпадают с тем, что
    // было зашито в коде раньше.
    $defaults = [
        'easy'     => 'Клиент дружелюбный, лояльный, легко соглашается с идеями дизайнера, почти не придирается, максимум 1 несущественная правка.',
        'standard' => 'Клиент обычный, среднего уровня требовательности: иногда просит 1-2 уточнения или небольшую правку, в целом адекватен.',
        'hard'     => 'Клиент придирчивый и требовательный: часто просит правки, сомневается, сравнивает с конкурентами, торгуется по цене, но остаётся вежливым (без грубости и оскорблений). До 3-4 раундов правок.',
    ];
    $key = 'TRAINER_PROMPT_' . strtoupper($level);
    return getResSetting($pdo, $key, $defaults[$level] ?? $defaults['standard']);
}

/** Подставляет {CLIENT_NAME}/{RANDOM_BUDGET}/{HALF_BUDGET}/{TOPIC} в текст промпта сложности. */
function fillPromptPlaceholders(string $tpl, array $vars): string
{
    $map = [];
    foreach ($vars as $k => $v) { $map['{' . $k . '}'] = (string)$v; }
    return strtr($tpl, $map);
}

/** Случайный бюджет для сессии тренажёра, округлённый до сотни — реалистичнее "рваных" сумм. */
function generateTrainerBudget(): int
{
    return random_int(10, 50) * 100; // 1000..5000 ₽
}

/**
 * Вырезает маркер оплаты [PAYMENT_SUCCESS: amount=NNN, type="..."] из ответа
 * ИИ-клиента и возвращает [чистый текст без маркера, данные оплаты|null].
 * Без этого маркер так и остался бы виден дизайнеру как есть — сырым
 * текстом прямо в сообщении.
 */
function extractPaymentMarker(string $text, int $defaultAmount = 0): array
{
    // Терпимый разбор: любой регистр, пробелы, прямые/«ёлочки»/типографские
    // кавычки, "=" или ":" после amount/type, обёртка в [] или `` ` ``.
    // Раньше жёсткий шаблон не срабатывал на малейшем отличии формата, и
    // дизайнер видел сырой маркер прямо в реплике клиента.
    $pattern = '/[`\s]*\[\s*PAYMENT_SUCCESS\b[^\]]*\][`]*/iu';
    if (!preg_match($pattern, $text, $m)) return [$text, null];

    $marker = $m[0];
    $amount = $defaultAmount;
    if (preg_match('/amount\s*[=:]\s*(\d[\d\s]*)/iu', $marker, $a)) {
        $amount = (int)preg_replace('/\s+/', '', $a[1]);
    }
    $type = 'Предоплата';
    if (preg_match('/type\s*[=:]\s*["“”«\']?\s*([^"“”»\'\]]+)/iu', $marker, $t)) {
        $type = trim($t[1]);
    }
    $clean = trim(preg_replace($pattern, '', $text));
    if ($clean === '') $clean = 'Держи предоплату 👍';
    return [$clean, ['amount' => $amount, 'type' => $type]];
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
    $budget     = generateTrainerBudget();
    $halfBudget = intdiv($budget, 2);

    $personaPrompt = fillPromptPlaceholders(difficultyPersona($pdo, $difficulty), [
        'CLIENT_NAME'   => $clientName,
        'RANDOM_BUDGET' => $budget,
        'HALF_BUDGET'   => $halfBudget,
        'TOPIC'         => $topic,
    ]);

    $briefPrompt = "Тема заказа: «{$topic}». " . $personaPrompt . " "
        . "Напиши ПЕРВОЕ сообщение дизайнеру СТРОГО по правилам ведения диалога выше (пункт про первое сообщение). "
        . "Если правил нет — просто поздоровайся и коротко скажи, что хочешь заказать; подробное ТЗ выдавай позже, по вопросам дизайнера. "
        . "Свой бюджет в первом сообщении НЕ называй и вообще не озвучивай, пока дизайнер сам не назвал цену. "
        . "Пиши как реальный человек в мессенджере: без markdown и звёздочек. Не упоминай, что ты ИИ, и не пиши маркер оплаты в первом сообщении.";

    $brief = geminiText($briefPrompt, [], "Напиши первое сообщение.");
    [$brief] = extractPaymentMarker($brief); // на случай, если модель всё же вставила маркер

    $stmt = $pdo->prepare("INSERT INTO trainer_sessions (tg_id, client_name, difficulty, topic, brief, budget) VALUES (?,?,?,?,?,?) RETURNING id");
    $stmt->execute([$tgId, $clientName, $difficulty, $topic, $brief, $budget]);
    $sessionId = (int)$stmt->fetchColumn();

    $pdo->prepare("INSERT INTO trainer_messages (session_id, role, content) VALUES (?, 'client', ?)")->execute([$sessionId, $brief]);

    jexit(['ok' => true, 'session_id' => $sessionId, 'client_name' => $clientName, 'difficulty' => $difficulty, 'topic' => $topic, 'budget' => $budget,
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

    $budget = (int)$session['budget'];
    $halfBudget = intdiv($budget, 2);
    $personaPrompt = fillPromptPlaceholders(difficultyPersona($pdo, $session['difficulty']), [
        'CLIENT_NAME'   => $session['client_name'],
        'RANDOM_BUDGET' => $budget,
        'HALF_BUDGET'   => $halfBudget,
        'TOPIC'         => $session['topic'],
    ]);

    $alreadyPaid = (int)$session['paid_amount'] > 0;
    $systemPrompt = "Тема заказа: «{$session['topic']}». " . $personaPrompt . " "
        . "Своё первое сообщение дизайнеру (с ТЗ) ты уже отправил, вот оно: «{$session['brief']}». "
        . ($alreadyPaid
            ? "Предоплату ты уже отправил ранее — повторно маркер оплаты НЕ пиши. "
            : "Если по правилам выше пора отправить маркер оплаты — напиши его В ТОЧНОСТИ в формате, который задан в правилах (не меняй синтаксис). ")
        . "Свой бюджет ({$budget} ₽) называй только когда торгуешься по цене, не раньше. "
        . "Отвечай ОЧЕНЬ коротко — 1-3 предложения, как в реальном чате Telegram, никаких длинных монологов и списков. Без markdown, оставайся в характере на протяжении всего диалога.";

    $rawReply = geminiText($systemPrompt, $turns, $content);
    [$reply, $payment] = extractPaymentMarker($rawReply, $halfBudget);
    if ($payment !== null && !$alreadyPaid) {
        $pdo->prepare("UPDATE trainer_sessions SET paid_amount = ?, payment_type = ? WHERE id = ?")
            ->execute([$payment['amount'], $payment['type'], $sessionId]);
    }

    $pdo->prepare("INSERT INTO trainer_messages (session_id, role, content) VALUES (?, 'client', ?)")->execute([$sessionId, $reply]);
    $pdo->prepare("UPDATE trainer_sessions SET updated_at = NOW() WHERE id = ?")->execute([$sessionId]);

    jexit(['ok' => true, 'reply' => $reply, 'payment' => $payment]);
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
    $transcript = buildTrainerTranscript($pdo, $sessionId);
    $paidInfo = (int)$session['paid_amount'] > 0
        ? "Предоплата от клиента ПОЛУЧЕНА ({$session['paid_amount']} ₽, {$session['payment_type']})."
        : "Предоплата от клиента НЕ была получена — дизайнер не довёл переговоры до оплаты.";

    $scorePrompt = "Ты — опытный арт-директор и наставник, оцениваешь тренировочную сдачу дизайнера. "
        . "Клиент играл роль: имя «{$session['client_name']}», тема «{$session['topic']}», сложность {$session['difficulty']}. "
        . "Вот ПОЛНАЯ переписка дизайнера с клиентом (в ней клиент раскрывал детали ТЗ):\n---\n{$transcript}\n---\n"
        . $paidInfo . " К сообщению приложено изображение — это сданная работа. "
        . "Оцени по критериям (в сумме 100): соответствие работы ТЗ из переписки — до 45; общение (вежливость, уточняющие вопросы по ТЗ, инициативность) — до 25; "
        . "работа с ценой и выход на оплату — до 20; соблюдение условий (размеры, сроки, правки) — до 10. "
        . "Будь честным: если работа не соответствует ТЗ (не та игра/цвета/персонаж) — балл низкий, но без придирок к мелочам вроде положения текста на пиксель. "
        . "Пункты должны быть КОНКРЕТНЫМИ и ссылаться на то, что реально было в переписке или на картинке (без общих фраз). "
        . "Ответь СТРОГО JSON без markdown и пояснений: "
        . "{\"score\": <0-100>, \"pros\": [\"что сделано хорошо\", ...2-5 пунктов], \"cons\": [\"за что сняты баллы\", ...0-5 пунктов], "
        . "\"review_text\": \"отзыв клиента 1-3 предложения от первого лица, в его характере\"}";

    $raw = geminiWithImage($scorePrompt, 'Оцени сдачу дизайнера.', $dir . $filename, $mimeMap[$ext]);
    $parsed = parseModelJson($raw);
    if ($parsed === null || !isset($parsed['score'])) {
        @unlink($dir . $filename);
        jexit(['ok' => false, 'error' => 'ИИ не смог оценить работу (пустой ответ). Попробуй сдать ещё раз через минуту.']);
    }
    $score = max(0, min(100, (int)$parsed['score']));
    $pros = cleanPointsList($parsed['pros'] ?? []);
    $cons = cleanPointsList($parsed['cons'] ?? []);
    $review = trim((string)($parsed['review_text'] ?? ($parsed['review'] ?? ''))) ?: 'Спасибо, работа принята!';
    $sentiment = sentimentForScore($score); // цвет считаем сами по баллу — не доверяем модели

    $pdo->prepare("INSERT INTO trainer_messages (session_id, role, content, attachment_url) VALUES (?, 'designer', 'Сдал работу на проверку', ?)")
        ->execute([$sessionId, $publicUrl]);
    $pdo->prepare("UPDATE trainer_sessions SET status = 'scored', score = ?, review = ?, pros = ?, cons = ?, sentiment = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$score, $review, json_encode($pros, JSON_UNESCAPED_UNICODE), json_encode($cons, JSON_UNESCAPED_UNICODE), $sentiment, $sessionId]);

    jexit(['ok' => true, 'score' => $score, 'review' => $review, 'pros' => $pros, 'cons' => $cons, 'sentiment' => $sentiment, 'attachment_url' => $publicUrl]);
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
            . "Отзыв ИИ-клиента: {$session['review']}\n"
            . "✅ " . implode('; ', json_decode((string)$session['pros'], true) ?: ['—']) . "\n"
            . "⚠️ " . implode('; ', json_decode((string)$session['cons'], true) ?: ['—']);
        @file_get_contents("https://api.telegram.org/bot{$token}/sendMessage?chat_id={$adminId}&text=" . urlencode($text));
    }
    jexit(['ok' => true]);
}

case 'list_sessions': {
    $stmt = $pdo->prepare("SELECT id, client_name, topic, difficulty, status, score, admin_reaction, admin_comment FROM trainer_sessions WHERE tg_id = ? ORDER BY updated_at DESC LIMIT 50");
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
    $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

    // Сессии, сыгранные до починки разбора маркера, могли сохранить
    // "сырой" [PAYMENT_SUCCESS:...] прямо в тексте — чистим при выдаче и
    // восстанавливаем факт оплаты, чтобы пузырёк 💰 всё равно показался.
    $paidAmount = (int)$session['paid_amount'];
    $paymentType = (string)$session['payment_type'];
    foreach ($messages as &$m) {
        if ($m['role'] !== 'client') continue;
        [$clean, $pay] = extractPaymentMarker((string)$m['content'], intdiv((int)$session['budget'], 2));
        if ($pay !== null) {
            $m['content'] = $clean;
            if ($paidAmount === 0) {
                $paidAmount = $pay['amount']; $paymentType = $pay['type'];
                $pdo->prepare("UPDATE trainer_sessions SET paid_amount = ?, payment_type = ? WHERE id = ?")
                    ->execute([$paidAmount, $paymentType, $sessionId]);
            }
        }
    }
    unset($m);

    jexit(['ok' => true, 'client_name' => $session['client_name'], 'topic' => $session['topic'], 'difficulty' => $session['difficulty'],
        'status' => $session['status'], 'score' => $session['score'], 'review' => $session['review'],
        'shared_with_admin' => (bool)$session['shared_with_admin'],
        'admin_reaction' => $session['admin_reaction'], 'admin_comment' => $session['admin_comment'],
        'budget' => (int)$session['budget'], 'paid_amount' => $paidAmount, 'payment_type' => $paymentType,
        'pros' => json_decode((string)$session['pros'], true) ?: [], 'cons' => json_decode((string)$session['cons'], true) ?: [],
        'sentiment' => (string)$session['sentiment'],
        'messages' => $messages]);
}

default:
    jexit(['ok' => false, 'error' => 'Неизвестное действие']);
}