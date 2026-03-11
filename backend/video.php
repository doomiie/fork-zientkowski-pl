<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/access_guard.php';

/**
 * @param array<string,mixed> $payload
 */
function json_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
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

function format_time_label(int $seconds): string
{
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) {
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }
    return sprintf('%02d:%02d', $m, $s);
}

function get_input_data(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }
    return $_POST;
}

/**
 * @return array{logged_in:bool,user_id:int|null,email:string|null,role:string|null}
 */
function get_current_user_auth(PDO $pdo): array
{
    if (!is_logged_in()) {
        return [
            'logged_in' => false,
            'user_id' => null,
            'email' => null,
            'role' => null,
        ];
    }

    $userId = current_user_id();
    if ($userId <= 0) {
        return [
            'logged_in' => false,
            'user_id' => null,
            'email' => null,
            'role' => null,
        ];
    }

    try {
        $stmt = $pdo->prepare('SELECT id, email, role, is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['is_active'] !== 1) {
            return [
                'logged_in' => false,
                'user_id' => null,
                'email' => null,
                'role' => null,
            ];
        }
        $rawRole = strtolower(trim((string)($row['role'] ?? 'viewer')));
        $mapped = 'user';
        if ($rawRole === 'admin') {
            $mapped = 'admin';
        } elseif ($rawRole === 'editor') {
            $mapped = 'trener';
        }
        return [
            'logged_in' => true,
            'user_id' => (int)$row['id'],
            'email' => (string)$row['email'],
            'role' => $mapped,
        ];
    } catch (Throwable $e) {
        return [
            'logged_in' => false,
            'user_id' => null,
            'email' => null,
            'role' => null,
        ];
    }
}

/**
 * @return array<string,bool>
 */
