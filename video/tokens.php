<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
$videoPageTitle = 'Video App - Żetony';
require __DIR__ . '/_layout_top.php';
?>
<section class="vapp-card">
  <h1>Zakup żetonów</h1>
  <p id="vapp-token-balance" class="vapp-muted">Ładowanie salda...</p>
  <div id="vapp-token-types" class="vapp-grid"></div>
  <p id="vapp-tokens-status" class="vapp-status"></p>
</section>

<section class="vapp-card">
  <h2>Moje zamówienia</h2>
  <div class="vapp-table-wrap">
    <table class="vapp-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Pakiet</th>
          <th>Status</th>
          <th>Kwota</th>
          <th>Data</th>
        </tr>
      </thead>
      <tbody id="vapp-token-orders"></tbody>
    </table>
  </div>
</section>
<?php require __DIR__ . '/_layout_bottom.php'; ?>

