<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/access_guard.php';
require_once __DIR__ . '/video_tokens_lib.php';
require_once __DIR__ . '/video_review_lib.php';

/**
 * @param array<string,mixed> $payload
 */
function json_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function validate_source_key(string $value): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{6,20}$/', $value);
}

function validate_youtube_id(string $value): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{6,20}$/', $value);
}

function validate_drive_file_id(string $value): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{20,120}$/', $value);
}

/**
 * @return string[]
 */
function app_role_list(string $raw): array
{
    $value = strtolower(trim($raw));
    if ($value === '') {
        return [];
    }
    $parts = preg_split('/[\s,;|]+/', $value) ?: [];
    $roles = [];
    foreach ($parts as $part) {
        $role = trim((string)$part);
        if ($role === '') continue;
        $roles[$role] = true;
    }
    return array_keys($roles);
}

function app_role_has(string $raw, string $role): bool
{
    $needle = strtolower(trim($role));
    if ($needle === '') return false;
    return in_array($needle, app_role_list($raw), true);
}

function build_drive_source_key(string $driveFileId): string
{
    return 'gd_' . substr(sha1($driveFileId), 0, 17);
}

/**
 * @return array{provider:string,provider_video_id:string,source_key:string,source_url:string}|null
 */
function parse_video_source(string $value): ?array
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (validate_youtube_id($value)) {
        return [
            'provider' => 'youtube',
            'provider_video_id' => $value,
            'source_key' => $value,
            'source_url' => 'https://www.youtube.com/watch?v=' . $value,
        ];
    }
    if (validate_drive_file_id($value)) {
        return [
            'provider' => 'gdrive',
            'provider_video_id' => $value,
            'source_key' => build_drive_source_key($value),
            'source_url' => 'https://drive.google.com/file/d/' . $value . '/view',
        ];
    }

    $parts = @parse_url($value);
    if (!is_array($parts)) {
        return null;
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    $path = trim((string)($parts['path'] ?? ''), '/');
    $query = (string)($parts['query'] ?? '');

    if ($host === 'youtu.be' || $host === 'www.youtu.be') {
        $id = $path === '' ? '' : explode('/', $path)[0];
        if (validate_youtube_id($id)) {
            return [
                'provider' => 'youtube',
                'provider_video_id' => $id,
                'source_key' => $id,
                'source_url' => 'https://www.youtube.com/watch?v=' . $id,
            ];
        }
        return null;
    }

    if (
        $host === 'youtube.com' ||
        $host === 'www.youtube.com' ||
        $host === 'm.youtube.com' ||
        $host === 'music.youtube.com'
    ) {
        parse_str($query, $queryParams);
        if (!empty($queryParams['v']) && validate_youtube_id((string)$queryParams['v'])) {
            $id = (string)$queryParams['v'];
            return [
                'provider' => 'youtube',
                'provider_video_id' => $id,
                'source_key' => $id,
                'source_url' => 'https://www.youtube.com/watch?v=' . $id,
            ];
        }
        $segments = $path === '' ? [] : explode('/', $path);
        if (count($segments) >= 2 && in_array($segments[0], ['embed', 'shorts', 'live', 'v'], true)) {
            $candidate = (string)$segments[1];
            if (validate_youtube_id($candidate)) {
                return [
                    'provider' => 'youtube',
                    'provider_video_id' => $candidate,
                    'source_key' => $candidate,
                    'source_url' => 'https://www.youtube.com/watch?v=' . $candidate,
                ];
            }
            return null;
        }
    }

    if (
        $host === 'drive.google.com' ||
        $host === 'www.drive.google.com' ||
        $host === 'docs.google.com'
    ) {
        parse_str($query, $queryParams);
        $driveId = '';
        if (!empty($queryParams['id'])) {
            $driveId = (string)$queryParams['id'];
        } else {
            $segments = $path === '' ? [] : explode('/', $path);
            $dIndex = array_search('d', $segments, true);
            if ($dIndex !== false && isset($segments[$dIndex + 1])) {
                $driveId = (string)$segments[$dIndex + 1];
            }
        }

        if (validate_drive_file_id($driveId)) {
            return [
                'provider' => 'gdrive',
                'provider_video_id' => $driveId,
                'source_key' => build_drive_source_key($driveId),
                'source_url' => 'https://drive.google.com/file/d/' . $driveId . '/view',
            ];
        }
    }

    return null;
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
        $rawRole = (string)($row['role'] ?? 'viewer');
        $mapped = 'user';
        if (app_role_has($rawRole, 'admin')) {
            $mapped = 'admin';
        } elseif (app_role_has($rawRole, 'editor')) {
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
    $allowedVideoIds = array_values(array_unique(array_filter($allowedVideoIds, fn($v) => validate_source_key((string)$v))));

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
    if (!validate_source_key($source)) {
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
    if (!validate_source_key($source)) {
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
        $sql = 'SELECT
                    v.id,
                    v.youtube_id,
                    v.provider,
                    v.provider_video_id,
                    v.source_url,
                    v.tytul,
                    v.slug,
                    v.miniaturka_url,
                    v.status,
                    v.publiczny,
                    v.owner_user_id,
                    v.assigned_trainer_user_id,
                    v.created_via_token_order_id,
                    v.utworzono,
                    v.zaktualizowano,
                    trainer.email AS assigned_trainer_username
                FROM videos v
                LEFT JOIN users trainer ON trainer.id = v.assigned_trainer_user_id';
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
            $allowed = array_values(array_unique(array_filter(array_map('strval', $allowed), 'validate_source_key')));
            if (count($allowed) === 0) {
                json_response(200, [
                    'ok' => true,
                    'videos' => [],
                    'edit' => false,
                    'access' => build_access_meta($ctx),
                ]);
            }
            $in = implode(',', array_fill(0, count($allowed), '?'));
            $where[] = 'v.youtube_id IN (' . $in . ')';
            $params = array_merge($params, $allowed);
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY v.zaktualizowano DESC, v.id DESC';

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
    $parsedSource = parse_video_source($rawUrl);

    if (!$parsedSource) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_video_source',
            'message' => 'Podaj poprawny link lub ID YouTube albo Google Drive.',
        ]);
    }

    $provider = (string)$parsedSource['provider'];
    $providerVideoId = (string)$parsedSource['provider_video_id'];
    $sourceKey = (string)$parsedSource['source_key'];
    $sourceUrl = (string)$parsedSource['source_url'];

    try {
        $selectStmt = $pdo->prepare(
            'SELECT id, youtube_id, provider, provider_video_id, source_url, tytul, slug, opis, miniaturka_url, status, dlugosc_sekundy, jezyk, publiczny, owner_user_id, assigned_trainer_user_id, created_via_token_order_id, utworzono, zaktualizowano
             FROM videos
             WHERE (provider = ? AND provider_video_id = ?) OR youtube_id = ?
             LIMIT 1'
        );
        $selectStmt->execute([$provider, $providerVideoId, $sourceKey]);
        $existingVideo = $selectStmt->fetch(PDO::FETCH_ASSOC);
        if ($existingVideo) {
            json_response(200, [
                'ok' => true,
                'created' => false,
                'video' => $existingVideo,
                'access' => build_access_meta($ctx),
            ]);
        }

        $title = $provider === 'gdrive'
            ? ('Google Drive video ' . mb_substr($providerVideoId, 0, 10))
            : ('YouTube video ' . $providerVideoId);
        $slug = $sourceKey;
        $thumbnail = $provider === 'youtube'
            ? ('https://i.ytimg.com/vi/' . rawurlencode($providerVideoId) . '/hqdefault.jpg')
            : '';
        $statusCandidates = ['active', 'opublikowany', 'published', 'aktywny', 'draft'];
        $inserted = false;

        foreach ($statusCandidates as $status) {
            try {
                $insertStmt = $pdo->prepare(
                    'INSERT INTO videos
                        (youtube_id, provider, provider_video_id, source_url, tytul, slug, opis, miniaturka_url, status, dlugosc_sekundy, jezyk, publiczny, utworzono, zaktualizowano)
                     VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                );
                $insertStmt->execute([
                    $sourceKey,
                    $provider,
                    $providerVideoId,
                    $sourceUrl,
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

        $selectStmt->execute([$provider, $providerVideoId, $sourceKey]);
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

if ($action === 'add_user_video_link' && $method === 'POST') {
    $data = get_input_data();
    $csrf = (string)($data['csrf_token'] ?? '');
    if (!csrf_check($csrf)) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_csrf',
            'message' => 'Nieprawidłowy token bezpieczeństwa.',
        ]);
    }

    $user = $ctx['user'] ?? [];
    $userId = (int)($user['user_id'] ?? 0);
    $role = (string)($user['role'] ?? '');
    if ($userId <= 0 || empty($user['logged_in'])) {
        json_response(401, [
            'ok' => false,
            'error' => 'not_logged_in',
            'message' => 'Musisz się zalogować.',
        ]);
    }
    if ($role !== 'user' && $role !== 'admin') {
        json_response(403, [
            'ok' => false,
            'error' => 'role_forbidden',
            'message' => 'Tylko użytkownik może dodać film przez żeton.',
        ]);
    }

    $rawUrl = trim((string)($data['youtube_url'] ?? $data['source'] ?? ''));
    $parsed = parse_video_source($rawUrl);
    if (!$parsed || (string)$parsed['provider'] !== 'youtube') {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_source',
            'message' => 'W tym trybie możesz dodać tylko poprawny link lub ID YouTube.',
        ]);
    }

    $selectedTrainerId = (int)($data['trainer_user_id'] ?? 0);
    $consumeTrainerChoice = $selectedTrainerId > 0;
    if ($selectedTrainerId > 0 && !vt_is_trainer_user($pdo, $selectedTrainerId)) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_trainer',
            'message' => 'Wybrany trener nie jest dostępny.',
        ]);
    }

    if ($selectedTrainerId <= 0) {
        try {
            $defStmt = $pdo->prepare(
                'SELECT trainer_user_id
                 FROM user_trainer_rel
                 WHERE user_id = ? AND is_default = 1
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $defStmt->execute([$userId]);
            $relTrainer = (int)($defStmt->fetchColumn() ?: 0);
            if ($relTrainer > 0 && vt_is_trainer_user($pdo, $relTrainer)) {
                $selectedTrainerId = $relTrainer;
            }
        } catch (Throwable $e) {
            // ignore, fallback below
        }
        if ($selectedTrainerId <= 0) {
            $selectedTrainerId = (int)(vt_pick_default_trainer($pdo) ?? 0);
        }
    }

    if ($selectedTrainerId <= 0) {
        json_response(400, [
            'ok' => false,
            'error' => 'trainer_missing',
            'message' => 'Brak dostępnego trenera do przypisania filmu.',
        ]);
    }

    $consume = vt_consume_upload_entitlement($pdo, $userId, $consumeTrainerChoice);
    if (!$consume['ok']) {
        json_response(402, [
            'ok' => false,
            'error' => 'insufficient_entitlements',
            'message' => $consumeTrainerChoice
                ? 'Brak żetonu pozwalającego wybrać trenera.'
                : 'Brak żetonów upload do dodania filmu.',
            'balance' => vt_get_user_balance($pdo, $userId),
        ]);
    }

    $provider = (string)$parsed['provider'];
    $providerVideoId = (string)$parsed['provider_video_id'];
    $sourceKey = (string)$parsed['source_key'];
    $sourceUrl = (string)$parsed['source_url'];
    $entitlementId = (int)($consume['entitlement_id'] ?? 0);
    $sourceOrderId = (int)($consume['source_order_id'] ?? 0);

    try {
        $selectStmt = $pdo->prepare(
            'SELECT id, youtube_id, owner_user_id
             FROM videos
             WHERE (provider = ? AND provider_video_id = ?) OR youtube_id = ?
             LIMIT 1'
        );
        $selectStmt->execute([$provider, $providerVideoId, $sourceKey]);
        $existing = $selectStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $owner = (int)($existing['owner_user_id'] ?? 0);
            if ($owner > 0 && $owner !== $userId && $role !== 'admin') {
                throw new RuntimeException('Film już należy do innego użytkownika.');
            }
            $videoId = (int)$existing['id'];
            $up = $pdo->prepare(
                'UPDATE videos
                 SET owner_user_id = COALESCE(owner_user_id, ?),
                     assigned_trainer_user_id = COALESCE(assigned_trainer_user_id, ?),
                     created_via_token_order_id = COALESCE(created_via_token_order_id, ?),
                     source_url = ?,
                     zaktualizowano = NOW()
                 WHERE id = ?'
            );
            $up->execute([$userId, $selectedTrainerId, $sourceOrderId > 0 ? $sourceOrderId : null, $sourceUrl, $videoId]);
        } else {
            $title = 'YouTube video ' . $providerVideoId;
            $slug = $sourceKey;
            $thumbnail = 'https://i.ytimg.com/vi/' . rawurlencode($providerVideoId) . '/hqdefault.jpg';
            $insertStmt = $pdo->prepare(
                'INSERT INTO videos
                    (youtube_id, provider, provider_video_id, source_url, tytul, slug, opis, miniaturka_url, status, dlugosc_sekundy, jezyk, publiczny, owner_user_id, assigned_trainer_user_id, created_via_token_order_id, utworzono, zaktualizowano)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $insertStmt->execute([
                $sourceKey,
                $provider,
                $providerVideoId,
                $sourceUrl,
                $title,
                $slug,
                '',
                $thumbnail,
                'active',
                0,
                'pl',
                1,
                $userId,
                $selectedTrainerId,
                $sourceOrderId > 0 ? $sourceOrderId : null,
            ]);
            $videoId = (int)$pdo->lastInsertId();
        }

        $assignOwner = $pdo->prepare(
            'INSERT INTO user_video_access (user_id, video_id, can_edit, created_at, updated_at)
             VALUES (?, ?, 0, NOW(), NOW())
             ON DUPLICATE KEY UPDATE updated_at = NOW()'
        );
        $assignOwner->execute([$userId, $videoId]);

        $assignTrainer = $pdo->prepare(
            'INSERT INTO user_video_access (user_id, video_id, can_edit, created_at, updated_at)
             VALUES (?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE can_edit = 1, updated_at = NOW()'
        );
        $assignTrainer->execute([$selectedTrainerId, $videoId]);

        $relUpsert = $pdo->prepare(
            'INSERT INTO user_trainer_rel (user_id, trainer_user_id, is_default, created_at, updated_at)
             VALUES (?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE updated_at = NOW()'
        );
        $relUpsert->execute([$userId, $selectedTrainerId]);

        json_response(201, [
            'ok' => true,
            'video' => [
                'id' => $videoId,
                'youtube_id' => $sourceKey,
                'source_url' => $sourceUrl,
                'assigned_trainer_user_id' => $selectedTrainerId,
            ],
            'balance' => vt_get_user_balance($pdo, $userId),
        ]);
    } catch (Throwable $e) {
        if ($entitlementId > 0) {
            try {
                $refundSql = 'UPDATE user_token_entitlements
                              SET remaining_upload_links = remaining_upload_links + 1, updated_at = NOW()';
                if ($consumeTrainerChoice) {
                    $refundSql .= ', remaining_trainer_choices = remaining_trainer_choices + 1';
                }
                $refundSql .= ' WHERE id = ?';
                $refund = $pdo->prepare($refundSql);
                $refund->execute([$entitlementId]);
            } catch (Throwable $rollbackErr) {
                // ignore
            }
        }

        json_response(500, [
            'ok' => false,
            'error' => 'add_user_video_link_failed',
            'message' => $e->getMessage() ?: 'Nie udało się dodać filmu.',
        ]);
    }
}

if ($action === 'update_user_video_title' && $method === 'POST') {
    $data = get_input_data();
    $csrf = (string)($data['csrf_token'] ?? '');
    if (!csrf_check($csrf)) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_csrf',
            'message' => 'Nieprawidłowy token bezpieczeństwa.',
        ]);
    }

    $source = trim((string)($data['source'] ?? ''));
    $title = mb_substr(trim((string)($data['title'] ?? '')), 0, 160);
    if (!validate_source_key($source)) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_source',
            'message' => 'Niepoprawny identyfikator filmu.',
        ]);
    }
    if ($title === '') {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_title',
            'message' => 'Tytuł nie może być pusty.',
        ]);
    }

    $user = $ctx['user'] ?? [];
    $userId = (int)($user['user_id'] ?? 0);
    $role = (string)($user['role'] ?? '');
    if ($userId <= 0 || empty($user['logged_in'])) {
        json_response(401, [
            'ok' => false,
            'error' => 'not_logged_in',
            'message' => 'Musisz się zalogować.',
        ]);
    }

    try {
        $stmt = $pdo->prepare('SELECT id, owner_user_id FROM videos WHERE youtube_id = ? LIMIT 1');
        $stmt->execute([$source]);
        $video = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$video) {
            json_response(404, [
                'ok' => false,
                'error' => 'video_not_found',
                'message' => 'Nie znaleziono filmu.',
            ]);
        }

        $ownerId = (int)($video['owner_user_id'] ?? 0);
        $isAdmin = ($role === 'admin');
        if (!$isAdmin && $ownerId !== $userId) {
            json_response(403, [
                'ok' => false,
                'error' => 'title_update_forbidden',
                'message' => 'Możesz edytować tylko swoje filmy.',
            ]);
        }

        $upd = $pdo->prepare('UPDATE videos SET tytul = ?, zaktualizowano = NOW() WHERE id = ? LIMIT 1');
        $upd->execute([$title, (int)$video['id']]);

        json_response(200, [
            'ok' => true,
            'source' => $source,
            'title' => $title,
        ]);
    } catch (Throwable $e) {
        json_response(500, [
            'ok' => false,
            'error' => 'title_update_failed',
            'message' => 'Nie udało się zapisać tytułu.',
        ]);
    }
}

