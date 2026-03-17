<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
$role = (string)($videoAppUser['role'] ?? '');
if ($role !== 'trener' && $role !== 'admin') {
    header('Location: /video/login.php');
    exit;
}
$videoPageTitle = 'Video App - Strefa trenera';
require __DIR__ . '/_layout_top.php';
?>
<section class="vapp-card">
  <h1>Strefa trenera</h1>
  <p>Wybierz film z listy i przejdź do komentowania w istniejącym interfejsie.</p>
  <div class="vapp-table-wrap">
    <table class="vapp-table">
      <thead>
        <tr>
          <th>Source</th>
          <th>Tytuł</th>
          <th>Akcja</th>
        </tr>
      </thead>
      <tbody id="vapp-trainer-videos"></tbody>
    </table>
  </div>
  <p id="vapp-trainer-status" class="vapp-status"></p>
</section>
<?php require __DIR__ . '/_layout_bottom.php'; ?>

