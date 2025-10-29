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
$DB_HOST = getenv('APP_DB_HOST') ?: 'localhost';
$DB_NAME = getenv('APP_DB_NAME') ?: 'app_db';
$DB_USER = getenv('APP_DB_USER') ?: 'app_user';
$DB_PASS = getenv('APP_DB_PASS') ?: 'app_pass';

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

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function current_user_email(): string {
    return $_SESSION['user_email'] ?? '';
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

