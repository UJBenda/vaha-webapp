<?php
// Funkce používané weighing_daemon.php. Vytažené do samostatného souboru,
// aby šly otestovat (require) bez spuštění nekonečné smyčky démona.

// ----- Načtení nastavení z app_settings (s fallbackem na výchozí hodnoty) -----
function loadSettings(PDO $pdo, array $defaults): array {
    $settings = $defaults;
    try {
        $rows = $pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($defaults as $key => $default) {
            if (isset($rows[$key]) && $rows[$key] !== '' && is_numeric($rows[$key])) {
                $settings[$key] = $rows[$key] + 0; // zachová int/float podle výchozí hodnoty
            }
        }
    } catch (PDOException $e) {
        echo "Nastavení se nepodařilo načíst z DB, používám výchozí hodnoty: " . $e->getMessage() . "\n";
    }
    return $settings;
}

// ----- Přečte aktuální váhu z live_weight_file (stejný soubor, co
// zobrazuje "Aktuální váha" na dashboardu) - používají force_save.php a
// driver_save.php pro kontrolu proti min_weight_kg před uložením.
function getCurrentWeight(array $config): ?float {
    $raw = @file_get_contents($config['live_weight_file']);
    if ($raw === false) {
        return null;
    }
    $raw = trim($raw);
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    return (float)$raw;
}

// ----- Pořízení snímku z kamery přes FFmpeg -----
// Vrací ['web_path' => ..., 'filesystem_path' => ...] nebo null při chybě.
function takeSnapshot(string $rtspUrl, string $storagePath, string $webPrefix): ?array {
    if (empty($rtspUrl)) {
        return null;
    }

    $dateDirectory = date('Y/m/d');
    $fullStorageDirectory = $storagePath . $dateDirectory;

    if (!is_dir($fullStorageDirectory)) {
        if (!mkdir($fullStorageDirectory, 0774, true)) {
            echo "Chyba: Nelze vytvořit adresář: $fullStorageDirectory\n";
            return null;
        }
        echo "Vytvořen adresář: $fullStorageDirectory\n";
    }

    $filename = 'snap_' . date('His') . '_' . uniqid() . '.jpg';
    $filesystemPath = $fullStorageDirectory . '/' . $filename;
    $webPath = $webPrefix . $dateDirectory . '/' . $filename;

    $command = sprintf(
        'ffmpeg -rtsp_transport tcp -i %s -vframes 1 -q:v 2 -t 5 %s 2>&1',
        escapeshellarg($rtspUrl),
        escapeshellarg($filesystemPath)
    );

    echo "Spouštím FFmpeg: $command\n";
    $output = [];
    $returnVar = -1;
    exec($command, $output, $returnVar);

    if ($returnVar === 0 && file_exists($filesystemPath) && filesize($filesystemPath) > 0) {
        echo "Fotka uložena: $filesystemPath\n";
        return ['web_path' => $webPath, 'filesystem_path' => $filesystemPath];
    }

    echo "Chyba při spuštění FFmpeg (kód: $returnVar), soubor smazán.\n";
    echo "Výstup FFmpeg: " . implode("\n", $output) . "\n";
    @unlink($filesystemPath);
    return null;
}

// ----- Rozpoznání SPZ -----
// Podle lpr_mode kamery buď přes QR kód na vozidle (rychlé, spolehlivé -
// viz read_qr.py, hledá payload ve tvaru "SPZ:xxxxxxx"), přes OCR
// (EasyOCR - viz read_spz.py), nebo "both" - nejdřív QR, a pokud auto
// nemá/nejde přečíst nálepku, spadne zpět na OCR.
function runRecognitionScript(string $imagePath, string $scriptPath): ?string {
    $command = sprintf('python3 %s %s 2>&1', escapeshellarg($scriptPath), escapeshellarg($imagePath));
    $output = [];
    $returnVar = -1;
    exec($command, $output, $returnVar);

    $firstLine = trim($output[0] ?? '');
    if ($returnVar !== 0 || $firstLine === '' || $firstLine === 'NENALEZENO_DLE_FILTRU' || str_starts_with($firstLine, 'CHYBA')) {
        if ($returnVar !== 0) {
            echo "Skript $scriptPath selhal (kód $returnVar): " . implode(' | ', $output) . "\n";
        }
        return null;
    }
    return $firstLine;
}

