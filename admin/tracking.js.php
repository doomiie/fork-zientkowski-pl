<?php
declare(strict_types=1);
// Combined tracking loader: emits Hotjar and Facebook Pixel if enabled
header('Content-Type: application/javascript; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/db.php';

try {
    $row = $pdo->query('SELECT hotjar_enabled, hotjar_site_id, fb_pixel_enabled, fb_pixel_id, mailchimp_enabled, mailchimp_url FROM site_settings WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    http_response_code(204);
    exit;
}

// Mailchimp — emit exactly as inline <script id="mcjs"> inside <head>
$mcEnabled = (int)($row['mailchimp_enabled'] ?? 0) === 1;
$mcUrl = trim((string)($row['mailchimp_url'] ?? ''));
if ($mcEnabled && $mcUrl !== '' && preg_match('#^https://[^\s\"\']+$#', $mcUrl)) {
    echo "(function(){";
    echo "try{var h=document.getElementsByTagName('head')[0]||document.documentElement;";
    echo "var s=document.createElement('script');s.id='mcjs';s.type='text/javascript';";
    echo "s.text='!function(c,h,i,m,p){m=c.createElement(h),p=c.getElementsByTagName(h)[0],m.async=1,m.src=i,p.parentNode.insertBefore(m,p)}(document,\\\"script\\\",\\\"" . addslashes($mcUrl) . "\\\");';";
    echo "h.appendChild(s);}catch(e){}})();\n";
}
// Hotjar
$hjEnabled = (int)($row['hotjar_enabled'] ?? 0) === 1;
$hjId = trim((string)($row['hotjar_site_id'] ?? ''));
if ($hjEnabled && $hjId !== '' && preg_match('/^\d+$/', $hjId)) {
    echo "(function(h,o,t,j,a,r){h.hj=h.hj||function(){(h.hj.q=h.hj.q||[]).push(arguments)};";
    echo "h._hjSettings={hjid:" . $hjId . ",hjsv:6};a=o.getElementsByTagName('head')[0];";
    echo "r=o.createElement('script');r.async=1;r.src=t+h._hjSettings.hjid+j+h._hjSettings.hjsv;";
    echo 'a.appendChild(r);})(window,document,\'https://static.hotjar.com/c/hotjar-\',\'.js?sv=\');';
    echo "\n";
}

// Facebook Pixel
$fbEnabled = (int)($row['fb_pixel_enabled'] ?? 0) === 1;
$fbId = trim((string)($row['fb_pixel_id'] ?? ''));
if ($fbEnabled && $fbId !== '' && preg_match('/^\d+$/', $fbId)) {
    echo "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?";
    echo "n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;";
    echo "n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;";
    echo "t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');";
    echo "fbq('init','" . $fbId . "');fbq('track','PageView');";
    echo "\n";
}
