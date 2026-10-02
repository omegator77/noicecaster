#!/bin/sh
# Einmalig mit sudo ausfuehren: installiert den root-Helfer fuer die
# Netzwerk-Einstellungen und erlaubt dem Gateway-Benutzer, genau diesen
# Helfer ohne Passwort aufzurufen.
set -e
USER_NAME="${1:-${SUDO_USER:-carlo}}"
DIR="$(cd "$(dirname "$0")/.." && pwd)"
install -o root -g root -m 755 "$DIR/scripts/gateway-netconfig" /usr/local/sbin/gateway-netconfig
echo "$USER_NAME ALL=(root) NOPASSWD: /usr/local/sbin/gateway-netconfig" > /etc/sudoers.d/gateway-netconfig
chmod 440 /etc/sudoers.d/gateway-netconfig
visudo -cf /etc/sudoers.d/gateway-netconfig
echo "Installiert fuer Benutzer $USER_NAME"
