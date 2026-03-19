<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require_login();
require_admin();
$videosAdminBuild = 'videos.php build 2026-03-16-gdrive-1';
require_once __DIR__ . '/../backend/video_mail_lib.php';
header('X-Admin-Videos-Build: ' . $videosAdminBuild);

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
        return '';
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

/**
 * @return array<int,array{source:string,title:string,line:string}>
 */
function parse_bulk_video_entries(string $input): array
{
    $raw = trim($input);
    if ($raw === '') {
        return [];
    }

    $lines = preg_split('/\R+/', $raw) ?: [];
    $items = [];

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }

        $title = '';
        $source = '';

        if (str_contains($line, '->')) {
            [$left, $right] = array_pad(explode('->', $line, 2), 2, '');
            $left = trim((string)$left);
            $right = trim((string)$right);
            $left = preg_replace('/^\s*\d+[.)]?\s*/u', '', $left) ?? $left;
            $title = trim($left);
            $source = trim($right);
        } else {
            $source = $line;
        }

        if ($source === '') {
            continue;
        }

        $items[] = [
            'source' => $source,
            'title' => $title,
            'line' => $line,
        ];
    }

    return $items;
}

/**
 * @param mixed $raw
 * @return array<int,int>
 */
function normalize_video_ids($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));
    return $ids;
}

/**
 * @param array<string,mixed> $video
 */
function resolve_video_workflow_status(array $video): string
{
    $commentsCount = (int)($video['comments_count'] ?? 0);
    $summariesCount = (int)($video['summaries_count'] ?? 0);
    $orderPaid = (int)($video['token_order_paid'] ?? 0) === 1;

    if ($summariesCount > 0) {
        return 'zamkniete';
    }
    if ($commentsCount > 0) {
        return 'w trakcie';
    }
    if ($orderPaid) {
        return 'otwarte';
    }
    $fallbackStatus = trim((string)($video['status'] ?? ''));
    if ($fallbackStatus === '' || $fallbackStatus === '-') {
        return 'uwaga';
    }

    $normalized = mb_strtolower($fallbackStatus, 'UTF-8');
    $knownStatuses = ['zamkniete', 'otwarte', 'w trakcie'];
    if (in_array($normalized, $knownStatuses, true)) {
        return $normalized;
    }

    return 'uwaga';
}

function video_workflow_status_class(string $status): string
{
    if ($status === 'zamkniete') {
        return 'status-badge status-badge--closed';
    }
    if ($status === 'otwarte') {
        return 'status-badge status-badge--open';
    }
    if ($status === 'w trakcie') {
        return 'status-badge status-badge--progress';
    }
    return 'status-badge status-badge--warning';
}

function admin_video_user_option_label(array $user): string
{
    $videoScopeLabel = ((string)($user['role'] ?? '') === 'editor')
        ? (((int)($user['has_global_video_access'] ?? 0) === 1) ? 'all-video' : 'assigned-video')
        : 'client';
    return (string)$user['email'] . ' [' . (string)$user['role'] . '; ' . $videoScopeLabel . ']';
}

function admin_video_option_label(array $video): string
{
    return (string)$video['provider'] . ' : ' . (string)$video['youtube_id'] . ' - ' . (string)$video['tytul'];
}

function admin_video_owner_label(array $video): string
{
    $email = trim((string)($video['owner_email'] ?? ''));
    return $email !== '' ? $email : 'brak wlasciciela';
}

/**
 * @return array{assignment_saved:bool,owner_updated:bool,mail_sent:bool}
 */
