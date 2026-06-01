<?php
session_start();
require_once 'config/db.php';

$bot_token    = "8919210171:AAHOgiJUeqtrGA3Vh8V6PCuxEeT261i7Xeg";
$my_chat_id   = "1710365896";
$bot_link     = 'https://t.me/kostlimdznbot';
$support_tg   = 'https://t.me/Perlo_ovka';

// ──────────────────────────────────────────────
// imgbb: 3 ключа, fallback при ошибке / лимите
// ──────────────────────────────────────────────
$imgbb_keys = [
    'c3e6a55335c71a052c1a59b6a2d6d150',
    '58ff4596fd55028a81cbf8c4e38388e1',
    '6981ba08e7b2a8743aab2c8ea008f675',
];

/**
 * Загружает файл на imgbb, перебирая ключи при неудаче.
 * Возвращает URL или пустую строку.
 */
function uploadToImgbb(string $tmpPath, array $keys): string {
    $data = base64_encode(file_get_contents($tmpPath));
    foreach ($keys as $key) {
        $ch = curl_init('https://api.imgbb.com/1/upload');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['key' => $key, 'image' => $data],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $httpCode !== 200) continue;

        $json = json_decode($resp, true);
        if (!empty($json['data']['url'])) {
            return $json['data']['url'];
        }
        // Если лимит (429) — пробуем следующий ключ
    }
    return '';
}

