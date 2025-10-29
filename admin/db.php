<?php
declare(strict_types=1);

// Secure session configuration
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
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
        $stmt = $pdo->prepare('SELECT email, role, is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([ (int)$_SESSION['user_id'] ]);
        if ($row = $stmt->fetch()) {
            if ((int)$row['is_active'] !== 1) {
                // deactivate session if user disabled
                $_SESSION = [];
                return;
            }
            $_SESSION['user_email'] = (string)$row['email'];
            $_SESSION['user_role']  = (string)$row['role'];
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

function is_admin(): bool {
    return current_user_role() === 'admin';
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
