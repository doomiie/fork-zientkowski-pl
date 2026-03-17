<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/video_tokens_lib.php';

/**
 * @param array<string,mixed> $payload
 */
function vt_json_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function vt_require_auth(PDO $pdo): array
{
    $user = vt_current_user($pdo);
    if (!$user['logged_in'] || empty($user['user_id'])) {
        vt_json_response(401, [
            'ok' => false,
            'error' => 'not_logged_in',
            'message' => 'Musisz się zalogować.',
        ]);
    }
    return $user;
}

function vt_list_types(PDO $pdo): void
{
    $stmt = $pdo->query(
        'SELECT id, code, title, description, price_gross_pln, currency, max_upload_links, can_choose_trainer, is_active, sort_order
         FROM token_types
         WHERE is_active = 1
         ORDER BY sort_order ASC, id ASC'
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    vt_json_response(200, ['ok' => true, 'types' => $rows]);
}

function vt_my_balance(PDO $pdo): void
{
    $user = vt_require_auth($pdo);
    $balance = vt_get_user_balance($pdo, (int)$user['user_id']);
    vt_json_response(200, ['ok' => true, 'balance' => $balance]);
}

function vt_my_orders(PDO $pdo): void
{
    $user = vt_require_auth($pdo);
    $stmt = $pdo->prepare(
        'SELECT o.id, o.order_uuid, o.status, o.amount_gross_pln, o.currency, o.payment_provider, o.provider_order_id,
                o.provider_session_id, o.paid_at, o.entitlements_granted_at, o.created_at,
                t.code AS token_code, t.title AS token_title, t.max_upload_links, t.can_choose_trainer
         FROM token_orders o
         JOIN token_types t ON t.id = o.token_type_id
         WHERE o.user_id = ?
         ORDER BY o.id DESC
         LIMIT 200'
    );
    $stmt->execute([(int)$user['user_id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    vt_json_response(200, ['ok' => true, 'orders' => $rows]);
}

function vt_list_trainers(PDO $pdo): void
{
    vt_require_auth($pdo);
    $stmt = $pdo->query(
        "SELECT id, email
         FROM users
         WHERE is_active = 1 AND role = 'editor'
         ORDER BY (last_login_at IS NULL) ASC, last_login_at DESC, id ASC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    vt_json_response(200, ['ok' => true, 'trainers' => $rows]);
}

function vt_create_order(PDO $pdo): void
{
    $user = vt_require_auth($pdo);
    $data = vt_get_input_data();
    $tokenTypeId = (int)($data['token_type_id'] ?? 0);
    $note = mb_substr(trim((string)($data['note'] ?? '')), 0, 255);
    $csrf = (string)($data['csrf_token'] ?? '');

    if (!csrf_check($csrf)) {
        vt_json_response(400, ['ok' => false, 'error' => 'invalid_csrf', 'message' => 'Nieprawidłowy token bezpieczeństwa.']);
    }
    if ($tokenTypeId <= 0) {
        vt_json_response(400, ['ok' => false, 'error' => 'invalid_token_type', 'message' => 'Wybierz typ żetonu.']);
    }

    $stmt = $pdo->prepare(
        'SELECT id, code, title, price_gross_pln, currency, max_upload_links, can_choose_trainer, is_active
         FROM token_types
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$tokenTypeId]);
    $type = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$type || (int)$type['is_active'] !== 1) {
        vt_json_response(404, ['ok' => false, 'error' => 'token_type_not_found', 'message' => 'Typ żetonu jest niedostępny.']);
    }

    $orderUuid = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff)
    );
    $amount = (float)$type['price_gross_pln'];
    $currency = strtoupper((string)$type['currency']);

    $insert = $pdo->prepare(
        'INSERT INTO token_orders
            (order_uuid, user_id, token_type_id, status, amount_gross_pln, currency, payment_provider, note, created_at, updated_at)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $insert->execute([
        $orderUuid,
        (int)$user['user_id'],
        $tokenTypeId,
        'pending',
        $amount,
        $currency,
        'p24',
        $note !== '' ? $note : null,
    ]);
    $orderId = (int)$pdo->lastInsertId();

    vt_json_response(201, [
        'ok' => true,
        'order' => [
            'id' => $orderId,
            'order_uuid' => $orderUuid,
            'status' => 'pending',
            'amount_gross_pln' => number_format($amount, 2, '.', ''),
            'currency' => $currency,
            'token_type' => [
                'id' => (int)$type['id'],
                'code' => (string)$type['code'],
                'title' => (string)$type['title'],
                'max_upload_links' => (int)$type['max_upload_links'],
                'can_choose_trainer' => (int)$type['can_choose_trainer'] === 1,
            ],
        ],
    ]);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_GET['action'] ?? 'list_types'));

if ($action === 'list_types' && $method === 'GET') vt_list_types($pdo);
if ($action === 'create_order' && $method === 'POST') vt_create_order($pdo);
if ($action === 'my_balance' && $method === 'GET') vt_my_balance($pdo);
if ($action === 'my_orders' && $method === 'GET') vt_my_orders($pdo);
if ($action === 'list_trainers' && $method === 'GET') vt_list_trainers($pdo);

vt_json_response(405, [
    'ok' => false,
    'error' => 'method_not_allowed',
    'message' => 'Nieobsługiwana akcja lub metoda.',
]);
