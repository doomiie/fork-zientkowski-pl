<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_login();
require_admin();

// Collect .html and .php files from site root, /lp and /masterclass (non-recursive)
$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$entries = [];
$ok = '';
$error = '';

/**
 * @param string $dir Absolute directory
 * @param string $prefix Web path prefix (leading slash, no trailing slash)
 * @param array<int, array{href:string,name:string,mtime:int,size:int,where:string}> $out
 */
function collectFiles(string $dir, string $prefix, array &$out, string $where): void {
    if (!is_dir($dir)) {
        return;
    }
    $dh = opendir($dir);
    if (!$dh) {
        return;
    }
    while (($file = readdir($dh)) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) {
            continue;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext !== 'html' && $ext !== 'php') {
            continue;
        }
        // Skip admin pages to avoid exposing backend
        if ($where === 'root' && in_array($file, ['admin.php'], true)) {
            continue;
        }
        $href = rtrim($prefix, '/') . '/' . rawurlencode($file);
        $name = preg_replace('/\.(html|php)$/i', '', $file) ?: $file;
        $out[] = [
            'href' => $href,
            'name' => $name,
            'mtime' => @filemtime($path) ?: 0,
            'size' => @filesize($path) ?: 0,
            'where' => $where,
        ];
    }
    closedir($dh);
}

collectFiles($root, '', $entries, 'root');
collectFiles($root . DIRECTORY_SEPARATOR . 'lp', '/lp', $entries, 'lp');
collectFiles($root . DIRECTORY_SEPARATOR . 'masterclass', '/masterclass', $entries, 'masterclass');

/**
 * @param array<int, array{href:string,name:string,mtime:int,size:int,where:string}> $entries
 */
function resolveDeleteTarget(string $root, string $where, string $href, array $entries): ?string {
    $allowedDirs = [
        'root' => $root,
        'lp' => $root . DIRECTORY_SEPARATOR . 'lp',
        'masterclass' => $root . DIRECTORY_SEPARATOR . 'masterclass',
    ];
    $allowedPrefixes = [
        'root' => '',
        'lp' => '/lp',
        'masterclass' => '/masterclass',
    ];

    if (!isset($allowedDirs[$where], $allowedPrefixes[$where])) {
        return null;
    }

    $entryKey = $where . '|' . $href;
    $known = false;
    foreach ($entries as $entry) {
        if (($entry['where'] . '|' . $entry['href']) === $entryKey) {
            $known = true;
            break;
        }
    }
    if (!$known) {
        return null;
    }

    $decodedHref = rawurldecode(trim($href));
    $prefix = $allowedPrefixes[$where];
    if ($prefix === '') {
        if (!preg_match('#^/[^/]+$#', $decodedHref)) {
            return null;
        }
    } else {
        if (!preg_match('#^' . preg_quote($prefix, '#') . '/[^/]+$#', $decodedHref)) {
            return null;
        }
    }

    $fileName = basename($decodedHref);
    if ($fileName === '' || $fileName === '.' || $fileName === '..') {
        return null;
    }

    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext !== 'html' && $ext !== 'php') {
        return null;
    }

    $dirReal = realpath($allowedDirs[$where]);
    if ($dirReal === false) {
        return null;
    }

    $path = $dirReal . DIRECTORY_SEPARATOR . $fileName;
    $pathReal = realpath($path);
    if ($pathReal === false || !is_file($pathReal)) {
        return null;
    }

    $dirPrefix = rtrim($dirReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strncmp($pathReal, $dirPrefix, strlen($dirPrefix)) !== 0) {
        return null;
    }

    return $pathReal;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $error = 'Nieprawidłowy token bezpieczeństwa.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'delete_page') {
            $where = (string)($_POST['where'] ?? '');
            $href = (string)($_POST['href'] ?? '');
            $target = resolveDeleteTarget($root, $where, $href, $entries);

            if ($target === null) {
                $error = 'Nie można usunąć tej strony.';
            } elseif (!is_writable($target)) {
                $error = 'Brak uprawnień do usunięcia pliku.';
            } elseif (!@unlink($target)) {
                $error = 'Usuwanie pliku nie powiodło się.';
            } else {
                header('Location: pages.php?ok=' . rawurlencode('Strona została usunięta.'));
                exit;
            }
        }
    }
}

if (isset($_GET['ok']) && is_string($_GET['ok'])) {
    $ok = (string)$_GET['ok'];
}

