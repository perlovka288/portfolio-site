<?php
/**
 * CRUD-бэкенд личного планера клиентов. Каждая запись привязана к
 * owner_tg_id — пользователь видит и может менять только свои строки.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/ppk_access.php';

function jexit(array $data): void { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

$access = resolvePpkAccess($pdo);
if (!$access['isPackDesigner']) jexit(['ok' => false, 'error' => 'Доступ только для PPK/ADMIN']);
$tgId = $access['tgId'];

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS client_planner (
        id SERIAL PRIMARY KEY,
        owner_tg_id VARCHAR(64) NOT NULL,
        client_name VARCHAR(150) NOT NULL DEFAULT '',
        contact VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(40) NOT NULL DEFAULT 'in_progress',
        deadline DATE,
        amount NUMERIC(10,2) NOT NULL DEFAULT 0,
        notes TEXT NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
    // FIX/Блок 3 ТЗ: раньше статус был жёстко зашит в 3 значения и не было
    // ни платёжных/рабочих полей, ни своих статусов с цветом — добавляем
    // недостающие колонки существующим установкам (безопасно — IF NOT EXISTS).
    $pdo->exec("ALTER TABLE client_planner ALTER COLUMN status TYPE VARCHAR(40)");
    $pdo->exec("ALTER TABLE client_planner ADD COLUMN IF NOT EXISTS payment_method VARCHAR(20) NOT NULL DEFAULT ''");
    $pdo->exec("ALTER TABLE client_planner ADD COLUMN IF NOT EXISTS prepayment BOOLEAN NOT NULL DEFAULT FALSE");
    $pdo->exec("ALTER TABLE client_planner ADD COLUMN IF NOT EXISTS tz_link TEXT NOT NULL DEFAULT ''");
    $pdo->exec("ALTER TABLE client_planner ADD COLUMN IF NOT EXISTS paid_at TIMESTAMP");

    // Кастомные статусы (Блок 3.2 ТЗ) — свои, с выбором цвета плашки,
    // отдельно у каждого дизайнера (owner_tg_id).
    $pdo->exec("CREATE TABLE IF NOT EXISTS planner_custom_statuses (
        id SERIAL PRIMARY KEY,
        owner_tg_id VARCHAR(64) NOT NULL,
        name VARCHAR(60) NOT NULL,
        color VARCHAR(7) NOT NULL DEFAULT '#FF7A00',
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
} catch (Throwable $e) { error_log('client_planner schema error: ' . $e->getMessage()); }

/** Встроенные статусы (всегда доступны, id — фиксированная строка). */
function plannerBuiltinStatuses(): array {
    return [
        ['id' => 'in_progress', 'name' => 'В работе',  'color' => '#FF7A00', 'builtin' => true],
        ['id' => 'revision',    'name' => 'Правки',    'color' => '#3B82F6', 'builtin' => true],
        ['id' => 'paid',        'name' => 'Оплачено',  'color' => '#22C55E', 'builtin' => true],
    ];
}

