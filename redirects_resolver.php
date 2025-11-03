<?php
declare(strict_types=1);

// Resolve dynamic redirects defined in DB (admin).
// This script is intended to be routed via .htaccess catch‑all for non-existing files.

// Include DB connection from admin
require __DIR__ . '/admin/db.php';

// Extract normalized path from the request
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';

try {
    // Find active redirect for exact link
    $stmt = $pdo->prepare('SELECT id, link, http_code, target, expires_at, fallback, is_active, hit_count FROM redirects WHERE link = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$path]);
    $row = $stmt->fetch();

    if ($row) {
        $now = new DateTimeImmutable('now');
        $destination = (string)$row['target'];
        // Preserve incoming query string parameters when redirecting
        $qs = parse_url($uri, PHP_URL_QUERY) ?: '';
        if (!empty($row['expires_at'])) {
            $exp = new DateTimeImmutable((string)$row['expires_at']);
            if ($exp < $now) {
                $destination = (string)$row['fallback'];
            }
        }

        $code = (int)$row['http_code'];
        if (!in_array($code, [301,302,307,308], true)) {
            $code = 302;
        }

        // Update stats (best‑effort)
        try {
            $pdo->prepare('UPDATE redirects SET hit_count = hit_count + 1, last_hit_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
        } catch (Throwable $e) {}

        if ($qs !== '') {
            if (strpos($destination, '?') !== false) {
                $destination .= '&' . $qs;
            } else {
                $destination .= '?' . $qs;
            }
        }
        header('Location: ' . $destination, true, $code);
        exit;
    }
} catch (Throwable $e) {
    // fall through to 404
}

// Nothing matched: render unified 404 (with DB logging)
require __DIR__ . '/404.php';
