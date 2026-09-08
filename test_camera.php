<?php
// /var/www/vaha/test_camera.php

require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Chybí ID kamery.']);
    exit;
}

$id = (int)$_GET['id'];

try {
    $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Vytažení dat o kameře a její šabloně
    $stmt = $pdo->prepare("
        SELECT c.ip_address, c.username, c.password, t.url_template 
        FROM cameras c
        JOIN camera_templates t ON c.template_id = t.id
        WHERE c.id = ?
    ");
    $stmt->execute([$id]);
    $camera = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$camera) {
        echo json_encode(['status' => 'error', 'message' => 'Kamera nenalezena.']);
        exit;
    }

    // URL kódování jména a hesla (kdyby obsahovaly zavináč apod.)
    $user = urlencode($camera['username']);
    $pass = urlencode($camera['password']);
    $ip = $camera['ip_address'];

    // Nahrazení zástupných znaků v šabloně
    $rtsp_url = str_replace(
        ['{user}', '{pass}', '{ip}'],
        [$user, $pass, $ip],
        $camera['url_template']
    );

    // Vytvoření dočasné cesty pro uložení fotky (uložíme ji do /tmp)
    $temp_image = sys_get_temp_dir() . '/cam_test_' . $id . '_' . time() . '.jpg';

    // Detekce, zda se jedná o rtsps (vynutí vypnutí ověřování certifikátu)
    $tls_verify_flag = (strpos($rtsp_url, 'rtsps://') === 0) ? '-tls_verify 0 ' : '';

    // Sestavení příkazu pro FFmpeg s timeoutem 5 vteřin
    $command = sprintf(
        'ffmpeg -rtsp_transport tcp %s-i %s -vframes 1 -q:v 2 -t 5 %s 2>&1',
        $tls_verify_flag,
        escapeshellarg($rtsp_url),
        escapeshellarg($temp_image)
    );

    $output = [];
    $return_var = -1;
    exec($command, $output, $return_var);

    // Kontrola, zda FFmpeg uspěl a soubor existuje
    if ($return_var === 0 && file_exists($temp_image) && filesize($temp_image) > 0) {
        // Převedeme fotku na base64, abychom ji mohli poslat jako text přímo do prohlížeče
        $image_data = file_get_contents($temp_image);
        $base64 = 'data:image/jpeg;base64,' . base64_encode($image_data);
        
        // Smažeme dočasný soubor
        unlink($temp_image);

        echo json_encode([
            'status' => 'success', 
            'image' => $base64,
            'command' => $command // Posíláme i command pro případný debug
        ]);
    } else {
        // Chyba! Vrátíme log z FFmpegu
        if (file_exists($temp_image)) @unlink($temp_image);
        echo json_encode([
            'status' => 'error', 
            'message' => 'FFmpeg selhal.', 
            'log' => implode("\n", $output),
            'command' => $command
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Chyba skriptu: ' . $e->getMessage()]);
}
?>
