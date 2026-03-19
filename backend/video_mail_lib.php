<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/lib/Mailer.php';

const VIDEO_MAIL_TOUCHPOINTS_FILE = __DIR__ . '/../mail_touchpoints.json';

function video_mail_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        $host = 'localhost';
    }
    return $scheme . '://' . $host;
}

function video_mail_absolute_url(string $pathOrUrl): string
{
    $value = trim($pathOrUrl);
    if ($value === '') {
        return video_mail_base_url();
    }
    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }
    if ($value[0] !== '/') {
        $value = '/' . $value;
    }
    return video_mail_base_url() . $value;
}

/**
 * @return array<string,mixed>
 */
function video_mail_registry(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    if (!is_readable(VIDEO_MAIL_TOUCHPOINTS_FILE)) {
        throw new RuntimeException('Brak pliku mail_touchpoints.json.');
    }
    $decoded = json_decode((string)file_get_contents(VIDEO_MAIL_TOUCHPOINTS_FILE), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Nieprawidlowy plik mail_touchpoints.json.');
    }
    $cache = $decoded;
    return $cache;
}

/**
 * @return array<string,mixed>|null
 */
function video_mail_template_def(string $templateKey): ?array
{
    $registry = video_mail_registry();
    $templates = $registry['templates'] ?? null;
    if (!is_array($templates) || !isset($templates[$templateKey]) || !is_array($templates[$templateKey])) {
        return null;
    }
    return $templates[$templateKey];
}

/**
 * @return array<string,mixed>|null
 */
function video_mail_touchpoint_def(string $touchpointId): ?array
{
    $registry = video_mail_registry();
    $touchpoints = $registry['touchpoints'] ?? null;
    if (!is_array($touchpoints)) {
        return null;
    }
    foreach ($touchpoints as $touchpoint) {
        if (is_array($touchpoint) && (string)($touchpoint['id'] ?? '') === $touchpointId) {
            return $touchpoint;
        }
    }
    return null;
}

