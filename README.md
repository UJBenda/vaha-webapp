# vaha-webapp

Vážní systém a dohled - PHP/PostgreSQL aplikace pro automatické vážení
vozidel, streamování kamer (MediaMTX), rozpoznání SPZ (OCR/QR) a
administraci. Běží jako Apache vhost + PHP skripty + jeden trvalý PHP
démon (`weighing_daemon.php` jako `vaha.service`), čte váhu po síti
(RS232-to-Ethernet převodník) a ukládá do PostgreSQL.

## Nasazení na nový server

Tenhle postup počítá s tím, že **migruješ ze stávajícího běžícího
serveru** (náhrada/záloha) - tedy přenášíš reálná data (databázi,
fotky, nastavení), ne že zakládáš úplně prázdnou instalaci. Kroky dělej
v tomto pořadí, všechny příkazy spouštěj jako root/sudo, pokud není
řečeno jinak.

### 0. Na STARÉM serveru - co zazálohovat

```bash
# Databáze (kompletní, včetně dat)
sudo -u postgres pg_dump -d vaha -F c -f /root/vaha_backup.dump

# Soubor s hesly
cp /etc/vaha/vaha.env /root/vaha.env.backup

# Fotky z vážení
tar czf /root/vaha_photos.tar.gz -C /var/www/vaha photos

# systemd jednotka a cron (pro porovnání s tímhle návodem - server se
# mohl v čase odchýlit od toho, co je popsané níže)
cat /etc/systemd/system/vaha.service > /root/vaha.service.backup
crontab -u www-data -l > /root/vaha_crontab.backup

# MediaMTX config - najdi skutečné umístění (liší se podle toho, jak
# byl kontejner spuštěný, např. docker inspect mediamtx | grep -i mounts)
# a zazálohuj mediamtx.yml + docker-compose.yml/spouštěcí příkaz.
```

Všechny tyhle soubory (`vaha_backup.dump`, `vaha.env.backup`,
`vaha_photos.tar.gz`, `mediamtx.yml`, `docker-compose.yml`) přenes na
nový server, např. přes `scp`.

### 1. Systémové balíčky (nový server)

```bash
sudo apt update
sudo apt install -y apache2 postgresql php php-cli php-pgsql ffmpeg \
    python3 python3-pip git docker.io docker-compose certbot python3-certbot-apache

sudo a2enmod proxy proxy_http proxy_wstunnel rewrite ssl headers

# Ověř, že PHP umí PostgreSQL a sockets (potřebuje je weighing_daemon.php
# pro TCP spojení s váhou) - pokud sockets chybí, dřív to bývá
# samostatný balíček php-sockets, podle distribuce/verze PHP:
php -m | grep -iE "pgsql|sockets"
```

### 2. Klon repozitáře

```bash
sudo mkdir -p /var/www/vaha
sudo git clone https://github.com/UJBenda/vaha-webapp.git /var/www/vaha
cd /var/www/vaha
```

### 3. Databáze - obnovení ze zálohy

```bash
sudo -u postgres createuser vaha
sudo -u postgres createdb -O vaha vaha
sudo -u postgres psql -c "ALTER USER vaha WITH PASSWORD 'stejne-heslo-jako-v-vaha.env';"

sudo -u postgres pg_restore -d vaha /root/vaha_backup.dump
```

Pokud bys místo migrace zakládal **čistě novou databázi bez historie**,
nejspolehlivější je nechat si vypsat aktuální schéma přímo ze starého
serveru (`sudo -u postgres pg_dump -d vaha --schema-only`) a to použít -
ručně rekonstruované `CREATE TABLE` příkazy se v průběhu vývoje této
appky několikrát měnily (naposledy `weighings.is_stabilized`,
`weighing_readings.is_driver_press`, `cameras.lpr_mode` - viz git log
jednotlivých `ALTER TABLE` v commit message), takže jedině `pg_dump` ze
skutečného serveru dá jistotu, že nic nechybí.

### 4. `/etc/vaha/vaha.env` (hesla a nastavení)

Nejjednodušší je zkopírovat existující soubor přímo ze starého serveru:

```bash
sudo mkdir -p /etc/vaha
sudo cp /root/vaha.env.backup /etc/vaha/vaha.env
sudo chown root:www-data /etc/vaha/vaha.env
sudo chmod 640 /etc/vaha/vaha.env
```

Pokud ho nemáš po ruce, založ nový podle šablony `vaha.env.example` v
tomto repu (obsahuje kompletní seznam proměnných s popisem) a vyplň
skutečné hodnoty.

### 5. Fotky z vážení

