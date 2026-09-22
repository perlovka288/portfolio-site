<?php
/**
 * Система плашек ролей (Badges) — поддерживает вывод НЕСКОЛЬКИХ плашек
 * одновременно у одного пользователя (например ADMIN + PPK у главного админа).
 *
 * Роли не хранятся в отдельной таблице — они и так уже вычисляются на
 * каждой странице ($isAdmin, $isPackDesigner из includes/pack_role.php),
 * это просто единая функция отрисовки поверх уже готовых флагов, чтобы
 * везде (шапка, профиль, карточка в планере и т.д.) плашки выглядели
 * одинаково и не дублировался HTML/CSS по всем файлам.
 *
 * Подключение:
 *   require_once __DIR__ . '/includes/badges.php';
 *   echo renderRoleBadges(['ADMIN' => $isAdmin, 'PPK' => $isPackDesigner]);
 *
 * Стили — см. assets/kostlim-upgrade.css (.role-badge, .role-badge--admin,
 * .role-badge--ppk).
 */

const ROLE_BADGE_META = [
    'ADMIN' => ['label' => 'ADMIN', 'icon' => '⚡', 'class' => 'role-badge--admin'],
    'PPK'   => ['label' => 'PPK',   'icon' => '🎨', 'class' => 'role-badge--ppk'],
];

/**
 * @param array<string,bool> $flags например ['ADMIN' => true, 'PPK' => true]
 *                           Порядок ключей в массиве = порядок вывода плашек.
 *                           Плашки с false/пустым значением просто пропускаются.
 */
function renderRoleBadges(array $flags): string
{
    $out = '';
    foreach ($flags as $code => $active) {
        if (!$active) continue;
        $meta = ROLE_BADGE_META[$code] ?? ['label' => htmlspecialchars((string)$code), 'icon' => '', 'class' => 'role-badge--default'];
        $out .= '<span class="role-badge ' . $meta['class'] . '">'
              . ($meta['icon'] !== '' ? '<span class="role-badge-icon">' . $meta['icon'] . '</span>' : '')
              . htmlspecialchars($meta['label'])
              . '</span>';
    }
    return $out === '' ? '' : '<span class="role-badges">' . $out . '</span>';
}

/**
 * Ручная выдача / проверка роли PPK — не связана с TTL-проверкой участия
 * в Telegram-группе (ту делает checkPackMembership() в pack_role.php).
 * Используется, когда админ выдаёт доступ вручную по ID/нику, или человек
 * активировал одноразовый ключ — доступ не должен пропасть, если бота
 * временно не удалось опросить.
 */
function ensurePpkManualSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ppk_manual_grants (
            tg_id VARCHAR(64) PRIMARY KEY,
            granted_by VARCHAR(64) NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            granted_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ppk_activation_keys (
            id SERIAL PRIMARY KEY,
            code VARCHAR(64) UNIQUE NOT NULL,
            is_used BOOLEAN NOT NULL DEFAULT FALSE,
            used_by_tg_id VARCHAR(64) NOT NULL DEFAULT '',
            used_at TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");
    } catch (Throwable $e) {
        error_log('ensurePpkManualSchema error: ' . $e->getMessage());
    }
}

function hasManualPpkGrant(PDO $pdo, string $tgId): bool
{
    if ($tgId === '') return false;
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM ppk_manual_grants WHERE tg_id = ? LIMIT 1");
        $stmt->execute([$tgId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** Выдать PPK вручную из админ-панели по Telegram ID. */
function grantManualPpk(PDO $pdo, string $tgId, string $grantedByTgId, string $note = ''): void
{
    ensurePpkManualSchema($pdo);
    $pdo->prepare("
        INSERT INTO ppk_manual_grants (tg_id, granted_by, note) VALUES (?, ?, ?)
        ON CONFLICT (tg_id) DO UPDATE SET granted_by = EXCLUDED.granted_by, note = EXCLUDED.note
    ")->execute([$tgId, $grantedByTgId, $note]);
}

function revokeManualPpk(PDO $pdo, string $tgId): void
{
    $pdo->prepare("DELETE FROM ppk_manual_grants WHERE tg_id = ?")->execute([$tgId]);
}

/** Сгенерировать N одноразовых ключей и вернуть их список (для показа админу один раз). */
function generatePpkKeys(PDO $pdo, int $count = 1): array
{
    ensurePpkManualSchema($pdo);
    $codes = [];
    $stmt = $pdo->prepare("INSERT INTO ppk_activation_keys (code) VALUES (?)");
    for ($i = 0; $i < max(1, $count); $i++) {
        $code = 'PPK-' . strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(2)));
        try {
            $stmt->execute([$code]);
            $codes[] = $code;
        } catch (Throwable $e) { /* коллизия — крайне маловероятна, просто пропускаем */ }
    }
    return $codes;
}

/**
 * Погасить ключ и выдать PPK введённому пользователю.
 * @return array{ok:bool, error?:string}
 */
function redeemPpkKey(PDO $pdo, string $rawCode, string $tgId): array
{
    if ($tgId === '') return ['ok' => false, 'error' => 'Сначала войдите через Telegram.'];
    ensurePpkManualSchema($pdo);
    $code = strtoupper(trim($rawCode));
    if ($code === '') return ['ok' => false, 'error' => 'Введите ключ.'];

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT id, is_used FROM ppk_activation_keys WHERE code = ? FOR UPDATE");
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Ключ не найден.'];
        }
        if ($row['is_used']) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Этот ключ уже был использован.'];
        }
        $pdo->prepare("UPDATE ppk_activation_keys SET is_used = TRUE, used_by_tg_id = ?, used_at = NOW() WHERE id = ?")
            ->execute([$tgId, $row['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('redeemPpkKey error: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Ошибка сервера, попробуйте позже.'];
    }

    grantManualPpk($pdo, $tgId, 'key:' . $code, 'Активирован ключом');
    return ['ok' => true];
}
