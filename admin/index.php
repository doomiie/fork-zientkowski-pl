<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_login();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel admin</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; }
    header { background:#040327; color:#fff; padding:16px 24px; display:flex; justify-content:space-between; align-items:center; }
    main { max-width:1000px; margin:24px auto; padding:0 24px; }
    .card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 12px 30px rgba(0,0,0,.06); }
    a.btn { display:inline-block; background:#fff; color:#040327; border:1px solid #9ca3af; padding:8px 12px; border-radius:8px; text-decoration:none; font-weight:600; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
</head>
<body>
  <header>
    <div><strong>Panel administracyjny</strong></div>
    <div>
      Zalogowano jako: <?php echo htmlspecialchars(current_user_email(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
      &nbsp;|&nbsp; <?php echo htmlspecialchars(current_user_role(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
      &nbsp;|&nbsp; <a class="btn" href="logout.php">Wyloguj</a>
    </div>
  </header>
  <main>
    <div class="card">
      <h1>Witaj w panelu</h1>
      <p>To jest przykładowa strona chroniona sesją użytkownika.</p>
      <ul>
        <li>Dodaj tu swoje widoki administracyjne.</li>
        <li>Ta strona wymaga zalogowania (sprawdzane w <code>require_login()</code>).</li>
      </ul>
      <hr style="margin:16px 0; border:none; border-top:1px solid #e5e7eb;">
      <h2>Ustawienia konta</h2>
      <ul>
        <li><a class="btn" href="password.php">Zmień swoje hasło</a></li>
      </ul>
      <?php if (is_admin()): ?>
        <h2 style="margin-top:12px;">Zarządzanie użytkownikami</h2>
        <ul>
          <li><a class="btn" href="users_password.php">Zmień hasło użytkownika</a></li>
        </ul>
        <h2 style="margin-top:12px;">Dokumenty</h2>
        <ul>
          <li><a class="btn" href="docs.php">Dodaj/zarządzaj dokumentami</a></li>
        </ul>
        <h2 style="margin-top:12px;">Przekierowania</h2>
        <ul>
          <li><a class="btn" href="redirects.php">Zarządzaj przekierowaniami</a></li>
        </ul>
        <h2 style="margin-top:12px;">Statystyki</h2>
        <ul>
          <li><a class="btn" href="404stats.php">404 - raporty i analiza</a></li>
        </ul>

        <h2 style="margin-top:12px;">Integracje</h2>
        <ul>
          <li><a class="btn" href="sheets.php">Google Sheets — autoryzacja i test</a></li>
        </ul>

        <h2 style="margin-top:12px;">Poczta</h2>
        <ul>
          <li><a class="btn" href="mail.php">Gmail OAuth – konfiguracja i test</a></li>
        </ul>
        <h2 style="margin-top:12px;">Ustawienia serwisu</h2>
        <ul>
          <li><a class="btn" href="site.php">Hotjar i inne ustawienia</a></li>
        </ul>
        <h2 style="margin-top:12px;">Nawigacja po stronach</h2>
        <ul>
          <li><a class="btn" href="pages.php">Lista stron (kafle)</a></li>
        </ul>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>

