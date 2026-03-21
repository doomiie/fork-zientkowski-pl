<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
$debugInfo = auth_debug_enabled() ? auth_debug_snapshot([
    'flow' => 'admin_login',
    'phase' => 'page_load',
]) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $token = $_POST['csrf_token'] ?? '';
    $csrfMatches = csrf_check($token);

    auth_debug_emit('admin_login_attempt', [
        'email_present' => $email !== '',
        'password_present' => $password !== '',
        'csrf_present' => $token !== '',
        'csrf_matches' => $csrfMatches,
    ]);

    if (!$csrfMatches) {
        $error = 'Nieprawidlowy token bezpieczenstwa. Odswiez strone i sprobuj ponownie.';
        $debugInfo = auth_debug_enabled() ? auth_debug_snapshot([
            'flow' => 'admin_login',
            'phase' => 'csrf_failed',
        ]) : [];
    } elseif ($email === '' || $password === '') {
        $error = 'Podaj e-mail i haslo.';
        $debugInfo = auth_debug_enabled() ? auth_debug_snapshot([
            'flow' => 'admin_login',
            'phase' => 'input_failed',
        ]) : [];
    } else {
        $stmt = $pdo->prepare('SELECT id, email, password_hash, is_active, role, has_global_video_access FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        $passwordVerified = $user ? password_verify($password, (string)$user['password_hash']) : false;
        $ok = $user && (int)$user['is_active'] === 1 && $passwordVerified;

        if ($ok) {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_email'] = (string)$user['email'];
            $_SESSION['user_role'] = (string)($user['role'] ?? 'viewer');
            $_SESSION['user_has_global_video_access'] = (int)($user['has_global_video_access'] ?? 0);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$user['id']]);
            auth_debug_emit('admin_login_success', [
                'user_id' => (int)$user['id'],
                'role' => (string)($user['role'] ?? ''),
            ]);
            header('Location: index.php');
            exit;
        }

        auth_debug_emit('admin_login_rejected', [
            'user_found' => (bool)$user,
            'is_active' => $user ? (int)$user['is_active'] === 1 : null,
            'password_verified' => $user ? $passwordVerified : null,
        ]);
        $error = 'Nieprawidlowy e-mail lub haslo.';
        $debugInfo = auth_debug_enabled() ? auth_debug_snapshot([
            'flow' => 'admin_login',
            'phase' => 'credentials_failed',
            'user_found' => (bool)$user,
            'is_active' => $user ? (int)$user['is_active'] === 1 : null,
            'password_verified' => $user ? $passwordVerified : null,
        ]) : [];
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel admin - logowanie</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; }
    .wrap { min-height:100vh; display:grid; place-items:center; padding:24px; }
    .card { width:100%; max-width:420px; background:#fff; border-radius:16px; box-shadow:0 12px 30px rgba(0,0,0,.08); padding:28px; }
    h1 { margin:0 0 16px; font-size:22px; }
    .muted { color:#6b7280; margin-bottom:16px; }
    label { display:block; font-weight:600; margin:12px 0 6px; }
    input[type=email], input[type=password] { width:100%; padding:12px 14px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; }
    button { background:#040327; color:#fff; border:0; border-radius:10px; padding:12px 16px; font-weight:600; cursor:pointer; width:100%; margin-top:16px; }
    .error { background:#fee2e2; color:#7f1d1d; padding:10px 12px; border-radius:8px; margin-bottom:12px; font-size:14px; }
    .footer { margin-top:16px; text-align:center; color:#9ca3af; font-size:12px; }
    .debug { margin-top:16px; text-align:left; }
    .debug pre { margin-top:12px; white-space:pre-wrap; word-break:break-word; text-align:left; color:#111827; background:#f3f4f6; border-radius:8px; padding:12px; font-size:12px; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
</head>
<body>
  <div class="wrap">
    <form class="card" method="post" action="login.php<?php echo auth_debug_enabled() ? '?debug_auth=1' : ''; ?>" autocomplete="off">
      <h1>Logowanie</h1>
      <p class="muted">Wprowadz dane dostepowe do panelu administracyjnego.</p>
      <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
      <?php endif; ?>
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

      <label for="email">E-mail</label>
      <input type="email" id="email" name="email" required autofocus>

      <label for="password">Haslo</label>
      <input type="password" id="password" name="password" required>

      <button type="submit">Zaloguj sie</button>
      <div class="footer"><a href="forgot.php">Zapomniales hasla?</a></div>
      <div class="footer">&copy; <?php echo date('Y'); ?> Panel administracyjny</div>
      <?php if (auth_debug_enabled()): ?>
        <details class="debug" open>
          <summary>Debug logowania</summary>
          <pre><?php echo htmlspecialchars((string)json_encode($debugInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></pre>
        </details>
      <?php endif; ?>
    </form>
  </div>
</body>
</html>
