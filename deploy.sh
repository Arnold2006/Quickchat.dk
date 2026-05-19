#!/usr/bin/env bash
# deploy.sh — Hent seneste kode og ret fil-rettigheder.
#
# Kør dette script (som root eller med sudo) fra projektets rodmappe efter
# hvert deployment i stedet for at køre "sudo git pull" direkte.
#
# Brug:
#   sudo bash deploy.sh                       # standard webserver-bruger (www)
#   sudo WEB_USER=www-data bash deploy.sh     # Debian / Ubuntu
#   sudo WEB_USER=apache     bash deploy.sh   # RHEL / CentOS / AlmaLinux
#
# Scriptet skal køres fra roden af Quickchat.dk-projektet.

set -euo pipefail

# ---------------------------------------------------------------------------
# Konfiguration
# ---------------------------------------------------------------------------
# Sæt WEB_USER til den bruger din webserver kører som.
# Typiske værdier:
#   www       — FreeBSD, OpenBSD
#   www-data  — Debian, Ubuntu
#   apache    — RHEL, CentOS, AlmaLinux, Fedora
WEB_USER="${WEB_USER:-www}"

# ---------------------------------------------------------------------------
# Hjælpefunktioner
# ---------------------------------------------------------------------------
info()  { echo "  --> $*"; }
error() { echo "ERROR: $*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Tjek
# ---------------------------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
    error "Dette script skal køres som root (brug sudo)."
fi

if [[ ! -f "config.php" ]]; then
    error "Kør dette script fra Quickchat.dk-projektets rodmappe."
fi

if ! id -u "${WEB_USER}" &>/dev/null; then
    error "Webserver-bruger '${WEB_USER}' blev ikke fundet. Sæt WEB_USER= til den rigtige bruger."
fi

if [[ ! -f "config-local.php" ]]; then
    echo "ADVARSEL: config-local.php mangler."
    echo "          Kopiér config-local.php.example til config-local.php og udfyld dine indstillinger."
fi

# ---------------------------------------------------------------------------
# Trin
# ---------------------------------------------------------------------------
echo "==> Henter seneste kode..."
git pull

echo "==> Sætter fil-tilladelser..."
# Alle PHP-filer og mapper sættes til 644/755 (webserveren behøver ikke skrive til dem).
find . -not -path './.git/*' -type f -name '*.php' -exec chmod 644 {} +
find . -not -path './.git/*' -type d              -exec chmod 755 {} +

# config-local.php må kun læses af root og webserver-brugeren.
if [[ -f "config-local.php" ]]; then
    chown "root:${WEB_USER}" config-local.php
    chmod 640 config-local.php
    info "Rettigheder på config-local.php sat til 640 (root:${WEB_USER})"
fi

# Giv webserveren skriveadgang til migrations-mappen, så upgrade.php kan slette
# migreringsfiler efter de er anvendt.
if [[ -d "database/migrations" ]]; then
    chown -R "${WEB_USER}:${WEB_USER}" database/migrations
    chmod -R 750 database/migrations
    info "Rettigheder på database/migrations sat (${WEB_USER}:${WEB_USER}, 755)"
fi

echo "==> Kører database-migreringer..."
php upgrade.php

echo ""
echo "==> Deployment fuldført."
echo "    Webserver-bruger : ${WEB_USER}"
echo ""
echo "    Husk: config-local.php skal eksistere på serveren med dine rigtige"
echo "    DB-oplysninger og admin-adgangskoder (brug config-local.php.example)."

