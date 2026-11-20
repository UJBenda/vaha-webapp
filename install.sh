#!/usr/bin/env bash
# Interaktivní instalátor vaha-webapp pro čistou instalaci na Debian 13.
# Spouštěj jako root z adresáře, kam jsi naklonoval tento repozitář:
#
#   git clone https://github.com/UJBenda/vaha-webapp.git /var/www/vaha
#   cd /var/www/vaha
#   sudo ./install.sh
#
# Skript se ptá na vše potřebné (DB, hostname, SSL) a nastaví: systémové
# balíčky, PostgreSQL (lokální nebo vzdálenou DB), /etc/vaha/vaha.env,
# Apache vhost + SSL, systemd službu vaha.service, cron pro
# sync_cameras.php, Python závislosti pro OCR/QR a MediaMTX (Docker).
#
# Je navržený jako idempotentní, kde to dává smysl (dá se spustit znovu
# po opravě chyby), ale NENÍ to migrační nástroj - na čistou instalaci,
# ne na přenos existujících dat ze starého serveru (na to viz README.md).

set -euo pipefail

# ----- Pomocné funkce -----

C_RESET='\033[0m'; C_BOLD='\033[1m'; C_GREEN='\033[32m'; C_YELLOW='\033[33m'; C_RED='\033[31m'

info()  { echo -e "${C_BOLD}==> $*${C_RESET}"; }
ok()    { echo -e "${C_GREEN}✓ $*${C_RESET}"; }
warn()  { echo -e "${C_YELLOW}! $*${C_RESET}"; }
fail()  { echo -e "${C_RED}✗ $*${C_RESET}"; }

ask() {
    # ask "otázka" "výchozí_hodnota" -> echo odpověď
    local prompt="$1" default="${2:-}" answer
    if [ -n "$default" ]; then
        read -r -p "$prompt [$default]: " answer
        echo "${answer:-$default}"
    else
        read -r -p "$prompt: " answer
        echo "$answer"
    fi
}

ask_secret() {
    # ask_secret "otázka" -> echo odpověď (skrytý vstup, bez výchozí hodnoty)
    local prompt="$1" answer
    read -r -s -p "$prompt: " answer
    echo >&2
    echo "$answer"
}

ask_yn() {
    # ask_yn "otázka" "y|n (default)" -> vrátí 0 (ano) nebo 1 (ne)
    local prompt="$1" default="${2:-y}" answer
    while true; do
        read -r -p "$prompt [${default}]: " answer
        answer="${answer:-$default}"
        case "$answer" in
            [Yy]*) return 0 ;;
            [Nn]*) return 1 ;;
            *) echo "Odpověz y/n." ;;
        esac
    done
}

random_token() {
    openssl rand -hex 24
}

if [ "$(id -u)" -ne 0 ]; then
    fail "Spusť jako root (sudo ./install.sh)."
    exit 1
fi

APP_DIR_DEFAULT="/var/www/vaha"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo
echo "========================================================"
echo " Instalace vaha-webapp (vážní systém a dohled) - Debian 13"
echo "========================================================"
echo

# ===========================================================
# 1) Interaktivní otázky
# ===========================================================

info "Adresář aplikace"
APP_DIR=$(ask "Kam appku nainstalovat" "$APP_DIR_DEFAULT")

info "Databáze"
DB_HOST=$(ask "Hostname/IP PostgreSQL serveru (prázdné = lokální instalace)" "localhost")
DB_PORT=$(ask "Port PostgreSQL" "5432")
DB_NAME=$(ask "Jméno databáze" "vaha")
DB_USER=$(ask "Databázový uživatel appky" "vaha")

DB_IS_LOCAL=0
if [ "$DB_HOST" = "localhost" ] || [ "$DB_HOST" = "127.0.0.1" ]; then
    DB_IS_LOCAL=1
fi

if [ "$DB_IS_LOCAL" -eq 1 ]; then
    info "DB běží lokálně - skript nainstaluje PostgreSQL a sám založí uživatele i databázi."
    if ask_yn "Vygenerovat náhodné heslo pro DB uživatele automaticky?" "y"; then
        DB_PASS=$(random_token)
        echo "Vygenerované heslo pro DB: $DB_PASS"
    else
        DB_PASS=$(ask_secret "Heslo pro DB uživatele '$DB_USER'")
    fi
