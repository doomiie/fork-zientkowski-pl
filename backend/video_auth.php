<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../admin/db.php';

/**
 * @param array<string,mixed> $payload
 */
function auth_json_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function auth_role_map(string $rawRole): string
{
    if (function_exists('role_raw_has') && role_raw_has($rawRole, 'admin')) {
        return 'admin';
    }
    if (function_exists('role_raw_has') && role_raw_has($rawRole, 'editor')) {
        return 'trener';
    }
    return 'user';
}

/**
 * @return array<string,mixed>
 */
function auth_get_input_data(): array
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
function auth_current_user(PDO $pdo): array
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

    try {
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
        return [
            'logged_in' => true,
            'user_id' => (int)$row['id'],
            'email' => (string)$row['email'],
            'role' => auth_role_map((string)$row['role']),
        ];
    } catch (Throwable $e) {
        return [
            'logged_in' => false,
            'user_id' => null,
            'email' => null,
            'role' => null,
        ];
    }
}

function auth_status(PDO $pdo): void
{
    auth_json_response(200, [
        'ok' => true,
        'user' => auth_current_user($pdo),
        'csrf_token' => csrf_token(),
    ]);
}

function auth_login(PDO $pdo): void
{
    $data = auth_get_input_data();
    $email = trim((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $token = (string)($data['csrf_token'] ?? '');

    if (!csrf_check($token)) {
        auth_json_response(400, [
            'ok' => false,
            'error' => 'invalid_csrf',
            'message' => 'Nieprawidłowy token bezpieczeństwa.',
        ]);
    }
    if ($email === '' || $password === '') {
        auth_json_response(400, [
            'ok' => false,
            'error' => 'invalid_input',
            'message' => 'Podaj e-mail i hasło.',
        ]);
    }

    try {
        $stmt = $pdo->prepare('SELECT id, email, password_hash, is_active, role FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $ok = $row && (int)$row['is_active'] === 1 && password_verify($password, (string)$row['password_hash']);
        if (!$ok) {
            auth_json_response(401, [
                'ok' => false,
                'error' => 'invalid_credentials',
                'message' => 'Nieprawidłowy e-mail lub hasło.',
            ]);
        }

        $_SESSION['user_id'] = (int)$row['id'];
        $_SESSION['user_email'] = (string)$row['email'];
        $_SESSION['user_role'] = (string)$row['role'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);

        auth_json_response(200, [
            'ok' => true,
            'user' => auth_current_user($pdo),
            'csrf_token' => csrf_token(),
        ]);
    } catch (Throwable $e) {
        auth_json_response(500, [
            'ok' => false,
            'error' => 'login_failed',
            'message' => 'Nie udało się zalogować.',
        ]);
    }
}

function auth_logout(PDO $pdo): void
{
    $data = auth_get_input_data();
    $token = (string)($data['csrf_token'] ?? '');
    if (!csrf_check($token)) {
        auth_json_response(400, [
            'ok' => false,
            'error' => 'invalid_csrf',
            'message' => 'Nieprawidłowy token bezpieczeństwa.',
        ]);
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
    session_start();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    auth_json_response(200, [
        'ok' => true,
        'user' => [
            'logged_in' => false,
            'user_id' => null,
            'email' => null,
            'role' => null,
        ],
        'csrf_token' => csrf_token(),
    ]);
}

function auth_register(PDO $pdo): void
{
    $data = auth_get_input_data();
    $email = mb_strtolower(trim((string)($data['email'] ?? '')));
    $password = (string)($data['password'] ?? '');
    $token = (string)($data['csrf_token'] ?? '');

    if (!csrf_check($token)) {
        auth_json_response(400, [
            'ok' => false,
            'error' => 'invalid_csrf',
            'message' => 'Nieprawidłowy token bezpieczeństwa.',
        ]);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        auth_json_response(400, [
            'ok' => false,
            'error' => 'invalid_email',
            'message' => 'Podaj poprawny e-mail.',
        ]);
    }
    if (mb_strlen($password) < 8) {
        auth_json_response(400, [
            'ok' => false,
            'error' => 'weak_password',
            'message' => 'Hasło musi mieć co najmniej 8 znaków.',
        ]);
    }

    try {
        $exists = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $exists->execute([$email]);
        if ($exists->fetch(PDO::FETCH_ASSOC)) {
            auth_json_response(409, [
                'ok' => false,
                'error' => 'email_exists',
                'message' => 'Konto o tym e-mailu już istnieje.',
            ]);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $insert = $pdo->prepare(
            "INSERT INTO users (email, password_hash, role, is_active, created_at, updated_at)
             VALUES (?, ?, 'viewer', 1, NOW(), NOW())"
        );
        $insert->execute([$email, $hash]);
        $newId = (int)$pdo->lastInsertId();

        $_SESSION['user_id'] = $newId;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_role'] = 'viewer';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$newId]);

        auth_json_response(201, [
            'ok' => true,
            'user' => auth_current_user($pdo),
            'csrf_token' => csrf_token(),
        ]);
    } catch (Throwable $e) {
        auth_json_response(500, [
            'ok' => false,
            'error' => 'register_failed',
            'message' => 'Nie udało się utworzyć konta.',
        ]);
    }
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_GET['action'] ?? 'status'));

if ($action === 'status' && $method === 'GET') {
    auth_status($pdo);
}
if ($action === 'login' && $method === 'POST') {
    auth_login($pdo);
}
if ($action === 'logout' && $method === 'POST') {
    auth_logout($pdo);
}
if ($action === 'register' && $method === 'POST') {
    auth_register($pdo);
}

auth_json_response(405, [
    'ok' => false,
    'error' => 'method_not_allowed',
    'message' => 'Nieobsługiwana akcja lub metoda.',
]);
