<?php
// Diagnostický nástroj pro Gravex 3000 (terminál SysTec IT1) - připojí se
// na "continuous output" TCP port a vypíše syrová data tak, jak přesně
// přicházejí (čitelně i jako hex), abychom poznali, jaký formát
// (SysTec/MT-SICS/Toledo/CAS/...) je na terminálu nastavený v Service
// Mode > General > "Cont.out:". Výchozí port terminálu je 1900, ale mohl
// být změněn v Service Mode > Interface > Com0: Port.
//
// Použití:
//   php gravex_probe.php <ip> [port]
//
// Necháme běžet ~30-60 vteřin (Ctrl+C pro ukončení) a pošleme výstup.

if ($argc < 2) {
    fwrite(STDERR, "Použití: php gravex_probe.php <ip> [port=1900]\n");
    exit(1);
}

$ip = $argv[1];
$port = isset($argv[2]) ? (int)$argv[2] : 1900;

echo "Připojuji se na $ip:$port ...\n";
$socket = @stream_socket_client("tcp://$ip:$port", $errno, $errstr, 10);
if (!$socket) {
    echo "Nelze se připojit: $errstr ($errno)\n";
    echo "Zkontroluj: je terminál na síti dostupný (ping $ip)? Je 'Cont.out:' v Service Mode nastaveno na jiný protokol než 'Off'? Je zvolené rozhraní 'Eth'? Sedí port (Service Mode > Interface > Com0: Port)?\n";
    exit(1);
}
echo "Připojeno. Čekám na data (Ctrl+C pro ukončení)...\n\n";

stream_set_timeout($socket, 15);

$buffer = '';
$lineNo = 0;

while (!feof($socket)) {
    $chunk = fread($socket, 256);
    if ($chunk === false || $chunk === '') {
        $info = stream_get_meta_data($socket);
        if ($info['timed_out']) {
            echo "[Žádná data posledních 15 vteřin - terminál možná nemá continuous output zapnutý, nebo jde o jiný interval]\n";
            continue;
        }
        break;
    }

    $buffer .= $chunk;

    // Rozdělíme podle CR nebo LF (nevíme předem, co přesně terminál používá)
    while (($pos = strcspn($buffer, "\r\n")) < strlen($buffer)) {
        $line = substr($buffer, 0, $pos);
        $buffer = ltrim(substr($buffer, $pos), "\r\n");
        if ($line === '') {
            continue;
        }
        $lineNo++;
        $hex = implode(' ', array_map(fn($c) => str_pad(dechex(ord($c)), 2, '0', STR_PAD_LEFT), str_split($line)));
        printf("[%s] řádek #%d (%d znaků)\n  text: %s\n  hex:  %s\n\n",
            date('H:i:s'), $lineNo, strlen($line), $line, $hex);
    }
}

echo "Spojení ukončeno (terminál zavřel socket nebo timeout).\n";
