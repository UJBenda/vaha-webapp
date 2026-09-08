<?php
// Backend pro admin.html - přihlášení + CRUD nad "cameras" a "camera_templates".
// Autentizace: jedno sdílené heslo (VAHA_ADMIN_PASSWORD) + PHP session.
// Apache navíc omezuje přístup k celé appce podle IP (viz vaha.conf).

session_set_cookie_params(['samesite' => 'Strict']);
session_start();

$config = require __DIR__ . '/config.php';
require __DIR__ . '/mediamtx_client.php';
require __DIR__ . '/camera_sync.php';

header('Content-Type: application/json');

function respond($data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$rawInput = json_decode(file_get_contents('php://input'), true);
$input = is_array($rawInput) ? $rawInput : [];
$action = $_GET['action'] ?? $input['action'] ?? '';

// ----- Přihlášení / odhlášení (bez potřeby DB) -----

if ($action === 'login') {
    $password = (string)($input['password'] ?? '');
    if ($password !== '' && hash_equals($config['admin_password'], $password)) {
        $_SESSION['admin_ok'] = true;
        respond(['ok' => true]);
    }
    respond(['ok' => false, 'error' => 'Nesprávné heslo'], 401);
}

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    respond(['ok' => true]);
}

if ($action === 'check') {
    respond(['ok' => !empty($_SESSION['admin_ok'])]);
}

if (empty($_SESSION['admin_ok'])) {
    respond(['ok' => false, 'error' => 'Nepřihlášeno'], 401);
}

// ----- Vše pod touto čárou vyžaduje přihlášení -----

