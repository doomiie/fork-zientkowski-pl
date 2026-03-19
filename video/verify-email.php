<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../backend/video_auth_lib.php';

$token = trim((string)($_GET['token'] ?? ''));
$stage = 'error';
$message = 'Brak tokenu weryfikacyjnego.';

if ($token !== '') {
    $result = auth_verify_email_token($pdo, $token);
    if (!empty($result['ok'])) {
        $stage = 'success';
        $message = 'Adres e-mail zostal potwierdzony. Mozesz sie zalogowac.';
    } else {
        $error = (string)($result['error'] ?? 'invalid');
        if ($error === 'used') {
            $stage = 'success';
            $message = 'Ten adres e-mail jest juz potwierdzony. Mozesz sie zalogowac.';
        } elseif ($error === 'expired') {
            $message = 'Link weryfikacyjny wygasl.';
        } else {
            $message = 'Link weryfikacyjny jest nieprawidlowy.';
        }
    }
}

$videoPageTitle = 'Video App - Weryfikacja e-mail';
require __DIR__ . '/_layout_top.php';
?>
<section class="vapp-card vapp-card--narrow">
  <h1>Weryfikacja adresu e-mail</h1>
  <p class="vapp-status <?php echo $stage === 'success' ? 'vapp-status--ok' : 'vapp-status--error'; ?>">
    <?php echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
  </p>
  <p>
    <a class="vapp-btn" href="/video/login.php">Przejdz do logowania</a>
  </p>
</section>
<?php require __DIR__ . '/_layout_bottom.php'; ?>