else
    info "DB je na vzdáleném serveru - předpokládá se, že uživatel a databáze už tam existují"
    info "(tenhle skript na vzdáleném serveru nic nezakládá, jen appku nastaví, aby se tam připojila)."
    DB_PASS=$(ask_secret "Heslo pro DB uživatele '$DB_USER' na $DB_HOST")
fi

# Pro vložení do SQL řetězce (ALTER/CREATE USER ... PASSWORD '...') -
# zdvojení uvozovky je standardní SQL escapování, kdyby heslo obsahovalo '.
DB_PASS_SQL=${DB_PASS//\'/\'\'}

info "Webový server"
HOSTNAME=$(ask "Doménové jméno appky (např. vaha.firma.cz)" "")
if [ -z "$HOSTNAME" ]; then
    fail "Hostname je povinný."
    exit 1
fi

echo
echo "Appka řídí váhu a otevírá kamery - doporučujeme ji nenechávat volně"
echo "přístupnou z celého internetu. Zadej IP rozsahy, odkud má být appka"
echo "dostupná (např. 192.168.1.0/24), oddělené mezerou. Prázdné = bez"
echo "omezení (přístupné odkudkoliv, admin.html je pak chráněný jen heslem)."
read -r -a ALLOWED_IP_RANGES -p "Povolené IP rozsahy: "

USE_HTTPS=1
if ! ask_yn "Nastavit appku na HTTPS (443) - doporučeno, vyžaduje certifikát? Ne = jen HTTP (80)" "y"; then
    USE_HTTPS=0
fi

SSL_MODE=""
SSL_CERT_SRC=""
SSL_KEY_SRC=""
if [ "$USE_HTTPS" -eq 1 ]; then
    echo
    echo "Jak získat SSL certifikát?"
    echo "  1) Let's Encrypt (certbot) - vyžaduje, aby doména '$HOSTNAME' už teď"
    echo "     ukazovala na tenhle server a port 80 byl zvenku dostupný"
    echo "     (DNS + prostupy si musí připravit IT admin předem)."
    echo "  2) Vygenerovat vlastní (self-signed) certifikát - prohlížeč bude"
    echo "     hlásit nedůvěryhodné spojení, dokud certifikát ručně nedůvěřuješ."
    echo "  3) Zkopírovat existující certifikát (cesty k souborům zadáš)."
    ssl_choice=$(ask "Volba (1/2/3)" "1")
    case "$ssl_choice" in
        1)
            SSL_MODE="letsencrypt"
            LETSENCRYPT_EMAIL=$(ask "E-mail pro upozornění od Let's Encrypt (expirace apod.)" "")
            ;;
        2) SSL_MODE="selfsigned" ;;
        3)
            SSL_MODE="copy"
            SSL_CERT_SRC=$(ask "Cesta k existujícímu certifikátu (fullchain.pem)" "")
            SSL_KEY_SRC=$(ask "Cesta k existujícímu klíči (privkey.pem)" "")
            ;;
        *) fail "Neplatná volba."; exit 1 ;;
    esac
fi

info "Administrace"
ADMIN_PASSWORD=$(ask_secret "Heslo pro přihlášení do administrace (admin.html)")
if [ -z "$ADMIN_PASSWORD" ]; then
    fail "Heslo administrace nesmí být prázdné."
    exit 1
fi

FORCE_SAVE_TOKEN=$(random_token)

echo
if ! ask_yn "Nastavit časovou zónu systému na Europe/Prague?" "y"; then
    SET_TIMEZONE=0
else
    SET_TIMEZONE=1
fi

SETUP_BACKUP_CRON=0
if ask_yn "Nastavit noční automatickou zálohu databáze (cron, pg_dump do /var/backups/vaha)?" "y"; then
    SETUP_BACKUP_CRON=1
fi

SETUP_UFW=0
if ask_yn "Nastavit firewall (ufw) - povolit jen SSH/80/443?" "n"; then
    SETUP_UFW=1
