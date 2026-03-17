<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
$videoPageTitle = 'Video App - Moje filmy';
require __DIR__ . '/_layout_top.php';
?>
<section class="vapp-card">
  <h1>Dodaj link YouTube</h1>
  <p id="vapp-my-balance" class="vapp-muted">Ładowanie salda...</p>
  <form id="vapp-my-video-form" class="vapp-form" novalidate>
    <label>Link lub ID YouTube
      <input type="text" name="youtube_url" placeholder="https://youtu.be/... lub ID" required>
    </label>
    <label>Wybór trenera (opcjonalny, zużywa odpowiedni żeton)
      <select id="vapp-trainer-select" name="trainer_user_id">
        <option value="">Automatyczny wybór trenera</option>
      </select>
    </label>
    <button class="vapp-btn" type="submit">Dodaj film</button>
    <p id="vapp-my-video-status" class="vapp-status"></p>
  </form>
</section>

<section class="vapp-card">
  <h2>Moje filmy</h2>
  <div class="vapp-table-wrap">
    <table class="vapp-table">
      <thead>
        <tr>
          <th>Źródło</th>
          <th>Tytuł</th>
          <th>Trener</th>
          <th>Akcja</th>
        </tr>
      </thead>
      <tbody id="vapp-my-videos-list"></tbody>
    </table>
  </div>
</section>
<?php require __DIR__ . '/_layout_bottom.php'; ?>

