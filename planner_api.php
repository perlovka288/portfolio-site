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
        status VARCHAR(20) NOT NULL DEFAULT 'in_progress',
        deadline DATE,
        amount NUMERIC(10,2) NOT NULL DEFAULT 0,
        notes TEXT NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
} catch (Throwable $e) { error_log('client_planner schema error: ' . $e->getMessage()); }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

switch ($action) {

case 'list_rows': {
    $stmt = $pdo->prepare("SELECT id, client_name, contact, status, deadline, amount, notes FROM client_planner WHERE owner_tg_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$tgId]);
    jexit(['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'save_row': {
    $id       = $input['id'] ?? null;
    $client   = trim((string)($input['client_name'] ?? ''));
    $contact  = trim((string)($input['contact'] ?? ''));
    $status   = in_array($input['status'] ?? '', ['in_progress','revision','paid'], true) ? $input['status'] : 'in_progress';
    $deadline = trim((string)($input['deadline'] ?? '')) ?: null;
    $amount   = (float)($input['amount'] ?? 0);
    $notes    = trim((string)($input['notes'] ?? ''));

    if ($id) {
        // Проверка владения строкой перед апдейтом
        $chk = $pdo->prepare("SELECT 1 FROM client_planner WHERE id = ? AND owner_tg_id = ?");
        $chk->execute([$id, $tgId]);
        if (!$chk->fetchColumn()) jexit(['ok' => false, 'error' => 'Запись не найдена']);

        $pdo->prepare("UPDATE client_planner SET client_name=?, contact=?, status=?, deadline=?, amount=?, notes=?, updated_at=NOW() WHERE id=? AND owner_tg_id=?")
            ->execute([$client, $contact, $status, $deadline, $amount, $notes, $id, $tgId]);
        jexit(['ok' => true, 'id' => (int)$id]);
    }

    $stmt = $pdo->prepare("INSERT INTO client_planner (owner_tg_id, client_name, contact, status, deadline, amount, notes) VALUES (?,?,?,?,?,?,?) RETURNING id");
    $stmt->execute([$tgId, $client, $contact, $status, $deadline, $amount, $notes]);
    jexit(['ok' => true, 'id' => (int)$stmt->fetchColumn()]);
}

case 'delete_row': {
    $id = $input['id'] ?? 0;
    $pdo->prepare("DELETE FROM client_planner WHERE id = ? AND owner_tg_id = ?")->execute([$id, $tgId]);
    jexit(['ok' => true]);
}

default:
    jexit(['ok' => false, 'error' => 'Неизвестное действие']);
}
