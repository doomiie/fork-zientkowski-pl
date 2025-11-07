<?php

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
    $return = $_GET['state'] ?? $_GET['return'] ?? '/lp/ebookhumorwbiznesie.html';
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
      $ret = $_GET['return'] ?? ($_SERVER['HTTP_REFERER'] ?? '/lp/ebookhumorwbiznesie.html');
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

  $from = trim((string)($data['from'] ?? 'direct'));

  $service = new Google_Service_Sheets($client);
  $values = [ [ date('Y-m-d H:i:s'), $email, 'ebookhumorwbiznesie', $from ] ];
  $body = new Google_Service_Sheets_ValueRange([ 'values' => $values ]);
  $params = [ 'valueInputOption' => 'RAW' ];
  $appendResp = $service->spreadsheets_values->append($spreadsheetId, $range, $body, $params);
  $updatedRange = method_exists($appendResp, 'getUpdates') && $appendResp->getUpdates() ? $appendResp->getUpdates()->getUpdatedRange() : null;
  $updatedRows  = method_exists($appendResp, 'getUpdates') && $appendResp->getUpdates() ? $appendResp->getUpdates()->getUpdatedRows()  : null;

  // Send confirmation email synchronously and include status in response
  $mail = false; $mailError = null;
  try {
    // Resolve per-landing mail config (POST overrides, fallback to /lp/<landing>.json from Referer)
    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)), '/');
    $mailSubject = null; $mailTemplate = null; $mailAttachment = null;
    if (isset($data['mail_subject']) && is_string($data['mail_subject'])) { $mailSubject = trim($data['mail_subject']); }
    if (isset($data['mail_template']) && is_string($data['mail_template'])) { $mailTemplate = trim($data['mail_template']); }
    if (isset($data['mail_attachment']) && is_string($data['mail_attachment'])) { $mailAttachment = trim($data['mail_attachment']); }

    if ($mailSubject === null || $mailTemplate === null || $mailAttachment === null) {
      $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
      if ($ref !== '') {
        $path = parse_url($ref, PHP_URL_PATH) ?: '';
        $base = basename($path);
        if ($base !== '') {
          $name = preg_replace('/\.[^.]+$/', '', $base);
          $cfgPath = $docRoot . '/lp/' . $name . '.json';
          if (is_readable($cfgPath)) {
            $cfg = json_decode((string)file_get_contents($cfgPath), true);
            if (is_array($cfg) && isset($cfg['mail']) && is_array($cfg['mail'])) {
              $m = $cfg['mail'];
              if ($mailSubject === null && !empty($m['subject'])) $mailSubject = (string)$m['subject'];
              if ($mailTemplate === null && !empty($m['template'])) $mailTemplate = (string)$m['template'];
              if ($mailAttachment === null && !empty($m['attachment'])) $mailAttachment = (string)$m['attachment'];
            }
          }
        }
      }
    }

    if ($mailSubject === null) { $mailSubject = 'Dziękujemy za pobranie ebooka'; }
    if ($mailTemplate === null) { $mailTemplate = 'ebookhumorwbiznesie.html'; }

    $safeTpl = preg_replace('/[^A-Za-z0-9_.-]+/', '', (string)$mailTemplate);
    $tplPath = $docRoot . '/docs/mail/' . $safeTpl;
    $html = is_readable($tplPath) ? (string)file_get_contents($tplPath) : '<p>Dziękujemy za pobranie ebooka.</p><p><a href="{{DOWNLOAD_URL}}">Pobierz</a></p>';

    $downloadUrl = null; $atts = [];
    if ($mailAttachment) {
      $attPath = (string)$mailAttachment;
      if (str_starts_with($attPath, '/')) {
        $abs = $docRoot . $attPath;
        if (is_readable($abs)) {
          $atts[] = $abs;
        } else {
          $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
          $host = $_SERVER['HTTP_HOST'] ?? 'zientkowski.pl';
          $url = $scheme . '://' . $host . $attPath;
          $ctx = stream_context_create(['http' => ['timeout' => 15]]);
          $remote = @file_get_contents($url, false, $ctx);
          if (is_string($remote) && $remote !== '') {
            $atts[] = [ 'name' => 'ebook.pdf', 'mime' => 'application/pdf', 'content' => $remote ];
          }
          $downloadUrl = $url;
        }
        if ($downloadUrl === null) {
          $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
          $host = $_SERVER['HTTP_HOST'] ?? 'zientkowski.pl';
          $downloadUrl = $scheme . '://' . $host . $attPath;
        }
      }
    }

    // Placeholder substitution
    $html = str_replace(['{{EMAIL}}','{{DOWNLOAD_URL}}','{{DATE}}'], [htmlspecialchars($email, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'), (string)$downloadUrl, date('Y-m-d H:i')], $html);
    $text = trim(strip_tags(preg_replace('/<br\b[^>]*>/i', "\n", $html)));

    require_once __DIR__ . '/../admin/db.php';
    require_once __DIR__ . '/../admin/lib/Mailer.php';
    $mailer = new GmailOAuthMailer($pdo);
    $mailer->send($email, $mailSubject, $html, $text, $atts);
  } catch (Throwable $e) {
    $mailError = $e->getMessage();
  }
  $resp = ['ok' => true, 'range' => $range, 'updatedRange' => $updatedRange, 'updatedRows' => $updatedRows, 'mail' => $mail];
  if (!$mail) { $resp['mailError'] = $mailError; }
  echo json_encode($resp);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Błąd zapisu do Arkuszy Google.', 'detail' => $e->getMessage()]);
}