function readLicensePlate(string $imagePath, array $config, string $lprMode): ?string {
    if ($lprMode === 'qr' || $lprMode === 'both') {
        $spz = runRecognitionScript($imagePath, $config['read_qr_script']);
        if ($spz !== null) {
            return $spz;
        }
        if ($lprMode === 'qr') {
            return null;
        }
    }
    if ($lprMode === 'ocr' || $lprMode === 'both') {
        return runRecognitionScript($imagePath, $config['read_spz_script']);
    }
    return null;
}

// ----- Uložení vážení do DB, vrací id nového řádku -----
// $isStabilized = false pro vážení, které se neustálilo (auto odjelo
// předčasně) - viz weighing_daemon.php, ukládá se i tak kvůli fotce a grafu.
function saveWeight(PDO $pdo, float $weight, bool $isStabilized = true): ?int {
    try {
        $stmt = $pdo->prepare("INSERT INTO weighings (weight, timestamp, is_stabilized) VALUES (?, DEFAULT, ?) RETURNING id");
        $stmt->execute([$weight, (int)$isStabilized]);
        $id = (int)$stmt->fetchColumn();
        echo "Uloženo: $weight kg (id $id)" . ($isStabilized ? "" : " [NEUSTÁLENO]") . "\n";
        return $id;
    } catch (PDOException $e) {
        echo "DB Chyba při uložení: " . $e->getMessage() . "\n";
        return null;
    }
}

// ----- Dočasná fotka na začátku vážení -----
// Pořídí se hned při detekci vozidla (IDLE -> WEIGHING), ještě než víme, jestli
// se váha ustálí. Pokud se vážení ustálí normálně, je zbytečná a smaže se
// (viz weighing_daemon.php). Pokud auto odjede bez ustálení, použije se jako
// jediný záznam o tom, kdo na váze byl.
function takeStartPhoto(PDO $pdo, array $config): ?array {
    $camera = $pdo->query(
        "SELECT c.ip_address, c.username, c.password, c.channel, c.substream, t.url_template
         FROM cameras c
         JOIN camera_templates t ON t.id = c.template_id
         WHERE c.is_active = true AND c.is_weighing_photo = true
         ORDER BY c.id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$camera) {
        return null;
    }

    $rtspUrl = resolveCameraSource($camera);
    return takeSnapshot($rtspUrl, $config['photo_storage_path'], $config['photo_web_path_prefix']);
}

// ----- Graf průběhu vážení (weighing_readings) -----
//
// Během WEIGHING fáze se jednotlivá čtení drží v paměti (weighing_id ještě
// neexistuje) a uloží se naráz přes saveWeighingReadings() ve chvíli, kdy se
// vážení uloží. Během LOCKED fáze (čeká se na odjezd) už weighing_id známe,
// takže se každé čtení ukládá rovnou přes insertWeighingReading().

// Uloží dávku čtení (pole ['time' => 'Y-m-d H:i:s.u', 'weight' => float,
// 'is_stable' => bool, 'is_button_press' => bool, 'is_driver_press' =>
// bool (nepovinné, default false)]) k jednomu vážení.
// Stejně jako saveWeight() chybu jen zaloguje a nehodí ji dál - záznam pro
// graf je vedlejší údaj, nesmí kvůli němu spadnout celý démon (a tím pádem
// i hlavní vážení). Viz incident 16.10.2026: chybějící GRANT na tuto
// tabulku shodil démon při každém INSERTu, systemd ho pořád dokola
// restartoval a každý restart vyrobil duplicitní vážení pro stojící auto.
function saveWeighingReadings(PDO $pdo, int $weighingId, array $readings): void {
    if (empty($readings)) {
        return;
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO weighing_readings (weighing_id, reading_time, weight, is_stable, is_button_press, is_driver_press)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($readings as $reading) {
            $stmt->execute([
                $weighingId,
                $reading['time'],
                $reading['weight'],
                (int)$reading['is_stable'],
                (int)$reading['is_button_press'],
                (int)($reading['is_driver_press'] ?? false),
            ]);
        }
    } catch (PDOException $e) {
        echo "DB Chyba při ukládání průběhu vážení: " . $e->getMessage() . "\n";
    }
}

