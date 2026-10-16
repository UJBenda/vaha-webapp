<?php
// ----- NASTAVENÍ VÁHY (hardware, needituje se přes admin) -----
$scale_ip = '192.168.1.164';
$scale_port = 10001;
$scale_command = "MSV?;\r\n";

// Výchozí hodnoty logiky - přebijí se hodnotami z app_settings (viz
// loadSettings() ve weighing_lib.php), pokud tam admin něco nastaví. Načtou
// se znovu při každém navázání spojení s váhou (reconnect), restart démona
// tedy není nutný, ale doporučený pro jistotu.
$settingDefaults = [
    'poll_interval_sec' => 1,
    'stability_time_sec' => 20,
    'stability_margin_kg' => 50,
    'min_weight_kg' => 500,
    'min_weight_reset_kg' => 400,
];

$config = require __DIR__ . '/config.php';
require __DIR__ . '/camera_sync.php'; // resolveCameraSource()
require __DIR__ . '/weighing_lib.php';

// DB Připojení (PDO pro PostgreSQL)
try {
    $db = $config['db'];
    $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}";
    $pdo = new PDO($dsn, $db['user'], $db['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB Chyba: " . $e->getMessage());
}

// =================================================================
// ----- Hlavní smyčka démona -----
// =================================================================

$current_state = 'IDLE';
$last_stable_weight = 0;
$stable_counter = 0;
$sessionReadings = [];    // čtení z probíhajícího vážení, dokud nemá weighing_id
$activeWeighingId = null; // weighing_id, jakmile je vážení uložené (fáze LOCKED)

while (true) {
    // Nastavení se znovu načtou při každém (re)connectu k váze.
    $settings = loadSettings($pdo, $settingDefaults);
    $poll_interval_sec = $settings['poll_interval_sec'];
    $stability_time_sec = $settings['stability_time_sec'];
    $stability_margin_kg = $settings['stability_margin_kg'];
    $min_weight_kg = $settings['min_weight_kg'];
    $min_weight_reset_kg = $settings['min_weight_reset_kg'];

    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($socket === false) {
        echo "Nelze vytvořit socket\n";
        sleep(5);
        continue;
    }

    $conn = socket_connect($socket, $scale_ip, $scale_port);
    if ($conn === false) {
        echo "Nelze se připojit k váze $scale_ip:$scale_port\n";
        socket_close($socket);
        sleep(5);
        continue;
    }

    echo "Připojeno k váze. Stav: $current_state\n";

    while (true) {
        $weight = getWeightFromScale($socket, $scale_command, $rawResponse);

        if ($weight === null) {
            echo "Ztráta spojení nebo chyba dat. Rekonektuji...\n";
            socket_close($socket);
            sleep($poll_interval_sec);
            break;
        }

        file_put_contents($config['live_weight_file'], $weight);
        if ($rawResponse !== null) {
            file_put_contents($config['scale_raw_file'], $rawResponse);
        }

        // Ruční tlačítko u váhy (force_save.php) - uložit okamžitě, bez
        // ohledu na stav automatiky, a nečekat na stabilizaci.
        if (file_exists($config['force_save_flag'])) {
            @unlink($config['force_save_flag']);
            echo "Ruční tlačítko - vynucuji uložení ($weight kg)\n";

            $sessionReadings[] = [
                'time' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                'weight' => $weight,
                'is_stable' => false,
                'is_button_press' => true,
            ];

            $weighingId = saveWeight($pdo, $weight);
            if ($weighingId !== null) {
                saveWeighingReadings($pdo, $weighingId, $sessionReadings);
                captureWeighingPhotos($pdo, $weighingId, $config);
            }

            $sessionReadings = [];
            $activeWeighingId = $weighingId;
            $current_state = 'LOCKED';
            $stable_counter = 0;
            sleep($poll_interval_sec);
            continue;
        }

        switch ($current_state) {

            case 'IDLE':
                if ($weight >= $min_weight_kg) {
                    echo "Detekováno vážení, startuji... ($weight kg)\n";
                    $current_state = 'WEIGHING';
                    $stable_counter = 0;
                    $last_stable_weight = $weight;
                    $sessionReadings = [[
                        'time' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                        'weight' => $weight,
                        'is_stable' => false,
                        'is_button_press' => false,
                    ]];
                }
                break;

            case 'WEIGHING':
                if ($weight < $min_weight_reset_kg) {
                    echo "Auto odjelo (neuloženo), resetuji.\n";
                    $current_state = 'IDLE';
                    $sessionReadings = [];
                    break;
                }

                $isStableTick = abs($weight - $last_stable_weight) <= $stability_margin_kg;
                if ($isStableTick) {
                    $stable_counter++;
                    echo "Váha stabilní... $stable_counter/$stability_time_sec ($weight kg)\n";
                } else {
                    echo "Váha se pohnula, resetuji časovač. ($weight kg)\n";
                    $stable_counter = 0;
                    $last_stable_weight = $weight;
                }

                $sessionReadings[] = [
                    'time' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                    'weight' => $weight,
                    'is_stable' => $isStableTick,
                    'is_button_press' => false,
                ];

                if ($stable_counter >= $stability_time_sec) {
                    $weighingId = saveWeight($pdo, $weight);
                    if ($weighingId !== null) {
                        saveWeighingReadings($pdo, $weighingId, $sessionReadings);
                        captureWeighingPhotos($pdo, $weighingId, $config);
                    }

                    $sessionReadings = [];
                    $activeWeighingId = $weighingId;
                    $current_state = 'LOCKED';
                }
                break;

            case 'LOCKED':
                echo "Stav 'Uloženo'. Čekám na odjezd... ($weight kg)\n";
                if ($activeWeighingId !== null) {
                    insertWeighingReading($pdo, $activeWeighingId, $weight, false, false);
                }
                if ($weight < $min_weight_reset_kg) {
                    echo "Auto odjelo, systém připraven pro další vážení.\n";
                    $current_state = 'IDLE';
                    $activeWeighingId = null;
                }
                break;
        }

        sleep($poll_interval_sec);
    }
}
