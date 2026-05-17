# QuickChat.dk

**QuickChat.dk** er en dansk, anonym chat-platform inspireret af den legendariske SuperChat.dk. Ingen registrering er nødvendig — vælg blot et brugernavn og hop ind i et chatrum.

---

## Funktioner

- 💬 **Flere chatrum** — vælg mellem tilgængelige rum på forsiden
- 👤 **100% anonymt** — ingen registrering eller konto
- 🪟 **Hvert rum åbner i et nyt vindue** — vær i flere rum på én gang
- 🔴 **Tale i Rødt** — privat én-til-én chat med rød tekst (se nedenfor)
- 🔄 **Live opdatering** via AJAX polling (hvert 2. sekund)
- 👥 **Max 30 brugere** pr. rum
- ⚙️ **Skjult admin panel** til oprettelse og sletning af chatrum

---

## Krav

- **PHP 8.x** eller nyere
- **MySQL 5.7+** eller **MariaDB 10.x+**
- **Apache** webserver med `mod_rewrite` aktiveret

---

## Installation

### 1. Database

Kør `install.sql` mod din MySQL/MariaDB server:

```bash
mysql -u din_bruger -p < install.sql
```

Dette opretter databasen `quickchat` med tabellerne `rooms`, `messages` og `room_users`, samt tre standard chatrum.

### 2. Konfiguration

Åbn `config.php` og ret følgende konstanter:

```php
define('DB_HOST', 'localhost');             // Database host
define('DB_NAME', 'quickchat');             // Database navn
define('DB_USER', 'dit_db_brugernavn');     // Database bruger
define('DB_PASS', 'dit_db_password');       // Database adgangskode
define('MAX_USERS', 30);                    // Maks brugere per rum
define('USER_TIMEOUT', 45);                 // Sekunder før bruger anses som offline
define('ADMIN_PASSWORD', 'dit-stærke-password-her');     // Admin adgangskode – SKIFT DETTE!
define('ADMIN_TOKEN', 'dit-tilfældige-url-token-her');   // URL-token til admin panel – SKIFT DETTE!
```

> ⚠️ **Vigtigt:** Skift både `ADMIN_PASSWORD` og `ADMIN_TOKEN` til noget unikt og sikkert, inden du sætter siden i drift.

### 3. Webserver

Placer filerne i din Apache webservers dokument-rod (f.eks. `/var/www/html/quickchat`). Sørg for at `AllowOverride All` er aktiveret i din Apache-konfiguration, så `.htaccess` virker korrekt.

---

## Admin panel

Admin-panelet er beskyttet bag to lag:

1. **URL-token:** Naviger til `https://quickchat.dk/admin/panel.php?token=DIT_ADMIN_TOKEN`
   - Forkert token returnerer HTTP 404.
2. **Adgangskode:** Indtast den adgangskode du har sat i `ADMIN_PASSWORD`.

Via admin-panelet kan du:
- Oprette nye chatrum
- Slette eksisterende chatrum (bekræftelse kræves)
- Se antal online brugere og beskeder per rum

---

## "Tale i Rødt"

**"Tale i Rødt"** er QuickChat.dk's funktion til privat én-til-én chat — direkte inspireret af SuperChat.dk's ikoniske funktion:

1. I chatrummet ses en **dropdown-liste** ("Tal i Rødt med:") øverst i inputfeltet.
2. Vælg den bruger du vil tale privat med.
3. Dine beskeder sendes **kun til den valgte bruger** (og dig selv).
4. Private beskeder vises med **rød tekst** og rød venstre-kant — deraf udtrykket *"at tale i rødt"*.
5. Vælg **"-- Alle (offentlig) --"** for at sende beskeder til hele rummet igen.

---

## Filstruktur

```
config.php          – Database og konfiguration
install.sql         – SQL til oprettelse af database og tabeller
index.php           – Forside med rumoversigt
chat.php            – Chatrum
api/
  fetch.php         – Hent nye beskeder (AJAX)
  send.php          – Send besked (AJAX)
  users.php         – Liste over online brugere (AJAX)
  heartbeat.php     – Opdater brugerens online-status (AJAX)
  leave.php         – Forlad chatrum (AJAX / sendBeacon)
  rooms.php         – Rumliste med brugerantal (AJAX)
admin/
  panel.php         – Admin panel
css/
  style.css         – Mørkt tema (mørkeblå + rød accent)
.htaccess           – Beskytter config.php
```
