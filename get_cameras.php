<?php
// Vrátí seznam aktivních kamer, které se mají zobrazit na dashboardu.
// "path" odpovídá MediaMTX path jménu, které vytváří sync_cameras.php
// (prefix "cam" + id kamery), pro sestavení WHEP URL na frontendu.

$config = require __DIR__ . '/config.php';
$db = $config['db'];

try {
    $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}";
    $pdo = new PDO($dsn, $db['user'], $db['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "SELECT id, name, is_lpr
            FROM cameras
            WHERE is_active = true AND show_on_dashboard = true
            ORDER BY id";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $cameras = array_map(function ($row) {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'path' => 'cam' . $row['id'],
            'is_lpr' => (bool)$row['is_lpr'],
        ];
    }, $rows);

    header('Content-Type: application/json');
    echo json_encode($cameras);

} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}
