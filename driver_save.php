<?php
// Endpoint pro tlačítko "Vážení řidič" na dashboardu (index.html) - stejná
// rutina jako force_save.php (okamžité uložení bez čekání na stabilizaci),
// jen jiný zdroj příznaku, aby šlo v grafu odlišit modrou čarou od
// červené (fyzické tlačítko u váhy). Bez tokenu - stránka je dostupná jen
// ze stejného původu jako zbytek dashboardu (chráněno IP omezením v
// Apache stejně jako get_history.php apod.), žádná session navíc není
// potřeba.

$config = require __DIR__ . '/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Použij POST']);
    exit;
}

if (@file_put_contents($config['force_save_flag'], 'driver') === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Nelze vytvořit příznak - zkontroluj oprávnění k souboru']);
    exit;
}

echo json_encode(['ok' => true]);
