<?php
// /var/www/vaha/edit_camera.php

require_once 'config.php';

try {
    $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Chyba připojení k databázi: " . $e->getMessage());
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = '';

$templates = $pdo->query("SELECT * FROM camera_templates ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $template_id = (int)($_POST['template_id'] ?? 0);
    $ip_address = $_POST['ip_address'] ?? '';
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // Načtení nových checkboxů
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $is_weighing_photo = isset($_POST['is_weighing_photo']) ? 1 : 0;
    $is_lpr = isset($_POST['is_lpr']) ? 1 : 0;
    $show_on_dashboard = isset($_POST['show_on_dashboard']) ? 1 : 0;

    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE cameras SET name=?, template_id=?, ip_address=?, username=?, password=?, is_active=?, is_weighing_photo=?, is_lpr=?, show_on_dashboard=? WHERE id=?");
        $stmt->execute([$name, $template_id, $ip_address, $username, $password, $is_active, $is_weighing_photo, $is_lpr, $show_on_dashboard, $id]);
        $message = 'Kamera úspěšně upravena.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO cameras (name, template_id, ip_address, username, password, is_active, is_weighing_photo, is_lpr, show_on_dashboard) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $template_id, $ip_address, $username, $password, $is_active, $is_weighing_photo, $is_lpr, $show_on_dashboard]);
        $id = $pdo->lastInsertId('cameras_id_seq');
        $message = 'Kamera úspěšně přidána.';
    }
}

// Výchozí hodnoty
$camera = [
    'name' => '', 'template_id' => '', 'ip_address' => '', 'username' => '', 'password' => '', 
    'is_active' => 1, 'is_weighing_photo' => 1, 'is_lpr' => 0, 'show_on_dashboard' => 0
];

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM cameras WHERE id = ?");
    $stmt->execute([$id]);
    $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fetched) {
        $camera = $fetched;
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title><?= $id > 0 ? 'Úprava' : 'Přidání' ?> kamery</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 20px; }
        .card { background: #fff; padding: 20px; border-radius: 8px; max-width: 600px; margin: 0 auto; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        .checkbox-group label { font-weight: normal; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        input[type="text"], input[type="password"], select { width: 100%; padding: 8px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px;}
        .btn { padding: 10px 15px; background: #1a73e8; color: white; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 14px;}
        .btn:hover { background: #1558b3; }
        .btn-back { background: #6c757d; margin-right: 10px; }
        .btn-back:hover { background: #5a6268; }
        .msg { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
        h3 { border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 30px; }
    </style>
</head>
<body>

    <div class="card">
        <h2><?= $id > 0 ? '✏️ Úprava' : '➕ Přidání' ?> kamery</h2>
        
        <?php if ($message): ?>
            <div class="msg"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Pojmenování (např. Kamera Nájezd)</label>
                <input type="text" name="name" value="<?= htmlspecialchars($camera['name']) ?>" required>
            </div>

            <div class="form-group">
                <label>Výrobce / Typ šablony</label>
                <select name="template_id" required>
                    <option value="">-- Vyberte --</option>
                    <?php foreach ($templates as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $t['id'] == $camera['template_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['brand_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>IP Adresa nebo doména</label>
                <input type="text" name="ip_address" value="<?= htmlspecialchars($camera['ip_address']) ?>" required>
            </div>

            <div class="form-group">
                <label>Uživatelské jméno</label>
                <input type="text" name="username" value="<?= htmlspecialchars($camera['username']) ?>" required>
            </div>

            <div class="form-group">
                <label>Heslo</label>
                <input type="text" name="password" value="<?= htmlspecialchars($camera['password']) ?>" required>
            </div>

            <h3>⚙️ Funkce kamery v systému</h3>
            <div class="form-group checkbox-group">
                <label>
                    <input type="checkbox" name="is_active" <?= $camera['is_active'] ? 'checked' : '' ?>>
                    <strong>Kamera je aktivní</strong> (globální vypínač)
                </label>
                <label>
                    <input type="checkbox" name="is_weighing_photo" <?= $camera['is_weighing_photo'] ? 'checked' : '' ?>>
                    📸 Ukládat fotku při každém ustálení váhy
                </label>
                <label>
                    <input type="checkbox" name="is_lpr" <?= $camera['is_lpr'] ? 'checked' : '' ?>>
                    🚗 Vyčítat z fotky SPZ (License Plate Recognition)
                </label>
                <label>
                    <input type="checkbox" name="show_on_dashboard" <?= $camera['show_on_dashboard'] ? 'checked' : '' ?>>
                    🖥️ Zobrazit živý stream na hlavní stránce
                </label>
            </div>

            <div style="margin-top: 20px;">
                <a href="admin.php" class="btn btn-back">⬅ Zpět</a>
                <button type="submit" class="btn">💾 Uložit kameru</button>
            </div>
        </form>
    </div>

</body>
</html>
