<?php
declare(strict_types=1);

function app_request_header(string $name): string {
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$serverKey] ?? ''));
}

function app_is_https_request(): bool {
    $https = strtolower(trim((string)($_SERVER['HTTPS'] ?? '')));
    if ($https !== '' && $https !== 'off') {
        return true;
    }
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    if ($forwardedProto === 'https') {
        return true;
    }
    $frontEndHttps = strtolower(trim((string)($_SERVER['HTTP_FRONT_END_HTTPS'] ?? '')));
    if ($frontEndHttps !== '' && $frontEndHttps !== 'off') {
        return true;
    }
    return (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function auth_debug_enabled(): bool {
    $flag = strtolower(trim((string)(getenv('APP_DEBUG_AUTH') ?: '')));
    if (in_array($flag, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    $query = strtolower(trim((string)($_GET['debug_auth'] ?? $_POST['debug_auth'] ?? '')));
    if (in_array($query, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    $header = strtolower(app_request_header('X-Debug-Auth'));
    return in_array($header, ['1', 'true', 'yes', 'on'], true);
}

function auth_debug_mask(string $value): string {
    if ($value === '') {
        return '';
    }
    $len = strlen($value);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    return substr($value, 0, 4) . '...' . substr($value, -4);
}

/**
 * @return array<string,mixed>
 */
function auth_debug_snapshot(array $extra = []): array {
    $cookieParams = session_get_cookie_params();
    $setCookieHeaders = [];
    foreach (headers_list() as $header) {
        if (stripos($header, 'Set-Cookie:') === 0) {
            $setCookieHeaders[] = $header;
        }
    }

    $sessionKeys = array_keys($_SESSION ?? []);
    sort($sessionKeys);

    return [
        'time' => gmdate('c'),
        'request' => [
            'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
            'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
            'server_name' => (string)($_SERVER['SERVER_NAME'] ?? ''),
            'https' => app_is_https_request(),
            'https_raw' => (string)($_SERVER['HTTPS'] ?? ''),
            'server_port' => (string)($_SERVER['SERVER_PORT'] ?? ''),
            'x_forwarded_proto' => (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''),
            'x_forwarded_host' => (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''),
            'origin' => (string)($_SERVER['HTTP_ORIGIN'] ?? ''),
            'referer' => (string)($_SERVER['HTTP_REFERER'] ?? ''),
            'content_type' => (string)($_SERVER['CONTENT_TYPE'] ?? ''),
        ],
        'session' => [
            'status' => session_status(),
            'name' => session_name(),
            'id' => auth_debug_mask(session_id()),
            'cookie_present' => array_key_exists(session_name(), $_COOKIE),
            'cookie_value_present' => trim((string)($_COOKIE[session_name()] ?? '')) !== '',
            'cookie_params' => $cookieParams,
            'keys' => $sessionKeys,
            'user_id' => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            'csrf_present' => !empty($_SESSION['csrf_token']),
            'csrf_len' => strlen((string)($_SESSION['csrf_token'] ?? '')),
        ],
        'response' => [
            'headers_sent' => headers_sent(),
            'set_cookie_headers' => $setCookieHeaders,
        ],
        'extra' => $extra,
    ];
}

function auth_debug_emit(string $event, array $extra = []): void {
    if (!auth_debug_enabled()) {
        return;
    }
    error_log('[auth-debug] ' . json_encode(auth_debug_snapshot([
        'event' => $event,
        'details' => $extra,
    ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// Secure session configuration
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => app_is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (auth_debug_enabled()) {
    header('X-Auth-Debug-Enabled: 1');
    auth_debug_emit('session_bootstrap', [
        'session_cookie_params' => session_get_cookie_params(),
    ]);
}

// Database config: set via environment variables or edit defaults below
$DB_HOST = getenv('APP_DB_HOST') ?: 'mysql5';
$DB_NAME = getenv('APP_DB_NAME') ?: 'doomiie_zpl2025';
$DB_USER = getenv('APP_DB_USER') ?: 'doomiie_zpl2025';
$DB_PASS = getenv('APP_DB_PASS') ?: 'JslWMt0h4Ew9krpfGBa033nsNpGFCeLm';

// Create PDO connection
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $DB_HOST, $DB_NAME),
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo 'Błąd połączenia z bazą danych.';
    exit;
}

// Ensure current user info (email, role, active) is fresh in session
function refresh_current_user(PDO $pdo): void {
    if (empty($_SESSION['user_id'])) return;
    try {
        $stmt = $pdo->prepare('SELECT email, role, has_global_video_access, is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([ (int)$_SESSION['user_id'] ]);
        if ($row = $stmt->fetch()) {
            if ((int)$row['is_active'] !== 1) {
                // deactivate session if user disabled
                $_SESSION = [];
                return;
            }
            $_SESSION['user_email'] = (string)$row['email'];
            $_SESSION['user_role']  = (string)$row['role'];
            $_SESSION['user_has_global_video_access'] = (int)($row['has_global_video_access'] ?? 0);
        }
    } catch (Throwable $e) {
        // ignore; do not break page render
    }
}

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
    // Refresh user info each request (role/email)
    refresh_current_user($GLOBALS['pdo']);
}

function current_user_email(): string {
    return $_SESSION['user_email'] ?? '';
}

function current_user_id(): int {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
}

function current_user_role(): string {
    return $_SESSION['user_role'] ?? '';
}

function current_user_has_global_video_access(): bool {
    return (int)($_SESSION['user_has_global_video_access'] ?? 0) === 1;
}

/**
 * @return string[]
 */
function role_list_from_raw(string $raw): array {
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

function role_raw_has(string $raw, string $role): bool {
    $needle = strtolower(trim($role));
    if ($needle === '') return false;
    $list = role_list_from_raw($raw);
    return in_array($needle, $list, true);
}

/**
 * @return string[]
 */
function current_user_roles(): array {
    return role_list_from_raw(current_user_role());
}

function is_admin(): bool {
    return role_raw_has(current_user_role(), 'admin');
}

function is_editor(): bool {
    return role_raw_has(current_user_role(), 'editor');
}

function require_admin(): void {
    if (!is_admin()) {
        http_response_code(403);
        echo 'Brak uprawnień.';
        exit;
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(?string $token): bool {
    if (!$token) return false;
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    return is_string($sessionToken) && hash_equals($sessionToken, $token);
}
