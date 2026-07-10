<?php
// ----- NASTAVENÍ (zůstávají stejná) -----
$scale_ip = '192.168.1.164';
$scale_port = 10001;
$scale_command = "MSV?;\r\n";

// Logika
$poll_interval_sec = 1;
$stability_time_sec = 20;
$stability_margin_kg = 50;
$min_weight_kg = 500;
$min_weight_reset_kg = 400;

// DB (PostgreSQL)
$db_host = 'localhost';
$db_port = '5432';
$db_name = 'vaha';
$db_user = 'vaha';
$db_pass = 'eJzZX5nuc@9RqUXSX';

// Soubor pro živá data
$live_weight_file = '/var/www/vaha/live_weight.txt';

// ----- NOVÁ NASTAVENÍ PRO FOTKY (ZMĚNA) -----
// !!! ZDE ZADEJTE PŘÍMOU RTSP URL VAŠÍ KAMERY !!!
// Formát: rtsp://uzivatel:heslo@IP_ADRESA:PORT/cesta_ke_streamu
$camera_rtsp_url = 'rtsp://admin:lJuoMjn9R6iv@192.168.93.227/stream1'; 

// Cesta na disku (kam se ukládá) - Toto je KOŘENOVÁ složka
$photo_storage_path = '/var/www/vaha/photos/'; 
// Cesta, jak ji uvidí web (co se uloží do DB) - Toto je KOŘENOVÁ složka
$photo_web_path_prefix = 'photos/'; 
// ----------------------------------------

// DB Připojení (PDO pro PostgreSQL) - beze změny
try {
    $dsn = "pgsql:host=$db_host;port=$db_port;dbname=$db_name";
    $pdo = new PDO($dsn, $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB Chyba: ". $e->getMessage());
}

// ----- PŘEDĚLANÁ FUNKCE: Pořízení snímku (přes FFmpeg) -----
function takeSnapshot($rtsp_url, $storagePath, $webPrefix) {
    if (empty($rtsp_url) || $rtsp_url === 'rtsp://admin:lJuoMjn9R6iv@192.168.93.227/stream1') {
        echo "RTSP URL snímku není nastavena, fotka přeskočena.\n";
        return null;
    }
    
    // 1. Vytvoříme cestu YYYY/MM/DD (beze změny)
    $year = date('Y');
    $month = date('m');
    $day = date('d');
    $date_directory = "$year/$month/$day";

    // 2. Vytvoříme plnou cestu pro uložení na disk (beze změny)
    $full_storage_directory = $storagePath. $date_directory;

    // 3. Zkontrolujeme a vytvoříme složky (beze změny)
    if (!is_dir($full_storage_directory)) {
        if (!mkdir($full_storage_directory, 0774, true)) {
            echo "Chyba: Nelze vytvořit adresář: $full_storage_directory\n";
            return null;
        }
        echo "Vytvořen adresář: $full_storage_directory\n";
    }

    // 4. Sestavíme název souboru a finální cesty (beze změny)
    $filename = 'snap_'. date('His'). '_'. uniqid(). '.jpg';
    $filesystem_path = $full_storage_directory. '/'. $filename;
    $web_path = $webPrefix. $date_directory. '/'. $filename; 

    // 5. ZAVOLÁME FFMPEG PRO ULOŽENÍ SNÍMKU
    // -i "..."        -> Vstupní RTSP stream
    // -vframes 1      -> Uložit pouze 1 snímek
    // -q:v 2          -> Kvalita JPEGu (2 = vysoká)
    // -t 1            -> Timeout 1 sekunda na pořízení snímku (pro zrychlení)
    // "..."           -> Výstupní soubor
    // 2>&1            -> Přesměruje chybový výstup (stderr) na standardní (stdout)
    
    // Problém: Přihlašovací údaje v RTSP URL mohou obsahovat speciální znaky.
    // Dáme URL do uvozovek.
    $command = sprintf(
        'ffmpeg -i %s -vframes 1 -q:v 2 -t 1 %s 2>&1',
        escapeshellarg($rtsp_url), // Bezpečné vložení URL do příkazu
        escapeshellarg($filesystem_path) // Bezpečné vložení cesty k souboru
    );

    echo "Spouštím FFmpeg: $command\n";
    $output = [];
    $return_var = -1;
    
    // Použijeme exec() pro spuštění příkazu
    exec($command, $output, $return_var);

    // 6. Zkontrolujeme výsledek
    if ($return_var === 0 && file_exists($filesystem_path) && filesize($filesystem_path) > 0) {
        // Vše OK
        echo "Fotka uložena: $filesystem_path\n";
        return $web_path; // Vracíme webovou cestu pro DB
    } else {
        // Chyba
        echo "Chyba při spuštění FFmpeg (kód: $return_var), soubor smazán.\n";
        echo "Výstup FFmpeg: ". implode("\n", $output). "\n";
        @unlink($filesystem_path); 
        return null;
    }
}

// ----- UPRAVENÁ FUNKCE: Zápis do DB (beze změny) -----
function saveWeight($pdo, $weight, $photoPath) {
    $sql = "INSERT INTO weighings (weight, timestamp, photo_path) VALUES (?, DEFAULT, ?)";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$weight, $photoPath]);
        echo "Uloženo: $weight kg, Foto: $photoPath\n";
    } catch (PDOException $e) {
        echo "DB Chyba při uložení: ". $e->getMessage(). "\n";
    }
}