// Uloží jedno čtení rovnou (weighing_id už existuje).
function insertWeighingReading(PDO $pdo, int $weighingId, float $weight, bool $isStable, bool $isButtonPress, bool $isDriverPress = false): void {
    try {
        $pdo->prepare(
            'INSERT INTO weighing_readings (weighing_id, reading_time, weight, is_stable, is_button_press, is_driver_press)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $weighingId,
            (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            $weight,
            (int)$isStable,
            (int)$isButtonPress,
            (int)$isDriverPress,
        ]);
    } catch (PDOException $e) {
        echo "DB Chyba při ukládání čtení vážení: " . $e->getMessage() . "\n";
    }
}

// ----- Foto + SPZ pro všechny aktivní kamery určené k fotografování při vážení -----
function captureWeighingPhotos(PDO $pdo, int $weighingId, array $config): void {
    $sql = "SELECT c.id, c.name, c.ip_address, c.username, c.password, c.channel, c.substream, c.is_weighing_photo, c.lpr_mode, t.url_template
            FROM cameras c
            JOIN camera_templates t ON t.id = c.template_id
            WHERE c.is_active = true AND (c.is_weighing_photo = true OR c.lpr_mode != 'off')
            ORDER BY c.id";
    $cameras = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cameras)) {
        echo "Žádná kamera není nastavena pro foto/SPZ při vážení.\n";
        return;
    }

    $insertPhoto = $pdo->prepare('INSERT INTO weighing_photos (weighing_id, camera_id, photo_path) VALUES (?, ?, ?)');
    $primaryPhotoSet = false;

    foreach ($cameras as $camera) {
        $rtspUrl = resolveCameraSource($camera);
        $snapshot = takeSnapshot($rtspUrl, $config['photo_storage_path'], $config['photo_web_path_prefix']);

        if ($snapshot === null) {
            echo "Kamera '{$camera['name']}': snímek se nepodařilo pořídit.\n";
            continue;
        }

        $insertPhoto->execute([$weighingId, $camera['id'], $snapshot['web_path']]);

        // Zpětná kompatibilita - první "foto při vážení" kamera se navíc
        // uloží i do weighings.photo_path (to, co zobrazuje historie na webu).
        if (!$primaryPhotoSet && $camera['is_weighing_photo']) {
            $pdo->prepare('UPDATE weighings SET photo_path = ? WHERE id = ?')
                ->execute([$snapshot['web_path'], $weighingId]);
            $primaryPhotoSet = true;
        }

        if ($camera['lpr_mode'] !== 'off') {
            $spz = readLicensePlate($snapshot['filesystem_path'], $config, $camera['lpr_mode']);
            if ($spz !== null) {
                $pdo->prepare('UPDATE weighings SET spz = ? WHERE id = ?')->execute([$spz, $weighingId]);
                echo "SPZ rozpoznána (kamera '{$camera['name']}', režim '{$camera['lpr_mode']}'): $spz\n";
            } else {
                echo "SPZ se z kamery '{$camera['name']}' nepodařilo rozpoznat (režim '{$camera['lpr_mode']}').\n";
            }
        }
    }
}

