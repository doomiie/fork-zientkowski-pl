<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Read input (JSON or form)
$raw = file_get_contents('php://input') ?: '';
$data = [];
if ($raw !== '') {
  $tmp = json_decode($raw, true);
  if (is_array($tmp)) { $data = $tmp; }
}
if (!isset($data['email'])) {
  $data['email'] = $_POST['email'] ?? $_POST['EMAIL'] ?? '';
}

$email = trim((string)($data['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(['error' => 'Podaj poprawny adres e-mail.']);
  exit;
}

// Configuration
$apiKey = getenv('APP_MC_API_KEY') ?: '';
$listId = getenv('APP_MC_LIST_ID') ?: '0198f94b4b';
$tagName = 'ebook_ahwb_pobrany';
$doubleOptIn = true; // włączony

if ($apiKey === '' || strpos($apiKey, '-') === false) {
  http_response_code(500);
  echo json_encode(['error' => 'Brak klucza Mailchimp (APP_MC_API_KEY). Ustaw zmienną środowiskową.']);
  exit;
}
if ($listId === '') {
  http_response_code(500);
  echo json_encode(['error' => 'Brak identyfikatora listy (APP_MC_LIST_ID). Ustaw zmienną środowiskową.']);
  exit;
}

$dc = substr($apiKey, strrpos($apiKey, '-') + 1); // data center, np. us7
$base = "https://{$dc}.api.mailchimp.com/3.0";

function mc_request(string $method, string $url, array $body = null, string $apiKey = ''): array {
  $ch = curl_init($url);
  $headers = [
    'Content-Type: application/json',
    'Authorization: apikey ' . $apiKey,
  ];
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  if ($body !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
  }
  $resp = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  $json = null;
  if ($resp !== false && $resp !== null && $resp !== '') {
    $tmp = json_decode($resp, true);
    if (is_array($tmp)) { $json = $tmp; }
  }
  return ['status' => $status, 'body' => $json, 'raw' => $resp, 'error' => $err];
}

// Compute subscriber hash
$subscriberHash = md5(strtolower($email));

// Upsert member
$status = $doubleOptIn ? 'pending' : 'subscribed';
$upsertUrl = $base . "/lists/" . rawurlencode($listId) . "/members/" . $subscriberHash;
$payload = [
  'email_address' => $email,
  'status_if_new' => $status,
];
$res = mc_request('PUT', $upsertUrl, $payload, $apiKey);

// If explicit status change needed for existing member (e.g., was unsubscribed), do not force it here; leave as-is.
if ($res['status'] >= 400) {
  // Return MC error if present
  $msg = 'Błąd zapisu w Mailchimp.';
  if (is_array($res['body']) && !empty($res['body']['detail'])) { $msg = (string)$res['body']['detail']; }
  http_response_code(400);
  echo json_encode(['error' => $msg, 'mc' => $res['body']]);
  exit;
}

// Add tag (active)
$tagUrl = $base . "/lists/" . rawurlencode($listId) . "/members/" . $subscriberHash . "/tags";
$tagPayload = [ 'tags' => [[ 'name' => $tagName, 'status' => 'active' ]] ];
$tagRes = mc_request('POST', $tagUrl, $tagPayload, $apiKey);
// Ignore tag errors for now; proceed

echo json_encode(['ok' => true]);
