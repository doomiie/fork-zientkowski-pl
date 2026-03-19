<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require_login();
require_admin();

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

$error = '';
$ok = '';

$emailInput = '';
$roleInput = 'viewer';
$hasGlobalVideoAccessInput = false;
$isActiveInput = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? 'create');

    if (!csrf_check($csrf)) {
        $error = 'Nieprawidlowy token bezpieczenstwa.';
    } elseif ($action === 'toggle_active') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $nextState = ((string)($_POST['next_state'] ?? '0') === '1') ? 1 : 0;
        $currentAdminId = current_user_id();

        if ($targetUserId <= 0) {
            $error = 'Niepoprawny identyfikator uzytkownika.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT id, email, role, is_active FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$targetUserId]);
                $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$userRow) {
                    $error = 'Nie znaleziono uzytkownika.';
                } elseif ($targetUserId === $currentAdminId && $nextState === 0) {
                    $error = 'Nie mozesz zdezaktywowac swojego konta.';
                } elseif ($nextState === 0 && role_raw_has((string)$userRow['role'], 'admin') && (int)$userRow['is_active'] === 1 && active_admins_count($pdo) <= 1) {
                    $error = 'Nie mozna zdezaktywowac ostatniego aktywnego admina.';
                } elseif ((int)$userRow['is_active'] === $nextState) {
                    $ok = $nextState === 1 ? 'Konto jest juz aktywne.' : 'Konto jest juz nieaktywne.';
                } else {
                    $update = $pdo->prepare('UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
                    $update->execute([$nextState, $targetUserId]);
                    $ok = $nextState === 1
                        ? ('Konto ' . h((string)$userRow['email']) . ' zostalo aktywowane.')
                        : ('Konto ' . h((string)$userRow['email']) . ' zostalo zdezaktywowane.');
                }
            } catch (Throwable $e) {
                $error = 'Nie udalo sie zmienic statusu uzytkownika.';
            }
        }
    } else {
        $emailInput = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password2'] ?? '');
        $roleInput = (string)($_POST['role'] ?? 'viewer');
        $hasGlobalVideoAccessInput = ((string)($_POST['has_global_video_access'] ?? '0') === '1');
        $isActiveInput = ((string)($_POST['is_active'] ?? '0') === '1');
        $hasGlobalVideoAccessValue = ($roleInput === 'editor' && $hasGlobalVideoAccessInput) ? 1 : 0;

        if (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
            $error = 'Podaj poprawny adres e-mail.';
        } elseif (strlen($password) < 8) {
            $error = 'Haslo musi miec co najmniej 8 znakow.';
        } elseif ($password !== $password2) {
            $error = 'Hasla nie sa identyczne.';
        } elseif (!in_array($roleInput, ['editor', 'viewer'], true)) {
            $error = 'Niepoprawna rola.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                if ($hash === false) {
                    throw new RuntimeException('Blad haszowania hasla.');
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO users (email, password_hash, role, has_global_video_access, is_active, email_verified_at)
                     VALUES (?, ?, ?, ?, ?, NOW())'
                );
                $stmt->execute([
                    $emailInput,
                    $hash,
                    $roleInput,
                    $hasGlobalVideoAccessValue,
                    $isActiveInput ? 1 : 0,
                ]);
                $ok = 'Uzytkownik zostal dodany.';
                $emailInput = '';
                $roleInput = 'viewer';
                $hasGlobalVideoAccessInput = false;
                $isActiveInput = true;
            } catch (PDOException $e) {
                $sqlState = (string)($e->errorInfo[0] ?? '');
                if ($sqlState === '23000') {
                    $error = 'Konto o tym adresie e-mail juz istnieje.';
                } else {
                    $error = 'Wystapil blad zapisu uzytkownika.';
                }
            } catch (Throwable $e) {
                $error = 'Wystapil blad zapisu uzytkownika.';
            }
        }
    }
}

