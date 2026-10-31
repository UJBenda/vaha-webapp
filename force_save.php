<?php
// Endpoint pro fyzické tlačítko u váhy (WiFi relé typu Shelly/Sonoff,
// USB tlačítko se skriptem, tablet v kabince obsluhy apod.) - zavoláním
// (GET nebo POST) požádá weighing_daemon.php, aby při nejbližším cyklu
// uložil aktuální váhu OKAMŽITĚ, bez čekání na automatickou stabilizaci.
//
// Použití: nakonfiguruj tlačítko, ať při stisku zavolá
//   https://vaha.micharnaubusinek.cz/force_save.php?token=XXXXX
//
// Bez přihlašovací session (tlačítko není prohlížeč) - chráněno tokenem
// (VAHA_FORCE_SAVE_TOKEN) + IP omezením v Apache (stejně jako zbytek webu).

$config = require __DIR__ . '/config.php';
require __DIR__ . '/weighing_lib.php';

header('Content-Type: application/json');

$token = $_GET['token'] ?? $_POST['token'] ?? '';
if ($token === '' || !hash_equals($config['force_save_token'], $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Neplatný token']);
    exit;
}

try {
    $db = $config['db'];
    $pdo = new PDO("pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}", $db['user'], $db['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB chyba: ' . $e->getMessage()]);
    exit;
}

$settings = loadSettings($pdo, ['min_weight_kg' => 500]);
$currentWeight = getCurrentWeight($config);

if ($currentWeight === null || $currentWeight < $settings['min_weight_kg']) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => "Na váze musí být alespoň {$settings['min_weight_kg']} kg (aktuálně: "
            . ($currentWeight !== null ? $currentWeight . ' kg' : 'neznámo') . ')',
    ]);
    exit;
}

if (@file_put_contents($config['force_save_flag'], 'physical') === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Nelze vytvořit příznak - zkontroluj oprávnění k souboru']);
    exit;
}

echo json_encode(['ok' => true]);
