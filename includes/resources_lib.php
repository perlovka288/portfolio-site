<?php
/**
 * Библиотека закрытого раздела ресурсов (Блок 3 ТЗ).
 * Один общий склад под 4 фиксированные категории:
 *   psd      — посты с превью + ссылка на сообщение в канале
 *   font     — файлы .ttf
 *   brush    — стили/кисти для Photoshop
 *   sd_video — видео-инструкции по установке Stable Diffusion
 * Плюс отдельно текст гайда по SD (хранится как обычная настройка сайта,
 * тем же способом, что уже используется для ИИ-промпта/ключей).
 */

function ensureResourcesSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pack_resources (
            id SERIAL PRIMARY KEY,
            type VARCHAR(20) NOT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            preview_image TEXT NOT NULL DEFAULT '',
            telegram_url TEXT NOT NULL DEFAULT '',
            file_url TEXT NOT NULL DEFAULT '',
            file_id TEXT NOT NULL DEFAULT '',
            file_name TEXT NOT NULL DEFAULT '',
            video_url TEXT NOT NULL DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");
        // На случай, если таблица уже существовала до этого обновления —
        // добавляем недостающие колонки под прямое скачивание (Блок 2.2 ТЗ).
        $pdo->exec("ALTER TABLE pack_resources ADD COLUMN IF NOT EXISTS file_id TEXT NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE pack_resources ADD COLUMN IF NOT EXISTS file_name TEXT NOT NULL DEFAULT ''");
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(64) PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ''
        )");
        // Лайки и избранное (Блок 2.3 ТЗ) — один пользователь (по tg_id) не
        // может лайкнуть/добавить в избранное один и тот же материал дважды.
        $pdo->exec("CREATE TABLE IF NOT EXISTS resource_likes (
            id SERIAL PRIMARY KEY,
            resource_id INT NOT NULL REFERENCES pack_resources(id) ON DELETE CASCADE,
            tg_id VARCHAR(64) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE(resource_id, tg_id)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS resource_favorites (
            id SERIAL PRIMARY KEY,
            resource_id INT NOT NULL REFERENCES pack_resources(id) ON DELETE CASCADE,
            tg_id VARCHAR(64) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE(resource_id, tg_id)
        )");
    } catch (Throwable $e) {
        error_log('ensureResourcesSchema error: ' . $e->getMessage());
    }
}

