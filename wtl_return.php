<?php
$log = __DIR__ . '/wtl_return.log';
file_put_contents($log, date('c') . " " . json_encode($_GET) . "\n", FILE_APPEND);
$status = $_GET['status'] ?? $_GET['result'] ?? $_GET['success'] ?? '';
$success = in_array(strtolower($status), ['ok','success','1','true','paid']);
// Treat missing status as success because WTL may not provide it
if ($status === '' && !empty($_GET['wtl_offer_uid'])) {
    $success = true;
}
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
<?php if ($success): ?>
  <p class="text-green-600">Dziękujemy za rezerwację terminu.</p>
<?php elseif ($status === ''): ?>
  <p>Nie udało się zweryfikować statusu transakcji.</p>
<?php else: ?>
  <p class="text-red-600">Niestety płatność nie powiodła się.</p>
<?php endif; ?>
<?php if (!empty($_GET)): ?>
  <h2 class="font-semibold mt-4">Odebrane parametry</h2>
  <pre class="bg-gray-100 p-2"><?php echo htmlspecialchars(json_encode($_GET, JSON_PRETTY_PRINT)); ?></pre>
<?php endif; ?>
  <p id="return-link" class="mt-4 hidden"><a href="sesja.html" class="text-blue-600 underline">Powrót do rezerwacji</a></p>
  <script>
    if (window.parent !== window) {
        window.parent.postMessage({ wtlPaymentStatus: <?php echo $success ? "'success'" : "'fail'"; ?> }, '*');
    } else {
        document.getElementById('return-link').classList.remove('hidden');
        setTimeout(() => { window.location.href = 'sesja.html'; }, 5000);
    }
  </script>
</body>
</html>
