<?php
// Endpoint pro tlačítko "Vynulovat váhu" na dashboardu (index.html), vedle
// tlačítka "Vážení řidič". Bez tokenu/přihlášení - stránka je dostupná jen
// ze stejného původu jako zbytek dashboardu (chráněno IP omezením v Apache
// stejně jako driver_save.php apod.), žádná admin session navíc není
// potřeba. Bezpečnostní pojistka (plošina musí být prázdná) je ve sdílené
// requestZeroScale() ve weighing_lib.php - stejná funkce, kterou používá i
// administrace.

$config = require __DIR__ . '/config.php';
require __DIR__ . '/weighing_lib.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Použij POST']);
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

$result = requestZeroScale($pdo, $config);
if (!$result['ok']) {
    http_response_code(400);
}
echo json_encode($result);