try {
    $db = $config['db'];
    $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}";
    $pdo = new PDO($dsn, $db['user'], $db['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    respond(['ok' => false, 'error' => 'DB chyba: ' . $e->getMessage()], 500);
}

switch ($action) {

    case 'list_cameras': {
        $sql = "SELECT c.*, t.brand_name FROM cameras c
                LEFT JOIN camera_templates t ON t.id = c.template_id
                ORDER BY c.id";
        respond(['ok' => true, 'items' => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'save_camera': {
        $id = $input['id'] ?? null;
        $name = trim((string)($input['name'] ?? ''));
        $templateId = $input['template_id'] ?? null;
        $ip = trim((string)($input['ip_address'] ?? ''));
        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        // (int) místo bool - PDO by jinak PHP `false` odeslalo jako prázdný
        // string a Postgres to u sloupce typu boolean odmítne.
        $isActive = (int)!empty($input['is_active']);
        $isWeighingPhoto = (int)!empty($input['is_weighing_photo']);
        $isLpr = (int)!empty($input['is_lpr']);
        $showOnDashboard = (int)!empty($input['show_on_dashboard']);

        if ($name === '' || $ip === '' || $username === '' || empty($templateId)) {
            respond(['ok' => false, 'error' => 'Chybí povinné pole (jméno, šablona, IP, uživatel)'], 400);
        }

        if ($id) {
            // Prázdné heslo při editaci = ponechat stávající.
            if ($password === '') {
                $stmt = $pdo->prepare('SELECT password FROM cameras WHERE id = ?');
                $stmt->execute([$id]);
                $password = (string)$stmt->fetchColumn();
            }
            $sql = "UPDATE cameras SET name=?, template_id=?, ip_address=?, username=?, password=?,
                    is_active=?, is_weighing_photo=?, is_lpr=?, show_on_dashboard=? WHERE id=?";
            $pdo->prepare($sql)->execute([
                $name, $templateId, $ip, $username, $password,
                $isActive, $isWeighingPhoto, $isLpr, $showOnDashboard, $id,
            ]);
        } else {
            if ($password === '') {
                respond(['ok' => false, 'error' => 'Heslo je povinné u nové kamery'], 400);
            }
            $sql = "INSERT INTO cameras (name, template_id, ip_address, username, password,
                    is_active, is_weighing_photo, is_lpr, show_on_dashboard)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([
                $name, $templateId, $ip, $username, $password,
                $isActive, $isWeighingPhoto, $isLpr, $showOnDashboard,
            ]);
        }

        respond(['ok' => true, 'sync_log' => syncCameras($pdo)]);
    }

    case 'delete_camera': {
        $id = $input['id'] ?? null;
        if (!$id) {
            respond(['ok' => false, 'error' => 'Chybí id'], 400);
        }
        $pdo->prepare('DELETE FROM cameras WHERE id = ?')->execute([$id]);
        respond(['ok' => true, 'sync_log' => syncCameras($pdo)]);
    }

    case 'sync_now': {
        respond(['ok' => true, 'sync_log' => syncCameras($pdo)]);
    }

    case 'list_templates': {
        $items = $pdo->query('SELECT * FROM camera_templates ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        respond(['ok' => true, 'items' => $items]);
    }

    case 'save_template': {
        $id = $input['id'] ?? null;
        $brand = trim((string)($input['brand_name'] ?? ''));
        $urlTemplate = trim((string)($input['url_template'] ?? ''));
        $notes = (string)($input['notes'] ?? '');

        if ($brand === '' || $urlTemplate === '') {
            respond(['ok' => false, 'error' => 'Chybí povinné pole (výrobce, šablona URL)'], 400);
        }

        if ($id) {
            $pdo->prepare('UPDATE camera_templates SET brand_name=?, url_template=?, notes=? WHERE id=?')
                ->execute([$brand, $urlTemplate, $notes, $id]);
        } else {
            $pdo->prepare('INSERT INTO camera_templates (brand_name, url_template, notes) VALUES (?, ?, ?)')
                ->execute([$brand, $urlTemplate, $notes]);
        }

        respond(['ok' => true]);
    }

    case 'delete_template': {
        $id = $input['id'] ?? null;
        if (!$id) {
            respond(['ok' => false, 'error' => 'Chybí id'], 400);
        }
        try {
            $pdo->prepare('DELETE FROM camera_templates WHERE id = ?')->execute([$id]);
        } catch (PDOException $e) {
            respond(['ok' => false, 'error' => 'Šablonu nejde smazat - používá ji nějaká kamera.'], 409);
        }
        respond(['ok' => true]);
    }

    // Vyfotí aktuální snímek z kamery přes FFmpeg a vrátí ho jako base64
    // (bez ukládání na disk) - pro rychlé ověření přihlašovacích údajů/URL.
    case 'test_camera': {
        $id = $input['id'] ?? null;
        if (!$id) {
            respond(['ok' => false, 'error' => 'Chybí id'], 400);
        }

        $stmt = $pdo->prepare("SELECT c.ip_address, c.username, c.password, t.url_template
                                FROM cameras c JOIN camera_templates t ON t.id = c.template_id
                                WHERE c.id = ?");
        $stmt->execute([$id]);
        $camera = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$camera) {
            respond(['ok' => false, 'error' => 'Kamera nenalezena'], 404);
        }

        $rtspUrl = resolveCameraSource($camera);
        $tempImage = sys_get_temp_dir() . '/vaha_cam_test_' . $id . '_' . time() . '.jpg';
        $tlsVerifyFlag = str_starts_with($rtspUrl, 'rtsps://') ? '-tls_verify 0 ' : '';

        $command = sprintf(
            'ffmpeg -rtsp_transport tcp %s-i %s -vframes 1 -q:v 2 -t 5 %s 2>&1',
            $tlsVerifyFlag,
            escapeshellarg($rtspUrl),
            escapeshellarg($tempImage)
        );

        $output = [];
        $returnVar = -1;
        exec($command, $output, $returnVar);

        if ($returnVar === 0 && file_exists($tempImage) && filesize($tempImage) > 0) {
            $base64 = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tempImage));
            @unlink($tempImage);
            respond(['ok' => true, 'image' => $base64]);
        }

        @unlink($tempImage);
        respond(['ok' => false, 'error' => 'FFmpeg selhal', 'log' => implode("\n", $output)], 502);
    }

    // app_settings - obecný key/value editor. Standardní klíče pro
    // weighing_daemon.php se založí s výchozími hodnotami, pokud v DB
    // ještě nejsou (aby se admin dal použít bez ruční SQL přípravy).
    case 'list_settings': {
        $defaults = [
            'poll_interval_sec' => ['1', 'Jak často (v sekundách) se čte váha z terminálu'],
            'stability_time_sec' => ['20', 'Kolik sekund musí být váha stabilní, než se uloží'],
            'stability_margin_kg' => ['50', 'Tolerance kolísání váhy (kg), která se ještě počítá jako "stabilní"'],
            'min_weight_kg' => ['500', 'Od jaké váhy (kg) se považuje, že najelo vozidlo'],
            'min_weight_reset_kg' => ['400', 'Pod jakou váhou (kg) se považuje, že vozidlo odjelo'],
        ];
        $existing = $pdo->query('SELECT setting_key FROM app_settings')->fetchAll(PDO::FETCH_COLUMN);
        $insert = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, description) VALUES (?, ?, ?)');
        foreach ($defaults as $key => [$value, $description]) {
            if (!in_array($key, $existing, true)) {
                $insert->execute([$key, $value, $description]);
            }
        }

        $items = $pdo->query('SELECT * FROM app_settings ORDER BY setting_key')->fetchAll(PDO::FETCH_ASSOC);
        respond(['ok' => true, 'items' => $items]);
    }

    case 'save_settings': {
        $settings = $input['settings'] ?? null;
        if (!is_array($settings)) {
            respond(['ok' => false, 'error' => 'Chybí settings'], 400);
        }
        $update = $pdo->prepare('UPDATE app_settings SET setting_value = ? WHERE setting_key = ?');
        foreach ($settings as $key => $value) {
            $update->execute([(string)$value, (string)$key]);
        }
        respond(['ok' => true]);
    }

    default:
        respond(['ok' => false, 'error' => 'Neznámá akce'], 400);
}
