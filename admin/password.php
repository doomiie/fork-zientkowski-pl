<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_login();

$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    $old = (string)($_POST['old_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $new2 = (string)($_POST['new_password2'] ?? '');

    if (!csrf_check($csrf)) {
        $error = 'Nieprawidłowy token bezpieczeństwa.';
    } elseif (strlen($new) < 8) {
        $error = 'Nowe hasło musi mieć co najmniej 8 znaków.';
    } elseif ($new !== $new2) {
        $error = 'Hasła nie są identyczne.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([current_user_id()]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($old, (string)$row['password_hash'])) {
                $error = 'Błędne obecne hasło.';
            } else {
                $hash = password_hash($new, PASSWORD_BCRYPT);
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, current_user_id()]);
                $ok = 'Hasło zostało zmienione.';
            }
        } catch (Throwable $e) {
            $error = 'Wystąpił błąd. Spróbuj ponownie.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Zmień swoje hasło</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; }
    header { background:#040327; color:#fff; padding:16px 24px; display:flex; justify-content:space-between; align-items:center; }
    main { max-width:720px; margin:24px auto; padding:0 24px; }
    .card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 12px 30px rgba(0,0,0,.06); }
    label { display:block; font-weight:600; margin:12px 0 6px; }
    input[type=password] { width:100%; padding:12px 14px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; }
    button { background:#040327; color:#fff; border:0; border-radius:10px; padding:12px 16px; font-weight:600; cursor:pointer; margin-top:16px; }
    .error { background:#fee2e2; color:#7f1d1d; padding:10px 12px; border-radius:8px; margin:12px 0; font-size:14px; }
    .ok { background:#ecfdf5; color:#065f46; padding:10px 12px; border-radius:8px; margin:12px 0; font-size:14px; }
    a.btn { display:inline-block; background:#fff; color:#040327; border:1px solid #9ca3af; padding:8px 12px; border-radius:8px; text-decoration:none; font-weight:600; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
  </head>
<body>
  <header>
    <div><strong>Panel administracyjny</strong></div>
    <div>
      Zalogowano jako: <?php echo htmlspecialchars(current_user_email(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
      &nbsp;|&nbsp;
      <a class="btn" href="index.php">Powrót</a>
      &nbsp;|&nbsp;
      <a class="btn" href="logout.php">Wyloguj</a>
    </div>
  </header>
  <main>
    <div class="card">
      <h1>Zmień swoje hasło</h1>
      <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="ok"><?php echo htmlspecialchars($ok, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
      <form method="post" action="password.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <label for="old_password">Obecne hasło</label>
        <input type="password" id="old_password" name="old_password" required>
        <label for="new_password">Nowe hasło (min. 8 znaków)</label>
        <input type="password" id="new_password" name="new_password" minlength="8" required>
        <label for="new_password2">Powtórz nowe hasło</label>
        <input type="password" id="new_password2" name="new_password2" minlength="8" required>
        <button type="submit">Zmień hasło</button>
      </form>
    </div>
  </main>
</body>
</html>