// Funkce getWeightFromScale() - beze změny
function getWeightFromScale($socket, $command) {
    socket_write($socket, $command, strlen($command));
    $response = socket_read($socket, 1024);
    
    if ($response === false) {
        return null;
    }

    $parts = explode(',', trim($response));
    if (isset($parts[0]) && is_numeric(trim($parts[0]))) {
        return (float)trim($parts[0]);
    }
    return null;
}

// =================================================================
// ----- Hlavní smyčka démona (BEZE ZMĚNY) -----
// =================================================================

$current_state = 'IDLE'; 
$last_stable_weight = 0;
$stable_counter = 0;

while (true) {
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
        $weight = getWeightFromScale($socket, $scale_command);

        if ($weight === null) {
            echo "Ztráta spojení nebo chyba dat. Rekonektuji...\n";
            socket_close($socket);
            sleep($poll_interval_sec);
            break; 
        }

        file_put_contents($live_weight_file, $weight);

        switch ($current_state) {
            
            case 'IDLE':
                if ($weight >= $min_weight_kg) {
                    echo "Detekováno vážení, startuji... ($weight kg)\n";
                    $current_state = 'WEIGHING';
                    $stable_counter = 0;
                    $last_stable_weight = $weight;
                }
                break;

            case 'WEIGHING':
                if ($weight < $min_weight_reset_kg) {
                    echo "Auto odjelo (neuloženo), resetuji.\n";
                    $current_state = 'IDLE';
                    break;
                }

                if (abs($weight - $last_stable_weight) <= $stability_margin_kg) {
                    $stable_counter++;
                    echo "Váha stabilní... $stable_counter/$stability_time_sec ($weight kg)\n";
                } else {
                    echo "Váha se pohnula, resetuji časovač. ($weight kg)\n";
                    $stable_counter = 0;
                    $last_stable_weight = $weight;
                }

                if ($stable_counter >= $stability_time_sec) {
                    
                    // Volání nové funkce
                    $photoPath = takeSnapshot($camera_rtsp_url, $photo_storage_path, $photo_web_path_prefix);
                    saveWeight($pdo, $weight, $photoPath);

                    $current_state = 'LOCKED'; 
                }
                break;

            case 'LOCKED':
                echo "Stav 'Uloženo'. Čekám na odjezd... ($weight kg)\n";
                if ($weight < $min_weight_reset_kg) {
                    echo "Auto odjelo, systém připraven pro další vážení.\n";
                    $current_state = 'IDLE';
                }
                break;
        }

        sleep($poll_interval_sec);
    }
}
?>
