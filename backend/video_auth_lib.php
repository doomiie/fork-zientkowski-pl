<?php
declare(strict_types=1);

require_once __DIR__ . '/video_mail_lib.php';

const AUTH_EMAIL_VERIFY_TTL_HOURS = 24;

function auth_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        $host = 'localhost';
    }
    return $scheme . '://' . $host;
}

function auth_email_verify_url(string $token): string
{
    return auth_base_url() . '/video/verify-email.php?token=' . urlencode($token);
}

/**
 * @return array{id:int,user_id:int,token:string,expires_at:string,used_at:?string}|null
 */
function auth_find_email_verification(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, user_id, token, expires_at, used_at
         FROM user_email_verifications
         WHERE token = ?
         LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'user_id' => (int)$row['user_id'],
        'token' => (string)$row['token'],
        'expires_at' => (string)$row['expires_at'],
        'used_at' => isset($row['used_at']) ? (string)$row['used_at'] : null,
    ];
}

/**
 * @return array{ok:bool,error:string,user_id?:int,email?:string}
 */
function auth_verify_email_token(PDO $pdo, string $token): array
{
    $verification = auth_find_email_verification($pdo, $token);
    if (!$verification) {
        return ['ok' => false, 'error' => 'invalid'];
    }
    if ($verification['used_at'] !== null && $verification['used_at'] !== '') {
        return ['ok' => false, 'error' => 'used'];
    }

    $now = new DateTimeImmutable('now');
    $expiresAt = new DateTimeImmutable($verification['expires_at']);
    if ($expiresAt < $now) {
        return ['ok' => false, 'error' => 'expired'];
    }

    try {
        $pdo->beginTransaction();

        $userStmt = $pdo->prepare('SELECT id, email, is_active, email_verified_at FROM users WHERE id = ? LIMIT 1');
        $userStmt->execute([$verification['user_id']]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'invalid'];
        }

        if ((string)($user['email_verified_at'] ?? '') !== '') {
            $pdo->prepare('UPDATE user_email_verifications SET used_at = NOW() WHERE id = ?')->execute([$verification['id']]);
            $pdo->commit();
            return [
                'ok' => true,
                'user_id' => (int)$user['id'],
                'email' => (string)$user['email'],
            ];
        }

        $pdo->prepare('UPDATE users SET email_verified_at = NOW(), updated_at = NOW() WHERE id = ? LIMIT 1')->execute([$verification['user_id']]);
        $pdo->prepare('UPDATE user_email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$verification['user_id']]);
        $pdo->commit();

        return [
            'ok' => true,
            'user_id' => (int)$user['id'],
            'email' => (string)$user['email'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'failed'];
    }
}

/**
 * @return array{token:string,expires_at:string}
 */
function auth_issue_email_verification(PDO $pdo, int $userId): array
{
    $pdo->prepare('UPDATE user_email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);

    $token = bin2hex(random_bytes(32));
    $expiresAt = (new DateTimeImmutable('+' . AUTH_EMAIL_VERIFY_TTL_HOURS . ' hours'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO user_email_verifications (user_id, token, expires_at, used_at, created_at)
         VALUES (?, ?, ?, NULL, NOW())'
    );
    $stmt->execute([$userId, $token, $expiresAt]);

    return [
        'token' => $token,
        'expires_at' => $expiresAt,
    ];
}

function auth_send_verification_email(PDO $pdo, string $email, string $token): void
{
    $verifyUrl = auth_email_verify_url($token);
    video_mail_send_touchpoint($pdo, 'video_auth.register.initial_verification', $email, [
        'verify_url' => $verifyUrl,
        'verify_ttl_hours' => AUTH_EMAIL_VERIFY_TTL_HOURS,
    ]);
}
