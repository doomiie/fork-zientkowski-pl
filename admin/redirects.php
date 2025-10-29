<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_login();
require_admin();

$error = '';
$ok = '';

// Handle delete via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $error = 'Nieprawidłowy token bezpieczeństwa (delete).';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare('DELETE FROM redirects WHERE id = ?');
                $stmt->execute([$id]);
                $ok = 'Przekierowanie usunięte.';
            } catch (Throwable $e) {
                $error = 'Nie udało się usunąć przekierowania.';
            }
        }
    }
}

// Fetch all redirects
$rows = [];
try {
    $rows = $pdo->query('SELECT id, link, http_code, target, expires_at, fallback, is_active, hit_count, last_hit_at FROM redirects ORDER BY created_at DESC')->fetchAll();
} catch (Throwable $e) {
    $error = 'Nie można pobrać listy przekierowań (czy uruchomiono create_redirects.sql?).';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Przekierowania</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; }
    header { background:#040327; color:#fff; padding:16px 24px; display:flex; justify-content:space-between; align-items:center; }
    main { max-width:1100px; margin:24px auto; padding:0 24px; }
    .card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 12px 30px rgba(0,0,0,.06); }
    a.btn, button.btn { display:inline-block; background:#fff; color:#040327; border:1px solid #9ca3af; padding:8px 12px; border-radius:8px; text-decoration:none; font-weight:600; cursor:pointer; }
    table { width:100%; border-collapse:collapse; }
    th, td { text-align:left; border-bottom:1px solid #e5e7eb; padding:8px 6px; font-size:14px; }
    .muted { color:#6b7280; font-size:13px; }
    .ok { background:#ecfdf5; color:#065f46; padding:10px 12px; border-radius:8px; margin:12px 0; font-size:14px; }
    .error { background:#fee2e2; color:#7f1d1d; padding:10px 12px; border-radius:8px; margin:12px 0; font-size:14px; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
</head>
<body>
  <header>
    <div><strong>Panel administracyjny</strong></div>
    <div>
      Zalogowano jako: <?php echo htmlspecialchars(current_user_email(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> | <?php echo htmlspecialchars(current_user_role(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
      &nbsp;|&nbsp;
      <a class="btn" href="index.php">Powrót</a>
      &nbsp;|&nbsp;
      <a class="btn" href="logout.php">Wyloguj</a>
    </div>
  </header>
  <main>
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
        <h1 style="margin:0;">Przekierowania</h1>
        <a class="btn" href="redirects_edit.php">Dodaj przekierowanie</a>
      </div>
      <p class="muted">Zarządzaj regułami: link → (301/302/307/308) → cel | ważne do → fallback.</p>
      <?php if ($ok): ?><div class="ok"><?php echo htmlspecialchars($ok, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
      <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
      <div style="overflow:auto;">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Link</th>
              <th>Kod</th>
              <th>Cel</th>
              <th>Ważne do</th>
              <th>Fallback</th>
              <th>Aktywne</th>
              <th>Hity</th>
              <th>Ostatni hit</th>
              <th>Akcje</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?php echo (int)$r['id']; ?></td>
                <td><code><?php echo htmlspecialchars($r['link'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></code></td>
                <td><?php echo (int)$r['http_code']; ?></td>
                <td style="max-width:260px; overflow:auto;"><code><?php echo htmlspecialchars($r['target'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></code></td>
                <td><?php echo htmlspecialchars((string)$r['expires_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                <td style="max-width:260px; overflow:auto;"><code><?php echo htmlspecialchars($r['fallback'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></code></td>
                <td><?php echo ((int)$r['is_active'] ? 'tak' : 'nie'); ?></td>
                <td><?php echo (int)$r['hit_count']; ?></td>
                <td><?php echo htmlspecialchars((string)$r['last_hit_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                <td>
                  <a class="btn" href="redirects_edit.php?id=<?php echo (int)$r['id']; ?>">Edytuj</a>
                  <form method="post" action="redirects.php" style="display:inline" onsubmit="return confirm('Usunąć przekierowanie?');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <button class="btn" type="submit">Usuń</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="10" class="muted">Brak reguł przekierowań.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</body>
</html>

