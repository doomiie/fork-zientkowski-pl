<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
$videoPageTitle = 'Video App - Start';
require __DIR__ . '/_layout_top.php';
?>
<section class="vapp-card">
  <h1>Video App</h1>
  <p>Samodzielna aplikacja pod <code>/video/</code> do rejestracji, zakupu żetonów i pracy na filmach.</p>
  <?php if (!empty($videoAppUser['logged_in'])): ?>
    <p class="vapp-ok">Zalogowano jako <strong><?php echo htmlspecialchars((string)$videoAppUser['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>.</p>
  <?php else: ?>
    <p class="vapp-warn">Nie jesteś zalogowany. Przejdź do rejestracji lub logowania.</p>
  <?php endif; ?>
  <div class="vapp-actions">
    <a class="vapp-btn" href="/video/register.php">Rejestracja</a>
    <a class="vapp-btn vapp-btn--ghost" href="/video/login.php">Logowanie</a>
    <a class="vapp-btn vapp-btn--ghost" href="/video/tokens.php">Zakup żetonów</a>
    <a class="vapp-btn vapp-btn--ghost" href="/video/my-videos.php">Dodaj link YouTube</a>
    <?php if (($videoAppUser['role'] ?? '') === 'trener' || ($videoAppUser['role'] ?? '') === 'admin'): ?>
      <a class="vapp-btn vapp-btn--ghost" href="/video/trener.php">Strefa trenera</a>
    <?php endif; ?>
  </div>
</section>

<section class="vapp-card">
  <h2>Workflow MVP</h2>
  <ol>
    <li>Rejestracja użytkownika (auto-login).</li>
    <li>Zakup pakietu żetonów przez Przelewy24.</li>
    <li>Dodanie linku YouTube i przypisanie do trenera.</li>
    <li>Trener komentuje film w obecnym interfejsie <code>video.html</code>.</li>
  </ol>
</section>
<?php require __DIR__ . '/_layout_bottom.php'; ?>

