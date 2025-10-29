<?php
session_start();

$tokenPath = dirname(__DIR__) . '/token.json';

require_once __DIR__ . '/../vendor/autoload.php';

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\FreeBusyRequest;

// Configure Google Client
$client = new Client();
$client->setAuthConfig(__DIR__ . '/credentials.json');
$client->setRedirectUri('https://zientkowski.pl/backend/calendar.php');
$client->addScope(Calendar::CALENDAR);
$client->setAccessType('offline');

// Handle OAuth callback
if (isset($_GET['code'])) {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    if (!isset($token['error'])) {
        file_put_contents($tokenPath, json_encode($token));
        $client->setAccessToken($token);
    }
    $return = $_SESSION['return'] ?? '/sesja.html';
    header('Location: ' . $return);
    exit();
}

// Ensure we have a token
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

if (!$client->getAccessToken()) {
    $_SESSION['return'] = $_GET['return'] ?? '/sesja.html';
    $authUrl = $client->createAuthUrl();
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['authUrl' => $authUrl]);
    exit();
}

$service = new Calendar($client);
$action = $_GET['action'] ?? '';

// Load configuration
$configPath = dirname(__DIR__) . '/config.json';
$cfg = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : [];
$meetingTypes = $cfg['meetingTypes'] ?? [];
$workingHours = $cfg['workingHours'] ?? [];

if ($action === 'busy') {
    $date = $_GET['date'] ?? '';
    $duration = (int)($_GET['duration'] ?? 60);
    if (!$date) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing date']);
        exit();
    }
    $timeMin = $date . 'T00:00:00Z';
    $fullDay = isset($_GET['fullDay']) && $_GET['fullDay'] == '1';
    if ($fullDay) {
        $timeMax = $date . 'T23:59:59Z';
    } else {
        $dayKey = strtolower(date('D', strtotime($date))); // mon, tue, ...
        $end = $workingHours[$dayKey]['end'] ?? '17:00';
        $dt = new DateTime("$date $end", new DateTimeZone('Europe/Warsaw'));
        $dt->setTimezone(new DateTimeZone('UTC'));
        $timeMax = $dt->format('Y-m-d\TH:i:s\Z');
    }
    $req = new FreeBusyRequest([
        'timeMin' => $timeMin,
        'timeMax' => $timeMax,
        'items' => [['id' => 'primary']]
    ]);
    $res = $service->freebusy->query($req);
    $busy = $res->getCalendars()['primary']->getBusy();
    $slots = [];
    foreach ($busy as $b) {
        $start = new DateTime($b->getStart(), new DateTimeZone('UTC'));
        $start->setTimezone(new DateTimeZone('Europe/Warsaw'));

        $end = new DateTime($b->getEnd(), new DateTimeZone('UTC'));
        $end->setTimezone(new DateTimeZone('Europe/Warsaw'));

        $cursor = clone $start;
        while ($cursor < $end) {
            $slots[] = $cursor->format('H:i');
            $cursor->modify("+{$duration} minutes");
        }
    }
    header('Content-Type: application/json');
    //echo json_encode(['busy' => $slots]);
    echo json_encode(['busy' => $slots, 'res' => $res]);
    exit();
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $meetingType = $data['meetingType'] ?? $data['summary'] ?? 'spotkanie';
    $attendees = $data['attendees'] ?? [];
    $email = $attendees[0] ?? '';

    $key = strtolower($meetingType);
    $emoji = isset($meetingTypes[$key]['emoji']) ? $meetingTypes[$key]['emoji'] : '🗓️';
    $displayName = isset($meetingTypes[$key]['name']) ? $meetingTypes[$key]['name'] : ucfirst($meetingType);
    $calendarTitle = isset($meetingTypes[$key]['calendar_title']) ? $meetingTypes[$key]['calendar_title'] : $displayName;

    $summary = trim(sprintf('%s %s%s', $emoji, $calendarTitle, $email ? ' - ' . $email : ''));

    $start = new DateTime($data['start']);
    $end = new DateTime($data['end']);
    $event = new Event([
        'summary' => $summary,
        'start' => ['dateTime' => $start->format(DateTime::RFC3339)],
        'end' => ['dateTime' => $end->format(DateTime::RFC3339)],
        // Request Google Meet conference on creation
        'conferenceData' => [
            'createRequest' => [
                'requestId' => bin2hex(random_bytes(8)),
                'conferenceSolutionKey' => [ 'type' => 'hangoutsMeet' ],
            ],
        ],
    ]);
    if (!empty($attendees)) {
        $event->setAttendees(array_map(function ($e) {
            return ['email' => $e];
        }, $attendees));
    }
    // Pass conferenceDataVersion to enable conference creation
    $created = $service->events->insert('primary', $event, ['conferenceDataVersion' => 1]);

    // Extract Google Meet link if available
    $meetLink = '';
    if (method_exists($created, 'getHangoutLink')) {
        $meetLink = (string)$created->getHangoutLink();
    }
    if (!$meetLink && method_exists($created, 'getConferenceData')) {
        $conf = $created->getConferenceData();
        if ($conf && method_exists($conf, 'getEntryPoints')) {
            foreach ((array)$conf->getEntryPoints() as $ep) {
                if (method_exists($ep, 'getEntryPointType') && $ep->getEntryPointType() === 'video') {
                    if (method_exists($ep, 'getUri')) {
                        $meetLink = (string)$ep->getUri();
                        break;
                    }
                }
            }
        }
    }

    // Send notification email about the reservation
    try {
        require_once __DIR__ . '/../admin/db.php';
        require_once __DIR__ . '/../admin/lib/Mailer.php';
        $mailer = new GmailOAuthMailer($pdo);

        $startPl = new DateTime($data['start']);
        $startPl->setTimezone(new DateTimeZone('Europe/Warsaw'));
        $endPl = new DateTime($data['end']);
        $endPl->setTimezone(new DateTimeZone('Europe/Warsaw'));

        $whenTxt = $startPl->format('Y-m-d H:i') . ' – ' . $endPl->format('H:i') . ' (Europe/Warsaw)';
        $subject = 'Nowa rezerwacja: ' . $displayName . ' ' . $startPl->format('Y-m-d H:i');
        $html = '<p>Nowa rezerwacja terminu.</p>'
              . '<p><strong>Typ:</strong> ' . htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
              . '<p><strong>Kiedy:</strong> ' . htmlspecialchars($whenTxt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
              . '<p><strong>E-mail uczestnika:</strong> ' . htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        if (!empty($meetLink)) {
            $safeLink = htmlspecialchars($meetLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<p><strong>Google Meet:</strong> <a href="' . $safeLink . '">' . $safeLink . '</a></p>';
        }

        $mailer->send('jerzy+rezerwacje@zientkowski.pl', $subject, $html);
    } catch (Throwable $e) {
        // Swallow errors to not break API response
    }
    header('Content-Type: application/json');
    echo json_encode([
        'event' => $created,
        'meetLink' => $meetLink,
    ]);
    exit();
}

http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['error' => 'Invalid request']);
