#!/bin/sh
# Einmalig mit sudo ausfuehren (nach jeder Aenderung an den Helfern erneut):
# installiert die root-Helfer fuer Netzwerk und Firewall und erlaubt dem
# Gateway-Benutzer, genau diese Helfer ohne Passwort aufzurufen.
set -e
USER_NAME="${1:-${SUDO_USER:-carlo}}"
DIR="$(cd "$(dirname "$0")/.." && pwd)"
install -o root -g root -m 755 "$DIR/scripts/gateway-netconfig" /usr/local/sbin/gateway-netconfig
install -o root -g root -m 755 "$DIR/scripts/gateway-firewall" /usr/local/sbin/gateway-firewall
cat > /etc/sudoers.d/gateway-netconfig <<EOF
$USER_NAME ALL=(root) NOPASSWD: /usr/local/sbin/gateway-netconfig
$USER_NAME ALL=(root) NOPASSWD: /usr/local/sbin/gateway-firewall
EOF
chmod 440 /etc/sudoers.d/gateway-netconfig
visudo -cf /etc/sudoers.d/gateway-netconfig
echo "Installiert fuer Benutzer $USER_NAME"