$users = [];
try {
    $users = $pdo->query(
        'SELECT id, email, role, has_global_video_access, is_active, email_verified_at, created_at, last_login_at
         FROM users
         ORDER BY created_at DESC, id DESC'
    )->fetchAll();
} catch (Throwable $e) {
    if ($error === '') {
        $error = 'Nie mozna pobrac listy uzytkownikow.';
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Uzytkownicy</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; color:#111827; }
    header { background:#040327; color:#fff; padding:16px 24px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
    main { max-width:1100px; margin:24px auto; padding:0 24px; display:grid; gap:16px; }
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
    table { width:100%; border-collapse:collapse; font-size:14px; }
    th, td { text-align:left; border-bottom:1px solid #e5e7eb; padding:8px 6px; vertical-align:top; }
    .tag { display:inline-block; border-radius:999px; padding:3px 8px; font-size:12px; font-weight:700; }
    .tag-on { background:#dcfce7; color:#166534; }
    .tag-off { background:#fee2e2; color:#991b1b; }
    .cell-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .inline-form { margin:0; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
</head>
<body>
  <header>
    <div><strong>Uzytkownicy - administracja</strong></div>
    <div>
      Zalogowano jako: <?php echo h(current_user_email()); ?> (<?php echo h(current_user_role()); ?>)
      &nbsp;|&nbsp;
      <a class="btn secondary" href="index.php">Powrot do panelu</a>
    </div>
  </header>
  <main>
    <section class="card">
      <h1 style="margin-top:0;">Uzytkownicy</h1>
      <p class="muted">Zarzadzaj kontami uzytkownikow. Dezaktywacja blokuje logowanie, ale zachowuje wszystkie powiazania i historie danych.</p>
      <?php if ($error !== ''): ?><p class="err"><?php echo h($error); ?></p><?php endif; ?>
      <?php if ($ok !== ''): ?><p class="ok"><?php echo $ok; ?></p><?php endif; ?>
    </section>

    <section class="card">
      <h2 style="margin-top:0;">Dodaj uzytkownika</h2>
      <p class="muted">Dostepne role przy dodawaniu: <code>editor</code>, <code>viewer</code>. Pelna edycja konta jest dostepna z listy uzytkownikow.</p>
      <form method="post" action="users.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="create">

        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" required value="<?php echo h($emailInput); ?>" placeholder="user@example.com">

        <label for="password">Haslo (min. 8 znakow)</label>
        <input id="password" name="password" type="password" minlength="8" required>

        <label for="password2">Powtorz haslo</label>
        <input id="password2" name="password2" type="password" minlength="8" required>

        <label for="role">Rola</label>
        <select id="role" name="role" required>
          <option value="viewer" <?php echo $roleInput === 'viewer' ? 'selected' : ''; ?>>viewer</option>
          <option value="editor" <?php echo $roleInput === 'editor' ? 'selected' : ''; ?>>editor</option>
        </select>

        <label class="checkbox-row">
          <input type="checkbox" name="has_global_video_access" value="1" <?php echo $hasGlobalVideoAccessInput ? 'checked' : ''; ?>>
          Globalny dostep do wszystkich video (dla trenera/editor)
        </label>

        <label class="checkbox-row">
          <input type="checkbox" name="is_active" value="1" <?php echo $isActiveInput ? 'checked' : ''; ?>>
          Aktywny uzytkownik
        </label>

        <button type="submit" style="margin-top:16px;">Dodaj uzytkownika</button>
      </form>
    </section>

    <section class="card">
      <h2 style="margin-top:0;">Lista uzytkownikow</h2>
      <?php if (!$users): ?>
        <p>Brak uzytkownikow.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>E-mail</th>
              <th>Rola</th>
              <th>Zakres video</th>
              <th>Aktywny</th>
              <th>E-mail potwierdzony</th>
              <th>Utworzono</th>
              <th>Ostatnie logowanie</th>
              <th>Akcje</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <?php
                $userIsActive = (int)$u['is_active'] === 1;
                $targetId = (int)$u['id'];
                $isSelf = $targetId === current_user_id();
              ?>
              <tr>
                <td><?php echo h((string)$u['email']); ?></td>
                <td><code><?php echo h((string)$u['role']); ?></code></td>
                <td>
                  <?php
                    $userRole = (string)($u['role'] ?? '');
                    $hasGlobalVideoAccess = (int)($u['has_global_video_access'] ?? 0) === 1;
                    if ($userRole === 'editor') {
                        echo h($hasGlobalVideoAccess ? 'wszystkie' : 'przypisane');
                    } else {
                        echo '-';
                    }
                  ?>
                </td>
                <td>
                  <?php if ($userIsActive): ?>
                    <span class="tag tag-on">tak</span>
                  <?php else: ?>
                    <span class="tag tag-off">nie</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ((string)($u['email_verified_at'] ?? '') !== ''): ?>
                    <span class="tag tag-on">tak</span>
                  <?php else: ?>
                    <span class="tag tag-off">nie</span>
                  <?php endif; ?>
                </td>
                <td><?php echo h((string)$u['created_at']); ?></td>
                <td><?php echo h((string)($u['last_login_at'] ?? '')); ?></td>
                <td>
                  <div class="cell-actions">
                    <a class="btn secondary" href="users_edit.php?id=<?php echo $targetId; ?>">Edytuj</a>
                    <form class="inline-form" method="post" action="users.php" onsubmit="return confirm('<?php echo $userIsActive ? 'Zdezaktywowac to konto?' : 'Aktywowac to konto?'; ?>');">
                      <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                      <input type="hidden" name="action" value="toggle_active">
                      <input type="hidden" name="user_id" value="<?php echo $targetId; ?>">
                      <input type="hidden" name="next_state" value="<?php echo $userIsActive ? '0' : '1'; ?>">
                      <button type="submit" class="<?php echo $userIsActive ? 'warn' : 'secondary'; ?>" <?php echo $isSelf && $userIsActive ? 'title="Nie mozesz zdezaktywowac swojego konta."' : ''; ?>>
                        <?php echo $userIsActive ? 'Dezaktywuj' : 'Aktywuj'; ?>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
