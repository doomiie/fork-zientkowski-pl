<?php
header('Content-Type: application/json');
$beforePath = __DIR__ . '/../wtl_before.log';
$afterPath = __DIR__ . '/../wtl_after.log';
$before = file_exists($beforePath) ? file($beforePath, FILE_IGNORE_NEW_LINES) : [];
$after = file_exists($afterPath) ? file($afterPath, FILE_IGNORE_NEW_LINES) : [];
echo json_encode(['before' => $before, 'after' => $after]);

