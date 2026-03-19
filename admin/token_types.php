<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require_login();
require_admin();

$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $error = 'Nieprawidlowy token bezpieczenstwa.';
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
                    throw new RuntimeException('Uzupelnij kod, tytul i cene > 0.');
                }

                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE token_types
                         SET code=?, title=?, description=?, price_gross_pln=?, currency=?, max_upload_links=?, can_choose_trainer=?, is_active=?, sort_order=?, updated_at=NOW()
                         WHERE id=?'
                    );
                    $stmt->execute([$code, $title, $description !== '' ? $description : null, $price, $currency, $maxUploads, $canChoose, $isActive, $sortOrder, $id]);
                    $ok = 'Zaktualizowano typ zetonu.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO token_types (code, title, description, price_gross_pln, currency, max_upload_links, can_choose_trainer, is_active, sort_order, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                    );
                    $stmt->execute([$code, $title, $description !== '' ? $description : null, $price, $currency, $maxUploads, $canChoose, $isActive, $sortOrder]);
                    $ok = 'Dodano typ zetonu.';
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('Niepoprawny identyfikator typu zetonu.');
                }

                $stmt = $pdo->prepare('DELETE FROM token_types WHERE id = ? LIMIT 1');
                $stmt->execute([$id]);
                if ($stmt->rowCount() < 1) {
                    throw new RuntimeException('Nie znaleziono typu zetonu do usuniecia.');
                }
                $ok = 'Usunieto typ zetonu.';
            }
        } catch (PDOException $e) {
            $sqlState = (string)($e->errorInfo[0] ?? '');
            if ($action === 'delete' && $sqlState === '23000') {
                $error = 'Nie mozna usunac tego typu zetonu, bo jest powiazany z zamowieniami lub uprawnieniami.';
            } else {
                $error = 'Blad: ' . mb_substr($e->getMessage(), 0, 220);
            }
        } catch (Throwable $e) {
            $error = 'Blad: ' . mb_substr($e->getMessage(), 0, 220);
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
  <title>Admin - Typy zetonow</title>
  <style>
    body{font-family:Segoe UI,Tahoma,sans-serif;background:#f3f5f9;margin:0;padding:20px}
    .wrap{max-width:1200px;margin:0 auto}
    .card{background:#fff;border:1px solid #d9e1ec;border-radius:12px;padding:14px;margin-bottom:12px}
    .grid{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}
    input,select,textarea{width:100%;padding:10px;border:1px solid #d9e1ec;border-radius:8px;box-sizing:border-box}
    button,a.btn{padding:9px 12px;border-radius:8px;border:1px solid #d9e1ec;background:#040327;color:#fff;text-decoration:none;cursor:pointer}
    a.btn{display:inline-block;background:#fff;color:#111}
    button.danger{background:#fff7f7;color:#991b1b;border-color:#f1c9c9}
    table{width:100%;border-collapse:collapse}
    th,td{padding:8px 6px;border-bottom:1px solid #e8edf4;text-align:left;font-size:14px;vertical-align:top}
    .ok{color:#065f46}.err{color:#991b1b}
    .hint{display:block;margin-top:4px;color:#6b7280;font-size:12px;line-height:1.35}
    .inline-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
    details form{display:grid;gap:8px;margin-top:10px}
  </style>
</head>
<body>
<div class="wrap">
  <p><a class="btn" href="index.php">Powrot do admin</a></p>

  <div class="card">
    <h1 style="margin-top:0">Typy zetonow</h1>
    <?php if ($error): ?><p class="err"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($ok): ?><p class="ok"><?php echo htmlspecialchars($ok, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p><?php endif; ?>

    <form method="post" action="token_types.php">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="save">
      <div class="grid">
        <label>Kod
          <input name="code" required>
          <span class="hint">Unikalny kod techniczny typu zetonu, np. <code>START_1</code>.</span>
        </label>
        <label>Tytul
          <input name="title" required>
          <span class="hint">Nazwa widoczna dla klienta w ofercie i przy zakupie.</span>
        </label>
        <label>Cena brutto PLN
          <input name="price_gross_pln" type="number" step="0.01" min="0.01" required>
          <span class="hint">Cena koncowa placona przez klienta.</span>
        </label>
        <label>Waluta
          <input name="currency" value="PLN" maxlength="3">
          <span class="hint">Trzyliterowy kod waluty, zwykle <code>PLN</code>.</span>
        </label>
        <label>Max upload links
          <input name="max_upload_links" type="number" min="0" value="1">
          <span class="hint">Ile filmow lub linkow klient moze dodac po zakupie.</span>
        </label>
        <label>Sort
          <input name="sort_order" type="number" min="0" value="100">
          <span class="hint">Mniejsza liczba = wyzej na liscie.</span>
        </label>
        <label>Wybor trenera
          <select name="can_choose_trainer"><option value="0">Nie</option><option value="1">Tak</option></select>
          <span class="hint">Czy klient moze wybrac konkretnego trenera.</span>
        </label>
        <label>Aktywny
          <select name="is_active"><option value="1">Tak</option><option value="0">Nie</option></select>
          <span class="hint">Tylko aktywne typy sa dostepne w sprzedazy.</span>
        </label>
      </div>
      <label>Opis
        <textarea name="description" rows="2"></textarea>
        <span class="hint">Krotki opis tego typu zetonu pokazywany uzytkownikowi.</span>
      </label>
      <p><button type="submit">Dodaj typ</button></p>
    </form>
  </div>

  <div class="card">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Kod</th>
          <th>Tytul</th>
          <th>Cena</th>
          <th>Upload</th>
          <th>Wybor trenera</th>
          <th>Status</th>
          <th>Akcja</th>
        </tr>
      </thead>
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

                <label>Kod
                  <input name="code" value="<?php echo htmlspecialchars((string)$t['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                  <span class="hint">Unikalny kod techniczny typu zetonu.</span>
                </label>
                <label>Tytul
                  <input name="title" value="<?php echo htmlspecialchars((string)$t['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                  <span class="hint">Nazwa widoczna dla klienta.</span>
                </label>
                <label>Cena brutto PLN
                  <input name="price_gross_pln" type="number" step="0.01" min="0.01" value="<?php echo htmlspecialchars((string)$t['price_gross_pln'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                  <span class="hint">Cena koncowa tego typu zetonu.</span>
                </label>
                <label>Waluta
                  <input name="currency" value="<?php echo htmlspecialchars((string)$t['currency'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" maxlength="3">
                  <span class="hint">Kod waluty, np. PLN.</span>
                </label>
                <label>Max upload links
                  <input name="max_upload_links" type="number" min="0" value="<?php echo (int)$t['max_upload_links']; ?>">
                  <span class="hint">Limit liczby filmow lub linkow.</span>
                </label>
                <label>Sort
                  <input name="sort_order" type="number" min="0" value="<?php echo (int)$t['sort_order']; ?>">
                  <span class="hint">Mniejsza liczba wyswietla typ wyzej.</span>
                </label>
                <label>Wybor trenera
                  <select name="can_choose_trainer"><option value="0" <?php echo ((int)$t['can_choose_trainer'] !== 1) ? 'selected' : ''; ?>>Nie</option><option value="1" <?php echo ((int)$t['can_choose_trainer'] === 1) ? 'selected' : ''; ?>>Tak</option></select>
                  <span class="hint">Pozwala klientowi wybrac trenera.</span>
                </label>
                <label>Aktywny
                  <select name="is_active"><option value="1" <?php echo ((int)$t['is_active'] === 1) ? 'selected' : ''; ?>>Tak</option><option value="0" <?php echo ((int)$t['is_active'] !== 1) ? 'selected' : ''; ?>>Nie</option></select>
                  <span class="hint">Nieaktywny typ nie pojawia sie w sprzedazy.</span>
                </label>
                <label>Opis
                  <textarea name="description" rows="2"><?php echo htmlspecialchars((string)($t['description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
                  <span class="hint">Krotki opis dla klienta.</span>
                </label>

                <div class="inline-actions">
                  <button type="submit">Zapisz</button>
                </div>
              </form>

              <form method="post" action="token_types.php" onsubmit="return confirm('Usunac ten typ zetonu?');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                <p class="inline-actions"><button type="submit" class="danger">Usun</button></p>
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
