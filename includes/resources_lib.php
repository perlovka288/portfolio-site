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

function ensureResourcesSchema__run(PDO $pdo): void
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
        // Разделы закрытого раздела (встроенные + созданные админом).
        $pdo->exec("CREATE TABLE IF NOT EXISTS pack_sections (
            id SERIAL PRIMARY KEY,
            slug VARCHAR(30) NOT NULL UNIQUE,
            title VARCHAR(80) NOT NULL,
            icon VARCHAR(16) NOT NULL DEFAULT '',
            sort_order INT NOT NULL DEFAULT 100,
            is_builtin BOOLEAN NOT NULL DEFAULT FALSE
        )");
        $seed = $pdo->prepare("INSERT INTO pack_sections (slug, title, icon, sort_order, is_builtin) VALUES (?, ?, ?, ?, TRUE) ON CONFLICT (slug) DO NOTHING");
        foreach (packBuiltinSections() as $i => $b) {
            $seed->execute([$b['slug'], $b['title'], $b['icon'], ($i + 1) * 10]);
        }
        // Старые эмодзи-иконки встроенных разделов → линейные иконки
        $fixIco = $pdo->prepare("UPDATE pack_sections SET icon = ? WHERE slug = ? AND icon NOT IN ('" . implode("','", packIconKeys()) . "')");
        foreach (packBuiltinSections() as $b) { $fixIco->execute([$b['icon'], $b['slug']]); }
    } catch (Throwable $e) {
        error_log('ensureResourcesSchema error: ' . $e->getMessage());
    }
}

/** KUI: схема проверяется один раз на контейнер (см. includes/schema_once.php) */
function ensureResourcesSchema(PDO $pdo): void
{
    if (!function_exists('kuiSchemaDone')) { require_once __DIR__ . '/schema_once.php'; }
    if (kuiSchemaDone('ensureResourcesSchema_v3')) { return; }
    ensureResourcesSchema__run($pdo);
    kuiSchemaMark('ensureResourcesSchema_v3');
}

function getResSetting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        require_once __DIR__ . '/kui_cache.php';
        $val = kuiSettingGet($pdo, $key);
        return $val !== null && $val !== '' ? $val : $default;
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
        if (function_exists('kuiSettingsForget')) { kuiSettingsForget(); }
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

/**
 * Вытаскивает id файла из обычной ссылки на Google Drive (любого из
 * распространённых форматов), чтобы скачивание материала могло идти через
 * авторизованный API сервис-аккаунта (download.php → downloadFromGoogleDrive),
 * а не через публичную страницу Google с предупреждением "не удалось
 * проверить на вирусы" и капризным confirm-токеном — при условии, что файл
 * лежит в папке, к которой у сервис-аккаунта есть доступ (см. GDRIVE_FOLDER_ID).
 * Возвращает null, если это не похоже на ссылку Google Drive.
 */
function extractGDriveFileId(string $link): ?string
{
    // .../file/d/<id>/view  или  .../file/d/<id>/edit
    if (preg_match('~drive\.google\.com/file/d/([a-zA-Z0-9_-]+)~', $link, $m)) return $m[1];
    // .../uc?...id=<id>...   или   .../download?...id=<id>...  (оба домена: drive.google.com и drive.usercontent.google.com)
    if (preg_match('~[?&]id=([a-zA-Z0-9_-]+)~', $link, $m)) return $m[1];
    // .../drive/folders/<id> — это ссылка на ПАПКУ, а не на файл, не подходит
    return null;
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
 * Загрузка картинки-превью для PSD-поста. FIX: раньше сохранялось только
 * локально на диск сервера (uploads/pack_resources/) — после каждого
 * git push/деплоя эта папка не сохраняется, и превью "слетали". Теперь
 * грузим на ImgBB — постоянная ссылка, переживает любой деплой. Ключи
 * читаем ТАК ЖЕ, как остальной сайт (admin/index.php::uploadToImgBB): из
 * таблицы site_settings (вкладка "Ключи и API" в админке), с фолбэком на
 * переменные окружения IMGBB_API_KEY/2/3. Локальное сохранение — запасной
 * вариант, если ImgBB недоступен (ключи не заданы/лимит исчерпан).
 */
function uploadPackResourcePreview(PDO $pdo, string $field, string $uploadDir): string
{
    $err = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE || empty($_FILES[$field]['name'])) return '';
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES[$field]['tmp_name'])) return '';
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return '';

    $imgbbErr = null;
    $imgbbUrl = uploadPackImageToImgBB($pdo, $_FILES[$field]['tmp_name'], 'psd_preview_' . time(), $imgbbErr);
    if ($imgbbUrl !== '') return $imgbbUrl; // resImg() отдаёт http(s)-ссылки как есть
    // KUI: на локальный диск НЕ сохраняем — он стирается при деплое (превью «пропадали»)
    $GLOBALS['kuiImgWarn'] = '❌ Хранилище картинок не приняло превью' . ($imgbbErr ? " ({$imgbbErr})" : '') . ' — проверь Cloudinary/ImgBB в «Ключи и API» и загрузи ещё раз.';
    return '';
}