if ($action === 'load_review_form' && $method === 'GET') {
    $source = trim((string)($_GET['source'] ?? ''));
    if ($source === '' || !validate_source_key($source)) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_source',
            'message' => 'Niepoprawny identyfikator filmu.',
        ]);
    }
    ensure_can_edit_source($ctx, $source);

    $userId = (int)($ctx['user']['user_id'] ?? 0);
    if ($userId <= 0 || empty($ctx['user']['logged_in'])) {
        json_response(401, [
            'ok' => false,
            'error' => 'not_logged_in',
            'message' => 'Musisz byc zalogowany, aby uzupelnic podsumowanie.',
        ]);
    }

    try {
        $video = vr_find_video_by_source($pdo, $source);
        if (!$video) {
            json_response(404, [
                'ok' => false,
                'error' => 'video_not_found',
                'message' => 'Nie znaleziono filmu.',
            ]);
        }

        $catalog = vr_catalog();
        $dict = vr_item_dict();
        $published = vr_load_latest_published($pdo, (int)$video['id']);
        $draft = vr_load_draft_for_user($pdo, (int)$video['id'], $userId);

        json_response(200, [
            'ok' => true,
            'definition' => $catalog,
            'review_summary_published' => $published ? vr_hydrate_summary($pdo, $published, $catalog, $dict) : null,
            'review_summary_draft' => $draft ? vr_hydrate_summary($pdo, $draft, $catalog, $dict) : null,
            'access' => build_access_meta($ctx, $source),
        ]);
    } catch (Throwable $e) {
        json_response(500, [
            'ok' => false,
            'error' => 'load_review_form_failed',
            'message' => 'Nie udalo sie pobrac formularza podsumowania.',
        ]);
    }
}

