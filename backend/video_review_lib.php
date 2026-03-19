<?php
declare(strict_types=1);

/**
 * @return array<int,array{key:string,title:string,position:int,items:array<int,array{item_key:string,label:string,position:int}>}>
 */
function vr_catalog(): array
{
    return [
        [
            'key' => 'idea',
            'title' => 'IDEA',
            'position' => 1,
            'items' => [
                ['item_key' => 'idea_1', 'label' => 'Czy jest widoczna idea mowy (teza, przeslanie)', 'position' => 1],
                ['item_key' => 'idea_2', 'label' => 'Czy jest widoczny cel mowy (w modelu PIE i modelu szczegolowym)', 'position' => 2],
                ['item_key' => 'idea_3', 'label' => 'Czy narzedzia retoryczne i inne warstwy wspieraja idee mowy', 'position' => 3],
            ],
        ],
        [
            'key' => 'tekst',
            'title' => 'TEKST',
            'position' => 2,
            'items' => [
                ['item_key' => 'tekst_1', 'label' => 'Czy tekst jest zrozumialy', 'position' => 1],
                ['item_key' => 'tekst_2', 'label' => 'Czy tekst jest logiczny (przeplyw informacji)', 'position' => 2],
                ['item_key' => 'tekst_3', 'label' => 'Czy widac uporzadkowanie i struktury', 'position' => 3],
            ],
        ],
        [
            'key' => 'warsztat_sceniczny',
            'title' => 'WARSZTAT SCENICZNY',
            'position' => 3,
            'items' => [
                ['item_key' => 'warsztat_sceniczny_1', 'label' => 'Czy mowca wyglada na przygotowanego', 'position' => 1],
                ['item_key' => 'warsztat_sceniczny_2', 'label' => 'Czy mowca robi dobre pierwsze wrazenie (inteligencja, eksperckosc, pasja)', 'position' => 2],
                ['item_key' => 'warsztat_sceniczny_3', 'label' => 'Czy warsztat sceniczny przeszkadza, jest neutralny czy wspiera mowe', 'position' => 3],
            ],
        ],
        [
            'key' => 'visuals',
            'title' => 'VISUALS',
            'position' => 4,
            'items' => [
                ['item_key' => 'visuals_1', 'label' => 'Czy slajdy lub pomoce wizualne wspieraja mowe', 'position' => 1],
                ['item_key' => 'visuals_2', 'label' => 'Czy to, co widac, pomaga w odbiorze', 'position' => 2],
                ['item_key' => 'visuals_3', 'label' => 'Czy ubior i wyglad mowcy jest adekwatny do mowy, celu i widowni', 'position' => 3],
            ],
        ],
        [
            'key' => 'kontekst',
            'title' => 'KONTEKST',
            'position' => 5,
            'items' => [
                ['item_key' => 'kontekst_1', 'label' => 'Czy mowa nawiazuje do widowni', 'position' => 1],
                ['item_key' => 'kontekst_2', 'label' => 'Czy mowca ma zaplanowane interakcje', 'position' => 2],
                ['item_key' => 'kontekst_3', 'label' => 'Czy mowca uzywa atraktorow', 'position' => 3],
            ],
        ],
        [
            'key' => 'brand',
            'title' => 'BRAND',
            'position' => 6,
            'items' => [
                ['item_key' => 'brand_1', 'label' => 'Czy mowa buduje wyrazny, unikatowy brand mowcy', 'position' => 1],
                ['item_key' => 'brand_2', 'label' => 'Czy sa elementy, ktore budują pozytywną marke mowcy', 'position' => 2],
                ['item_key' => 'brand_3', 'label' => 'Czy mowca jest profesjonalny', 'position' => 3],
            ],
        ],
        [
            'key' => 'benchmark',
            'title' => 'BENCHMARK',
            'position' => 7,
            'items' => [
                ['item_key' => 'benchmark_1', 'label' => 'Czy zaprosilbym mowce na swoja konferencje', 'position' => 1],
                ['item_key' => 'benchmark_2', 'label' => 'Czy zaplacilbym mowcy za mowe', 'position' => 2],
                ['item_key' => 'benchmark_3', 'label' => 'Czy zadedykowalbym konferencje marce mowcy', 'position' => 3],
            ],
        ],
    ];
}