function get_user_video_access(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT v.youtube_id, uva.can_edit
             FROM user_video_access uva
             JOIN videos v ON v.id = uva.video_id
             WHERE uva.user_id = ?'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $youtubeId = trim((string)($row['youtube_id'] ?? ''));
            if ($youtubeId === '') {
                continue;
            }
            $map[$youtubeId] = ((int)($row['can_edit'] ?? 0) === 1);
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param array<string,mixed>|null $accessSession
 * @param array{logged_in:bool,user_id:int|null,email:string|null,role:string|null} $userAuth
 * @param array<string,bool> $userVideoMap
 * @return array<string,mixed>
 */
function build_effective_video_access(?array $accessSession, array $userAuth, array $userVideoMap): array
{
    $tokenEnabled = false;
    $tokenScope = null;
    $tokenResourceType = null;
    $tokenResourceId = null;
    $tokenGlobalView = false;
    $tokenGlobalEdit = false;

    if ($accessSession && (string)($accessSession['target_key'] ?? '') === 'video') {
        $tokenEnabled = true;
        $tokenScopeRaw = strtolower(trim((string)($accessSession['scope'] ?? '')));
        $tokenScope = ($tokenScopeRaw === 'edit') ? 'edit' : 'view';
        $tokenResourceTypeVal = trim((string)($accessSession['resource_type'] ?? ''));
        $tokenResourceIdVal = trim((string)($accessSession['resource_id'] ?? ''));
        if ($tokenResourceTypeVal !== '') {
            $tokenResourceType = $tokenResourceTypeVal;
        }
        if ($tokenResourceIdVal !== '') {
            $tokenResourceId = $tokenResourceIdVal;
        }

        $resourceRestricted = ($tokenResourceType !== null || $tokenResourceId !== null);
        if (!$resourceRestricted) {
            $tokenGlobalView = true;
            $tokenGlobalEdit = ($tokenScope === 'edit');
        }
    }

    $role = (string)($userAuth['role'] ?? '');
    $isAdmin = $role === 'admin';
    $isTrainer = $role === 'trener';
    $isUser = $role === 'user';

    if ($isUser) {
      foreach ($userVideoMap as $k => $v) {
          $userVideoMap[$k] = false;
      }
    }

    $canAddVideo = $isAdmin || $isTrainer;
    $globalView = $isAdmin || $tokenGlobalView;
    $globalEdit = $isAdmin || $tokenGlobalEdit;

    $allowedVideoIds = array_keys($userVideoMap);
    if ($tokenResourceType === 'video' && $tokenResourceId !== null) {
        $allowedVideoIds[] = $tokenResourceId;
    }
    $allowedVideoIds = array_values(array_unique(array_filter($allowedVideoIds, fn($v) => validate_youtube_id((string)$v))));

    return [
        'user' => $userAuth,
        'user_video_map' => $userVideoMap,
        'token' => [
            'enabled' => $tokenEnabled,
            'token_id' => $accessSession ? (int)($accessSession['token_id'] ?? 0) : null,
            'scope' => $tokenScope,
            'resource_type' => $tokenResourceType,
            'resource_id' => $tokenResourceId,
        ],
        'effective' => [
            'can_add_video' => $canAddVideo,
            'global_view' => $globalView,
            'global_edit' => $globalEdit,
            'allowed_video_ids' => $allowedVideoIds,
        ],
    ];
}

/**
 * @param array<string,mixed> $ctx
 */
function can_view_source(array $ctx, string $source): bool
{
    if (!validate_youtube_id($source)) {
        return false;
    }
    if (!empty($ctx['effective']['global_view'])) {
        return true;
    }
    $allowed = $ctx['effective']['allowed_video_ids'] ?? [];
    return in_array($source, is_array($allowed) ? $allowed : [], true);
}

/**
 * @param array<string,mixed> $ctx
 */
function can_edit_source(array $ctx, string $source): bool
{
    if (!validate_youtube_id($source)) {
        return false;
    }
    if (!empty($ctx['effective']['global_edit'])) {
        return true;
    }

    $userMap = is_array($ctx['user_video_map'] ?? null) ? $ctx['user_video_map'] : [];
    if (array_key_exists($source, $userMap) && (bool)$userMap[$source] === true) {
        return true;
    }

    $token = is_array($ctx['token'] ?? null) ? $ctx['token'] : [];
    $tokenScope = (string)($token['scope'] ?? '');
    $tokenType = (string)($token['resource_type'] ?? '');
    $tokenId = (string)($token['resource_id'] ?? '');
    if ($tokenScope === 'edit' && $tokenType === 'video' && $tokenId === $source) {
        return true;
    }

    return false;
}

/**
 * @param array<string,mixed> $ctx
 * @return array<string,mixed>
 */
function build_access_meta(array $ctx, ?string $source = null): array
{
    return [
        'user' => [
            'logged_in' => (bool)($ctx['user']['logged_in'] ?? false),
            'user_id' => $ctx['user']['user_id'] ?? null,
            'email' => $ctx['user']['email'] ?? null,
            'role' => $ctx['user']['role'] ?? null,
        ],
        'token' => [
            'token_id' => $ctx['token']['token_id'] ?? null,
            'scope' => $ctx['token']['scope'] ?? null,
            'resource_type' => $ctx['token']['resource_type'] ?? null,
            'resource_id' => $ctx['token']['resource_id'] ?? null,
        ],
        'effective' => [
            'can_add_video' => (bool)($ctx['effective']['can_add_video'] ?? false),
            'global_view' => (bool)($ctx['effective']['global_view'] ?? false),
            'global_edit' => (bool)($ctx['effective']['global_edit'] ?? false),
            'can_view_source' => $source !== null ? can_view_source($ctx, $source) : null,
            'can_edit_source' => $source !== null ? can_edit_source($ctx, $source) : null,
        ],
    ];
}

/**
 * @param array<string,mixed> $ctx
 */
function ensure_can_add_video(array $ctx): void
{
    if (!($ctx['effective']['can_add_video'] ?? false)) {
        json_response(403, [
            'ok' => false,
            'error' => 'add_video_forbidden',
            'message' => 'Brak uprawnień do dodawania filmów.',
        ]);
    }
}

/**
 * @param array<string,mixed> $ctx
 */
function ensure_can_view_source(array $ctx, string $source): void
{
    if (!can_view_source($ctx, $source)) {
        json_response(403, [
            'ok' => false,
            'error' => 'source_forbidden',
            'message' => 'Brak dostępu do tego filmu.',
        ]);
    }
}

/**
 * @param array<string,mixed> $ctx
 */
function ensure_can_edit_source(array $ctx, string $source): void
{
    if (!can_edit_source($ctx, $source)) {
        json_response(403, [
            'ok' => false,
            'error' => 'source_edit_forbidden',
            'message' => 'Brak uprawnień do edycji tego filmu.',
        ]);
    }
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_GET['action'] ?? ($method === 'POST' ? ($_POST['action'] ?? '') : 'load')));
$accessSession = access_get_session($pdo);
$userAuth = get_current_user_auth($pdo);
$userVideoMap = ($userAuth['logged_in'] && $userAuth['role'] !== 'admin')
    ? get_user_video_access($pdo, (int)($userAuth['user_id'] ?? 0))
    : [];
