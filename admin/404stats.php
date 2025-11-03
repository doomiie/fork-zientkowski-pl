<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_login();
require_admin();

$error = '';

// Date filters (GET): from=YYYY-MM-DD, to=YYYY-MM-DD
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));

function parse_date_or_null(string $s): ?string {
  if ($s === '') return null;
  try { $dt = new DateTime($s); return $dt->format('Y-m-d'); } catch (Throwable $e) { return null; }
}

$fromD = parse_date_or_null($from);
$toD = parse_date_or_null($to);

$where = [];
$params = [];
if ($fromD) { $where[] = 'created_at >= ?'; $params[] = $fromD . ' 00:00:00'; }
if ($toD) { $where[] = 'created_at <= ?'; $params[] = $toD . ' 23:59:59'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Summary
$total = 0; $uniquePages = 0; $firstSeen = ''; $lastSeen = '';
try {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM http_404_logs $whereSql");
  $stmt->execute($params);
  $total = (int)$stmt->fetchColumn();

  $stmt = $pdo->prepare("SELECT COUNT(DISTINCT request_uri) FROM http_404_logs $whereSql");
  $stmt->execute($params);
  $uniquePages = (int)$stmt->fetchColumn();

  $stmt = $pdo->prepare("SELECT MIN(created_at), MAX(created_at) FROM http_404_logs $whereSql");
  $stmt->execute($params);
  [$firstSeen, $lastSeen] = $stmt->fetch(PDO::FETCH_NUM) ?: ['', ''];
} catch (Throwable $e) { $error = 'Nie udało się pobrać podsumowania.'; }

// Per page (top 200)
$perPage = [];
try {
  $stmt = $pdo->prepare("SELECT request_uri, COUNT(*) hits, MIN(created_at) first_seen, MAX(created_at) last_seen
                         FROM http_404_logs $whereSql
                         GROUP BY request_uri
                         ORDER BY hits DESC
                         LIMIT 200");
  $stmt->execute($params);
  $perPage = $stmt->fetchAll();
} catch (Throwable $e) {}

// Per day (last 60 days by default)
$perDay = [];
try {
  $stmt = $pdo->prepare("SELECT DATE(created_at) d, COUNT(*) hits
                         FROM http_404_logs $whereSql
                         GROUP BY DATE(created_at)
                         ORDER BY d DESC
                         LIMIT 120");
  $stmt->execute($params);
  $perDay = $stmt->fetchAll();
} catch (Throwable $e) {}

// Popular referers (top 50)
$topRef = [];
try {
  $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(referer,''),'(brak)') referer, COUNT(*) hits
                         FROM http_404_logs $whereSql
                         GROUP BY referer
                         ORDER BY hits DESC
                         LIMIT 50");
  $stmt->execute($params);
  $topRef = $stmt->fetchAll();
} catch (Throwable $e) {}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Statystyki 404</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; }
    header { background:#040327; color:#fff; padding:16px 24px; display:flex; justify-content:space-between; align-items:center; }
    main { max-width:1200px; margin:24px auto; padding:0 24px; }
    .card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 12px 30px rgba(0,0,0,.06); margin-bottom:20px; }
    .row { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
    label { display:block; font-weight:600; margin:6px 0; }
    input[type=date] { padding:10px 12px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; }
    button, a.btn { background:#040327; color:#fff; border:0; border-radius:10px; padding:10px 14px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
    a.btn.secondary { background:#fff; color:#040327; border:1px solid #9ca3af; }
    table { width:100%; border-collapse:collapse; }
    th, td { text-align:left; border-bottom:1px solid #e5e7eb; padding:8px 6px; font-size:14px; }
    th { background:#f3f4f6; }
    .muted { color:#6b7280; font-size:13px; }
    .grid { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
    @media (max-width:900px){ .grid, .row { grid-template-columns:1fr; } }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
  <meta name="robots" content="noindex,nofollow">
</head>
<body>
  <header>
    <div><strong>Panel administracyjny</strong></div>
    <div>
      Zalogowano jako: <?= h(current_user_email()); ?>
      &nbsp;|&nbsp; <a class="btn secondary" href="index.php">Panel</a>
      &nbsp;|&nbsp; <a class="btn secondary" href="redirects.php">Przekierowania</a>
      &nbsp;|&nbsp; <a class="btn secondary" href="logout.php">Wyloguj</a>
    </div>
  </header>
  <main>
    <div class="card">
      <h1 style="margin:0 0 8px 0;">Statystyki 404</h1>
      <div class="muted">Raporty: per strona • per zakres dat • najpopularniejsze</div>
      <?php if ($error): ?><div style="margin-top:10px; background:#fee2e2; color:#7f1d1d; padding:10px 12px; border-radius:8px;"><?= h($error); ?></div><?php endif; ?>
      <form method="get" action="404stats.php" style="margin-top:12px; display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
        <div>
          <label for="from">Od (YYYY-MM-DD)</label>
          <input type="date" id="from" name="from" value="<?= h($fromD ?? ''); ?>">
        </div>
        <div>
          <label for="to">Do (YYYY-MM-DD)</label>
          <input type="date" id="to" name="to" value="<?= h($toD ?? ''); ?>">
        </div>
        <div>
          <button type="submit">Filtruj</button>
        </div>
        <div style="margin-left:auto; display:flex; gap:8px;">
          <a class="btn secondary" href="404stats.php?from=<?= h((new DateTime('-6 days'))->format('Y-m-d')); ?>&to=<?= h((new DateTime('today'))->format('Y-m-d')); ?>">Ostatnie 7 dni</a>
          <a class="btn secondary" href="404stats.php?from=<?= h((new DateTime('-29 days'))->format('Y-m-d')); ?>&to=<?= h((new DateTime('today'))->format('Y-m-d')); ?>">Ostatnie 30 dni</a>
          <a class="btn secondary" href="404stats.php">Wyczyść</a>
        </div>
      </form>
    </div>

    <div class="grid">
      <div class="card">
        <h2 style="margin:0 0 10px 0;">Podsumowanie</h2>
        <table>
          <tbody>
            <tr><th>Łącznie zdarzeń</th><td><?= (int)$total; ?></td></tr>
            <tr><th>Unikalnych adresów</th><td><?= (int)$uniquePages; ?></td></tr>
            <tr><th>Pierwsze wystąpienie</th><td><?= h((string)$firstSeen); ?></td></tr>
            <tr><th>Ostatnie wystąpienie</th><td><?= h((string)$lastSeen); ?></td></tr>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h2 style="margin:0 0 10px 0;">Per dzień <?= $fromD || $toD ? '(wg filtra)' : '(ostatnie)' ?></h2>
        <table>
          <thead><tr><th>Data</th><th>Hits</th></tr></thead>
          <tbody>
            <?php foreach ($perDay as $d): ?>
              <tr><td><?= h($d['d']); ?></td><td><?= (int)$d['hits']; ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$perDay): ?><tr><td colspan="2" class="muted">Brak danych.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <h2 style="margin:0 0 10px 0;">Per strona (top 200)</h2>
      <div style="overflow:auto;">
        <table>
          <thead>
            <tr>
              <th>Adres</th>
              <th>Hits</th>
              <th>Pierwsze</th>
              <th>Ostatnie</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($perPage as $r): ?>
              <tr>
                <td><code><?= h($r['request_uri']); ?></code></td>
                <td><?= (int)$r['hits']; ?></td>
                <td class="muted"><?= h($r['first_seen']); ?></td>
                <td class="muted"><?= h($r['last_seen']); ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$perPage): ?><tr><td colspan="4" class="muted">Brak danych.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <h2 style="margin:0 0 10px 0;">Najpopularniejsze źródła (referer) — top 50</h2>
      <table>
        <thead><tr><th>Referer</th><th>Hits</th></tr></thead>
        <tbody>
          <?php foreach ($topRef as $r): ?>
            <tr>
              <td style="max-width:760px; overflow:auto;"><code><?= h($r['referer']); ?></code></td>
              <td><?= (int)$r['hits']; ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$topRef): ?><tr><td colspan="2" class="muted">Brak danych.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

  </main>
</body>
</html>

