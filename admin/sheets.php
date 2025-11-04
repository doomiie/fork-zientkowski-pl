<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_login();
if (!is_admin()) { http_response_code(403); echo 'Brak uprawnień.'; exit; }

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'zientkowski.pl';
$redirectUri = $scheme . '://' . $host . '/backend/sheets_subscribe.php';

$tokenPath = __DIR__ . '/../backend/token_sheets.json';
$tokenInfo = null;
if (file_exists($tokenPath)) {
  $raw = @file_get_contents($tokenPath);
  if ($raw !== false) {
    $json = json_decode($raw, true);
    if (is_array($json)) { $tokenInfo = $json; }
  }
}

function humanTokenStatus(?array $t): string {
  if (!$t) return 'Brak tokenu (wymagana autoryzacja).';
  $created = isset($t['created']) ? (int)$t['created'] : null;
  $expiresIn = isset($t['expires_in']) ? (int)$t['expires_in'] : null;
  if ($created && $expiresIn) {
    $exp = $created + $expiresIn;
    $left = $exp - time();
    if ($left > 0) {
      return 'Token ważny jeszcze ~' . floor($left/60) . ' min';
    } else {
      return 'Token wygasł (odświeżenie przez refresh_token nastąpi automatycznie przy użyciu).';
    }
  }
  return 'Token obecny (szczegóły nieznane).';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Google Sheets — autoryzacja i test</title>
  <style>
    body { font-family: system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; background:#f6f7fb; margin:0; }
    header { background:#040327; color:#fff; padding:16px 24px; display:flex; justify-content:space-between; align-items:center; }
    main { max-width:900px; margin:24px auto; padding:0 24px; }
    .card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 12px 30px rgba(0,0,0,.06); }
    a.btn, button.btn { display:inline-block; background:#fff; color:#040327; border:1px solid #9ca3af; padding:8px 12px; border-radius:8px; text-decoration:none; font-weight:600; cursor:pointer; }
    .muted { color:#6b7280; }
    code { background:#f3f4f6; padding:2px 4px; border-radius:4px; }
    .row { margin:10px 0; }
    input[type=email] { padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; width:100%; max-width:360px; }
    .ok { color:#065f46; }
    .err { color:#b91c1c; }
    pre { background:#0b1021; color:#e5e7eb; padding:12px; border-radius:8px; overflow:auto; }
  </style>
</head>
<body>
  <header>
    <div><strong>Google Sheets — autoryzacja i test</strong></div>
    <div>
      <a class="btn" href="index.php">Powrót</a>
    </div>
  </header>
  <main>
    <div class="card">
      <h2>Status</h2>
      <p class="row">Redirect URI: <code><?php echo htmlspecialchars($redirectUri, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?></code></p>
      <p class="row">Token: <?php echo htmlspecialchars(humanTokenStatus($tokenInfo), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?></p>
      <div class="row">
        <button class="btn" id="btnAuth">Rozpocznij autoryzację</button>
        <span class="muted">Po autoryzacji wrócisz tutaj.</span>
      </div>

      <hr style="margin:16px 0; border:none; border-top:1px solid #e5e7eb;">
      <h2>Test zapisu</h2>
      <div class="row">
        <form id="testForm" onsubmit="return false;">
          <label>E-mail
            <input type="email" id="testEmail" value="<?php echo 'test+' . date('Ymd_His') . '@zientkowski.pl'; ?>" required>
          </label>
          <button class="btn" id="btnSend">Wyślij test do Sheet</button>
        </form>
      </div>
      <div class="row" id="msg" class="muted"></div>
      <pre id="out" style="display:none;"></pre>
    </div>
  </main>
  <script>
  (function(){
    const btn = document.getElementById('btnSend');
    const btnAuth = document.getElementById('btnAuth');
    const email = document.getElementById('testEmail');
    const msg = document.getElementById('msg');
    const out = document.getElementById('out');
    function show(m, ok){ if(msg){ msg.textContent = m; msg.className = ok? 'ok' : 'err'; } }
    function showOut(o){ if(out){ out.style.display = 'block'; out.textContent = o; } }
    if(btnAuth){
      btnAuth.addEventListener('click', async function(){
        try {
          const res = await fetch('/backend/sheets_subscribe.php?return=/admin/sheets.php');
          const data = await res.json().catch(()=>({}));
          if (res.status === 401 && data && data.authUrl) {
            window.location.href = data.authUrl; // full-page redirect to Google consent
            return;
          }
          show('Nie udało się rozpocząć autoryzacji. Sprawdź konfigurację.', false);
          if (data) showOut(JSON.stringify(data, null, 2));
        } catch(ex) {
          show('Błąd połączenia.', false);
          showOut(String(ex));
        }
      });
    }
    if(btn){
      btn.addEventListener('click', async function(){
        // Basic client-side check to avoid 400s
        if (!email.checkValidity()) {
          show('Podaj poprawny adres e-mail.', false);
          return;
        }
        show('Wysyłanie...', true);
        out.style.display = 'none'; out.textContent = '';
        try{
          const res = await fetch('/backend/sheets_subscribe.php', { method:'POST', headers:{ 'Content-Type':'application/json' }, body: JSON.stringify({ email: (email.value||'').trim() }) });
          const data = await res.json().catch(()=>({}));
          if (res.status === 401 && data && data.error === 'auth_required' && data.authUrl) {
            window.open(data.authUrl, '_blank', 'noopener');
            show('Autoryzuj dostęp w nowej karcie, wróć i spróbuj ponownie.', false);
            showOut(JSON.stringify(data, null, 2));
            return;
          }
          if(!res.ok || data.error){ show(data.error || ('Błąd: HTTP ' + res.status), false); showOut(JSON.stringify(data, null, 2)); return; }
          show('OK — wpis dodany do Arkusza.', true);
          showOut(JSON.stringify(data, null, 2));
        }catch(ex){ show('Błąd połączenia.', false); showOut(String(ex)); }
      });
    }
  })();
  </script>
</body>
</html>