$ctx = build_effective_video_access($accessSession, $userAuth, $userVideoMap);

if ($action === 'list_videos' && $method === 'GET') {
    $editModeRequested = ((string)($_GET['edit'] ?? '') === '1');
    $editMode = $editModeRequested && ((bool)($ctx['effective']['global_edit'] ?? false));

    try {
        $sql = 'SELECT id, youtube_id, tytul, slug, miniaturka_url, status, publiczny, utworzono, zaktualizowano
                FROM videos';
        $where = [];
        $params = [];

        if (!($ctx['effective']['global_view'] ?? false)) {
            $allowed = $ctx['effective']['allowed_video_ids'] ?? [];
            if (!is_array($allowed) || count($allowed) === 0) {
                json_response(200, [
                    'ok' => true,
                    'videos' => [],
                    'edit' => false,
                    'access' => build_access_meta($ctx),
                ]);
            }
            $allowed = array_values(array_unique(array_filter(array_map('strval', $allowed), 'validate_youtube_id')));
            if (count($allowed) === 0) {
                json_response(200, [
                    'ok' => true,
                    'videos' => [],
                    'edit' => false,
                    'access' => build_access_meta($ctx),
                ]);
            }
            $in = implode(',', array_fill(0, count($allowed), '?'));
            $where[] = 'youtube_id IN (' . $in . ')';
            $params = array_merge($params, $allowed);
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY zaktualizowano DESC, id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $videos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($videos as &$video) {
            $vid = (string)($video['youtube_id'] ?? '');
            $video['can_edit'] = can_edit_source($ctx, $vid);
        }
        unset($video);

        json_response(200, [
            'ok' => true,
            'videos' => $videos,
            'edit' => $editMode,
            'access' => build_access_meta($ctx),
        ]);
    } catch (Throwable $e) {
        json_response(500, [
            'ok' => false,
            'error' => 'list_failed',
            'message' => 'Nie udało się pobrać listy filmów.',
        ]);
    }
}

if ($action === 'upsert_video' && $method === 'POST') {
    ensure_can_add_video($ctx);

    $data = get_input_data();
    $rawUrl = trim((string)($data['youtube_url'] ?? $data['source'] ?? ''));
    $youtubeId = extract_youtube_id($rawUrl);

    if ($youtubeId === '') {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_youtube_url',
            'message' => 'Podaj poprawny link lub ID YouTube.',
        ]);
    }

    try {
        $selectStmt = $pdo->prepare(
            'SELECT id, youtube_id, tytul, slug, opis, miniaturka_url, status, dlugosc_sekundy, jezyk, publiczny, utworzono, zaktualizowano
             FROM videos
             WHERE youtube_id = ?
             LIMIT 1'
        );
        $selectStmt->execute([$youtubeId]);
        $existingVideo = $selectStmt->fetch(PDO::FETCH_ASSOC);
        if ($existingVideo) {
            json_response(200, [
                'ok' => true,
                'created' => false,
                'video' => $existingVideo,
                'access' => build_access_meta($ctx),
            ]);
        }

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
            json_response(500, [
                'ok' => false,
                'error' => 'video_insert_failed',
                'message' => 'Nie udało się dodać filmu do bazy.',
            ]);
        }

        $selectStmt->execute([$youtubeId]);
        $newVideo = $selectStmt->fetch(PDO::FETCH_ASSOC);
        if (!$newVideo) {
            json_response(500, [
                'ok' => false,
                'error' => 'video_readback_failed',
                'message' => 'Film dodany, ale nie udało się odczytać rekordu.',
            ]);
        }

        // Auto-przypisanie dla trenera (editor -> trener)
        if (($ctx['user']['role'] ?? null) === 'trener' && !empty($ctx['user']['user_id'])) {
            try {
                $assignStmt = $pdo->prepare(
                    'INSERT INTO user_video_access (user_id, video_id, can_edit, created_at, updated_at)
                     VALUES (?, ?, 1, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE can_edit = 1, updated_at = NOW()'
                );
                $assignStmt->execute([(int)$ctx['user']['user_id'], (int)$newVideo['id']]);
            } catch (Throwable $assignErr) {
                // ignore assignment failures - video is already created
            }
        }

        json_response(201, [
            'ok' => true,
            'created' => true,
            'video' => $newVideo,
            'access' => build_access_meta($ctx),
        ]);
    } catch (Throwable $e) {
        json_response(500, [
            'ok' => false,
            'error' => 'video_upsert_failed',
            'message' => 'Nie udało się przetworzyć filmu.',
        ]);
    }
}

