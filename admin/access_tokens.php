<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require_login();
require_admin();

$error = '';
$success = '';
$generatedToken = '';
$generatedUrl = '';

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function get_site_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }
    return $scheme . '://' . $host;
}

$defaults = [
    'target_key' => 'video',
    'scope' => 'view',
    'resource_type' => 'video',
    'resource_id' => '',
    'token_ttl_minutes' => '10',
    'session_ttl_minutes' => '30',
    'max_uses' => '0',
    'note' => '',
];
$form = $defaults;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $error = 'Nieprawidlowy token bezpieczenstwa.';
    } else {
        $action = trim((string)($_POST['action'] ?? 'create'));

        if ($action === 'delete_token') {
            $tokenId = (int)($_POST['token_id'] ?? 0);
            if ($tokenId <= 0) {
                $error = 'Niepoprawny identyfikator tokenu.';
            } else {
                try {
                    $stmt = $pdo->prepare('DELETE FROM access_tokens WHERE id = ?');
                    $stmt->execute([$tokenId]);
                    $success = $stmt->rowCount() > 0 ? 'Token zostal usuniety.' : 'Token nie istnieje.';
                } catch (Throwable $e) {
                    $error = 'Nie udalo sie usunac tokenu.';
                }
            }
        } else {
            $form['target_key'] = trim((string)($_POST['target_key'] ?? ''));
            $form['scope'] = trim((string)($_POST['scope'] ?? 'view'));
            $form['resource_type'] = trim((string)($_POST['resource_type'] ?? ''));
            $form['resource_id'] = trim((string)($_POST['resource_id'] ?? ''));
            $form['token_ttl_minutes'] = trim((string)($_POST['token_ttl_minutes'] ?? '10'));
            $form['session_ttl_minutes'] = trim((string)($_POST['session_ttl_minutes'] ?? '30'));
            $form['max_uses'] = trim((string)($_POST['max_uses'] ?? '0'));
            $form['note'] = trim((string)($_POST['note'] ?? ''));

            $scope = in_array($form['scope'], ['view', 'edit'], true) ? $form['scope'] : '';
            $targetKey = mb_substr($form['target_key'], 0, 64);
            $resourceType = $form['resource_type'] === '' ? null : mb_substr($form['resource_type'], 0, 64);
            $resourceId = $form['resource_id'] === '' ? null : mb_substr($form['resource_id'], 0, 191);
            $tokenTtl = (int)$form['token_ttl_minutes'];
            $sessionTtl = (int)$form['session_ttl_minutes'];
            $maxUses = (int)$form['max_uses']; // 0 = bez limitu
            $note = $form['note'] === '' ? null : mb_substr($form['note'], 0, 255);

            if ($targetKey === '' || !preg_match('/^[a-z0-9_-]{2,64}$/i', $targetKey)) {
                $error = 'Pole target_key jest wymagane (2-64 znaki: litery/cyfry/_/-).';
            } elseif ($scope === '') {
                $error = 'Niepoprawny scope.';
            } elseif ($tokenTtl < 1 || $tokenTtl > 1440) {
                $error = 'TTL tokenu musi byc w zakresie 1-1440 minut.';
            } elseif ($sessionTtl < 1 || $sessionTtl > 720) {
                $error = 'TTL sesji musi byc w zakresie 1-720 minut.';
            } elseif ($maxUses < 0 || $maxUses > 10000) {
                $error = 'Maksymalna liczba uzyc musi byc w zakresie 0-10000 (0 = bez limitu).';
            } else {
                try {
                    $rawToken = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $rawToken);
                    $expiresAt = (new DateTimeImmutable('now'))
                        ->modify('+' . $tokenTtl . ' minutes')
                        ->format('Y-m-d H:i:s');

                    $stmt = $pdo->prepare(
                        'INSERT INTO access_tokens
                            (token_hash, target_key, scope, resource_type, resource_id, max_uses, used_count, session_ttl_minutes, expires_at, note, created_by_user_id, created_at, updated_at)
                         VALUES
                            (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, NOW(), NOW())'
                    );
                    $stmt->execute([
                        $tokenHash,
                        $targetKey,
                        $scope,
                        $resourceType,
                        $resourceId,
                        $maxUses,
                        $sessionTtl,
                        $expiresAt,
                        $note,
                        current_user_id(),
                    ]);

                    $generatedToken = $rawToken;
                    $success = 'Token zostal utworzony. Skopiuj go teraz - pozniej nie bedzie widoczny.';

                    if ($targetKey === 'video') {
                        $base = get_site_base_url();
                        if ($base !== '') {
                            $params = ['vt' => $generatedToken];
                            if ($resourceId !== null && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $resourceId)) {
                                $params['source'] = $resourceId;
                            }
                            if ($scope === 'edit') {
                                $params['edit'] = '1';
                            }
                            $generatedUrl = $base . '/video.html?' . http_build_query($params);
                        }
                    }

                    $form = $defaults;
                } catch (Throwable $e) {
                    $generatedToken = '';
                    $generatedUrl = '';
                    $error = 'Nie udalo sie utworzyc tokenu: ' . mb_substr($e->getMessage(), 0, 220);
                }
            }
        }
    }
}

