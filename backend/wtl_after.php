<?php
// Webhook called by WTL after payment is completed
$log = __DIR__ . '/../wtl_after.log';
$payload = file_get_contents('php://input');
file_put_contents($log, date('c') . " " . $payload . "\n", FILE_APPEND);
header('Content-Type: text/plain');
echo "ok";
