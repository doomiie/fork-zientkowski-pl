<?php
declare(strict_types=1);

// Public JSON listing of downloadable documents
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../admin/db.php';

try {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare('SELECT id, display_name, file_path, original_name, mime_type, file_size, available_from, expires_at, created_at, is_enabled, fallback_url, share_hash, download_count FROM doc_files WHERE is_enabled = 1 AND (available_from IS NULL OR available_from <= ?) AND (expires_at IS NULL OR expires_at > ?) ORDER BY created_at DESC');
    $stmt->execute([$now, $now]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $shareGenerator = static function (PDO $pdo): string {
        while (true) {
            $candidate = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
            $check = $pdo->prepare('SELECT COUNT(*) FROM doc_files WHERE share_hash = ?');
            $check->execute([$candidate]);
            if ((int)$check->fetchColumn() === 0) {
                return $candidate;
            }
        }
    };
    foreach ($rows as &$row) {
        if (empty($row['share_hash'])) {
            $hash = $shareGenerator($pdo);
            $update = $pdo->prepare('UPDATE doc_files SET share_hash = ? WHERE id = ?');
            $update->execute([$hash, (int)$row['id']]);
            $row['share_hash'] = $hash;
        }
        $shareBase = '/docs/download/' . rawurlencode((string)$row['share_hash']);
        $downloadName = trim((string)($row['original_name'] ?? '')) ?: basename((string)($row['file_path'] ?? '')) ?: ('dokument-' . (int)$row['id']);
        $row['share_url'] = $shareBase;
        $row['download_url'] = $shareBase . '/' . rawurlencode($downloadName);
        $row['download_count'] = (int)($row['download_count'] ?? 0);
        $row['available_from'] = $row['available_from'] ?? null;
        unset($row['share_hash']);
    }
    unset($row);
    echo json_encode(['items' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'list_failed']);
}