fi

echo
info "Shrnutí:"
echo "  Adresář appky:   $APP_DIR"
echo "  Databáze:        $DB_USER@$DB_HOST:$DB_PORT/$DB_NAME $( [ "$DB_IS_LOCAL" -eq 1 ] && echo '(lokální, skript ji založí)' || echo '(vzdálená, musí už existovat)')"
echo "  Hostname:        $HOSTNAME"
if [ "${#ALLOWED_IP_RANGES[@]}" -gt 0 ]; then
    echo "  Povolené IP:     ${ALLOWED_IP_RANGES[*]}"
else
    echo "  Povolené IP:     (bez omezení - přístupné odkudkoliv)"
fi
echo "  HTTPS:           $( [ "$USE_HTTPS" -eq 1 ] && echo "ano ($SSL_MODE)" || echo 'ne (jen port 80)')"
echo "  Časová zóna:     $( [ "$SET_TIMEZONE" -eq 1 ] && echo 'Europe/Prague' || echo 'beze změny')"
echo "  Záloha DB (cron):$( [ "$SETUP_BACKUP_CRON" -eq 1 ] && echo ' ano' || echo ' ne')"
echo "  Firewall (ufw):  $( [ "$SETUP_UFW" -eq 1 ] && echo 'ano' || echo 'ne')"
echo
if ! ask_yn "Pokračovat s instalací?" "y"; then
    echo "Zrušeno."
    exit 0
fi

# ===========================================================
# 2) Systémové balíčky
# ===========================================================

info "Instaluji systémové balíčky (chvíli to potrvá)..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq

PACKAGES=(apache2 php php-cli php-pgsql ffmpeg python3 python3-pip python3-venv git curl rsync cron openssl ca-certificates gnupg)
if [ "$DB_IS_LOCAL" -eq 1 ]; then
    PACKAGES+=(postgresql postgresql-contrib)
fi
if [ "$USE_HTTPS" -eq 1 ] && [ "$SSL_MODE" = "letsencrypt" ]; then
    PACKAGES+=(certbot python3-certbot-apache)
fi
if [ "$SETUP_UFW" -eq 1 ]; then
    PACKAGES+=(ufw)
fi

apt-get install -y -qq "${PACKAGES[@]}"
ok "Balíčky nainstalovány."

# Docker - oficiální skript, Debian repo mívá starší verzi
if ! command -v docker &>/dev/null; then
    info "Instaluji Docker (pro MediaMTX)..."
    curl -fsSL https://get.docker.com | sh
    ok "Docker nainstalován."
else
    ok "Docker už je nainstalovaný."
fi

a2enmod proxy proxy_http proxy_wstunnel rewrite headers >/dev/null
if [ "$USE_HTTPS" -eq 1 ]; then
    a2enmod ssl >/dev/null
fi
systemctl enable --now apache2 >/dev/null
systemctl enable --now cron >/dev/null

if ! php -m | grep -qi sockets; then
    warn "PHP rozšíření 'sockets' nebylo nalezeno - weighing_daemon.php ho potřebuje pro spojení s váhou."
    warn "Na Debianu 13 bývá součástí balíčku php (CLI SAPI) - pokud chybí, zkus 'apt-get install php-sockets' a zkontroluj znovu."
fi

if [ "$SET_TIMEZONE" -eq 1 ]; then
    timedatectl set-timezone Europe/Prague || warn "Nepodařilo se nastavit časovou zónu."
fi

# ===========================================================
# 3) Kopie aplikace do APP_DIR
# ===========================================================

if [ "$SCRIPT_DIR" != "$APP_DIR" ]; then
    info "Kopíruji appku z $SCRIPT_DIR do $APP_DIR..."
    mkdir -p "$APP_DIR"
    rsync -a --exclude '.git' "$SCRIPT_DIR"/ "$APP_DIR"/
else
    ok "Skript už běží přímo v $APP_DIR."
fi

mkdir -p "$APP_DIR/photos"

# ===========================================================
# 4) PostgreSQL
# ===========================================================

info "Databáze..."