/** Встроенные + кастомные статусы владельца, одним списком для селекта. */
function plannerAllStatuses(PDO $pdo, string $tgId): array {
    $stmt = $pdo->prepare("SELECT id, name, color FROM planner_custom_statuses WHERE owner_tg_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$tgId]);
    $custom = array_map(function ($r) {
        return ['id' => 'custom:' . $r['id'], 'name' => $r['name'], 'color' => $r['color'], 'builtin' => false];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    return array_merge(plannerBuiltinStatuses(), $custom);
}

function plannerIsValidStatus(PDO $pdo, string $tgId, string $status): bool {
    foreach (plannerAllStatuses($pdo, $tgId) as $s) { if ($s['id'] === $status) return true; }
    return false;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

switch ($action) {

case 'list_rows': {
    $stmt = $pdo->prepare("SELECT id, client_name, contact, status, deadline, amount, notes, payment_method, prepayment, tz_link, paid_at FROM client_planner WHERE owner_tg_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$tgId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Блок 3.3 ТЗ: калькулятор сверху — "Заработано за месяц" (сумма
    // amount у строк, отмеченных оплаченными в текущем календарном месяце)
    // и "В работе" (кол-во активных заказов = всё, что не 'paid').
    $earnedMonth = 0.0; $inProgressCount = 0;
    $curMonth = date('Y-m');
    foreach ($rows as $row) {
        if ($row['status'] === 'paid') {
            if ($row['paid_at'] && substr($row['paid_at'], 0, 7) === $curMonth) $earnedMonth += (float)$row['amount'];
        } else {
            $inProgressCount++;
        }
    }

    jexit(['ok' => true, 'rows' => $rows, 'statuses' => plannerAllStatuses($pdo, $tgId),
        'calc' => ['earned_month' => round($earnedMonth, 2), 'in_progress_count' => $inProgressCount]]);
}

case 'save_row': {
    $id       = $input['id'] ?? null;
    $client   = trim((string)($input['client_name'] ?? ''));
    $contact  = trim((string)($input['contact'] ?? ''));
    $status   = trim((string)($input['status'] ?? 'in_progress'));
    if (!plannerIsValidStatus($pdo, $tgId, $status)) $status = 'in_progress';
    $deadline = trim((string)($input['deadline'] ?? '')) ?: null;
    $amount   = (float)($input['amount'] ?? 0);
    $notes    = trim((string)($input['notes'] ?? ''));
    $payment  = in_array($input['payment_method'] ?? '', ['Cryptobot', 'Карта', 'PayPal', ''], true) ? $input['payment_method'] : '';
    $prepay   = !empty($input['prepayment']);
    $tzLink   = trim((string)($input['tz_link'] ?? ''));

    if ($id) {
        // Проверка владения строкой перед апдейтом
        $chk = $pdo->prepare("SELECT status FROM client_planner WHERE id = ? AND owner_tg_id = ?");
        $chk->execute([$id, $tgId]);
        $prevStatus = $chk->fetchColumn();
        if ($prevStatus === false) jexit(['ok' => false, 'error' => 'Запись не найдена']);

        // paid_at проставляем только в момент первого перехода в 'paid',
        // чтобы "Заработано за месяц" считался по месяцу реальной оплаты,
        // а не переезжал при каждом последующем редактировании строки.
        if ($status === 'paid' && $prevStatus !== 'paid') {
            $pdo->prepare("UPDATE client_planner SET client_name=?, contact=?, status=?, deadline=?, amount=?, notes=?, payment_method=?, prepayment=?, tz_link=?, paid_at=NOW(), updated_at=NOW() WHERE id=? AND owner_tg_id=?")
                ->execute([$client, $contact, $status, $deadline, $amount, $notes, $payment, $prepay, $tzLink, $id, $tgId]);
        } else {
            $pdo->prepare("UPDATE client_planner SET client_name=?, contact=?, status=?, deadline=?, amount=?, notes=?, payment_method=?, prepayment=?, tz_link=?, updated_at=NOW() WHERE id=? AND owner_tg_id=?")
                ->execute([$client, $contact, $status, $deadline, $amount, $notes, $payment, $prepay, $tzLink, $id, $tgId]);
        }
        jexit(['ok' => true, 'id' => (int)$id]);
    }

    $paidAtSql = $status === 'paid' ? 'NOW()' : 'NULL';
    $stmt = $pdo->prepare("INSERT INTO client_planner (owner_tg_id, client_name, contact, status, deadline, amount, notes, payment_method, prepayment, tz_link, paid_at) VALUES (?,?,?,?,?,?,?,?,?,?, $paidAtSql) RETURNING id");
    $stmt->execute([$tgId, $client, $contact, $status, $deadline, $amount, $notes, $payment, $prepay, $tzLink]);
    jexit(['ok' => true, 'id' => (int)$stmt->fetchColumn()]);
}

case 'delete_row': {
    $id = $input['id'] ?? 0;
    $pdo->prepare("DELETE FROM client_planner WHERE id = ? AND owner_tg_id = ?")->execute([$id, $tgId]);
    jexit(['ok' => true]);
}

case 'create_status': {
    $name  = trim((string)($input['name'] ?? ''));
    $color = trim((string)($input['color'] ?? '#FF7A00'));
    if ($name === '') jexit(['ok' => false, 'error' => 'Укажи название статуса']);
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#FF7A00';
    $stmt = $pdo->prepare("INSERT INTO planner_custom_statuses (owner_tg_id, name, color) VALUES (?, ?, ?) RETURNING id");
    $stmt->execute([$tgId, $name, $color]);
    jexit(['ok' => true, 'id' => 'custom:' . (int)$stmt->fetchColumn(), 'statuses' => plannerAllStatuses($pdo, $tgId)]);
}

case 'delete_status': {
    $sid = (int)($input['id'] ?? 0);
    // Строки, использовавшие этот статус, откатываем на "В работе", чтобы
    // не остались висеть со статусом-сиротой без имени/цвета.
    $pdo->prepare("UPDATE client_planner SET status = 'in_progress' WHERE owner_tg_id = ? AND status = ?")
        ->execute([$tgId, 'custom:' . $sid]);
    $pdo->prepare("DELETE FROM planner_custom_statuses WHERE id = ? AND owner_tg_id = ?")->execute([$sid, $tgId]);
    jexit(['ok' => true, 'statuses' => plannerAllStatuses($pdo, $tgId)]);
}

default:
    jexit(['ok' => false, 'error' => 'Неизвестное действие']);
}
