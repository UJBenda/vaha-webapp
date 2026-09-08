<?php
// /var/www/vaha/index.php
require_once 'config.php';

// Načtení kamer určených pro zobrazení na hlavní obrazovce (max 4)
try {
    $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SELECT id, name FROM cameras WHERE is_active = true AND show_on_dashboard = true ORDER BY id ASC LIMIT 4");
    $dashboard_cameras = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dashboard_cameras = [];
}

$cam_count = count($dashboard_cameras);
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitorování Váhy</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            background: #f0f2f5; 
            margin: 0; 
            padding: 10px 20px; 
            height: 100vh; 
            display: flex; 
            flex-direction: column; 
            box-sizing: border-box; 
        }
        
        /* Zmenšená hlavička */
        h1 { 
            margin: 5px 0 15px 0; 
            font-size: 1.5rem; 
            color: #333; 
        }

        .container {
            display: flex;
            gap: 20px;
            flex-grow: 1; /* Roztáhne kontejner na zbytek výšky okna */
            align-items: stretch;
            min-height: 0; /* Zabrání přetečení ve flexboxu */
        }
        
        /* LEVÝ SLOUPEC (Váha a historie) */
        .weighing {
            flex: 1; 
            min-width: 350px;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
        }

        /* Obří zobrazení váhy (využije uvolněný prostor) */
        #live-weight-display {
            font-size: 8rem;
            font-weight: bold;
            color: #1a73e8;
            text-align: center;
            flex-grow: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            margin-bottom: 20px;
        }
        #live-weight-display small {
            font-size: 2rem;
            color: #5f6368;
            margin-left: 10px;
        }

        /* Historie v levém sloupci */
        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        .history-header h2 {
            margin: 0;
            color: #3c4043;
            font-size: 1.2rem;
        }
        .btn-history {
            padding: 6px 12px;
            background: #1a73e8;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        .btn-history:hover { background: #1558b3; }

        #history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        #history-table th, #history-table td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }
        #history-table th { background: #f9f9f9; }

        /* PRAVÝ SLOUPEC (Videa) */
        .video-wrapper {
            flex: 2; 
            min-width: 500px;
            background-color: #000;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            padding: 5px;
        }

        /* CSS Grid pro automatické rozložení 1-4 kamer */
        .video-grid {
            display: grid;
            gap: 5px;
            width: 100%;
            height: 100%;
        }
        
        .video-item {
            background: #111;
            position: relative;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .video-item video {
            width: 100%;
            height: 100%;
            object-fit: cover; /* Zajistí, že video vyplní svůj obdélník bez deformace */
            display: block;
        }

        .cam-label {
            position: absolute;
            top: 10px;
            left: 10px;
            background: rgba(0,0,0,0.6);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            z-index: 10;
        }

        /* Pravidla pro počty kamer */
        .count-1 { grid-template-columns: 1fr; grid-template-rows: 1fr; }
        .count-2 { grid-template-columns: 1fr 1fr; grid-template-rows: 1fr; }
        .count-3 { grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; }
        .count-3 .video-item:first-child { grid-column: span 2; } /* První kamera nahoře přes celou šířku */
        .count-4 { grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; }
    </style>
</head>
<body>

    <h1>Vážní systém a dohled</h1>

    <div class="container">
        
        <!-- LEVÝ SLOUPEC -->
        <div class="weighing">
            <!-- Odstraněn nadpis Aktuální váha -->
            <div id="live-weight-display">
                --.-- <small>kg</small>
            </div>

            <div class="history-header">
                <h2>Poslední vážení</h2>
                <!-- Tlačítko odkazující na budoucí stránku history.php, kam přesuneme filtry -->
                <a href="history.php" class="btn-history">Filtry a celá historie ➔</a>
            </div>
            
            <table id="history-table">
                <thead>
                    <tr>
                        <th>Čas</th>
                        <th>Váha (kg)</th>
                        <th>SPZ</th>
                    </tr>
                </thead>
                <tbody id="history-body">
                    <tr><td colspan="3">Načítání...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- PRAVÝ SLOUPEC -->
        <div class="video-wrapper">
            <?php if ($cam_count == 0): ?>
                <div style="color: white; display: flex; align-items: center; justify-content: center; height: 100%;">
                    <p>Žádná kamera není nastavena pro zobrazení na dashboardu.</p>
                </div>
            <?php else: ?>
                <div class="video-grid count-<?= $cam_count ?>">
                    <?php foreach ($dashboard_cameras as $cam): ?>
                        <div class="video-item">
                            <div class="cam-label"><?= htmlspecialchars($cam['name']) ?></div>
                            <video id="kamera-<?= $cam['id'] ?>" controls autoplay muted playsinline data-camid="<?= $cam['id'] ?>"></video>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script>
        const liveWeightEl = document.getElementById('live-weight-display');
        const historyBodyEl = document.getElementById('history-body');

        // Živá váha
        async function fetchLiveWeight() {
            try {
                const response = await fetch('live_weight.txt?t=' + new Date().getTime());
                if (!response.ok) throw new Error('Chyba sítě');
                const weight = parseFloat(await response.text());
                liveWeightEl.innerHTML = `${weight.toFixed(2)} <small>kg</small>`;
            } catch (error) {
                liveWeightEl.innerHTML = `CHYBA <small>!</small>`;
            }
        }

        // Zkrácená historie (na dashboardu stačí 5 posledních)
        async function fetchHistory() {
            try {
                const response = await fetch('get_history.php');
                if (!response.ok) throw new Error('Chyba sítě');
                const data = await response.json();
                
                historyBodyEl.innerHTML = ''; 
                
                // Ořízneme pole pouze na prvních 5 záznamů
                const recentData = data.slice(0, 5);

                if (recentData.length === 0) {
                     historyBodyEl.innerHTML = '<tr><td colspan="3">Zatím žádná data</td></tr>';
                     return;
                }
                
                recentData.forEach(row => {
                    const tr = document.createElement('tr');
                    const ts = new Date(row.timestamp);
                    const formattedTime = ts.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                    
                    // Bezpečné načtení SPZ (pokud existuje)
                    const spz = row.spz ? row.spz : '-';

                    tr.innerHTML = `
                        <td>${formattedTime}</td>
                        <td><strong>${parseFloat(row.weight).toFixed(2)}</strong></td>
                        <td>${spz}</td>
                    `;
                    historyBodyEl.appendChild(tr);
                });
            } catch (error) {
                historyBodyEl.innerHTML = '<tr><td colspan="3">Chyba načítání</td></tr>';
            }
        }
        
        // Původní WebRTC logika upravená pro dynamický seznam kamer
        async function startStream(videoEl, camId) {
            const pc = new RTCPeerConnection();
            pc.ontrack = (e) => {
                if (e.track.kind === 'video') { videoEl.srcObject = e.streams[0]; }
            };
            pc.addTransceiver('video', { 'direction': 'recvonly' });
            
            try {
                const offer = await pc.createOffer();
                await pc.setLocalDescription(offer);
                
                // TODO: Zde bude nutné upravit URL na tvůj WebRTC / MediaMTX server podle camId
                // Aktuálně používá tvou původní testovací cestu
                const response = await fetch('https://kamera.micharnaubusinek.cz/kamera' + camId + '/whep', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/sdp' },
                    body: offer.sdp
                });
                
                if (!response.ok) throw new Error(`Server odpověděl chybou: ${response.status}`);
                const sdpAnswer = await response.text();
                await pc.setRemoteDescription(new RTCSessionDescription({ type: 'answer', sdp: sdpAnswer }));
            } catch (err) {
                console.error('Chyba WebRTC pro kameru ID ' + camId + ':', err);
            }
        }

        // Inicializace všech video tagů na stránce
        document.querySelectorAll('video[data-camid]').forEach(videoEl => {
            const camId = videoEl.getAttribute('data-camid');
            startStream(videoEl, camId);
        });

        // Spuštění intervalů
        setInterval(fetchLiveWeight, 1000); 
        setInterval(fetchHistory, 10000); 
        
        // První načtení
        fetchLiveWeight();
        fetchHistory();
    </script>
</body>
</html>
