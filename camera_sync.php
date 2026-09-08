<?php
// Sdílená logika pro synchronizaci tabulky "cameras" s MediaMTX Control API.
// Používá ji sync_cameras.php (cron) i admin_api.php (okamžitá synchronizace
// po přidání/úpravě/smazání kamery).
//
// Cesty spravované touto appkou mají vždy prefix "cam" + id kamery (např.
// "cam3"), aby se nikdy nesáhlo na ručně nastavené paths z mediamtx.yml
// (třeba "kamera", "kamera2").
//
// camera_templates.url_template používá placeholdery {ip_address},
// {username}, {password}, např.:
//   rtsp://{username}:{password}@{ip_address}/stream1

function syncCameras(PDO $pdo): array {
    $log = [];

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
            $log[] = "OK: $pathName ({$camera['name']})";
        } else {
            $log[] = "CHYBA u $pathName ({$camera['name']}): HTTP {$result['code']} {$result['error']} {$result['body']}";
        }
    }

    foreach (mediamtxListPathNames() as $name) {
        if (preg_match('/^cam\d+$/', $name) && !isset($desiredPathNames[$name])) {
            mediamtxDeletePath($name);
            $log[] = "Smazáno: $name (kamera už není aktivní)";
        }
    }

    return $log;
}
