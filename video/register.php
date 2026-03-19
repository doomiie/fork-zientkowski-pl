<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
$videoPageTitle = 'Video App - Rejestracja';
require __DIR__ . '/_layout_top.php';
?>
<section class="vapp-card vapp-card--narrow">
  <h1>Rejestracja</h1>
  <p>Zakladane konto ma role <strong>user</strong>. Po rejestracji wyslemy link weryfikacyjny na podany adres e-mail.</p>
  <form id="vapp-register-form" class="vapp-form" novalidate>
    <label>E-mail
      <input type="email" name="email" autocomplete="username" required>
    </label>
    <label>Haslo (min. 8 znakow)
      <input type="password" name="password" autocomplete="new-password" minlength="8" required>
    </label>
    <button class="vapp-btn" type="submit">Utworz konto</button>
    <p id="vapp-register-status" class="vapp-status"></p>
  </form>
</section>
<?php require __DIR__ . '/_layout_bottom.php'; ?>
