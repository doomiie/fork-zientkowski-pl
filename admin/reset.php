<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$token = is_string($token) ? trim($token) : '';

$stage = 'form'; // form | done | invalid
$error = '';

// Validate token: exists, not used, not expired
function fetch_valid_reset(PDO $pdo, string $token): ?array {
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT pr.id, pr.user_id, pr.expires_at, pr.used, u.email FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if ((int)$row['used'] === 1) return null;
    $now = new DateTimeImmutable('now');
    $exp = new DateTimeImmutable((string)$row['expires_at']);
    if ($exp < $now) return null;
    return $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    $pwd = (string)($_POST['password'] ?? '');
    $pwd2 = (string)($_POST['password2'] ?? '');
    if (!csrf_check($csrf)) {
        $error = 'Nieprawidłowy token bezpieczeństwa.';
    } else {
        $row = fetch_valid_reset($pdo, $token);
        if (!$row) {
            $stage = 'invalid';
        } else {
            if (strlen($pwd) < 8) {
                $error = 'Hasło musi mieć co najmniej 8 znaków.';
            } elseif ($pwd !== $pwd2) {
                $error = 'Hasła nie są identyczne.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $hash = password_hash($pwd, PASSWORD_BCRYPT);
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, (int)$row['user_id']]);
                    $pdo->prepare('UPDATE password_resets SET used = 1 WHERE id = ?')->execute([(int)$row['id']]);
                    // Invalidate other tokens for this user
                    $pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ?')->execute([(int)$row['user_id']]);
                    $pdo->commit();
                    $stage = 'done';
                    // Log out any active session for safety
                    $_SESSION = [];
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $error = 'Wystąpił błąd. Spróbuj ponownie.';
                }
            }
        }
    }
} else {
    if (!fetch_valid_reset($pdo, $token)) {
        $stage = 'invalid';
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset hasła</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; }
    .wrap { min-height:100vh; display:grid; place-items:center; padding:24px; }
    .card { width:100%; max-width:460px; background:#fff; border-radius:16px; box-shadow:0 12px 30px rgba(0,0,0,.08); padding:28px; }
    h1 { margin:0 0 8px; font-size:22px; }
    .muted { color:#6b7280; margin-bottom:16px; }
    label { display:block; font-weight:600; margin:12px 0 6px; }
    input[type=password] { width:100%; padding:12px 14px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; }
    button { background:#040327; color:#fff; border:0; border-radius:10px; padding:12px 16px; font-weight:600; cursor:pointer; width:100%; margin-top:16px; }
    .error { background:#fee2e2; color:#7f1d1d; padding:10px 12px; border-radius:8px; margin-bottom:12px; font-size:14px; }
    .ok { background:#ecfdf5; color:#065f46; padding:10px 12px; border-radius:8px; margin-bottom:12px; font-size:14px; }
    .footer { margin-top:16px; text-align:center; color:#9ca3af; font-size:12px; }
    a { color:#040327; text-decoration:underline; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
</head>
<body>
  <div class="wrap">
    <div class="card">
      <?php if ($stage === 'invalid'): ?>
        <h1>Link wygasł lub jest nieprawidłowy</h1>
        <p class="muted">Poproś o nowy link resetu.</p>
        <div class="footer"><a href="forgot.php">Wróć do prośby o reset</a></div>
      <?php elseif ($stage === 'done'): ?>
        <div class="ok">Hasło zostało zaktualizowane.</div>
        <div class="footer"><a href="login.php">Przejdź do logowania</a></div>
      <?php else: ?>
        <h1>Ustaw nowe hasło</h1>
        <p class="muted">Wprowadź nowe hasło dla swojego konta.</p>
        <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
        <form method="post" action="reset.php">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">

          <label for="password">Nowe hasło</label>
          <input type="password" id="password" name="password" minlength="8" required>

          <label for="password2">Powtórz hasło</label>
          <input type="password" id="password2" name="password2" minlength="8" required>

          <button type="submit">Zmień hasło</button>
        </form>
        <div class="footer"><a href="login.php">Powrót do logowania</a></div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>

