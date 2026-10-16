<?php
// Sdílené nastavení pro všechny PHP skripty appky.
// Citlivé hodnoty (hlavně DB heslo) se NEUKLÁDAJÍ do gitu - berou se
// z proměnných prostředí. Jediný zdroj pravdy je soubor /etc/vaha/vaha.env
// (mimo git repozitář, mimo webroot) - viz vaha.env.example pro formát a
// README k nasazení. Apache, systemd (vaha.service) i cron (sync_cameras.php)
// spouští stejné PHP soubory, takže stačí jeden soubor s hesly místo
// duplikování SetEnv / Environment= / inline proměnných na třech místech.
//
// Pokud už je proměnná nastavená jinak (např. starší Environment= v
// systemd unitu), má přednost před souborem - viz vahaLoadEnvFile().

function vahaLoadEnvFile(string $path): void {
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $len = strlen($value);
        if ($len >= 2 && (
            ($value[0] === '"' && $value[$len - 1] === '"') ||
            ($value[0] === "'" && $value[$len - 1] === "'")
        )) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

vahaLoadEnvFile('/etc/vaha/vaha.env');

function vahaEnv(string $name, ?string $default = null): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException("Chybí proměnná prostředí: $name");
    }
    return $value;
}

return [
    'db' => [
        'host' => vahaEnv('VAHA_DB_HOST', 'localhost'),
        'port' => vahaEnv('VAHA_DB_PORT', '5432'),
        'name' => vahaEnv('VAHA_DB_NAME', 'vaha'),
        'user' => vahaEnv('VAHA_DB_USER', 'vaha'),
        'pass' => vahaEnv('VAHA_DB_PASS'),
    ],
    // MediaMTX Control API (api: yes v mediamtx.yml). Musí zůstat dostupné
    // jen lokálně (127.0.0.1). Přes docker publikování portu mediamtx často
    // nevidí spojení jako z localhostu (vidí IP docker bridge), takže
    // výchozí "bez hesla z 127.0.0.1" výjimka v mediamtx.yml nefunguje -
    // proto vlastní uživatel/heslo pro "api"/"metrics"/"pprof" akce
    // (authInternalUsers v mediamtx.yml). Prázdné = bez auth (starší setup).
    'mediamtx_api' => vahaEnv('VAHA_MEDIAMTX_API', 'http://127.0.0.1:9997'),
    'mediamtx_api_user' => vahaEnv('VAHA_MEDIAMTX_API_USER', ''),
    'mediamtx_api_pass' => vahaEnv('VAHA_MEDIAMTX_API_PASS', ''),

    // Heslo pro přihlášení do admin.html (jedno sdílené heslo, žádné
    // uživatelské účty). Musí být nastaveno v prostředí, viz VAHA_DB_PASS výše.
    'admin_password' => vahaEnv('VAHA_ADMIN_PASSWORD'),

    // Kam appka ukládá/servíruje fotky z vážení a testů kamer.
    'photo_storage_path' => vahaEnv('VAHA_PHOTO_STORAGE_PATH', '/var/www/vaha/photos/'),
    'photo_web_path_prefix' => vahaEnv('VAHA_PHOTO_WEB_PREFIX', 'photos/'),

    // Soubor, do kterého weighing_daemon.php zapisuje aktuální váhu pro web.
    'live_weight_file' => vahaEnv('VAHA_LIVE_WEIGHT_FILE', '/var/www/vaha/live_weight.txt'),

    // Cesta k read_spz.py pro rozpoznání SPZ (LPR).
    'read_spz_script' => vahaEnv('VAHA_READ_SPZ_SCRIPT', __DIR__ . '/read_spz.py'),

    // Soubor se syrovou (neparsovanou) poslední odpovědí z váhy - zapisuje
    // weighing_daemon.php, čte scale_relay.php (viz níže).
    'scale_raw_file' => vahaEnv('VAHA_SCALE_RAW_FILE', '/var/www/vaha/scale_raw.txt'),

    // Port, na kterém scale_relay.php naslouchá pro druhý (nezávislý) SW,
    // co potřebuje číst váhu stejným protokolem, ale fyzický převodník
    // (Papouch GNOME232 apod.) umí jen jedno TCP spojení najednou. Místo
    // dotazu na skutečnou váhu relay odpoví poslední hodnotou z
    // scale_raw_file - žádné druhé spojení k převodníku není potřeba.
    'scale_relay_port' => (int)vahaEnv('VAHA_SCALE_RELAY_PORT', '10002'),

    // Soubor "příznak" pro ruční tlačítko u váhy - force_save.php ho
    // vytvoří, weighing_daemon.php ho při každém cyklu kontroluje a pokud
    // existuje, uloží aktuální váhu okamžitě (bez čekání na automatickou
    // stabilizaci) a soubor smaže.
    'force_save_flag' => vahaEnv('VAHA_FORCE_SAVE_FLAG', '/var/www/vaha/force_save.flag'),

    // Token v URL pro force_save.php - endpoint nemá přihlášení (volá ho
    // fyzické tlačítko/IoT relé, ne prohlížeč), takže ho chrání token +
    // IP omezení v Apache (stejné jako zbytek webu).
    'force_save_token' => vahaEnv('VAHA_FORCE_SAVE_TOKEN'),
];
