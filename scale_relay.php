<?php
// Umožňuje druhému, nezávislému SW (např. Lantronix Com Port Redirector na
// PC dodavatele) číst váhu stejným protokolem, jako appka - i když fyzický
// RS232/LAN převodník (Papouch GNOME232 apod.) povolí jen JEDNO TCP
// spojení najednou, které drží weighing_daemon.php.
//
// Neotevírá druhé spojení ke skutečné váze. weighing_daemon.php průběžně
// (jednou za poll_interval_sec) zapisuje poslední syrovou odpověď z váhy
// do scale_raw_file (viz config.php) - tenhle skript na tom naslouchá na
// TCP portu a na jakýkoli dotaz odpoví tou poslední známou hodnotou,
// přesně ve stejném formátu, v jakém ji posílá skutečná váha.
//
// Nasazení: samostatná systemd služba vedle vaha.service, např.
// /etc/systemd/system/vaha-scale-relay.service:
//
//   [Unit]
//   Description=Relay pro čtení váhy druhým SW (vedle Papouch GNOME232)
//   After=network.target
//
//   [Service]
//   ExecStart=/usr/bin/php /var/www/vaha/scale_relay.php
//   Restart=always
//   Environment=VAHA_DB_PASS=...
//   Environment=VAHA_ADMIN_PASSWORD=...
//
//   [Install]
//   WantedBy=multi-user.target
//
// (VAHA_DB_PASS/VAHA_ADMIN_PASSWORD musí být nastavené, protože je
// vyžaduje config.php, i když je tenhle skript nikde nepoužívá.)

$config = require __DIR__ . '/config.php';

$listenPort = $config['scale_relay_port'];
$rawFile = $config['scale_raw_file'];

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($socket === false) {
    fwrite(STDERR, "Nelze vytvořit socket: " . socket_strerror(socket_last_error()) . "\n");
    exit(1);
}

socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

if (!socket_bind($socket, '0.0.0.0', $listenPort)) {
    fwrite(STDERR, "Nelze naslouchat na portu $listenPort: " . socket_strerror(socket_last_error($socket)) . "\n");
    exit(1);
}

socket_listen($socket, 5);
echo "Relay naslouchá na portu $listenPort, odpovídá daty z $rawFile\n";

while (true) {
    $client = socket_accept($socket);
    if ($client === false) {
        continue;
    }

    echo "Klient připojen.\n";

    while (true) {
        $request = @socket_read($client, 1024);
        if ($request === false || $request === '') {
            echo "Klient odpojen.\n";
            socket_close($client);
            break;
        }

        $raw = @file_get_contents($rawFile);
        if ($raw === false || trim($raw) === '') {
            $raw = '0,0,0';
        }

        @socket_write($client, rtrim($raw) . "\r\n");
    }
}
