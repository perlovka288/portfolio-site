<?php

/**
 * YouTubeParser — работает с YouTube Data API v3 через cURL.
 * Не требует google/apiclient — только ключ API.
 */
class YouTubeParser
{
    private string $apiKey;
    private string $baseUrl = 'https://www.googleapis.com/youtube/v3/';

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Ищет видео по ключевому слову, публикованные за последние 48 часов.
     * Возвращает массив channel_id (уникальные).
     */
    public function searchChannelsByKeyword(string $keyword, string $region = 'RU', int $maxResults = 50): array
    {
        $publishedAfter = gmdate('Y-m-d\TH:i:s\Z', strtotime('-48 hours'));

        $params = [
            'part'            => 'snippet',
            'q'               => $keyword,
            'type'            => 'video',
            'regionCode'      => $region,
            'publishedAfter'  => $publishedAfter,
            'maxResults'      => min($maxResults, 50),
            'key'             => $this->apiKey,
        ];

        $data = $this->get('search', $params);
        if (!$data || empty($data['items'])) {
            return [];
        }

        $channelIds = [];
        foreach ($data['items'] as $item) {
            $cid = $item['snippet']['channelId'] ?? null;
            if ($cid && !in_array($cid, $channelIds, true)) {
                $channelIds[] = $cid;
            }
        }

        // Если запросили больше 50 — делаем pageToken-пагинацию
        $nextToken = $data['nextPageToken'] ?? null;
        while ($nextToken && count($channelIds) < $maxResults) {
            $params['pageToken'] = $nextToken;
            $data = $this->get('search', $params);
            if (!$data || empty($data['items'])) break;
            foreach ($data['items'] as $item) {
                $cid = $item['snippet']['channelId'] ?? null;
                if ($cid && !in_array($cid, $channelIds, true)) {
                    $channelIds[] = $cid;
                }
            }
            $nextToken = $data['nextPageToken'] ?? null;
        }

        return array_slice($channelIds, 0, $maxResults);
    }

    /**
     * Получить статистику каналов по массиву ID.
     * Возвращает ассоциативный массив: channel_id => [name, url, subscribers, description, ...].
     */
    public function getChannelStats(array $channelIds): array
    {
        if (!$channelIds) return [];

        $results = [];
        // API принимает до 50 ID за раз
        foreach (array_chunk($channelIds, 50) as $chunk) {
            $params = [
                'part' => 'snippet,statistics,brandingSettings',
                'id'   => implode(',', $chunk),
                'key'  => $this->apiKey,
            ];
            $data = $this->get('channels', $params);
            if (!$data || empty($data['items'])) continue;

            foreach ($data['items'] as $ch) {
                $cid = $ch['id'];
                $results[$cid] = [
                    'channel_id'       => $cid,
                    'channel_name'     => $ch['snippet']['title'] ?? '',
                    'channel_url'      => 'https://www.youtube.com/channel/' . $cid,
                    'description'      => $ch['snippet']['description'] ?? '',
                    'subscriber_count' => (int)($ch['statistics']['subscriberCount'] ?? 0),
                    'preview_url'      => $ch['snippet']['thumbnails']['default']['url'] ?? null,
                ];
            }
        }

        return $results;
    }

    /**
     * Фильтрует каналы по количеству подписчиков.
     */
    public function filterBySubscribers(array $channels, int $min = 500, int $max = 20000): array
    {
        return array_filter($channels, function ($ch) use ($min, $max) {
            return $ch['subscriber_count'] >= $min && $ch['subscriber_count'] <= $max;
        });
    }

    /**
     * Получить URL последнего видео канала (для video_url).
     */
    public function getLastVideoUrl(string $channelId): ?string
    {
        $params = [
            'part'       => 'snippet',
            'channelId'  => $channelId,
            'order'      => 'date',
            'maxResults' => 1,
            'type'       => 'video',
            'key'        => $this->apiKey,
        ];
        $data = $this->get('search', $params);
        $vid  = $data['items'][0]['id']['videoId'] ?? null;
        return $vid ? 'https://www.youtube.com/watch?v=' . $vid : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE
    // ─────────────────────────────────────────────────────────────────────────

    private function get(string $endpoint, array $params): ?array
    {
        $url = $this->baseUrl . $endpoint . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $this->logError("cURL error for $endpoint: $err");
            return null;
        }

        $decoded = json_decode($body, true);
        if (isset($decoded['error'])) {
            $this->logError("YouTube API error: " . json_encode($decoded['error']));
            return null;
        }

        return $decoded;
    }

    private function logError(string $msg): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] [YouTubeParser] ' . $msg . PHP_EOL;
        $logFile = __DIR__ . '/../../bot_runtime_error.log';
        // Если лог не найден рядом — пробуем корень
        if (!file_exists(dirname($logFile))) {
            $logFile = dirname(__DIR__, 2) . '/bot_runtime_error.log';
        }
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
