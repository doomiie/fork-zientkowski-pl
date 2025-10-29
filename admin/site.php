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
            $stmt = $pdo->prepare('UPDATE site_settings SET hotjar_enabled = ?, hotjar_site_id = ? WHERE id = 1');
            $stmt->execute([$enabled, $siteId !== '' ? $siteId : null]);
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
          <div style="margin-top:16px;"><button type="submit">Zapisz</button></div>
        </form>
      </div>
    </main>
  </body>
  </html>

