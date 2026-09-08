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

// ----- Rozpoznání SPZ přes read_spz.py (EasyOCR) -----
function readLicensePlate(string $imagePath, string $scriptPath): ?string {
    $command = sprintf('python3 %s %s 2>&1', escapeshellarg($scriptPath), escapeshellarg($imagePath));
    $output = [];
    $returnVar = -1;
    exec($command, $output, $returnVar);

    $firstLine = trim($output[0] ?? '');
    if ($returnVar !== 0 || $firstLine === '' || $firstLine === 'NENALEZENO_DLE_FILTRU' || str_starts_with($firstLine, 'CHYBA')) {
        if ($returnVar !== 0) {
            echo "read_spz.py selhal (kód $returnVar): " . implode(' | ', $output) . "\n";
        }
        return null;
    }
    return $firstLine;
}

// ----- Uložení vážení do DB, vrací id nového řádku -----
function saveWeight(PDO $pdo, float $weight): ?int {
    try {
        $stmt = $pdo->prepare("INSERT INTO weighings (weight, timestamp) VALUES (?, DEFAULT) RETURNING id");
        $stmt->execute([$weight]);
        $id = (int)$stmt->fetchColumn();
        echo "Uloženo: $weight kg (id $id)\n";
        return $id;
    } catch (PDOException $e) {
        echo "DB Chyba při uložení: " . $e->getMessage() . "\n";
        return null;
    }
}

// ----- Foto + SPZ pro všechny aktivní kamery určené k fotografování při vážení -----
function captureWeighingPhotos(PDO $pdo, int $weighingId, array $config): void {
    $sql = "SELECT c.id, c.name, c.ip_address, c.username, c.password, c.is_weighing_photo, c.is_lpr, t.url_template
            FROM cameras c
            JOIN camera_templates t ON t.id = c.template_id
            WHERE c.is_active = true AND (c.is_weighing_photo = true OR c.is_lpr = true)
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

        if ($camera['is_lpr']) {
            $spz = readLicensePlate($snapshot['filesystem_path'], $config['read_spz_script']);
            if ($spz !== null) {
                $pdo->prepare('UPDATE weighings SET spz = ? WHERE id = ?')->execute([$spz, $weighingId]);
                echo "SPZ rozpoznána (kamera '{$camera['name']}'): $spz\n";
            } else {
                echo "SPZ se z kamery '{$camera['name']}' nepodařilo rozpoznat.\n";
            }
        }
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
