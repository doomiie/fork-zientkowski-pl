<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/video_tokens_lib.php';

/**
 * @param array<string,mixed> $payload
 */
function p24_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @return array{merchant_id:int,pos_id:int,api_key:string,crc:string,base_url:string,return_url:string,status_url:string}
 */
function p24_config(): array
{
    $sandbox = (string)(getenv('P24_SANDBOX') ?: '1');
    $baseUrl = ($sandbox === '1')
        ? 'https://sandbox.przelewy24.pl/api/v1'
        : 'https://secure.przelewy24.pl/api/v1';
    $siteBase = rtrim((string)(getenv('APP_BASE_URL') ?: ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'))), '/');

    return [
        'merchant_id' => (int)(getenv('P24_MERCHANT_ID') ?: 0),
        'pos_id' => (int)(getenv('P24_POS_ID') ?: 0),
        'api_key' => (string)(getenv('P24_API_KEY') ?: ''),
        'crc' => (string)(getenv('P24_CRC') ?: ''),
        'base_url' => $baseUrl,
        'return_url' => $siteBase . '/video/tokens.php?payment=return',
        'status_url' => $siteBase . '/backend/video_payment_p24.php?action=notify',
    ];
}

/**
 * @param array<string,mixed> $parts
 */
function p24_sign(array $parts, string $crc): string
{
    ksort($parts);
    $json = json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return hash('sha384', (string)$json . $crc);
}

/**
 * @return array<string,mixed>
 */
function p24_post(string $url, array $payload, int $posId, string $apiKey): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl_missing', 'status' => 500, 'raw' => null];
    }
    $ch = curl_init($url);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode($posId . ':' . $apiKey),
    ];
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errNo = curl_errno($ch);
    $errStr = curl_error($ch);
    curl_close($ch);

    if ($errNo !== 0) {
        return ['ok' => false, 'error' => 'curl_error', 'status' => 500, 'raw' => $errStr];
    }
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'invalid_json', 'status' => $code ?: 500, 'raw' => $raw];
    }
    return ['ok' => ($code >= 200 && $code < 300), 'status' => $code, 'data' => $decoded, 'raw' => $raw];
}

function p24_require_auth(PDO $pdo): array
{
    $user = vt_current_user($pdo);
    if (!$user['logged_in'] || empty($user['user_id'])) {
        p24_json(401, ['ok' => false, 'error' => 'not_logged_in', 'message' => 'Musisz się zalogować.']);
    }
    return $user;
}

