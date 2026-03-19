<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../backend/access_guard.php';
require_once __DIR__ . '/../backend/video_review_lib.php';

function rp_validate_source_key(string $value): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{6,20}$/', $value);
}

/**
 * @return string[]
 */
function rp_role_list(string $raw): array
{
    $value = strtolower(trim($raw));
    if ($value === '') return [];
    $parts = preg_split('/[\s,;|]+/', $value) ?: [];
    $roles = [];
    foreach ($parts as $part) {
        $role = trim((string)$part);
        if ($role === '') continue;
        $roles[$role] = true;
    }
    return array_keys($roles);
}

function rp_role_has(string $raw, string $role): bool
{
    $needle = strtolower(trim($role));
    if ($needle === '') return false;
    return in_array($needle, rp_role_list($raw), true);
}

/**
 * @return array{logged_in:bool,user_id:int|null,email:string|null,role:string|null}
 */
function rp_current_user_auth(PDO $pdo): array
{
    if (!is_logged_in()) {
        return ['logged_in' => false, 'user_id' => null, 'email' => null, 'role' => null];
    }
    $userId = current_user_id();
    if ($userId <= 0) {
        return ['logged_in' => false, 'user_id' => null, 'email' => null, 'role' => null];
    }
    try {
        $stmt = $pdo->prepare('SELECT id, email, role, is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['is_active'] !== 1) {
            return ['logged_in' => false, 'user_id' => null, 'email' => null, 'role' => null];
        }
        $rawRole = (string)($row['role'] ?? 'viewer');
        $mapped = 'user';
        if (rp_role_has($rawRole, 'admin')) {
            $mapped = 'admin';
        } elseif (rp_role_has($rawRole, 'editor')) {
            $mapped = 'trener';
        }
        return [
            'logged_in' => true,
            'user_id' => (int)$row['id'],
            'email' => (string)$row['email'],
            'role' => $mapped,
        ];
    } catch (Throwable $e) {
        return ['logged_in' => false, 'user_id' => null, 'email' => null, 'role' => null];
    }
}

/**
 * @return string[]
 */
