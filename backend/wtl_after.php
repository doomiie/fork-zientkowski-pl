<?php
// Webhook called by WTL after payment is completed
// In addition to logging the received payload we also create a calendar
// reservation when a paid session was purchased.

date_default_timezone_set('Europe/Warsaw');

$log = __DIR__ . '/wtl_after.log';
$payload = file_get_contents('php://input');
file_put_contents($log, date('c') . " payload: " . $payload . "\n", FILE_APPEND);

// Helper for verbose logging
$logVerbose = function ($label, $data = null) use ($log) {
    $line = date('c') . " " . $label;
    if ($data !== null) {
        $line .= " " . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    file_put_contents($log, $line . "\n", FILE_APPEND);
};

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
            $logVerbose('loading token', $tokenPath);
            $client->setAccessToken(json_decode(file_get_contents($tokenPath), true));
            $logVerbose('current token', $client->getAccessToken());
            if ($client->isAccessTokenExpired()) {
                $logVerbose('token expired', null);
                if ($client->getRefreshToken()) {
                    $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                    file_put_contents($tokenPath, json_encode($client->getAccessToken()));
                    $logVerbose('token refreshed', $client->getAccessToken());
                } else {
                    unlink($tokenPath);
                    $logVerbose('token removed', null);
                }
            }
        }

        if ($client->getAccessToken()) {
            $service = new Google\Service\Calendar($client);
            try {
                $primary = $service->calendarList->get('primary');
                $logVerbose('using calendar', ['id' => $primary->id, 'summary' => $primary->summary]);
            } catch (Throwable $e) {
                $logVerbose('calendar info error', $e->getMessage());
            }

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

                $logVerbose('event payload', [
                    'summary' => $summary,
                    'start' => $start->format(DateTime::RFC3339),
                    'end'   => $end->format(DateTime::RFC3339),
                    'attendee' => $email,
                ]);

                if ($email) {
                    $event->setAttendees([['email' => $email]]);
                }

                try {
                    $created = $service->events->insert('primary', $event);
                    $logVerbose('event created', ['id' => $created->id, 'link' => $created->htmlLink]);
                } catch (Throwable $e) {
                    $logVerbose('event insert error', $e->getMessage());
                }
            } else {
                $logVerbose('missing full_date', $data);
            }
        } else {
            $logVerbose('no access token', null);
        }
    } catch (Throwable $e) {
        $logVerbose('error', $e->getMessage());
    }
}

header('Content-Type: text/plain');
echo "ok";