if [ "$DB_IS_LOCAL" -eq 1 ]; then
    systemctl enable --now postgresql >/dev/null

    if sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'" | grep -q 1; then
        warn "DB uživatel '$DB_USER' už existuje, heslo přenastavím."
        sudo -u postgres psql -c "ALTER USER \"$DB_USER\" WITH PASSWORD '$DB_PASS_SQL';" >/dev/null
    else
        sudo -u postgres psql -c "CREATE USER \"$DB_USER\" WITH PASSWORD '$DB_PASS_SQL';" >/dev/null
    fi

    if sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" | grep -q 1; then
        warn "Databáze '$DB_NAME' už existuje, schéma níže se vytvoří jen pokud tabulky ještě nejsou (IF NOT EXISTS)."
    else
        sudo -u postgres psql -c "CREATE DATABASE \"$DB_NAME\" OWNER \"$DB_USER\";" >/dev/null
    fi

    PSQL_RUN=(sudo -u postgres psql -d "$DB_NAME")
else
    info "Ověřuji spojení na vzdálenou DB $DB_HOST:$DB_PORT..."
    if ! PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -tAc "SELECT 1" >/dev/null 2>&1; then
        fail "Nepodařilo se připojit na $DB_HOST:$DB_PORT jako $DB_USER/$DB_NAME."
        fail "Zkontroluj, že databáze a uživatel na vzdáleném serveru existují a heslo sedí, pak spusť skript znovu."
        exit 1
    fi
    ok "Spojení na vzdálenou DB funguje."
    PSQL_RUN=(env PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME")
fi

info "Zakládám schéma (pokud ještě neexistuje)..."
"${PSQL_RUN[@]}" <<'SQL'
CREATE TABLE IF NOT EXISTS camera_templates (
    id SERIAL PRIMARY KEY,
    brand_name VARCHAR(100) NOT NULL,
    url_template TEXT NOT NULL,
    notes TEXT
);

CREATE TABLE IF NOT EXISTS cameras (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    template_id INTEGER REFERENCES camera_templates(id),
    ip_address VARCHAR(50) NOT NULL,
    username VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_weighing_photo BOOLEAN DEFAULT true,
    show_on_dashboard BOOLEAN DEFAULT false,
    lpr_mode VARCHAR(10) NOT NULL DEFAULT 'off' CHECK (lpr_mode IN ('off','ocr','qr','both'))
);

CREATE TABLE IF NOT EXISTS weighings (
    id SERIAL PRIMARY KEY,
    weight NUMERIC(10,2) NOT NULL,
    timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    photo_path VARCHAR(255),
    spz VARCHAR(20),
    is_stabilized BOOLEAN NOT NULL DEFAULT true
);

CREATE TABLE IF NOT EXISTS weighing_photos (
    id SERIAL PRIMARY KEY,
    weighing_id INTEGER REFERENCES weighings(id) ON DELETE CASCADE,
    camera_id INTEGER REFERENCES cameras(id) ON DELETE SET NULL,
    photo_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS weighing_readings (
    id SERIAL PRIMARY KEY,
    weighing_id INTEGER NOT NULL REFERENCES weighings(id) ON DELETE CASCADE,
    reading_time TIMESTAMP NOT NULL,
    weight NUMERIC(10,2) NOT NULL,
    is_stable BOOLEAN NOT NULL DEFAULT false,
    is_button_press BOOLEAN NOT NULL DEFAULT false,
    is_driver_press BOOLEAN NOT NULL DEFAULT false
);
CREATE INDEX IF NOT EXISTS idx_weighing_readings_weighing_id ON weighing_readings(weighing_id);

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    description TEXT
);
SQL

if [ "$DB_IS_LOCAL" -eq 1 ]; then
    sudo -u postgres psql -d "$DB_NAME" -c "GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO \"$DB_USER\";" >/dev/null
    sudo -u postgres psql -d "$DB_NAME" -c "GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO \"$DB_USER\";" >/dev/null
fi
ok "Databáze připravena."

# ===========================================================
# 5) /etc/vaha/vaha.env
# ===========================================================

info "Zapisuji /etc/vaha/vaha.env..."
mkdir -p /etc/vaha
cat > /etc/vaha/vaha.env <<EOF
VAHA_DB_HOST=$DB_HOST
VAHA_DB_PORT=$DB_PORT
VAHA_DB_NAME=$DB_NAME
VAHA_DB_USER=$DB_USER
VAHA_DB_PASS=$DB_PASS
VAHA_ADMIN_PASSWORD=$ADMIN_PASSWORD
VAHA_FORCE_SAVE_TOKEN=$FORCE_SAVE_TOKEN
VAHA_PHOTO_STORAGE_PATH=$APP_DIR/photos/
VAHA_READ_SPZ_SCRIPT=$APP_DIR/read_spz.py
VAHA_READ_QR_SCRIPT=$APP_DIR/read_qr.py
VAHA_LIVE_WEIGHT_FILE=$APP_DIR/live_weight.txt
VAHA_SCALE_RAW_FILE=$APP_DIR/scale_raw.txt
VAHA_FORCE_SAVE_FLAG=$APP_DIR/force_save.flag
VAHA_DRIVER_SAVE_LAST_FILE=$APP_DIR/driver_save_last.txt
EOF
chown root:www-data /etc/vaha/vaha.env
chmod 640 /etc/vaha/vaha.env
ok "/etc/vaha/vaha.env zapsán."

# ===========================================================
# 6) SSL certifikát
# ===========================================================

SSL_CERT_PATH=""
SSL_KEY_PATH=""

if [ "$USE_HTTPS" -eq 1 ]; then
    case "$SSL_MODE" in
        selfsigned)
            info "Generuji self-signed certifikát..."
            mkdir -p /etc/ssl/vaha
            openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
                -keyout /etc/ssl/vaha/privkey.pem \
                -out /etc/ssl/vaha/fullchain.pem \
                -subj "/CN=$HOSTNAME" >/dev/null 2>&1
            SSL_CERT_PATH=/etc/ssl/vaha/fullchain.pem
            SSL_KEY_PATH=/etc/ssl/vaha/privkey.pem
            warn "Self-signed certifikát - prohlížeče ho budou hlásit jako nedůvěryhodný, dokud ho ručně nepřidáš mezi výjimky/důvěryhodné kořeny."
            ;;
        copy)
            mkdir -p /etc/ssl/vaha
            cp "$SSL_CERT_SRC" /etc/ssl/vaha/fullchain.pem
            cp "$SSL_KEY_SRC" /etc/ssl/vaha/privkey.pem
            SSL_CERT_PATH=/etc/ssl/vaha/fullchain.pem
            SSL_KEY_PATH=/etc/ssl/vaha/privkey.pem
            ok "Certifikát zkopírován."
            ;;
        letsencrypt)
            # Certbot potřebuje, aby vhost na portu 80 už existoval a odpovídal
            # na doméně, než se o certifikát požádá - nejdřív tedy vytvoříme
            # dočasný HTTP-only vhost, pak zkusíme certbot, teprve pak
            # finální vhost s HTTPS (viz sekce 7 níže).
            ;;
    esac