if ($action === 'load' && $method === 'GET') {
    $source = trim((string)($_GET['source'] ?? ''));
    if ($source === '' || !validate_youtube_id($source)) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_source',
            'message' => 'Brak poprawnego parametru source.',
        ]);
    }

    $editRequested = ((string)($_GET['edit'] ?? '') === '1');
    ensure_can_view_source($ctx, $source);
    if ($editRequested) {
        ensure_can_edit_source($ctx, $source);
    }
    $editMode = $editRequested && can_edit_source($ctx, $source);

    try {
        $videoStmt = $pdo->prepare(
            'SELECT id, youtube_id, tytul, slug, opis, miniaturka_url, status, dlugosc_sekundy, jezyk, publiczny, utworzono, zaktualizowano
             FROM videos
             WHERE youtube_id = ?
             LIMIT 1'
        );
        $videoStmt->execute([$source]);
        $video = $videoStmt->fetch(PDO::FETCH_ASSOC);
        if (!$video) {
            json_response(404, [
                'ok' => false,
                'error' => 'video_not_found',
                'message' => 'Nie znaleziono filmu.',
            ]);
        }

        $commentsSql = 'SELECT id, uuid, czas_sekundy, czas_tekst, tytul, tresc, wariant, kolejnosc, widoczny, autor, utworzono, zaktualizowano
                        FROM komentarze_video
                        WHERE video_id = ?';
        if (!$editMode) {
            $commentsSql .= ' AND widoczny = 1';
        }
        $commentsSql .= ' ORDER BY kolejnosc ASC, czas_sekundy ASC, id ASC';

        $commentsStmt = $pdo->prepare($commentsSql);
        $commentsStmt->execute([(int)$video['id']]);
        $comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($comments as &$comment) {
            $secs = (int)($comment['czas_sekundy'] ?? 0);
            if (trim((string)($comment['czas_tekst'] ?? '')) === '') {
                $comment['czas_tekst'] = format_time_label($secs);
            }
            $comment['czas_sekundy'] = $secs;
            $comment['widoczny'] = (int)($comment['widoczny'] ?? 0);
        }
        unset($comment);

        json_response(200, [
            'ok' => true,
            'video' => $video,
            'comments' => $comments,
            'edit' => $editMode,
            'access' => build_access_meta($ctx, $source),
        ]);
    } catch (Throwable $e) {
        json_response(500, [
            'ok' => false,
            'error' => 'load_failed',
            'message' => 'Wystąpił błąd podczas pobierania danych.',
        ]);
    }
}