/**
 * Тот же ImgBB-аплоад, что и uploadToImgBB() в admin/index.php (ключи из
 * site_settings + фолбэк на getenv), но без зависимости от admin/index.php
 * (его нельзя просто require — там объявлен весь остальной файл админки).
 */
function uploadPackImageToImgBB(PDO $pdo, string $tmpPath, string $name = 'image', ?string &$error = null): string
{
    require_once __DIR__ . '/imgbb.php';
    if (!function_exists('curl_init')) { $error = 'на сервере не включён PHP-модуль curl'; return ''; }
    return imgbbUpload($tmpPath, $name, $pdo, $error);
}

/**
 * Локальная загрузка файла материала (шрифт/кисти/видео) прямо на сервер —
 * запасной вариант, когда Google Drive не настроен (нет admin/gdrive_key.json
 * или переменной GDRIVE_FOLDER_ID). В отличие от Google Drive, локальный
 * файл гарантированно скачивается с Content-Disposition: attachment через
 * download.php — то, что и требуется по ТЗ, — без внешних зависимостей.
 *
 * @return array{url:string,file_name:string}|null
 */
function uploadPackResourceFileLocal(string $field, string $uploadDir): ?array
{
    $err = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE || empty($_FILES[$field]['name'])) return null;
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES[$field]['tmp_name'])) return null;
    $origName = basename((string)$_FILES[$field]['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed = ['ttf', 'otf', 'woff', 'woff2', 'abr', 'asl', 'atn', 'grd', 'pat', 'psd', 'psb', 'zip', 'rar', '7z', 'pdf', 'mp4', 'mov', 'webm'];
    if (!in_array($ext, $allowed, true)) return null;
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
    if (!is_writable($uploadDir)) return null;
    $filename = 'res_' . time() . '_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($_FILES[$field]['tmp_name'], $uploadDir . $filename)) {
        return ['url' => '/uploads/pack_resources/' . $filename, 'file_name' => $origName];
    }
    return null;
}


/**
 * Загрузка файла материала (шрифт/кисти/PSD/видео) в Cloudinary — постоянное хранилище,
 * файлы переживают любой git push / деплой (в отличие от папки uploads/ на Render).
 * Ключи: CLOUDINARY_CLOUD_NAME / API_KEY / API_SECRET (окружение или админка → «Ключи и API»).
 * Возвращает ['url' => https-ссылка Cloudinary, 'file_name' => исходное имя] или null (причина — в $error).
 */
