<?php
declare(strict_types=1);

// Pretty 404 page with DB logging

// Try to include DB (best-effort, once); if DB fails, still render 404
try {
    require_once __DIR__ . '/admin/db.php';
} catch (Throwable $e) {
    // ignore
}

// Set 404 status
http_response_code(404);

// Gather details
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

// Log to DB (if connection available)
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare('INSERT INTO http_404_logs (request_uri, referer, user_agent, ip) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            substr((string)$requestUri, 0, 1024),
            substr((string)$referer, 0, 1024),
            substr((string)$ua, 0, 512),
            substr((string)$ip, 0, 64),
        ]);
    } catch (Throwable $e) {
        // ignore logging failures
    }
}

// Basic HTML (no external assets to avoid cascading 404s)
?><!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>404 – Nie znaleziono strony</title>
  <style>
    :root { --accent:#040327; --bg:#f6f7fb; --text:#111827; --muted:#6b7280; }
    *{ box-sizing:border-box; }
    body{ margin:0; background:var(--bg); color:var(--text); font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
    .wrap{ min-height:100vh; display:grid; place-items:center; padding:24px; }
    .card{ background:#fff; width:100%; max-width:820px; border-radius:20px; padding:32px; box-shadow:0 25px 60px rgba(0,0,0,.08); position:relative; overflow:hidden; }
    .badge{ display:inline-block; padding:6px 10px; border-radius:999px; background:rgba(4,3,39,.08); color:var(--accent); font-weight:700; font-size:12px; letter-spacing:.06em; text-transform:uppercase; }
    h1{ margin:.4rem 0 0; font-size:42px; line-height:1.1; color:var(--accent); }
    p.lead{ color:var(--muted); font-size:16px; margin:.6rem 0 1.2rem; }
    code.path{ background:#f3f4f6; border:1px solid #e5e7eb; padding:4px 8px; border-radius:6px; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size:13px; }
    .actions{ display:flex; gap:12px; flex-wrap:wrap; margin-top:14px; }
    a.btn{ text-decoration:none; display:inline-flex; align-items:center; gap:8px; padding:12px 16px; border-radius:12px; border:1px solid var(--accent); color:var(--accent); background:#fff; font-weight:700; }
    a.btn.primary{ background:var(--accent); color:#fff; }
    ul.suggestions{ margin:18px 0 0 18px; color:#374151; }
    .grid{ display:grid; grid-template-columns:1fr; gap:18px; margin-top:20px; }
    @media (min-width:800px){ .grid{ grid-template-columns: 1.1fr .9fr; } }
    .panel{ background:#fafafa; border:1px solid #eee; border-radius:16px; padding:16px; }
    .muted{ color:#6b7280; font-size:12px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <span class="badge">Błąd 404</span>
      <h1>Nie znaleziono strony</h1>
      <p class="lead">Nie udało się odnaleźć adresu:
        <br><code class="path"><?php echo htmlspecialchars($requestUri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></code>
      </p>

      <div class="grid">
        <div>
          <p>Możesz:</p>
          <ul class="suggestions">
            <li>Wrócić na stronę główną</li>
            <li>Przejść do sekcji BIO lub Referencje</li>
            <li>Skontaktować się z nami</li>
          </ul>
          <div class="actions">
            <a class="btn primary" href="/">Strona główna</a>
            <a class="btn" href="/bio.html#bio">BIO</a>
            <a class="btn" href="/bio.html#referencje">Referencje</a>
            <a class="btn" href="/kontakt">Kontakt</a>
          </div>
        </div>
        <div class="panel">
          <strong>Szczegóły techniczne</strong>
          <div class="muted" style="margin-top:6px;">
            Referer: <?php echo htmlspecialchars($referer ?: '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?><br>
            User‑Agent: <?php echo htmlspecialchars($ua ?: '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?><br>
            IP: <?php echo htmlspecialchars($ip ?: '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
