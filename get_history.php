<?php
// ----- NASTAVENÍ -----
$db_host = 'localhost';
$db_port = '5432'; 
$db_name = 'vaha';
$db_user = 'vaha'; 
$db_pass = 'eJzZX5nuc@9RqUXSX';
// ---------------------

try {
    $dsn = "pgsql:host=$db_host;port=$db_port;dbname=$db_name";
    $pdo = new PDO($dsn, $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Základní dotaz
    // Vybíráme i ID (pro budoucí použití) a photo_path
    $sql = "SELECT id, weight, timestamp, photo_path, spz FROM weighings";
    // Zpracování filtrů
    $where_clauses = [];
    $parameters = [];

    if (!empty($_GET['date_from'])) {
        // Používáme PostgreSQL syntaxi '::date' pro porovnání pouze data
        $where_clauses[] = "timestamp::date >= ?";
        $parameters[] = $_GET['date_from'];
    }
    
    if (!empty($_GET['date_to'])) {
        $where_clauses[] = "timestamp::date <= ?";
        $parameters[] = $_GET['date_to'];
    }

    if (!empty($_GET['weight_min'])) {
        $where_clauses[] = "weight >= ?";
        $parameters[] = (float)$_GET['weight_min'];
    }
    
    if (!empty($_GET['weight_max'])) {
        $where_clauses[] = "weight <= ?";
        $parameters[] = (float)$_GET['weight_max'];
    }

    // Sestavení finálního dotazu
    if (count($where_clauses) > 0) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }

    // Pořadí a limit (dáme větší limit, když filtrujeme)
    // TADY JE TA ZMĚNA -> LIMIT 100
    $sql .= " ORDER BY timestamp DESC LIMIT 100";

    // Příprava a spuštění dotazu
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parameters);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($data);

} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}
?>