function uploadPackResourceFileCloudinary(string $field, ?PDO $pdo = null, ?string &$error = null): ?array
{
    $error = null;
    $err = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE || empty($_FILES[$field]['name'])) return null;
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        $error = 'файл не дошёл до сервера (возможно, больше upload_max_filesize / post_max_size)';
        return null;
    }
    $origName = basename((string)$_FILES[$field]['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed = ['ttf', 'otf', 'woff', 'woff2', 'abr', 'asl', 'atn', 'grd', 'pat', 'psd', 'psb', 'zip', 'rar', '7z', 'pdf', 'mp4', 'mov', 'webm'];
    if (!in_array($ext, $allowed, true)) { $error = 'недопустимое расширение .' . $ext; return null; }

    require_once __DIR__ . '/image_store.php';
    if (!($pdo instanceof PDO)) { $pdo = $GLOBALS['pdo'] ?? null; }
    if (!imageStoreConfigured($pdo)) { $error = 'Cloudinary не настроен'; return null; }

    $base = pathinfo($origName, PATHINFO_FILENAME);
    $e = null;
    // folder != 'kostlim' → файл НЕ пережимается как картинка, грузится как есть
    $url = imageStoreUpload($_FILES[$field]['tmp_name'], 'res_' . $base, $pdo, $e, 300, 2, 'kostlim/resources', $origName);
    if ($url === '') { $error = (string)$e ?: 'Cloudinary не принял файл'; return null; }
    return ['url' => $url, 'file_name' => $origName];
}

/** true — ссылка ведёт на файл в Cloudinary (https://res.cloudinary.com/...). */
function isCloudinaryUrl(string $url): bool
{
    $p = parse_url($url);
    return $p && ($p['scheme'] ?? '') === 'https' && ($p['host'] ?? '') === 'res.cloudinary.com';
}


/* ═════════ Видео: встраивание плеера по ссылке ═════════ */

/**
 * Определяет, как показать видео по ссылке — прямо на сайте, без скачивания.
 * Поддерживается: YouTube (в т.ч. shorts / youtu.be), Vimeo, Rutube, Google Drive,
 * видео в Cloudinary и прямые ссылки на .mp4/.webm/.mov/.m4v.
 * @return array{kind:string,src:string,thumb:string}|null  kind = 'iframe' | 'video'; null — встроить нельзя
 */
function videoEmbedInfo(string $url): ?array
{
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) return null;
    $p = parse_url($url);
    $host = strtolower(preg_replace('/^www\./', '', $p['host'] ?? ''));
    $path = $p['path'] ?? '';
    parse_str($p['query'] ?? '', $q);

    // YouTube
    $yt = '';
    if ($host === 'youtu.be') { $yt = trim($path, '/'); }
    elseif (in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com'], true)) {
        if (!empty($q['v'])) { $yt = (string)$q['v']; }
        elseif (preg_match('#^/(?:embed|shorts|live|v)/([A-Za-z0-9_-]{6,})#', $path, $m)) { $yt = $m[1]; }
    }
    if ($yt !== '' && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $yt)) {
        $start = '';
        if (!empty($q['t']) && preg_match('/^(\d+)/', (string)$q['t'], $tm)) { $start = '&start=' . (int)$tm[1]; }
        return ['kind' => 'iframe',
            'src'   => 'https://www.youtube-nocookie.com/embed/' . $yt . '?rel=0&modestbranding=1&playsinline=1' . $start,
            'thumb' => 'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg'];
    }

    // Vimeo
    if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true) && preg_match('#/(\d{5,})#', $path, $m)) {
        return ['kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/' . $m[1], 'thumb' => ''];
    }

    // Rutube
    if ($host === 'rutube.ru' && preg_match('#/(?:video|play/embed)/([a-f0-9]{32})#i', $path, $m)) {
        return ['kind' => 'iframe', 'src' => 'https://rutube.ru/play/embed/' . $m[1], 'thumb' => ''];
    }

    // Google Drive (файл должен быть открыт «всем, у кого есть ссылка»)
    if (in_array($host, ['drive.google.com', 'drive.usercontent.google.com'], true)) {
        $gid = extractGDriveFileId($url);
        if ($gid) return ['kind' => 'iframe', 'src' => 'https://drive.google.com/file/d/' . $gid . '/preview', 'thumb' => ''];
    }

    // Cloudinary-видео и прямые ссылки на видеофайл
    $isCloudVideo = $host === 'res.cloudinary.com' && strpos($path, '/video/upload/') !== false;
    if ($isCloudVideo || preg_match('#\.(mp4|webm|mov|m4v|ogv)$#i', $path)) {
        $thumb = '';
        if ($isCloudVideo) {
            $thumb = preg_replace('#/video/upload/#', '/video/upload/so_1,w_800,c_limit,f_jpg,q_auto/', $url, 1);
            $thumb = preg_replace('#\.[A-Za-z0-9]{2,4}(\?.*)?$#', '.jpg', $thumb);
        }
        return ['kind' => 'video', 'src' => $url, 'thumb' => $thumb];
    }
    return null;
}