if ($action === 'add_comment' && $method === 'POST') {
    $data = get_input_data();
    $source = trim((string)($data['source'] ?? ''));
    if ($source === '' || !validate_youtube_id($source)) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_source',
            'message' => 'Niepoprawny identyfikator filmu.',
        ]);
    }
    ensure_can_edit_source($ctx, $source);

    $secondsRaw = (string)($data['czas_sekundy'] ?? '');
    if (!preg_match('/^\d{1,6}$/', $secondsRaw)) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_time',
            'message' => 'Niepoprawny czas komentarza.',
        ]);
    }
    $seconds = (int)$secondsRaw;
    if ($seconds < 0 || $seconds > 86400) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_time_range',
            'message' => 'Czas komentarza jest poza zakresem.',
        ]);
    }

    $title = mb_substr(trim((string)($data['tytul'] ?? '')), 0, 160);
    $content = mb_substr(trim((string)($data['tresc'] ?? '')), 0, 2000);
    $variant = mb_substr(trim((string)($data['wariant'] ?? 'ogolny')) ?: 'ogolny', 0, 64);
    $author = mb_substr(trim((string)($data['autor'] ?? '')), 0, 80);
    $timeText = trim((string)($data['czas_tekst'] ?? ''));
    $timeText = $timeText === '' ? format_time_label($seconds) : mb_substr($timeText, 0, 16);

    if ($title === '' && $content === '') {
        json_response(400, [
            'ok' => false,
            'error' => 'empty_comment',
            'message' => 'Uzupełnij tytuł lub treść komentarza.',
        ]);
    }

    try {
        $videoStmt = $pdo->prepare('SELECT id FROM videos WHERE youtube_id = ? LIMIT 1');
        $videoStmt->execute([$source]);
        $video = $videoStmt->fetch(PDO::FETCH_ASSOC);
        if (!$video) {
            json_response(404, [
                'ok' => false,
                'error' => 'video_not_found',
                'message' => 'Nie znaleziono filmu dla podanego source.',
            ]);
        }
        $videoId = (int)$video['id'];

        $orderStmt = $pdo->prepare('SELECT COALESCE(MAX(kolejnosc), 0) + 1 AS next_order FROM komentarze_video WHERE video_id = ?');
        $orderStmt->execute([$videoId]);
        $nextOrder = (int)($orderStmt->fetchColumn() ?: 1);

        $uuid = bin2hex(random_bytes(16));
        $insertStmt = $pdo->prepare(
            'INSERT INTO komentarze_video
                (video_id, uuid, czas_sekundy, czas_tekst, tytul, tresc, wariant, kolejnosc, widoczny, autor, utworzono, zaktualizowano)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())'
        );
        $insertStmt->execute([
            $videoId,
            $uuid,
            $seconds,
            $timeText,
            $title,
            $content,
            $variant,
            $nextOrder,
            $author,
        ]);

        json_response(201, [
            'ok' => true,
            'comment' => [
                'id' => (int)$pdo->lastInsertId(),
                'uuid' => $uuid,
                'czas_sekundy' => $seconds,
                'czas_tekst' => $timeText,
                'tytul' => $title,
                'tresc' => $content,
                'wariant' => $variant,
                'kolejnosc' => $nextOrder,
                'widoczny' => 1,
                'autor' => $author,
            ],
        ]);
    } catch (Throwable $e) {
        json_response(500, [
            'ok' => false,
            'error' => 'save_failed',
            'message' => 'Nie udało się zapisać komentarza.',
        ]);
    }
}

