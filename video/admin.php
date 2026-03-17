<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
if (empty($videoAppUser['is_admin'])) {
    header('Location: /video/login.php');
    exit;
}
$videoPageTitle = 'Video App - Admin';
require __DIR__ . '/_layout_top.php';
?>
<section class="vapp-card">
  <h1>Admin Video App</h1>
  <p>Panel administracyjny aplikacji video wykorzystuje istniejące moduły admin.</p>
  <div class="vapp-actions">
    <a class="vapp-btn" href="/admin/index.php">Panel główny admin</a>
    <a class="vapp-btn vapp-btn--ghost" href="/admin/videos.php">Zarządzanie video</a>
    <a class="vapp-btn vapp-btn--ghost" href="/admin/users.php">Użytkownicy i trenerzy</a>
    <a class="vapp-btn vapp-btn--ghost" href="/admin/video_payment_settings.php">Płatności (P24 + Sandbox)</a>
    <a class="vapp-btn vapp-btn--ghost" href="/admin/access_tokens.php">Tokeny dostępu</a>
  </div>
</section>
<?php require __DIR__ . '/_layout_bottom.php'; ?>
