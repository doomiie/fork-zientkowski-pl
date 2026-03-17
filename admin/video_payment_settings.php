<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_login();
require_admin();

$error = '';
$ok = '';

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS video_payment_settings (
            id TINYINT UNSIGNED NOT NULL,
            provider VARCHAR(32) NOT NULL DEFAULT 'p24',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            sandbox_mode TINYINT(1) NOT NULL DEFAULT 1,
            sandbox_auto_capture TINYINT(1) NOT NULL DEFAULT 1,
            app_base_url VARCHAR(255) NULL,
            p24_merchant_id INT UNSIGNED NULL,
            p24_pos_id INT UNSIGNED NULL,
            p24_api_key VARCHAR(255) NULL,
            p24_crc VARCHAR(255) NULL,
            note VARCHAR(255) NULL,
            updated_by_user_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "INSERT INTO video_payment_settings
            (id, provider, enabled, sandbox_mode, sandbox_auto_capture, created_at, updated_at)
         SELECT 1, 'p24', 1, 1, 1, NOW(), NOW()
         WHERE NOT EXISTS (SELECT 1 FROM video_payment_settings WHERE id = 1)"
    );
} catch (Throwable $e) {
    $error = 'Nie udało się przygotować tabeli ustawień.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $error = 'Nieprawidłowy token bezpieczeństwa.';
    } else {
        $provider = trim((string)($_POST['provider'] ?? 'p24'));
        $enabled = ((int)($_POST['enabled'] ?? 0) === 1) ? 1 : 0;
        $sandboxMode = ((int)($_POST['sandbox_mode'] ?? 0) === 1) ? 1 : 0;
        $sandboxAuto = ((int)($_POST['sandbox_auto_capture'] ?? 0) === 1) ? 1 : 0;
        $appBaseUrl = trim((string)($_POST['app_base_url'] ?? ''));
        $merchantId = (int)($_POST['p24_merchant_id'] ?? 0);
        $posId = (int)($_POST['p24_pos_id'] ?? 0);
        $apiKey = trim((string)($_POST['p24_api_key'] ?? ''));
        $crc = trim((string)($_POST['p24_crc'] ?? ''));
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);

        if ($provider !== 'p24') {
            $error = 'Aktualnie obsługiwany provider to tylko p24.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    "UPDATE video_payment_settings
                     SET provider = ?, enabled = ?, sandbox_mode = ?, sandbox_auto_capture = ?, app_base_url = ?,
                         p24_merchant_id = ?, p24_pos_id = ?, p24_api_key = ?, p24_crc = ?, note = ?, updated_by_user_id = ?, updated_at = NOW()
                     WHERE id = 1"
                );
                $stmt->execute([
                    $provider,
                    $enabled,
                    $sandboxMode,
                    $sandboxAuto,
                    $appBaseUrl !== '' ? $appBaseUrl : null,
                    $merchantId > 0 ? $merchantId : null,
                    $posId > 0 ? $posId : null,
                    $apiKey !== '' ? $apiKey : null,
                    $crc !== '' ? $crc : null,
                    $note !== '' ? $note : null,
                    current_user_id() > 0 ? current_user_id() : null,
                ]);
                $ok = 'Ustawienia płatności zapisane.';
            } catch (Throwable $e) {
                $error = 'Nie udało się zapisać ustawień: ' . mb_substr($e->getMessage(), 0, 180);
            }
        }
    }
}

$row = [];
try {
    $stmt = $pdo->query('SELECT * FROM video_payment_settings WHERE id = 1 LIMIT 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    if ($error === '') $error = 'Nie udało się pobrać ustawień.';
}
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin - Płatności video app</title>
  <style>
    body{font-family:Segoe UI,Tahoma,sans-serif;background:#f3f5f9;margin:0;padding:20px}
    .wrap{max-width:980px;margin:0 auto}
    .card{background:#fff;border:1px solid #d9e1ec;border-radius:12px;padding:14px;margin-bottom:12px}
    .grid{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
    input,select,textarea{width:100%;padding:10px;border:1px solid #d9e1ec;border-radius:8px}
    button,a.btn{padding:9px 12px;border-radius:8px;border:1px solid #d9e1ec;background:#040327;color:#fff;text-decoration:none;cursor:pointer}
    a.btn{display:inline-block;background:#fff;color:#111}
    .ok{color:#065f46}.err{color:#991b1b}
    .hint{color:#475569;font-size:13px}
  </style>
</head>
<body>
<div class="wrap">
  <p><a class="btn" href="index.php">Powrót do admin</a></p>
  <div class="card">
    <h1 style="margin-top:0">Ustawienia płatności Video App</h1>
    <?php if ($error): ?><p class="err"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($ok): ?><p class="ok"><?php echo htmlspecialchars($ok, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p><?php endif; ?>
    <form method="post" action="video_payment_settings.php" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
      <div class="grid">
        <label>Provider
          <select name="provider">
            <option value="p24" <?php echo (($row['provider'] ?? 'p24') === 'p24') ? 'selected' : ''; ?>>Przelewy24</option>
          </select>
        </label>
        <label>Włączone
          <select name="enabled">
            <option value="1" <?php echo ((int)($row['enabled'] ?? 1) === 1) ? 'selected' : ''; ?>>Tak</option>
            <option value="0" <?php echo ((int)($row['enabled'] ?? 1) !== 1) ? 'selected' : ''; ?>>Nie</option>
          </select>
        </label>
        <label>Tryb SANDBOX
          <select name="sandbox_mode">
            <option value="1" <?php echo ((int)($row['sandbox_mode'] ?? 1) === 1) ? 'selected' : ''; ?>>Tak</option>
            <option value="0" <?php echo ((int)($row['sandbox_mode'] ?? 1) !== 1) ? 'selected' : ''; ?>>Nie (produkcja)</option>
          </select>
        </label>
        <label>SANDBOX auto-zaliczanie
          <select name="sandbox_auto_capture">
            <option value="1" <?php echo ((int)($row['sandbox_auto_capture'] ?? 1) === 1) ? 'selected' : ''; ?>>Tak</option>
            <option value="0" <?php echo ((int)($row['sandbox_auto_capture'] ?? 1) !== 1) ? 'selected' : ''; ?>>Nie</option>
          </select>
        </label>
        <label>APP base URL (opcjonalnie)
          <input name="app_base_url" value="<?php echo htmlspecialchars((string)($row['app_base_url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" placeholder="https://zientkowski.pl">
        </label>
        <label>P24 Merchant ID
          <input name="p24_merchant_id" type="number" min="0" value="<?php echo htmlspecialchars((string)($row['p24_merchant_id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </label>
        <label>P24 POS ID
          <input name="p24_pos_id" type="number" min="0" value="<?php echo htmlspecialchars((string)($row['p24_pos_id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </label>
        <label>P24 API KEY
          <input name="p24_api_key" value="<?php echo htmlspecialchars((string)($row['p24_api_key'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </label>
        <label>P24 CRC
          <input name="p24_crc" value="<?php echo htmlspecialchars((string)($row['p24_crc'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </label>
      </div>
      <label>Notatka
        <textarea name="note" rows="2"><?php echo htmlspecialchars((string)($row['note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
      </label>
      <p class="hint">Gdy SANDBOX auto-zaliczanie = Tak, checkout automatycznie oznaczy zamówienie jako opłacone i zaksięguje żetony (bez przejścia do bramki).</p>
      <p><button type="submit">Zapisz ustawienia</button></p>
    </form>
  </div>
</div>
</body>
</html>

