<?php
// Sdílené nastavení pro všechny PHP skripty appky.
// Citlivé hodnoty (hlavně DB heslo) se NEUKLÁDAJÍ do gitu - berou se
// z proměnných prostředí. Na serveru je potřeba je nastavit, např.:
//   Apache (vaha.conf, do <VirtualHost>):
//     SetEnv VAHA_DB_PASS "heslo"
//   cron (pro sync_cameras.php):
//     VAHA_DB_PASS=heslo
//     * * * * * php /var/www/vaha/sync_cameras.php >> /var/log/vaha-sync-cameras.log 2>&1
//   nebo přes /etc/environment / systemd EnvironmentFile pro weighing_daemon.php.

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
    // jen lokálně (127.0.0.1) - nemá vlastní autentizaci.
    'mediamtx_api' => vahaEnv('VAHA_MEDIAMTX_API', 'http://127.0.0.1:9997'),

    // Heslo pro přihlášení do admin.html (jedno sdílené heslo, žádné
    // uživatelské účty). Musí být nastaveno v prostředí, viz VAHA_DB_PASS výše.
    'admin_password' => vahaEnv('VAHA_ADMIN_PASSWORD'),
];