fi

# ===========================================================
# 7) Apache vhost
# ===========================================================

info "Nastavuji Apache vhost..."

VHOST_FILE="/etc/apache2/sites-available/vaha.conf"

ACCESS_BLOCK="        Require all granted"
if [ "${#ALLOWED_IP_RANGES[@]}" -gt 0 ]; then
    ACCESS_BLOCK="        Require all denied"
    for range in "${ALLOWED_IP_RANGES[@]}"; do
        ACCESS_BLOCK="$ACCESS_BLOCK"$'\n'"        Require ip $range"
    done
fi

write_http_only_vhost() {
    cat > "$VHOST_FILE" <<EOF
<VirtualHost *:80>
    ServerName $HOSTNAME
    DocumentRoot $APP_DIR

    <Directory $APP_DIR>
        AllowOverride All
$ACCESS_BLOCK
    </Directory>

    ProxyRequests Off
    ProxyPreserveHost On
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} websocket [NC]
    RewriteCond %{HTTP:Connection} upgrade [NC]
    RewriteRule ^/([A-Za-z0-9_-]+)/whep/?\$ ws://127.0.0.1:8889/\$1/whep [P,L]
    ProxyPassMatch ^/([A-Za-z0-9_-]+)/whep\$ http://127.0.0.1:8889/\$1/whep

    ErrorLog \${APACHE_LOG_DIR}/vaha-error.log
    CustomLog \${APACHE_LOG_DIR}/vaha-access.log combined
