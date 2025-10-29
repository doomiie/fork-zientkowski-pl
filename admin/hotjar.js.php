<?php
declare(strict_types=1);
// Public endpoint to emit Hotjar snippet if enabled in admin/site settings
header('Content-Type: application/javascript; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/db.php';

try {
    $row = $pdo->query('SELECT hotjar_enabled, hotjar_site_id FROM site_settings WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    http_response_code(204);
    exit;
}

$enabled = (int)($row['hotjar_enabled'] ?? 0) === 1;
$siteId = trim((string)($row['hotjar_site_id'] ?? ''));

if (!$enabled || $siteId === '') {
    http_response_code(204); // no content when disabled or missing id
    exit;
}

// Basic validation: allow only digits to avoid injection
if (!preg_match('/^\d+$/', $siteId)) {
    http_response_code(204);
    exit;
}

echo "(function(h,o,t,j,a,r){h.hj=h.hj||function(){(h.hj.q=h.hj.q||[]).push(arguments)};";
echo "h._hjSettings={hjid:" . $siteId . ",hjsv:6};a=o.getElementsByTagName('head')[0];";
echo "r=o.createElement('script');r.async=1;r.src=t+h._hjSettings.hjid+j+h._hjSettings.hjsv;";
echo 'a.appendChild(r);})(window,document,\'https://static.hotjar.com/c/hotjar-\',\'.js?sv=\');';
