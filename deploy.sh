#!/usr/bin/env bash
# deploy.sh — Pull the latest code and fix file-system ownership / permissions.
#
# Run this script (as root or with sudo) from the project root after every
# deployment instead of running "sudo git pull" on its own.
#
# Usage:
#   sudo bash deploy.sh                       # default web-server user (www)
#   sudo WEB_USER=www-data bash deploy.sh     # Debian / Ubuntu
#   sudo WEB_USER=apache     bash deploy.sh   # RHEL / CentOS / AlmaLinux
#
# The script must be run from the root directory of the Quickchat.dk project.

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
# Set WEB_USER to the user your web server runs as.
# Common values:
#   www       — FreeBSD, OpenBSD
#   www-data  — Debian, Ubuntu
#   apache    — RHEL, CentOS, AlmaLinux, Fedora
#   nginx     — some Nginx-only stacks
WEB_USER="${WEB_USER:-www}"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
info()  { echo "  --> $*"; }
error() { echo "ERROR: $*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Guards
# ---------------------------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
    error "This script must be run as root (use sudo)."
fi

if [[ ! -f "index.php" ]]; then
    error "Run this script from the Quickchat.dk project root directory."
fi

if ! id -u "${WEB_USER}" &>/dev/null; then
    error "Web-server user '${WEB_USER}' not found. Set WEB_USER= to the correct user."
fi

# ---------------------------------------------------------------------------
# Steps
# ---------------------------------------------------------------------------
echo "==> Pulling latest code..."
git pull origin main

echo "==> Setting ownership of project files to ${WEB_USER}:${WEB_USER}..."
find . -not -path './.git/*' -not -path './.git' \
    | xargs chown "${WEB_USER}:${WEB_USER}"

echo "==> Setting file permissions..."
# Directories: 755, files: 644
find . -not -path './.git/*' -type d -exec chmod 755 {} +
find . -not -path './.git/*' -type f -exec chmod 644 {} +

# Make this script itself executable
chmod +x deploy.sh

echo ""
echo "==> Deployment complete."
echo "    Web-server user : ${WEB_USER}"
echo ""
echo "    Remember to keep config.php out of version control and ensure"
echo "    DB credentials are correct on the server."