/**
 * @return array<string,array{item_key:string,label:string,category_key:string,category_title:string,category_position:int,position:int}>
 */
function vr_item_dict(): array
{
    $dict = [];
    foreach (vr_catalog() as $category) {
        foreach ($category['items'] as $item) {
            $dict[$item['item_key']] = [
                'item_key' => $item['item_key'],
                'label' => $item['label'],
                'category_key' => $category['key'],
                'category_title' => $category['title'],
                'category_position' => (int)$category['position'],
                'position' => (int)$item['position'],
            ];
        }
    }
    return $dict;
}

function vr_total_items_count(): int
{
    return count(vr_item_dict());
}

/**
 * @param mixed $rawAnswers
 * @param array<string,array{item_key:string,label:string,category_key:string,category_title:string,category_position:int,position:int}> $dict
 * @return array<int,array{item_key:string,category_key:string,position:int,score:int}>
 */
function vr_normalize_answers($rawAnswers, array $dict): array
{
    if (!is_array($rawAnswers)) {
        return [];
    }
    $normalized = [];
    foreach ($rawAnswers as $row) {
        if (!is_array($row)) {
            continue;
        }
        $itemKey = trim((string)($row['item_key'] ?? ''));
        if ($itemKey === '' || !isset($dict[$itemKey])) {
            continue;
        }
        $score = (int)($row['score'] ?? 0);
        if ($score < 0 || $score > 3) {
            continue;
        }
        $normalized[$itemKey] = [
            'item_key' => $itemKey,
            'category_key' => (string)$dict[$itemKey]['category_key'],
            'position' => (int)$dict[$itemKey]['position'],
            'score' => $score,
        ];
    }
    return array_values($normalized);
}

/**
 * @return array{id:int,youtube_id:string}|null
 */
function vr_find_video_by_source(PDO $pdo, string $source): ?array
{
    $stmt = $pdo->prepare('SELECT id, youtube_id FROM videos WHERE youtube_id = ? LIMIT 1');
    $stmt->execute([$source]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'youtube_id' => (string)$row['youtube_id'],
    ];
}

/**
 * @return array{id:int,video_id:int,reviewer_user_id:int,status:string,version_no:int,published_at:?string,overall_note:?string,total_score:int,max_score:int,created_at:string,updated_at:string,archived_at:?string}|null
 */
