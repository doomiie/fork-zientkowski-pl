<?php
require_once __DIR__ . '/vendor/autoload.php';

// Ustawienia stałe
$calendarId = 'primary';
$date = '2025-07-05'; // na sztywno
$startHour = 9;
$endHour = 16;
$intervalMinutes = 30;

// Autoryzacja Google
$client = new Google_Client();
$client->setApplicationName('Google Calendar API PHP');
$client->setScopes(Google_Service_Calendar::CALENDAR_READONLY);
$client->setAuthConfig(__DIR__ . '/../credentials.json');
$client->setAccessType('offline');

// Załaduj zapisany token
$tokenPath = __DIR__ . '/../token.json';
if (!file_exists($tokenPath)) {
    exit("Brakuje pliku token.json. Zaloguj się najpierw.\n");
}
$accessToken = json_decode(file_get_contents($tokenPath), true);
$client->setAccessToken($accessToken);

// Odśwież token jeśli wygasł
if ($client->isAccessTokenExpired()) {
    if ($client->getRefreshToken()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    } else {
        exit("Token wygasł i brak możliwości odświeżenia.\n");
    }
}

// Połączenie z API kalendarza
$service = new Google_Service_Calendar($client);

$timeMin = new DateTime("$date 00:00:00", new DateTimeZone('Europe/Warsaw'));
$timeMax = new DateTime("$date 23:59:59", new DateTimeZone('Europe/Warsaw'));

// Zapytanie freeBusy
$freeBusyRequest = new Google_Service_Calendar_FreeBusyRequest([
    'timeMin' => $timeMin->format(DateTime::RFC3339),
    'timeMax' => $timeMax->format(DateTime::RFC3339),
    'timeZone' => 'Europe/Warsaw',
    'items' => [['id' => $calendarId]],
]);

$response = $service->freebusy->query($freeBusyRequest);
$busySlots = $response->getCalendars()[$calendarId]->getBusy();

// Tworzenie slotów
$availableSlots = [];
$dt = new DateTime("$date $startHour:00", new DateTimeZone('Europe/Warsaw'));
$end = new DateTime("$date $endHour:00", new DateTimeZone('Europe/Warsaw'));

while ($dt < $end) {
    $slotStart = clone $dt;
    $slotEnd = clone $dt;
    $slotEnd->modify("+{$intervalMinutes} minutes");

    $isBusy = false;
    foreach ($busySlots as $busy) {
        $busyStart = new DateTime($busy->start);
        $busyEnd = new DateTime($busy->end);
        if ($slotStart < $busyEnd && $slotEnd > $busyStart) {
            $isBusy = true;
            break;
        }
    }

    if (!$isBusy) {
        $availableSlots[] = $slotStart->format('H:i');
    }

    $dt->modify("+{$intervalMinutes} minutes");
}

header('Content-Type: application/json');
echo json_encode($availableSlots);