function video_mail_render_string(string $template, array $vars): string
{
    $replacements = [];
    foreach ($vars as $key => $value) {
        $replacements['{{' . $key . '}}'] = is_scalar($value) || $value === null ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    return strtr($template, $replacements);
}

/**
 * @return array{subject:string,html:string,text:string}
 */
function video_mail_render_template(string $templateKey, array $vars): array
{
    $template = video_mail_template_def($templateKey);
    if (!$template) {
        throw new RuntimeException('Nieznany szablon maila: ' . $templateKey);
    }

    $subject = video_mail_render_string((string)($template['subject'] ?? ''), $vars);
    $html = video_mail_render_string((string)($template['html'] ?? ''), $vars);
    $text = trim((string)($template['text'] ?? ''));
    if ($text === '') {
        $text = trim(strip_tags(preg_replace('/<br\b[^>]*>/i', "\n", $html)));
    } else {
        $text = video_mail_render_string($text, $vars);
    }

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
}

/**
 * @return array{message_id:?string,subject:string,html:string,text:string,template_key:string,touchpoint_id:string}
 */
function video_mail_send_touchpoint(PDO $pdo, string $touchpointId, string $toEmail, array $vars = []): array
{
    $touchpoint = video_mail_touchpoint_def($touchpointId);
    if (!$touchpoint) {
        throw new RuntimeException('Nieznany punkt mailowy: ' . $touchpointId);
    }
    $templateKey = (string)($touchpoint['template_key'] ?? '');
    if ($templateKey === '') {
        throw new RuntimeException('Brak template_key dla punktu: ' . $touchpointId);
    }

    $rendered = video_mail_render_template($templateKey, $vars);
    $mailer = new GmailOAuthMailer($pdo);
    $result = $mailer->send($toEmail, $rendered['subject'], $rendered['html'], $rendered['text']);

    return [
        'message_id' => isset($result['id']) ? (string)$result['id'] : null,
        'subject' => $rendered['subject'],
        'html' => $rendered['html'],
        'text' => $rendered['text'],
        'template_key' => $templateKey,
        'touchpoint_id' => $touchpointId,
    ];
}

/**
 * @return array{to_email:string,vars:array<string,mixed>}|null
 */
function video_mail_latest_context(PDO $pdo, string $touchpointId): ?array
{
    switch ($touchpointId) {
        case 'video.tokens.order_paid':
            $stmt = $pdo->query(
                "SELECT o.id, o.order_uuid, o.user_id, o.token_type_id, t.title AS token_title, t.max_upload_links, t.can_choose_trainer, u.email AS user_email
                 FROM token_orders o
                 JOIN users u ON u.id = o.user_id
                 JOIN token_types t ON t.id = o.token_type_id
                 WHERE o.status = 'paid'
                 ORDER BY o.paid_at DESC, o.id DESC
                 LIMIT 1"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $userId = (int)$row['user_id'];
            $balance = function_exists('vt_get_user_balance') ? vt_get_user_balance($pdo, $userId) : ['remaining_upload_links' => 0, 'remaining_trainer_choices' => 0];
            return [
                'to_email' => (string)$row['user_email'],
                'vars' => [
                    'token_type_title' => (string)$row['token_title'],
                    'order_uuid' => (string)$row['order_uuid'],
                    'remaining_upload_links' => (int)$balance['remaining_upload_links'],
                    'remaining_trainer_choices' => (int)$balance['remaining_trainer_choices'],
                ],
            ];

        case 'video.upload.confirmation':
            $stmt = $pdo->query(
                "SELECT v.id, v.youtube_id, v.tytul, v.source_url, v.assigned_trainer_user_id,
                        owner.email AS owner_email,
                        trainer.email AS trainer_email
                 FROM videos v
                 JOIN users owner ON owner.id = v.owner_user_id
                 LEFT JOIN users trainer ON trainer.id = v.assigned_trainer_user_id
                 WHERE v.owner_user_id IS NOT NULL
                 ORDER BY v.zaktualizowano DESC, v.id DESC
                 LIMIT 1"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $trainerEmail = trim((string)($row['trainer_email'] ?? ''));
            return [
                'to_email' => (string)$row['owner_email'],
                'vars' => [
                    'video_title' => (string)$row['tytul'],
                    'video_url' => (string)$row['source_url'],
                    'trainer_name' => $trainerEmail !== '' ? $trainerEmail : 'brak przypisanego trenera',
                ],
            ];

        case 'video.summary.published':
            $stmt = $pdo->query(
                "SELECT s.id AS summary_id, s.version_no, s.total_score, s.max_score, s.video_id,
                        v.youtube_id, v.tytul, owner.email AS owner_email
                 FROM video_review_summaries s
                 JOIN videos v ON v.id = s.video_id
                 LEFT JOIN users owner ON owner.id = v.owner_user_id
                 WHERE s.status = 'published'
                 ORDER BY s.published_at DESC, s.id DESC
                 LIMIT 1"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $source = (string)($row['youtube_id'] ?? '');
            $summaryUrl = $source !== ''
                ? video_mail_absolute_url('/video/review-print.php?source=' . rawurlencode($source) . '&review_id=' . (int)$row['summary_id'])
                : video_mail_absolute_url('/video/play.php?source=' . rawurlencode($source));
            return [
                'to_email' => (string)($row['owner_email'] ?? ''),
                'vars' => [
                    'video_title' => (string)$row['tytul'],
                    'summary_version' => (int)$row['version_no'],
                    'summary_score' => (int)$row['total_score'],
                    'summary_max_score' => (int)$row['max_score'],
                    'summary_url' => $summaryUrl,
                ],
            ];

        case 'video.title.changed':
            $stmt = $pdo->query(
                "SELECT v.id, v.youtube_id, v.tytul, v.source_url, owner.email AS owner_email
                 FROM videos v
                 JOIN users owner ON owner.id = v.owner_user_id
                 WHERE v.owner_user_id IS NOT NULL
                 ORDER BY v.zaktualizowano DESC, v.id DESC
                 LIMIT 1"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $currentTitle = (string)($row['tytul'] ?? '');
            $oldTitle = $currentTitle !== '' ? $currentTitle . ' (stara nazwa)' : (string)($row['youtube_id'] ?? '');
            $newTitle = $currentTitle !== '' ? $currentTitle . ' (nowa nazwa)' : (string)($row['youtube_id'] ?? '');
            $youtubeUrl = trim((string)($row['source_url'] ?? ''));
            $platformUrl = video_mail_absolute_url('/video/play.php?source=' . rawurlencode((string)($row['youtube_id'] ?? '')));
            if ($youtubeUrl === '') {
                $youtubeUrl = $platformUrl;
            }
            return [
                'to_email' => (string)($row['owner_email'] ?? ''),
                'vars' => [
                    'video_title_old' => $oldTitle,
                    'video_title_new' => $newTitle,
                    'video_youtube_url' => $youtubeUrl,
                    'video_platform_url' => $platformUrl,
                ],
            ];
    }

    return null;
}

/**
 * @return array<string,mixed>
 */
function video_mail_touchpoints_for_ui(): array
{
    $registry = video_mail_registry();
    $touchpoints = $registry['touchpoints'] ?? [];
    if (!is_array($touchpoints)) {
        return [];
    }
    $out = [];
    foreach ($touchpoints as $touchpoint) {
        if (!is_array($touchpoint)) {
            continue;
        }
        $id = (string)($touchpoint['id'] ?? '');
        if (!str_starts_with($id, 'video.')) {
            continue;
        }
        $out[] = $touchpoint;
    }
    return $out;
}
