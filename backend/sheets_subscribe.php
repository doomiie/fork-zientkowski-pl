<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Config: Spreadsheet ID + Range from env or secrets.php
if (file_exists(__DIR__ . '/secrets.php')) {
  @include __DIR__ . '/secrets.php';
}
$spreadsheetId = getenv('APP_SHEET_ID') ?: (isset($GSHEET_SPREADSHEET_ID) ? (string)$GSHEET_SPREADSHEET_ID : '');
$range = getenv('APP_SHEET_RANGE') ?: (isset($GSHEET_RANGE) ? (string)$GSHEET_RANGE : 'Subskrypcje!A:C');

if ($spreadsheetId === '') {
  http_response_code(500);
  echo json_encode(['error' => 'Brak konfiguracji Google Sheet. Ustaw APP_SHEET_ID (env) lub dodaj backend/secrets.php z $GSHEET_SPREADSHEET_ID.']);
  exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

try {
  // Support older google/apiclient versions (pre-2.x) that use non-namespaced classes
  $client = new Google_Client();
  $client->setApplicationName('Zientkowski Sheets Subscribe');
  $client->setAuthConfig(__DIR__ . '/credentials.json');
  // Build redirect dynamically to cover www/non-www or staging hosts
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'zientkowski.pl';
  $redirectUri = $scheme . '://' . $host . '/backend/sheets_subscribe.php';
  $client->setRedirectUri($redirectUri);
  $client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
  $client->setAccessType('offline');

  $tokenPath = __DIR__ . '/token_sheets.json';

  // Handle OAuth callback
  if (isset($_GET['code'])) {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    if (!isset($token['error'])) {
      file_put_contents($tokenPath, json_encode($token));
      $client->setAccessToken($token);
    }
    $return = $_GET['state'] ?? $_GET['return'] ?? '/ebookhumorwbiznesie.html';
    header('Location: ' . $return);
    exit;
  }

  if (file_exists($tokenPath)) {
    $client->setAccessToken(json_decode((string)file_get_contents($tokenPath), true));
  }

  if ($client->isAccessTokenExpired()) {
    if ($client->getRefreshToken()) {
      $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
      file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    } else {
      // Try to preserve a return target for UX; prefer explicit ?return=
      $ret = $_GET['return'] ?? ($_SERVER['HTTP_REFERER'] ?? '/ebookhumorwbiznesie.html');
      // Carry return via OAuth state, then build auth URL
      $client->setState($ret);
      $authUrl = $client->createAuthUrl();
      http_response_code(401);
      echo json_encode(['error' => 'auth_required', 'authUrl' => $authUrl, 'return' => $ret, 'redirectUri' => $redirectUri]);
      exit;
    }
  }
  // If not a POST, return current auth status (useful for admin start)
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['ok' => true, 'status' => 'authorized']);
    return;
  }

  // POST: read input and append to Google Sheets
  $raw = file_get_contents('php://input') ?: '';
  $data = [];
  if ($raw !== '') {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) { $data = $tmp; }
  }
  if (!isset($data['email'])) {
    $data['email'] = $_POST['email'] ?? $_POST['EMAIL'] ?? '';
  }
  $rawEmail = trim((string)($data['email'] ?? ''));
  $email = filter_var($rawEmail, FILTER_SANITIZE_EMAIL);
  $email = trim($email);
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Podaj poprawny adres e-mail.']);
    exit;
  }

  $service = new Google_Service_Sheets($client);
  $values = [ [ date('Y-m-d H:i:s'), $email, 'ebookhumorwbiznesie' ] ];
  $body = new Google_Service_Sheets_ValueRange([ 'values' => $values ]);
  $params = [ 'valueInputOption' => 'RAW' ];
  $service->spreadsheets_values->append($spreadsheetId, $range, $body, $params);

  echo json_encode(['ok' => true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Błąd zapisu do Arkuszy Google.', 'detail' => $e->getMessage()]);
}
