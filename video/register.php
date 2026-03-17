<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
$videoPageTitle = 'Video App - Rejestracja';
require __DIR__ . '/_layout_top.php';
?>
<section class="vapp-card vapp-card--narrow">
  <h1>Rejestracja</h1>
  <p>Zakładane konto ma rolę <strong>user</strong>.</p>
  <form id="vapp-register-form" class="vapp-form" novalidate>
    <label>E-mail
      <input type="email" name="email" autocomplete="username" required>
    </label>
    <label>Hasło (min. 8 znaków)
      <input type="password" name="password" autocomplete="new-password" minlength="8" required>
    </label>
    <button class="vapp-btn" type="submit">Utwórz konto</button>
    <p id="vapp-register-status" class="vapp-status"></p>
  </form>
</section>
<?php require __DIR__ . '/_layout_bottom.php'; ?>

