<?php
// Cron vstupní bod pro syncCameras() (viz camera_sync.php).
// Spouštět z cronu, např. každou minutu:
//   * * * * * php /var/www/vaha/sync_cameras.php >> /var/log/vaha-sync-cameras.log 2>&1
//
// Okamžitá synchronizace po úpravě kamery v adminu proběhne i bez cronu
// (admin_api.php volá syncCameras() rovnou), tenhle skript je pojistka
// pro případ restartu MediaMTX kontejneru nebo ruční změny v DB.

$config = require __DIR__ . '/config.php';
require __DIR__ . '/mediamtx_client.php';
require __DIR__ . '/camera_sync.php';

$db = $config['db'];

try {
    $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}";
    $pdo = new PDO($dsn, $db['user'], $db['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    fwrite(STDERR, "DB chyba: " . $e->getMessage() . "\n");
    exit(1);
}

foreach (syncCameras($pdo) as $line) {
    echo $line . "\n";
}