$selected_service = $_GET['service'] ?? '';
$services = $pdo->query("SELECT title, category_key, price_uan, price_rub FROM prices ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$success_msg = '';
$error_msg   = '';

$rules_accepted = (($_GET['accepted'] ?? '') === '1') || (($_POST['rules_accepted'] ?? '') === '1');

$accept_params = [];
if ($selected_service !== '') $accept_params['service'] = $selected_service;
$accept_params['accepted'] = '1';
$accept_url = 'order.php?' . http_build_query($accept_params);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$rules_accepted) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username    = trim($_POST['username']    ?? '');
    $telegram    = trim($_POST['telegram']    ?? '');
    $service_key = trim($_POST['service']     ?? '');
    $details     = trim($_POST['details']     ?? '');

    // ── Загрузка нескольких файлов каждого типа ──
    function collectUploadedUrls(string $field, array $imgbbKeys): array {
        $urls = [];
        if (empty($_FILES[$field]['name'][0])) return $urls;

        $files = $_FILES[$field];
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            $url = uploadToImgbb($files['tmp_name'][$i], $imgbbKeys);
            if ($url !== '') $urls[] = $url;
        }
        return $urls;
    }

    $screenshot_urls = collectUploadedUrls('screenshot',    $imgbb_keys);
    $example_urls    = collectUploadedUrls('example_photo', $imgbb_keys);

    $pay_screenshot  = implode(',', $screenshot_urls);
    $example_img     = implode(',', $example_urls);

    try {
        $stmt = $pdo->prepare("INSERT INTO orders
            (username, telegram, service_key, details, screenshot, example_photo, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->execute([$username, $telegram, $service_key, $details, $pay_screenshot, $example_img]);
        $order_id = $pdo->lastInsertId();

        $success_msg = "🚀 Заказ #{$order_id} отправлен! Отслеживай статус в боте: /status_{$order_id}";

        if (!empty($my_chat_id)) {
            $price_stmt = $pdo->prepare("SELECT title, price_rub, price_uan FROM prices WHERE category_key = ? LIMIT 1");
            $price_stmt->execute([$service_key]);
            $price_info    = $price_stmt->fetch(PDO::FETCH_ASSOC);
            $service_title = $price_info['title'] ?? $service_key;
            $p_rub         = $price_info['price_rub'] ?? 0;
            $p_uan         = $price_info['price_uan'] ?? 0;

            $msg_text  = "⚡️ **НОВЫЙ ЗАКАЗ #{$order_id}** ⚡️\n\n";
            $msg_text .= "👤 **Клиент:** " . htmlspecialchars($username) . "\n";
            $msg_text .= "📞 **Контакт:** " . htmlspecialchars($telegram) . "\n";
            $msg_text .= "🎨 **Услуга:** " . htmlspecialchars($service_title) . "\n";
            $msg_text .= "💰 **Цена:** {$p_rub}₽ / {$p_uan}₴\n";
            $msg_text .= "📝 **ТЗ:** " . htmlspecialchars($details) . "\n";

            if (!empty($screenshot_urls)) $msg_text .= "🖼 **Оплата (imgbb):** " . implode(', ', $screenshot_urls) . "\n";
            if (!empty($example_urls))   $msg_text .= "🎭 **Референсы (imgbb):** " . implode(', ', $example_urls) . "\n";

            $clean_tg = str_replace(['@', 'https://t.me/'], '', $telegram);
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '⏳ Взять в работу', 'callback_data' => "adm_work_{$order_id}"],
                        ['text' => '❌ Отклонить',       'callback_data' => "adm_dec_{$order_id}"],
                    ],
                    [['text' => '💬 Написать клиенту', 'url' => "https://t.me/{$clean_tg}"]],
                ],
            ];

            // Собираем все imgbb-фото в одну медиагруппу (макс 10)
            $all_urls = array_merge($screenshot_urls, $example_urls);
            $media    = [];
            foreach (array_slice($all_urls, 0, 10) as $idx => $url) {
                $item = ['type' => 'photo', 'media' => $url];
                if ($idx === 0) { $item['caption'] = $msg_text; $item['parse_mode'] = 'Markdown'; }
                $media[] = $item;
            }

            if (!empty($media)) {
                $ch = curl_init("https://api.telegram.org/bot{$bot_token}/sendMediaGroup");
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => http_build_query(['chat_id' => $my_chat_id, 'media' => json_encode($media)]),
                    CURLOPT_RETURNTRANSFER => true,
                ]);
                curl_exec($ch); curl_close($ch);

                // Кнопки управления отдельным сообщением
                $ch = curl_init("https://api.telegram.org/bot{$bot_token}/sendMessage");
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => http_build_query([
                        'chat_id'      => $my_chat_id,
                        'text'         => "🎛 Управление заказом #{$order_id}:",
                        'parse_mode'   => 'Markdown',
                        'reply_markup' => json_encode($keyboard),
                    ]),
                    CURLOPT_RETURNTRANSFER => true,
                ]);
                curl_exec($ch); curl_close($ch);
            } else {
                $ch = curl_init("https://api.telegram.org/bot{$bot_token}/sendMessage");
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => http_build_query([
                        'chat_id'      => $my_chat_id,
                        'text'         => $msg_text,
                        'parse_mode'   => 'Markdown',
                        'reply_markup' => json_encode($keyboard),
                    ]),
                    CURLOPT_RETURNTRANSFER => true,
                ]);
                curl_exec($ch); curl_close($ch);
            }
        }
    } catch (PDOException $e) {
        $error_msg = "❌ Ошибка БД: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Заполнить ТЗ | Kostlim Design</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@400;700;900&family=Mulish:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:        #0a0a0f;
    --surface:   #111118;
    --border:    rgba(255,255,255,.07);
    --accent:    #c0544a;
    --accent2:   #e06b60;
    --text:      #e8e8f0;
    --muted:     #6b6b7d;
    --success:   #2dd4a0;
    --error:     #ef4444;
    --radius:    14px;
    --input-bg:  #0e0e16;
  }

  html, body { min-height: 100vh; background: var(--bg); color: var(--text); font-family: 'Mulish', sans-serif; }

  body {
    background-image:
      radial-gradient(ellipse 80% 50% at 50% -10%, rgba(192,84,74,.18) 0%, transparent 70%),
      radial-gradient(ellipse 40% 30% at 100% 60%, rgba(192,84,74,.08) 0%, transparent 60%);
  }

  /* ── Header ── */
  .kd-header {
    display: flex; align-items: center; justify-content: center;
    padding: 28px 24px 0;
    gap: 12px;
  }
  .kd-back {
    position: absolute; left: 50%; transform: translateX(-50%) translateY(-32px);
    color: var(--accent2); text-decoration: none; font-size: 12px; letter-spacing: .5px;
    display: flex; align-items: center; gap: 5px;
    opacity: .85; transition: opacity .2s;
  }
  .kd-back:hover { opacity: 1; }

  /* ── Card ── */
  .kd-card {
    position: relative;
    max-width: 580px;
    margin: 56px auto 60px;
    padding: 38px 38px 44px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 22px;
    box-shadow: 0 32px 80px rgba(0,0,0,.55), inset 0 1px rgba(255,255,255,.04);
  }

  .kd-title {
    font-family: 'Unbounded', sans-serif;
    font-size: 20px; font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    text-align: center;
    margin-bottom: 32px;
    display: flex; align-items: center; justify-content: center; gap: 10px;
  }
  .kd-title-icon { font-size: 22px; }

  /* ── Fields ── */
  .kd-field { margin-bottom: 18px; }
  .kd-label {
    display: block;
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1px;
    color: var(--muted); margin-bottom: 7px;
  }
  .kd-input, .kd-select, .kd-textarea {
    width: 100%;
    background: var(--input-bg);
    border: 1px solid rgba(255,255,255,.09);
    color: var(--text);
    border-radius: var(--radius);
    padding: 13px 16px;
    font-family: 'Mulish', sans-serif;
    font-size: 14px;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
    -webkit-appearance: none;
  }
  .kd-input::placeholder, .kd-textarea::placeholder { color: var(--muted); }
  .kd-input:focus, .kd-select:focus, .kd-textarea:focus {
    border-color: var(--accent); box-shadow: 0 0 0 3px rgba(192,84,74,.15);
  }
  .kd-select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b6b7d' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 40px;
    cursor: pointer;
  }
  .kd-textarea { height: 112px; resize: none; line-height: 1.6; }

  /* ── Drop-zone upload ── */
  .kd-upload-zone {
    position: relative;
    border: 1.5px dashed rgba(255,255,255,.12);
    border-radius: var(--radius);
    padding: 20px 18px;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    background: var(--input-bg);
  }
  .kd-upload-zone:hover,
  .kd-upload-zone.drag-over {
    border-color: var(--accent);
    background: rgba(192,84,74,.06);
  }
  .kd-upload-zone input[type="file"] {
    position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer;
  }
  .kd-upload-label {
    display: flex; align-items: center; gap: 12px; pointer-events: none;
  }
  .kd-upload-icon {
    width: 40px; height: 40px; border-radius: 10px;
    background: rgba(192,84,74,.18);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
  }
  .kd-upload-text { flex: 1; }
  .kd-upload-title { font-weight: 700; font-size: 13px; color: var(--text); }
  .kd-upload-hint  { font-size: 11px; color: var(--muted); margin-top: 2px; }

  /* ── Превью загруженных ── */
  .kd-previews {
    display: flex; flex-wrap: wrap; gap: 8px;
    margin-top: 10px; min-height: 0;
  }
  .kd-preview-item {
    position: relative; width: 72px; height: 72px;
    border-radius: 10px; overflow: hidden;
    border: 1px solid rgba(255,255,255,.1);
    background: #0e0e16;
  }
  .kd-preview-item img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .kd-preview-remove {
    position: absolute; top: 3px; right: 3px;
    width: 18px; height: 18px; border-radius: 50%;
    background: rgba(0,0,0,.75); border: none; cursor: pointer;
    color: #fff; font-size: 10px; display: flex;
    align-items: center; justify-content: center;
    line-height: 1;
  }
  .kd-preview-count {
    display: inline-block; font-size: 11px; color: var(--accent2);
    font-weight: 700; margin-top: 6px;
  }

  /* ── Submit ── */
  .kd-btn {
    width: 100%; padding: 16px;
    background: linear-gradient(170deg, #d06459, #a84142);
    border: none; border-radius: var(--radius);
    color: #fff; font-family: 'Unbounded', sans-serif;
    font-size: 13px; font-weight: 900;
    letter-spacing: 1.2px; text-transform: uppercase;
    cursor: pointer; margin-top: 10px;
    box-shadow: 0 8px 28px rgba(192,84,74,.35), inset 0 1px rgba(255,255,255,.15);
    transition: transform .15s, box-shadow .15s, opacity .15s;
  }
  .kd-btn:hover  { transform: translateY(-1px); box-shadow: 0 12px 36px rgba(192,84,74,.45); }
  .kd-btn:active { transform: translateY(0);    opacity: .9; }
  .kd-btn:disabled { opacity: .55; cursor: not-allowed; transform: none; }

  /* ── Alerts ── */
  .kd-alert {
    padding: 14px 18px; border-radius: 10px;
    font-size: 13px; font-weight: 600; line-height: 1.5;
    margin-bottom: 22px; text-align: center;
  }
  .kd-alert-success { background: rgba(45,212,160,.1); border: 1px solid var(--success); color: var(--success); }
  .kd-alert-error   { background: rgba(239,68,68,.1);  border: 1px solid var(--error);   color: var(--error); }

  /* ── Rules card ── */
  .kd-rules-list {
    list-style: none; padding: 0;
    display: grid; gap: 12px;
    margin-bottom: 28px;
  }
  .kd-rules-list li {
    display: flex; align-items: flex-start; gap: 10px;
    font-size: 14px; line-height: 1.55; color: #d0d0dc;
  }
  .kd-rules-list li::before {
    content: '◆'; color: var(--accent); font-size: 9px; flex-shrink: 0; margin-top: 4px;
  }
  .kd-rules-list a { color: var(--accent2); text-decoration: none; }
  .kd-rules-list a:hover { text-decoration: underline; }
  .kd-rules-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .kd-btn-outline {
    width: 100%; padding: 14px;
    background: transparent;
    border: 1px solid rgba(255,255,255,.12); border-radius: var(--radius);
    color: #fff; font-family: 'Unbounded', sans-serif;
    font-size: 11px; font-weight: 700;
    letter-spacing: .8px; text-transform: uppercase;
    cursor: pointer; text-decoration: none; display: flex;
    align-items: center; justify-content: center;
    transition: background .2s, border-color .2s;
  }
  .kd-btn-outline:hover { background: rgba(255,255,255,.05); border-color: rgba(255,255,255,.2); }

  /* ── Upload progress bar ── */
  .kd-progress { display: none; margin-top: 12px; }
  .kd-progress.active { display: block; }
  .kd-progress-bar {
    height: 4px; border-radius: 4px;
    background: rgba(255,255,255,.08);
    overflow: hidden;
  }
  .kd-progress-fill {
    height: 100%; border-radius: 4px;
    background: linear-gradient(90deg, #a84142, #d06459);
    width: 0%;
    transition: width .3s ease;
    animation: kd-shimmer 1.5s infinite;
  }
  @keyframes kd-shimmer {
    0%   { opacity: 1; }
    50%  { opacity: .7; }
    100% { opacity: 1; }
  }
  .kd-progress-text { font-size: 11px; color: var(--muted); margin-top: 5px; text-align: center; }

  /* ── Responsive ── */
  @media (max-width: 600px) {
    .kd-card { margin: 20px 14px; padding: 26px 22px 32px; }
    .kd-title { font-size: 16px; }
    .kd-rules-btns { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>
<div style="position:relative; padding-top:28px;">
  <a href="index.php" class="kd-back" style="position:relative; left:auto; transform:none; display:flex; width:max-content; margin: 0 auto 0;">
    ← На главную к портфолио
  </a>
</div>

<div class="kd-card">

  <?php if (!empty($success_msg)): ?>
    <div class="kd-alert kd-alert-success"><?= htmlspecialchars($success_msg) ?></div>
  <?php endif; ?>
  <?php if (!empty($error_msg)): ?>
    <div class="kd-alert kd-alert-error"><?= htmlspecialchars($error_msg) ?></div>
  <?php endif; ?>

  <?php if (!$rules_accepted && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>

    <!-- ПРАВИЛА -->
    <div class="kd-title"><span class="kd-title-icon">📜</span>Правила заказа</div>
    <p style="text-align:center; color:var(--muted); font-size:13px; margin-bottom:26px; line-height:1.6;">
      Перед заполнением ТЗ подтвердите, что вы ознакомились с условиями работы.
    </p>
    <ul class="kd-rules-list">
      <li>Заказ выполняется в течение <strong>5 дней</strong>. При предоплате 50% — сегодня или следующий день, вне очереди.</li>
      <li>Деньги возврату не подлежат.</li>
      <li>Отслеживать статус заказа можно в боте:
        <a href="<?= htmlspecialchars($bot_link) ?>" target="_blank">@kostlimdznbot</a>
      </li>
      <li>По личным вопросам:
        <a href="<?= htmlspecialchars($support_tg) ?>" target="_blank">@Perlo_ovka</a>
      </li>
    </ul>
    <div class="kd-rules-btns">
      <a href="<?= htmlspecialchars($accept_url) ?>" class="kd-btn" style="text-decoration:none; display:flex; align-items:center; justify-content:center;">
        ✓ Согласен
      </a>
      <a href="index.php" class="kd-btn-outline">Назад</a>
    </div>

  <?php else: ?>

    <!-- ФОРМА -->
    <div class="kd-title"><span class="kd-title-icon">📋</span>Заполнить ТЗ</div>

    <form id="orderForm" action="" method="POST" enctype="multipart/form-data">
      <input type="hidden" name="rules_accepted" value="1">

      <div class="kd-field">
        <label class="kd-label">Ваше имя / никнейм</label>
        <input class="kd-input" type="text" name="username" required placeholder="Например: Влад">
      </div>

      <div class="kd-field">
        <label class="kd-label">Telegram @username</label>
        <input class="kd-input" type="text" name="telegram" required placeholder="@username">
      </div>

      <div class="kd-field">
        <label class="kd-label">Что вас интересует?</label>
        <select class="kd-select" name="service">
          <?php foreach ($services as $s): ?>
            <option value="<?= htmlspecialchars($s['category_key']) ?>"
              <?= ($selected_service === $s['category_key']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($s['title']) ?>
              (<?= $s['price_uan'] ?>₴ / <?= $s['price_rub'] ?>₽)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="kd-field">
        <label class="kd-label">Детали заказа (ТЗ)</label>
        <textarea class="kd-textarea" name="details" required placeholder="Опиши цвета, текст, стиль, референсы..."></textarea>
      </div>

      <!-- Скриншот оплаты (множественный) -->
      <div class="kd-field">
        <label class="kd-label">Скриншот оплаты</label>
        <div class="kd-upload-zone" id="zone-pay" onclick="document.getElementById('file-pay').click()">
          <input type="file" name="screenshot[]" id="file-pay" accept="image/*" multiple>
          <div class="kd-upload-label">
            <div class="kd-upload-icon">💳</div>
            <div class="kd-upload-text">
              <div class="kd-upload-title">Нажми или перетащи файлы</div>
              <div class="kd-upload-hint">PNG, JPG, WEBP · можно несколько</div>
            </div>
          </div>
        </div>
        <div class="kd-previews" id="previews-pay"></div>
        <span class="kd-preview-count" id="count-pay"></span>
      </div>

      <!-- Референс (множественный) -->
      <div class="kd-field">
        <label class="kd-label">Референс / пример</label>
        <div class="kd-upload-zone" id="zone-ref" onclick="document.getElementById('file-ref').click()">
          <input type="file" name="example_photo[]" id="file-ref" accept="image/*" multiple>
          <div class="kd-upload-label">
            <div class="kd-upload-icon">🎨</div>
            <div class="kd-upload-text">
              <div class="kd-upload-title">Нажми или перетащи файлы</div>
              <div class="kd-upload-hint">PNG, JPG, WEBP · можно несколько</div>
            </div>
          </div>
        </div>
        <div class="kd-previews" id="previews-ref"></div>
        <span class="kd-preview-count" id="count-ref"></span>
      </div>

      <!-- Прогресс -->
      <div class="kd-progress" id="uploadProgress">
        <div class="kd-progress-bar"><div class="kd-progress-fill" id="progressFill"></div></div>
        <div class="kd-progress-text" id="progressText">Загружаем файлы...</div>
      </div>

      <button class="kd-btn" type="submit" id="submitBtn">
        Отправить заказ Kostlim'у
      </button>
    </form>

  <?php endif; ?>
</div>

<script>
// ── Drag & Drop + Preview ──
function initUploadZone(zoneId, inputId, previewId, countId) {
  const zone     = document.getElementById(zoneId);
  const input    = document.getElementById(inputId);
  const previews = document.getElementById(previewId);
  const countEl  = document.getElementById(countId);
  let   dt       = new DataTransfer();

  function render() {
    previews.innerHTML = '';
    Array.from(dt.files).forEach((file, i) => {
      const reader = new FileReader();
      reader.onload = e => {
        const wrap = document.createElement('div');
        wrap.className = 'kd-preview-item';
        wrap.innerHTML = `<img src="${e.target.result}" alt="">
          <button class="kd-preview-remove" type="button" data-idx="${i}">✕</button>`;
        wrap.querySelector('button').addEventListener('click', ev => {
          ev.stopPropagation();
          const newDt = new DataTransfer();
          Array.from(dt.files).forEach((f, fi) => { if (fi !== i) newDt.items.add(f); });
          dt = newDt;
          input.files = dt.files;
          render();
        });
        previews.appendChild(wrap);
      };
      reader.readAsDataURL(file);
    });
    countEl.textContent = dt.files.length > 0 ? `Выбрано: ${dt.files.length} фото` : '';
  }

  input.addEventListener('change', () => {
    Array.from(input.files).forEach(f => dt.items.add(f));
    input.files = dt.files;
    render();
  });

  zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
  zone.addEventListener('drop', e => {
    e.preventDefault(); zone.classList.remove('drag-over');
    Array.from(e.dataTransfer.files).forEach(f => dt.items.add(f));
    input.files = dt.files;
    render();
  });
}

initUploadZone('zone-pay', 'file-pay', 'previews-pay', 'count-pay');
initUploadZone('zone-ref', 'file-ref', 'previews-ref', 'count-ref');

// ── Progress animation on submit ──
document.getElementById('orderForm')?.addEventListener('submit', function() {
  const btn      = document.getElementById('submitBtn');
  const progress = document.getElementById('uploadProgress');
  const fill     = document.getElementById('progressFill');
  const text     = document.getElementById('progressText');
  btn.disabled   = true;
  btn.textContent = 'Отправляем...';
  progress.classList.add('active');

  let w = 0;
  const iv = setInterval(() => {
    w = Math.min(w + Math.random() * 8, 90);
    fill.style.width = w + '%';
    if (w > 60) text.textContent = 'Загружаем на imgbb...';
    if (w > 85) text.textContent = 'Почти готово...';
  }, 200);

  // Очищаем интервал когда страница уходит
  window.addEventListener('beforeunload', () => clearInterval(iv));
});
</script>
</body>
</html>