if ($action === 'update_comment' && $method === 'POST') {
    $data = get_input_data();
    $source = trim((string)($data['source'] ?? ''));
    $commentId = (int)($data['comment_id'] ?? 0);
    if ($source === '' || !validate_youtube_id($source) || $commentId <= 0) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_input',
            'message' => 'Niepoprawne dane aktualizacji komentarza.',
        ]);
    }
    ensure_can_edit_source($ctx, $source);

    $secondsRaw = (string)($data['czas_sekundy'] ?? '');
    if (!preg_match('/^\d{1,6}$/', $secondsRaw)) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_time',
            'message' => 'Niepoprawny czas komentarza.',
        ]);
    }
    $seconds = (int)$secondsRaw;
    if ($seconds < 0 || $seconds > 86400) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_time_range',
            'message' => 'Czas komentarza jest poza zakresem.',
        ]);
    }

    $title = mb_substr(trim((string)($data['tytul'] ?? '')), 0, 160);
    $content = mb_substr(trim((string)($data['tresc'] ?? '')), 0, 2000);
    $variant = mb_substr(trim((string)($data['wariant'] ?? 'ogolny')) ?: 'ogolny', 0, 64);
    $author = mb_substr(trim((string)($data['autor'] ?? '')), 0, 80);
    $timeText = trim((string)($data['czas_tekst'] ?? ''));
    $timeText = $timeText === '' ? format_time_label($seconds) : mb_substr($timeText, 0, 16);

    if ($title === '' && $content === '') {
        json_response(400, [
            'ok' => false,
            'error' => 'empty_comment',
            'message' => 'Uzupełnij tytuł lub treść komentarza.',
        ]);
    }

    try {
        $videoStmt = $pdo->prepare('SELECT id FROM videos WHERE youtube_id = ? LIMIT 1');
        $videoStmt->execute([$source]);
        $video = $videoStmt->fetch(PDO::FETCH_ASSOC);
        if (!$video) {
            json_response(404, [
                'ok' => false,
                'error' => 'video_not_found',
                'message' => 'Nie znaleziono filmu dla podanego source.',
            ]);
        }
        $videoId = (int)$video['id'];

        $existsStmt = $pdo->prepare('SELECT id, kolejnosc FROM komentarze_video WHERE id = ? AND video_id = ? LIMIT 1');
        $existsStmt->execute([$commentId, $videoId]);
        $existing = $existsStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            json_response(404, [
                'ok' => false,
                'error' => 'comment_not_found',
                'message' => 'Nie znaleziono komentarza do aktualizacji.',
            ]);
        }

        $updateStmt = $pdo->prepare(
            'UPDATE komentarze_video
             SET czas_sekundy = ?, czas_tekst = ?, tytul = ?, tresc = ?, wariant = ?, autor = ?, zaktualizowano = NOW()
             WHERE id = ? AND video_id = ?'
        );
        $updateStmt->execute([
            $seconds,
            $timeText,
            $title,
            $content,
            $variant,
            $author,
            $commentId,
            $videoId,
        ]);

        json_response(200, [
            'ok' => true,
            'comment' => [
                'id' => $commentId,
                'czas_sekundy' => $seconds,
                'czas_tekst' => $timeText,
                'tytul' => $title,
                'tresc' => $content,
                'wariant' => $variant,
                'kolejnosc' => (int)$existing['kolejnosc'],
                'widoczny' => 1,
                'autor' => $author,
            ],
        ]);
    } catch (Throwable $e) {
        json_response(500, [
            'ok' => false,
            'error' => 'update_failed',
            'message' => 'Nie udało się zaktualizować komentarza.',
        ]);
    }
}

if ($action === 'delete_comment' && $method === 'POST') {
    $data = get_input_data();
    $source = trim((string)($data['source'] ?? ''));
    $commentId = (int)($data['comment_id'] ?? 0);
    if ($source === '' || !validate_youtube_id($source) || $commentId <= 0) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_input',
            'message' => 'Niepoprawne dane usuwania komentarza.',
        ]);
    }
    ensure_can_edit_source($ctx, $source);

    try {
        $videoStmt = $pdo->prepare('SELECT id FROM videos WHERE youtube_id = ? LIMIT 1');
        $videoStmt->execute([$source]);
        $video = $videoStmt->fetch(PDO::FETCH_ASSOC);
        if (!$video) {
            json_response(404, [
                'ok' => false,
                'error' => 'video_not_found',
                'message' => 'Nie znaleziono filmu dla podanego source.',
            ]);
        }
        $videoId = (int)$video['id'];

        $deleteStmt = $pdo->prepare('DELETE FROM komentarze_video WHERE id = ? AND video_id = ? LIMIT 1');
        $deleteStmt->execute([$commentId, $videoId]);
        if ($deleteStmt->rowCount() < 1) {
            json_response(404, [
                'ok' => false,
                'error' => 'comment_not_found',
                'message' => 'Nie znaleziono komentarza do usunięcia.',
            ]);
        }

        json_response(200, [
            'ok' => true,
            'deleted_id' => $commentId,
        ]);
    } catch (Throwable $e) {
        json_response(500, [
            'ok' => false,
            'error' => 'delete_failed',
            'message' => 'Nie udało się usunąć komentarza.',
        ]);
    }
}

json_response(405, [
    'ok' => false,
    'error' => 'method_not_allowed',
    'message' => 'Nieobsługiwana akcja lub metoda.',
]);
