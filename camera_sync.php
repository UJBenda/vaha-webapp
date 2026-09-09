<?php
// Sdílená logika pro práci s kamerami: sestavení RTSP zdrojové URL ze
// šablony a synchronizace tabulky "cameras" s MediaMTX Control API.
//
// Tabulka "cameras" je jediný zdroj pravdy pro to, co má v MediaMTX běžet -
// syncCameras() smaže úplně KAŽDÝ path, který v MediaMTX existuje, ale
// neodpovídá žádné aktivní kameře v DB (typicky staré ručně přidané cesty
// z mediamtx.yml). Cesty spravované touto appkou mají vždy jméno "cam" + id
// kamery (např. "cam3"). Frontend i weighing_daemon.php vždy dostávají
// jméno path od appky (get_cameras.php / DB), nikdy si ho samy neodvozují.
//
// camera_templates.url_template podporuje dvě sady placeholderů (kvůli
// starším šablonám zadaným ručně): {ip_address}/{username}/{password}
// a kratší {ip}/{user}/{pass}, např.:
//   rtsp://{username}:{password}@{ip_address}/stream1
//   rtsp://{user}:{pass}@{ip}/stream1

function mediamtxPathName(int $cameraId): string {
    return 'cam' . $cameraId;
}

function resolveCameraSource(array $camera): string {
    return str_replace(
        ['{ip_address}', '{username}', '{password}', '{ip}', '{user}', '{pass}'],
        [$camera['ip_address'], $camera['username'], $camera['password'], $camera['ip_address'], $camera['username'], $camera['password']],
        $camera['url_template']
    );
}

function syncCameras(PDO $pdo): array {
    $log = [];

    $sql = "SELECT c.id, c.name, c.ip_address, c.username, c.password, t.url_template
            FROM cameras c
            JOIN camera_templates t ON t.id = c.template_id
            WHERE c.is_active = true";
    $cameras = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $desiredPathNames = [];

    foreach ($cameras as $camera) {
        $pathName = mediamtxPathName((int)$camera['id']);
        $source = resolveCameraSource($camera);
        $desiredPathNames[$pathName] = true;

        $result = mediamtxUpsertPath($pathName, $source);
        if ($result['code'] >= 200 && $result['code'] < 300) {
            $log[] = "OK: $pathName ({$camera['name']})";
        } else {
            $log[] = "CHYBA u $pathName ({$camera['name']}): HTTP {$result['code']} {$result['error']} {$result['body']}";
        }
    }

    foreach (mediamtxListPathNames() as $name) {
        if (!isset($desiredPathNames[$name])) {
            mediamtxDeletePath($name);
            $log[] = "Smazáno: $name (není mezi aktivními kamerami v DB)";
        }
    }

    return $log;
}
