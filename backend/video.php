<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../admin/db.php';

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

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_GET['action'] ?? ($method === 'POST' ? ($_POST['action'] ?? '') : 'load')));

if ($action === 'load' && $method === 'GET') {
    $source = trim((string)($_GET['source'] ?? ''));
    if ($source === '' || !validate_youtube_id($source)) {
        json_response(400, [
            'ok' => false,
            'error' => 'invalid_source',
            'message' => 'Brak poprawnego parametru source.',
        ]);
    }

    $editMode = ((string)($_GET['edit'] ?? '') === '1');

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

        if (!$editMode && isset($video['publiczny']) && (int)$video['publiczny'] !== 1) {
            json_response(404, [
                'ok' => false,
                'error' => 'video_not_public',
                'message' => 'Film nie jest publicznie dostępny.',
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

    $title = trim((string)($data['tytul'] ?? ''));
    $content = trim((string)($data['tresc'] ?? ''));
    $variant = trim((string)($data['wariant'] ?? 'ogolny'));
    $author = trim((string)($data['autor'] ?? ''));
    $timeText = trim((string)($data['czas_tekst'] ?? ''));

    if ($title === '' && $content === '') {
        json_response(400, [
            'ok' => false,
            'error' => 'empty_comment',
            'message' => 'Uzupełnij tytuł lub treść komentarza.',
        ]);
    }

    $title = mb_substr($title, 0, 160);
    $content = mb_substr($content, 0, 2000);
    $variant = mb_substr($variant === '' ? 'ogolny' : $variant, 0, 64);
    $author = mb_substr($author, 0, 80);

    if ($timeText === '') {
        $timeText = format_time_label($seconds);
    } else {
        $timeText = mb_substr($timeText, 0, 16);
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
