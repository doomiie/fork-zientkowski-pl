<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/access_guard.php';

/**
 * @param array<string,mixed> $payload
 */
function access_json_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function access_get_input_data(): array
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

function access_set_session_cookie(string $sessionRaw): void
{
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    setcookie(ACCESS_SESSION_COOKIE, $sessionRaw, [
        'expires' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function access_validate_source_key(string $value): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{6,20}$/', $value);
}

/**
 * @return string[]
 */
function access_role_list(string $raw): array
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

function access_role_has(string $raw, string $role): bool
{
    $needle = strtolower(trim($role));
    if ($needle === '') return false;
    return in_array($needle, access_role_list($raw), true);
}

function access_can_create_video_token(PDO $pdo, int $userId, string $role, string $sourceKey): bool
{
    if ($userId <= 0 || $sourceKey === '' || !access_validate_source_key($sourceKey)) {
        return false;
    }
    if (access_role_has($role, 'admin')) {
        return true;
    }
    if (!access_role_has($role, 'editor')) {
        return false;
    }

    try {
        $scopeStmt = $pdo->prepare('SELECT has_global_video_access FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $scopeStmt->execute([$userId]);
        if ((int)($scopeStmt->fetchColumn() ?: 0) === 1) {
            return true;
        }

        $stmt = $pdo->prepare(
            'SELECT 1
             FROM user_video_access uva
             JOIN videos v ON v.id = uva.video_id
             WHERE uva.user_id = ?
               AND uva.can_edit = 1
               AND v.youtube_id = ?
             LIMIT 1'
        );
        $stmt->execute([$userId, $sourceKey]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function access_create(PDO $pdo): void
{
    if (!is_logged_in()) {
        access_json_response(401, [
            'ok' => false,
            'error' => 'not_logged_in',
            'message' => 'Wymagane logowanie.',
        ]);
    }

    $userId = current_user_id();
    $role = (string)current_user_role();
    $data = access_get_input_data();
    $csrf = (string)($data['csrf_token'] ?? '');
    $targetKey = trim((string)($data['target'] ?? 'video'));
    $scope = trim((string)($data['scope'] ?? 'view'));
    $resourceType = trim((string)($data['resource_type'] ?? 'video'));
    $resourceId = trim((string)($data['resource_id'] ?? ''));
    $note = mb_substr(trim((string)($data['note'] ?? '')), 0, 255);
    $tokenTtl = (int)($data['token_ttl_minutes'] ?? 1440);
    $sessionTtl = (int)($data['session_ttl_minutes'] ?? 720);

    if (!csrf_check($csrf)) {
        access_json_response(400, [
            'ok' => false,
            'error' => 'invalid_csrf',
            'message' => 'Nieprawidlowy token bezpieczenstwa.',
        ]);
    }
    if ($targetKey !== 'video' || $scope !== 'view' || $resourceType !== 'video') {
        access_json_response(400, [
            'ok' => false,
            'error' => 'invalid_input',
            'message' => 'Dla tego endpointu dozwolony jest tylko token view dla video.',
        ]);
    }
    if (!access_validate_source_key($resourceId)) {
        access_json_response(400, [
            'ok' => false,
            'error' => 'invalid_resource',
            'message' => 'Niepoprawny identyfikator filmu.',
        ]);
    }
    if ($tokenTtl < 1 || $tokenTtl > 1440) {
        access_json_response(400, [
            'ok' => false,
            'error' => 'invalid_token_ttl',
            'message' => 'TTL tokenu musi byc w zakresie 1-1440 minut.',
        ]);
    }
    if ($sessionTtl < 1 || $sessionTtl > 720) {
        access_json_response(400, [
            'ok' => false,
            'error' => 'invalid_session_ttl',
            'message' => 'TTL sesji musi byc w zakresie 1-720 minut.',
        ]);
    }
    if (!access_can_create_video_token($pdo, $userId, $role, $resourceId)) {
        access_json_response(403, [
            'ok' => false,
            'error' => 'forbidden',
            'message' => 'Brak uprawnien do generowania tokenu dla tego filmu.',
        ]);
    }

    try {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new DateTimeImmutable('now'))
            ->modify('+' . $tokenTtl . ' minutes')
            ->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO access_tokens
                (token_hash, target_key, scope, resource_type, resource_id, max_uses, used_count, session_ttl_minutes, expires_at, note, created_by_user_id, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $tokenHash,
            $targetKey,
            $scope,
            $resourceType,
            $resourceId,
            $sessionTtl,
            $expiresAt,
            $note !== '' ? $note : null,
            $userId,
        ]);

        $params = ['vt' => $rawToken];
        $url = '/video/play.php?' . http_build_query($params);

        access_json_response(201, [
            'ok' => true,
            'token' => $rawToken,
            'url' => $url,
            'meta' => [
                'target' => $targetKey,
                'scope' => $scope,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'token_ttl_minutes' => $tokenTtl,
                'session_ttl_minutes' => $sessionTtl,
                'expires_at' => $expiresAt,
            ],
        ]);
    } catch (Throwable $e) {
        access_json_response(500, [
            'ok' => false,
            'error' => 'create_failed',
            'message' => 'Nie udalo sie utworzyc tokenu.',
        ]);
    }
}

function access_exchange(PDO $pdo): void
{
    $data = access_get_input_data();
    $token = trim((string)($data['token'] ?? ''));
    $targetKey = trim((string)($data['target'] ?? ''));

    if ($token === '' || strlen($token) < 32 || $targetKey === '') {
        access_json_response(400, [
            'ok' => false,
            'error' => 'invalid_input',
            'message' => 'Niepoprawny token lub target.',
        ]);
    }

    $tokenHash = hash('sha256', $token);
    $ip = mb_substr(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 64);
    $ua = mb_substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id, target_key, scope, resource_type, resource_id, max_uses, used_count, session_ttl_minutes, expires_at, revoked_at
             FROM access_tokens
             WHERE token_hash = ?
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            access_json_response(404, [
                'ok' => false,
                'error' => 'token_not_found',
                'message' => 'Token nie istnieje.',
            ]);
        }

        if ((string)$row['target_key'] !== $targetKey) {
            $pdo->rollBack();
            access_json_response(403, [
                'ok' => false,
                'error' => 'target_mismatch',
                'message' => 'Token nie pasuje do tego zasobu.',
            ]);
        }

        if (!empty($row['revoked_at'])) {
            $pdo->rollBack();
            access_json_response(403, [
                'ok' => false,
                'error' => 'token_revoked',
                'message' => 'Token został unieważniony.',
            ]);
        }

        $expiresAt = strtotime((string)$row['expires_at']) ?: 0;
        if ($expiresAt <= time()) {
            $pdo->rollBack();
            access_json_response(403, [
                'ok' => false,
                'error' => 'token_expired',
                'message' => 'Token wygasł.',
            ]);
        }

        $maxUses = max(0, (int)($row['max_uses'] ?? 0)); // 0 = bez limitu
        $usedCount = max(0, (int)($row['used_count'] ?? 0));
        if ($maxUses > 0 && $usedCount >= $maxUses) {
            $pdo->rollBack();
            access_json_response(403, [
                'ok' => false,
                'error' => 'token_already_used',
                'message' => 'Token został już wykorzystany.',
            ]);
        }

        $useStmt = $pdo->prepare('UPDATE access_tokens SET used_count = used_count + 1, updated_at = NOW() WHERE id = ?');
        $useStmt->execute([(int)$row['id']]);

        $sessionRaw = bin2hex(random_bytes(32));
        $sessionHash = hash('sha256', strtolower($sessionRaw));
        $ttl = max(1, min(720, (int)($row['session_ttl_minutes'] ?? 30)));

        $sessionStmt = $pdo->prepare(
            'INSERT INTO access_token_sessions
                (session_hash, token_id, target_key, scope, resource_type, resource_id, expires_at, ip_address, user_agent, last_seen_at, created_at)
             VALUES
                (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?, ?, NOW(), NOW())'
        );
        $sessionStmt->execute([
            $sessionHash,
            (int)$row['id'],
            (string)$row['target_key'],
            (string)$row['scope'],
            $row['resource_type'] !== null ? (string)$row['resource_type'] : null,
            $row['resource_id'] !== null ? (string)$row['resource_id'] : null,
            $ttl,
            $ip !== '' ? $ip : null,
            $ua !== '' ? $ua : null,
        ]);

        $pdo->commit();
        access_set_session_cookie($sessionRaw);

        access_json_response(200, [
            'ok' => true,
            'granted' => [
                'target' => (string)$row['target_key'],
                'scope' => (string)$row['scope'],
                'resource_type' => $row['resource_type'],
                'resource_id' => $row['resource_id'],
                'ttl_minutes' => $ttl,
            ],
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        access_json_response(500, [
            'ok' => false,
            'error' => 'exchange_failed',
            'message' => 'Nie udało się wymienić tokenu.',
        ]);
    }
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_GET['action'] ?? ''));

if ($action === 'exchange' && $method === 'POST') {
    access_exchange($pdo);
}
if ($action === 'create' && $method === 'POST') {
    access_create($pdo);
}

access_json_response(405, [
    'ok' => false,
    'error' => 'method_not_allowed',
    'message' => 'Nieobsługiwana akcja lub metoda.',
]);
