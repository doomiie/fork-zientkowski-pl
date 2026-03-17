<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if (!empty($videoAppUser['logged_in'])) {
    if (!empty($videoAppUser['is_admin']) || !empty($videoAppUser['is_trener'])) {
        header('Location: /video/trener.php');
        exit;
    }
    header('Location: /video/my-videos.php');
    exit;
}

$videoPageTitle = 'Video App - Start';
require __DIR__ . '/_layout_top.php';
?>
<section class="vapp-card">
  <h1>Video App</h1>
  <p class="vapp-muted">Aby rozpocząć, zaloguj się lub utwórz konto.</p>
  <div class="vapp-actions">
    <a class="vapp-btn" href="/video/login.php">Logowanie</a>
    <a class="vapp-btn vapp-btn--ghost" href="/video/register.php">Rejestracja</a>
  </div>
</section>

<section class="vapp-card">
  <h2>Workflow MVP</h2>
  <ol>
    <li>Rejestracja użytkownika (auto-login).</li>
    <li>Zakup pakietu żetonów przez Przelewy24.</li>
    <li>Dodanie linku YouTube i przypisanie do trenera.</li>
    <li>Trener komentuje film w interfejsie <code>/video/play.php</code>.</li>
  </ol>
</section>
<?php require __DIR__ . '/_layout_bottom.php'; ?>
