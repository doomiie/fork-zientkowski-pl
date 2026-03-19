<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require_login();
require_admin();
require_once __DIR__ . '/../backend/video_auth_lib.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function active_admins_count(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM users WHERE is_active = 1 AND (role = 'admin' OR role LIKE '%admin%')"
    );
    return (int)$stmt->fetchColumn();
}

$userId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($userId <= 0) {
    http_response_code(400);
    echo 'Niepoprawny identyfikator uzytkownika.';
    exit;
}

$error = '';
$ok = '';
$emailInput = '';
$roleInput = 'viewer';
$hasGlobalVideoAccessInput = false;
$isActiveInput = true;
$createdAt = '';
$lastLoginAt = '';
$emailVerifiedAt = '';

$loadUser = static function () use ($pdo, $userId): array {
    $stmt = $pdo->prepare(
        'SELECT id, email, role, is_active, email_verified_at, created_at, last_login_at
                , has_global_video_access
         FROM users
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Nie znaleziono uzytkownika.');
    }
    return $row;
};

try {
    $userRow = $loadUser();
    $emailInput = (string)$userRow['email'];
    $roleInput = (string)$userRow['role'];
    $hasGlobalVideoAccessInput = (int)($userRow['has_global_video_access'] ?? 0) === 1;
    $isActiveInput = ((int)$userRow['is_active'] === 1);
    $createdAt = (string)($userRow['created_at'] ?? '');
    $lastLoginAt = (string)($userRow['last_login_at'] ?? '');
    $emailVerifiedAt = (string)($userRow['email_verified_at'] ?? '');
} catch (Throwable $e) {
    http_response_code(404);
    echo 'Nie znaleziono uzytkownika.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? 'save');
    $currentAdminId = current_user_id();

    if (!csrf_check($csrf)) {
        $error = 'Nieprawidlowy token bezpieczenstwa.';
    } elseif ($action === 'resend_verification') {
        try {
            $userRow = $loadUser();
            if ((int)$userRow['is_active'] !== 1) {
                $error = 'Nie mozna wyslac maila weryfikacyjnego do nieaktywnego konta.';
            } elseif ((string)($userRow['email_verified_at'] ?? '') !== '') {
                $error = 'Adres e-mail tego uzytkownika jest juz potwierdzony.';
            } else {
                $pdo->beginTransaction();
                $verification = auth_issue_email_verification($pdo, $userId);
                auth_send_verification_email($pdo, (string)$userRow['email'], $verification['token']);
                $pdo->commit();
                $ok = 'Ponownie wyslano mail weryfikacyjny.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Nie udalo sie wyslac maila weryfikacyjnego.';
        }
    } elseif ($action === 'toggle_active') {
        $nextState = ((string)($_POST['next_state'] ?? '0') === '1') ? 1 : 0;
        try {
            $userRow = $loadUser();
            if ($userId === $currentAdminId && $nextState === 0) {
                $error = 'Nie mozesz zdezaktywowac swojego konta.';
            } elseif ($nextState === 0 && role_raw_has((string)$userRow['role'], 'admin') && (int)$userRow['is_active'] === 1 && active_admins_count($pdo) <= 1) {
                $error = 'Nie mozna zdezaktywowac ostatniego aktywnego admina.';
            } elseif ((int)$userRow['is_active'] === $nextState) {
                $ok = $nextState === 1 ? 'Konto jest juz aktywne.' : 'Konto jest juz nieaktywne.';
            } else {
                $stmt = $pdo->prepare('UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
                $stmt->execute([$nextState, $userId]);
                $ok = $nextState === 1 ? 'Konto zostalo aktywowane.' : 'Konto zostalo zdezaktywowane.';
            }
        } catch (Throwable $e) {
            $error = 'Nie udalo sie zmienic statusu uzytkownika.';
        }
    } else {
        $emailInput = strtolower(trim((string)($_POST['email'] ?? '')));
        $roleInput = (string)($_POST['role'] ?? 'viewer');
        $hasGlobalVideoAccessInput = ((string)($_POST['has_global_video_access'] ?? '0') === '1');
        $isActiveInput = ((string)($_POST['is_active'] ?? '0') === '1');
        $password = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password2'] ?? '');
        $hasGlobalVideoAccessValue = ($roleInput === 'editor' && $hasGlobalVideoAccessInput) ? 1 : 0;

        if (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
            $error = 'Podaj poprawny adres e-mail.';
        } elseif (!in_array($roleInput, ['admin', 'editor', 'viewer'], true)) {
            $error = 'Niepoprawna rola.';
        } elseif ($password !== '' || $password2 !== '') {
            if (strlen($password) < 8) {
                $error = 'Haslo musi miec co najmniej 8 znakow.';
            } elseif ($password !== $password2) {
                $error = 'Hasla nie sa identyczne.';
            }
        }

        if ($error === '' && $userId === $currentAdminId) {
            if (!$isActiveInput) {
                $error = 'Nie mozesz zdezaktywowac swojego konta.';
            } elseif ($roleInput !== 'admin') {
                $error = 'Nie mozesz odebrac sobie roli admina.';
            }
        }

        if ($error === '') {
            try {
                $dupStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
                $dupStmt->execute([$emailInput, $userId]);
                if ($dupStmt->fetchColumn()) {
                    $error = 'Konto o tym adresie e-mail juz istnieje.';
                }
            } catch (Throwable $e) {
                $error = 'Nie mozna zweryfikowac unikalnosci e-maila.';
            }
        }

        if ($error === '') {
            try {
                $pdo->beginTransaction();

                $currentStmt = $pdo->prepare('SELECT email, role, is_active FROM users WHERE id = ? LIMIT 1');
                $currentStmt->execute([$userId]);
                $currentRow = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $oldEmail = (string)($currentRow['email'] ?? '');
                $oldRole = (string)($currentRow['role'] ?? '');
                $wasActive = (int)($currentRow['is_active'] ?? 0) === 1;
                $emailChanged = $oldEmail !== '' && strcasecmp($oldEmail, $emailInput) !== 0;

                if ($wasActive && !$isActiveInput && role_raw_has($oldRole, 'admin') && active_admins_count($pdo) <= 1) {
                    throw new RuntimeException('last_admin');
                }

                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    if ($hash === false) {
                        throw new RuntimeException('Blad haszowania hasla.');
                    }
                    $stmt = $pdo->prepare(
                        'UPDATE users
                         SET email = ?, role = ?, has_global_video_access = ?, is_active = ?, password_hash = ?, updated_at = NOW()
                         WHERE id = ? LIMIT 1'
                    );
                    $stmt->execute([$emailInput, $roleInput, $hasGlobalVideoAccessValue, $isActiveInput ? 1 : 0, $hash, $userId]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE users
                         SET email = ?, role = ?, has_global_video_access = ?, is_active = ?, updated_at = NOW()
                         WHERE id = ? LIMIT 1'
                    );
                    $stmt->execute([$emailInput, $roleInput, $hasGlobalVideoAccessValue, $isActiveInput ? 1 : 0, $userId]);
                }

                if ($emailChanged) {
                    $pdo->prepare('UPDATE users SET email_verified_at = NULL WHERE id = ? LIMIT 1')->execute([$userId]);
                    $pdo->prepare('UPDATE user_email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
                }

                $pdo->commit();

                if ($userId === $currentAdminId) {
                    $_SESSION['user_email'] = $emailInput;
                    $_SESSION['user_role'] = $roleInput;
                    $_SESSION['user_has_global_video_access'] = $hasGlobalVideoAccessValue;
                }

                $ok = $emailChanged
                    ? 'Dane uzytkownika zostaly zapisane. Adres e-mail wymaga teraz ponownej weryfikacji.'
                    : 'Dane uzytkownika zostaly zapisane.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e instanceof RuntimeException && $e->getMessage() === 'last_admin') {
                    $error = 'Nie mozna zdezaktywowac ostatniego aktywnego admina.';
                } else {
                    $error = 'Wystapil blad zapisu uzytkownika.';
                }
            }
        }
    }

    try {
        $userRow = $loadUser();
        $emailInput = (string)$userRow['email'];
        $roleInput = (string)$userRow['role'];
        $hasGlobalVideoAccessInput = (int)($userRow['has_global_video_access'] ?? 0) === 1;
        $isActiveInput = ((int)$userRow['is_active'] === 1);
        $createdAt = (string)($userRow['created_at'] ?? '');
        $lastLoginAt = (string)($userRow['last_login_at'] ?? '');
        $emailVerifiedAt = (string)($userRow['email_verified_at'] ?? '');
    } catch (Throwable $e) {
        $error = 'Nie mozna odswiezyc danych uzytkownika.';
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edytuj uzytkownika</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; color:#111827; }
    header { background:#040327; color:#fff; padding:16px 24px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
    main { max-width:900px; margin:24px auto; padding:0 24px; display:grid; gap:16px; }
    .card { background:#fff; border-radius:14px; padding:16px; box-shadow:0 10px 28px rgba(0,0,0,.06); }
    label { display:block; font-weight:600; margin:12px 0 6px; }
    input[type=email], input[type=password], select { width:100%; border:1px solid #d1d5db; border-radius:10px; padding:10px 12px; font:inherit; }
    .checkbox-row { margin-top:12px; display:flex; align-items:center; gap:8px; font-weight:600; }
    .checkbox-row input { width:auto; margin:0; }
    button, a.btn { display:inline-block; background:#040327; color:#fff; border:0; border-radius:10px; padding:10px 14px; text-decoration:none; font-weight:600; cursor:pointer; }
    a.btn.secondary, button.secondary { background:#fff; color:#111827; border:1px solid #d1d5db; }
    button.warn { background:#fff7ed; color:#9a3412; border:1px solid #fdba74; }
    .ok { color:#166534; font-weight:600; margin:10px 0; }
    .err { color:#991b1b; font-weight:600; margin:10px 0; }
    .muted { color:#6b7280; font-size:13px; }
    .meta { display:grid; gap:6px; margin:0; }
    .actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:16px; }
    .pill { display:inline-block; border-radius:999px; padding:4px 10px; font-size:12px; font-weight:700; }
    .pill-ok { background:#dcfce7; color:#166534; }
    .pill-warn { background:#fef3c7; color:#92400e; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
</head>
<body>
  <header>
    <div><strong>Edycja uzytkownika</strong></div>
    <div>
      Zalogowano jako: <?php echo h(current_user_email()); ?> (<?php echo h(current_user_role()); ?>)
      &nbsp;|&nbsp;
      <a class="btn secondary" href="users.php">Powrot do uzytkownikow</a>
    </div>
  </header>
  <main>
    <section class="card">
      <h1 style="margin-top:0;">Edytuj konto</h1>
      <p class="muted">Mozesz zmienic e-mail, role, status oraz opcjonalnie ustawic nowe haslo.</p>
      <?php if ($error !== ''): ?><p class="err"><?php echo h($error); ?></p><?php endif; ?>
      <?php if ($ok !== ''): ?><p class="ok"><?php echo h($ok); ?></p><?php endif; ?>

      <form method="post" action="users_edit.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$userId; ?>">
        <input type="hidden" name="action" value="save">

        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" required value="<?php echo h($emailInput); ?>">

        <label for="role">Rola</label>
        <select id="role" name="role" required>
          <option value="viewer" <?php echo $roleInput === 'viewer' ? 'selected' : ''; ?>>viewer</option>
          <option value="editor" <?php echo $roleInput === 'editor' ? 'selected' : ''; ?>>editor</option>
          <option value="admin" <?php echo $roleInput === 'admin' ? 'selected' : ''; ?>>admin</option>
        </select>

        <label class="checkbox-row">
          <input type="checkbox" name="has_global_video_access" value="1" <?php echo $hasGlobalVideoAccessInput ? 'checked' : ''; ?>>
          Globalny dostep do wszystkich video (dla trenera/editor)
        </label>

        <label class="checkbox-row">
          <input type="checkbox" name="is_active" value="1" <?php echo $isActiveInput ? 'checked' : ''; ?>>
          Aktywny uzytkownik
        </label>

        <label for="password">Nowe haslo</label>
        <input id="password" name="password" type="password" minlength="8" placeholder="Pozostaw puste, aby nie zmieniac">

        <label for="password2">Powtorz nowe haslo</label>
        <input id="password2" name="password2" type="password" minlength="8" placeholder="Pozostaw puste, aby nie zmieniac">

        <div class="actions">
          <button type="submit">Zapisz zmiany</button>
        </div>
      </form>
    </section>

    <section class="card">
      <h2 style="margin-top:0;">Status konta</h2>
      <p class="muted">Dezaktywacja blokuje logowanie, ale zostawia wszystkie filmy, komentarze, podsumowania i pozostale zaleznosci.</p>
      <div class="meta">
        <div>
          Aktywnosc:
          <?php if ($isActiveInput): ?>
            <span class="pill pill-ok">aktywne</span>
          <?php else: ?>
            <span class="pill pill-warn">nieaktywne</span>
          <?php endif; ?>
        </div>
      </div>
      <form method="post" action="users_edit.php" autocomplete="off" style="margin-top:16px;" onsubmit="return confirm('<?php echo $isActiveInput ? 'Zdezaktywowac to konto?' : 'Aktywowac to konto?'; ?>');">
        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$userId; ?>">
        <input type="hidden" name="action" value="toggle_active">
        <input type="hidden" name="next_state" value="<?php echo $isActiveInput ? '0' : '1'; ?>">
        <button type="submit" class="<?php echo $isActiveInput ? 'warn' : 'secondary'; ?>">
          <?php echo $isActiveInput ? 'Dezaktywuj konto' : 'Aktywuj konto'; ?>
        </button>
      </form>
    </section>

    <section class="card">
      <h2 style="margin-top:0;">Weryfikacja e-mail</h2>
      <p class="muted">Ten blok pozwala sprawdzic realny mechanizm wysylki maila weryfikacyjnego dla tego konta.</p>
      <div class="meta">
        <div>
          Status:
          <?php if ($emailVerifiedAt !== ''): ?>
            <span class="pill pill-ok">potwierdzony</span>
          <?php else: ?>
            <span class="pill pill-warn">niepotwierdzony</span>
          <?php endif; ?>
        </div>
        <div class="muted">Data potwierdzenia: <?php echo h($emailVerifiedAt !== '' ? $emailVerifiedAt : '-'); ?></div>
      </div>
      <?php if ($emailVerifiedAt === ''): ?>
        <form method="post" action="users_edit.php" autocomplete="off" style="margin-top:16px;">
          <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
          <input type="hidden" name="id" value="<?php echo (int)$userId; ?>">
          <input type="hidden" name="action" value="resend_verification">
          <button type="submit" class="secondary">Wyslij ponownie mail weryfikacyjny</button>
        </form>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2 style="margin-top:0;">Informacje</h2>
      <div class="meta muted">
        <div>ID: <?php echo (int)$userId; ?></div>
        <div>Utworzono: <?php echo h($createdAt); ?></div>
        <div>Ostatnie logowanie: <?php echo h($lastLoginAt); ?></div>
      </div>
    </section>
  </main>
</body>
</html>
