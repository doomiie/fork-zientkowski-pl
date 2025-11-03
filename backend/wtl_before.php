<?php
// Webhook called by WTL before payment is processed
$log = __DIR__ . '/wtl_before.log';
$payload = file_get_contents('php://input');
file_put_contents($log, date('c') . " " . $payload . "\n", FILE_APPEND);
header('Content-Type: text/plain');
echo "ok";
