<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

// Access control
$allow = false;
try {
    require_once __DIR__ . '/../admin/db.php';
    if (function_exists('is_admin') && is_admin()) {
        $allow = true;
    }
} catch (Throwable $e) {
    // ignore
}

if (!$allow) {
    $cfgPath = dirname(__DIR__) . '/config.json';
    $debugFlag = false;
    if (is_file($cfgPath)) {
        try { $cfg = json_decode((string)file_get_contents($cfgPath), true); $debugFlag = !empty($cfg['debugBoxVisible']); }
        catch (Throwable $e) { $debugFlag = false; }
    }
    if (!$debugFlag) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
}

$beforePath = __DIR__ . '/wtl_before.log';
$afterPath = __DIR__ . '/wtl_after.log';
$before = file_exists($beforePath) ? file($beforePath, FILE_IGNORE_NEW_LINES) : [];
$after = file_exists($afterPath) ? file($afterPath, FILE_IGNORE_NEW_LINES) : [];
echo json_encode(['before' => $before, 'after' => $after]);