function getResSetting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $stmt = $pdo->prepare("SELECT value FROM site_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false && $val !== null && $val !== '' ? (string)$val : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function setResSetting(PDO $pdo, string $key, string $value): void
{
    try {
        $pdo->prepare("
            INSERT INTO site_settings (setting_key, value) VALUES (?, ?)
            ON CONFLICT (setting_key) DO UPDATE SET value = EXCLUDED.value
        ")->execute([$key, $value]);
    } catch (Throwable $e) {
        error_log('setResSetting error: ' . $e->getMessage());
    }
}

/** @return array<int, array<string,mixed>> */
function listPackResources(PDO $pdo, string $type): array
{
    try {
        $stmt = $pdo->prepare("SELECT * FROM pack_resources WHERE type = ? ORDER BY sort_order ASC, id DESC");
        $stmt->execute([$type]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function createPackResource(PDO $pdo, array $data): int
{
    $stmt = $pdo->prepare("
        INSERT INTO pack_resources (type, title, description, preview_image, telegram_url, file_url, file_id, file_name, video_url)
        VALUES (:type, :title, :description, :preview_image, :telegram_url, :file_url, :file_id, :file_name, :video_url)
    ");
    $stmt->execute([
        ':type'          => $data['type'] ?? '',
        ':title'         => $data['title'] ?? '',
        ':description'   => $data['description'] ?? '',
        ':preview_image' => $data['preview_image'] ?? '',
        ':telegram_url'  => $data['telegram_url'] ?? '',
        ':file_url'      => $data['file_url'] ?? '',
        ':file_id'       => $data['file_id'] ?? '',
        ':file_name'     => $data['file_name'] ?? '',
        ':video_url'     => $data['video_url'] ?? '',
    ]);
    return (int)$pdo->lastInsertId('pack_resources_id_seq');
}

function deletePackResource(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM pack_resources WHERE id = ?")->execute([$id]);
}

/** Переключает лайк текущего пользователя на материале. Возвращает новое состояние (true = лайкнул). */
function toggleResourceLike(PDO $pdo, int $resourceId, string $tgId): bool
{
    $stmt = $pdo->prepare("SELECT id FROM resource_likes WHERE resource_id = ? AND tg_id = ?");
    $stmt->execute([$resourceId, $tgId]);
    if ($stmt->fetchColumn()) {
        $pdo->prepare("DELETE FROM resource_likes WHERE resource_id = ? AND tg_id = ?")->execute([$resourceId, $tgId]);
        return false;
    }
    try {
        $pdo->prepare("INSERT INTO resource_likes (resource_id, tg_id) VALUES (?, ?)")->execute([$resourceId, $tgId]);
    } catch (Throwable $e) {
        // Гонка двух кликов — запись уже есть, это ок (UNIQUE(resource_id, tg_id)).
    }
    return true;
}

/** Переключает «избранное» текущего пользователя на материале. Возвращает новое состояние (true = добавлено). */
function toggleResourceFavorite(PDO $pdo, int $resourceId, string $tgId): bool
{
    $stmt = $pdo->prepare("SELECT id FROM resource_favorites WHERE resource_id = ? AND tg_id = ?");
    $stmt->execute([$resourceId, $tgId]);
    if ($stmt->fetchColumn()) {
        $pdo->prepare("DELETE FROM resource_favorites WHERE resource_id = ? AND tg_id = ?")->execute([$resourceId, $tgId]);
        return false;
    }
    try {
        $pdo->prepare("INSERT INTO resource_favorites (resource_id, tg_id) VALUES (?, ?)")->execute([$resourceId, $tgId]);
    } catch (Throwable $e) {
    }
    return true;
}

/**
 * Лайки/избранное для набора материалов одним запросом (без N+1), чтобы
 * отрисовать плитку/список. Возвращает [resource_id => ['likes'=>int,'liked'=>bool,'favorited'=>bool]].
 */
function getResourceEngagement(PDO $pdo, array $resourceIds, string $tgId): array
{
    $out = [];
    foreach ($resourceIds as $id) { $out[(int)$id] = ['likes' => 0, 'liked' => false, 'favorited' => false]; }
    if (!$resourceIds) return $out;
    $in = implode(',', array_fill(0, count($resourceIds), '?'));

    $stmt = $pdo->prepare("SELECT resource_id, COUNT(*) c FROM resource_likes WHERE resource_id IN ($in) GROUP BY resource_id");
    $stmt->execute(array_values($resourceIds));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $out[(int)$row['resource_id']]['likes'] = (int)$row['c']; }

    if ($tgId !== '') {
        $params = array_merge(array_values($resourceIds), [$tgId]);
        $stmt = $pdo->prepare("SELECT resource_id FROM resource_likes WHERE resource_id IN ($in) AND tg_id = ?");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rid) { $out[(int)$rid]['liked'] = true; }

        $stmt = $pdo->prepare("SELECT resource_id FROM resource_favorites WHERE resource_id IN ($in) AND tg_id = ?");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rid) { $out[(int)$rid]['favorited'] = true; }
    }
    return $out;
}

/** Все материалы (любого типа), которые пользователь добавил в «Избранное», новые сверху. */
function listFavoriteResources(PDO $pdo, string $tgId): array
{
    if ($tgId === '') return [];
    $stmt = $pdo->prepare("
        SELECT pr.* FROM pack_resources pr
        INNER JOIN resource_favorites rf ON rf.resource_id = pr.id
        WHERE rf.tg_id = ?
        ORDER BY rf.created_at DESC
    ");
    $stmt->execute([$tgId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Простая локальная загрузка картинки-превью (для ручного добавления PSD-
 * поста прямо на сайте) — без внешнего хостинга, аналогично uploads/psd/.
 */
function uploadPackResourcePreview(string $field, string $uploadDir): string
{
    $err = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE || empty($_FILES[$field]['name'])) return '';
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES[$field]['tmp_name'])) return '';
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return '';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
    if (!is_writable($uploadDir)) return '';
    $filename = 'psdres_' . time() . '_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($_FILES[$field]['tmp_name'], $uploadDir . $filename)) {
        return 'pack_resources/' . $filename;
    }
    return '';
}