function rp_user_allowed_sources(PDO $pdo, int $userId): array
{
    if ($userId <= 0) return [];
    try {
        $stmt = $pdo->prepare(
            'SELECT v.youtube_id
             FROM user_video_access uva
             JOIN videos v ON v.id = uva.video_id
             WHERE uva.user_id = ?'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $sources = [];
        foreach ($rows as $row) {
            $source = trim((string)($row['youtube_id'] ?? ''));
            if ($source !== '' && rp_validate_source_key($source)) {
                $sources[$source] = true;
            }
        }
        return array_keys($sources);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param array{logged_in:bool,user_id:int|null,email:string|null,role:string|null} $userAuth
 * @return array{global_view:bool,allowed_sources:array<int,string>}
 */
function rp_effective_view_access(PDO $pdo, array $userAuth): array
{
    $session = access_get_session($pdo);
    $tokenGlobalView = false;
    $tokenResourceSource = null;
    if ($session && (string)($session['target_key'] ?? '') === 'video') {
        $scope = strtolower(trim((string)($session['scope'] ?? '')));
        if ($scope === 'view' || $scope === 'edit') {
            $resourceType = trim((string)($session['resource_type'] ?? ''));
            $resourceId = trim((string)($session['resource_id'] ?? ''));
            if ($resourceType === '' && $resourceId === '') {
                $tokenGlobalView = true;
            } elseif ($resourceType === 'video' && rp_validate_source_key($resourceId)) {
                $tokenResourceSource = $resourceId;
            }
        }
    }

    $isAdmin = ((string)($userAuth['role'] ?? '') === 'admin');
    $allowedSources = [];
    $userId = (int)($userAuth['user_id'] ?? 0);
    if ($userId > 0 && !$isAdmin) {
        $allowedSources = rp_user_allowed_sources($pdo, $userId);
    }
    if ($tokenResourceSource !== null) {
        $allowedSources[] = $tokenResourceSource;
    }
    $allowedSources = array_values(array_unique($allowedSources));

    return [
        'global_view' => $isAdmin || $tokenGlobalView,
        'allowed_sources' => $allowedSources,
    ];
}

function rp_can_view_source(PDO $pdo, array $userAuth, string $source): bool
{
    if (!rp_validate_source_key($source)) return false;
    $access = rp_effective_view_access($pdo, $userAuth);
    if (!empty($access['global_view'])) return true;
    return in_array($source, $access['allowed_sources'], true);
}

function rp_score_tone(int $score): string
{
    if ($score <= 1) return 'low';
    if ($score === 2) return 'mid';
    return 'high';
}

function rp_score_label(int $score): string
{
    if ($score <= 1) return 'do poprawy';
    if ($score === 2) return 'srednio';
    return 'mocna strona';
}

function rp_format_time_label(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;
    if ($hours > 0) {
        return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
    }
    return sprintf('%02d:%02d', $minutes, $secs);
}

$source = trim((string)($_GET['source'] ?? ''));
$reviewId = (int)($_GET['review_id'] ?? 0);
if (!rp_validate_source_key($source) || $reviewId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Niepoprawne parametry.';
    exit;
}

$userAuth = rp_current_user_auth($pdo);
if (!rp_can_view_source($pdo, $userAuth, $source)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Brak dostepu do podsumowania.';
    exit;
}

$videoStmt = $pdo->prepare('SELECT id, youtube_id, tytul FROM videos WHERE youtube_id = ? LIMIT 1');
$videoStmt->execute([$source]);
$video = $videoStmt->fetch(PDO::FETCH_ASSOC);
if (!$video) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Nie znaleziono filmu.';
    exit;
}

$catalog = vr_catalog();
$dict = vr_item_dict();
$publishedRows = vr_load_published_summaries($pdo, (int)$video['id']);
$selected = null;
foreach ($publishedRows as $row) {
    if ((int)$row['id'] === $reviewId) {
        $selected = vr_hydrate_summary($pdo, $row, $catalog, $dict);
        break;
    }
}
if (!$selected) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Nie znaleziono opublikowanego podsumowania.';
    exit;
}

$title = trim((string)($video['tytul'] ?? ''));
if ($title === '') $title = 'Podsumowanie nagrania';
$reviewerEmail = trim((string)($selected['reviewer_email'] ?? ''));
if ($reviewerEmail === '') $reviewerEmail = '-';
$publishedAt = trim((string)($selected['published_at'] ?? ''));
$totalScore = (int)($selected['total_score'] ?? 0);
$maxScore = (int)($selected['max_score'] ?? 0);
$percent = ($maxScore > 0) ? (int)round(($totalScore / $maxScore) * 100) : 0;

$commentsStmt = $pdo->prepare(
    'SELECT id, czas_sekundy, czas_tekst, tytul, tresc, wariant, autor
     FROM komentarze_video
     WHERE video_id = ? AND widoczny = 1
     ORDER BY kolejnosc ASC, czas_sekundy ASC, id ASC'
);
$commentsStmt->execute([(int)$video['id']]);
$timelineComments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($timelineComments as &$timelineComment) {
    $commentSeconds = max(0, (int)($timelineComment['czas_sekundy'] ?? 0));
    $timelineComment['czas_sekundy'] = $commentSeconds;
    $timelineComment['czas_tekst'] = trim((string)($timelineComment['czas_tekst'] ?? ''));
    if ($timelineComment['czas_tekst'] === '') {
        $timelineComment['czas_tekst'] = rp_format_time_label($commentSeconds);
    }
    $timelineComment['tytul'] = trim((string)($timelineComment['tytul'] ?? ''));
    $timelineComment['tresc'] = trim((string)($timelineComment['tresc'] ?? ''));
    $timelineComment['wariant'] = trim((string)($timelineComment['wariant'] ?? ''));
    $timelineComment['autor'] = trim((string)($timelineComment['autor'] ?? ''));
}
unset($timelineComment);
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Podsumowanie trenera - <?php echo htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></title>
  <style>
    :root {
      color-scheme: light;
      --rp-bg: #eef2f7;
      --rp-surface: #ffffff;
      --rp-border: #dce5f1;
      --rp-text: #111827;
      --rp-text-soft: #334155;
      --rp-brand: #040327;
      --rp-brand-2: #1f2049;
      --rp-bar-bg: #e6ecf5;
      --rp-bar-fill: linear-gradient(90deg, #040327 0%, #1f2049 100%);
      --rp-bar-fill-light: linear-gradient(90deg, #c7d2fe 0%, #ffffff 100%);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", Arial, sans-serif;
      color: var(--rp-text);
      background: var(--rp-bg);
      line-height: 1.4;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .print-wrap {
      max-width: 1040px;
      margin: 18px auto 28px;
      padding: 0 14px;
    }
    .print-actions {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      margin-bottom: 10px;
    }
    .print-btn {
      border: 1px solid #cdd6e3;
      border-radius: 999px;
      background: #fff;
      color: var(--rp-text);
      min-height: 34px;
      padding: 0 14px;
      font-size: 0.86rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .sheet {
      background: var(--rp-surface);
      border: 1px solid var(--rp-border);
      border-radius: 22px;
      box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
      overflow: hidden;
    }
    .hero {
      padding: 22px 24px 20px;
      background: linear-gradient(140deg, var(--rp-brand) 0%, var(--rp-brand-2) 100%);
      color: #fff;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .hero h1 {
      margin: 0;
      font-size: 1.9rem;
      line-height: 1.3;
      letter-spacing: 0.01em;
    }
    .hero-meta {
      margin-top: 10px;
      font-size: 0.98rem;
      opacity: 0.95;
      display: grid;
      gap: 4px;
    }
    .hero-score {
      margin-top: 18px;
      display: grid;
      gap: 8px;
    }
    .hero-score__line {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      font-weight: 700;
      font-size: 1rem;
    }
    .bar {
      height: 10px;
      border-radius: 999px;
      background: var(--rp-bar-bg);
      overflow: hidden;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .bar > span {
      display: block;
      height: 100%;
      width: 0;
      background: var(--rp-bar-fill);
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .content {
      padding: 16px;
      display: grid;
      gap: 12px;
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .card {
      border: 1px solid var(--rp-border);
      border-radius: 12px;
      background: var(--rp-surface);
      padding: 12px;
      break-inside: avoid;
    }
    .card h2 {
      margin: 0 0 8px;
      font-size: 0.92rem;
      color: var(--rp-brand);
    }
    .item { margin-bottom: 9px; }
    .item:last-child { margin-bottom: 0; }
    .item-label {
      display: block;
      margin-bottom: 4px;
      font-size: 0.8rem;
      color: var(--rp-text-soft);
    }
    .item-line {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 8px;
      align-items: center;
    }
    .item-line .bar {
      background: var(--rp-bar-bg);
      height: 8px;
    }
    .item-line .bar > span {
      background: var(--rp-bar-fill);
    }
    .tone {
      font-size: 0.79rem;
      font-weight: 700;
      color: #0f172a;
      white-space: nowrap;
    }
    .tone.low { color: #9a3412; }
    .tone.mid { color: #1d4ed8; }
    .tone.high { color: #166534; }
    .note {
      border: 1px solid var(--rp-border);
      border-radius: 12px;
      background: var(--rp-surface);
      margin: 0 16px 16px;
      padding: 12px;
      font-size: 0.9rem;
      color: #1f2937;
      white-space: pre-wrap;
    }
    .timeline-page {
      margin-top: 18px;
      background: var(--rp-surface);
      border: 1px solid var(--rp-border);
      border-radius: 22px;
      padding: 22px 24px 24px;
      box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
      break-before: page;
    }
    .timeline-head {
      display: grid;
      gap: 6px;
      margin-bottom: 18px;
    }
    .timeline-kicker {
      display: inline-flex;
      align-items: center;
      width: fit-content;
      min-height: 28px;
      padding: 0 10px;
      border-radius: 999px;
      background: #eef2ff;
      color: var(--rp-brand);
      font-size: 0.75rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .timeline-title {
      margin: 0;
      font-size: 1.45rem;
      line-height: 1.2;
      color: var(--rp-brand);
    }
    .timeline-meta {
      margin: 0;
      color: #64748b;
      font-size: 0.92rem;
    }
    .timeline {
      position: relative;
      display: grid;
      gap: 14px;
      padding-left: 132px;
    }
    .timeline::before {
      content: "";
      position: absolute;
      top: 4px;
      bottom: 4px;
      left: 91px;
      width: 2px;
      background: linear-gradient(180deg, #c7d2fe 0%, #e2e8f0 100%);
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .timeline-entry {
      position: relative;
      min-height: 72px;
    }
    .timeline-time {
      position: absolute;
      top: 8px;
      left: -132px;
      width: 72px;
      min-height: 28px;
      padding: 5px 8px;
      border-radius: 999px;
      background: linear-gradient(135deg, var(--rp-brand) 0%, var(--rp-brand-2) 100%);
      color: #fff;
      text-align: center;
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.02em;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .timeline-dot {
      position: absolute;
      top: 18px;
      left: -46px;
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background: #fff;
      border: 3px solid var(--rp-brand);
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .timeline-card {
      border: 1px solid var(--rp-border);
      border-radius: 16px;
      background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
      padding: 12px 14px;
      break-inside: avoid;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .timeline-card-head {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 10px;
    }
    .timeline-card-title {
      margin: 0;
      font-size: 0.96rem;
      line-height: 1.3;
      color: #0f172a;
      font-weight: 700;
    }
    .timeline-variant {
      display: inline-flex;
      align-items: center;
      min-height: 24px;
      padding: 0 8px;
      border-radius: 999px;
      background: #eef2ff;
      color: #3730a3;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .timeline-card-body {
      margin-top: 8px;
      color: var(--rp-text-soft);
      font-size: 0.88rem;
      line-height: 1.5;
      white-space: pre-wrap;
    }
    .timeline-card-footer {
      margin-top: 8px;
      color: var(--rp-text-muted);
      font-size: 0.76rem;
    }
    @media (max-width: 900px) {
      .content { grid-template-columns: 1fr; }
      .timeline {
        padding-left: 0;
      }
      .timeline::before,
      .timeline-time,
      .timeline-dot {
        position: static;
      }
      .timeline::before {
        display: none;
      }
      .timeline-entry {
        min-height: 0;
        display: grid;
        gap: 8px;
      }
      .timeline-card-head {
        display: grid;
      }
    }
    @page { size: A4; margin: 10mm; }
    @media print {
      html, body { background: #fff; }
      body {
        font-size: 11.2px;
        line-height: 1.28;
      }
      .print-wrap { margin: 0; max-width: none; padding: 0; }
      .print-actions { display: none !important; }
      .sheet {
        border: 0;
        border-radius: 0;
        box-shadow: none;
      }
      .hero {
        padding: 14px 16px 12px;
      }
      .hero h1 {
        font-size: 1.45rem;
      }
      .hero-meta {
        margin-top: 6px;
        font-size: 0.82rem;
        gap: 2px;
      }
      .hero-score {
        margin-top: 10px;
        gap: 4px;
      }
      .hero-score__line {
        font-size: 0.9rem;
      }
      .content {
        padding: 10px;
        gap: 8px;
      }
      .card {
        padding: 9px 10px;
      }
      .card h2 {
        margin-bottom: 5px;
        font-size: 0.82rem;
      }
      .item {
        margin-bottom: 6px;
      }
      .item-label {
        margin-bottom: 2px;
        font-size: 0.72rem;
      }
      .item-line {
        gap: 6px;
      }
      .bar {
        height: 8px;
      }
      .item-line .bar {
        height: 7px;
      }
      .tone {
        font-size: 0.72rem;
      }
      .note {
        margin: 0 10px 10px;
        padding: 9px 10px;
        font-size: 0.8rem;
        line-height: 1.32;
      }
      .timeline-page {
        margin-top: 0;
        border: 0;
        border-radius: 0;
        padding: 12px 2px 0;
        box-shadow: none;
      }
      .timeline-head {
        margin-bottom: 12px;
      }
      .timeline-kicker {
        min-height: 24px;
        padding: 0 8px;
        font-size: 0.64rem;
      }
      .timeline-title {
        font-size: 1.18rem;
      }
      .timeline-meta {
        font-size: 0.8rem;
      }
      .timeline {
        gap: 10px;
        padding-left: 104px;
      }
      .timeline::before {
        left: 72px;
      }
      .timeline-entry {
        min-height: 0;
      }
      .timeline-time {
        top: 6px;
        left: -104px;
        width: 58px;
        min-height: 24px;
        font-size: 0.7rem;
      }
      .timeline-dot {
        top: 14px;
        left: -38px;
        width: 10px;
        height: 10px;
        border-width: 2px;
      }
      .timeline-card {
        padding: 9px 10px;
        border-radius: 12px;
      }
      .timeline-card-title {
        font-size: 0.84rem;
      }
      .timeline-variant {
        min-height: 20px;
        font-size: 0.62rem;
      }
      .timeline-card-body {
        margin-top: 6px;
        font-size: 0.77rem;
        line-height: 1.35;
      }
      .timeline-card-footer {
        margin-top: 6px;
        font-size: 0.68rem;
      }
      .card, .note, .hero, .timeline-entry, .timeline-card { break-inside: avoid; }
    }
  </style>
</head>
<body>
  <div class="print-wrap">
    <div class="print-actions">
      <button type="button" class="print-btn" onclick="window.print()">Drukuj teraz</button>
      <a class="print-btn" href="/video/play.php?source=<?php echo urlencode($source); ?>">Powrot do nagrania</a>
    </div>

    <article class="sheet">
      <header class="hero">
        <h1><?php echo htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h1>
        <div class="hero-meta">
          <span>Podsumowanie trenera: <?php echo htmlspecialchars($reviewerEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
          <span>Data publikacji: <?php echo htmlspecialchars($publishedAt !== '' ? $publishedAt : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
          <span>Wersja: #<?php echo (int)($selected['version_no'] ?? 0); ?></span>
        </div>
        <div class="hero-score">
          <div class="hero-score__line">
            <span>Wynik globalny</span>
            <span><?php echo $totalScore; ?> / <?php echo $maxScore; ?> (<?php echo $percent; ?>%)</span>
          </div>
          <div class="bar"><span style="width: <?php echo max(0, min(100, $percent)); ?>%; background: var(--rp-bar-fill-light);"></span></div>
        </div>
      </header>

      <section class="content">
        <?php foreach ((array)($selected['categories'] ?? []) as $category): ?>
          <?php
            $avg = (float)($category['avg_score'] ?? 0);
            $avgPct = (int)round(($avg / 3) * 100);
          ?>
          <section class="card">
            <h2><?php echo htmlspecialchars((string)($category['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> &middot; <?php echo number_format($avg, 2); ?>/3</h2>
            <div class="bar"><span style="width: <?php echo max(0, min(100, $avgPct)); ?>%"></span></div>
            <?php foreach ((array)($category['items'] ?? []) as $item): ?>
              <?php
                $score = (int)($item['score'] ?? 0);
                $scorePct = (int)round(($score / 3) * 100);
                $tone = rp_score_tone($score);
              ?>
              <div class="item">
                <span class="item-label"><?php echo htmlspecialchars((string)($item['label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                <div class="item-line">
                  <div class="bar"><span style="width: <?php echo max(0, min(100, $scorePct)); ?>%"></span></div>
                  <span class="tone <?php echo htmlspecialchars($tone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                    <?php echo $score; ?>/3 &middot; <?php echo htmlspecialchars(rp_score_label($score), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                  </span>
                </div>
              </div>
            <?php endforeach; ?>
          </section>
        <?php endforeach; ?>
      </section>

      <?php $overallNote = trim((string)($selected['overall_note'] ?? '')); ?>
      <?php if ($overallNote !== ''): ?>
        <section class="note"><?php echo htmlspecialchars($overallNote, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></section>
      <?php endif; ?>
    </article>

    <?php if (!empty($timelineComments)): ?>
      <?php
        $firstTimeline = $timelineComments[0];
        $lastTimeline = $timelineComments[count($timelineComments) - 1];
        $timelineRange = (string)$firstTimeline['czas_tekst'] . ' - ' . (string)$lastTimeline['czas_tekst'];
      ?>
      <section class="timeline-page">
        <header class="timeline-head">
          <span class="timeline-kicker">Strona 2</span>
          <h2 class="timeline-title">Notatki trenerskie w czasie</h2>
          <p class="timeline-meta">
            <?php echo count($timelineComments); ?> komentarzy &middot; zakres nagrania: <?php echo htmlspecialchars($timelineRange, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
          </p>
        </header>

        <section class="timeline">
          <?php foreach ($timelineComments as $timelineComment): ?>
            <?php
              $commentTitle = (string)($timelineComment['tytul'] ?? '');
              if ($commentTitle === '') $commentTitle = 'Komentarz trenerski';
              $commentBody = (string)($timelineComment['tresc'] ?? '');
              $commentAuthor = (string)($timelineComment['autor'] ?? '');
              $commentVariant = (string)($timelineComment['wariant'] ?? '');
            ?>
            <article class="timeline-entry">
              <div class="timeline-time"><?php echo htmlspecialchars((string)$timelineComment['czas_tekst'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
              <div class="timeline-dot" aria-hidden="true"></div>
              <div class="timeline-card">
                <div class="timeline-card-head">
                  <h3 class="timeline-card-title"><?php echo htmlspecialchars($commentTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h3>
                  <?php if ($commentVariant !== ''): ?>
                    <span class="timeline-variant"><?php echo htmlspecialchars($commentVariant, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                  <?php endif; ?>
                </div>
                <?php if ($commentBody !== ''): ?>
                  <div class="timeline-card-body"><?php echo htmlspecialchars($commentBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                <?php endif; ?>
                <?php if ($commentAuthor !== ''): ?>
                  <div class="timeline-card-footer">Autor: <?php echo htmlspecialchars($commentAuthor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </section>
      </section>
    <?php endif; ?>
  </div>

  <script>
    window.addEventListener("load", function () {
      window.setTimeout(function () { window.print(); }, 220);
    });
  </script>
</body>
</html>