```bash
sudo mkdir -p /var/www/vaha/photos
sudo tar xzf /root/vaha_photos.tar.gz -C /var/www/vaha
sudo chown -R www-data:www-data /var/www/vaha/photos
```

### 6. MediaMTX (Docker)

Zkopíruj `mediamtx.yml` a `docker-compose.yml` (nebo spouštěcí
`docker run` příkaz) ze starého serveru na stejné místo na novém a spusť:

```bash
docker compose up -d
```

Důležité z dřívějšího ladění: MediaMTX přes Docker nevidí spojení z
Apache jako "localhost", takže výchozí bezheslová výjimka pro
127.0.0.1 nefunguje - Control API proto potřebuje `authInternalUsers`
v `mediamtx.yml` a odpovídající `VAHA_MEDIAMTX_API_USER`/`_PASS` ve
`vaha.env`. Pokud to na starém serveru funguje, stačí config 1:1
zkopírovat.

### 7. systemd služba (`vaha.service`)

```bash
sudo tee /etc/systemd/system/vaha.service > /dev/null <<'EOF'
[Unit]
Description=Služba pro monitorování váhy WE2108
After=network.target postgresql.service

[Service]
Type=simple
ExecStart=/usr/bin/php /var/www/vaha/weighing_daemon.php
Restart=always
RestartSec=5
User=www-data

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now vaha.service
sudo systemctl status vaha.service
```

Porovnej se zálohou `vaha.service.backup` ze starého serveru - pokud se
tam v průběhu provozu přidalo něco navíc (např. starší `Environment=`
řádky, které už nejsou potřeba díky `vaha.env`, ale neškodí), zvaž, jestli
to chceš přenést.

### 8. cron (`sync_cameras.php`)

```bash
sudo crontab -u www-data /root/vaha_crontab.backup
```

Nebo ručně, pokud zálohu nemáš:

```bash
sudo crontab -u www-data -e
```
```cron
* * * * * php /var/www/vaha/sync_cameras.php >> /var/log/vaha-sync-cameras.log 2>&1
```

### 9. Apache vhost

```bash
sudo cp /var/www/vaha/vaha.conf /etc/apache2/sites-available/vaha.conf
sudo nano /etc/apache2/sites-available/vaha.conf
```

V souboru uprav/zkontroluj:
- `ServerName` - doména nového serveru
- `SSLCertificateFile`/`SSLCertificateKeyFile` - cesty k certifikátu
  (buď existující přenesený, nebo nově vystavený přes `certbot`)
- `Require ip` bloky - IP rozsahy sítě, pokud se na novém umístění liší
- `ProxyPassMatch`/`RewriteRule` cíl (`192.168.93.80:8889`) - interní
  adresa, na které běží MediaMTX na novém serveru

```bash
sudo a2ensite vaha.conf
sudo systemctl reload apache2
```

### 10. Python závislosti (OCR + QR rozpoznání SPZ)

```bash
pip3 install easyocr opencv-python-headless "qrcode[pil]" pillow numpy
```

EasyOCR si při prvním spuštění stáhne modely do
`/var/www/vaha/.easyocr_models` (cesta nastavená v `read_spz.py`) -
buď je nech stáhnout znovu (chvíli to trvá), nebo zkopíruj tenhle
adresář ze starého serveru, ať se vážení hned po startu nezasekne na
prvním stahování.

### 11. Oprávnění

```bash
sudo chown -R www-data:www-data /var/www/vaha
```

`weighing_daemon.php` i `force_save.php`/`driver_save.php` potřebují
zapisovat do souborů definovaných ve `vaha.env`
(`live_weight_file`, `scale_raw_file`, `force_save_flag`,
`driver_save_last_file`) i do `photos/` - všechny musí být
zapisovatelné pro `www-data` (pokud `vaha.service` běží pod jiným
uživatelem, uprav vlastníka odpovídajícím způsobem).

### 12. Ověření

```bash
sudo systemctl status vaha.service        # démon běží, čte váhu
curl -s https://<doména>/get_cameras.php  # vrací JSON se seznamem kamer
curl -s https://<doména>/driver_save.php  # {"ok":true,"cooldown_remaining_sec":0}
```

A nakonec v prohlížeči otevři dashboard, zkontroluj, že se zobrazuje
živá váha, kamery streamují a historie vážení z obnovené databáze je
vidět.

## Proměnné prostředí

Kompletní seznam s popisem je v `vaha.env.example`. Tři jsou povinné bez
výchozí hodnoty (appka bez nich spadne): `VAHA_DB_PASS`,
`VAHA_ADMIN_PASSWORD`, `VAHA_FORCE_SAVE_TOKEN`.
