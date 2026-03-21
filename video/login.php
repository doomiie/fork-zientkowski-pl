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
<?php if (auth_debug_enabled()): ?>
<section class="vapp-card vapp-card--narrow">
  <h2>Debug logowania</h2>
  <p class="vapp-status">Widok diagnostyczny jest wlaczony dla tej sesji przez <code>?debug_auth=1</code>.</p>
  <pre id="vapp-auth-debug-output" class="vapp-auth-debug-output">Ladowanie debug info...</pre>
</section>
<?php endif; ?>
<?php require __DIR__ . '/_layout_bottom.php'; ?>
