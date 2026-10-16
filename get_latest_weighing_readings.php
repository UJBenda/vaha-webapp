<?php
// Vrátí poslední vážení + celý průběh čtení váhy k němu (pro graf na
// dashboardu). Čtení se ukládají do weighing_readings - viz
// weighing_daemon.php (saveWeighingReadings / insertWeighingReading).

$config = require __DIR__ . '/config.php';
$db = $config['db'];

try {
    $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}";
    $pdo = new PDO($dsn, $db['user'], $db['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $weighing = $pdo->query(
        'SELECT id, weight, timestamp, spz, photo_path, is_stabilized FROM weighings ORDER BY id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');

    if (!$weighing) {
        echo json_encode(['weighing' => null, 'readings' => []]);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT reading_time, weight, is_stable, is_button_press
         FROM weighing_readings WHERE weighing_id = ? ORDER BY reading_time'
    );
    $stmt->execute([$weighing['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $readings = array_map(function ($r) {
        return [
            't' => (new DateTime($r['reading_time']))->format('Y-m-d\TH:i:s.v'),
            'w' => (float)$r['weight'],
            'stable' => (bool)$r['is_stable'],
            'button' => (bool)$r['is_button_press'],
        ];
    }, $rows);

    echo json_encode([
        'weighing' => [
            'id' => (int)$weighing['id'],
            'weight' => (float)$weighing['weight'],
            'timestamp' => (new DateTime($weighing['timestamp']))->format('Y-m-d\TH:i:s.v'),
            'spz' => $weighing['spz'],
            'photo_path' => $weighing['photo_path'],
            'is_stabilized' => ($weighing['is_stabilized'] === 't' || $weighing['is_stabilized'] === true),
        ],
        'readings' => $readings,
    ]);

} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}
