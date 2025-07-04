<?php
header('Content-Type: application/json');
$payload = json_decode(file_get_contents('php://input'), true);
if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit();
}

$configPath = dirname(__DIR__) . '/config.json';
$cfg = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : [];
$p24 = $cfg['przelewy24'] ?? [];
$merchantId = $p24['merchantId'] ?? '';
$posId = $p24['posId'] ?? $merchantId;
$crc = $p24['crc'] ?? '';
$sandbox = isset($p24['sandbox']) ? $p24['sandbox'] : true;

if (!$merchantId || !$posId || !$crc) {
    http_response_code(500);
    echo json_encode(['error' => 'Przelewy24 not configured']);
    exit();
}

$sessionId = uniqid('p24_', true);
$amountGrosz = intval($payload['amount']) * 100;
$description = $payload['meetingType'] . ' ' . ($payload['date'] ?? '') . ' ' . ($payload['time'] ?? '');

$scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$returnBase = $scheme . '://' . $host . dirname($_SERVER['REQUEST_URI']) . '/p24_return.php';

$request = [
    'p24_merchant_id' => $merchantId,
    'p24_pos_id' => $posId,
    'p24_session_id' => $sessionId,
    'p24_amount' => $amountGrosz,
    'p24_currency' => 'PLN',
    'p24_description' => $description,
    'p24_email' => $payload['email'] ?? '',
    'p24_country' => 'PL',
    'p24_url_return' => $returnBase . '?session=' . urlencode($sessionId),
    'p24_url_status' => $returnBase . '?session=' . urlencode($sessionId),
    'p24_sign' => md5($sessionId . '|' . $posId . '|' . $amountGrosz . '|PLN|' . $crc)
];

$url = $sandbox ? 'https://sandbox.przelewy24.pl/trnRegister' : 'https://secure.przelewy24.pl/trnRegister';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($request));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// save raw response for debugging
$logLine = sprintf(
    "%s HTTP %s CURL:%s RESPONSE:%s\n",
    date('c'),
    $httpCode,
    $curlError ?: 'OK',
    trim($response)
);
file_put_contents(__DIR__ . '/p24_debug.log', $logLine, FILE_APPEND);

parse_str($response, $resp);
if (!isset($resp['token']) || ($resp['error'] ?? '1') !== '0') {
    http_response_code(500);
    echo json_encode(['error' => 'Register failed', 'details' => $resp]);
    exit();
}

// store session details for later verification
$payload['sessionId'] = $sessionId;
file_put_contents(__DIR__ . '/p24_sessions/' . $sessionId . '.json', json_encode($payload));

$payUrlBase = $sandbox ? 'https://sandbox.przelewy24.pl/trnRequest/' : 'https://secure.przelewy24.pl/trnRequest/';
$redirect = $payUrlBase . $resp['token'];

echo json_encode(['url' => $redirect]);
