<?php
header('Content-Type: application/json');
$path = dirname(__DIR__).'/config.json';
$data = json_decode(file_get_contents('php://input'), true);
if ($data === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit();
}
if (file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Write failed']);
    exit();
}
echo json_encode(['status' => 'ok']);
