<?php
declare(strict_types=1);

const ACCESS_SESSION_COOKIE = 'zt_access';

/**
 * @return array<string,mixed>|null
 */
function access_get_session(PDO $pdo): ?array
{
    $raw = trim((string)($_COOKIE[ACCESS_SESSION_COOKIE] ?? ''));
    if ($raw === '' || !preg_match('/^[A-Fa-f0-9]{64}$/', $raw)) {
        return null;
    }

    $hash = hash('sha256', strtolower($raw));
    $stmt = $pdo->prepare(
        'SELECT id, token_id, target_key, scope, resource_type, resource_id, expires_at
         FROM access_token_sessions
         WHERE session_hash = ?
           AND revoked_at IS NULL
           AND expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$hash]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        return null;
    }

    try {
        $touch = $pdo->prepare('UPDATE access_token_sessions SET last_seen_at = NOW() WHERE id = ?');
        $touch->execute([(int)$session['id']]);
    } catch (Throwable $e) {
        // no-op: failure to update last_seen should not block request
    }

    return $session;
}

function access_scope_allows(string $granted, string $required): bool
{
    $levels = ['view' => 1, 'edit' => 2];
    $g = $levels[$granted] ?? 0;
    $r = $levels[$required] ?? 99;
    return $g >= $r;
}

/**
 * @param array<string,mixed>|null $session
 */
function access_session_allows(?array $session, string $targetKey, string $requiredScope, ?string $resourceType = null, ?string $resourceId = null): bool
{
    if (!$session) {
        return false;
    }
    if ((string)($session['target_key'] ?? '') !== $targetKey) {
        return false;
    }
    if (!access_scope_allows((string)($session['scope'] ?? ''), $requiredScope)) {
        return false;
    }

    $grantedType = trim((string)($session['resource_type'] ?? ''));
    $grantedId = trim((string)($session['resource_id'] ?? ''));

    if ($grantedType !== '' || $grantedId !== '') {
        if ($resourceType === null || $resourceId === null) {
            return false;
        }
        if ($grantedType !== '' && $grantedType !== $resourceType) {
            return false;
        }
        if ($grantedId !== '' && $grantedId !== $resourceId) {
            return false;
        }
    }

    return true;
}