if ($action === 'save_review_draft' && $method === 'POST') {
    $data = get_input_data();
    $csrf = (string)($data['csrf_token'] ?? '');
    if (!csrf_check($csrf)) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_csrf',
            'message' => 'Nieprawidlowy token bezpieczenstwa.',
        ]);
    }

    $source = trim((string)($data['source'] ?? ''));
    if ($source === '' || !validate_source_key($source)) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_source',
            'message' => 'Niepoprawny identyfikator filmu.',
        ]);
    }
    ensure_can_edit_source($ctx, $source);

    $userId = (int)($ctx['user']['user_id'] ?? 0);
    if ($userId <= 0 || empty($ctx['user']['logged_in'])) {
        json_response(401, [
            'ok' => false,
            'error' => 'not_logged_in',
            'message' => 'Musisz byc zalogowany, aby zapisac podsumowanie.',
        ]);
    }

    $catalog = vr_catalog();
    $dict = vr_item_dict();
    $answers = vr_normalize_answers($data['answers'] ?? [], $dict);
    $summaryId = (int)($data['summary_id'] ?? 0);
    $overallNote = mb_substr(trim((string)($data['overall_note'] ?? '')), 0, 4000);

    try {
        $video = vr_find_video_by_source($pdo, $source);
        if (!$video) {
            json_response(404, [
                'ok' => false,
                'error' => 'video_not_found',
                'message' => 'Nie znaleziono filmu.',
            ]);
        }
        $videoId = (int)$video['id'];

        $pdo->beginTransaction();

        $summaryRow = null;
        if ($summaryId > 0) {
            $summaryStmt = $pdo->prepare(
                'SELECT id, video_id, reviewer_user_id, status, version_no, published_at, overall_note, total_score, max_score, created_at, updated_at, archived_at
                 FROM video_review_summaries
                 WHERE id = ? AND video_id = ? LIMIT 1'
            );
            $summaryStmt->execute([$summaryId, $videoId]);
            $row = $summaryStmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->rollBack();
                json_response(404, [
                    'ok' => false,
                    'error' => 'summary_not_found',
                    'message' => 'Nie znaleziono szkicu podsumowania.',
                ]);
            }
            if ((int)$row['reviewer_user_id'] !== $userId || (string)$row['status'] !== 'draft') {
                $pdo->rollBack();
                json_response(403, [
                    'ok' => false,
                    'error' => 'summary_forbidden',
                    'message' => 'Brak uprawnien do zapisu tego szkicu.',
                ]);
            }
            $summaryRow = vr_cast_summary_row($row);
        } else {
            $existingDraft = vr_load_draft_for_user($pdo, $videoId, $userId);
            if ($existingDraft) {
                $summaryRow = $existingDraft;
            } else {
                $nextVersion = vr_next_version_no($pdo, $videoId);
                $insertStmt = $pdo->prepare(
                    'INSERT INTO video_review_summaries
                        (video_id, reviewer_user_id, status, version_no, overall_note, total_score, max_score, created_at, updated_at)
                     VALUES
                        (?, ?, "draft", ?, ?, 0, 0, NOW(), NOW())'
                );
                $insertStmt->execute([$videoId, $userId, $nextVersion, $overallNote !== '' ? $overallNote : null]);
                $summaryRow = [
                    'id' => (int)$pdo->lastInsertId(),
                    'video_id' => $videoId,
                    'reviewer_user_id' => $userId,
                    'status' => 'draft',
                    'version_no' => $nextVersion,
                    'published_at' => null,
                    'overall_note' => $overallNote,
                    'total_score' => 0,
                    'max_score' => 0,
                    'created_at' => '',
                    'updated_at' => '',
                    'archived_at' => null,
                ];
            }
        }

        if (!$summaryRow) {
            $pdo->rollBack();
            json_response(500, [
                'ok' => false,
                'error' => 'summary_create_failed',
                'message' => 'Nie udalo sie utworzyc szkicu podsumowania.',
            ]);
        }

        $summaryId = (int)$summaryRow['id'];
        $updateStmt = $pdo->prepare(
            'UPDATE video_review_summaries
             SET overall_note = ?, updated_at = NOW()
             WHERE id = ? LIMIT 1'
        );
        $updateStmt->execute([$overallNote !== '' ? $overallNote : null, $summaryId]);
        vr_upsert_scores($pdo, $summaryId, $answers);

        $selectSavedStmt = $pdo->prepare(
            'SELECT id, video_id, reviewer_user_id, status, version_no, published_at, overall_note, total_score, max_score, created_at, updated_at, archived_at
             FROM video_review_summaries
             WHERE id = ? LIMIT 1'
        );
        $selectSavedStmt->execute([$summaryId]);
        $saved = $selectSavedStmt->fetch(PDO::FETCH_ASSOC);
        if (!$saved) {
            $pdo->rollBack();
            json_response(500, [
                'ok' => false,
                'error' => 'summary_reload_failed',
                'message' => 'Nie udalo sie odczytac zapisanego szkicu.',
            ]);
        }

        $pdo->commit();

        $draft = vr_hydrate_summary($pdo, vr_cast_summary_row($saved), $catalog, $dict);
        json_response(200, [
            'ok' => true,
            'review_summary_draft' => $draft,
            'definition' => $catalog,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(500, [
            'ok' => false,
            'error' => 'save_review_draft_failed',
            'message' => 'Nie udalo sie zapisac szkicu podsumowania.',
        ]);
    }
}

