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
  <link rel="stylesheet" href="/video/app.css?v=20260317-1">
</head>
<body data-video-app-page="<?php echo htmlspecialchars((string)basename($_SERVER['SCRIPT_NAME']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
  <header class="vapp-top">
    <div class="vapp-wrap vapp-top__inner">
      <a class="vapp-brand" href="/video/index.php">
        <img src="/assets/img/logo_main.png" alt="Jerzy Zientkowski">
        <span>Video App</span>
      </a>
      <nav class="vapp-nav">
        <a href="/video/index.php">Start</a>
        <a href="/video/tokens.php">Żetony</a>
        <a href="/video/my-videos.php">Moje filmy</a>
        <?php if (($videoAppUser['role'] ?? '') === 'trener' || ($videoAppUser['role'] ?? '') === 'admin'): ?>
          <a href="/video/trener.php">Strefa trenera</a>
        <?php endif; ?>
        <?php if (($videoAppUser['role'] ?? '') === 'admin'): ?>
          <a href="/video/admin.php">Admin</a>
        <?php endif; ?>
      </nav>
      <div class="vapp-user">
        <?php if (!empty($videoAppUser['logged_in'])): ?>
          <span><?php echo htmlspecialchars((string)$videoAppUser['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
          <button id="vapp-logout-btn" type="button">Wyloguj</button>
        <?php else: ?>
          <a href="/video/login.php">Logowanie</a>
          <a href="/video/register.php">Rejestracja</a>
        <?php endif; ?>
      </div>
    </div>
  </header>
  <main class="vapp-main">
    <div class="vapp-wrap">
      <input type="hidden" id="vapp-csrf" value="<?php echo htmlspecialchars($videoAppCsrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">