// ----- Komunikace s váhou - podporované protokoly -----
//
// 'generic_csv': jednoduchý dotaz/odpověď (výchozí, odpovídá původnímu
//   natvrdo napsanému chování appky) - dotaz "MSV?;\r\n", odpověď = čárkou
//   oddělené hodnoty, první pole je váha (číslo).
//
// 'it1_the': SysTec IT1 - "Online Mode SysTec Standard" (Technical Manual
//   IT1 Rev. 3, kap. 18). Terminál musí být přepnutý do režimu ONLINE
//   (tlačítko Return v hlavním zobrazení). Dotaz "<RM1>" = "Read Weight (in
//   motion)" - vrátí váhu okamžitě i neustálenou (vlastní stabilizaci řeší
//   weighing_daemon.php sám pomocí stability_time_sec/stability_margin_kg,
//   nemá smysl čekat až 10s na terminálu jako u "RN"). Odpověď je 62bajtový
//   řetězec s pevnou šířkou polí orámovaný '<' '>' (viz kap. 18.3):
//   byte 1-2 = chybový kód ("00" = OK), byte 23-30 = hrubá váha (gross) -
//   appka netáruje, celé vozidlo stojí na můstku od prázdného po naložený
//   stav, takže "váha" appky odpovídá hrubé váze terminálu.
//
// 'it1_continuous': SysTec IT1 - "Continuous Output", "SysTec Protocol"
//   (Technical Manual IT1 Rev. 3, kap. 20.1). Terminál sám bez vyzvání
//   průběžně posílá 15znakový řetězec (+CR LF): byte 1 = 'S' (vždy), byte 2
//   = stav (mezera = ustáleno, 'D' = v pohybu), byte 3-12 = váha (10 znaků),
//   byte 13 = mezera, byte 14-15 = jednotka. Appka nic neposílá (terminál na
//   vstup v tomhle režimu nereaguje) a jen přečte další řádek ze streamu.
const SCALE_PROTOCOLS = ['generic_csv', 'it1_the', 'it1_continuous'];

const IT1_THE_ERROR_CODES = [
    '00' => 'OK',
    '11' => 'Obecná chyba váhy (např. není připojen snímač)',
    '12' => 'Přetížení váhy',
    '13' => 'Váha se nestabilizovala do 10 s',
    '15' => 'Chyba tárování/nulování',
    '31' => 'Chyba přenosu (např. příliš dlouhý řetězec nebo timeout)',
    '32' => 'Neplatný příkaz',
    '33' => 'Neplatný parametr',
];

function buildScaleCommand(string $protocol): string {
    if ($protocol === 'it1_the') {
        return '<RM1>';
    }
    if ($protocol === 'it1_continuous') {
        // Continuous output - terminál posílá data sám, bez dotazu.
        return '';
    }
    return "MSV?;\r\n";
}

function parseIT1TheResponse(string $response, ?string &$errorMessage = null): ?float {
    $content = trim($response);
    if ($content !== '' && $content[0] === '<') {
        $content = substr($content, 1);
    }
    $gtPos = strpos($content, '>');
    if ($gtPos !== false) {
        $content = substr($content, 0, $gtPos);
    }

    // Gross (hrubá váha) končí na bajtu 30 (offset 22, délka 8) - viz
    // Technical Manual IT1 Rev. 3, str. 100, tabulka "Response" pro RN/RM.
    if (strlen($content) < 30) {
        $errorMessage = 'Odpověď je příliš krátká (očekává se min. 30 znaků, viz Technical Manual IT1 kap. 18.3)';
        return null;
    }

    $errorCode = substr($content, 0, 2);
    if ($errorCode !== '00') {
        $errorMessage = "Váha vrátila chybu $errorCode: " . (IT1_THE_ERROR_CODES[$errorCode] ?? 'neznámý kód');
        return null;
    }

    $gross = str_replace(',', '.', trim(substr($content, 22, 8)));
    if ($gross === '' || !is_numeric($gross)) {
        $errorMessage = "Pole 'Gross' se nepodařilo rozparsovat jako číslo: '$gross'";
        return null;
    }

    return (float)$gross;
}