if ($action === 'publish_review' && $method === 'POST') {
    $data = get_input_data();
    $csrf = (string)($data['csrf_token'] ?? '');
    if (!csrf_check($csrf)) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_csrf',
            'message' => 'Nieprawidlowy token bezpieczenstwa.',
        ]);
    }

    $source = trim((string)($data['source'] ?? ''));
    $summaryId = (int)($data['summary_id'] ?? 0);
    if ($source === '' || !validate_source_key($source) || $summaryId <= 0) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_input',
            'message' => 'Niepoprawne dane publikacji.',
        ]);
    }
    ensure_can_edit_source($ctx, $source);

    $userId = (int)($ctx['user']['user_id'] ?? 0);
    if ($userId <= 0 || empty($ctx['user']['logged_in'])) {
        json_response(401, [
            'ok' => false,
            'error' => 'not_logged_in',
            'message' => 'Musisz byc zalogowany, aby opublikowac podsumowanie.',
        ]);
    }

    $catalog = vr_catalog();
    $dict = vr_item_dict();
    $totalItems = vr_total_items_count();

    try {
        $video = vr_find_video_by_source($pdo, $source);
        if (!$video) {
            json_response(404, [
                'ok' => false,
                'error' => 'video_not_found',
                'message' => 'Nie znaleziono filmu.',
            ]);
        }
        $videoId = (int)$video['id'];

        $summaryStmt = $pdo->prepare(
            'SELECT id, video_id, reviewer_user_id, status, version_no, published_at, overall_note, total_score, max_score, created_at, updated_at, archived_at
             FROM video_review_summaries
             WHERE id = ? AND video_id = ? LIMIT 1'
        );
        $summaryStmt->execute([$summaryId, $videoId]);
        $row = $summaryStmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(404, [
                'ok' => false,
                'error' => 'summary_not_found',
                'message' => 'Nie znaleziono szkicu podsumowania.',
            ]);
        }
        if ((int)$row['reviewer_user_id'] !== $userId || (string)$row['status'] !== 'draft') {
            json_response(403, [
                'ok' => false,
                'error' => 'summary_forbidden',
                'message' => 'Brak uprawnien do publikacji tego szkicu.',
            ]);
        }

        $summary = vr_cast_summary_row($row);
        $answers = vr_load_scores($pdo, $summary, $dict);
        if (count($answers) !== $totalItems) {
            json_response(400, [
                'ok' => false,
                'error' => 'review_incomplete',
                'message' => 'Uzupelnij wszystkie pytania przed publikacja.',
            ]);
        }

        $totalScore = 0;
        foreach ($answers as $answer) {
            $totalScore += (int)$answer['score'];
        }
        $maxScore = $totalItems * 3;

        $pdo->beginTransaction();
        $archiveStmt = $pdo->prepare(
            'UPDATE video_review_summaries
             SET status = "archived", archived_at = NOW(), updated_at = NOW()
             WHERE video_id = ? AND status = "published"'
        );
        $archiveStmt->execute([$videoId]);

        $publishStmt = $pdo->prepare(
            'UPDATE video_review_summaries
             SET status = "published", published_at = NOW(), archived_at = NULL, total_score = ?, max_score = ?, updated_at = NOW()
             WHERE id = ? AND status = "draft" LIMIT 1'
        );
        $publishStmt->execute([$totalScore, $maxScore, $summaryId]);
        if ($publishStmt->rowCount() < 1) {
            $pdo->rollBack();
            json_response(409, [
                'ok' => false,
                'error' => 'publish_conflict',
                'message' => 'Szkic nie jest juz dostepny do publikacji.',
            ]);
        }

        $published = vr_load_latest_published($pdo, $videoId);
        if (!$published) {
            $pdo->rollBack();
            json_response(500, [
                'ok' => false,
                'error' => 'publish_reload_failed',
                'message' => 'Nie udalo sie odczytac opublikowanego podsumowania.',
            ]);
        }

        $pdo->commit();

        json_response(200, [
            'ok' => true,
            'review_summary_published' => vr_hydrate_summary($pdo, $published, $catalog, $dict),
            'definition' => $catalog,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(500, [
            'ok' => false,
            'error' => 'publish_review_failed',
            'message' => 'Nie udalo sie opublikowac podsumowania.',
        ]);
    }
}

