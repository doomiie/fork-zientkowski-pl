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

if ($action === 'busy') {
    $date = $_GET['date'] ?? '';
    $duration = (int)($_GET['duration'] ?? 60);
    if (!$date) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing date']);
        exit();
    }
    $timeMin = $date . 'T00:00:00Z';
    $timeMax = $date . 'T23:59:59Z';
    $req = new FreeBusyRequest([
        'timeMin' => $timeMin,
        'timeMax' => $timeMax,
        'items' => [['id' => 'primary']]
    ]);
    $res = $service->freebusy->query($req);
    $busy = $res->getCalendars()['primary']['busy'] ?? [];
    $slots = [];
    foreach ($busy as $b) {
        $start = new DateTime($b['start']);
        $end = new DateTime($b['end']);
        $cursor = clone $start;
        while ($cursor < $end) {
            $slots[] = $cursor->format('H:i');
            $cursor->modify("+{$duration} minutes");
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['busy' => $slots]);
    exit();
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $summary = $data['summary'] ?? 'Spotkanie';
    $start = new DateTime($data['start']);
    $end = new DateTime($data['end']);
    $event = new Event([
        'summary' => $summary,
        'start' => ['dateTime' => $start->format(DateTime::RFC3339)],
        'end' => ['dateTime' => $end->format(DateTime::RFC3339)],
    ]);
    if (!empty($data['attendees'])) {
        $event->setAttendees(array_map(function ($e) { return ['email' => $e]; }, $data['attendees']));
    }
    $created = $service->events->insert('primary', $event);
    header('Content-Type: application/json');
    echo json_encode($created);
    exit();
}

http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['error' => 'Invalid request']);
