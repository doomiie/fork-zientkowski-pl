<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_login();
require_admin();

$error = '';
$ok = '';
$editDoc = null;
$requestedEditId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

$targetDir = dirname(__DIR__) . '/docs/files';
if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0775, true);
}
$sharePrefix = '/docs/download/';

$normalizeDate = static function (?string $value, string $defaultTime): ?string {
    $trimmed = trim((string)$value);
    if ($trimmed === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
        return $trimmed . $defaultTime;
    }
    return $trimmed;
};

$normalizeExpires = static function (?string $value) use ($normalizeDate): ?string {
    return $normalizeDate($value, ' 23:59:59');
};

$normalizeAvailableFrom = static function (?string $value) use ($normalizeDate): ?string {
    return $normalizeDate($value, ' 00:00:00');
};

$normalizeFallback = static function (?string $value): array {
    $url = trim((string)$value);
    if ($url === '') {
        return ['', null];
    }
    if (strncmp($url, '/', 1) === 0 || filter_var($url, FILTER_VALIDATE_URL)) {
        return [$url, null];
    }
    return ['', 'Podaj prawidłowy adres fallback (http/https lub ścieżka zaczynająca się od /).'];
};

$processUpload = static function (array $file, string $targetDir): array {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Wybierz plik do wysłania.');
    }
    $orig = (string)($file['name'] ?? 'file');
    $size = (int)($file['size'] ?? 0);
    $mime = (string)($file['type'] ?? 'application/octet-stream');
    $ext = pathinfo($orig, PATHINFO_EXTENSION);
    $safeExt = $ext ? ('.' . preg_replace('/[^a-zA-Z0-9]+/', '', $ext)) : '';
    $basename = bin2hex(random_bytes(8)) . $safeExt;
    $destPath = $targetDir . '/' . $basename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new RuntimeException('Przeniesienie pliku nie powiodło się.');
    }
    return [
        'file_path' => '/docs/files/' . $basename,
        'original_name' => $orig,
        'mime_type' => $mime,
        'file_size' => $size,
    ];
};

$generateShareHash = static function (PDO $pdo): string {
    while (true) {
        $candidate = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM doc_files WHERE share_hash = ?');
        $stmt->execute([$candidate]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $candidate;
        }
    }
};

$ensureShareHash = static function (PDO $pdo, array &$doc) use ($generateShareHash): void {
    if (!empty($doc['share_hash'])) {
        return;
    }
    $hash = $generateShareHash($pdo);
    $stmt = $pdo->prepare('UPDATE doc_files SET share_hash = ? WHERE id = ?');
    $stmt->execute([$hash, (int)$doc['id']]);
    $doc['share_hash'] = $hash;
};

$shareUrlFromHash = static function (?string $hash) use ($sharePrefix): string {
    $hash = trim((string)$hash);
    if ($hash === '') {
        return '';
    }
    return rtrim($sharePrefix, '/') . '/' . rawurlencode($hash);
};

