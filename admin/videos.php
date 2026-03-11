<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require_login();
require_admin();

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function validate_youtube_id(string $value): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{6,20}$/', $value);
}

function extract_youtube_id(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (validate_youtube_id($value)) {
        return $value;
    }

    $parts = @parse_url($value);
    if (!is_array($parts)) {
        return '';
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    $path = trim((string)($parts['path'] ?? ''), '/');
    $query = (string)($parts['query'] ?? '');

    if ($host === 'youtu.be' || $host === 'www.youtu.be') {
        $id = $path === '' ? '' : explode('/', $path)[0];
        return validate_youtube_id($id) ? $id : '';
    }
    if (
        $host === 'youtube.com' ||
        $host === 'www.youtube.com' ||
        $host === 'm.youtube.com' ||
        $host === 'music.youtube.com'
    ) {
        parse_str($query, $queryParams);
        if (!empty($queryParams['v']) && validate_youtube_id((string)$queryParams['v'])) {
            return (string)$queryParams['v'];
        }
        $segments = $path === '' ? [] : explode('/', $path);
        if (count($segments) >= 2 && in_array($segments[0], ['embed', 'shorts', 'live', 'v'], true)) {
            $candidate = (string)$segments[1];
            return validate_youtube_id($candidate) ? $candidate : '';
        }
    }
    return '';
}

$error = '';
$success = '';
$sourceInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $error = 'Nieprawidlowy token bezpieczenstwa.';
    } else {
        $action = trim((string)($_POST['action'] ?? 'add'));

        if ($action === 'add') {
            $sourceInput = trim((string)($_POST['youtube_source'] ?? ''));
            $youtubeId = extract_youtube_id($sourceInput);
            if ($youtubeId === '') {
                $error = 'Podaj poprawny link YouTube lub ID.';
            } else {
                try {
                    $selectStmt = $pdo->prepare('SELECT id, youtube_id FROM videos WHERE youtube_id = ? LIMIT 1');
                    $selectStmt->execute([$youtubeId]);
                    $existing = $selectStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                        $success = 'Film juz istnieje w bazie.';
                    } else {
                        $title = 'YouTube video ' . $youtubeId;
                        $slug = $youtubeId;
                        $thumbnail = 'https://i.ytimg.com/vi/' . rawurlencode($youtubeId) . '/hqdefault.jpg';
                        $statusCandidates = ['active', 'opublikowany', 'published', 'aktywny', 'draft'];
                        $inserted = false;
                        foreach ($statusCandidates as $status) {
                            try {
                                $insertStmt = $pdo->prepare(
                                    'INSERT INTO videos
                                        (youtube_id, tytul, slug, opis, miniaturka_url, status, dlugosc_sekundy, jezyk, publiczny, utworzono, zaktualizowano)
                                     VALUES
                                        (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                                );
                                $insertStmt->execute([
                                    $youtubeId,
                                    $title,
                                    $slug,
                                    '',
                                    $thumbnail,
                                    $status,
                                    0,
                                    'pl',
                                    1,
                                ]);
                                $inserted = true;
                                break;
                            } catch (Throwable $insertErr) {
                                continue;
                            }
                        }
                        if (!$inserted) {
                            throw new RuntimeException('Nie udalo sie dodac filmu do bazy.');
                        }
                        $success = 'Film zostal dodany.';
                    }
                } catch (Throwable $e) {
                    $error = 'Blad zapisu: ' . mb_substr($e->getMessage(), 0, 220);
                }
            }
        } elseif ($action === 'delete') {
            $videoId = (int)($_POST['video_id'] ?? 0);
            if ($videoId <= 0) {
                $error = 'Niepoprawne ID filmu do usuniecia.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('DELETE FROM user_video_access WHERE video_id = ?')->execute([$videoId]);
                    $pdo->prepare('DELETE FROM komentarze_video WHERE video_id = ?')->execute([$videoId]);
                    $deleteVideoStmt = $pdo->prepare('DELETE FROM videos WHERE id = ? LIMIT 1');
                    $deleteVideoStmt->execute([$videoId]);
                    if ($deleteVideoStmt->rowCount() < 1) {
                        $pdo->rollBack();
                        $error = 'Film nie istnieje lub zostal juz usuniety.';
                    } else {
                        $pdo->commit();
                        $success = 'Film zostal usuniety razem z przypisaniami i komentarzami.';
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Blad usuwania: ' . mb_substr($e->getMessage(), 0, 220);
                }
            }
        } elseif ($action === 'assign_user_to_video') {
            $videoId = (int)($_POST['video_id'] ?? 0);
            $userId = (int)($_POST['user_id'] ?? 0);
            $canEdit = ((string)($_POST['can_edit'] ?? '0') === '1') ? 1 : 0;
            if ($videoId <= 0 || $userId <= 0) {
                $error = 'Niepoprawne dane przypisania.';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO user_video_access (user_id, video_id, can_edit, created_at, updated_at)
                         VALUES (?, ?, ?, NOW(), NOW())
                         ON DUPLICATE KEY UPDATE can_edit = VALUES(can_edit), updated_at = NOW()'
                    );
                    $stmt->execute([$userId, $videoId, $canEdit]);
                    $success = 'Przypisanie zapisane.';
                } catch (Throwable $e) {
                    $error = 'Blad przypisania: ' . mb_substr($e->getMessage(), 0, 220);
                }
            }
        } elseif ($action === 'unassign_user_from_video') {
            $videoId = (int)($_POST['video_id'] ?? 0);
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($videoId <= 0 || $userId <= 0) {
                $error = 'Niepoprawne dane odpiecia.';
            } else {
                try {
                    $stmt = $pdo->prepare('DELETE FROM user_video_access WHERE user_id = ? AND video_id = ? LIMIT 1');
                    $stmt->execute([$userId, $videoId]);
                    $success = $stmt->rowCount() > 0 ? 'Przypisanie usuniete.' : 'Brak takiego przypisania.';
                } catch (Throwable $e) {
                    $error = 'Blad usuwania przypisania: ' . mb_substr($e->getMessage(), 0, 220);
                }
            }
        } elseif ($action === 'set_assignment_edit_flag') {
            $videoId = (int)($_POST['video_id'] ?? 0);
            $userId = (int)($_POST['user_id'] ?? 0);
            $canEdit = ((string)($_POST['can_edit'] ?? '0') === '1') ? 1 : 0;
            if ($videoId <= 0 || $userId <= 0) {
                $error = 'Niepoprawne dane zmiany uprawnienia.';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        'UPDATE user_video_access
                         SET can_edit = ?, updated_at = NOW()
                         WHERE user_id = ? AND video_id = ?'
                    );
                    $stmt->execute([$canEdit, $userId, $videoId]);
                    $success = $stmt->rowCount() > 0 ? 'Uprawnienie edycji zaktualizowane.' : 'Brak przypisania do aktualizacji.';
                } catch (Throwable $e) {
                    $error = 'Blad aktualizacji uprawnienia: ' . mb_substr($e->getMessage(), 0, 220);
                }
            }
        }
    }
}

