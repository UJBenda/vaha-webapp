<?php
// /var/www/vaha/admin.php

require_once 'config.php';
define('APP_VERSION', 1);

try {
    $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Chyba připojení k databázi: " . $e->getMessage());
}

// Ochrana verze DB
$stmt = $pdo->query("SELECT value FROM system_info WHERE key = 'db_version'");
$db_version = (int)$stmt->fetchColumn();
if (APP_VERSION > $db_version) {
    die('<h3>⚠️ Je vyžadována aktualizace databáze!</h3>');
}

$message = '';

// Zpracování formuláře pro uložení nastavení
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    if (isset($_POST['settings']) && is_array($_POST['settings'])) {
        $updateStmt = $pdo->prepare("UPDATE app_settings SET setting_value = ? WHERE setting_key = ?");
        foreach ($_POST['settings'] as $key => $value) {
            $updateStmt->execute([$value, $key]);
        }
        $message = '<div style="background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 20px;">Nastavení bylo úspěšně uloženo. Démon začne používat nové hodnoty (při dalším cyklu/restartu).</div>';
    }
}

// Načtení nastavení váhy
$settings = $pdo->query("SELECT * FROM app_settings ORDER BY setting_key ASC")->fetchAll(PDO::FETCH_ASSOC);

// Načtení kamer
$stmt_cameras = $pdo->query("
    SELECT c.id, c.name, c.ip_address, c.username, c.is_active, 
           c.is_weighing_photo, c.is_lpr, c.show_on_dashboard, 
           t.brand_name 
    FROM cameras c
    LEFT JOIN camera_templates t ON c.template_id = t.id
    ORDER BY c.id ASC
");
$cameras = $stmt_cameras->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>Administrace - Váha</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 20px; }
        .container { display: flex; gap: 20px; flex-wrap: wrap; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); flex: 1; min-width: 400px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f9f9f9; }
        input[type="text"], input[type="number"] { padding: 6px; width: 100%; box-sizing: border-box; }
        .btn { padding: 8px 12px; background: #1a73e8; color: white; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 14px; display: inline-block; margin-top: 10px;}
        .btn:hover { background: #1558b3; }
        .btn-test { background: #34a853; }
        .btn-test:hover { background: #2b8c46; }
        .status-on { color: green; font-weight: bold; }
        .status-off { color: red; font-weight: bold; }
    </style>
</head>
<body>

    <h1>⚙️ Administrace Systému</h1>
    <?= $message ?>

    <div class="container">
        <!-- KARTA NASTAVENÍ -->
        <div class="card">
            <h2>Nastavení váhy a démona</h2>
            <form method="POST">
                <input type="hidden" name="action" value="save_settings">
                <table>
                    <thead>
                        <tr>
                            <th>Parametr</th>
                            <th>Hodnota</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($settings as $setting): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($setting['description']) ?></strong><br>
                                <small style="color: #666;"><?= htmlspecialchars($setting['setting_key']) ?></small>
                            </td>
                            <td>
                                <input type="text" name="settings[<?= htmlspecialchars($setting['setting_key']) ?>]" value="<?= htmlspecialchars($setting['setting_value']) ?>">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" class="btn">💾 Uložit nastavení</button>
            </form>
        </div>

        <!-- KARTA KAMER -->
        <div class="card">
            <h2>Správa Kamer</h2>
            <a href="edit_camera.php" class="btn" style="background: #28a745;">+ Přidat novou kameru</a>
            
	<table>
                <thead>
                    <tr>
                        <th>Název</th>
                        <th>Výrobce / Typ</th>
                        <th>IP Adresa</th>
                        <th>Funkce</th>
                        <th>Stav</th>
                        <th>Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cameras)): ?>
                        <tr><td colspan="6">Zatím nejsou přidány žádné kamery.</td></tr>
                    <?php else: ?>
                        <?php foreach ($cameras as $cam): ?>
                            <tr>
                                <td><?= htmlspecialchars($cam['name']) ?></td>
                                <td><?= htmlspecialchars($cam['brand_name'] ?? 'Neznámý') ?></td>
                                <td><?= htmlspecialchars($cam['ip_address']) ?></td>
                                <td>
                                    <!-- Ikonky podle toho, co je zaškrtnuté -->
                                    <span title="Fotit při vážení" style="opacity: <?= $cam['is_weighing_photo'] ? '1' : '0.2' ?>;">📸</span>
                                    <span title="Číst SPZ" style="opacity: <?= $cam['is_lpr'] ? '1' : '0.2' ?>;">🚗</span>
                                    <span title="Živý náhled" style="opacity: <?= $cam['show_on_dashboard'] ? '1' : '0.2' ?>;">🖥️</span>
                                </td>
                                <td class="<?= $cam['is_active'] ? 'status-on' : 'status-off' ?>">
                                    <?= $cam['is_active'] ? 'Aktivní' : 'Vypnuto' ?>
                                </td>
                                <td>
                                    <a href="edit_camera.php?id=<?= $cam['id'] ?>" class="btn">✏️ Upravit</a>
                                    <button class="btn btn-test" onclick="testCamera(<?= $cam['id'] ?>)">📸 Test</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<div id="test-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center;">
        <div style="background: white; padding: 20px; border-radius: 8px; max-width: 800px; width: 90%; max-height: 90vh; overflow-y: auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #ccc; padding-bottom: 10px; margin-bottom: 15px;">
                <h3 style="margin: 0;">Výsledek testu kamery</h3>
                <button onclick="closeModal()" style="background: #dc3545; color: white; border: none; padding: 5px 10px; cursor: pointer; border-radius: 4px;">Zavřít</button>
            </div>
            
            <div id="test-result-content">
                <!-- Zde se objeví načítání, fotka nebo chyba -->
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById('test-modal');
        const resultContent = document.getElementById('test-result-content');

        function closeModal() {
            modal.style.display = 'none';
        }

        async function testCamera(id) {
            // Zobrazit modal a loading state
            modal.style.display = 'flex';
            resultContent.innerHTML = '<p style="font-size: 18px; text-align: center;">⏳ Připojuji se ke kameře a stahuji snímek...<br><small>(Může to trvat až 5 vteřin)</small></p>';

            try {
                const response = await fetch('test_camera.php?id=' + id);
                const data = await response.json();

                if (data.status === 'success') {
                    resultContent.innerHTML = `
                        <div style="text-align: center;">
                            <p style="color: green; font-weight: bold;">✅ Spojení úspěšné!</p>
                            <img src="${data.image}" style="max-width: 100%; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.2);">
                        </div>
                    `;
                } else {
                    resultContent.innerHTML = `
                        <p style="color: red; font-weight: bold;">❌ ${data.message}</p>
                        <p><strong>Zkoušený příkaz:</strong><br><code style="background: #f4f4f4; padding: 5px; display: block; word-wrap: break-word;">${data.command}</code></p>
                        <p><strong>Log z FFmpeg:</strong></p>
                        <pre style="background: #333; color: #fff; padding: 15px; border-radius: 4px; overflow-x: auto;">${data.log}</pre>
                    `;
                }
            } catch (error) {
                resultContent.innerHTML = `<p style="color: red; font-weight: bold;">❌ Chyba komunikace se serverem.</p><pre>${error}</pre>`;
            }
        }
    </script>
</body>
</html>
