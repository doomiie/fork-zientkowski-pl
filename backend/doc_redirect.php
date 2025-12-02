<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    echo 'Nie znaleziono pliku.';
    exit;
}

$generateShareHash = static function (PDO $pdo): string {
    while (true) {
        $candidate = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM doc_files WHERE share_hash = ?');
        $stmt->execute([$candidate]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $candidate;
        }
    }
};

try {
    $stmt = $pdo->prepare('SELECT id, fallback_url, expires_at, available_from, is_enabled, share_hash FROM doc_files WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        http_response_code(404);
        echo 'Nie znaleziono pliku.';
        exit;
    }
    if (empty($doc['share_hash'])) {
        $hash = $generateShareHash($pdo);
        $update = $pdo->prepare('UPDATE doc_files SET share_hash = ? WHERE id = ?');
        $update->execute([$hash, (int)$doc['id']]);
        $doc['share_hash'] = $hash;
    }
    $isExpired = false;
    if (!empty($doc['expires_at'])) {
        $expiresTime = strtotime((string)$doc['expires_at']);
        $isExpired = $expiresTime !== false && $expiresTime <= time();
    }
    $isUpcoming = false;
    if (!empty($doc['available_from'])) {
        $availableTime = strtotime((string)$doc['available_from']);
        $isUpcoming = $availableTime !== false && $availableTime > time();
    }
    $isActive = (int)($doc['is_enabled'] ?? 0) === 1 && !$isExpired && !$isUpcoming;
    if ($isActive) {
        $target = '/docs/download/' . rawurlencode((string)$doc['share_hash']);
        header('Location: ' . $target, true, 302);
        exit;
    }
    $fallback = trim((string)($doc['fallback_url'] ?? ''));
    if ($fallback !== '') {
        header('Location: ' . $fallback, true, 302);
        exit;
    }
    http_response_code($isExpired ? 410 : 404);
    if ($isUpcoming) {
        echo 'Ten plik bedzie dostepny od ' . htmlspecialchars((string)($doc['available_from'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '.';
    } else {
        echo $isExpired ? 'Link wygasl.' : 'Link zostal wylaczony.';
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Wystapil blad. Sprobuj ponownie pozniej.';
}