/* ═════════ Разделы (вкладки) закрытого раздела ═════════ */

/** Встроенные разделы — их нельзя удалить, но можно переименовать и сменить иконку. */
function packBuiltinSections(): array
{
    return [
        ['slug' => 'psd',      'title' => 'PSD',     'icon' => 'layers'],
        ['slug' => 'font',     'title' => 'Шрифты',  'icon' => 'type'],
        ['slug' => 'brush',    'title' => 'Стили',   'icon' => 'brush'],
        ['slug' => 'sd_video', 'title' => 'SD',      'icon' => 'sparkles'],
    ];
}

/** @return array<int, array{id:int,slug:string,title:string,icon:string,sort_order:int,is_builtin:bool}> */
function getPackSections(PDO $pdo): array
{
    try {
        $rows = $pdo->query("SELECT * FROM pack_sections ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows) {
            foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['is_builtin'] = !empty($r['is_builtin']) && $r['is_builtin'] !== 'f'; }
            unset($r);
            return $rows;
        }
    } catch (Throwable $e) {
        error_log('getPackSections error: ' . $e->getMessage());
    }
    $out = [];
    foreach (packBuiltinSections() as $i => $b) {
        $out[] = ['id' => 0, 'slug' => $b['slug'], 'title' => $b['title'], 'icon' => $b['icon'], 'sort_order' => ($i + 1) * 10, 'is_builtin' => true];
    }
    return $out;
}


/** Ключи линейных иконок разделов (см. ppkIcon) → подпись в выборе иконки. */
function packIconLabels(): array
{
    return [
        'folder' => 'Папка', 'layers' => 'Слои', 'image' => 'Картинка', 'type' => 'Шрифт',
        'brush' => 'Кисть', 'palette' => 'Палитра', 'sparkles' => 'Магия', 'video' => 'Видео',
        'camera' => 'Фото', 'box' => 'Набор', 'shapes' => 'Фигуры', 'ruler' => 'Линейка',
        'droplet' => 'Капля', 'zap' => 'Молния', 'star' => 'Звезда', 'tile' => 'Сетка',
    ];
}
function packIconKeys(): array { return array_keys(packIconLabels()); }

/** Приводит значение иконки (ключ ИЛИ старый эмодзи) к ключу; по умолчанию — «box». */
function packIconKey(string $v): string
{
    $v = trim($v);
    if (in_array($v, packIconKeys(), true)) return $v;
    $legacy = [
        '📁' => 'folder', '🖼' => 'image', '🔤' => 'type', '🎨' => 'palette', '🖌' => 'brush', '✨' => 'sparkles',
        '🎬' => 'video', '🎞' => 'video', '📦' => 'box', '🧩' => 'shapes', '📐' => 'ruler', '🧰' => 'box',
        '🌈' => 'droplet', '📷' => 'camera', '⚡' => 'zap', '⭐' => 'star', '🖥' => 'sparkles',
    ];
    return $legacy[$v] ?? 'box';
}

function cleanSectionTitle(string $t): string
{
    $t = trim(preg_replace('/\s+/u', ' ', strip_tags($t)));
    return mb_substr($t, 0, 40);
}

function cleanSectionIcon(string $i): string
{
    return packIconKey($i);
}

function createPackSection(PDO $pdo, string $title, string $icon): string
{
    $title = cleanSectionTitle($title);
    if ($title === '') return '';
    $slug = 'c' . substr(md5(uniqid('', true)), 0, 9);
    $max = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM pack_sections")->fetchColumn();
    $pdo->prepare("INSERT INTO pack_sections (slug, title, icon, sort_order, is_builtin) VALUES (?, ?, ?, ?, FALSE)")
        ->execute([$slug, $title, cleanSectionIcon($icon), $max + 10]);
    return $slug;
}

function updatePackSection(PDO $pdo, string $slug, string $title, string $icon): bool
{
    $title = cleanSectionTitle($title);
    if ($title === '') return false;
    $pdo->prepare("UPDATE pack_sections SET title = ?, icon = ? WHERE slug = ?")
        ->execute([$title, cleanSectionIcon($icon), $slug]);
    return true;
}

