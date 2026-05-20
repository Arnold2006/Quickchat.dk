# QuickChat.dk

Dansk, anonym chat-platform inspireret af den klassiske SuperChat.dk.

## Funktioner

- **Kategorier på forsiden** med rum-oversigt og online-tæller
- **Flere chatrum** per kategori
- **100 % anonymt** – ingen registrering eller konto
- **Hvert rum åbner i et nyt vindue** – vær aktiv i flere rum ad gangen
- **Tale i Rødt** – privat én-til-én besked vist i rød tekst; vælg modtager i drop-down + "Opdater liste"-knap
- **Privat billedoverførsel** – træk og slip et billede i chatvinduet for at sende det som privat besked (base64, maks. ≈ 384 KB rå billeddata)
- **Live opdatering** via AJAX polling hvert 2. sekund
- **Maks. 30 beskeder per rum** – ældste besked ryger ud, når ny ankommer
- **Maks. 20 brugere per rum**
- **Al site-konfiguration** (max brugere, timeout, sidenavn, forsidetekst, …) i databasen
- **Brugere og chatbeskeder** lever kun i PHP-hukommelsen (APCu) – intet gemt i databasen
- **Konfigurerbar navigationsmenu** – links administreres fra admin-panelet og vises i site-headeren
- **Kontaktformular** (`contact.php`) – besøgende kan sende en besked til admin; indlæg gemmes i databasen
- **Del-modal** på forsiden – del siden via WhatsApp, Facebook eller kopiér link
- **Skjult admin-panel** til oprettelse, sletning og sortering af kategorier, rum og menupunkter
- **Database-migreringssystem** (`upgrade.php`) – kør afventende SQL-migreringer via browser eller CLI

---

## Krav

| Komponent | Version |
|-----------|---------|
| PHP | 8.0+ |
| PHP-udvidelse | **APCu** (`apt install php-apcu`) |
| PHP-udvidelse | mbstring (standardaktiveret i PHP 8) |
| MySQL / MariaDB | 5.7+ / 10.x+ |
| Webserver | Apache med `mod_rewrite` aktiveret |

---

## Installation

### 1. Opret database og tabeller

```bash
mysql -u root -p < install.sql
```

### 2. Konfigurer forbindelsen

Kopier `config-local.php.example` til `config-local.php` (filen er ikke i git) og ret til:

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'quickchat');
define('DB_USER', 'quickchat');
define('DB_PASS', 'dit-password');

// Admin-panel – skift BEGGE værdier!
define('ADMIN_PASSWORD', 'hemmeligtPassword');
define('ADMIN_TOKEN',    'hemmeligtUrlToken');
```

### 3. Aktivér APCu

```bash
# Ubuntu/Debian
sudo apt install php-apcu
sudo phpenmod apcu
sudo systemctl restart apache2
```

Tilføj til `/etc/php/8.x/apache2/conf.d/20-apcu.ini` (eller tilsvarende):

```ini
apc.enabled=1
apc.shm_size=64M
apc.enable_cli=0
```

### 4. Apache VirtualHost (eksempel)

```apache
<VirtualHost *:80>
    ServerName quickchat.dk
    DocumentRoot /var/www/quickchat

    <Directory /var/www/quickchat>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 5. Kør database-migreringer

Efter første installation – og efter hvert fremtidigt deployment – køres eventuelle afventende migreringer:

```bash
php upgrade.php
```

Eller brug `deploy.sh` (som root/sudo), der håndterer git pull, fil-rettigheder og migreringer i ét trin:

```bash
sudo bash deploy.sh
```

---

## Admin-panel

Admin-panelet er skjult bag en URL-token **og** et password:

```
https://quickchat.dk/admin/panel.php?token=<ADMIN_TOKEN>
```

Siden returnerer 404 til alle andre.  
Log ind med `ADMIN_PASSWORD` for at:

- Oprette, slette og omsortere kategorier og rum
- Redigere forsideteksten
- Administrere navigationsmenu-punkter
- Læse og slette indkomne kontaktbeskeder

---

## Teknisk overblik

```
/
├── index.php              Forside – kategori-oversigt + del-modal
├── category.php           Kategori-side – rum-oversigt + join-modal
├── chat.php               Chatrum (åbnes i nyt vindue)
├── contact.php            Kontaktformular – besked til admin
├── config.php             DB-forbindelse, APCu-funktioner, konstanter
├── install.sql            Database-schema + standard-data
├── upgrade.php            Database migrations-runner (CLI + web)
├── deploy.sh              Deployment-hjælpescript (git pull + migreringer)
├── 404.php                404-side
├── .htaccess              Apache-direktiver
├── css/
│   └── style.css          Mørkt design
├── includes/
│   ├── header.php         Fælles HTML-header + navigationsmenu
│   └── footer.php         Fælles HTML-footer
├── api/
│   ├── fetch.php          GET  – nye beskeder siden last_id (APCu)
│   ├── send.php           POST – send besked eller billede (APCu)
│   ├── heartbeat.php      GET  – opdater bruger-tilstedeværelse (APCu)
│   ├── leave.php          GET  – forlad rum (APCu, sendBeacon)
│   ├── rooms.php          GET  – rum med online-tal (bruges af category.php)
│   └── users.php          GET  – aktive brugernavne i et rum (APCu)
├── admin/
│   └── panel.php          Skjult admin-panel
└── database/
    └── migrations/        SQL-migreringsfiler (køres af upgrade.php)
```

### Hukommelsesstruktur (APCu)

| Nøgle | Indhold |
|-------|---------|
| `qc_m{room_id}` | Array med de seneste ≤ 30 beskeder (inkl. billedbeskeder) |
| `qc_u{room_id}` | Assoc. array `username → {token, ts}` |
| `qc_ctr` | Global besked-ID-tæller (atomisk) |

Alle chat-data forsvinder, hvis PHP/APCu genstartes. Det er by design.

### Databasetabeller

| Tabel | Formål |
|-------|--------|
| `categories` | Chatrum-kategorier med ikon og rækkefølge |
| `rooms` | Chatrum tilknyttet en kategori |
| `site_config` | Nøgle/værdi-konfiguration (max brugere, sidenavn, …) |
| `nav_items` | Navigationsmenu-links |
| `contact_messages` | Indkomne beskeder fra kontaktformularen |
| `db_migrations` | Sporing af anvendte database-migreringer |
