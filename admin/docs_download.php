<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$hash = trim((string)($_GET['hash'] ?? ''));
$requestedName = (string)($_GET['name'] ?? '');
@file_put_contents(dirname(__DIR__) . '/docs_download.log', sprintf("[%s] %s %s hash=%s name=%s\n", date('c'), $_SERVER['REMOTE_ADDR'] ?? '-', $_SERVER['REQUEST_URI'] ?? '-', $hash, $requestedName), FILE_APPEND);

if ($hash === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $hash)) {
    http_response_code(404);
    echo 'Nie znaleziono pliku.';
    exit;
}

$stmt = $pdo->prepare('SELECT id, display_name, file_path, original_name, mime_type, file_size, expires_at, available_from, is_enabled, fallback_url, share_hash FROM doc_files WHERE share_hash = ? LIMIT 1');
$stmt->execute([$hash]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    http_response_code(404);
    echo 'Nie znaleziono pliku.';
    exit;
}

$downloadName = trim((string)($doc['original_name'] ?? '')) ?: basename((string)($doc['file_path'] ?? '')) ?: ('dokument-' . (int)$doc['id']);
$encodedDownloadName = rawurlencode($downloadName);
$sharePath = '/docs/download/' . rawurlencode((string)$doc['share_hash']);
$canonicalUrl = rtrim($sharePath, '/') . '/' . $encodedDownloadName;

$isExpired = false;
if (!empty($doc['expires_at'])) {
    $expiresTs = strtotime((string)$doc['expires_at']);
    $isExpired = $expiresTs !== false && $expiresTs <= time();
}
$isUpcoming = false;
if (!empty($doc['available_from'])) {
    $availableTs = strtotime((string)$doc['available_from']);
    $isUpcoming = $availableTs !== false && $availableTs > time();
}
$isActive = (int)($doc['is_enabled'] ?? 0) === 1 && !$isExpired && !$isUpcoming;

if (!$isActive) {
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
    exit;
}

$normalizedRequested = $requestedName === '' ? '' : rawurldecode($requestedName);
if ($normalizedRequested === '' || $normalizedRequested !== $downloadName) {
    header('Location: ' . $canonicalUrl, true, 302);
    exit;
}

$relativePath = (string)($doc['file_path'] ?? '');
$absolutePath = dirname(__DIR__) . $relativePath;

if (!is_file($absolutePath)) {
    http_response_code(404);
    echo 'Nie znaleziono pliku.';
    exit;
}

$storageRoot = realpath(dirname(__DIR__) . '/docs/files');
$realPath = realpath($absolutePath);
if ($realPath === false || ($storageRoot && strpos($realPath, $storageRoot) !== 0)) {
    http_response_code(404);
    echo 'Nie znaleziono pliku.';
    exit;
}

$mime = trim((string)($doc['mime_type'] ?? ''));
if ($mime === '') {
    $mime = 'application/octet-stream';
}
$fileSize = filesize($realPath);
if ($fileSize === false) {
    $fileSize = (int)($doc['file_size'] ?? 0);
}

try {
    $update = $pdo->prepare('UPDATE doc_files SET download_count = download_count + 1 WHERE id = ?');
    $update->execute([(int)$doc['id']]);
} catch (Throwable $e) {
    // ignore counter errors
}

header('Content-Type: ' . $mime);
if ($fileSize > 0) {
    header('Content-Length: ' . (string)$fileSize);
}
header('Content-Disposition: inline; filename="' . str_replace('"', '', $downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Cache-Control: private, max-age=0, no-cache');
header('X-Robots-Tag: noindex');
header('X-Content-Type-Options: nosniff');
readfile($realPath);
exit;