$videos = [];
$users = [];
$assignments = [];

try {
    $videosStmt = $pdo->query(
        'SELECT id, youtube_id, tytul, status, publiczny, zaktualizowano
         FROM videos
         ORDER BY zaktualizowano DESC, id DESC
         LIMIT 200'
    );
    $videos = $videosStmt ? ($videosStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    // no-op
}

try {
    $usersStmt = $pdo->query(
        "SELECT id, email, role, is_active
         FROM users
         WHERE role IN ('editor', 'viewer')
         ORDER BY email ASC"
    );
    $users = $usersStmt ? ($usersStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    // no-op
}

try {
    $assignStmt = $pdo->query(
        "SELECT uva.user_id, uva.video_id, uva.can_edit, uva.updated_at,
                u.email AS user_email, u.role AS user_role,
                v.youtube_id, v.tytul
         FROM user_video_access uva
         JOIN users u ON u.id = uva.user_id
         JOIN videos v ON v.id = uva.video_id
         ORDER BY v.zaktualizowano DESC, v.id DESC, u.email ASC"
    );
    $assignments = $assignStmt ? ($assignStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    // no-op
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin - Wideo</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; color:#111827; }
    header { background:#040327; color:#fff; padding:16px 24px; display:flex; justify-content:space-between; align-items:center; gap:12px; }
    main { max-width:1200px; margin:24px auto; padding:0 24px; display:grid; gap:16px; }
    .card { background:#fff; border-radius:14px; padding:16px; box-shadow:0 10px 28px rgba(0,0,0,.06); }
    .row { display:grid; gap:6px; margin-bottom:12px; }
    .grid { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); }
    input, select { width:100%; border:1px solid #d1d5db; border-radius:10px; padding:10px 12px; font:inherit; }
    button, a.btn { display:inline-block; background:#040327; color:#fff; border:0; border-radius:10px; padding:10px 14px; text-decoration:none; font-weight:600; cursor:pointer; }
    a.btn.secondary { background:#fff; color:#111827; border:1px solid #d1d5db; }
    .ok { color:#166534; font-weight:600; }
    .err { color:#991b1b; font-weight:600; }
    table { width:100%; border-collapse:collapse; font-size:14px; }
    th, td { text-align:left; border-bottom:1px solid #e5e7eb; padding:8px 6px; vertical-align:top; }
    code { background:#f3f4f6; padding:2px 6px; border-radius:6px; }
    .inline { display:inline-flex; gap:8px; align-items:center; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
</head>
<body>
  <header>
    <div><strong>Wideo - administracja</strong></div>
    <div><a class="btn secondary" href="index.php">Powrot do panelu</a></div>
  </header>
  <main>
    <section class="card">
      <h1 style="margin-top:0;">Dodaj film YouTube</h1>
      <p>Wklej pelny URL YouTube lub samo ID.</p>
      <?php if ($error !== ''): ?><p class="err"><?php echo h($error); ?></p><?php endif; ?>
      <?php if ($success !== ''): ?><p class="ok"><?php echo h($success); ?></p><?php endif; ?>
      <form method="post" action="videos.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="add">
        <div class="row">
          <label for="youtube_source">Link lub ID</label>
          <input id="youtube_source" name="youtube_source" type="text" required placeholder="https://www.youtube.com/watch?v=..." value="<?php echo h($sourceInput); ?>">
        </div>
        <button type="submit">Dodaj do bazy</button>
      </form>
    </section>

    <section class="card">
      <h2 style="margin-top:0;">Przypisz video do uzytkownika</h2>
      <form method="post" action="videos.php" class="grid" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="assign_user_to_video">
        <label class="row">
          <span>Video</span>
          <select name="video_id" required>
            <option value="">Wybierz video...</option>
            <?php foreach ($videos as $video): ?>
              <option value="<?php echo h((string)$video['id']); ?>">
                <?php echo h((string)$video['youtube_id'] . ' - ' . (string)$video['tytul']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="row">
          <span>Uzytkownik (trener/user)</span>
          <select name="user_id" required>
            <option value="">Wybierz uzytkownika...</option>
            <?php foreach ($users as $user): ?>
              <option value="<?php echo h((string)$user['id']); ?>">
                <?php echo h((string)$user['email'] . ' [' . (string)$user['role'] . ']'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="row">
          <span>Tryb</span>
          <select name="can_edit">
            <option value="0">view</option>
            <option value="1">edit</option>
          </select>
        </label>
        <div class="row" style="align-self:end;">
          <button type="submit">Zapisz przypisanie</button>
        </div>
      </form>
    </section>

    <section class="card">
      <h2 style="margin-top:0;">Aktywne przypisania</h2>
      <?php if (!$assignments): ?>
        <p>Brak przypisan video do uzytkownikow.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Video</th>
              <th>Uzytkownik</th>
              <th>Tryb</th>
              <th>Zmiana trybu</th>
              <th>Odepnij</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($assignments as $a): ?>
              <tr>
                <td>
                  <code><?php echo h((string)$a['youtube_id']); ?></code><br>
                  <?php echo h((string)$a['tytul']); ?>
                </td>
                <td><?php echo h((string)$a['user_email'] . ' [' . (string)$a['user_role'] . ']'); ?></td>
                <td><?php echo ((int)$a['can_edit'] === 1) ? 'edit' : 'view'; ?></td>
                <td>
                  <form method="post" action="videos.php" class="inline">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="set_assignment_edit_flag">
                    <input type="hidden" name="video_id" value="<?php echo h((string)$a['video_id']); ?>">
                    <input type="hidden" name="user_id" value="<?php echo h((string)$a['user_id']); ?>">
                    <select name="can_edit">
                      <option value="0" <?php echo ((int)$a['can_edit'] === 0) ? 'selected' : ''; ?>>view</option>
                      <option value="1" <?php echo ((int)$a['can_edit'] === 1) ? 'selected' : ''; ?>>edit</option>
                    </select>
                    <button type="submit">Zmien</button>
                  </form>
                </td>
                <td>
                  <form method="post" action="videos.php" onsubmit="return confirm('Usunac przypisanie?');">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="unassign_user_from_video">
                    <input type="hidden" name="video_id" value="<?php echo h((string)$a['video_id']); ?>">
                    <input type="hidden" name="user_id" value="<?php echo h((string)$a['user_id']); ?>">
                    <button type="submit" style="background:#9f1239;">Usun</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2 style="margin-top:0;">Filmy w bazie</h2>
      <?php if (!$videos): ?>
        <p>Brak filmow.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>YouTube ID</th>
              <th>Tytul</th>
              <th>Status</th>
              <th>Publiczny</th>
              <th>Zaktualizowano</th>
              <th>Podglad</th>
              <th>Akcja</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($videos as $video): ?>
              <tr>
                <td><?php echo h((string)$video['id']); ?></td>
                <td><code><?php echo h((string)$video['youtube_id']); ?></code></td>
                <td><?php echo h((string)$video['tytul']); ?></td>
                <td><?php echo h((string)$video['status']); ?></td>
                <td><?php echo ((int)$video['publiczny'] === 1) ? 'tak' : 'nie'; ?></td>
                <td><?php echo h((string)$video['zaktualizowano']); ?></td>
                <td><a href="/video.html?source=<?php echo urlencode((string)$video['youtube_id']); ?>" target="_blank" rel="noopener">otworz</a></td>
                <td>
                  <form method="post" action="videos.php" onsubmit="return confirm('Usunac ten film i wszystkie jego komentarze?');">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="video_id" value="<?php echo h((string)$video['id']); ?>">
                    <button type="submit" style="background:#9f1239;">Usun</button>
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