function admin_assign_video_to_user(PDO $pdo, int $videoId, int $userId, int $canEdit, bool $setOwner): array
{
    $result = [
        'assignment_saved' => false,
        'owner_updated' => false,
        'mail_sent' => false,
    ];

    $pdo->beginTransaction();
    try {
        $videoStmt = $pdo->prepare(
            'SELECT v.id, v.youtube_id, v.tytul, v.source_url, v.owner_user_id, v.assigned_trainer_user_id
             FROM videos v
             WHERE v.id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $videoStmt->execute([$videoId]);
        $video = $videoStmt->fetch(PDO::FETCH_ASSOC);
        if (!$video) {
            throw new RuntimeException('Nie znaleziono filmu.');
        }

        $userStmt = $pdo->prepare(
            'SELECT id, email
             FROM users
             WHERE id = ? AND is_active = 1
             LIMIT 1'
        );
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new RuntimeException('Nie znaleziono aktywnego uzytkownika.');
        }

        $assignStmt = $pdo->prepare(
            'INSERT INTO user_video_access (user_id, video_id, can_edit, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE can_edit = VALUES(can_edit), updated_at = NOW()'
        );
        $assignStmt->execute([$userId, $videoId, $canEdit]);
        $result['assignment_saved'] = true;

        if ($setOwner) {
            $ownerStmt = $pdo->prepare(
                'UPDATE videos
                 SET owner_user_id = ?, zaktualizowano = NOW()
                 WHERE id = ?'
            );
            $ownerStmt->execute([$userId, $videoId]);
            $result['owner_updated'] = true;
        }

        $pdo->commit();

        if ($setOwner) {
            try {
                $trainerEmail = '';
                $assignedTrainerId = (int)($video['assigned_trainer_user_id'] ?? 0);
                if ($assignedTrainerId > 0) {
                    $trainerStmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
                    $trainerStmt->execute([$assignedTrainerId]);
                    $trainerEmail = trim((string)($trainerStmt->fetchColumn() ?: ''));
                }

                video_mail_send_touchpoint($pdo, 'video.upload.confirmation', (string)$user['email'], [
                    'video_title' => (string)($video['tytul'] ?? ''),
                    'video_url' => (string)($video['source_url'] ?? ''),
                    'trainer_name' => $trainerEmail !== '' ? $trainerEmail : 'brak przypisanego trenera',
                ]);
                $result['mail_sent'] = true;
            } catch (Throwable $mailErr) {
                // ignore mail errors
            }
        }

        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
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
            $entries = parse_bulk_video_entries($sourceInput);
            if (!$entries) {
                $error = 'Wklej przynajmniej jeden link lub ID.';
            } else {
                $addedCount = 0;
                $existingCount = 0;
                $invalidLines = [];
                $saveErrors = [];

                foreach ($entries as $entry) {
                    $parsedSource = parse_video_source((string)$entry['source']);
                    if (!$parsedSource) {
                        $invalidLines[] = (string)$entry['line'];
                        continue;
                    }

                    $provider = (string)$parsedSource['provider'];
                    $providerVideoId = (string)$parsedSource['provider_video_id'];
                    $sourceKey = (string)$parsedSource['source_key'];
                    $sourceUrl = (string)$parsedSource['source_url'];

                    try {
                        $selectStmt = $pdo->prepare(
                            'SELECT id, youtube_id, provider, provider_video_id FROM videos
                             WHERE (provider = ? AND provider_video_id = ?) OR youtube_id = ?
                             LIMIT 1'
                        );
                        $selectStmt->execute([$provider, $providerVideoId, $sourceKey]);
                        $existing = $selectStmt->fetch(PDO::FETCH_ASSOC);

                        if ($existing) {
                            $existingCount += 1;
                            continue;
                        }

                        $candidateTitle = trim((string)$entry['title']);
                        $title = $candidateTitle !== ''
                            ? mb_substr($candidateTitle, 0, 255)
                            : ($provider === 'gdrive'
                                ? ('Google Drive video ' . mb_substr($providerVideoId, 0, 10))
                                : ('YouTube video ' . $providerVideoId));

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
                            $saveErrors[] = (string)$entry['line'];
                            continue;
                        }
                        $addedCount += 1;
                    } catch (Throwable $e) {
                        $saveErrors[] = (string)$entry['line'];
                    }
                }

                $parts = [];
                if ($addedCount > 0) $parts[] = 'Dodano: ' . $addedCount . '.';
                if ($existingCount > 0) $parts[] = 'Juz istnialo: ' . $existingCount . '.';
                if ($invalidLines) $parts[] = 'Niepoprawne linie: ' . count($invalidLines) . '.';
                if ($saveErrors) $parts[] = 'Bledy zapisu: ' . count($saveErrors) . '.';

                if ($addedCount > 0 || $existingCount > 0) {
                    $success = implode(' ', $parts);
                } else {
                    $error = $parts ? implode(' ', $parts) : 'Nie udalo sie przetworzyc danych.';
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
        } elseif ($action === 'bulk_delete') {
            $videoIds = normalize_video_ids($_POST['video_ids'] ?? []);
            if (!$videoIds) {
                $error = 'Zaznacz przynajmniej jeden film do usuniecia.';
            } else {
                try {
                    $placeholders = implode(',', array_fill(0, count($videoIds), '?'));
                    $pdo->beginTransaction();
                    $pdo->prepare('DELETE FROM user_video_access WHERE video_id IN (' . $placeholders . ')')->execute($videoIds);
                    $pdo->prepare('DELETE FROM komentarze_video WHERE video_id IN (' . $placeholders . ')')->execute($videoIds);
                    $deleteStmt = $pdo->prepare('DELETE FROM videos WHERE id IN (' . $placeholders . ')');
                    $deleteStmt->execute($videoIds);
                    $deleted = (int)$deleteStmt->rowCount();
                    $pdo->commit();
                    $success = 'Usunieto filmow: ' . $deleted . '.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Blad usuwania masowego: ' . mb_substr($e->getMessage(), 0, 220);
                }
            }
        } elseif ($action === 'assign_user_to_video') {
            $videoId = (int)($_POST['video_id'] ?? 0);
            $userId = (int)($_POST['user_id'] ?? 0);
            $canEdit = ((string)($_POST['can_edit'] ?? '0') === '1') ? 1 : 0;
            $setOwner = ((string)($_POST['set_as_owner'] ?? '0') === '1');
            if ($videoId <= 0 || $userId <= 0) {
                $error = 'Niepoprawne dane przypisania.';
            } else {
                try {
                    $result = admin_assign_video_to_user($pdo, $videoId, $userId, $canEdit, $setOwner);
                    if ($setOwner) {
                        $success = $result['mail_sent']
                            ? 'Przypisanie zapisane. Uzytkownik zostal ustawiony jako wlasciciel i dostal mail startowy workflow.'
                            : 'Przypisanie zapisane. Uzytkownik zostal ustawiony jako wlasciciel.';
                    } else {
                        $success = 'Przypisanie zapisane.';
                    }
                } catch (Throwable $e) {
                    $error = 'Blad przypisania: ' . mb_substr($e->getMessage(), 0, 220);
                }
            }
        } elseif ($action === 'bulk_assign_user_to_video') {
            $videoIds = normalize_video_ids($_POST['video_ids'] ?? []);
            $userId = (int)($_POST['bulk_user_id'] ?? 0);
            $canEdit = ((string)($_POST['bulk_can_edit'] ?? '0') === '1') ? 1 : 0;
            if (!$videoIds || $userId <= 0) {
                $error = 'Zaznacz filmy i wybierz uzytkownika.';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO user_video_access (user_id, video_id, can_edit, created_at, updated_at)
                         VALUES (?, ?, ?, NOW(), NOW())
                         ON DUPLICATE KEY UPDATE can_edit = VALUES(can_edit), updated_at = NOW()'
                    );
                    $assigned = 0;
                    foreach ($videoIds as $videoId) {
                        $stmt->execute([$userId, $videoId, $canEdit]);
                        $assigned += 1;
                    }
                    $success = 'Przypisano filmow: ' . $assigned . '.';
                } catch (Throwable $e) {
                    $error = 'Blad przypisania masowego: ' . mb_substr($e->getMessage(), 0, 220);
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
        } elseif ($action === 'update_video_title') {
            $videoId = (int)($_POST['video_id'] ?? 0);
            $title = mb_substr(trim((string)($_POST['video_title'] ?? '')), 0, 255);
            if ($videoId <= 0) {
                $error = 'Niepoprawne ID filmu do aktualizacji.';
            } elseif ($title === '') {
                $error = 'Tytul filmu nie moze byc pusty.';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        'SELECT v.id, v.youtube_id, v.tytul, v.source_url, owner.email AS owner_email
                         FROM videos v
                         LEFT JOIN users owner ON owner.id = v.owner_user_id
                         WHERE v.id = ? LIMIT 1'
                    );
                    $stmt->execute([$videoId]);
                    $video = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$video) {
                        $error = 'Nie znaleziono filmu do aktualizacji.';
                    } else {
                        $oldTitle = trim((string)($video['tytul'] ?? ''));
                        $videoYoutubeUrl = trim((string)($video['source_url'] ?? ''));
                        $videoPlatformUrl = function_exists('video_mail_absolute_url')
                            ? video_mail_absolute_url('/video/play.php?source=' . rawurlencode((string)($video['youtube_id'] ?? '')))
                            : '/video/play.php?source=' . rawurlencode((string)($video['youtube_id'] ?? ''));
                        $upd = $pdo->prepare('UPDATE videos SET tytul = ?, zaktualizowano = NOW() WHERE id = ? LIMIT 1');
                        $upd->execute([$title, $videoId]);
                        if ($oldTitle !== $title) {
                            try {
                                $ownerEmail = trim((string)($video['owner_email'] ?? ''));
                                if ($ownerEmail !== '') {
                                    video_mail_send_touchpoint($pdo, 'video.title.changed', $ownerEmail, [
                                        'video_title_old' => $oldTitle !== '' ? $oldTitle : (string)($video['youtube_id'] ?? $videoId),
                                        'video_title_new' => $title,
                                        'video_youtube_url' => $videoYoutubeUrl !== '' ? $videoYoutubeUrl : $videoPlatformUrl,
                                        'video_platform_url' => $videoPlatformUrl,
                                    ]);
                                }
                            } catch (Throwable $mailErr) {
                                // ignore mail errors
                            }
                        }
                        $success = $upd->rowCount() > 0 ? 'Tytul filmu zaktualizowany.' : 'Brak zmian tytulu.';
                    }
                } catch (Throwable $e) {
                    $error = 'Blad aktualizacji tytulu: ' . mb_substr($e->getMessage(), 0, 220);
                }
            }
        } elseif ($action === 'update_video_owner') {
            $videoId = (int)($_POST['video_id'] ?? 0);
            $ownerUserIdRaw = trim((string)($_POST['owner_user_id'] ?? ''));
            $ownerUserId = $ownerUserIdRaw === '' ? 0 : (int)$ownerUserIdRaw;
            if ($videoId <= 0) {
                $error = 'Niepoprawne ID filmu do aktualizacji wlasciciela.';
            } elseif ($ownerUserIdRaw !== '' && $ownerUserId <= 0) {
                $error = 'Niepoprawny identyfikator wlasciciela.';
            } else {
                try {
                    $videoStmt = $pdo->prepare('SELECT id FROM videos WHERE id = ? LIMIT 1');
                    $videoStmt->execute([$videoId]);
                    if (!$videoStmt->fetch(PDO::FETCH_ASSOC)) {
                        $error = 'Nie znaleziono filmu do aktualizacji wlasciciela.';
                    } else {
                        $ownerValue = null;
                        if ($ownerUserId > 0) {
                            $userStmt = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
                            $userStmt->execute([$ownerUserId]);
                            if (!$userStmt->fetch(PDO::FETCH_ASSOC)) {
                                $error = 'Nie znaleziono wybranego uzytkownika.';
                            } else {
                                $ownerValue = $ownerUserId;
                            }
                        }
                        if ($error === '') {
                            $updateStmt = $pdo->prepare(
                                'UPDATE videos
                                 SET owner_user_id = ?, zaktualizowano = NOW()
                                 WHERE id = ? LIMIT 1'
                            );
                            $updateStmt->execute([$ownerValue, $videoId]);
                            $success = $ownerValue !== null
                                ? 'Wlasciciel filmu zaktualizowany.'
                                : 'Wlasciciel filmu usuniety.';
                        }
                    }
                } catch (Throwable $e) {
                    $error = 'Blad aktualizacji wlasciciela: ' . mb_substr($e->getMessage(), 0, 220);
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
        'SELECT
            v.id,
            v.youtube_id,
            v.provider,
            v.provider_video_id,
            v.source_url,
            v.tytul,
            owner.email AS owner_email,
            v.status,
            v.publiczny,
            v.created_via_token_order_id,
            v.zaktualizowano,
            CASE
                WHEN o.status = "paid" THEN 1
                ELSE 0
            END AS token_order_paid,
            (
                SELECT COUNT(*)
                FROM komentarze_video kv
                WHERE kv.video_id = v.id
                  AND kv.widoczny = 1
            ) AS comments_count,
            (
                SELECT COUNT(*)
                FROM video_review_summaries vrs
                WHERE vrs.video_id = v.id
                  AND vrs.status IN ("published", "archived")
            ) AS summaries_count,
            (
                SELECT COUNT(DISTINCT vrs2.reviewer_user_id)
                FROM video_review_summaries vrs2
                WHERE vrs2.video_id = v.id
            ) AS trainers_count
         FROM videos v
         LEFT JOIN users owner ON owner.id = v.owner_user_id
         LEFT JOIN token_orders o ON o.id = v.created_via_token_order_id
         ORDER BY v.zaktualizowano DESC, v.id DESC
         LIMIT 200'
    );
    $videos = $videosStmt ? ($videosStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    // no-op
}

try {
    $usersStmt = $pdo->query(
        "SELECT id, email, role, has_global_video_access, is_active
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
                v.youtube_id, v.provider, v.provider_video_id, v.tytul
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
    input, select, textarea { width:100%; border:1px solid #d1d5db; border-radius:10px; padding:10px 12px; font:inherit; }
    textarea { min-height: 130px; resize: vertical; }
    button, a.btn { display:inline-block; background:#040327; color:#fff; border:0; border-radius:10px; padding:10px 14px; text-decoration:none; font-weight:600; cursor:pointer; }
    a.btn.secondary { background:#fff; color:#111827; border:1px solid #d1d5db; }
    .ok { color:#166534; font-weight:600; }
    .err { color:#991b1b; font-weight:600; }
    table { width:100%; border-collapse:collapse; font-size:14px; }
    th, td { text-align:left; border-bottom:1px solid #e5e7eb; padding:8px 6px; vertical-align:top; }
    code { background:#f3f4f6; padding:2px 6px; border-radius:6px; }
    .inline { display:inline-flex; gap:8px; align-items:center; }
    .inline input[type="text"] { min-width:260px; padding:8px 10px; }
    .bulk-tools { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px; }
    .bulk-tools button { background:#fff; color:#111827; border:1px solid #d1d5db; }
    .build { margin:0; font-size:12px; opacity:.8; }
    .video-title-edit { display:flex; align-items:center; gap:8px; }
    .video-title-button { display:inline-flex; align-items:center; gap:8px; background:transparent; border:0; padding:0; color:#111827; font:inherit; cursor:pointer; text-align:left; }
    .video-title-button[disabled] { cursor:default; opacity:.95; }
    .video-title-icon { font-size:14px; opacity:.7; }
    .video-title-input { display:none; min-width:260px; max-width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font:inherit; }
    .video-title-edit.is-editing .video-title-button { display:none; }
    .video-title-edit.is-editing .video-title-input { display:inline-block; }
    .video-owner-edit { display:flex; align-items:center; gap:8px; }
    .video-owner-button { display:inline-flex; align-items:center; gap:8px; background:transparent; border:0; padding:0; color:#111827; font:inherit; cursor:pointer; text-align:left; }
    .video-owner-button[disabled] { cursor:default; opacity:.95; }
    .video-owner-icon { font-size:14px; opacity:.7; }
    .video-owner-select { display:none; min-width:280px; max-width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font:inherit; }
    .video-owner-edit.is-editing .video-owner-button { display:none; }
    .video-owner-edit.is-editing .video-owner-select { display:inline-block; }
    .searchable-select { display:grid; gap:8px; }
    .searchable-select-meta { color:#6b7280; font-size:12px; min-height:18px; }
    .status-badge { display:inline-flex; align-items:center; border-radius:999px; padding:4px 10px; font-size:12px; font-weight:700; letter-spacing:.01em; text-transform:uppercase; }
    .status-badge--closed { background:#dcfce7; color:#166534; }
    .status-badge--open { background:#fee2e2; color:#991b1b; }
    .status-badge--progress { background:#fef3c7; color:#92400e; }
    .status-badge--warning { background:#fde68a; color:#854d0e; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
</head>
<body>
  <!-- <?php echo h($videosAdminBuild); ?> -->
  <header>
    <div>
      <strong>Wideo - administracja</strong>
      <p class="build"><?php echo h($videosAdminBuild); ?></p>
    </div>
    <div><a class="btn secondary" href="index.php">Powrot do panelu</a></div>
  </header>
  <main>
    <section class="card">
      <h2 style="margin-top:0;">Filmy w bazie</h2>
      <?php if (!$videos): ?>
        <p>Brak filmow.</p>
      <?php else: ?>
        <div class="bulk-tools">
          <button id="bulk-select-all-btn" type="button">Zaznacz wszystko</button>
          <button id="bulk-select-none-btn" type="button">Odznacz wszystko</button>
        </div>
        <form id="bulk-videos-form" method="post" action="videos.php" class="grid" style="margin-bottom:12px;">
          <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
          <label class="row">
            <span>Uzytkownik (dla przypisania)</span>
            <div class="searchable-select" data-searchable-select-wrapper>
              <input type="search" placeholder="Szukaj po e-mailu, roli, zakresie..." data-searchable-select-input>
              <select name="bulk_user_id" data-searchable-select data-empty-label="Brak pasujacych uzytkownikow">
                <option value="">Wybierz uzytkownika...</option>
                <?php foreach ($users as $user): ?>
                  <option value="<?php echo h((string)$user['id']); ?>"><?php echo h(admin_video_user_option_label($user)); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="searchable-select-meta" data-searchable-select-meta></div>
            </div>
          </label>
          <label class="row">
            <span>Tryb przypisania</span>
            <select name="bulk_can_edit">
              <option value="0">view</option>
              <option value="1">edit</option>
            </select>
          </label>
          <div class="row" style="align-self:end;">
            <button type="submit" name="action" value="bulk_assign_user_to_video">Dodaj zaznaczone do uzytkownika</button>
          </div>
          <div class="row" style="align-self:end;">
            <button type="submit" name="action" value="bulk_delete" style="background:#9f1239;">Usun zaznaczone filmy</button>
          </div>
        </form>

        <table>
          <thead>
            <tr>
              <th><input id="bulk-select-master" type="checkbox" aria-label="Zaznacz wszystkie filmy"></th>
              <th>ID</th>
              <th>Source key</th>
              <th>Provider</th>
              <th>Provider ID</th>
              <th>Tytul</th>
              <th>Wlasciciel</th>
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
                <td>
                  <input form="bulk-videos-form" class="bulk-video-checkbox" type="checkbox" name="video_ids[]" value="<?php echo h((string)$video['id']); ?>" aria-label="Zaznacz film <?php echo h((string)$video['id']); ?>">
                </td>
                <td><?php echo h((string)$video['id']); ?></td>
                <td><code><?php echo h((string)$video['youtube_id']); ?></code></td>
                <td><?php echo h((string)$video['provider']); ?></td>
                <td><code><?php echo h((string)$video['provider_video_id']); ?></code></td>
                <td>
                  <form method="post" action="videos.php" class="inline video-title-form" data-video-title-form>
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="update_video_title">
                    <input type="hidden" name="video_id" value="<?php echo h((string)$video['id']); ?>">
                    <div class="video-title-edit" data-video-title-edit>
                      <button type="button" class="video-title-button" data-video-title-trigger aria-label="Edytuj tytuł filmu">
                        <span class="video-title-text" data-video-title-text><?php echo h((string)$video['tytul']); ?></span>
                        <span class="video-title-icon" aria-hidden="true">&#9998;</span>
                      </button>
                      <input
                        type="text"
                        class="video-title-input"
                        name="video_title"
                        maxlength="255"
                        value="<?php echo h((string)$video['tytul']); ?>"
                        required
                        data-video-title-input
                      >
                    </div>
                  </form>
                </td>
                <td>
                  <form method="post" action="videos.php" class="inline video-owner-form" data-video-owner-form>
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="update_video_owner">
                    <input type="hidden" name="video_id" value="<?php echo h((string)$video['id']); ?>">
                    <div class="video-owner-edit" data-video-owner-edit>
                      <button type="button" class="video-owner-button" data-video-owner-trigger aria-label="Edytuj wlasciciela filmu">
                        <span class="video-owner-text" data-video-owner-text><?php echo h(admin_video_owner_label($video)); ?></span>
                        <span class="video-owner-icon" aria-hidden="true">&#9998;</span>
                      </button>
                      <select
                        class="video-owner-select"
                        name="owner_user_id"
                        data-video-owner-select
                      >
                        <option value=""><?php echo h('brak wlasciciela'); ?></option>
                        <?php foreach ($users as $user): ?>
                          <option value="<?php echo h((string)$user['id']); ?>" <?php echo ((string)($video['owner_email'] ?? '') === (string)($user['email'] ?? '')) ? 'selected' : ''; ?>>
                            <?php echo h((string)$user['email']); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </form>
                </td>
                <td>
                  <?php $workflowStatus = resolve_video_workflow_status($video); ?>
                  <div><span class="<?php echo h(video_workflow_status_class($workflowStatus)); ?>"><?php echo h($workflowStatus); ?></span></div>
                  <small>Komentarze: <?php echo (int)($video['comments_count'] ?? 0); ?></small><br>
                  <small>Podsumowania: <?php echo (int)($video['summaries_count'] ?? 0); ?></small><br>
                  <small>Trenerzy: <?php echo (int)($video['trainers_count'] ?? 0); ?></small>
                </td>
                <td><?php echo ((int)$video['publiczny'] === 1) ? 'tak' : 'nie'; ?></td>
                <td><?php echo h((string)$video['zaktualizowano']); ?></td>
                <td>
                  <?php
                    $previewUrl = '/video/play.php?source=' . urlencode((string)$video['youtube_id']);
                    if ((string)($video['provider'] ?? '') === 'gdrive' && trim((string)($video['provider_video_id'] ?? '')) !== '') {
                        $previewUrl .= '&gdrive_id=' . urlencode((string)$video['provider_video_id']);
                    }
                  ?>
                  <a href="<?php echo h($previewUrl); ?>" target="_blank" rel="noopener">otworz</a>
                </td>
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

    <section class="card">
      <h1 style="margin-top:0;">Dodaj film (YouTube / Google Drive)</h1>
      <p>Wklej pojedynczy URL/ID albo liste linii, np. "Imie Nazwisko -&gt; URL".</p>
      <?php if ($error !== ''): ?><p class="err"><?php echo h($error); ?></p><?php endif; ?>
      <?php if ($success !== ''): ?><p class="ok"><?php echo h($success); ?></p><?php endif; ?>
      <form method="post" action="videos.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="add">
        <div class="row">
          <label for="youtube_source">Link lub ID</label>
          <textarea id="youtube_source" name="youtube_source" required placeholder="https://www.youtube.com/watch?v=...&#10;1. Jan Kowalski -> https://youtu.be/ABC...&#10;2. Anna Nowak -> https://drive.google.com/file/d/XYZ.../view"><?php echo h($sourceInput); ?></textarea>
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
          <div class="searchable-select" data-searchable-select-wrapper>
            <input type="search" placeholder="Szukaj po source, providerze, tytule..." data-searchable-select-input>
            <select name="video_id" required data-searchable-select data-empty-label="Brak pasujacych video">
              <option value="">Wybierz video...</option>
              <?php foreach ($videos as $video): ?>
                <option value="<?php echo h((string)$video['id']); ?>"><?php echo h(admin_video_option_label($video)); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="searchable-select-meta" data-searchable-select-meta></div>
          </div>
        </label>
        <label class="row">
          <span>Uzytkownik (trener/user)</span>
          <div class="searchable-select" data-searchable-select-wrapper>
            <input type="search" placeholder="Szukaj po e-mailu, roli, zakresie..." data-searchable-select-input>
            <select name="user_id" required data-searchable-select data-empty-label="Brak pasujacych uzytkownikow">
              <option value="">Wybierz uzytkownika...</option>
              <?php foreach ($users as $user): ?>
                <option value="<?php echo h((string)$user['id']); ?>"><?php echo h(admin_video_user_option_label($user)); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="searchable-select-meta" data-searchable-select-meta></div>
          </div>
        </label>
        <label class="row">
          <span>Tryb</span>
          <select name="can_edit">
            <option value="0">view</option>
            <option value="1">edit</option>
          </select>
        </label>
        <label class="row">
          <span>Wlasciciel / workflow</span>
          <select name="set_as_owner">
            <option value="0">tylko dostep</option>
            <option value="1">ustaw jako wlasciciela</option>
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
                  <code><?php echo h((string)$a['youtube_id']); ?></code>
                  <small><?php echo h(' [' . (string)$a['provider'] . ']'); ?></small><br>
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
  </main>
  <script src="/assets/js/admin-videos-bulk.js?v=20260319-5" defer></script>
</body>
</html>