function vr_load_latest_published(PDO $pdo, int $videoId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, video_id, reviewer_user_id, status, version_no, published_at, overall_note, total_score, max_score, created_at, updated_at, archived_at
         FROM video_review_summaries
         WHERE video_id = ? AND status = "published"
         ORDER BY published_at DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute([$videoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return vr_cast_summary_row($row);
}

/**
 * @return array<int,array{id:int,video_id:int,reviewer_user_id:int,status:string,version_no:int,published_at:?string,overall_note:?string,total_score:int,max_score:int,created_at:string,updated_at:string,archived_at:?string}>
 */
function vr_load_published_summaries(PDO $pdo, int $videoId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, video_id, reviewer_user_id, status, version_no, published_at, overall_note, total_score, max_score, created_at, updated_at, archived_at
         FROM video_review_summaries
         WHERE video_id = ? AND status = "published"
         ORDER BY published_at DESC, id DESC'
    );
    $stmt->execute([$videoId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = vr_cast_summary_row($row);
    }
    return $out;
}

/**
 * @return array<int,array{id:int,video_id:int,reviewer_user_id:int,status:string,version_no:int,published_at:?string,overall_note:?string,total_score:int,max_score:int,created_at:string,updated_at:string,archived_at:?string}>
 */
function vr_load_summary_history(PDO $pdo, int $videoId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, video_id, reviewer_user_id, status, version_no, published_at, overall_note, total_score, max_score, created_at, updated_at, archived_at
         FROM video_review_summaries
         WHERE video_id = ? AND status IN ("published", "archived")
         ORDER BY version_no DESC, id DESC'
    );
    $stmt->execute([$videoId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = vr_cast_summary_row($row);
    }
    return $out;
}

/**
 * @return array{id:int,video_id:int,reviewer_user_id:int,status:string,version_no:int,published_at:?string,overall_note:?string,total_score:int,max_score:int,created_at:string,updated_at:string,archived_at:?string}|null
 */
function vr_load_draft_for_user(PDO $pdo, int $videoId, int $reviewerUserId): ?array
{
    if ($reviewerUserId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, video_id, reviewer_user_id, status, version_no, published_at, overall_note, total_score, max_score, created_at, updated_at, archived_at
         FROM video_review_summaries
         WHERE video_id = ? AND reviewer_user_id = ? AND status = "draft"
         ORDER BY updated_at DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute([$videoId, $reviewerUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return vr_cast_summary_row($row);
}

/**
 * @param array<string,mixed> $row
 * @return array{id:int,video_id:int,reviewer_user_id:int,status:string,version_no:int,published_at:?string,overall_note:?string,total_score:int,max_score:int,created_at:string,updated_at:string,archived_at:?string}
 */
function vr_cast_summary_row(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'video_id' => (int)$row['video_id'],
        'reviewer_user_id' => (int)$row['reviewer_user_id'],
        'status' => (string)$row['status'],
        'version_no' => (int)$row['version_no'],
        'published_at' => ($row['published_at'] ?? null) !== null ? (string)$row['published_at'] : null,
        'overall_note' => ($row['overall_note'] ?? null) !== null ? (string)$row['overall_note'] : null,
        'total_score' => (int)($row['total_score'] ?? 0),
        'max_score' => (int)($row['max_score'] ?? 0),
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? ''),
        'archived_at' => ($row['archived_at'] ?? null) !== null ? (string)$row['archived_at'] : null,
    ];
}

function vr_next_version_no(PDO $pdo, int $videoId): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 FROM video_review_summaries WHERE video_id = ?');
    $stmt->execute([$videoId]);
    return max(1, (int)$stmt->fetchColumn());
}

/**
 * @param array{id:int,video_id:int,reviewer_user_id:int,status:string,version_no:int,published_at:?string,overall_note:?string,total_score:int,max_score:int,created_at:string,updated_at:string,archived_at:?string} $summary
 * @param array<string,array{item_key:string,label:string,category_key:string,category_title:string,category_position:int,position:int}> $dict
 * @return array<int,array{item_key:string,category_key:string,label:string,position:int,score:int}>
 */
function vr_load_scores(PDO $pdo, array $summary, array $dict): array
{
    $stmt = $pdo->prepare(
        'SELECT item_key, category_key, score, position
         FROM video_review_scores
         WHERE summary_id = ?
         ORDER BY position ASC, item_key ASC'
    );
    $stmt->execute([(int)$summary['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $itemKey = trim((string)($row['item_key'] ?? ''));
        if ($itemKey === '' || !isset($dict[$itemKey])) {
            continue;
        }
        $score = (int)($row['score'] ?? 0);
        if ($score < 0 || $score > 3) {
            continue;
        }
        $meta = $dict[$itemKey];
        $out[] = [
            'item_key' => $itemKey,
            'category_key' => (string)$meta['category_key'],
            'label' => (string)$meta['label'],
            'position' => (int)$meta['position'],
            'score' => $score,
        ];
    }
    return $out;
}

/**
 * @param array<int,array{key:string,title:string,position:int,items:array<int,array{item_key:string,label:string,position:int}>}> $catalog
 * @param array<int,array{item_key:string,category_key:string,label:string,position:int,score:int}> $answers
 * @return array<int,array{key:string,title:string,position:int,total_score:int,max_score:int,avg_score:float,rated_items:int,items:array<int,array{item_key:string,category_key:string,label:string,position:int,score:int,is_nd:bool}>}>
 */
function vr_build_category_stats(array $catalog, array $answers): array
{
    $byCategory = [];
    foreach ($answers as $answer) {
        $key = (string)$answer['category_key'];
        if (!isset($byCategory[$key])) {
            $byCategory[$key] = [];
        }
        $byCategory[$key][] = $answer;
    }

    $result = [];
    foreach ($catalog as $category) {
        $key = (string)$category['key'];
        $items = $byCategory[$key] ?? [];
        usort($items, static function (array $a, array $b): int {
            return (int)$a['position'] <=> (int)$b['position'];
        });
        $total = 0;
        $ratedCount = 0;
        $normalizedItems = [];
        foreach ($items as $item) {
            $score = (int)$item['score'];
            if ($score > 0) {
                $total += $score;
                $ratedCount++;
            }
            $item['is_nd'] = ($score === 0);
            $normalizedItems[] = $item;
        }
        $max = $ratedCount * 3;
        $avg = $ratedCount > 0 ? round($total / $ratedCount, 2) : 0.0;
        $result[] = [
            'key' => $key,
            'title' => (string)$category['title'],
            'position' => (int)$category['position'],
            'total_score' => $total,
            'max_score' => $max,
            'avg_score' => $avg,
            'rated_items' => $ratedCount,
            'items' => $normalizedItems,
        ];
    }
    return $result;
}

/**
 * @param array{id:int,video_id:int,reviewer_user_id:int,status:string,version_no:int,published_at:?string,overall_note:?string,total_score:int,max_score:int,created_at:string,updated_at:string,archived_at:?string} $summary
 * @param array<int,array{key:string,title:string,position:int,items:array<int,array{item_key:string,label:string,position:int}>}> $catalog
 * @param array<string,array{item_key:string,label:string,category_key:string,category_title:string,category_position:int,position:int}> $dict
 * @return array<string,mixed>
 */
function vr_hydrate_summary(PDO $pdo, array $summary, array $catalog, array $dict): array
{
    static $reviewerEmailCache = [];
    $answers = vr_load_scores($pdo, $summary, $dict);
    $categories = vr_build_category_stats($catalog, $answers);
    $answered = count($answers);
    $totalItems = vr_total_items_count();
    $ratedItems = 0;
    $totalScore = (int)$summary['total_score'];
    $maxScore = (int)$summary['max_score'];
    if ($totalScore <= 0 && $answered > 0) {
        foreach ($answers as $answer) {
            $score = (int)$answer['score'];
            if ($score > 0) {
                $totalScore += $score;
            }
        }
    }
    foreach ($answers as $answer) {
        if ((int)$answer['score'] > 0) {
            $ratedItems++;
        }
    }
    if ($maxScore <= 0) {
        $maxScore = $ratedItems * 3;
    }
    $reviewerUserId = (int)$summary['reviewer_user_id'];
    if (!array_key_exists($reviewerUserId, $reviewerEmailCache)) {
        $reviewerEmailCache[$reviewerUserId] = '-';
        if ($reviewerUserId > 0) {
            try {
                $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$reviewerUserId]);
                $email = trim((string)($stmt->fetchColumn() ?: ''));
                if ($email !== '') {
                    $reviewerEmailCache[$reviewerUserId] = $email;
                }
            } catch (Throwable $e) {
                $reviewerEmailCache[$reviewerUserId] = '-';
            }
        }
    }

    return [
        'id' => (int)$summary['id'],
        'video_id' => (int)$summary['video_id'],
        'reviewer_user_id' => (int)$summary['reviewer_user_id'],
        'reviewer_email' => (string)($reviewerEmailCache[$reviewerUserId] ?? '-'),
        'status' => (string)$summary['status'],
        'version_no' => (int)$summary['version_no'],
        'published_at' => $summary['published_at'],
        'overall_note' => (string)($summary['overall_note'] ?? ''),
        'total_score' => $totalScore,
        'max_score' => $maxScore,
        'answered_items' => $answered,
        'rated_items' => $ratedItems,
        'total_items' => $totalItems,
        'is_complete' => ($answered === $totalItems),
        'categories' => $categories,
        'answers' => $answers,
        'created_at' => (string)$summary['created_at'],
        'updated_at' => (string)$summary['updated_at'],
        'archived_at' => $summary['archived_at'],
    ];
}

/**
 * @param array<int,array{item_key:string,category_key:string,position:int,score:int}> $answers
 */
function vr_upsert_scores(PDO $pdo, int $summaryId, array $answers): void
{
    if (!$answers) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO video_review_scores (summary_id, item_key, category_key, score, position)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            category_key = VALUES(category_key),
            score = VALUES(score),
            position = VALUES(position)'
    );
    foreach ($answers as $answer) {
        $stmt->execute([
            $summaryId,
            (string)$answer['item_key'],
            (string)$answer['category_key'],
            (int)$answer['score'],
            (int)$answer['position'],
        ]);
    }
}
