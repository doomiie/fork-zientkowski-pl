<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require_login();
require_admin();
require_once __DIR__ . '/lib/Mailer.php';
require_once __DIR__ . '/../backend/video_auth_lib.php';
require_once __DIR__ . '/../backend/video_mail_lib.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function video_touchpoint_button_label(string $touchpointId): string
{
    $map = [
        'video.tokens.order_paid' => 'zakupu zetonow',
        'video.upload.confirmation' => 'dodania filmu',
        'video.summary.published' => 'opublikowania podsumowania',
        'video.title.changed' => 'zmiany nazwy filmu',
    ];
    return $map[$touchpointId] ?? $touchpointId;
}

$mailer = new GmailOAuthMailer($pdo);
$error = '';
$ok = '';
$tab = (string)($_GET['tab'] ?? 'settings');

$testTo = '';
$testSubject = 'Test - Gmail OAuth';
$testHtml = '<p>Test Gmail OAuth - pozdrowienia!</p>';
$verifyEmail = '';
$videoTouchpointEmail = '';

$settings = $mailer->getSettings();
$configChecks = [
    'provider' => trim((string)($settings['provider'] ?? '')) === 'gmail_oauth',
    'client_id' => trim((string)($settings['client_id'] ?? '')) !== '',
    'client_secret' => trim((string)($settings['client_secret'] ?? '')) !== '',
    'refresh_token' => trim((string)($settings['refresh_token'] ?? '')) !== '',
    'sender_email' => trim((string)($settings['sender_email'] ?? '')) !== '',
];
$configReady = !in_array(false, $configChecks, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!csrf_check((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Nieprawidlowy token bezpieczenstwa.';
    } elseif ($action === 'save') {
        try {
            $mailer->saveSettings([
                'provider' => 'gmail_oauth',
                'client_id' => trim((string)($_POST['client_id'] ?? '')),
                'client_secret' => trim((string)($_POST['client_secret'] ?? '')),
                'refresh_token' => trim((string)($_POST['refresh_token'] ?? '')),
                'sender_email' => trim((string)($_POST['sender_email'] ?? '')),
                'sender_name' => trim((string)($_POST['sender_name'] ?? '')),
            ]);
            $settings = $mailer->getSettings();
            $configChecks = [
                'provider' => trim((string)($settings['provider'] ?? '')) === 'gmail_oauth',
                'client_id' => trim((string)($settings['client_id'] ?? '')) !== '',
                'client_secret' => trim((string)($settings['client_secret'] ?? '')) !== '',
                'refresh_token' => trim((string)($settings['refresh_token'] ?? '')) !== '',
                'sender_email' => trim((string)($settings['sender_email'] ?? '')) !== '',
            ];
            $configReady = !in_array(false, $configChecks, true);
            $ok = 'Zapisano ustawienia.';
            $tab = 'settings';
        } catch (Throwable $e) {
            $error = 'Blad zapisu ustawien.';
        }
    } elseif ($action === 'test_send') {
        $tab = 'test';
        $testTo = trim((string)($_POST['to'] ?? ''));
        $testSubject = trim((string)($_POST['subject'] ?? $testSubject));
        $testHtml = (string)($_POST['html'] ?? $testHtml);
        if ($testTo === '') {
            $error = 'Podaj adres docelowy dla testu.';
        } else {
            try {
                $res = $mailer->send($testTo, $testSubject, $testHtml);
                $ok = 'Wyslano test. ID: ' . h((string)($res['id'] ?? ''));
            } catch (Throwable $e) {
                $error = 'Blad wysylki testu: ' . h($e->getMessage());
            }
        }
    } elseif ($action === 'send_verification') {
        $tab = 'test';
        $verifyEmail = strtolower(trim((string)($_POST['verify_email'] ?? '')));
        if (!filter_var($verifyEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Podaj poprawny adres e-mail do weryfikacji.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT id, email, email_verified_at, is_active FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$verifyEmail]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) {
                    $error = 'Nie znaleziono uzytkownika o tym adresie e-mail.';
                } elseif ((int)$user['is_active'] !== 1) {
                    $error = 'To konto jest nieaktywne.';
                } elseif ((string)($user['email_verified_at'] ?? '') !== '') {
                    $error = 'To konto ma juz potwierdzony adres e-mail.';
                } else {
                    $pdo->beginTransaction();
                    $verification = auth_issue_email_verification($pdo, (int)$user['id']);
                    auth_send_verification_email($pdo, (string)$user['email'], $verification['token']);
                    $pdo->commit();
                    $ok = 'Wyslano ponownie prawdziwy mail weryfikacyjny do ' . h((string)$user['email']) . '.';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Blad wysylki maila weryfikacyjnego: ' . h($e->getMessage());
            }
        }
    } elseif ($action === 'send_video_touchpoint') {
        $tab = 'video';
        $touchpointId = trim((string)($_POST['touchpoint_id'] ?? ''));
        $videoTouchpointEmail = strtolower(trim((string)($_POST['recipient_email'] ?? '')));
        if (!filter_var($videoTouchpointEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Podaj poprawny adres e-mail dla testu punktu.';
        } elseif ($touchpointId === '') {
            $error = 'Brak identyfikatora punktu.';
        } else {
            try {
                $context = video_mail_latest_context($pdo, $touchpointId);
                if (!$context) {
                    $error = 'Brak danych w systemie do przetestowania tego punktu.';
                } else {
                    $result = video_mail_send_touchpoint($pdo, $touchpointId, $videoTouchpointEmail, $context['vars']);
                    $ok = 'Wyslano punkt ' . h(video_touchpoint_button_label($touchpointId)) . ' do ' . h($videoTouchpointEmail) . '.';
                    if (!empty($result['message_id'])) {
                        $ok .= ' ID: ' . h((string)$result['message_id']);
                    }
                }
            } catch (Throwable $e) {
                $error = 'Blad wysylki punktu: ' . h($e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Poczta - Gmail OAuth</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; color:#111827; }
    header { background:#040327; color:#fff; padding:16px 24px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
    main { max-width:1100px; margin:24px auto; padding:0 24px; display:grid; gap:16px; }
    .card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 12px 30px rgba(0,0,0,.06); display:grid; gap:12px; }
    label { display:block; font-weight:600; margin:12px 0 6px; }
    input[type=text], textarea { width:100%; padding:12px 14px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; font:inherit; }
    .row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .tabs { display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap; }
    a.tab { text-decoration:none; padding:10px 14px; border-radius:10px; border:1px solid #cbd5e1; color:#040327; background:#fff; font-weight:600; }
    a.tab.active { background:#040327; color:#fff; border-color:#040327; }
    .error { background:#fee2e2; color:#7f1d1d; padding:10px 12px; border-radius:8px; margin:0; font-size:14px; }
    .ok { background:#ecfdf5; color:#065f46; padding:10px 12px; border-radius:8px; margin:0; font-size:14px; }
    a.btn { display:inline-block; background:#fff; color:#040327; border:1px solid #9ca3af; padding:8px 12px; border-radius:8px; text-decoration:none; font-weight:600; }
    button { background:#040327; color:#fff; border:0; border-radius:10px; padding:12px 16px; font-weight:600; cursor:pointer; }
    .muted { color:#6b7280; font-size:13px; }
    .status-grid { display:grid; gap:10px; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); }
    .status-item { border:1px solid #e5e7eb; border-radius:12px; padding:12px; background:#f9fafb; }
    .status-item strong { display:block; margin-bottom:6px; }
    .badge { display:inline-block; border-radius:999px; padding:4px 10px; font-size:12px; font-weight:700; }
    .badge-ok { background:#dcfce7; color:#166534; }
    .badge-bad { background:#fee2e2; color:#991b1b; }
    .stack { display:grid; gap:16px; }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline'; connect-src https://oauth2.googleapis.com https://gmail.googleapis.com;">
</head>
<body>
  <header>
    <div><strong>Panel administracyjny</strong></div>
    <div>
      Zalogowano jako: <?php echo h(current_user_email()); ?> | <?php echo h(current_user_role()); ?>
      &nbsp;|&nbsp;
      <a class="btn" href="index.php">Powrot</a>
      &nbsp;|&nbsp;
      <a class="btn" href="logout.php">Wyloguj</a>
    </div>
  </header>
  <main>
    <div class="card">
      <div class="tabs">
        <a class="tab <?php echo $tab === 'settings' ? 'active' : ''; ?>" href="mail.php?tab=settings">Ustawienia</a>
        <a class="tab <?php echo $tab === 'test' ? 'active' : ''; ?>" href="mail.php?tab=test">Weryfikacja wysylki</a>
        <a class="tab <?php echo $tab === 'video' ? 'active' : ''; ?>" href="mail.php?tab=video">Video</a>
      </div>
      <?php if ($error !== ''): ?><p class="error"><?php echo $error; ?></p><?php endif; ?>
      <?php if ($ok !== ''): ?><p class="ok"><?php echo $ok; ?></p><?php endif; ?>

      <?php if ($tab === 'settings'): ?>
        <section class="stack">
          <div>
            <h2 style="margin:0 0 8px;">Stan konfiguracji</h2>
            <p class="muted" style="margin:0 0 12px;">Tutaj sprawdzisz, czy panel ma komplet danych potrzebnych do realnej wysylki maili.</p>
            <div class="status-grid">
              <div class="status-item">
                <strong>Provider</strong>
                <span class="badge <?php echo $configChecks['provider'] ? 'badge-ok' : 'badge-bad'; ?>">
                  <?php echo $configChecks['provider'] ? 'ok' : 'brak'; ?>
                </span>
              </div>
              <div class="status-item">
                <strong>Client ID</strong>
                <span class="badge <?php echo $configChecks['client_id'] ? 'badge-ok' : 'badge-bad'; ?>">
                  <?php echo $configChecks['client_id'] ? 'ok' : 'brak'; ?>
                </span>
              </div>
              <div class="status-item">
                <strong>Client Secret</strong>
                <span class="badge <?php echo $configChecks['client_secret'] ? 'badge-ok' : 'badge-bad'; ?>">
                  <?php echo $configChecks['client_secret'] ? 'ok' : 'brak'; ?>
                </span>
              </div>
              <div class="status-item">
                <strong>Refresh Token</strong>
                <span class="badge <?php echo $configChecks['refresh_token'] ? 'badge-ok' : 'badge-bad'; ?>">
                  <?php echo $configChecks['refresh_token'] ? 'ok' : 'brak'; ?>
                </span>
              </div>
              <div class="status-item">
                <strong>Nadawca</strong>
                <span class="badge <?php echo $configChecks['sender_email'] ? 'badge-ok' : 'badge-bad'; ?>">
                  <?php echo $configChecks['sender_email'] ? 'ok' : 'brak'; ?>
                </span>
              </div>
              <div class="status-item">
                <strong>Ostatnie uzycie</strong>
                <span class="badge <?php echo trim((string)($settings['last_used_at'] ?? '')) !== '' ? 'badge-ok' : 'badge-bad'; ?>">
                  <?php echo trim((string)($settings['last_used_at'] ?? '')) !== '' ? h((string)$settings['last_used_at']) : 'brak'; ?>
                </span>
              </div>
            </div>
            <p class="muted" style="margin:12px 0 0;">
              Status globalny:
              <strong><?php echo $configReady ? 'konfiguracja kompletna' : 'konfiguracja niepelna'; ?></strong>
            </p>
          </div>

          <form method="post" action="mail.php?tab=settings" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="save">
            <div class="row">
              <div>
                <label for="client_id">Client ID</label>
                <input type="text" id="client_id" name="client_id" value="<?php echo h((string)($settings['client_id'] ?? '')); ?>">
              </div>
              <div>
                <label for="client_secret">Client Secret</label>
                <input type="text" id="client_secret" name="client_secret" value="<?php echo h((string)($settings['client_secret'] ?? '')); ?>">
              </div>
            </div>
            <label for="refresh_token">Refresh Token</label>
            <input type="text" id="refresh_token" name="refresh_token" value="<?php echo h((string)($settings['refresh_token'] ?? '')); ?>">
            <div class="row">
              <div>
                <label for="sender_email">Nadawca - e-mail</label>
                <input type="text" id="sender_email" name="sender_email" value="<?php echo h((string)($settings['sender_email'] ?? '')); ?>">
              </div>
              <div>
                <label for="sender_name">Nadawca - nazwa</label>
                <input type="text" id="sender_name" name="sender_name" value="<?php echo h((string)($settings['sender_name'] ?? '')); ?>">
              </div>
            </div>
            <div class="muted" style="margin-top:8px;">
              Jak uzyskac Refresh Token? Uzyj
              <a href="https://developers.google.com/oauthplayground/" target="_blank" rel="noopener">OAuth 2.0 Playground</a>:
              1) wybierz scope <code>https://www.googleapis.com/auth/gmail.send</code>,
              2) autoryzuj i zamien kod na tokeny,
              3) skopiuj <strong>refresh_token</strong>.
            </div>
            <div style="margin-top:16px;"><button type="submit">Zapisz</button></div>
          </form>
        </section>
      <?php elseif ($tab === 'test'): ?>
        <section class="stack">
          <div class="card" style="padding:0; box-shadow:none; background:transparent;">
            <h2 style="margin:0 0 8px;">Test techniczny</h2>
            <p class="muted" style="margin:0 0 12px;">Wysyla zwyklego maila testowego. To sprawdza sam kanal Gmail OAuth.</p>
            <form method="post" action="mail.php?tab=test" autocomplete="off">
              <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
              <input type="hidden" name="action" value="test_send">
              <label for="to">Adres docelowy</label>
              <input type="text" id="to" name="to" placeholder="adres@domena.pl" value="<?php echo h($testTo); ?>">
              <label for="subject">Temat</label>
              <input type="text" id="subject" name="subject" value="<?php echo h($testSubject); ?>">
              <label for="html">Tresc (HTML)</label>
              <textarea id="html" name="html" rows="8"><?php echo h($testHtml); ?></textarea>
              <div style="margin-top:16px;"><button type="submit">Wyslij test</button></div>
            </form>
          </div>

          <div class="card" style="padding:0; box-shadow:none; background:transparent;">
            <h2 style="margin:0 0 8px;">Test maila weryfikacyjnego</h2>
            <p class="muted" style="margin:0 0 12px;">Wysyla prawdziwy mail weryfikacyjny do istniejacego, aktywnego i jeszcze niezweryfikowanego uzytkownika.</p>
            <form method="post" action="mail.php?tab=test" autocomplete="off">
              <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
              <input type="hidden" name="action" value="send_verification">
              <label for="verify_email">E-mail niezweryfikowanego uzytkownika</label>
              <input type="text" id="verify_email" name="verify_email" placeholder="uzytkownik@example.com" value="<?php echo h($verifyEmail); ?>">
              <div style="margin-top:16px;"><button type="submit">Wyslij mail weryfikacyjny</button></div>
            </form>
          </div>
        </section>
      <?php else: ?>
        <?php
          $videoTouchpoints = array_values(array_filter(video_mail_touchpoints_for_ui(), static function (array $touchpoint): bool {
              return in_array((string)($touchpoint['id'] ?? ''), [
                  'video.tokens.order_paid',
                  'video.upload.confirmation',
                  'video.summary.published',
                  'video.title.changed',
              ], true);
          }));
        ?>
        <section class="stack">
          <div>
            <h2 style="margin:0 0 8px;">Testy punktow video</h2>
            <p class="muted" style="margin:0 0 12px;">Kazdy punkt wysyla realny mail na wskazany adres, ale pobiera dane z najnowszego odpowiadajacego mu rekordu w systemie.</p>
          </div>
          <?php foreach ($videoTouchpoints as $touchpoint): ?>
            <?php
              $touchpointId = (string)($touchpoint['id'] ?? '');
              $processLocation = (string)($touchpoint['process_location'] ?? '');
              $buttonLabel = video_touchpoint_button_label($touchpointId);
            ?>
            <div class="card" style="padding:0; box-shadow:none; background:transparent;">
              <h3 style="margin:0 0 8px;"><?php echo h($buttonLabel); ?></h3>
              <p class="muted" style="margin:0 0 12px;"><?php echo h($processLocation); ?></p>
              <form method="post" action="mail.php?tab=video" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="action" value="send_video_touchpoint">
                <input type="hidden" name="touchpoint_id" value="<?php echo h($touchpointId); ?>">
                <label for="recipient_email_<?php echo h($touchpointId); ?>">Adres e-mail</label>
                <input type="text" id="recipient_email_<?php echo h($touchpointId); ?>" name="recipient_email" placeholder="adres@domena.pl" value="<?php echo h($videoTouchpointEmail); ?>">
                <div style="margin-top:16px;">
                  <button type="submit">Wyslij punkt <?php echo h($buttonLabel); ?></button>
                </div>
              </form>
            </div>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
