<?php
// Sdílené nastavení pro všechny PHP skripty appky.
// Citlivé hodnoty (hlavně DB heslo) se NEUKLÁDAJÍ do gitu - berou se
// z proměnných prostředí. Na serveru je potřeba je nastavit, např.:
//   Apache (vaha.conf, do <VirtualHost>):
//     SetEnv VAHA_DB_PASS "heslo"
//   cron (pro sync_cameras.php):
//     VAHA_DB_PASS=heslo
//     * * * * * php /var/www/vaha/sync_cameras.php >> /var/log/vaha-sync-cameras.log 2>&1
//   systemd EnvironmentFile pro weighing_daemon.php.

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
];