/** Удаляет только пользовательский раздел вместе с его материалами. */
function deletePackSection(PDO $pdo, string $slug): bool
{
    $stmt = $pdo->prepare("SELECT is_builtin FROM pack_sections WHERE slug = ?");
    $stmt->execute([$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !empty($row['is_builtin']) && $row['is_builtin'] !== 'f') return false;
    $pdo->prepare("DELETE FROM pack_resources WHERE type = ?")->execute([$slug]);
    $pdo->prepare("DELETE FROM pack_sections WHERE slug = ?")->execute([$slug]);
    return true;
}

/* ═════════ Редактирование материала ═════════ */

function getPackResource(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM pack_resources WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Обновляет только переданные поля (белый список колонок). */
function updatePackResource(PDO $pdo, int $id, array $fields): void
{
    $allowed = ['title', 'description', 'preview_image', 'telegram_url', 'file_url', 'file_id', 'file_name', 'video_url'];
    $set = []; $vals = [];
    foreach ($fields as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        $set[] = "$k = ?"; $vals[] = (string)$v;
    }
    if (!$set) return;
    $vals[] = $id;
    $pdo->prepare("UPDATE pack_resources SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
}

/**
 * Единый разбор «источника» материала из формы (добавление и правка):
 *   src_mode = file — загруженный файл (Google Drive → локально),
 *              link — готовая ссылка (Google Drive определяется автоматически),
 *              tg   — ссылка на пост в Telegram,
 *              keep — ничего не менять (только при правке).
 * Возвращает набор полей для БД; в $err — текст ошибки, если не получилось.
 * Для sd_video ссылка на файл хранится в video_url, для остальных — в file_url.
 */
function packResourceSourceFromPost(string $slug, array $post, string $localDir, ?string &$err = null, ?PDO $pdo = null): array
{
    $mode = (string)($post['src_mode'] ?? 'file');
    $urlCol = $slug === 'sd_video' ? 'video_url' : 'file_url';
    $title = trim((string)($post['title'] ?? ''));
    $err = null;

    if ($mode === 'keep') return [];

    if ($mode === 'tg') {
        $tg = trim((string)($post['telegram_url'] ?? ''));
        if ($tg === '') { $err = 'Вставь ссылку на пост в Telegram.'; return []; }
        return ['telegram_url' => $tg, $urlCol => '', 'file_id' => '', 'file_name' => ''];
    }

    if ($mode === 'link') {
        $link = trim((string)($post['resource_link'] ?? ''));
        if ($link === '') { $err = 'Вставь ссылку на файл.'; return []; }
        $out = [$urlCol => $link, 'telegram_url' => '', 'file_id' => '', 'file_name' => ''];
        $gdId = extractGDriveFileId($link);
        if ($gdId) { $out['file_id'] = $gdId; $out['file_name'] = $title; }
        return $out;
    }

    // file: 1) Cloudinary (постоянное хранилище) → 2) Google Drive.
    // На локальный диск НЕ сохраняем — uploads/ стирается при деплое (файл «пропадал» после пуша).
    if (empty($_FILES['resource_file']['name'])) { $err = 'Прикрепи файл или выбери «Ссылка».'; return []; }

    $cloudErr = null;
    $cl = uploadPackResourceFileCloudinary('resource_file', $pdo, $cloudErr);
    if ($cl) {
        return [$urlCol => $cl['url'], 'file_id' => '', 'file_name' => $cl['file_name'], 'telegram_url' => ''];
    }

    $gd = function_exists('uploadToGoogleDriveDetailed')
        ? uploadToGoogleDriveDetailed($_FILES['resource_file']['tmp_name'], basename((string)$_FILES['resource_file']['name']))
        : null;
    if ($gd) {
        return [$urlCol => $gd['url'], 'file_id' => $gd['id'], 'file_name' => $gd['name'], 'telegram_url' => ''];
    }
    $err = '❌ Файл не загрузился в Cloudinary' . ($cloudErr ? " ({$cloudErr})" : '')
         . '. Проверь ключи Cloudinary в «Ключи и API» (диагностика: /admin/storage_test.php) или вставь готовую ссылку. '
         . 'На сервер файл не сохраняется — там он пропадает при деплое.';
    return [];
}