</VirtualHost>
EOF
}

write_https_vhost() {
    cat > "$VHOST_FILE" <<EOF
<VirtualHost *:80>
    ServerName $HOSTNAME
    Redirect permanent / https://$HOSTNAME/
</VirtualHost>

<VirtualHost *:443>
    ServerName $HOSTNAME
    DocumentRoot $APP_DIR

    SSLEngine on
    SSLCertificateFile $SSL_CERT_PATH
    SSLCertificateKeyFile $SSL_KEY_PATH

    <Directory $APP_DIR>
        AllowOverride All
$ACCESS_BLOCK
    </Directory>

    ProxyRequests Off
    ProxyPreserveHost On
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} websocket [NC]
    RewriteCond %{HTTP:Connection} upgrade [NC]
    RewriteRule ^/([A-Za-z0-9_-]+)/whep/?\$ ws://127.0.0.1:8889/\$1/whep [P,L]
    ProxyPassMatch ^/([A-Za-z0-9_-]+)/whep\$ http://127.0.0.1:8889/\$1/whep

    ErrorLog \${APACHE_LOG_DIR}/vaha-error.log
    CustomLog \${APACHE_LOG_DIR}/vaha-access.log combined
</VirtualHost>
EOF
}

a2dissite 000-default >/dev/null 2>&1 || true

if [ "$USE_HTTPS" -eq 1 ] && [ "$SSL_MODE" = "letsencrypt" ]; then
    write_http_only_vhost
    a2ensite vaha.conf >/dev/null
    systemctl restart apache2

    info "Zkouším získat certifikát přes Let's Encrypt pro $HOSTNAME..."
    info "(vyžaduje funkční DNS záznam na tenhle server a otevřený port 80 zvenku)"
    CERTBOT_EMAIL_ARGS=(--register-unsafely-without-email)
    if [ -n "$LETSENCRYPT_EMAIL" ]; then
        CERTBOT_EMAIL_ARGS=(-m "$LETSENCRYPT_EMAIL")
    fi
    if certbot --apache -d "$HOSTNAME" --non-interactive --agree-tos "${CERTBOT_EMAIL_ARGS[@]}" --redirect; then
        ok "Certifikát od Let's Encrypt získán, certbot upravil vhost sám (auto-renew je nastavený systemd timerem certbot.timer)."
    else
        fail "Certbot selhal - nejspíš DNS/port-forwarding ještě není hotový."
        warn "Appka teď poběží na HTTP (port 80). Až bude DNS/prostup funkční, spusť:"
        warn "  certbot --apache -d $HOSTNAME"
    fi
elif [ "$USE_HTTPS" -eq 1 ]; then
    write_https_vhost
    a2ensite vaha.conf >/dev/null
    systemctl restart apache2
else
    write_http_only_vhost
    a2ensite vaha.conf >/dev/null
    systemctl restart apache2
fi

ok "Apache vhost nastaven."

# ===========================================================
# 8) systemd služba
# ===========================================================

info "Nastavuji vaha.service..."
cat > /etc/systemd/system/vaha.service <<EOF
[Unit]
Description=Služba pro monitorování váhy
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/php $APP_DIR/weighing_daemon.php
Restart=always
RestartSec=5
User=www-data

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable vaha.service >/dev/null
ok "vaha.service nastavena (spustí se na konci instalace)."

# ===========================================================
# 9) cron pro sync_cameras.php
# ===========================================================

info "Nastavuji cron pro sync_cameras.php..."
CRON_LINE="* * * * * php $APP_DIR/sync_cameras.php >> /var/log/vaha-sync-cameras.log 2>&1"
( crontab -u www-data -l 2>/dev/null | grep -vF "sync_cameras.php" || true; echo "$CRON_LINE" ) | crontab -u www-data -
ok "cron nastaven."

