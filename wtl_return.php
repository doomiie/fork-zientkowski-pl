<?php
$log = __DIR__ . '/wtl_return.log';
file_put_contents($log, date('c') . " " . json_encode($_GET) . "\n", FILE_APPEND);
$status = $_GET['status'] ?? $_GET['result'] ?? $_GET['success'] ?? '';
$success = in_array(strtolower($status), ['ok','success','1','true','paid']);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <title>Wynik płatności</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="p-6">
  <h1 class="text-2xl font-bold mb-4">Wynik płatności</h1>
<?php if ($status === ''): ?>
  <p>Nie udało się zweryfikować statusu transakcji.</p>
<?php elseif ($success): ?>
  <p class="text-green-600">Dziękujemy, płatność została przyjęta.</p>
<?php else: ?>
  <p class="text-red-600">Niestety płatność nie powiodła się.</p>
<?php endif; ?>
<?php if (!empty($_GET)): ?>
  <h2 class="font-semibold mt-4">Odebrane parametry</h2>
  <pre class="bg-gray-100 p-2"><?php echo htmlspecialchars(json_encode($_GET, JSON_PRETTY_PRINT)); ?></pre>
<?php endif; ?>
  <script>
    window.parent.postMessage({ wtlPaymentStatus: <?php echo $success ? "'success'" : "'fail'"; ?> }, '*');
  </script>
</body>
</html>