$recent = [];
try {
    $recentStmt = $pdo->query(
        'SELECT at.id, at.target_key, at.scope, at.resource_type, at.resource_id, at.max_uses, at.used_count, at.expires_at, at.revoked_at, at.note, at.created_at, u.email AS created_by_email
         FROM access_tokens at
         LEFT JOIN users u ON u.id = at.created_by_user_id
         ORDER BY at.id DESC
         LIMIT 30'
    );
    $recent = $recentStmt ? ($recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    // no-op
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tokeny dostepu</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; color:#111827; }
    header { background:#040327; color:#fff; padding:16px 24px; display:flex; justify-content:space-between; align-items:center; gap:12px; }
    main { max-width:1100px; margin:24px auto; padding:0 24px; display:grid; gap:16px; }
    .card { background:#fff; border-radius:14px; padding:16px; box-shadow:0 10px 28px rgba(0,0,0,.06); }
    .row { display:grid; gap:6px; margin-bottom:12px; }
    .grid { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); }
    .actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    label { font-size:14px; font-weight:600; }
    input, select, textarea { width:100%; border:1px solid #d1d5db; border-radius:10px; padding:10px 12px; font:inherit; }
    button, a.btn { display:inline-block; background:#040327; color:#fff; border:0; border-radius:10px; padding:10px 14px; text-decoration:none; font-weight:600; cursor:pointer; }
    a.btn.secondary, button.secondary { background:#fff; color:#111827; border:1px solid #d1d5db; }
    button.danger { background:#9f1239; }
    .ok { color:#166534; font-weight:600; }
    .err { color:#991b1b; font-weight:600; }
    code { background:#f3f4f6; padding:2px 6px; border-radius:6px; }
    table { width:100%; border-collapse:collapse; font-size:14px; }
    th, td { text-align:left; border-bottom:1px solid #e5e7eb; padding:8px 6px; vertical-align:top; }
    form.inline { margin:0; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
</head>
<body>
  <header>
    <div><strong>Generator tokenow dostepu</strong></div>
    <div>
      <a class="btn secondary" href="index.php">Powrot do panelu</a>
    </div>
  </header>
  <main>
    <section class="card">
      <h1 style="margin-top:0;">Nowy token</h1>
      <p>Token dziala do czasu wygasniecia TTL i moze byc uzyty wiele razy (wg max_uses).</p>
      <?php if ($error !== ''): ?><p class="err"><?php echo h($error); ?></p><?php endif; ?>
      <?php if ($success !== ''): ?><p class="ok"><?php echo h($success); ?></p><?php endif; ?>

      <?php if ($generatedToken !== ''): ?>
        <div class="row">
          <label>Wygenerowany token (pokazywany tylko raz)</label>
          <input type="text" readonly value="<?php echo h($generatedToken); ?>">
        </div>
        <?php if ($generatedUrl !== ''): ?>
          <div class="row">
            <label>Gotowy link</label>
            <input type="text" readonly value="<?php echo h($generatedUrl); ?>">
            <div class="actions">
              <a class="btn" href="<?php echo h($generatedUrl); ?>" target="_blank" rel="noopener">Otworz link</a>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <form method="post" action="access_tokens.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="create">
        <div class="grid">
          <div class="row">
            <label for="target_key">target_key</label>
            <input id="target_key" name="target_key" type="text" required value="<?php echo h($form['target_key']); ?>" placeholder="np. video">
          </div>
          <div class="row">
            <label for="scope">scope</label>
            <select id="scope" name="scope">
              <option value="view" <?php echo $form['scope'] === 'view' ? 'selected' : ''; ?>>view</option>
              <option value="edit" <?php echo $form['scope'] === 'edit' ? 'selected' : ''; ?>>edit</option>
            </select>
          </div>
          <div class="row">
            <label for="resource_type">resource_type (opcjonalnie)</label>
            <input id="resource_type" name="resource_type" type="text" value="<?php echo h($form['resource_type']); ?>" placeholder="np. video">
          </div>
          <div class="row">
            <label for="resource_id">resource_id (opcjonalnie)</label>
            <input id="resource_id" name="resource_id" type="text" value="<?php echo h($form['resource_id']); ?>" placeholder="np. YouTube ID">
          </div>
          <div class="row">
            <label for="token_ttl_minutes">TTL tokenu (min)</label>
            <input id="token_ttl_minutes" name="token_ttl_minutes" type="number" min="1" max="1440" required value="<?php echo h($form['token_ttl_minutes']); ?>">
          </div>
          <div class="row">
            <label for="session_ttl_minutes">TTL sesji po wymianie (min)</label>
            <input id="session_ttl_minutes" name="session_ttl_minutes" type="number" min="1" max="720" required value="<?php echo h($form['session_ttl_minutes']); ?>">
          </div>
          <div class="row">
            <label for="max_uses">Maksymalna liczba uzyc (0 = bez limitu)</label>
            <input id="max_uses" name="max_uses" type="number" min="0" max="10000" required value="<?php echo h($form['max_uses']); ?>">
          </div>
        </div>
        <div class="row">
          <label for="note">Notatka (opcjonalnie)</label>
          <textarea id="note" name="note" rows="2" placeholder="opis celu tokenu"><?php echo h($form['note']); ?></textarea>
        </div>
        <button type="submit">Wygeneruj token</button>
      </form>
    </section>

    <section class="card">
      <h2 style="margin-top:0;">Ostatnie tokeny</h2>
      <?php if (!$recent): ?>
        <p>Brak danych lub brak tabeli.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Target</th>
              <th>Scope</th>
              <th>Zasob</th>
              <th>Uzycia</th>
              <th>Wygasa</th>
              <th>Autor</th>
              <th>Notatka</th>
              <th>Akcja</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $row): ?>
              <?php
                $rt = trim((string)($row['resource_type'] ?? ''));
                $ri = trim((string)($row['resource_id'] ?? ''));
                $usesLabel = ((int)$row['max_uses'] === 0)
                  ? ((string)$row['used_count'] . '/bez limitu')
                  : ((string)$row['used_count'] . '/' . (string)$row['max_uses']);
              ?>
              <tr>
                <td><?php echo h((string)$row['id']); ?></td>
                <td><code><?php echo h((string)$row['target_key']); ?></code></td>
                <td><code><?php echo h((string)$row['scope']); ?></code></td>
                <td><?php echo h($rt === '' && $ri === '' ? '*' : ($rt . ':' . $ri)); ?></td>
                <td><?php echo h($usesLabel); ?></td>
                <td><?php echo h((string)$row['expires_at']); ?></td>
                <td><?php echo h((string)($row['created_by_email'] ?? '')); ?></td>
                <td><?php echo h((string)($row['note'] ?? '')); ?></td>
                <td>
                  <form class="inline" method="post" action="access_tokens.php" onsubmit="return confirm('Usunac token?');">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="delete_token">
                    <input type="hidden" name="token_id" value="<?php echo h((string)$row['id']); ?>">
                    <button class="danger" type="submit">Usun</button>
                  </form>
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
