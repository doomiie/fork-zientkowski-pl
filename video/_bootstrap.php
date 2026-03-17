<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';

function video_app_user(): array
{
    if (!is_logged_in()) {
        return ['logged_in' => false, 'user_id' => null, 'email' => null, 'role' => null];
    }
    $userId = current_user_id();
    if ($userId <= 0) {
        return ['logged_in' => false, 'user_id' => null, 'email' => null, 'role' => null];
    }
    $stmt = $GLOBALS['pdo']->prepare('SELECT id, email, role, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['is_active'] !== 1) {
        return ['logged_in' => false, 'user_id' => null, 'email' => null, 'role' => null];
    }
    $rawRole = strtolower((string)$row['role']);
    $mapped = 'user';
    if ($rawRole === 'admin') $mapped = 'admin';
    elseif ($rawRole === 'editor') $mapped = 'trener';
    return [
        'logged_in' => true,
        'user_id' => (int)$row['id'],
        'email' => (string)$row['email'],
        'role' => $mapped,
    ];
}

$videoAppUser = video_app_user();
$videoAppCsrf = csrf_token();

