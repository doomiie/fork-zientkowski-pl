<?php
declare(strict_types=1);
// Public endpoint to emit Facebook Pixel snippet if enabled
header('Content-Type: application/javascript; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/db.php';

try {
    $row = $pdo->query('SELECT fb_pixel_enabled, fb_pixel_id FROM site_settings WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    http_response_code(204);
    exit;
}

$enabled = (int)($row['fb_pixel_enabled'] ?? 0) === 1;
$pixelId = trim((string)($row['fb_pixel_id'] ?? ''));

if (!$enabled || $pixelId === '') {
    http_response_code(204);
    exit;
}

// Basic validation: digits only
if (!preg_match('/^\d+$/', $pixelId)) {
    http_response_code(204);
    exit;
}

// Standard Meta Pixel snippet
echo "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?";
echo "n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;";
echo "n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;";
echo "t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');";
echo "fbq('init','" . $pixelId . "');fbq('track','PageView');";

