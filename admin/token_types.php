<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_login();
require_admin();

$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $error = 'Nieprawidłowy token bezpieczeństwa.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'save') {
                $id = (int)($_POST['id'] ?? 0);
                $code = trim((string)($_POST['code'] ?? ''));
                $title = trim((string)($_POST['title'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                $price = (float)($_POST['price_gross_pln'] ?? 0);
                $currency = strtoupper(trim((string)($_POST['currency'] ?? 'PLN')));
                $maxUploads = max(0, (int)($_POST['max_upload_links'] ?? 0));
                $canChoose = ((int)($_POST['can_choose_trainer'] ?? 0) === 1) ? 1 : 0;
                $isActive = ((int)($_POST['is_active'] ?? 0) === 1) ? 1 : 0;
                $sortOrder = max(0, (int)($_POST['sort_order'] ?? 100));

                if ($code === '' || $title === '' || $price <= 0) {
                    throw new RuntimeException('Uzupełnij kod, tytuł i cenę > 0.');
                }
                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE token_types
                         SET code=?, title=?, description=?, price_gross_pln=?, currency=?, max_upload_links=?, can_choose_trainer=?, is_active=?, sort_order=?, updated_at=NOW()
                         WHERE id=?'
                    );
                    $stmt->execute([$code, $title, $description !== '' ? $description : null, $price, $currency, $maxUploads, $canChoose, $isActive, $sortOrder, $id]);
                    $ok = 'Zaktualizowano typ żetonu.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO token_types (code, title, description, price_gross_pln, currency, max_upload_links, can_choose_trainer, is_active, sort_order, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                    );
                    $stmt->execute([$code, $title, $description !== '' ? $description : null, $price, $currency, $maxUploads, $canChoose, $isActive, $sortOrder]);
                    $ok = 'Dodano typ żetonu.';
                }
            }
        } catch (Throwable $e) {
            $error = 'Błąd: ' . mb_substr($e->getMessage(), 0, 220);
        }
    }
}

$types = $pdo->query('SELECT * FROM token_types ORDER BY sort_order ASC, id ASC')->fetchAll() ?: [];
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin - Typy żetonów</title>
  <style>
    body{font-family:Segoe UI,Tahoma,sans-serif;background:#f3f5f9;margin:0;padding:20px}
    .wrap{max-width:1200px;margin:0 auto}
    .card{background:#fff;border:1px solid #d9e1ec;border-radius:12px;padding:14px;margin-bottom:12px}
    .grid{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}
    input,select,textarea{width:100%;padding:10px;border:1px solid #d9e1ec;border-radius:8px}
    button,a.btn{padding:9px 12px;border-radius:8px;border:1px solid #d9e1ec;background:#040327;color:#fff;text-decoration:none;cursor:pointer}
    a.btn{display:inline-block;background:#fff;color:#111}
    table{width:100%;border-collapse:collapse}
    th,td{padding:8px 6px;border-bottom:1px solid #e8edf4;text-align:left;font-size:14px}
    .ok{color:#065f46}.err{color:#991b1b}
  </style>
</head>
<body>
<div class="wrap">
  <p><a class="btn" href="index.php">Powrót do admin</a></p>
  <div class="card">
    <h1 style="margin-top:0">Typy żetonów</h1>
    <?php if ($error): ?><p class="err"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($ok): ?><p class="ok"><?php echo htmlspecialchars($ok, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p><?php endif; ?>
    <form method="post" action="token_types.php">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="save">
      <div class="grid">
        <label>Kod<input name="code" required></label>
        <label>Tytuł<input name="title" required></label>
        <label>Cena brutto PLN<input name="price_gross_pln" type="number" step="0.01" min="0.01" required></label>
        <label>Waluta<input name="currency" value="PLN" maxlength="3"></label>
        <label>Max upload links<input name="max_upload_links" type="number" min="0" value="1"></label>
        <label>Sort<input name="sort_order" type="number" min="0" value="100"></label>
        <label>Wybór trenera
          <select name="can_choose_trainer"><option value="0">Nie</option><option value="1">Tak</option></select>
        </label>
        <label>Aktywny
          <select name="is_active"><option value="1">Tak</option><option value="0">Nie</option></select>
        </label>
      </div>
      <label>Opis<textarea name="description" rows="2"></textarea></label>
      <p><button type="submit">Dodaj typ</button></p>
    </form>
  </div>

  <div class="card">
    <table>
      <thead><tr><th>ID</th><th>Kod</th><th>Tytuł</th><th>Cena</th><th>Upload</th><th>Wybór trenera</th><th>Status</th><th>Akcja</th></tr></thead>
      <tbody>
      <?php foreach ($types as $t): ?>
        <tr>
          <td><?php echo (int)$t['id']; ?></td>
          <td><?php echo htmlspecialchars((string)$t['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string)$t['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string)$t['price_gross_pln'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> <?php echo htmlspecialchars((string)$t['currency'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
          <td><?php echo (int)$t['max_upload_links']; ?></td>
          <td><?php echo ((int)$t['can_choose_trainer'] === 1) ? 'tak' : 'nie'; ?></td>
          <td><?php echo ((int)$t['is_active'] === 1) ? 'aktywny' : 'off'; ?></td>
          <td>
            <details>
              <summary>Edytuj</summary>
              <form method="post" action="token_types.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                <input name="code" value="<?php echo htmlspecialchars((string)$t['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                <input name="title" value="<?php echo htmlspecialchars((string)$t['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                <input name="price_gross_pln" type="number" step="0.01" min="0.01" value="<?php echo htmlspecialchars((string)$t['price_gross_pln'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                <input name="currency" value="<?php echo htmlspecialchars((string)$t['currency'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" maxlength="3">
                <input name="max_upload_links" type="number" min="0" value="<?php echo (int)$t['max_upload_links']; ?>">
                <input name="sort_order" type="number" min="0" value="<?php echo (int)$t['sort_order']; ?>">
                <select name="can_choose_trainer"><option value="0" <?php echo ((int)$t['can_choose_trainer'] !== 1) ? 'selected' : ''; ?>>Nie</option><option value="1" <?php echo ((int)$t['can_choose_trainer'] === 1) ? 'selected' : ''; ?>>Tak</option></select>
                <select name="is_active"><option value="1" <?php echo ((int)$t['is_active'] === 1) ? 'selected' : ''; ?>>Tak</option><option value="0" <?php echo ((int)$t['is_active'] !== 1) ? 'selected' : ''; ?>>Nie</option></select>
                <textarea name="description" rows="2"><?php echo htmlspecialchars((string)($t['description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
                <button type="submit">Zapisz</button>
              </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>

