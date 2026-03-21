    </div>
  </main>
  <?php $videoAppJsVersion = (string)(@filemtime(__DIR__ . '/app.js') ?: '20260321-1'); ?>
  <script src="/video/app.js?v=<?php echo htmlspecialchars($videoAppJsVersion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" defer></script>
</body>
</html>
