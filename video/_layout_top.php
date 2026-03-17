<?php
declare(strict_types=1);
/** @var string $videoPageTitle */
/** @var array<string,mixed> $videoAppUser */
/** @var string $videoAppCsrf */
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($videoPageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="/assets/css/shared.css?v=dev19">
  <link rel="stylesheet" href="/video/app.css?v=20260317-3">
</head>
<body
  data-video-app-page="<?php echo htmlspecialchars((string)basename($_SERVER['SCRIPT_NAME']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
  data-vapp-user-id="<?php echo htmlspecialchars((string)($videoAppUser['user_id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
  data-vapp-user-role="<?php echo htmlspecialchars((string)($videoAppUser['role'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
  data-vapp-user-roles="<?php echo htmlspecialchars(implode(',', array_map('strval', (array)($videoAppUser['roles'] ?? []))), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
>
  <header class="vapp-top">
    <div class="vapp-wrap vapp-top__inner">
      <a class="vapp-brand" href="/video">
        <img src="/assets/img/logo_main.png" alt="Jerzy Zientkowski">
        <span>Video App</span>
      </a>
      <button id="vapp-menu-toggle" class="vapp-menu-toggle" type="button" aria-expanded="false" aria-controls="vapp-drawer" aria-label="Otwórz menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </header>

  <div id="vapp-drawer-overlay" class="vapp-drawer-overlay" hidden></div>
  <aside id="vapp-drawer" class="vapp-drawer" aria-hidden="true">
    <div class="vapp-drawer__head">
      <a class="vapp-brand vapp-brand--drawer" href="/video">
        <img src="/assets/img/logo_main.png" alt="Jerzy Zientkowski">
        <span>Video App</span>
      </a>
      <button id="vapp-menu-close" class="vapp-drawer__close" type="button" aria-label="Zamknij menu">×</button>
    </div>
    <nav class="vapp-nav vapp-nav--drawer">
      <a href="/video">/video</a>
      <a href="/video/index.php">Start</a>
      <a href="/video/tokens.php">Żetony</a>
      <a href="/video/my-videos.php">Moje filmy</a>
      <?php if (!empty($videoAppUser['is_trener']) || !empty($videoAppUser['is_admin'])): ?>
        <a href="/video/trener.php">Strefa trenera</a>
      <?php endif; ?>
      <?php if (!empty($videoAppUser['is_admin'])): ?>
        <a href="/video/admin.php">Admin</a>
      <?php endif; ?>
    </nav>
    <div class="vapp-user vapp-user--drawer">
      <?php if (!empty($videoAppUser['logged_in'])): ?>
        <span><?php echo htmlspecialchars((string)$videoAppUser['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
        <button id="vapp-logout-btn" type="button">Wyloguj</button>
      <?php else: ?>
        <a href="/video/login.php">Logowanie</a>
        <a href="/video/register.php">Rejestracja</a>
      <?php endif; ?>
    </div>
  </aside>

  <main class="vapp-main">
    <div class="vapp-wrap">
      <input type="hidden" id="vapp-csrf" value="<?php echo htmlspecialchars($videoAppCsrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
