<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_login();
require_admin();

// Collect .html and .php files from site root and /lp (non-recursive)
$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$entries = [];

/**
 * @param string $dir Absolute directory
 * @param string $prefix Web path prefix (leading slash, no trailing slash)
 * @param array<int, array{href:string,name:string,mtime:int,size:int,where:string}> $out
 */
function collectFiles(string $dir, string $prefix, array &$out, string $where): void {
    if (!is_dir($dir)) return;
    $dh = opendir($dir);
    if (!$dh) return;
    while (($file = readdir($dh)) !== false) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext !== 'html' && $ext !== 'php') continue;
        // Skip admin pages to avoid exposing backend
        if ($where === 'root' && in_array($file, ['admin.php'], true)) continue;
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

// Sort by location then name
usort($entries, static function($a,$b){
    return [$a['where'], $a['name']] <=> [$b['where'], $b['name']];
});

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
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
    .link { display:inline-block; margin-top:8px; color:#040327; font-weight:600; text-decoration:none; }
    .link:hover { text-decoration:underline; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
  <meta name="robots" content="noindex,nofollow">
</head>
<body>
  <header>
    <div><strong>Lista stron (root + /lp)</strong></div>
    <div class="actions">
      <a class="btn" href="index.php">Powrót</a>
      <span>Użytkownik: <?php echo h(current_user_email()); ?></span>
    </div>
  </header>
  <main>
    <div style="margin:8px 0 16px;">
      <input id="q" class="search" type="search" placeholder="Filtruj po nazwie lub ścieżce...">
    </div>
    <div id="grid" class="grid">
      <?php foreach ($entries as $e): ?>
        <div class="card" data-name="<?php echo h($e['name']); ?>" data-path="<?php echo h($e['href']); ?>" data-where="<?php echo h($e['where']); ?>">
          <div class="row"><div class="badge"><?php echo $e['where']==='root'?'katalog główny':'/lp'; ?></div><div class="muted"><?php echo number_format($e['size']/1024,1); ?> KB</div></div>
          <div class="title" title="<?php echo h($e['name']); ?>"><?php echo h($e['name']); ?></div>
          <div class="muted">Ścieżka: <?php echo h($e['href'] ?: '/'); ?></div>
          <div class="muted">Modyfikacja: <?php echo $e['mtime'] ? date('Y-m-d H:i',$e['mtime']) : '—'; ?></div>
          <a class="link" href="<?php echo h($e['href']); ?>" target="_blank" rel="noopener">Otwórz stronę</a>
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
          const hay = (c.dataset.name+' '+c.dataset.path+' '+c.dataset.where).toLowerCase();
          c.style.display = hay.indexOf(v) >= 0 ? '' : 'none';
        });
      }
      q && q.addEventListener('input', filter);
    })();
  </script>
</body>
</html>

