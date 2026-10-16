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

header('Content-Type: application/json');

$token = $_GET['token'] ?? $_POST['token'] ?? '';
if ($token === '' || !hash_equals($config['force_save_token'], $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Neplatný token']);
    exit;
}

if (@touch($config['force_save_flag']) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Nelze vytvořit příznak - zkontroluj oprávnění k souboru']);
    exit;
}

echo json_encode(['ok' => true]);
