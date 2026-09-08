<?php
// Synchronizuje tabulku "cameras" (DB) s běžícím MediaMTX kontejnerem.
// Pro každou aktivní kameru vytvoří/aktualizuje odpovídající "path" přes
// MediaMTX Control API a smaže paths, které appka spravovala dřív, ale
// kamera už je neaktivní/smazaná.
//
// Spouštět z cronu, např. každou minutu:
//   * * * * * php /var/www/vaha/sync_cameras.php >> /var/log/vaha-sync-cameras.log 2>&1
//
// Cesty spravované touto appkou mají vždy prefix "cam" + id kamery (např.
// "cam3"), aby se nikdy nesáhlo na ručně nastavené paths z mediamtx.yml
// (třeba "kamera", "kamera2").
//
// camera_templates.url_template používá placeholdery {ip_address},
// {username}, {password}, např.:
//   rtsp://{username}:{password}@{ip_address}/stream1

$config = require __DIR__ . '/config.php';
require __DIR__ . '/mediamtx_client.php';

$db = $config['db'];

try {
    $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}";
    $pdo = new PDO($dsn, $db['user'], $db['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    fwrite(STDERR, "DB chyba: " . $e->getMessage() . "\n");
    exit(1);
}

$sql = "SELECT c.id, c.name, c.ip_address, c.username, c.password, t.url_template
        FROM cameras c
        JOIN camera_templates t ON t.id = c.template_id
        WHERE c.is_active = true";
$cameras = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$desiredPathNames = [];

foreach ($cameras as $camera) {
    $pathName = 'cam' . $camera['id'];
    $source = str_replace(
        ['{ip_address}', '{username}', '{password}'],
        [$camera['ip_address'], $camera['username'], $camera['password']],
        $camera['url_template']
    );
    $desiredPathNames[$pathName] = true;

    $result = mediamtxUpsertPath($pathName, $source);
    if ($result['code'] >= 200 && $result['code'] < 300) {
        echo "OK: $pathName ({$camera['name']})\n";
    } else {
        echo "CHYBA u $pathName ({$camera['name']}): HTTP {$result['code']} {$result['error']} {$result['body']}\n";
    }
}

// Úklid: smažeme cesty spravované touto appkou, které už nejsou v desiredPathNames.
foreach (mediamtxListPathNames() as $name) {
    if (preg_match('/^cam\d+$/', $name) && !isset($desiredPathNames[$name])) {
        mediamtxDeletePath($name);
        echo "Smazáno: $name (kamera už není aktivní)\n";
    }
}
