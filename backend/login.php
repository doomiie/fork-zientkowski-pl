<?php
require_once __DIR__ . '/../vendor/autoload.php';

$client = new Google_Client();
$client->setApplicationName('Google Calendar API PHP');
$client->setScopes(Google_Service_Calendar::CALENDAR);
$client->setAuthConfig(__DIR__ . '/credentials.json');
$client->setAccessType('offline');
$client->setPrompt('consent');
$client->setRedirectUri('https://zientkowski.pl/new/backend/login.php');


$tokenPath = __DIR__ . '/../token.json';

if (!isset($_GET['code'])) {
    // Pierwszy krok – przekieruj do Google
    $authUrl = $client->createAuthUrl();
    header('Location: ' . filter_var($authUrl, FILTER_SANITIZE_URL));
    exit;
} else {
    // Po powrocie z Google
    $accessToken = $client->fetchAccessTokenWithAuthCode($_GET['code']);

    if (isset($accessToken['error'])) {
        exit('Błąd autoryzacji: ' . htmlspecialchars($accessToken['error']));
    }

    // Zapisz token
    file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    echo "✅ Zapisano token w <code>token.json</code>.<br><a href='test.php'>Sprawdź sloty</a>";
}
