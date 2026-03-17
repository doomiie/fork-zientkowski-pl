<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';

/**
 * @return string[]
 */
function video_role_list(string $raw): array
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

function video_role_has(string $raw, string $role): bool
{
    $needle = strtolower(trim($role));
    if ($needle === '') return false;
    return in_array($needle, video_role_list($raw), true);
}

function video_app_user(): array
{
    if (!is_logged_in()) {
        return ['logged_in' => false, 'user_id' => null, 'email' => null, 'role' => null, 'roles' => [], 'is_admin' => false, 'is_trener' => false];
    }
    $userId = current_user_id();
    if ($userId <= 0) {
        return ['logged_in' => false, 'user_id' => null, 'email' => null, 'role' => null, 'roles' => [], 'is_admin' => false, 'is_trener' => false];
    }
    $stmt = $GLOBALS['pdo']->prepare('SELECT id, email, role, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['is_active'] !== 1) {
        return ['logged_in' => false, 'user_id' => null, 'email' => null, 'role' => null, 'roles' => [], 'is_admin' => false, 'is_trener' => false];
    }
    $rawRole = (string)$row['role'];
    $roles = video_role_list($rawRole);
    $isAdmin = video_role_has($rawRole, 'admin');
    $isTrener = video_role_has($rawRole, 'editor');
    $mapped = 'user';
    if ($isAdmin) $mapped = 'admin';
    elseif ($isTrener) $mapped = 'trener';
    return [
        'logged_in' => true,
        'user_id' => (int)$row['id'],
        'email' => (string)$row['email'],
        'role' => $mapped,
        'roles' => $roles,
        'is_admin' => $isAdmin,
        'is_trener' => $isTrener,
    ];
}

$videoAppUser = video_app_user();
$videoAppCsrf = csrf_token();
