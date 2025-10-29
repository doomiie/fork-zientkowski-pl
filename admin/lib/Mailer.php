<?php
declare(strict_types=1);

class GmailOAuthMailer {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getSettings(): array {
        $stmt = $this->pdo->query('SELECT * FROM mail_settings WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return $row;
    }

    public function saveSettings(array $data): void {
        $stmt = $this->pdo->prepare('UPDATE mail_settings SET provider=?, client_id=?, client_secret=?, refresh_token=?, sender_email=?, sender_name=? WHERE id=1');
        $stmt->execute([
            $data['provider'] ?? 'gmail_oauth',
            $data['client_id'] ?? null,
            $data['client_secret'] ?? null,
            $data['refresh_token'] ?? null,
            $data['sender_email'] ?? null,
            $data['sender_name'] ?? null,
        ]);
    }

    public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): array {
        $s = $this->getSettings();
        if (($s['provider'] ?? '') !== 'gmail_oauth') {
            throw new RuntimeException('Unsupported provider');
        }
        foreach (['client_id','client_secret','refresh_token','sender_email'] as $k) {
            if (empty($s[$k])) {
                throw new RuntimeException('Brak konfiguracji: ' . $k);
            }
        }
        $fromName = (string)($s['sender_name'] ?? '');
        $accessToken = $this->refreshAccessToken((string)$s['client_id'], (string)$s['client_secret'], (string)$s['refresh_token']);
        $raw = $this->buildMime($s['sender_email'], $fromName, $to, $subject, $htmlBody, $textBody);
        $resp = $this->gmailSendRaw($accessToken, $raw);
        $this->pdo->prepare('UPDATE mail_settings SET last_used_at = NOW() WHERE id=1')->execute();
        return $resp;
    }

    private function refreshAccessToken(string $clientId, string $clientSecret, string $refreshToken): string {
        $post = http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ], '', '&');

        $url = 'https://oauth2.googleapis.com/token';
        $headers = [ 'Content-Type: application/x-www-form-urlencoded' ];
        $body = $this->httpPost($url, $headers, $post);
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new RuntimeException('Nie udało się odświeżyć access_token: ' . substr($body ?? '', 0, 200));
        }
        return (string)$data['access_token'];
    }

    private function gmailSendRaw(string $accessToken, string $rawMime): array {
        $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';
        $payload = json_encode(['raw' => $this->base64url($rawMime)]);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
        ];
        $body = $this->httpPost($url, $headers, $payload);
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['id'])) {
            throw new RuntimeException('Gmail send error: ' . substr($body ?? '', 0, 200));
        }
        return $data;
    }

    private function httpPost(string $url, array $headers, string $body): string {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            $resp = curl_exec($ch);
            if ($resp === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new RuntimeException('HTTP POST failed: ' . $err);
            }
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 400) {
                throw new RuntimeException('HTTP ' . $code . ' ' . substr((string)$resp, 0, 200));
            }
            return (string)$resp;
        }
        // Fallback without cURL
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 20,
            ],
        ]);
        $resp = @file_get_contents($url, false, $context);
        if ($resp === false) {
            throw new RuntimeException('HTTP POST failed (stream context)');
        }
        return (string)$resp;
    }

    private function base64url(string $str): string {
        return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
    }

    private function encodeHeader(string $text): string {
        // RFC 2047 encoded-word for UTF-8
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    private function buildMime(string $fromEmail, string $fromName, string $toEmail, string $subject, string $html, ?string $text = null): string {
        $boundary = 'b_' . bin2hex(random_bytes(8));
        $date = date('r');
        $from = $fromName ? ($this->encodeHeader($fromName) . " <{$fromEmail}>") : $fromEmail;
        $headers = [];
        $headers[] = 'From: ' . $from;
        $headers[] = 'To: ' . $toEmail;
        $headers[] = 'Subject: ' . $this->encodeHeader($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Date: ' . $date;
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $textPart = $text ?? strip_tags(preg_replace('/<br\b[^>]*>/i', "\n", $html));
        $parts = [];
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: text/plain; charset=UTF-8';
        $parts[] = 'Content-Transfer-Encoding: base64';
        $parts[] = '';
        $parts[] = chunk_split(base64_encode($textPart));

        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: text/html; charset=UTF-8';
        $parts[] = 'Content-Transfer-Encoding: base64';
        $parts[] = '';
        $parts[] = chunk_split(base64_encode($html));

        $parts[] = '--' . $boundary . '--';

        $raw = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
        return $raw;
    }
}

