<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
$videoPageTitle = 'Video App - Logowanie';
require __DIR__ . '/_layout_top.php';
?>
<section class="vapp-card vapp-card--narrow">
  <h1>Logowanie</h1>
  <form id="vapp-login-form" class="vapp-form" novalidate>
    <label>E-mail
      <input type="email" name="email" autocomplete="username" required>
    </label>
    <label>Hasło
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button class="vapp-btn" type="submit">Zaloguj</button>
    <p id="vapp-login-status" class="vapp-status"></p>
  </form>
</section>
<?php require __DIR__ . '/_layout_bottom.php'; ?>