function p24_checkout(PDO $pdo): void
{
    $user = p24_require_auth($pdo);
    $data = vt_get_input_data();
    $csrf = (string)($data['csrf_token'] ?? '');
    $orderId = (int)($data['order_id'] ?? 0);
    if (!csrf_check($csrf)) {
        p24_json(400, ['ok' => false, 'error' => 'invalid_csrf', 'message' => 'Nieprawidłowy token bezpieczeństwa.']);
    }
    if ($orderId <= 0) {
        p24_json(400, ['ok' => false, 'error' => 'invalid_order', 'message' => 'Niepoprawny identyfikator zamówienia.']);
    }

    $stmt = $pdo->prepare(
        'SELECT o.id, o.order_uuid, o.status, o.amount_gross_pln, o.currency, o.user_id,
                t.title AS token_title
         FROM token_orders o
         JOIN token_types t ON t.id = o.token_type_id
         WHERE o.id = ? AND o.user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$orderId, (int)$user['user_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        p24_json(404, ['ok' => false, 'error' => 'order_not_found', 'message' => 'Nie znaleziono zamówienia.']);
    }
    if ((string)$order['status'] === 'paid') {
        p24_json(200, ['ok' => true, 'already_paid' => true, 'message' => 'Zamówienie jest już opłacone.']);
    }

    $cfg = p24_config();
    if ($cfg['merchant_id'] <= 0 || $cfg['pos_id'] <= 0 || $cfg['api_key'] === '' || $cfg['crc'] === '') {
        p24_json(500, [
            'ok' => false,
            'error' => 'p24_not_configured',
            'message' => 'Brak konfiguracji Przelewy24 (ENV).',
        ]);
    }

    $amount = (int)round(((float)$order['amount_gross_pln']) * 100);
    $sessionId = 'VIDEO_' . $order['order_uuid'] . '_' . time();
    $currency = strtoupper((string)$order['currency']);
    $sign = p24_sign([
        'sessionId' => $sessionId,
        'merchantId' => $cfg['merchant_id'],
        'amount' => $amount,
        'currency' => $currency,
    ], $cfg['crc']);

    $payload = [
        'merchantId' => $cfg['merchant_id'],
        'posId' => $cfg['pos_id'],
        'sessionId' => $sessionId,
        'amount' => $amount,
        'currency' => $currency,
        'description' => 'Zakup żetonu: ' . (string)$order['token_title'],
        'email' => (string)$user['email'],
        'country' => 'PL',
        'language' => 'pl',
        'urlReturn' => $cfg['return_url'] . '&order=' . urlencode((string)$order['order_uuid']),
        'urlStatus' => $cfg['status_url'],
        'sign' => $sign,
    ];

    $res = p24_post($cfg['base_url'] . '/transaction/register', $payload, $cfg['pos_id'], $cfg['api_key']);
    if (!$res['ok']) {
        p24_json(502, [
            'ok' => false,
            'error' => 'p24_register_failed',
            'message' => 'Nie udało się zarejestrować płatności.',
            'details' => $res['data'] ?? $res['raw'] ?? null,
        ]);
    }

    $dataOut = is_array($res['data'] ?? null) ? $res['data'] : [];
    $token = (string)($dataOut['data']['token'] ?? $dataOut['token'] ?? '');
    if ($token === '') {
        p24_json(502, ['ok' => false, 'error' => 'p24_missing_token', 'message' => 'Brak tokenu płatności P24.']);
    }

    $providerPayload = json_encode($dataOut, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $upd = $pdo->prepare(
        "UPDATE token_orders
         SET provider_session_id = ?, provider_payload = ?, updated_at = NOW()
         WHERE id = ?"
    );
    $upd->execute([$sessionId, $providerPayload, (int)$order['id']]);

    $redirect = str_replace('/api/v1', '', $cfg['base_url']) . '/trnRequest/' . rawurlencode($token);
    p24_json(200, [
        'ok' => true,
        'payment_url' => $redirect,
        'order_id' => (int)$order['id'],
    ]);
}

function p24_return(PDO $pdo): void
{
    // Return endpoint can be polled by frontend; final status is confirmed by notify/verify.
    $orderUuid = trim((string)($_GET['order'] ?? ''));
    if ($orderUuid === '') {
        p24_json(200, ['ok' => true, 'status' => 'pending']);
    }
    $stmt = $pdo->prepare('SELECT status, paid_at, entitlements_granted_at FROM token_orders WHERE order_uuid = ? LIMIT 1');
    $stmt->execute([$orderUuid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        p24_json(404, ['ok' => false, 'error' => 'order_not_found', 'message' => 'Nie znaleziono zamówienia.']);
    }
    p24_json(200, ['ok' => true, 'status' => (string)$row['status'], 'paid_at' => $row['paid_at'] ?? null, 'entitlements_granted_at' => $row['entitlements_granted_at'] ?? null]);
}

function p24_notify(PDO $pdo): void
{
    $payload = vt_get_input_data();
    $sessionId = trim((string)($payload['sessionId'] ?? ''));
    $orderId = trim((string)($payload['orderId'] ?? ''));
    $amount = (int)($payload['amount'] ?? 0);
    $currency = strtoupper(trim((string)($payload['currency'] ?? 'PLN')));

    if ($sessionId === '' || $orderId === '' || $amount <= 0) {
        p24_json(400, ['ok' => false, 'error' => 'invalid_notify_payload', 'message' => 'Niepoprawny payload notify.']);
    }

    $stmt = $pdo->prepare(
        'SELECT id, status, amount_gross_pln, currency
         FROM token_orders
         WHERE provider_session_id = ?
         LIMIT 1'
    );
    $stmt->execute([$sessionId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        p24_json(404, ['ok' => false, 'error' => 'order_not_found', 'message' => 'Nie znaleziono zamówienia po sessionId.']);
    }

    $cfg = p24_config();
    if ($cfg['merchant_id'] <= 0 || $cfg['pos_id'] <= 0 || $cfg['api_key'] === '' || $cfg['crc'] === '') {
        p24_json(500, ['ok' => false, 'error' => 'p24_not_configured', 'message' => 'Brak konfiguracji Przelewy24.']);
    }

    $sign = p24_sign([
        'sessionId' => $sessionId,
        'orderId' => (int)$orderId,
        'amount' => $amount,
        'currency' => $currency,
    ], $cfg['crc']);
    $verifyPayload = [
        'merchantId' => $cfg['merchant_id'],
        'posId' => $cfg['pos_id'],
        'sessionId' => $sessionId,
        'amount' => $amount,
        'currency' => $currency,
        'orderId' => (int)$orderId,
        'sign' => $sign,
    ];
    $verifyRes = p24_post($cfg['base_url'] . '/transaction/verify', $verifyPayload, $cfg['pos_id'], $cfg['api_key']);
    if (!$verifyRes['ok']) {
        $updFail = $pdo->prepare("UPDATE token_orders SET status = 'failed', provider_order_id = ?, provider_payload = ?, updated_at = NOW() WHERE id = ?");
        $updFail->execute([$orderId, json_encode($verifyRes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int)$order['id']]);
        p24_json(502, ['ok' => false, 'error' => 'p24_verify_failed', 'message' => 'Weryfikacja P24 nie powiodła się.']);
    }

    $update = $pdo->prepare(
        "UPDATE token_orders
         SET status = 'paid', paid_at = NOW(), provider_order_id = ?, provider_payload = ?, updated_at = NOW()
         WHERE id = ?"
    );
    $update->execute([
        $orderId,
        json_encode($verifyRes['data'] ?? $verifyRes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        (int)$order['id'],
    ]);

    vt_grant_order_entitlements($pdo, (int)$order['id']);

    p24_json(200, ['ok' => true]);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_GET['action'] ?? ''));

if ($action === 'checkout' && $method === 'POST') p24_checkout($pdo);
if ($action === 'return' && $method === 'GET') p24_return($pdo);
if ($action === 'notify' && $method === 'POST') p24_notify($pdo);

p24_json(405, [
    'ok' => false,
    'error' => 'method_not_allowed',
    'message' => 'Nieobsługiwana akcja lub metoda.',
]);

