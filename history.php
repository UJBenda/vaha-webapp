<?php
// /var/www/vaha/history.php
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>Historie vážení</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f0f2f5; padding: 20px; }
        .container { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); max-width: 1000px; margin: 0 auto; }
        .back-link { display: inline-block; margin-bottom: 20px; text-decoration: none; color: #1a73e8; }
        
        /* Filtry - stejné jako jsi měl */
        .filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 20px; }
        .filters input { padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .filters button { padding: 10px; background: #1a73e8; color: white; border: none; border-radius: 4px; cursor: pointer; grid-column: 1 / -1; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; border-bottom: 1px solid #ddd; text-align: left; }
        th { background: #f9f9f9; }
        tr[data-photopath] { cursor: pointer; }
        tr[data-photopath]:hover { background-color: #f5f5f5; }

        /* Modál pro fotky */
        #photo-modal { display: none; position: fixed; z-index: 100; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.8); justify-content: center; align-items: center; }
        #modal-image { max-width: 90%; max-height: 90vh; }
    </style>
</head>
<body>

    <div class="container">
        <a href="index.php" class="back-link">⬅ Zpět na hlavní dashboard</a>
        <h1>Historie vážení</h1>

        <div class="filters">
            <div><label>Datum od:</label><input type="date" id="filter-date-from"></div>
            <div><label>Datum do:</label><input type="date" id="filter-date-to"></div>
            <div><label>Váha od (kg):</label><input type="number" id="filter-weight-min"></div>
            <div><label>Váha do (kg):</label><input type="number" id="filter-weight-max"></div>
            <button id="filter-button">Filtrovat</button>
        </div>

        <table id="history-table">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Čas</th>
                    <th>Váha (kg)</th>
                    <th>SPZ</th>
                    <th>Foto</th>
                </tr>
            </thead>
            <tbody id="history-body">
                <tr><td colspan="5">Načítání...</td></tr>
            </tbody>
        </table>
    </div>

    <div id="photo-modal" onclick="this.style.display='none'">
        <img id="modal-image" src="" alt="Fotka">
    </div>

    <script>
        const historyBodyEl = document.getElementById('history-body');
        
        async function fetchHistory() {
            const params = new URLSearchParams({
                date_from: document.getElementById('filter-date-from').value,
                date_to: document.getElementById('filter-date-to').value,
                weight_min: document.getElementById('filter-weight-min').value,
                weight_max: document.getElementById('filter-weight-max').value
            });
            
            try {
                const response = await fetch('get_history.php?' + params.toString());
                const data = await response.json();
                historyBodyEl.innerHTML = '';
                
                data.forEach(row => {
                    const tr = document.createElement('tr');
                    const ts = new Date(row.timestamp);
                    if (row.photo_path) tr.dataset.photopath = row.photo_path;
                    
                    tr.innerHTML = `
                        <td>${ts.toLocaleDateString('cs-CZ')}</td>
                        <td>${ts.toLocaleTimeString('cs-CZ')}</td>
                        <td>${parseFloat(row.weight).toFixed(2)}</td>
                        <td>${row.spz || '-'}</td>
                        <td>${row.photo_path ? '📷 Zobrazit' : 'Ne'}</td>
                    `;
                    historyBodyEl.appendChild(tr);
                });
            } catch (e) {
                historyBodyEl.innerHTML = '<tr><td colspan="5">Chyba načítání dat.</td></tr>';
            }
        }

        document.getElementById('filter-button').addEventListener('click', fetchHistory);
        historyBodyEl.addEventListener('click', (e) => {
            const tr = e.target.closest('tr');
            if (tr?.dataset.photopath) {
                document.getElementById('modal-image').src = tr.dataset.photopath;
                document.getElementById('photo-modal').style.display = 'flex';
            }
        });

        fetchHistory();
    </script>
</body>
</html>
