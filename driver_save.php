<?php
// Endpoint pro tlačítko "Vážení řidič" na dashboardu (index.html) - stejná
// rutina jako force_save.php (okamžité uložení bez čekání na stabilizaci),
// jen jiný zdroj příznaku, aby šlo v grafu odlišit modrou čarou od
// červené (fyzické tlačítko u váhy). Bez tokenu - stránka je dostupná jen
// ze stejného původu jako zbytek dashboardu (chráněno IP omezením v
// Apache stejně jako get_history.php apod.), žádná session navíc není
// potřeba.
//
// GET  - jen zjistí aktuální stav cooldownu (pro inicializaci tlačítka
//        při načtení stránky), nic neukládá.
// POST - pokud není v cooldownu a na váze je aspoň min_weight_kg, uloží
//        vážení a nastaví cooldown.

$config = require __DIR__ . '/config.php';
require __DIR__ . '/weighing_lib.php';

header('Content-Type: application/json');

try {
    $db = $config['db'];
    $pdo = new PDO("pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}", $db['user'], $db['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB chyba: ' . $e->getMessage()]);
    exit;
}

$settings = loadSettings($pdo, ['min_weight_kg' => 500, 'driver_save_cooldown_sec' => 60]);
$cooldownSec = (int)$settings['driver_save_cooldown_sec'];

$lastPressRaw = @file_get_contents($config['driver_save_last_file']);
$lastPress = ($lastPressRaw !== false && trim($lastPressRaw) !== '') ? (int)trim($lastPressRaw) : 0;
$remaining = max(0, $cooldownSec - (time() - $lastPress));

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'cooldown_remaining_sec' => $remaining]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Použij POST']);
    exit;
}

if ($remaining > 0) {
    http_response_code(429);
    echo json_encode([
        'ok' => false,
        'error' => "Další vážení řidič bude možné za $remaining s",
        'cooldown_remaining_sec' => $remaining,
    ]);
    exit;
}

$currentWeight = getCurrentWeight($config);
if ($currentWeight === null || $currentWeight < $settings['min_weight_kg']) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => "Na váze musí být alespoň {$settings['min_weight_kg']} kg (aktuálně: "
            . ($currentWeight !== null ? $currentWeight . ' kg' : 'neznámo') . ')',
        'cooldown_remaining_sec' => 0,
    ]);
    exit;
}

if (@file_put_contents($config['force_save_flag'], 'driver') === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Nelze vytvořit příznak - zkontroluj oprávnění k souboru']);
    exit;
}

@file_put_contents($config['driver_save_last_file'], (string)time());

echo json_encode(['ok' => true, 'cooldown_remaining_sec' => $cooldownSec]);
