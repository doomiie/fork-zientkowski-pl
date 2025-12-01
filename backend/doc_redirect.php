<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    echo 'Nie znaleziono pliku.';
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT file_path, fallback_url, expires_at, is_enabled FROM doc_files WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        http_response_code(404);
        echo 'Nie znaleziono pliku.';
        exit;
    }
    $isExpired = false;
    if (!empty($doc['expires_at'])) {
        $expiresTime = strtotime((string)$doc['expires_at']);
        $isExpired = $expiresTime !== false && $expiresTime <= time();
    }
    $isActive = (int)($doc['is_enabled'] ?? 0) === 1 && !$isExpired;
    $target = $isActive
        ? (string)$doc['file_path']
        : (string)($doc['fallback_url'] ?? '');
    $target = trim($target);
    if ($target !== '') {
        header('Location: ' . $target, true, 302);
        exit;
    }
    http_response_code($isActive ? 404 : 410);
    echo $isActive ? 'Plik nie jest dostępny.' : 'Ten odnośnik został wyłączony.';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Wystąpił błąd. Spróbuj ponownie później.';
}

