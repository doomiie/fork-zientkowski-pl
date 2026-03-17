<?php
declare(strict_types=1);

$sourceFile = dirname(__DIR__) . '/video.html';
if (!is_file($sourceFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Brak pliku playera: video.html';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
readfile($sourceFile);
