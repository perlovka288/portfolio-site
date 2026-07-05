<?php
$botToken = getenv('BOT_TOKEN');
$apiKey = getenv('GEMINI_API_KEY');

$update = json_decode(file_get_contents('php://input'), true);
if (!$update || !isset($update['message']['text'])) exit;

$message = $update['message']['text'];
$chatId = $update['message']['chat']['id'];
$userId = $update['message']['from']['id'];
$isPrivate = ($update['message']['chat']['type'] == 'private');

// ЛОГИКА ОГРАНИЧЕНИЙ
$limitFile = sys_get_temp_dir() . "/ai_limit_{$userId}.txt";
$history = file_exists($limitFile) ? json_decode(file_get_contents($limitFile), true) : [];

// Очищаем историю старше 60 секунд
$now = time();
$history = array_filter($history, function($timestamp) use ($now) {
    return ($now - $timestamp) < 60;
});

if (count($history) >= 5) {
    file_get_contents("https://api.telegram.org/bot$botToken/sendMessage?chat_id=$chatId&text=" . urlencode("Погоди, дай передохнуть! Лимит 5 запросов в минуту исчерпан ⏳"));
    exit;
}

// Записываем новый запрос
$history[] = $now;
file_put_contents($limitFile, json_encode($history));

// ДАЛЕЕ КОД ЗАПРОСА К GEMINI (как в прошлом сообщении)
if (strpos($message, 'KostlimAI') === 0) {
    $userQuery = trim(str_replace('KostlimAI', '', $message));
    
    $systemPrompt = $isPrivate 
        ? "Ты — менеджер студии Kostlim Design. Общайся по делу, помогай с заказами, прайсом и правилами. Будь вежливым, но строгим." 
        : "Ты — свободный AI-собеседник. Общайся легко, весело, поддерживай любые темы, будь своим парнем.";

    $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey;
    
    $payload = [
        'contents' => [['role' => 'user', 'parts' => [['text' => $userQuery]]]],
        'systemInstruction' => ['parts' => [['text' => $systemPrompt]]]
    ];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    $aiReply = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'Не смог придумать ответ :(';

    file_get_contents("https://api.telegram.org/bot$botToken/sendMessage?chat_id=$chatId&text=" . urlencode($aiReply));
}