<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_login();
require_admin();
require_once __DIR__ . '/lib/Mailer.php';

$mailer = new GmailOAuthMailer($pdo);
$error = '';
$ok = '';
$tab = $_GET['tab'] ?? 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $error = 'Nieprawidłowy token bezpieczeństwa.';
    } elseif ($action === 'save') {
        try {
            $mailer->saveSettings([
                'provider' => 'gmail_oauth',
                'client_id' => trim((string)($_POST['client_id'] ?? '')),
                'client_secret' => trim((string)($_POST['client_secret'] ?? '')),
                'refresh_token' => trim((string)($_POST['refresh_token'] ?? '')),
                'sender_email' => trim((string)($_POST['sender_email'] ?? '')),
                'sender_name' => trim((string)($_POST['sender_name'] ?? '')),
            ]);
            $ok = 'Zapisano ustawienia.';
            $tab = 'settings';
        } catch (Throwable $e) {
            $error = 'Błąd zapisu ustawień.';
        }
    } elseif ($action === 'test') {
        $tab = 'test';
        $to = trim((string)($_POST['to'] ?? ''));
        $subject = trim((string)($_POST['subject'] ?? 'Test – Gmail OAuth'));
        $html = (string)($_POST['html'] ?? '<p>Test Gmail OAuth – pozdrowienia!</p>');
        if ($to === '') {
            $error = 'Podaj adres docelowy.';
        } else {
            try {
                $res = $mailer->send($to, $subject, $html);
                $ok = 'Wysłano. ID: ' . htmlspecialchars((string)($res['id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } catch (Throwable $e) {
                $error = 'Błąd wysyłki: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
    }
}

$s = $mailer->getSettings();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Poczta – Gmail OAuth</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; }
    header { background:#040327; color:#fff; padding:16px 24px; display:flex; justify-content:space-between; align-items:center; }
    main { max-width:1000px; margin:24px auto; padding:0 24px; }
    .card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 12px 30px rgba(0,0,0,.06); }
    label { display:block; font-weight:600; margin:12px 0 6px; }
    input[type=text], textarea { width:100%; padding:12px 14px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; }
    .row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .tabs { display:flex; gap:8px; margin-bottom:12px; }
    a.tab { text-decoration:none; padding:10px 14px; border-radius:10px; border:1px solid #cbd5e1; color:#040327; background:#fff; font-weight:600; }
    a.tab.active { background:#040327; color:#fff; border-color:#040327; }
    .error { background:#fee2e2; color:#7f1d1d; padding:10px 12px; border-radius:8px; margin:12px 0; font-size:14px; }
    .ok { background:#ecfdf5; color:#065f46; padding:10px 12px; border-radius:8px; margin:12px 0; font-size:14px; }
    a.btn { display:inline-block; background:#fff; color:#040327; border:1px solid #9ca3af; padding:8px 12px; border-radius:8px; text-decoration:none; font-weight:600; }
    button { background:#040327; color:#fff; border:0; border-radius:10px; padding:12px 16px; font-weight:600; cursor:pointer; }
    .muted { color:#6b7280; font-size:13px; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline'; connect-src https://oauth2.googleapis.com https://gmail.googleapis.com;">
</head>
<body>
  <header>
    <div><strong>Panel administracyjny</strong></div>
    <div>
      Zalogowano jako: <?php echo htmlspecialchars(current_user_email(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> | <?php echo htmlspecialchars(current_user_role(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
      &nbsp;|&nbsp;
      <a class="btn" href="index.php">Powrót</a>
      &nbsp;|&nbsp;
      <a class="btn" href="logout.php">Wyloguj</a>
    </div>
  </header>
  <main>
    <div class="card">
      <div class="tabs">
        <a class="tab <?php echo $tab==='settings'?'active':''; ?>" href="mail.php?tab=settings">Ustawienia</a>
        <a class="tab <?php echo $tab==='test'?'active':''; ?>" href="mail.php?tab=test">Test wysyłki</a>
      </div>
      <?php if ($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="ok"><?php echo $ok; ?></div><?php endif; ?>

      <?php if ($tab === 'settings'): ?>
        <form method="post" action="mail.php?tab=settings" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="save">
          <div class="row">
            <div>
              <label for="client_id">Client ID</label>
              <input type="text" id="client_id" name="client_id" value="<?php echo htmlspecialchars((string)($s['client_id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            </div>
            <div>
              <label for="client_secret">Client Secret</label>
              <input type="text" id="client_secret" name="client_secret" value="<?php echo htmlspecialchars((string)($s['client_secret'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            </div>
          </div>
          <label for="refresh_token">Refresh Token</label>
          <input type="text" id="refresh_token" name="refresh_token" value="<?php echo htmlspecialchars((string)($s['refresh_token'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          <div class="row">
            <div>
              <label for="sender_email">Nadawca – e‑mail</label>
              <input type="text" id="sender_email" name="sender_email" value="<?php echo htmlspecialchars((string)($s['sender_email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            </div>
            <div>
              <label for="sender_name">Nadawca – nazwa</label>
              <input type="text" id="sender_name" name="sender_name" value="<?php echo htmlspecialchars((string)($s['sender_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            </div>
          </div>
          <div class="muted" style="margin-top:8px;">
            Jak uzyskać Refresh Token? Użyj <a href="https://developers.google.com/oauthplayground/" target="_blank" rel="noopener">OAuth 2.0 Playground</a>:
            1) Wybierz scope: <code>https://www.googleapis.com/auth/gmail.send</code>. 2) Autoryzuj i zamień kod na tokeny. 3) Skopiuj <strong>refresh_token</strong>.
            Uwaga: Konto i projekt Google Cloud muszą mieć włączony Gmail API, a domena nadawcy powinna być zweryfikowana.
          </div>
          <div style="margin-top:16px;"><button type="submit">Zapisz</button></div>
        </form>
      <?php else: ?>
        <form method="post" action="mail.php?tab=test" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="test">
          <label for="to">Adres docelowy</label>
          <input type="text" id="to" name="to" placeholder="adres@domena.pl">
          <label for="subject">Temat</label>
          <input type="text" id="subject" name="subject" value="Test – Gmail OAuth">
          <label for="html">Treść (HTML)</label>
          <textarea id="html" name="html" rows="8"><p>Test Gmail OAuth – pozdrowienia!</p></textarea>
          <div style="margin-top:16px;"><button type="submit">Wyślij test</button></div>
        </form>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>

