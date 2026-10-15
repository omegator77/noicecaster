#!/bin/sh
# Installiert den RTMP-Hilfsserver (MediaMTX) fuer die RTMP-Annahme nach <Gateway>/bin/mediamtx.
# Kein sudo noetig, laeuft als der Benutzer, dem das Gateway gehoert. Die Version und ihre SHA-256-Pruefsumme sind fest
# eingetragen: stimmt die Pruefsumme der heruntergeladenen Datei nicht, wird nichts installiert.
# Aufruf: sh deploy/install-mediamtx.sh
set -eu

VERSION=1.21.2
FILE="mediamtx_v${VERSION}_linux_amd64.tar.gz"
SHA256=121be6e00397e473a95d83c75f4fcd3819a6af3b0e1a50889cba43ab4fab6a27
URL="https://github.com/bluenviron/mediamtx/releases/download/v${VERSION}/${FILE}"

ROOT=$(cd "$(dirname "$0")/.." && pwd)
DEST="$ROOT/bin"

if [ "$(uname -m)" != "x86_64" ]; then
  echo "Nur fuer x86_64 vorbereitet (gefunden: $(uname -m))." >&2
  exit 1
fi

if [ -x "$DEST/mediamtx" ] && [ -f "$DEST/VERSION" ] && [ "$(cat "$DEST/VERSION")" = "$VERSION" ]; then
  echo "MediaMTX $VERSION ist bereits installiert: $DEST/mediamtx"
  exit 0
fi

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
echo "Lade $URL"
curl -fsSL -o "$TMP/$FILE" "$URL"
echo "$SHA256  $TMP/$FILE" | sha256sum -c -

mkdir -p "$TMP/x" "$DEST"
tar -xzf "$TMP/$FILE" -C "$TMP/x" mediamtx LICENSE
install -m 0755 "$TMP/x/mediamtx" "$DEST/mediamtx.new"
mv -f "$DEST/mediamtx.new" "$DEST/mediamtx"
cp -f "$TMP/x/LICENSE" "$DEST/LICENSE-mediamtx"
echo "$VERSION" > "$DEST/VERSION"
echo "Installiert: $DEST/mediamtx (Version $VERSION)"
