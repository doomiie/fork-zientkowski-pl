<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_login();
require_admin();

$error = '';
$ok = '';

// Ensure target directory exists
$targetDir = dirname(__DIR__) . '/docs/files';
if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $error = 'Nieprawidłowy token bezpieczeństwa.';
    } else {
        $display = trim((string)($_POST['display_name'] ?? ''));
        $expires = trim((string)($_POST['expires_at'] ?? ''));
        $expiresAt = null;
        if ($expires !== '') {
            // Normalize to YYYY-MM-DD HH:MM:SS if only date provided
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) {
                $expiresAt = $expires . ' 23:59:59';
            } else {
                $expiresAt = $expires;
            }
        }

        if ($display === '') {
            $error = 'Podaj nazwę wyświetlaną.';
        } elseif (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            $error = 'Wybierz plik do wysłania.';
        } else {
            try {
                $orig = (string)($_FILES['file']['name'] ?? 'file');
                $size = (int)($_FILES['file']['size'] ?? 0);
                $mime = (string)($_FILES['file']['type'] ?? 'application/octet-stream');
                $ext = pathinfo($orig, PATHINFO_EXTENSION);
                $safeExt = $ext ? ('.' . preg_replace('/[^a-zA-Z0-9]+/', '', $ext)) : '';
                $basename = bin2hex(random_bytes(8)) . $safeExt;
                $destPath = $targetDir . '/' . $basename;
                if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
                    throw new RuntimeException('Przeniesienie pliku nie powiodło się.');
                }
                $publicPath = '/docs/files/' . $basename;
                $stmt = $pdo->prepare('INSERT INTO doc_files (display_name, file_path, original_name, mime_type, file_size, expires_at) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$display, $publicPath, $orig, $mime, $size, $expiresAt]);
                $ok = 'Dodano plik.';
                if (isset($_POST['ajax'])) {
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode([
                        'ok' => true,
                        'id' => (int)$pdo->lastInsertId(),
                        'display_name' => $display,
                        'file_path' => $publicPath,
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            } catch (Throwable $e) {
                if (isset($_POST['ajax'])) {
                    header('Content-Type: application/json; charset=UTF-8');
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
                    exit;
                }
                $error = 'Błąd wysyłania: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
    }
}

// Load list
try {
    $rows = $pdo->query('SELECT id, display_name, file_path, original_name, file_size, expires_at, created_at FROM doc_files ORDER BY created_at DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $rows = [];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dokumenty do pobrania</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; }
    header { background:#040327; color:#fff; padding:16px 24px; display:flex; justify-content:space-between; align-items:center; }
    main { max-width:1100px; margin:24px auto; padding:0 24px; }
    .card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 12px 30px rgba(0,0,0,.06); }
    label { display:block; font-weight:600; margin:12px 0 6px; }
    input[type=text], input[type=date], input[type=datetime-local], input[type=file] { width:100%; padding:12px 14px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; }
    table { width:100%; border-collapse:collapse; margin-top:16px; }
    th, td { text-align:left; padding:8px 10px; border-bottom:1px solid #e5e7eb; font-size:14px; }
    .error { background:#fee2e2; color:#7f1d1d; padding:10px 12px; border-radius:8px; margin:12px 0; font-size:14px; }
    .ok { background:#ecfdf5; color:#065f46; padding:10px 12px; border-radius:8px; margin:12px 0; font-size:14px; }
    a.btn { display:inline-block; background:#fff; color:#040327; border:1px solid #9ca3af; padding:8px 12px; border-radius:8px; text-decoration:none; font-weight:600; }
    button { background:#040327; color:#fff; border:0; border-radius:10px; padding:12px 16px; font-weight:600; cursor:pointer; }
    .muted { color:#6b7280; font-size:13px; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self';">
  <meta name="robots" content="noindex,nofollow">
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
      <h1>Dokumenty do pobrania</h1>
      <?php if ($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="ok"><?php echo $ok; ?></div><?php endif; ?>

      <form id="uploadForm" method="post" action="docs.php" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <label for="display_name">Nazwa wyświetlana</label>
        <input type="text" id="display_name" name="display_name" placeholder="np. Regulamin 2025.pdf">
        <label for="file">Plik</label>
        <input type="file" id="file" name="file" required>
        <label for="expires_at">Wygasa (opcjonalnie)</label>
        <input type="date" id="expires_at" name="expires_at" placeholder="YYYY-MM-DD">
        <div style="margin-top:12px"><button type="submit">Dodaj plik</button></div>
        <div id="progressWrap" style="margin-top:12px;" class="hidden">
          <div style="height:10px; background:#e5e7eb; border-radius:8px; overflow:hidden;">
            <div id="progressBar" style="height:10px; width:0; background:#040327;"></div>
          </div>
          <div id="progressText" class="muted" style="margin-top:6px;">0%</div>
          <pre id="progressLog" class="muted" style="margin-top:6px; max-height:120px; overflow:auto; background:#f8fafc; padding:8px; border-radius:8px;"></pre>
        </div>
        <div class="muted" style="margin-top:8px;">Po dacie wygaśnięcia plik nie będzie widoczny na stronie /docs.</div>
      </form>

      <h2 style="margin-top:20px;">Ostatnio dodane</h2>
      <table>
        <thead>
          <tr><th>Nazwa</th><th>Plik</th><th>Rozmiar</th><th>Wygasa</th><th>Dodano</th></tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo htmlspecialchars((string)$r['display_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
              <td><a href="<?php echo htmlspecialchars((string)$r['file_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" target="_blank">pobierz</a></td>
              <td><?php echo htmlspecialchars(number_format((int)$r['file_size'] ?: 0, 0, ',', ' ') . ' B', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars((string)($r['expires_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars((string)$r['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </main>
  <script defer src="docs.js"></script>
</body>
</html>
