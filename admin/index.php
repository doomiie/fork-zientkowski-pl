<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require_login();
if (!is_admin()) {
    header('Location: /video.html');
    exit;
}

$sections = [
    [
        'title' => 'Konto',
        'description' => 'Podstawowe operacje na Twoim koncie.',
        'links' => [
            ['label' => 'Zmien swoje haslo', 'href' => 'password.php'],
        ],
    ],
];

if (is_admin()) {
    $sections = array_merge($sections, [
        [
            'title' => 'Uzytkownicy',
            'description' => 'Operacje administracyjne na kontach.',
            'links' => [
                ['label' => 'Dodaj uzytkownika', 'href' => 'users.php'],
                ['label' => 'Zmien haslo uzytkownika', 'href' => 'users_password.php'],
            ],
        ],
        [
            'title' => 'Dokumenty',
            'description' => 'Dodawanie i zarzadzanie dokumentami.',
            'links' => [
                ['label' => 'Zarzadzaj dokumentami', 'href' => 'docs.php'],
            ],
        ],
        [
            'title' => 'Przekierowania i strony',
            'description' => 'Nawigacja i kontrola tras.',
            'links' => [
                ['label' => 'Zarzadzaj przekierowaniami', 'href' => 'redirects.php'],
                ['label' => 'Lista stron', 'href' => 'pages.php'],
            ],
        ],
        [
            'title' => 'Statystyki',
            'description' => 'Raporty i analiza bledow 404.',
            'links' => [
                ['label' => 'Raporty 404', 'href' => '404stats.php'],
            ],
        ],
        [
            'title' => 'Wideo',
            'description' => 'Dodawanie i porzadkowanie filmow YouTube.',
            'links' => [
                ['label' => 'Administracja wideo', 'href' => 'videos.php'],
            ],
        ],
        [
            'title' => 'Integracje',
            'description' => 'Uslugi zewnetrzne i automatyzacje.',
            'links' => [
                ['label' => 'Google Sheets', 'href' => 'sheets.php'],
                ['label' => 'Gmail OAuth', 'href' => 'mail.php'],
                ['label' => 'Ustawienia serwisu', 'href' => 'site.php'],
            ],
        ],
        [
            'title' => 'Dostep tymczasowy',
            'description' => 'Generowanie tokenow i dostepu czasowego.',
            'links' => [
                ['label' => 'Generator tokenow', 'href' => 'access_tokens.php'],
            ],
        ],
    ]);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel admin</title>
  <style>
    :root {
      --bg: #eef2f7;
      --surface: #ffffff;
      --text: #122033;
      --muted: #4f5f74;
      --accent: #0b2a56;
      --accent-soft: #d7e5ff;
      --border: #d8e0eb;
      --shadow: 0 16px 34px rgba(10, 32, 70, .08);
      --radius: 16px;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: "Segoe UI", "Trebuchet MS", sans-serif;
      color: var(--text);
      background:
        radial-gradient(1000px 500px at 20% -20%, #d7e6ff 0%, transparent 60%),
        radial-gradient(900px 420px at 100% -20%, #c8f3eb 0%, transparent 55%),
        var(--bg);
    }

    .topbar {
      position: sticky;
      top: 0;
      z-index: 20;
      border-bottom: 1px solid rgba(255, 255, 255, .2);
      background: linear-gradient(135deg, #0a1830 0%, #0b2a56 100%);
      color: #fff;
      backdrop-filter: blur(8px);
    }

    .topbar__inner {
      width: min(1200px, calc(100% - 32px));
      margin: 0 auto;
      min-height: 74px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
    }

    .brand {
      display: grid;
      gap: 2px;
    }

    .brand strong {
      font-size: 1.08rem;
      letter-spacing: .02em;
    }

    .brand span {
      font-size: .86rem;
      opacity: .85;
    }

    .session {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      border: 1px solid rgba(255, 255, 255, .3);
      border-radius: 999px;
      padding: 6px 10px;
      font-size: .82rem;
      background: rgba(255, 255, 255, .08);
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 36px;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: #fff;
      color: var(--accent);
      text-decoration: none;
      padding: 7px 12px;
      font-weight: 600;
      font-size: .9rem;
      transition: .18s ease;
    }

    .btn:hover {
      border-color: #b9c8df;
      transform: translateY(-1px);
    }

    .btn--logout {
      border-color: rgba(255, 255, 255, .4);
      background: rgba(255, 255, 255, .1);
      color: #fff;
    }

    .btn--logout:hover {
      background: rgba(255, 255, 255, .18);
      border-color: rgba(255, 255, 255, .6);
    }

    .layout {
      width: min(1200px, calc(100% - 32px));
      margin: 22px auto 34px;
      display: grid;
      gap: 16px;
    }

    .welcome {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 18px;
    }

    .welcome h1 {
      margin: 0;
      font-size: clamp(1.3rem, 2vw, 1.85rem);
    }

    .welcome p {
      margin: 8px 0 0;
      color: var(--muted);
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 14px;
    }

    .section {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 16px;
      display: grid;
      gap: 10px;
      align-content: start;
    }

    .section h2 {
      margin: 0;
      font-size: 1.03rem;
      color: var(--accent);
    }

    .section p {
      margin: 0;
      color: var(--muted);
      font-size: .92rem;
      line-height: 1.4;
      min-height: 2.6em;
    }

    .section__actions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .section--highlight {
      border-color: #b7cdf4;
      background: linear-gradient(180deg, #f9fbff 0%, #ffffff 65%);
    }

    @media (max-width: 640px) {
      .topbar__inner { padding: 8px 0; }
      .session { justify-content: flex-start; }
      .chip { font-size: .78rem; }
    }
  </style>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline';">
</head>
<body>
  <header class="topbar">
    <div class="topbar__inner">
      <div class="brand">
        <strong>Panel administracyjny</strong>
        <span>Szybki dostep do wszystkich modulow</span>
      </div>
      <div class="session">
        <span class="chip"><?php echo htmlspecialchars(current_user_email(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
        <span class="chip"><?php echo htmlspecialchars(current_user_role(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
        <a class="btn btn--logout" href="logout.php">Wyloguj</a>
      </div>
    </div>
  </header>

  <main class="layout">
    <section class="welcome">
      <h1>Witaj</h1>
      <p>Wybierz sekcje ponizej. Najczesciej uzywane funkcje sa na osobnych kartach, zeby skracac liczbe klikniec.</p>
    </section>

    <section class="grid">
      <?php foreach ($sections as $idx => $section): ?>
        <article class="section<?php echo $idx === 0 ? ' section--highlight' : ''; ?>">
          <h2><?php echo htmlspecialchars($section['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h2>
          <p><?php echo htmlspecialchars($section['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
          <div class="section__actions">
            <?php foreach ($section['links'] as $link): ?>
              <a class="btn" href="<?php echo htmlspecialchars($link['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($link['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
              </a>
            <?php endforeach; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  </main>
</body>
</html>
