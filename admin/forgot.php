<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

$info = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_check($token)) {
        $error = 'Nieprawidłowy token bezpieczeństwa. Odśwież stronę i spróbuj ponownie.';
    } elseif ($email === '') {
        $error = 'Podaj adres e‑mail.';
    } else {
        try {
            $pdo->beginTransaction();
            // Find user by email
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Always respond with success message to avoid user enumeration
            $info = 'Jeśli konto istnieje, wysłaliśmy link do resetu hasła.';

            if ($user) {
                $userId = (int)$user['id'];
                // Invalidate older active tokens for this user
                $pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0')->execute([$userId]);

                $rawToken = bin2hex(random_bytes(32));
                $expires = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

                $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at, used) VALUES (?, ?, ?, 0)')
                    ->execute([$userId, $rawToken, $expires]);

                $pdo->commit();

                // Build reset URL
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/'), '/\\');
                $resetUrl = $scheme . '://' . $host . $base . '/reset.php?token=' . urlencode($rawToken);

                // Try to send email (if configured); also always display link for convenience
                $sent = false;
                if (function_exists('mail')) {
                    $subject = 'Reset hasła – panel administracyjny';
                    $message = "Aby zresetować hasło, wejdź w link:\r\n\r\n$resetUrl\r\n\r\nLink wygaśnie za 1 godzinę.";
                    $headers = 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
                    // Suppress errors; hosting may not allow mail()
                    $sent = @mail($email, $subject, $message, $headers);
                }

                // Store resetUrl to show below (development convenience)
                $_SESSION['last_reset_url'] = $resetUrl;
            } else {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            // Do not leak cause
            $error = 'Wystąpił błąd. Spróbuj ponownie później.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset hasła – prośba</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; }
    .wrap { min-height:100vh; display:grid; place-items:center; padding:24px; }
    .card { width:100%; max-width:460px; background:#fff; border-radius:16px; box-shadow:0 12px 30px rgba(0,0,0,.08); padding:28px; }
    h1 { margin:0 0 8px; font-size:22px; }
    .muted { color:#6b7280; margin-bottom:16px; }
    label { display:block; font-weight:600; margin:12px 0 6px; }
    input[type=email] { width:100%; padding:12px 14px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; }
    button { background:#040327; color:#fff; border:0; border-radius:10px; padding:12px 16px; font-weight:600; cursor:pointer; width:100%; margin-top:16px; }
    .error { background:#fee2e2; color:#7f1d1d; padding:10px 12px; border-radius:8px; margin-bottom:12px; font-size:14px; }
    .info { background:#ecfeff; color:#155e75; padding:10px 12px; border-radius:8px; margin-bottom:12px; font-size:14px; }
    .footer { margin-top:16px; text-align:center; color:#9ca3af; font-size:12px; }
    a { color:#040327; text-decoration:underline; }
    .dev { margin-top:10px; font-size:12px; color:#6b7280; word-break:break-all; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
</head>
<body>
  <div class="wrap">
    <form class="card" method="post" action="forgot.php" autocomplete="off">
      <h1>Reset hasła</h1>
      <p class="muted">Podaj e‑mail konta. Jeśli istnieje, wyślemy link do resetu.</p>
      <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
      <?php if ($info): ?><div class="info"><?php echo htmlspecialchars($info, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

      <label for="email">E‑mail</label>
      <input type="email" id="email" name="email" required autofocus>

      <button type="submit">Wyślij link resetujący</button>
      <div class="footer"><a href="login.php">Powrót do logowania</a></div>
      <?php if (!empty($_SESSION['last_reset_url'])): ?>
        <div class="dev">DEV: Link resetu: <br><?php echo htmlspecialchars($_SESSION['last_reset_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
      <?php endif; ?>
    </form>
  </div>
</body>
</html>