if [ "$SETUP_BACKUP_CRON" -eq 1 ]; then
    info "Nastavuji noční zálohu databáze..."
    mkdir -p /var/backups/vaha
    cat > /usr/local/bin/vaha-backup-db.sh <<EOF
#!/usr/bin/env bash
set -euo pipefail
STAMP=\$(date +%Y%m%d)
EOF
    if [ "$DB_IS_LOCAL" -eq 1 ]; then
        cat >> /usr/local/bin/vaha-backup-db.sh <<EOF
sudo -u postgres pg_dump -d "$DB_NAME" -F c -f "/var/backups/vaha/vaha-\$STAMP.dump"
EOF
    else
        # printf %q zajistí, že heslo je ve vygenerovaném skriptu bezpečně
        # escapované, i kdyby obsahovalo uvozovky nebo jiné speciální znaky.
        DB_PASS_SHELL_QUOTED=$(printf '%q' "$DB_PASS")
        cat >> /usr/local/bin/vaha-backup-db.sh <<EOF
PGPASSWORD=$DB_PASS_SHELL_QUOTED pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -F c -f "/var/backups/vaha/vaha-\$STAMP.dump"
EOF
    fi
    cat >> /usr/local/bin/vaha-backup-db.sh <<'EOF'
find /var/backups/vaha -name "vaha-*.dump" -mtime +30 -delete
EOF
    chmod 700 /usr/local/bin/vaha-backup-db.sh
    ( crontab -l 2>/dev/null | grep -vF "vaha-backup-db.sh" || true; echo "15 2 * * * /usr/local/bin/vaha-backup-db.sh" ) | crontab -
    ok "Noční záloha nastavena (02:15, /var/backups/vaha, 30 dní historie)."
fi

# ===========================================================
# 10) MediaMTX (Docker)
# ===========================================================

info "Nastavuji MediaMTX (Docker)..."
mkdir -p /opt/mediamtx
MEDIAMTX_API_USER="vaha_api"
MEDIAMTX_API_PASS=$(random_token)

cat > /opt/mediamtx/mediamtx.yml <<EOF
# Základní konfigurace MediaMTX pro vaha-webapp. Cesty (paths) pro
# jednotlivé kamery vytváří/maže automaticky camera_sync.php přes
# Control API (viz mediamtx_client.php) - není potřeba je sem psát ručně.
#
# Pozor: přes Docker nevidí MediaMTX spojení z Apache jako "localhost",
# takže výchozí bezheslová výjimka pro 127.0.0.1 nefunguje - Control API
# proto vyžaduje authInternalUsers (viz authInternalUsers níže) a appka
# se k němu hlásí přes VAHA_MEDIAMTX_API_USER/VAHA_MEDIAMTX_API_PASS.

api: yes
apiAddress: :9997

authMethod: internal
authInternalUsers:
  - user: $MEDIAMTX_API_USER
    pass: $MEDIAMTX_API_PASS
    permissions:
      - action: api
      - action: metrics
      - action: pprof
  - user: any
    permissions:
      - action: publish
      - action: read
      - action: playback

webrtc: yes
webrtcAddress: :8889

paths:
  all_others:
EOF

cat > /opt/mediamtx/docker-compose.yml <<'EOF'
services:
  mediamtx:
    image: bluenviron/mediamtx:latest
    container_name: mediamtx
    restart: unless-stopped
    network_mode: host
    volumes:
      - ./mediamtx.yml:/mediamtx.yml:ro
EOF

(cd /opt/mediamtx && docker compose up -d) || warn "Nepodařilo se spustit MediaMTX kontejner - zkontroluj 'docker compose logs' v /opt/mediamtx."

# doplnit MediaMTX auth do vaha.env
if ! grep -q "^VAHA_MEDIAMTX_API_USER=" /etc/vaha/vaha.env; then
    cat >> /etc/vaha/vaha.env <<EOF
VAHA_MEDIAMTX_API=http://127.0.0.1:9997
VAHA_MEDIAMTX_API_USER=$MEDIAMTX_API_USER
VAHA_MEDIAMTX_API_PASS=$MEDIAMTX_API_PASS
EOF
fi