function parseIT1ContinuousResponse(string $response, ?string &$errorMessage = null): ?float {
    $content = rtrim($response, "\r\n");

    // Minimálně start znak 'S' + stav + 10znaková váha (byte 13 mezera a
    // jednotka nejsou pro parsování potřeba, ale bez nich by to nebyla
    // platná odpověď podle kap. 20.1).
    if (strlen($content) < 12) {
        $errorMessage = 'Odpověď je příliš krátká pro SysTec continuous output formát (očekává se min. 12 znaků, viz Technical Manual IT1 kap. 20.1)';
        return null;
    }
    if ($content[0] !== 'S') {
        $errorMessage = "Odpověď nezačíná znakem 'S' - neodpovídá formátu SysTec continuous output: '$content'";
        return null;
    }

    $weight = str_replace(',', '.', trim(substr($content, 2, 10)));
    if ($weight === '' || !is_numeric($weight)) {
        $errorMessage = "Pole váhy se nepodařilo rozparsovat jako číslo: '$weight'";
        return null;
    }

    return (float)$weight;
}

function parseGenericCsvResponse(string $response, ?string &$errorMessage = null): ?float {
    $parts = explode(',', trim($response));
    if (isset($parts[0]) && is_numeric(trim($parts[0]))) {
        return (float)trim($parts[0]);
    }
    $errorMessage = "Odpověď se nepodařilo rozparsovat jako čárkou oddělené hodnoty: '$response'";
    return null;
}

function parseScaleResponse(string $protocol, string $response, ?string &$errorMessage = null): ?float {
    if ($protocol === 'it1_the') {
        return parseIT1TheResponse($response, $errorMessage);
    }
    if ($protocol === 'it1_continuous') {
        return parseIT1ContinuousResponse($response, $errorMessage);
    }
    return parseGenericCsvResponse($response, $errorMessage);
}

// ----- Vynulování váhy na dálku (Set Zero) -----
//
// Podporováno jen pro terminály SysTec IT1 (oba varianty - "Online mode" i
// "Continuous output" sdílí stejnou sadu příkazů "<xx>" podle kap. 18 mimo
// samotné pravidelné čtení váhy). "Set Zero" (SZ) přenastaví nulu váhy -
// appka netáruje (viz výše u 'it1_the'), takže tohle je jediná relevantní
// "nulovací" operace, ne "Automatic Tare" (TA), která by zavedla tárování.
//
// POZOR: u reálného terminálu nastaveného na "Continuous Output" není z
// manuálu jisté, jestli v tomhle režimu vůbec zpracovává příchozí příkazy
// souběžně se svým vlastním vysíláním - ověřeno jen čtení (RM), ne zápis
// (SZ). Je potřeba otestovat na reálném zařízení (admin.html to umožní).
function buildZeroCommand(string $protocol): ?string {
    // Jen 'it1_the' (Online mode, příkaz/odpověď) - v 'it1_continuous'
    // terminál na příchozí příkazy vůbec nereaguje (jen dál posílá svůj
    // continuous-output řádek), takže by appka vyhodnotila začátek téhle
    // odpovědi ("S...") jako chybový kód - ověřeno na reálném zařízení.
    if ($protocol === 'it1_the') {
        return '<SZ1>';
    }
    return null;
}

function parseZeroResponse(string $response, ?string &$errorMessage = null): bool {
    $content = trim($response);
    if ($content !== '' && $content[0] === '<') {
        $content = substr($content, 1);
    }
    $gtPos = strpos($content, '>');
    if ($gtPos !== false) {
        $content = substr($content, 0, $gtPos);
    }

    if (strlen($content) < 2) {
        $errorMessage = "Odpověď je příliš krátká na chybový kód (viz Technical Manual IT1 kap. 18.6): '$content'";
        return false;
    }

    $code = substr($content, 0, 2);
    if ($code === '00') {
        return true;
    }

    $errorMessage = "Váha vrátila chybu $code: " . (IT1_THE_ERROR_CODES[$code] ?? 'neznámý kód');
    return false;
}