$resolveDownloadName = static function (array $doc): string {
    $name = trim((string)($doc['original_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $fallback = basename((string)($doc['file_path'] ?? ''));
    if ($fallback !== '' && $fallback !== '.' && $fallback !== '..') {
        return $fallback;
    }
    return 'dokument-' . (int)($doc['id'] ?? 0);
};

$buildDownloadUrl = static function (array $doc) use ($resolveDownloadName, $shareUrlFromHash): string {
    $base = $shareUrlFromHash($doc['share_hash'] ?? '');
    if ($base === '') {
        return '';
    }
    $filePart = rawurlencode($resolveDownloadName($doc));
    return rtrim($base, '/') . '/' . $filePart;
};

$fetchDoc = static function (PDO $pdo, int $id) use ($ensureShareHash): ?array {
    $stmt = $pdo->prepare('SELECT id, display_name, file_path, original_name, mime_type, file_size, expires_at, available_from, created_at, is_enabled, fallback_url, share_hash, download_count FROM doc_files WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $ensureShareHash($pdo, $row);
    }
    return $row ?: null;
};

$sendAjaxError = static function (string $message): void {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
};

if ($requestedEditId > 0) {
    $editDoc = $fetchDoc($pdo, $requestedEditId);
    if (!$editDoc) {
        $error = 'Nie znaleziono pliku do edycji.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $error = 'Nieprawidłowy token bezpieczeństwa.';
        if (isset($_POST['ajax'])) {
            $sendAjaxError($error);
        }
    } else {
        $action = (string)($_POST['action'] ?? 'create');
        if ($action === 'update') {
            $docId = (int)($_POST['doc_id'] ?? 0);
            if ($docId <= 0) {
                $error = 'Nie można zaktualizować nieznanego pliku.';
            } else {
                $current = $fetchDoc($pdo, $docId);
                if (!$current) {
                    $error = 'Plik nie istnieje.';
                } else {
                    $requestedEditId = $docId;
                    $display = trim((string)($_POST['display_name'] ?? ''));
                    $expiresAt = $normalizeExpires($_POST['expires_at'] ?? '');
                    $availableFrom = $normalizeAvailableFrom($_POST['available_from'] ?? '');
                    [$fallbackUrl, $fallbackError] = $normalizeFallback($_POST['fallback_url'] ?? '');
                    $isEnabled = (int)($_POST['is_enabled'] ?? 1) === 1 ? 1 : 0;
                    $editDoc = $current;
                    $editDoc['display_name'] = $display;
                    $editDoc['expires_at'] = $expiresAt;
                    $editDoc['available_from'] = $availableFrom;
                    $editDoc['is_enabled'] = $isEnabled;
                    $editDoc['fallback_url'] = $fallbackUrl !== '' ? $fallbackUrl : null;
                    if ($display === '') {
                        $error = 'Podaj nazwe wyswietlana.';
                    } elseif ($fallbackError) {
                        $error = $fallbackError;
                    } else {
                        $meta = [
                            'file_path' => (string)$current['file_path'],
                            'original_name' => (string)$current['original_name'],
                            'mime_type' => (string)$current['mime_type'],
                            'file_size' => (int)$current['file_size'],
                        ];
                        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                            try {
                                $meta = $processUpload($_FILES['file'], $targetDir);
                            } catch (Throwable $e) {
                                $error = 'Blad podczas zapisu pliku: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                            }
                        }
                        if (!$error) {
                            $stmt = $pdo->prepare('UPDATE doc_files SET display_name = ?, file_path = ?, original_name = ?, mime_type = ?, file_size = ?, expires_at = ?, available_from = ?, is_enabled = ?, fallback_url = ? WHERE id = ?');
                            $stmt->execute([
                                $display,
                                $meta['file_path'],
                                $meta['original_name'],
                                $meta['mime_type'],
                                $meta['file_size'],
                                $expiresAt,
                                $availableFrom,
                                $isEnabled,
                                $fallbackUrl !== '' ? $fallbackUrl : null,
                                $docId,
                            ]);
                            $ok = 'Zapisano zmiany.';
                            $editDoc = array_merge($current, [
                                'display_name' => $display,
                                'file_path' => $meta['file_path'],
                                'original_name' => $meta['original_name'],
                                'mime_type' => $meta['mime_type'],
                                'file_size' => $meta['file_size'],
                                'expires_at' => $expiresAt,
                                'available_from' => $availableFrom,
                                'is_enabled' => $isEnabled,
                                'fallback_url' => $fallbackUrl !== '' ? $fallbackUrl : null,
                                'download_count' => (int)($current['download_count'] ?? 0),
                            ]);
                        }
                    }
                }
            }
        } else {
            $display = trim((string)($_POST['display_name'] ?? ''));
            $expiresAt = $normalizeExpires($_POST['expires_at'] ?? '');
            $availableFrom = $normalizeAvailableFrom($_POST['available_from'] ?? '');
            [$fallbackUrl, $fallbackError] = $normalizeFallback($_POST['fallback_url'] ?? '');
            $isEnabled = (int)($_POST['is_enabled'] ?? 1) === 1 ? 1 : 0;
            if ($display === '') {
                $error = 'Podaj nazwę wyświetlaną.';
                if (isset($_POST['ajax'])) {
                    $sendAjaxError($error);
                }
            } elseif ($fallbackError) {
                $error = $fallbackError;
                if (isset($_POST['ajax'])) {
                    $sendAjaxError($error);
                }
            } elseif (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                $error = 'Wybierz plik do wysłania.';
                if (isset($_POST['ajax'])) {
                    $sendAjaxError($error);
                }
            } else {
                try {
                    $meta = $processUpload($_FILES['file'], $targetDir);
                    $shareHash = $generateShareHash($pdo);
                    $stmt = $pdo->prepare('INSERT INTO doc_files (display_name, file_path, original_name, mime_type, file_size, expires_at, available_from, is_enabled, fallback_url, share_hash, download_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([
                        $display,
                        $meta['file_path'],
                        $meta['original_name'],
                        $meta['mime_type'],
                        $meta['file_size'],
                        $expiresAt,
                        $availableFrom,
                        $isEnabled,
                        $fallbackUrl !== '' ? $fallbackUrl : null,
                        $shareHash,
                        0,
                    ]);
                    $newId = (int)$pdo->lastInsertId();
                    $ok = 'Dodano plik.';
                    if (isset($_POST['ajax'])) {
                        $shareUrl = $shareUrlFromHash($shareHash);
                        $downloadName = $resolveDownloadName([
                            'original_name' => $meta['original_name'],
                            'file_path' => $meta['file_path'],
                            'id' => $newId,
                            'share_hash' => $shareHash,
                        ]);
                        $downloadUrl = $shareUrl ? $shareUrl . '/' . rawurlencode($downloadName) : '';
                        header('Content-Type: application/json; charset=UTF-8');
                        echo json_encode([
                            'ok' => true,
                            'id' => $newId,
                            'display_name' => $display,
                            'file_path' => $meta['file_path'],
                            'share_url' => $shareUrl,
                            'download_url' => $downloadUrl,
                            'available_from' => $availableFrom,
                            'download_count' => 0,
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
                    $error = 'Blad wysylania: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
            }
        }
    }
}

try {
    $rows = $pdo->query('SELECT id, display_name, file_path, original_name, file_size, expires_at, available_from, created_at, is_enabled, fallback_url, share_hash, download_count FROM doc_files ORDER BY created_at DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $rows = [];
}
foreach ($rows as &$row) {
    $ensureShareHash($pdo, $row);
    $row['share_url'] = $shareUrlFromHash($row['share_hash'] ?? '');
    $row['download_url'] = $buildDownloadUrl($row);
    $row['available_from'] = $row['available_from'] ?? null;
    $row['download_count'] = (int)($row['download_count'] ?? 0);
}
unset($row);
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
    .card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 12px 30px rgba(0,0,0,.06); margin-bottom:20px; }
    label { display:block; font-weight:600; margin:12px 0 6px; }
    input[type=text], input[type=date], input[type=datetime-local], input[type=file], select { width:100%; padding:12px 14px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; }
    table { width:100%; border-collapse:collapse; margin-top:16px; }
    th, td { text-align:left; padding:8px 10px; border-bottom:1px solid #e5e7eb; font-size:14px; vertical-align:top; }
    .error { background:#fee2e2; color:#7f1d1d; padding:10px 12px; border-radius:8px; margin:12px 0; font-size:14px; }
    .ok { background:#ecfdf5; color:#065f46; padding:10px 12px; border-radius:8px; margin:12px 0; font-size:14px; }
    a.btn { display:inline-block; background:#fff; color:#040327; border:1px solid #9ca3af; padding:8px 12px; border-radius:8px; text-decoration:none; font-weight:600; }
    button { background:#040327; color:#fff; border:0; border-radius:10px; padding:12px 16px; font-weight:600; cursor:pointer; }
    .muted { color:#6b7280; font-size:13px; }
    .status-chip { display:inline-flex; align-items:center; gap:6px; padding:4px 8px; border-radius:999px; font-size:12px; font-weight:600; background:#e5e7eb; color:#111827; }
    .status-chip--off { background:#fee2e2; color:#7f1d1d; }
    .status-chip--expired { background:#fef3c7; color:#92400e; }
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
      <a class="btn" href="index.php">Powrot</a>
      &nbsp;|&nbsp;
      <a class="btn" href="logout.php">Wyloguj</a>
    </div>
  </header>
  <main>
    <div class="card" style="max-width: 1440px;">
      <h1>Dokumenty do pobrania</h1>
      <?php if ($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="ok"><?php echo $ok; ?></div><?php endif; ?>

      <form id="uploadForm" method="post" action="docs.php" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="create">
        <label for="display_name">Nazwa wyswietlana</label>
        <input type="text" id="display_name" name="display_name" placeholder="np. Regulamin 2025.pdf">
        <label for="file">Plik</label>
        <input type="file" id="file" name="file" required>
        <label for="expires_at">Wygasa (opcjonalnie)</label>
        <input type="date" id="expires_at" name="expires_at" placeholder="YYYY-MM-DD">
        <label for="available_from">Dostępny od (opcjonalnie)</label>
        <input type="date" id="available_from" name="available_from" placeholder="YYYY-MM-DD">
        <div class="muted" style="margin-top:4px;">Jeśli ustawisz przyszłą datę, dokument będzie dostępny dopiero od tego dnia.</div>
        <label for="is_enabled">Status linku</label>
        <select id="is_enabled" name="is_enabled">
          <option value="1" selected>Wlaczony</option>
          <option value="0">Wylaczony</option>
        </select>
        <label for="fallback_url">Fallback URL (opcjonalnie)</label>
        <input type="text" id="fallback_url" name="fallback_url" placeholder="https://twoja-strona.pl/fallback lub /fallback">
        <div class="muted" style="margin-top:4px;">Ten adres zostanie pokazany, gdy plik wygaśnie lub zostanie wyłączony.</div>
        <div style="margin-top:12px"><button type="submit">Dodaj plik</button></div>
        <div id="progressWrap" style="margin-top:12px;" class="hidden">
          <div style="height:10px; background:#e5e7eb; border-radius:8px; overflow:hidden;">
            <div id="progressBar" style="height:10px; width:0; background:#040327;"></div>
          </div>
          <div id="progressText" class="muted" style="margin-top:6px;">0%</div>
          <pre id="progressLog" class="muted" style="margin-top:6px; max-height:120px; overflow:auto; background:#f8fafc; padding:8px; border-radius:8px;"></pre>
        </div>
        <div class="muted" style="margin-top:8px;">Po dacie wygasniecia plik nie bedzie widoczny na stronie /docs.</div>
      </form>
    </div>

    <?php if ($editDoc): ?>
      <?php
        $editExpiresValue = '';
        if (!empty($editDoc['expires_at'])) {
            $editExpiresValue = substr((string)$editDoc['expires_at'], 0, 10);
        }
        $editAvailableValue = '';
        if (!empty($editDoc['available_from'])) {
            $editAvailableValue = substr((string)$editDoc['available_from'], 0, 10);
        }
        $editEnabled = (int)($editDoc['is_enabled'] ?? 0) === 1;
        $editShareUrl = $shareUrlFromHash($editDoc['share_hash'] ?? '');
        $editDownloadUrl = $buildDownloadUrl($editDoc);
      ?>
      <div class="card">
        <h2>Edytuj dokument: <?php echo htmlspecialchars((string)$editDoc['display_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h2>
        <form id="editForm" method="post" action="docs.php?edit=<?php echo (int)$editDoc['id']; ?>" enctype="multipart/form-data" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="doc_id" value="<?php echo (int)$editDoc['id']; ?>">
          <label for="edit_display_name">Nazwa wyswietlana</label>
          <input type="text" id="edit_display_name" name="display_name" value="<?php echo htmlspecialchars((string)$editDoc['display_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          <label for="edit_file">Nowy plik (opcjonalnie)</label>
          <input type="file" id="edit_file" name="file">
          <div class="muted" style="margin-top:4px;">Link udostępniania: <a href="<?php echo htmlspecialchars($editShareUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($editShareUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></a></div>
          <?php if ($editDownloadUrl): ?>
          <div class="muted" style="margin-top:4px;">Link bezpośredni: <a href="<?php echo htmlspecialchars($editDownloadUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($editDownloadUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></a></div>
          <?php endif; ?>
          <div class="muted" style="margin-top:4px;">Łącznie pobrań: <?php echo (int)($editDoc['download_count'] ?? 0); ?></div>
          <label for="edit_expires_at">Wygasa</label>
          <input type="date" id="edit_expires_at" name="expires_at" value="<?php echo htmlspecialchars($editExpiresValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          <label for="edit_available_from">Dostępny od</label>
          <input type="date" id="edit_available_from" name="available_from" value="<?php echo htmlspecialchars($editAvailableValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          <label for="edit_is_enabled">Status linku</label>
          <select id="edit_is_enabled" name="is_enabled">
            <option value="1" <?php echo $editEnabled ? 'selected' : ''; ?>>Wlaczony</option>
            <option value="0" <?php echo !$editEnabled ? 'selected' : ''; ?>>Wylaczony</option>
          </select>
          <label for="edit_fallback_url">Fallback URL</label>
          <input type="text" id="edit_fallback_url" name="fallback_url" value="<?php echo htmlspecialchars((string)($editDoc['fallback_url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" placeholder="https://twoja-strona.pl/fallback lub /fallback">
          <div style="margin-top:12px; display:flex; gap:12px; flex-wrap:wrap;">
            <button type="submit">Zapisz zmiany</button>
            <a class="btn" href="docs.php">Anuluj</a>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2>Ostatnio dodane</h2>
      <table>
        <thead>
          <tr>
            <th>Nazwa</th>
            <th>Plik</th>
            <th>Status</th>
            <th>Fallback</th>
            <th>Dostępny od</th>
            <th>Wygasa</th>
            <th>Pobrań</th>
            <th>Rozmiar</th>
            <th>Dodano</th>
            <th>Akcje</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $downloadUrl = (string)($r['download_url'] ?? ($r['share_url'] ?? ('/backend/doc_redirect.php?id=' . (int)$r['id'])));
              $isEnabledRow = (int)($r['is_enabled'] ?? 0) === 1;
              $isExpiredRow = false;
              if (!empty($r['expires_at'])) {
                  $expiresTs = strtotime((string)$r['expires_at']);
                  $isExpiredRow = $expiresTs !== false && $expiresTs <= time();
              }
              $isUpcomingRow = false;
              if (!empty($r['available_from'])) {
                  $availableTs = strtotime((string)$r['available_from']);
                  $isUpcomingRow = $availableTs !== false && $availableTs > time();
              }
            ?>
            <tr>
              <td><?php echo htmlspecialchars((string)$r['display_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
              <td>
                <a href="<?php echo htmlspecialchars($downloadUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" target="_blank" rel="noopener">pobierz</a>
                <?php if (!empty($r['share_url'])): ?>
                  <div class="muted" style="margin-top:4px;"><?php echo htmlspecialchars((string)$r['share_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="status-chip <?php echo (!$isEnabledRow || $isUpcomingRow) ? 'status-chip--off' : ($isExpiredRow ? 'status-chip--expired' : ''); ?>">
                  <?php echo $isEnabledRow ? 'Wlaczony' : 'Wylaczony'; ?>
                  <?php if ($isExpiredRow): ?>
                    <span>(Wygasl)</span>
                  <?php elseif ($isUpcomingRow): ?>
                    <span>(Start od <?php echo htmlspecialchars(substr((string)$r['available_from'], 0, 10), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>)</span>
                  <?php endif; ?>
                </span>
              </td>
              <td>
                <?php if (!empty($r['fallback_url'])): ?>
                  <a href="<?php echo htmlspecialchars((string)$r['fallback_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars((string)$r['fallback_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></a>
                <?php else: ?>
                  <span class="muted">brak</span>
                <?php endif; ?>
              </td>
              <td><?php echo htmlspecialchars((string)($r['available_from'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars((string)($r['expires_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars((string)$r['download_count'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars(number_format((int)$r['file_size'] ?: 0, 0, ',', ' ') . ' B', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars((string)$r['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
              <td><a class="btn" href="docs.php?edit=<?php echo (int)$r['id']; ?>">Edytuj</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </main>
  <script defer src="docs.js"></script>
</body>
</html>
