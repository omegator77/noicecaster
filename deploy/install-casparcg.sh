#!/bin/sh
# Installiert CasparCG (Grafik- und Playout-Server) nach <Gateway>/bin/casparcg, ohne sudo: die beiden .deb-Pakete werden
# heruntergeladen, gegen fest eingetragene SHA-256-Pruefsummen geprueft und nur ENTPACKT (nichts wird systemweit installiert).
# Stimmt eine Pruefsumme nicht, wird nichts installiert.
# Danach prueft das Skript, ob die benoetigten Systembibliotheken und Xvfb (virtuelle Anzeige) vorhanden sind. Fehlt etwas,
# wird der passende "sudo apt install"-Befehl ausgegeben, den Sie einmalig selbst ausfuehren.
# Aufruf: sh deploy/install-casparcg.sh
set -eu

CASPAR_VERSION=2.5.1
CEF_VERSION=142.0.17
BASE="https://github.com/CasparCG/server/releases/download/v${CASPAR_VERSION}-stable"
# Nur fuer Ubuntu 26.04 (resolute) geprueft; andere Versionen haben andere Dateien und Pruefsummen.
CODENAME=resolute
SERVER_FILE="casparcg-server-2.5_${CASPAR_VERSION}.stable-resolute1_amd64.deb"
SERVER_SHA=fc14400795df7ff50a1b80f5a0edaa2d2ba4795677fb62de429164bd5fb72f72
CEF_FILE="casparcg-cef-142_${CEF_VERSION}.g60aac24+3-resolute3_amd64.deb"
CEF_SHA=f758c152354d0ba96c2222974e34132263d05c3655ffef83344b2bf58de58f5f

ROOT=$(cd "$(dirname "$0")/.." && pwd)
DEST="$ROOT/bin/casparcg"

if [ "$(uname -m)" != "x86_64" ]; then
  echo "Nur fuer x86_64 vorbereitet (gefunden: $(uname -m))." >&2
  exit 1
fi
# os-release in einer Unterschale lesen: es setzt selbst eine Variable VERSION und wuerde sonst unsere ueberschreiben
HOST_CODENAME=$( (. /etc/os-release 2>/dev/null; echo "${VERSION_CODENAME:-}") )
if [ "$HOST_CODENAME" != "$CODENAME" ]; then
  echo "Dieses Skript ist nur fuer Ubuntu 26.04 ($CODENAME) geprueft (gefunden: ${HOST_CODENAME:-unbekannt})." >&2
  exit 1
fi

if [ -x "$DEST/root/usr/bin/casparcg-server-2.5" ] && [ -f "$DEST/VERSION" ] && [ "$(cat "$DEST/VERSION")" = "$CASPAR_VERSION" ]; then
  echo "CasparCG $CASPAR_VERSION ist bereits installiert: $DEST"
else
  TMP=$(mktemp -d)
  trap 'rm -rf "$TMP"' EXIT
  for pair in "$SERVER_FILE:$SERVER_SHA" "$CEF_FILE:$CEF_SHA"; do
    file=${pair%%:*}
    sha=${pair##*:}
    echo "Lade $BASE/$file"
    curl -fsSL -o "$TMP/$file" "$BASE/$file"
    echo "$sha  $TMP/$file" | sha256sum -c -
  done
  rm -rf "$DEST.new"
  mkdir -p "$DEST.new/root"
  dpkg-deb -x "$TMP/$SERVER_FILE" "$DEST.new/root"
  dpkg-deb -x "$TMP/$CEF_FILE" "$DEST.new/root"
  echo "$CASPAR_VERSION" > "$DEST.new/VERSION"
  rm -rf "$DEST.old"
  [ -d "$DEST" ] && mv "$DEST" "$DEST.old"
  mv "$DEST.new" "$DEST"
  rm -rf "$DEST.old"
  echo "Installiert: $DEST (CasparCG $CASPAR_VERSION)"
fi

# Voraussetzungen pruefen (nur lesen)
BIN="$DEST/root/usr/bin/casparcg-server-2.5"
MISSING=$(LD_LIBRARY_PATH="$DEST/root/usr/lib/casparcg-cef-142" ldd "$BIN" 2>/dev/null | grep -c "not found" || true)
NEED_XVFB=0
command -v Xvfb >/dev/null 2>&1 || NEED_XVFB=1
if [ "$MISSING" -gt 0 ] || [ "$NEED_XVFB" -eq 1 ]; then
  echo ""
  echo "Es fehlen Systempakete. Bitte einmalig ausfuehren (braucht sudo):"
  echo "  sudo apt install -y xvfb fonts-liberation libglew2.2 libboost-log1.90.0 libboost-locale1.90.0 libboost-filesystem1.90.0 libboost-thread1.90.0 libboost-chrono1.90.0 libboost-context1.90.0 libavdevice62 libsfml-system3.0 libsfml-window3.0 libtbb12"
  exit 2
fi
echo "Alle Voraussetzungen sind erfuellt."