// Pošle Set Zero na JIŽ OTEVŘENÉM socketu (weighing_daemon.php má s váhou
// navázané jediné spojení, otevírat druhé by u mnohých RS232/LAN
// převodníků selhalo - viz scale_relay.php).
function sendZeroCommand($socket, string $protocol, ?string &$rawOut = null, ?string &$errorMessage = null): bool {
    $command = buildZeroCommand($protocol);
    if ($command === null) {
        $errorMessage = "Protokol '$protocol' nepodporuje vynulování na dálku.";
        return false;
    }

    socket_write($socket, $command, strlen($command));
    $response = socket_read($socket, 1024);
    if ($response === false) {
        $errorMessage = 'Váha neodpověděla (spojení selhalo).';
        return false;
    }

    $rawOut = trim($response);
    return parseZeroResponse($rawOut, $errorMessage);
}

// Vyřídí požadavek na vynulování z webové vrstvy (admin_api.php i
// zero_scale.php na dashboardu) - appka se k váze sama nepřipojuje (druhé
// souběžné TCP spojení by u leckterého převodníku kolidovalo s tím, co drží
// weighing_daemon.php), místo toho založí příznak, který démon vyzvedne při
// svém příštím cyklu na SVÉM spojení, a čeká (až 10s) na výsledek.
//
// Appka sama žádnou podmínku "plošina musí být prázdná" nekontroluje -
// terminál IT1 má vlastní "zero range" (Technical Manual IT1 kap. 18.6),
// mimo který SZ sám odmítne s chybou 15 (viz IT1_THE_ERROR_CODES). To je
// žádoucí i legitimní použití: vynulování kompenzuje sníh/nečistoty na
// plošině nebo drift kalibrace, ne jen prázdnou váhu ukazující přesně 0.
function requestZeroScale(PDO $pdo, array $config): array {
    $scale = $pdo->query('SELECT protocol FROM scales WHERE active = true LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$scale) {
        return ['ok' => false, 'error' => 'Není nastavena žádná aktivní váha'];
    }
    if (buildZeroCommand($scale['protocol']) === null) {
        return ['ok' => false, 'error' => 'Aktivní protokol vynulování na dálku nepodporuje'];
    }

    $token = bin2hex(random_bytes(8));
    @unlink($config['zero_result_file']);
    file_put_contents($config['zero_request_flag'], $token);

    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        usleep(300000);
        $raw = @file_get_contents($config['zero_result_file']);
        if ($raw === false) {
            continue;
        }
        $result = json_decode($raw, true);
        if (is_array($result) && ($result['token'] ?? null) === $token) {
            return [
                'ok' => (bool)($result['ok'] ?? false),
                'raw' => $result['raw'] ?? null,
                'error' => $result['error'] ?? null,
            ];
        }
    }

    @unlink($config['zero_request_flag']);
    return ['ok' => false, 'error' => 'Váha neodpověděla do 10 s - běží weighing_daemon.php a má navázané spojení s váhou?'];
}

// ----- Aktivně vybraná váha (Administrace > Váhy) -----
// weighing_daemon.php sleduje vždy jen jednu váhu - tu s active = true.
// Pokud žádná není nastavená (čistá instalace, admin ještě nic nepřidal),
// vrátí null a démon na to má zareagovat čekáním, ne pádem.
function loadActiveScale(PDO $pdo): ?array {
    try {
        $row = $pdo->query('SELECT * FROM scales WHERE active = true ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        echo "Nastavení váhy se nepodařilo načíst z DB: " . $e->getMessage() . "\n";
        return null;
    }
}

// Funkce getWeightFromScale() - $rawOut (nepovinné, reference) dostane
// syrovou odpověď z váhy přesně tak, jak přišla (kvůli scale_relay.php,
// který ji přeposílá dál dodavatelskému SW).
function getWeightFromScale($socket, string $protocol, ?string &$rawOut = null): ?float {
    $command = buildScaleCommand($protocol);
    socket_write($socket, $command, strlen($command));
    $response = socket_read($socket, 1024);

    if ($response === false) {
        return null;
    }

    $rawOut = trim($response);
    return parseScaleResponse($protocol, $rawOut);
}
