<?php
// Webhook called by WTL after payment is completed
// In addition to logging the received payload we also create a calendar
// reservation when a paid session was purchased.

date_default_timezone_set('Europe/Warsaw');

$log = __DIR__ . '/../wtl_after.log';
$payload = file_get_contents('php://input');
file_put_contents($log, date('c') . " " . $payload . "\n", FILE_APPEND);

$data = json_decode($payload, true);
if ($data) {
    try {
        require_once __DIR__ . '/../vendor/autoload.php';

        $tokenPath = dirname(__DIR__) . '/token.json';

        $client = new Google\Client();
        $client->setAuthConfig(__DIR__ . '/credentials.json');
        $client->addScope(Google\Service\Calendar::CALENDAR);
        $client->setAccessType('offline');

        if (file_exists($tokenPath)) {
            $client->setAccessToken(json_decode(file_get_contents($tokenPath), true));
            if ($client->isAccessTokenExpired()) {
                if ($client->getRefreshToken()) {
                    $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                    file_put_contents($tokenPath, json_encode($client->getAccessToken()));
                } else {
                    unlink($tokenPath);
                }
            }
        }

        if ($client->getAccessToken()) {
            $service = new Google\Service\Calendar($client);

            $cfgPath = dirname(__DIR__) . '/config.json';
            $cfg = file_exists($cfgPath) ? json_decode(file_get_contents($cfgPath), true) : [];
            $meetingTypes = $cfg['meetingTypes'] ?? [];
            $workingHours = $cfg['workingHours'] ?? [];

            $meetingKey = strtolower($data['product_title'] ?? '');
            $mt = $meetingTypes[$meetingKey] ?? [];

            $email = $data['email'] ?? '';
            $startStr = $data['full_date'] ?? '';
            if ($startStr) {
                $start = new DateTime($startStr, new DateTimeZone('Europe/Warsaw'));
                $end = clone $start;

                $duration = $mt['duration'] ?? 60;
                if ($duration === 'full') {
                    $dayKey = strtolower($start->format('D'));
                    $wh = $workingHours[$dayKey] ?? ['start' => '09:00', 'end' => '17:00'];
                    $start->setTime(...explode(':', $wh['start']));
                    $end->setTime(...explode(':', $wh['end']));
                } else {
                    $end->modify('+' . (int)$duration . ' minutes');
                }

                $summary = trim(sprintf(
                    '%s %s%s',
                    $mt['emoji'] ?? '🗓️',
                    $mt['calendar_title'] ?? ($mt['name'] ?? 'Spotkanie'),
                    $email ? ' - ' . $email : ''
                ));

                $event = new Google\Service\Calendar\Event([
                    'summary' => $summary,
                    'start' => ['dateTime' => $start->format(DateTime::RFC3339)],
                    'end' => ['dateTime' => $end->format(DateTime::RFC3339)],
                ]);

                if ($email) {
                    $event->setAttendees([['email' => $email]]);
                }

                $created = $service->events->insert('primary', $event);
                file_put_contents($log, "created event " . $created->id . "\n", FILE_APPEND);
            }
        } else {
            file_put_contents($log, "no access token\n", FILE_APPEND);
        }
    } catch (Throwable $e) {
        file_put_contents($log, "error: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

header('Content-Type: text/plain');
echo "ok";
