<?php
declare(strict_types=1);

/**
 * Shared helpers for video token app.
 * Requires admin/db.php in caller file.
 */

/**
 * @return string[]
 */
function vt_role_list(string $raw): array
{
    $value = strtolower(trim($raw));
    if ($value === '') return [];
    $parts = preg_split('/[\s,;|]+/', $value) ?: [];
    $roles = [];
    foreach ($parts as $part) {
        $role = trim((string)$part);
        if ($role === '') continue;
        $roles[$role] = true;
    }
    return array_keys($roles);
}

function vt_role_has(string $raw, string $role): bool
{
    $needle = strtolower(trim($role));
    if ($needle === '') return false;
    return in_array($needle, vt_role_list($raw), true);
}

/**
 * @return array<string,mixed>
 */
function vt_get_input_data(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

/**
 * @return array{logged_in:bool,user_id:int|null,email:string|null,role:string|null}
 */
function vt_current_user(PDO $pdo): array
{
    if (!is_logged_in()) {
        return [
            'logged_in' => false,
            'user_id' => null,
            'email' => null,
            'role' => null,
        ];
    }
    $userId = current_user_id();
    if ($userId <= 0) {
        return [
            'logged_in' => false,
            'user_id' => null,
            'email' => null,
            'role' => null,
        ];
    }

    $stmt = $pdo->prepare('SELECT id, email, role, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['is_active'] !== 1) {
        return [
            'logged_in' => false,
            'user_id' => null,
            'email' => null,
            'role' => null,
        ];
    }
    $rawRole = (string)$row['role'];
    $mapped = 'user';
    if (vt_role_has($rawRole, 'admin')) {
        $mapped = 'admin';
    } elseif (vt_role_has($rawRole, 'editor')) {
        $mapped = 'trener';
    }
    return [
        'logged_in' => true,
        'user_id' => (int)$row['id'],
        'email' => (string)$row['email'],
        'role' => $mapped,
    ];
}

function vt_is_trainer_user(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) return false;
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$userId]);
    $role = (string)($stmt->fetchColumn() ?: '');
    return vt_role_has($role, 'editor') || vt_role_has($role, 'admin');
}

function vt_pick_default_trainer(PDO $pdo): ?int
{
    $stmt = $pdo->query(
        "SELECT id, role
         FROM users
         WHERE is_active = 1
         ORDER BY (last_login_at IS NULL) ASC, last_login_at DESC, id ASC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        if (!vt_role_has((string)($row['role'] ?? ''), 'editor')) continue;
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) return $id;
    }
    return null;
}

/**
 * @return array{remaining_upload_links:int,remaining_trainer_choices:int}
 */
function vt_get_user_balance(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return ['remaining_upload_links' => 0, 'remaining_trainer_choices' => 0];
    }
    $stmt = $pdo->prepare(
        'SELECT
            COALESCE(SUM(remaining_upload_links), 0) AS upload_left,
            COALESCE(SUM(remaining_trainer_choices), 0) AS trainer_left
         FROM user_token_entitlements
         WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'remaining_upload_links' => (int)($row['upload_left'] ?? 0),
        'remaining_trainer_choices' => (int)($row['trainer_left'] ?? 0),
    ];
}

/**
 * @return array{ok:bool,source_order_id:int|null,entitlement_id:int|null,error?:string}
 */
function vt_consume_upload_entitlement(PDO $pdo, int $userId, bool $consumeTrainerChoice): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'source_order_id' => null, 'entitlement_id' => null, 'error' => 'not_logged_in'];
    }

    $pdo->beginTransaction();
    try {
        $sql = 'SELECT id, source_order_id, remaining_upload_links, remaining_trainer_choices
                FROM user_token_entitlements
                WHERE user_id = ? AND remaining_upload_links > 0';
        if ($consumeTrainerChoice) {
            $sql .= ' AND remaining_trainer_choices > 0';
        }
        $sql .= ' ORDER BY id ASC LIMIT 1 FOR UPDATE';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            return ['ok' => false, 'source_order_id' => null, 'entitlement_id' => null, 'error' => 'insufficient_entitlements'];
        }

        $entId = (int)$row['id'];
        $updates = ['remaining_upload_links = remaining_upload_links - 1'];
        if ($consumeTrainerChoice) {
            $updates[] = 'remaining_trainer_choices = remaining_trainer_choices - 1';
        }
        $updates[] = 'updated_at = NOW()';
        $updateSql = 'UPDATE user_token_entitlements SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $up = $pdo->prepare($updateSql);
        $up->execute([$entId]);

        $pdo->commit();
        return [
            'ok' => true,
            'source_order_id' => isset($row['source_order_id']) ? (int)$row['source_order_id'] : null,
            'entitlement_id' => $entId,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'source_order_id' => null, 'entitlement_id' => null, 'error' => 'consume_failed'];
    }
}

/**
 * Grants entitlements for paid order only once.
 */
function vt_grant_order_entitlements(PDO $pdo, int $orderId): bool
{
    if ($orderId <= 0) return false;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT o.id, o.user_id, o.token_type_id, o.status, o.entitlements_granted_at,
                    t.max_upload_links, t.can_choose_trainer
             FROM token_orders o
             JOIN token_types t ON t.id = o.token_type_id
             WHERE o.id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $pdo->rollBack();
            return false;
        }
        if ((string)$order['status'] !== 'paid') {
            $pdo->rollBack();
            return false;
        }
        if (!empty($order['entitlements_granted_at'])) {
            $pdo->commit();
            return true;
        }

        $uploadCount = max(0, (int)$order['max_upload_links']);
        $trainerChoices = ((int)$order['can_choose_trainer'] === 1) ? $uploadCount : 0;
        if ($uploadCount <= 0) {
            $mark = $pdo->prepare('UPDATE token_orders SET entitlements_granted_at = NOW(), updated_at = NOW() WHERE id = ?');
            $mark->execute([$orderId]);
            $pdo->commit();
            return true;
        }

        $insert = $pdo->prepare(
            'INSERT INTO user_token_entitlements
                (user_id, token_type_id, source_order_id, total_upload_links, remaining_upload_links, total_trainer_choices, remaining_trainer_choices, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $insert->execute([
            (int)$order['user_id'],
            (int)$order['token_type_id'],
            (int)$order['id'],
            $uploadCount,
            $uploadCount,
            $trainerChoices,
            $trainerChoices,
        ]);

        $mark = $pdo->prepare('UPDATE token_orders SET entitlements_granted_at = NOW(), updated_at = NOW() WHERE id = ?');
        $mark->execute([$orderId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return false;
    }
}
