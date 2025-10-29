<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_login();
require_admin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;

$error = '';
$ok = '';

// Defaults
$link = '';
$http_code = 302;
$target = '';
$expires_at = '';
$fallback = '';
$is_active = 1;

// Load when editing
if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM redirects WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo 'Nie znaleziono przekierowania';
        exit;
    }
    $link = (string)$row['link'];
    $http_code = (int)$row['http_code'];
    $target = (string)$row['target'];
    $expires_at = (string)($row['expires_at'] ?? '');
    $fallback = (string)$row['fallback'];
    $is_active = (int)$row['is_active'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_check($token)) {
        $error = 'Nieprawidłowy token bezpieczeństwa.';
    } else {
        $link = trim((string)($_POST['link'] ?? ''));
        $http_code = (int)($_POST['http_code'] ?? 302);
        $target = trim((string)($_POST['target'] ?? ''));
        $expires_at = trim((string)($_POST['expires_at'] ?? ''));
        $fallback = trim((string)($_POST['fallback'] ?? ''));
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Normalize link to start with '/'
        if ($link !== '' && $link[0] !== '/') $link = '/' . $link;

        // Validate
        $allowed = [301,302,307,308];
        if ($link === '') {
            $error = 'Podaj link (np. /webinar).';
        } elseif (!in_array($http_code, $allowed, true)) {
            $error = 'Nieprawidłowy kod HTTP (dozwolone: 301,302,307,308).';
        } elseif ($target === '') {
            $error = 'Podaj cel przekierowania.';
        } elseif ($fallback === '') {
            $error = 'Podaj fallback (nie może być pusty).';
        } else {
            $expValue = null;
            if ($expires_at !== '') {
                try {
                    $dt = new DateTime($expires_at);
                    $expValue = $dt->format('Y-m-d H:i:s');
                } catch (Throwable $e) {
                    $error = 'Nieprawidłowa data w polu "Ważne do" (użyj formatu RRRR-MM-DD GG:MM:SS lub zostaw puste).';
                }
            }

            if ($error === '') {
                try {
                    if ($isEdit) {
                        $stmt = $pdo->prepare('UPDATE redirects SET link=?, http_code=?, target=?, expires_at=?, fallback=?, is_active=? WHERE id=?');
                        $stmt->execute([$link, $http_code, $target, $expValue, $fallback, $is_active, $id]);
                        $ok = 'Zapisano zmiany.';
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO redirects (link, http_code, target, expires_at, fallback, is_active) VALUES (?,?,?,?,?,?)');
                        $stmt->execute([$link, $http_code, $target, $expValue, $fallback, $is_active]);
                        $id = (int)$pdo->lastInsertId();
                        $isEdit = true;
                        $ok = 'Dodano przekierowanie.';
                    }
                } catch (PDOException $e) {
                    if ((int)$e->errorInfo[1] === 1062) {
                        $error = 'Istnieje już przekierowanie dla tego linku.';
                    } else {
                        $error = 'Błąd zapisu w bazie.';
                    }
                } catch (Throwable $e) {
                    $error = 'Błąd zapisu.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $isEdit ? 'Edytuj' : 'Dodaj'; ?> przekierowanie</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; }
    header { background:#040327; color:#fff; padding:16px 24px; display:flex; justify-content:space-between; align-items:center; }
    main { max-width:820px; margin:24px auto; padding:0 24px; }
    .card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 12px 30px rgba(0,0,0,.06); }
    label { display:block; font-weight:600; margin:12px 0 6px; }
    input[type=text], input[type=datetime-local], select { width:100%; padding:12px 14px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; }
    .row { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
    button, a.btn { background:#040327; color:#fff; border:0; border-radius:10px; padding:12px 16px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
    a.btn.secondary { background:#fff; color:#040327; border:1px solid #9ca3af; }
    .error { background:#fee2e2; color:#7f1d1d; padding:10px 12px; border-radius:8px; margin:12px 0; font-size:14px; }
    .ok { background:#ecfdf5; color:#065f46; padding:10px 12px; border-radius:8px; margin:12px 0; font-size:14px; }
    .muted { color:#6b7280; font-size:13px; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
</head>
<body>
  <header>
    <div><strong>Panel administracyjny</strong></div>
    <div>
      Zalogowano jako: <?php echo htmlspecialchars(current_user_email(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> | <?php echo htmlspecialchars(current_user_role(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
      &nbsp;|&nbsp;
      <a class="btn secondary" href="redirects.php">Lista</a>
      &nbsp;|&nbsp;
      <a class="btn secondary" href="index.php">Panel</a>
      &nbsp;|&nbsp;
      <a class="btn secondary" href="logout.php">Wyloguj</a>
    </div>
  </header>
  <main>
    <div class="card">
      <h1 style="margin-top:0;"><?php echo $isEdit ? 'Edytuj' : 'Dodaj'; ?> przekierowanie</h1>
      <p class="muted">Ważne do: jeśli puste — bezterminowo. Po dacie: kieruje na fallback (wymagany).</p>
      <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="ok"><?php echo htmlspecialchars($ok, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
      <form method="post" action="redirects_edit.php<?php echo $isEdit ? ('?id=' . (int)$id) : '';?>" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <label for="link">Link (np. /webinar)</label>
        <input type="text" id="link" name="link" value="<?php echo htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>

        <div class="row">
          <div>
            <label for="http_code">Typ przekierowania</label>
            <select id="http_code" name="http_code">
              <?php foreach ([301,302,307,308] as $code): ?>
                <option value="<?php echo $code; ?>" <?php echo ($http_code===$code?'selected':''); ?>><?php echo $code; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="expires_at">Ważne do</label>
            <input type="datetime-local" id="expires_at" name="expires_at" value="<?php echo htmlspecialchars($expires_at ? (new DateTime($expires_at))->format('Y-m-d\TH:i:s') : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          </div>
        </div>

        <label for="target">Cel (np. /webinar2025.html)</label>
        <input type="text" id="target" name="target" value="<?php echo htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>

        <label for="fallback">Fallback (po wygaśnięciu)</label>
        <input type="text" id="fallback" name="fallback" value="<?php echo htmlspecialchars($fallback, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>

        <label><input type="checkbox" name="is_active" value="1" <?php echo ($is_active ? 'checked' : ''); ?>> Aktywne</label>

        <div style="margin-top:16px; display:flex; gap:8px;">
          <button type="submit">Zapisz</button>
          <a class="btn secondary" href="redirects.php">Anuluj</a>
        </div>
      </form>
    </div>
  </main>
</body>
</html>

