<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require_once __DIR__ . '/../backend/video_tokens_lib.php';
require_login();
require_admin();

$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $error = 'Nieprawidlowy token bezpieczenstwa.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            $error = 'Niepoprawny identyfikator zamowienia.';
        } else {
            try {
                if ($action === 'mark_paid') {
                    $pdo->prepare("UPDATE token_orders SET status='paid', paid_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$orderId]);
                    vt_grant_order_entitlements($pdo, $orderId);
                    $ok = 'Zamowienie oznaczone jako oplacone i zaksięgowane.';
                } elseif ($action === 'mark_failed') {
                    $pdo->prepare("UPDATE token_orders SET status='failed', updated_at=NOW() WHERE id=?")->execute([$orderId]);
                    $ok = 'Zamowienie oznaczone jako failed.';
                } elseif ($action === 'grant') {
                    if (vt_grant_order_entitlements($pdo, $orderId)) {
                        $ok = 'Zaksiegowano uprawnienia.';
                    } else {
                        $error = 'Nie udalo sie zaksięgowac uprawnien (sprawdz status).';
                    }
                } elseif ($action === 'delete') {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare(
                        'SELECT id, order_uuid
                         FROM token_orders
                         WHERE id = ?
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $stmt->execute([$orderId]);
                    $order = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$order) {
                        throw new RuntimeException('Nie znaleziono zamowienia do usuniecia.');
                    }

                    $pdo->prepare('DELETE FROM user_token_entitlements WHERE source_order_id = ?')->execute([$orderId]);
                    $pdo->prepare('DELETE FROM token_orders WHERE id = ? LIMIT 1')->execute([$orderId]);

                    $pdo->commit();
                    $ok = 'Usunieto zamowienie zetonow #' . (int)$orderId . '.';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Blad: ' . mb_substr($e->getMessage(), 0, 220);
            }
        }
    }
}

$orders = $pdo->query(
    "SELECT o.id, o.order_uuid, o.status, o.amount_gross_pln, o.currency, o.payment_provider, o.provider_order_id, o.provider_session_id,
            o.paid_at, o.entitlements_granted_at, o.created_at,
            u.email AS user_email, t.title AS token_title,
            (SELECT COUNT(*) FROM user_token_entitlements e WHERE e.source_order_id = o.id) AS entitlements_count
     FROM token_orders o
     JOIN users u ON u.id = o.user_id
     JOIN token_types t ON t.id = o.token_type_id
     ORDER BY o.id DESC
     LIMIT 400"
)->fetchAll() ?: [];
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin - Zamowienia zetonow</title>
  <style>
    body{font-family:Segoe UI,Tahoma,sans-serif;background:#f3f5f9;margin:0;padding:20px}
    .wrap{max-width:1300px;margin:0 auto}
    .card{background:#fff;border:1px solid #d9e1ec;border-radius:12px;padding:14px;margin-bottom:12px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:8px 6px;border-bottom:1px solid #e8edf4;text-align:left;font-size:14px;vertical-align:top}
    button,a.btn{padding:8px 10px;border-radius:8px;border:1px solid #d9e1ec;background:#040327;color:#fff;cursor:pointer}
    a.btn{display:inline-block;text-decoration:none;background:#fff;color:#111}
    button.danger{background:#fff7f7;color:#991b1b;border-color:#f1c9c9}
    form.inline{display:inline}
    .ok{color:#065f46}.err{color:#991b1b}
    .meta{font-size:12px;color:#64748b}
    .actions{display:flex;gap:6px;flex-wrap:wrap}
  </style>
</head>
<body>
<div class="wrap">
  <p><a class="btn" href="index.php">Powrot do admin</a></p>
  <div class="card">
    <h1 style="margin-top:0">Zamowienia zetonow</h1>
    <?php if ($error): ?><p class="err"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($ok): ?><p class="ok"><?php echo htmlspecialchars($ok, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p><?php endif; ?>
    <table>
      <thead>
        <tr>
          <th>ID</th><th>User</th><th>Pakiet</th><th>Status</th><th>Kwota</th><th>Platnosc</th><th>Daty</th><th>Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td>
            <?php echo (int)$o['id']; ?>
            <div class="meta"><?php echo htmlspecialchars((string)$o['order_uuid'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
            <div class="meta">entitlements: <?php echo (int)$o['entitlements_count']; ?></div>
          </td>
          <td><?php echo htmlspecialchars((string)$o['user_email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string)$o['token_title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string)$o['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string)$o['amount_gross_pln'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> <?php echo htmlspecialchars((string)$o['currency'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
          <td>
            <div><?php echo htmlspecialchars((string)$o['payment_provider'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
            <div class="meta">order: <?php echo htmlspecialchars((string)($o['provider_order_id'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
            <div class="meta">session: <?php echo htmlspecialchars((string)($o['provider_session_id'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
          </td>
          <td>
            <div>created: <?php echo htmlspecialchars((string)$o['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
            <div class="meta">paid: <?php echo htmlspecialchars((string)($o['paid_at'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
            <div class="meta">grant: <?php echo htmlspecialchars((string)($o['entitlements_granted_at'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
          </td>
          <td>
            <div class="actions">
              <form class="inline" method="post" action="token_orders.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                <input type="hidden" name="action" value="mark_paid">
                <button type="submit">Mark paid</button>
              </form>
              <form class="inline" method="post" action="token_orders.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                <input type="hidden" name="action" value="grant">
                <button type="submit">Grant</button>
              </form>
              <form class="inline" method="post" action="token_orders.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                <input type="hidden" name="action" value="mark_failed">
                <button type="submit">Mark failed</button>
              </form>
              <form class="inline" method="post" action="token_orders.php" onsubmit="return confirm('Usunac to zamowienie zetonow wraz z przyznanymi z niego uprawnieniami?');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="danger">Usun</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