warn "MediaMTX config v /opt/mediamtx/mediamtx.yml je rozumný základ (API s autentizací, WebRTC/WHEP zapnuté),"
warn "ale nebyl testovaný proti konkrétní verzi MediaMTX na tomhle stroji - po přidání první kamery"
warn "v administraci zkontroluj 'docker compose logs -f' v /opt/mediamtx, jestli se cesta (path) vytvořila."

# ===========================================================
# 11) Python závislosti (OCR + QR)
# ===========================================================

info "Instaluji Python závislosti pro rozpoznání SPZ..."
pip3 install --break-system-packages --quiet easyocr opencv-python-headless "qrcode[pil]" pillow numpy \
    || warn "Instalace Python balíčků selhala - zkus ručně: pip3 install --break-system-packages easyocr opencv-python-headless qrcode[pil] pillow numpy"
mkdir -p "$APP_DIR/.easyocr_models"
ok "Python závislosti nainstalovány (modely EasyOCR se stáhnou při prvním použití)."

# ===========================================================
# 12) Oprávnění
# ===========================================================

info "Nastavuji oprávnění..."
chown -R www-data:www-data "$APP_DIR"
ok "Oprávnění nastavena."

# ===========================================================
# 13) Firewall (volitelné)
# ===========================================================

if [ "$SETUP_UFW" -eq 1 ]; then
    info "Nastavuji ufw..."
    ufw allow OpenSSH >/dev/null
    ufw allow 80/tcp >/dev/null
    if [ "$USE_HTTPS" -eq 1 ]; then
        ufw allow 443/tcp >/dev/null
    fi
    ufw --force enable >/dev/null
    ok "ufw zapnutý (SSH, 80$( [ "$USE_HTTPS" -eq 1 ] && echo ', 443' ))."
fi

# ===========================================================
# 14) Start a ověření
# ===========================================================

info "Spouštím vaha.service..."
systemctl restart vaha.service
sleep 2

echo
echo "========================================================"
echo " Shrnutí instalace"
echo "========================================================"

if systemctl is-active --quiet vaha.service; then
    ok "vaha.service běží."
else
    fail "vaha.service NEBĚŽÍ - zkontroluj: journalctl -u vaha.service -n 50"
fi

if systemctl is-active --quiet apache2; then
    ok "Apache běží."
else
    fail "Apache neběží - zkontroluj: systemctl status apache2 ; apache2ctl configtest"
fi

if docker ps --format '{{.Names}}' | grep -q '^mediamtx$'; then
    ok "MediaMTX kontejner běží."
else
    fail "MediaMTX kontejner neběží - zkontroluj: cd /opt/mediamtx && docker compose logs"
fi

PROTO="http"; [ "$USE_HTTPS" -eq 1 ] && PROTO="https"
echo
echo "Appka by měla být dostupná na: $PROTO://$HOSTNAME/"
echo "Administrace:                  $PROTO://$HOSTNAME/admin.html"
echo
echo "Heslo administrace:   (zadal jsi ho, nikam se neukládá do výpisu)"
echo "Token pro tlačítko u váhy (force_save.php): $FORCE_SAVE_TOKEN"
echo "  - použij ve webhooku: $PROTO://$HOSTNAME/force_save.php?token=$FORCE_SAVE_TOKEN"
echo
echo "Všechny hodnoty jsou uložené v /etc/vaha/vaha.env - uprav tam, pokud"
echo "je potřeba něco změnit, a restartuj 'systemctl restart vaha.service'."
echo
echo "Další kroky:"
echo "  1) V administraci (admin.html) přidej kamery a šablony."
echo "  2) Nastav parametry vážení (Nastavení váhy) podle reálné hardwarové váhy."
echo "  3) weighing_daemon.php se zatím připojuje na pevně nastavenou IP/port"
echo "     váhy - zkontroluj \$scale_ip/\$scale_port na začátku weighing_daemon.php."
echo "  4) Otestuj tlačítko u váhy a/nebo tlačítko 'Vážení řidič' na dashboardu."
echo
