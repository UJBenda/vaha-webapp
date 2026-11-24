<?php
// Backend pro admin.html - přihlášení + CRUD nad "cameras" a "camera_templates".
// Autentizace: jedno sdílené heslo + PHP session. Heslo (hash, password_hash())
// žije v app_settings['admin_password_hash'] - VAHA_ADMIN_PASSWORD ve
// vaha.env je jen prvotní/záložní heslo pro čistě novou instalaci, při
// prvním úspěšném přihlášení se automaticky migruje do DB (viz checkAdminPassword()).
// Apache navíc omezuje přístup k celé appce podle IP (viz vaha.conf).

session_set_cookie_params(['samesite' => 'Strict']);
session_start();

$config = require __DIR__ . '/config.php';
require __DIR__ . '/mediamtx_client.php';
require __DIR__ . '/camera_sync.php';
require __DIR__ . '/weighing_lib.php';

header('Content-Type: application/json');

function respond($data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// app_settings klíče spravované vlastními akcemi (heslo, nastavení zálohy) -
// nikdy se nesmí objevit/dát přepsat přes obecný list_settings/save_settings
// (záložka "Nastavení váhy").
const APP_SETTINGS_INTERNAL_KEYS = ['admin_password_hash', 'backup_retention_count'];

$rawInput = json_decode(file_get_contents('php://input'), true);
$input = is_array($rawInput) ? $rawInput : [];
$action = $_GET['action'] ?? $input['action'] ?? '';

try {
    $db = $config['db'];
    $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}";
    $pdo = new PDO($dsn, $db['user'], $db['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    respond(['ok' => false, 'error' => 'DB chyba: ' . $e->getMessage()], 500);
}

// Ověří heslo proti hashi v DB; pokud tam ještě žádný není (čerstvá
// instalace nebo appka ještě před touhle funkcí), porovná proti
// VAHA_ADMIN_PASSWORD z vaha.env a při shodě rovnou DB hashem osadí, ať se
// od příští chvíle používá jen ten.
function checkAdminPassword(PDO $pdo, array $config, string $password): bool {
    if ($password === '') {
        return false;
    }
    $hash = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'admin_password_hash'")->fetchColumn();
    if ($hash) {
        return password_verify($password, $hash);
    }
    if (!hash_equals($config['admin_password'], $password)) {
        return false;
    }
    setAdminPasswordHash($pdo, $password);
    return true;
}

function setAdminPasswordHash(PDO $pdo, string $password): void {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare(
        "INSERT INTO app_settings (setting_key, setting_value, description) VALUES ('admin_password_hash', ?, 'Hash hesla administrace (bcrypt) - spravuje se přes Administrace > Heslo, ne ručně')
         ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value"
    )->execute([$hash]);
}

// ----- Přihlášení / odhlášení -----

if ($action === 'login') {
    $password = (string)($input['password'] ?? '');
    if (checkAdminPassword($pdo, $config, $password)) {
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
        $channel = trim((string)($input['channel'] ?? ''));
        $substream = trim((string)($input['substream'] ?? ''));
        // (int) místo bool - PDO by jinak PHP `false` odeslalo jako prázdný
        // string a Postgres to u sloupce typu boolean odmítne.
        $isActive = (int)!empty($input['is_active']);
        $isWeighingPhoto = (int)!empty($input['is_weighing_photo']);
        $showOnDashboard = (int)!empty($input['show_on_dashboard']);

        $lprMode = (string)($input['lpr_mode'] ?? 'off');
        if (!in_array($lprMode, ['off', 'ocr', 'qr', 'both'], true)) {
            $lprMode = 'off';
        }

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
                    is_active=?, is_weighing_photo=?, lpr_mode=?, show_on_dashboard=?, channel=?, substream=? WHERE id=?";
            $pdo->prepare($sql)->execute([
                $name, $templateId, $ip, $username, $password,
                $isActive, $isWeighingPhoto, $lprMode, $showOnDashboard, $channel, $substream, $id,
            ]);
        } else {
            if ($password === '') {
                respond(['ok' => false, 'error' => 'Heslo je povinné u nové kamery'], 400);
            }
            $sql = "INSERT INTO cameras (name, template_id, ip_address, username, password,
                    is_active, is_weighing_photo, lpr_mode, show_on_dashboard, channel, substream)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([
                $name, $templateId, $ip, $username, $password,
                $isActive, $isWeighingPhoto, $lprMode, $showOnDashboard, $channel, $substream,
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

        $stmt = $pdo->prepare("SELECT c.ip_address, c.username, c.password, c.channel, c.substream, t.url_template
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
            'min_weight_kg' => ['500', 'Od jaké váhy (kg) se považuje, že najelo vozidlo (a od které se zamyká tlačítko "Vážení řidič" / ruční tlačítko)'],
            'min_weight_reset_kg' => ['400', 'Pod jakou váhou (kg) se považuje, že vozidlo odjelo'],
            'pre_roll_sec' => ['15', 'Kolik vteřin čtení před detekcí vozidla zahrnout do grafu (nástupní hrana)'],
            'departure_tail_sec' => ['15', 'Jak dlouho po poklesu pod min_weight_reset_kg ještě zaznamenávat čtení (sestupná hrana grafu)'],
            'departure_empty_kg' => ['50', 'Váha (kg), od které se váha považuje za prázdnou (ukončí odjezd dřív, nemusí se čekat na departure_tail_sec)'],
            'driver_save_cooldown_sec' => ['60', 'Jak dlouho po stisku tlačítka "Vážení řidič" na dashboardu musí uplynout, než jde použít znovu'],
        ];
        $existing = $pdo->query('SELECT setting_key FROM app_settings')->fetchAll(PDO::FETCH_COLUMN);
        $insert = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, description) VALUES (?, ?, ?)');
        foreach ($defaults as $key => [$value, $description]) {
            if (!in_array($key, $existing, true)) {
                $insert->execute([$key, $value, $description]);
            }
        }

        $items = $pdo->query(
            "SELECT * FROM app_settings WHERE setting_key NOT IN ('" . implode("','", APP_SETTINGS_INTERNAL_KEYS) . "') ORDER BY setting_key"
        )->fetchAll(PDO::FETCH_ASSOC);
        respond(['ok' => true, 'items' => $items]);
    }

    case 'save_settings': {
        $settings = $input['settings'] ?? null;
        if (!is_array($settings)) {
            respond(['ok' => false, 'error' => 'Chybí settings'], 400);
        }
        $update = $pdo->prepare('UPDATE app_settings SET setting_value = ? WHERE setting_key = ?');
        foreach ($settings as $key => $value) {
            // app_settings se dvojitě používá i pro interní hodnoty (hash hesla,
            // nastavení zálohy) spravované vlastními akcemi - obecný formulář
            // "Nastavení váhy" je nesmí nikdy umět přepsat.
            if (in_array((string)$key, APP_SETTINGS_INTERNAL_KEYS, true)) {
                continue;
            }
            $update->execute([(string)$value, (string)$key]);
        }
        respond(['ok' => true]);
    }

    // ----- Heslo administrace -----

    case 'change_password': {
        $currentPassword = (string)($input['current_password'] ?? '');
        $newPassword = (string)($input['new_password'] ?? '');

        if (!checkAdminPassword($pdo, $config, $currentPassword)) {
            respond(['ok' => false, 'error' => 'Současné heslo není správně'], 403);
        }
        if (mb_strlen($newPassword) < 8) {
            respond(['ok' => false, 'error' => 'Nové heslo musí mít alespoň 8 znaků'], 400);
        }

        setAdminPasswordHash($pdo, $newPassword);
        respond(['ok' => true]);
    }

    // ----- Zálohy (noční cron i ruční spuštění - viz /usr/local/bin/vaha-backup-full.sh) -----

    case 'list_backups': {
        $backupRoot = '/var/backups/vaha';
        $items = [];
        foreach (glob($backupRoot . '/vaha-backup-*', GLOB_ONLYDIR) ?: [] as $dir) {
            $sizeBytes = 0;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                $sizeBytes += $file->getSize();
            }
            $items[] = [
                'name' => basename($dir),
                'size_bytes' => $sizeBytes,
                'modified_at' => date('Y-m-d H:i:s', filemtime($dir)),
            ];
        }
        usort($items, fn($a, $b) => strcmp($b['name'], $a['name']));

        $retention = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'backup_retention_count'")->fetchColumn();
        respond(['ok' => true, 'items' => $items, 'retention_count' => $retention !== false ? (int)$retention : 7]);
    }

    case 'save_backup_retention': {
        $retention = (int)($input['retention_count'] ?? 0);
        if ($retention < 1 || $retention > 365) {
            respond(['ok' => false, 'error' => 'Počet uchovaných záloh musí být 1-365'], 400);
        }
        $pdo->prepare(
            "INSERT INTO app_settings (setting_key, setting_value, description) VALUES ('backup_retention_count', ?, 'Kolik posledních záloh uchovat (viz Administrace > Zálohy) - starší se při každém běhu zálohy smažou')
             ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value"
        )->execute([(string)$retention]);
        respond(['ok' => true]);
    }

    case 'run_backup_now': {
        $script = '/usr/local/bin/vaha-backup-full.sh';
        if (!is_executable($script)) {
            respond(['ok' => false, 'error' => "Záložní skript $script neexistuje nebo není spustitelný - nastav nejdřív noční zálohu v install.sh (nebo ho spusť znovu)."], 400);
        }
        // Běží na pozadí - plná záloha (DB + appka) může trvat déle, než je
        // rozumné čekat v jednom HTTP požadavku. Výsledek se objeví v
        // list_backups, až doběhne.
        // Log jde do /var/backups/vaha (ne /var/log) - ten je root:www-data
        // 2770, takže tam může zapisovat jak www-data (tohle tlačítko), tak
        // root (noční cron); /var/log je root:root 755 a www-data by do něj
        // nový soubor nevytvořil (exec() na pozadí by to selhání potichu
        // spolknul).
        exec(escapeshellarg($script) . ' > /var/backups/vaha/manual-run.log 2>&1 &');
        respond(['ok' => true]);
    }

    // ----- Váhy (IP/port/protokol terminálu - viz weighing_lib.php) -----

    case 'list_scales': {
        respond(['ok' => true, 'items' => $pdo->query('SELECT * FROM scales ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'save_scale': {
        $id = $input['id'] ?? null;
        $name = trim((string)($input['name'] ?? ''));
        $protocol = (string)($input['protocol'] ?? '');
        $ip = trim((string)($input['ip_address'] ?? ''));
        $port = (int)($input['port'] ?? 0);

        if (!in_array($protocol, SCALE_PROTOCOLS, true)) {
            respond(['ok' => false, 'error' => 'Neplatný protokol'], 400);
        }
        if ($name === '' || $ip === '' || $port < 1 || $port > 65535) {
            respond(['ok' => false, 'error' => 'Chybí povinné pole (jméno, IP, platný port)'], 400);
        }

        if ($id) {
            // "active" se tady nemění - zapíná/vypíná se výhradně přes
            // set_active_scale, aby nešlo omylem obejít pravidlo "nejvýš
            // jedna aktivní váha" uložením formuláře.
            $pdo->prepare('UPDATE scales SET name=?, protocol=?, ip_address=?, port=? WHERE id=?')
                ->execute([$name, $protocol, $ip, $port, $id]);
        } else {
            $pdo->prepare('INSERT INTO scales (name, protocol, ip_address, port, active) VALUES (?, ?, ?, ?, false)')
                ->execute([$name, $protocol, $ip, $port]);
        }

        respond(['ok' => true]);
    }

    case 'set_active_scale': {
        $id = $input['id'] ?? null;
        if (!$id) {
            respond(['ok' => false, 'error' => 'Chybí id'], 400);
        }
        $pdo->beginTransaction();
        $pdo->exec('UPDATE scales SET active = false');
        $pdo->prepare('UPDATE scales SET active = true WHERE id = ?')->execute([$id]);
        $pdo->commit();
        respond(['ok' => true]);
    }

    case 'delete_scale': {
        $id = $input['id'] ?? null;
        if (!$id) {
            respond(['ok' => false, 'error' => 'Chybí id'], 400);
        }
        $pdo->prepare('DELETE FROM scales WHERE id = ?')->execute([$id]);
        respond(['ok' => true]);
    }

    // Připojí se přímo k váze (bez ohledu na to, jestli je "aktivní") a
    // zkusí jedno čtení - pro rychlé ověření IP/portu/protokolu z
    // administrace, bez nutnosti ukládat a restartovat vaha.service.
    case 'test_scale': {
        $protocol = (string)($input['protocol'] ?? '');
        $ip = trim((string)($input['ip_address'] ?? ''));
        $port = (int)($input['port'] ?? 0);

        if (!in_array($protocol, SCALE_PROTOCOLS, true) || $ip === '' || $port < 1 || $port > 65535) {
            respond(['ok' => false, 'error' => 'Chybí nebo neplatná IP/port/protokol'], 400);
        }

        $socket = @stream_socket_client("tcp://$ip:$port", $errno, $errstr, 5);
        if (!$socket) {
            respond(['ok' => false, 'error' => "Nelze se připojit: $errstr ($errno)"], 502);
        }
        stream_set_timeout($socket, 5);

        fwrite($socket, buildScaleCommand($protocol));
        $raw = fread($socket, 1024);
        $meta = stream_get_meta_data($socket);
        fclose($socket);

        if ($raw === false || $raw === '') {
            $reason = !empty($meta['timed_out']) ? ' (timeout)' : '';
            respond(['ok' => false, 'error' => "Váha neodpověděla$reason"], 502);
        }

        $errorMessage = null;
        $weight = parseScaleResponse($protocol, $raw, $errorMessage);
        respond(['ok' => $weight !== null, 'raw' => $raw, 'weight' => $weight, 'error' => $errorMessage]);
    }

    // Vynulování aktivní váhy (Set Zero) - viz requestZeroScale() ve
    // weighing_lib.php (sdíleno se zero_scale.php na dashboardu).
    case 'zero_scale': {
        $result = requestZeroScale($pdo, $config);
        respond($result, $result['ok'] ? 200 : 400);
    }

    default:
        respond(['ok' => false, 'error' => 'Neznámá akce'], 400);
}
