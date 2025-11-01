<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_login();
require_admin();

$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $error = 'Nieprawidłowy token bezpieczeństwa.';
    } else {
        try {
            $enabled = isset($_POST['hotjar_enabled']) ? 1 : 0;
            $siteId = trim((string)($_POST['hotjar_site_id'] ?? ''));
            $fbEnabled = isset($_POST['fb_pixel_enabled']) ? 1 : 0;
            $fbId = trim((string)($_POST['fb_pixel_id'] ?? ''));
            $stmt = $pdo->prepare('UPDATE site_settings SET hotjar_enabled = ?, hotjar_site_id = ?, fb_pixel_enabled = ?, fb_pixel_id = ? WHERE id = 1');
            $stmt->execute([
              $enabled,
              $siteId !== '' ? $siteId : null,
              $fbEnabled,
              $fbId !== '' ? $fbId : null,
            ]);
            // Update Mailchimp independently (accept snippet or URL)
            try {
                $mcEnabled = isset($_POST['mailchimp_enabled']) ? 1 : 0;
                $mcRaw = trim((string)($_POST['mailchimp_code'] ?? ''));
                $mcUrl = '';
                if ($mcRaw !== '') {
                    if (preg_match('#https://chimpstatic\\.com/[^"\']+#', $mcRaw, $m)) {
                        $mcUrl = $m[0];
                    } elseif (preg_match('#^https://[^\s]+$#', $mcRaw)) {
                        $mcUrl = $mcRaw;
                    }
                }
                $stmt2 = $pdo->prepare('UPDATE site_settings SET mailchimp_enabled = ?, mailchimp_url = ? WHERE id = 1');
                $stmt2->execute([
                  $mcEnabled,
                  $mcUrl !== '' ? $mcUrl : null,
                ]);
            } catch (Throwable $e2) {
                // ignore if column missing; migration may not be applied yet
            }
            $ok = 'Zapisano ustawienia.';
        } catch (Throwable $e) {
            $error = 'Błąd zapisu ustawień.';
        }
    }
}

// Load settings
try {
    $row = $pdo->query('SELECT * FROM site_settings WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $row = [];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ustawienia serwisu</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; }
    header { background:#040327; color:#fff; padding:16px 24px; display:flex; justify-content:space-between; align-items:center; }
    main { max-width:1000px; margin:24px auto; padding:0 24px; }
    .card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 12px 30px rgba(0,0,0,.06); }
    label { display:block; font-weight:600; margin:12px 0 6px; }
    input[type=text] { width:100%; padding:12px 14px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; }
    .row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    a.btn { display:inline-block; background:#fff; color:#040327; border:1px solid #9ca3af; padding:8px 12px; border-radius:8px; text-decoration:none; font-weight:600; }
    button { background:#040327; color:#fff; border:0; border-radius:10px; padding:12px 16px; font-weight:600; cursor:pointer; }
    .error { background:#fee2e2; color:#7f1d1d; padding:10px 12px; border-radius:8px; margin:12px 0; font-size:14px; }
    .ok { background:#ecfdf5; color:#065f46; padding:10px 12px; border-radius:8px; margin:12px 0; font-size:14px; }
    .muted { color:#6b7280; font-size:13px; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
  <meta name="robots" content="noindex,nofollow">
  </head>
  <body>
    <header>
      <div><strong>Panel administracyjny</strong></div>
      <div>
        Zalogowano jako: <?php echo htmlspecialchars(current_user_email(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
        &nbsp;|&nbsp; <a class="btn" href="index.php">Powrót</a>
        &nbsp;|&nbsp; <a class="btn" href="logout.php">Wyloguj</a>
      </div>
    </header>
    <main>
      <div class="card">
        <h1>Ustawienia serwisu</h1>
        <?php if ($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="ok"><?php echo $ok; ?></div><?php endif; ?>
        <form method="post" action="site.php" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
          <div style="margin:8px 0 16px;">
            <label><input type="checkbox" name="hotjar_enabled" value="1" <?php echo !empty($row['hotjar_enabled']) ? 'checked' : ''; ?>> Włącz Hotjar</label>
          </div>
          <label for="hotjar_site_id">Hotjar Site ID</label>
          <input type="text" id="hotjar_site_id" name="hotjar_site_id" placeholder="np. 1234567" value="<?php echo htmlspecialchars((string)($row['hotjar_site_id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          <div class="muted" style="margin-top:8px;">Wklej numer Site ID z Hotjar (bez dodatkowych znaków). Gdy włączone, skrypt zostanie dołączony na stronach z wczytanym plikiem <code>/admin/hotjar.js.php</code>.</div>
          <div style="margin-top:12px;">
            <a class="btn" target="_blank" rel="noopener" href="https://insights.hotjar.com/sites/568048/dashboard/hNXeibUGf7qaRkcnzHGRCy-Site-overview">Otwórz Hotjar dashboard</a>
          </div>
          <hr style="margin:16px 0; border:none; border-top:1px solid #e5e7eb;">
          <div style="margin:8px 0 16px;">
            <label><input type="checkbox" name="fb_pixel_enabled" value="1" <?php echo !empty($row['fb_pixel_enabled']) ? 'checked' : ''; ?>> Włącz Facebook Pixel</label>
          </div>
          <label for="fb_pixel_id">Facebook Pixel ID</label>
          <input type="text" id="fb_pixel_id" name="fb_pixel_id" placeholder="np. 123456789012345" value="<?php echo htmlspecialchars((string)($row['fb_pixel_id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          <div class="muted" style="margin-top:8px;">Wklej numer Pixel ID z Meta Events Manager. Gdy włączone, skrypt będzie dostępny pod <code>/admin/fbpixel.js.php</code>.</div>
          <div style="margin-top:12px;">
            <a class="btn" target="_blank" rel="noopener" href="https://www.facebook.com/events_manager2/list/pixel">Otwórz Meta Events Manager</a>
          </div>
          <hr style="margin:16px 0; border:none; border-top:1px solid #e5e7eb;">
          <div style="margin:8px 0 16px;">
            <label><input type="checkbox" name="mailchimp_enabled" value="1" <?php echo !empty($row['mailchimp_enabled']) ? 'checked' : ''; ?>> Włącz Mailchimp</label>
          </div>
          <label for="mailchimp_code">Mailchimp snippet lub URL</label>
          <input type="text" id="mailchimp_code" name="mailchimp_code" placeholder="Wklej cały snippet lub sam URL z chimpstatic.com..." value="<?php echo htmlspecialchars((string)($row['mailchimp_url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          <div class="muted" style="margin-top:8px;">Wklej pełen kod Mailchimp (zaczyna się od &lt;script id=&quot;mcjs&quot;&gt;...&lt;/script&gt;) albo sam adres URL z domeny <code>chimpstatic.com</code>. Skrypt zostanie dołączony na stronach z loaderem <code>/admin/tracking.js.php</code>.</div>
          <div style="margin-top:16px;"><button type="submit">Zapisz</button></div>
        </form>
      </div>
    </main>
  </body>
  </html>
