<?php
declare(strict_types=1);

// Public JSON listing of downloadable documents
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../admin/db.php';

try {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare('SELECT id, display_name, file_path, original_name, mime_type, file_size, expires_at, created_at, is_enabled, fallback_url FROM doc_files WHERE is_enabled = 1 AND (expires_at IS NULL OR expires_at > ?) ORDER BY created_at DESC');
    $stmt->execute([$now]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['download_url'] = '/backend/doc_redirect.php?id=' . (int)$row['id'];
    }
    unset($row);
    echo json_encode(['items' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'list_failed']);
}
