<?php
session_start();
$sessionId = $_GET['session'] ?? $_GET['p24_session_id'] ?? '';
$orderId = $_GET['p24_order_id'] ?? '';

$configPath = dirname(__DIR__) . '/config.json';
$cfg = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : [];
$p24 = $cfg['przelewy24'] ?? [];
$merchantId = $p24['merchantId'] ?? '';
$posId = $p24['posId'] ?? $merchantId;
$crc = $p24['crc'] ?? '';
$sandbox = isset($p24['sandbox']) ? $p24['sandbox'] : true;

if (!$sessionId || !$orderId) {
    echo 'Brak danych zamówienia';
    exit();
}

$sessionFile = __DIR__ . '/p24_sessions/' . basename($sessionId) . '.json';
if (!file_exists($sessionFile)) {
    echo 'Nie znaleziono danych sesji';
    exit();
}
$data = json_decode(file_get_contents($sessionFile), true);
$amountGrosz = intval($data['amount']) * 100;

$verify = [
    'p24_session_id' => $sessionId,
    'p24_order_id' => $orderId,
    'p24_amount' => $amountGrosz,
    'p24_currency' => 'PLN',
    'p24_sign' => md5($sessionId . '|' . $orderId . '|' . $amountGrosz . '|PLN|' . $crc),
    'p24_merchant_id' => $merchantId,
];
$url = $sandbox ? 'https://sandbox.przelewy24.pl/trnVerify' : 'https://secure.przelewy24.pl/trnVerify';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($verify));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$verifyDump = http_build_query($verify);
$line = sprintf(
    "%s VERIFY HTTP %s CURL:%s\nREQ:%s\nRESP:%s\n",
    date('c'),
    $httpCode,
    $curlError ?: 'OK',
    $verifyDump,
    trim($response)
);
file_put_contents(__DIR__ . '/p24_debug.log', $line, FILE_APPEND);
parse_str($response, $resp);
if (($resp['error'] ?? '1') !== '0') {
    echo 'Błąd weryfikacji płatności';
    exit();
}

// utwórz wydarzenie w kalendarzu
require_once __DIR__ . '/../vendor/autoload.php';
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;

$tokenPath = dirname(__DIR__) . '/token.json';
$client = new Client();
$client->setAuthConfig(__DIR__ . '/credentials.json');
$client->addScope(Calendar::CALENDAR);
$client->setAccessType('offline');
if (file_exists($tokenPath)) {
    $client->setAccessToken(json_decode(file_get_contents($tokenPath), true));
    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        } else {
            unlink($tokenPath);
            echo 'Brak tokenu Google';
            exit();
        }
    }
} else {
    echo 'Brak tokenu Google';
    exit();
}
$service = new Calendar($client);
$meetingType = $data['meetingType'];
$meetingTypes = $cfg['meetingTypes'] ?? [];
$key = strtolower($meetingType);
$emoji = $meetingTypes[$key]['emoji'] ?? '🗓️';
$displayName = $meetingTypes[$key]['name'] ?? ucfirst($meetingType);
$calendarTitle = $meetingTypes[$key]['calendar_title'] ?? $displayName;
$summary = trim(sprintf('%s %s%s', $emoji, $calendarTitle, $data['email'] ? ' - ' . $data['email'] : ''));

if (($meetingTypes[$key]['duration'] ?? '') === 'full') {
    $start = new DateTime($data['date'] . 'T09:00:00');
    $end = new DateTime($data['date'] . 'T17:00:00');
} else {
    $start = new DateTime($data['date'] . 'T' . $data['time'] . ':00');
    $end = new DateTime($start->format('c'));
    $minutes = intval($meetingTypes[$key]['duration'] ?? 60);
    $end->modify('+' . $minutes . ' minutes');
}
$event = new Event([
    'summary' => $summary,
    'start' => ['dateTime' => $start->format(DateTime::RFC3339)],
    'end' => ['dateTime' => $end->format(DateTime::RFC3339)],
    'attendees' => $data['email'] ? [['email' => $data['email']]] : []
]);
$service->events->insert('primary', $event);

unlink($sessionFile);

echo 'Płatność zweryfikowana i wydarzenie utworzone. Możesz zamknąć to okno.';
