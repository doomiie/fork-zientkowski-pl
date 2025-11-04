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
// Prefer environment variables; allow optional fallback via backend/secrets.php (not committed)
if (file_exists(__DIR__ . '/secrets.php')) {
  // expects optional $MC_API_KEY and $MC_LIST_ID
  @include __DIR__ . '/secrets.php';
}
$apiKey = getenv('APP_MC_API_KEY') ?: (isset($MC_API_KEY) ? (string)$MC_API_KEY : '');
$listId = getenv('APP_MC_LIST_ID') ?: (isset($MC_LIST_ID) ? (string)$MC_LIST_ID : '0198f94b4b');
$tagName = 'ebook_ahwb_pobrany';
$doubleOptIn = true; // włączony

if ($apiKey === '' || strpos($apiKey, '-') === false) {
  http_response_code(500);
  echo json_encode(['error' => 'Brak klucza Mailchimp. Ustaw APP_MC_API_KEY (env) lub dodaj backend/secrets.php z $MC_API_KEY.']);
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

if ($res['status'] >= 400) {
  // Return MC error if present
  $msg = 'Błąd zapisu w Mailchimp.';
  if (is_array($res['body']) && !empty($res['body']['detail'])) { $msg = (string)$res['body']['detail']; }
  http_response_code(400);
  echo json_encode(['error' => $msg, 'mc' => $res['body']]);
  exit;
}

// Determine member status and reconcile according to requirements
$currentStatus = '';
if (is_array($res['body']) && isset($res['body']['status'])) {
  $currentStatus = (string)$res['body']['status'];
}

// If already subscribed, just add tag; no confirmation e-mail will be sent in this case
if ($currentStatus === 'subscribed') {
  $tagUrl = $base . "/lists/" . rawurlencode($listId) . "/members/" . $subscriberHash . "/tags";
  $tagPayload = [ 'tags' => [[ 'name' => $tagName, 'status' => 'active' ]] ];
  $tagRes = mc_request('POST', $tagUrl, $tagPayload, $apiKey);
  echo json_encode(['ok' => true, 'mc_status' => 'subscribed', 'tagged' => ($tagRes['status'] < 400)]);
  exit;
}

// If previously unsubscribed/cleaned/archived, request confirmation by setting status pending
if (in_array($currentStatus, ['unsubscribed','cleaned','archived'], true)) {
  $patchUrl = $base . "/lists/" . rawurlencode($listId) . "/members/" . $subscriberHash;
  $patch = mc_request('PATCH', $patchUrl, ['status' => 'pending'], $apiKey);
  if ($patch['status'] >= 400) {
    $msg = 'Nie udało się wysłać ponownego potwierdzenia.';
    if (is_array($patch['body']) && !empty($patch['body']['detail'])) { $msg = (string)$patch['body']['detail']; }
    http_response_code(400);
    echo json_encode(['error' => $msg, 'mc' => $patch['body']]);
    exit;
  }
  // proceed to tagging after status update (tagging may still apply only after confirmation)
}

// Add tag (active) for new/pending subscribers
$tagUrl = $base . "/lists/" . rawurlencode($listId) . "/members/" . $subscriberHash . "/tags";
$tagPayload = [ 'tags' => [[ 'name' => $tagName, 'status' => 'active' ]] ];
$tagRes = mc_request('POST', $tagUrl, $tagPayload, $apiKey);
// Ignore tag errors for now; proceed

// Send ebook link via configured mailer (best-effort)
try {
    require_once __DIR__ . '/../admin/db.php';
    require_once __DIR__ . '/../admin/lib/Mailer.php';
    $mailer = new GmailOAuthMailer($pdo);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'zientkowski.pl';
    $baseUrl = $scheme . '://' . $host;
    $fileName = 'Autentyczny Humor w Biznesie, 2025, ebook Jerzy Zientkowski.pdf';
    $fileUrl = $baseUrl . '/docs/' . rawurlencode($fileName);
    $subject = 'Oto Twój Ebook';
    $safeUrl = htmlspecialchars($fileUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<p>Dziękujemy za zapis.</p><p><a href="' . $safeUrl . '">Kliknij tutaj, aby pobrać ebook</a>.</p>';
    $text = "Dziękujemy za zapis.\nPobierz ebook: " . $fileUrl;
    $mailer->send($email, $subject, $html, $text);
} catch (Throwable $e) {
    // ignore mail errors, do not block API response
}

echo json_encode(['ok' => true, 'mc_status' => ($currentStatus !== '' ? $currentStatus : 'pending')]);