// Sort by location then name
usort($entries, static function ($a, $b) {
    return [$a['where'], $a['name']] <=> [$b['where'], $b['name']];
});

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Lista stron</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; }
    header { background:#040327; color:#fff; padding:16px 24px; display:flex; justify-content:space-between; align-items:center; }
    main { max-width:1200px; margin:24px auto; padding:0 24px; }
    .actions { display:flex; gap:8px; }
    a.btn { display:inline-block; background:#fff; color:#040327; border:1px solid #9ca3af; padding:8px 12px; border-radius:8px; text-decoration:none; font-weight:600; }
    .grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap:16px; }
    .card { background:#fff; border-radius:16px; padding:16px; box-shadow:0 12px 30px rgba(0,0,0,.06); border:1px solid #e5e7eb; }
    .muted { color:#6b7280; font-size:12px; }
    .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; border:1px solid #e5e7eb; background:#f9fafb; color:#374151; }
    .search { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; }
    .title { font-weight:700; color:#040327; margin:0 0 6px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .row { display:flex; justify-content:space-between; align-items:center; gap:8px; }
    .link { display:inline-block; color:#040327; font-weight:600; text-decoration:none; }
    .link:hover { text-decoration:underline; }
    .flash { border-radius:10px; padding:10px 12px; margin:0 0 16px; border:1px solid; font-size:14px; }
    .flash-ok { background:#ecfdf5; border-color:#86efac; color:#166534; }
    .flash-err { background:#fef2f2; border-color:#fca5a5; color:#991b1b; }
    .card-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
    .btn-danger { background:#fff; color:#b91c1c; border:1px solid #ef4444; padding:8px 12px; border-radius:8px; font-weight:700; cursor:pointer; }
    .btn-danger:hover { background:#fef2f2; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
  <meta name="robots" content="noindex,nofollow">
</head>
<body>
  <header>
    <div><strong>Lista stron (root + /lp + /masterclass)</strong></div>
    <div class="actions">
      <a class="btn" href="index.php">Powrót</a>
      <span>Użytkownik: <?php echo h(current_user_email()); ?></span>
    </div>
  </header>
  <main>
    <?php if ($ok !== ''): ?>
      <div class="flash flash-ok"><?php echo h($ok); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div class="flash flash-err"><?php echo h($error); ?></div>
    <?php endif; ?>

    <div style="margin:8px 0 16px;">
      <input id="q" class="search" type="search" placeholder="Filtruj po nazwie lub ścieżce...">
    </div>

    <div id="grid" class="grid">
      <?php foreach ($entries as $e): ?>
        <div class="card" data-name="<?php echo h($e['name']); ?>" data-path="<?php echo h($e['href']); ?>" data-where="<?php echo h($e['where']); ?>">
          <div class="row">
            <div class="badge"><?php echo $e['where'] === 'root' ? 'katalog główny' : ($e['where'] === 'lp' ? '/lp' : '/masterclass'); ?></div>
            <div class="muted"><?php echo number_format($e['size'] / 1024, 1); ?> KB</div>
          </div>
          <div class="title" title="<?php echo h($e['name']); ?>"><?php echo h($e['name']); ?></div>
          <div class="muted">Ścieżka: <?php echo h($e['href'] ?: '/'); ?></div>
          <div class="muted">Modyfikacja: <?php echo $e['mtime'] ? date('Y-m-d H:i', $e['mtime']) : '-'; ?></div>
          <div class="card-actions">
            <a class="link" href="<?php echo h($e['href']); ?>" target="_blank" rel="noopener">Otwórz stronę</a>
            <form method="post" class="delete-form">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="action" value="delete_page">
              <input type="hidden" name="where" value="<?php echo h($e['where']); ?>">
              <input type="hidden" name="href" value="<?php echo h($e['href']); ?>">
              <button type="submit" class="btn-danger">Usuń stronę</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </main>
  <script>
    (function(){
      const q = document.getElementById('q');
      const cards = Array.from(document.querySelectorAll('#grid .card'));
      function filter(){
        const v = (q && q.value || '').toLowerCase();
        cards.forEach(c=>{
          const hay = (c.dataset.name + ' ' + c.dataset.path + ' ' + c.dataset.where).toLowerCase();
          c.style.display = hay.indexOf(v) >= 0 ? '' : 'none';
        });
      }
      q && q.addEventListener('input', filter);

      document.querySelectorAll('.delete-form').forEach(function(form){
        form.addEventListener('submit', function(event){
          const card = form.closest('.card');
          const pagePath = card ? (card.dataset.path || '') : '';
          const msg = 'Czy na pewno chcesz usunąć tę stronę?\n' + pagePath + '\nTej operacji nie da się cofnąć.';
          if (!window.confirm(msg)) {
            event.preventDefault();
          }
        });
      });
    })();
  </script>
</body>
</html>
