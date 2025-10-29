<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $token = $_POST['csrf_token'] ?? '';

    if (!csrf_check($token)) {
        $error = 'Nieprawidłowy token bezpieczeństwa. Odśwież stronę i spróbuj ponownie.';
    } elseif ($email === '' || $password === '') {
        $error = 'Podaj e‑mail i hasło.';
    } else {
        $stmt = $pdo->prepare('SELECT id, email, password_hash, is_active, role FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        $ok = $user && (int)$user['is_active'] === 1 && password_verify($password, (string)$user['password_hash']);
        if ($ok) {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_email'] = (string)$user['email'];
            $_SESSION['user_role'] = (string)($user['role'] ?? 'viewer');
            // rotate CSRF token after login
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$user['id']]);
            header('Location: index.php');
            exit;
        }
        $error = 'Nieprawidłowy e‑mail lub hasło.';
    }
}

// Simple HTML form
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel admin – logowanie</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; }
    .wrap { min-height:100vh; display:grid; place-items:center; padding:24px; }
    .card { width:100%; max-width:420px; background:#fff; border-radius:16px; box-shadow:0 12px 30px rgba(0,0,0,.08); padding:28px; }
    h1 { margin:0 0 16px; font-size:22px; }
    .muted { color:#6b7280; margin-bottom:16px; }
    label { display:block; font-weight:600; margin:12px 0 6px; }
    input[type=email], input[type=password] { width:100%; padding:12px 14px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; }
    .row { display:flex; align-items:center; justify-content:space-between; margin-top:16px; }
    button { background:#040327; color:#fff; border:0; border-radius:10px; padding:12px 16px; font-weight:600; cursor:pointer; width:100%; margin-top:16px; }
    .error { background:#fee2e2; color:#7f1d1d; padding:10px 12px; border-radius:8px; margin-bottom:12px; font-size:14px; }
    .footer { margin-top:16px; text-align:center; color:#9ca3af; font-size:12px; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
</head>
<body>
  <div class="wrap">
    <form class="card" method="post" action="login.php" autocomplete="off">
      <h1>Logowanie</h1>
      <p class="muted">Wprowadź dane dostępowe do panelu administracyjnego.</p>
      <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
      <?php endif; ?>
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

      <label for="email">E‑mail</label>
      <input type="email" id="email" name="email" required autofocus>

      <label for="password">Hasło</label>
      <input type="password" id="password" name="password" required>

      <button type="submit">Zaloguj się</button>
      <div class="footer"><a href="forgot.php">Zapomniałeś hasła?</a></div>
      <div class="footer">© <?php echo date('Y'); ?> Panel administracyjny</div>
    </form>
  </div>
</body>
</html>
