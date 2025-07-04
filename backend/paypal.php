<?php
header('Content-Type: application/json');
$action = $_GET['action'] ?? '';

$clientId = getenv('PAYPAL_CLIENT_ID');
$secret = getenv('PAYPAL_SECRET');
$baseUrl = getenv('PAYPAL_BASE_URL') ?: 'https://api-m.sandbox.paypal.com';

if (!$clientId || !$secret) {
    http_response_code(500);
    echo json_encode(['error' => 'PayPal credentials not configured']);
    exit();
}

function getAccessToken($clientId, $secret, $baseUrl)
{
    $ch = curl_init("$baseUrl/v1/oauth2/token");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => "$clientId:$secret",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials'
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        return null;
    }
    $data = json_decode($res, true);
    return $data['access_token'] ?? null;
}

function createOrder($token, $baseUrl, $amount, $currency = 'USD')
{
    $payload = [
        'intent' => 'CAPTURE',
        'purchase_units' => [
            [
                'amount' => [
                    'currency_code' => $currency,
                    'value' => $amount
                ]
            ]
        ],
        'application_context' => [
            'return_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/sesja.html',
            'cancel_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/sesja.html'
        ]
    ];
    $ch = curl_init("$baseUrl/v2/checkout/orders");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($status >= 400 || $res === false) {
        http_response_code($status ?: 500);
        echo $res ? $res : json_encode(['error' => 'PayPal create order failed']);
        exit();
    }
    return json_decode($res, true);
}

function captureOrder($token, $baseUrl, $orderId)
{
    $ch = curl_init("$baseUrl/v2/checkout/orders/$orderId/capture");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]
    ]);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($status >= 400 || $res === false) {
        http_response_code($status ?: 500);
        echo $res ? $res : json_encode(['error' => 'PayPal capture failed']);
        exit();
    }
    return json_decode($res, true);
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $amount = $data['amount'] ?? '100.00';
    $currency = $data['currency'] ?? 'USD';
    $token = getAccessToken($clientId, $secret, $baseUrl);
    if (!$token) {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to obtain PayPal token']);
        exit();
    }
    $order = createOrder($token, $baseUrl, $amount, $currency);
    echo json_encode($order);
    exit();
}

if ($action === 'capture' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $orderId = $data['orderID'] ?? '';
    if (!$orderId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing orderID']);
        exit();
    }
    $token = getAccessToken($clientId, $secret, $baseUrl);
    if (!$token) {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to obtain PayPal token']);
        exit();
    }
    $capture = captureOrder($token, $baseUrl, $orderId);
    echo json_encode($capture);
    exit();
}

http_response_code(400);
echo json_encode(['error' => 'Invalid request']);