if ($action === 'load' && $method === 'GET') {
    $source = trim((string)($_GET['source'] ?? ''));
    if ($source === '' || !validate_source_key($source)) {
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
            'SELECT id, youtube_id, provider, provider_video_id, source_url, tytul, slug, opis, miniaturka_url, status, dlugosc_sekundy, jezyk, publiczny, owner_user_id, assigned_trainer_user_id, created_via_token_order_id, utworzono, zaktualizowano
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

        $catalog = vr_catalog();
        $dict = vr_item_dict();
        $publishedReview = null;
        $publishedReviews = [];
        $draftReview = null;
        try {
            $publishedRows = vr_load_published_summaries($pdo, (int)$video['id']);
            foreach ($publishedRows as $publishedRow) {
                $publishedReviews[] = vr_hydrate_summary($pdo, $publishedRow, $catalog, $dict);
            }
            $publishedReview = $publishedReviews[0] ?? null;
            $reviewerUserId = (int)($ctx['user']['user_id'] ?? 0);
            if (can_edit_source($ctx, $source) && $reviewerUserId > 0) {
                $draftReview = vr_load_draft_for_user($pdo, (int)$video['id'], $reviewerUserId);
            }
        } catch (Throwable $reviewErr) {
            $publishedReview = null;
            $publishedReviews = [];
            $draftReview = null;
        }

        json_response(200, [
            'ok' => true,
            'video' => $video,
            'comments' => $comments,
            'review_summary_published' => $publishedReview,
            'review_summaries_published' => $publishedReviews,
            'review_summary_draft' => $draftReview ? vr_hydrate_summary($pdo, $draftReview, $catalog, $dict) : null,
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
    if ($source === '' || !validate_source_key($source)) {
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
    if ($source === '' || !validate_source_key($source) || $commentId <= 0) {
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
    if ($source === '' || !validate_source_key($source) || $commentId <= 0) {
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
