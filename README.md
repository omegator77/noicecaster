# Noicecaster

SRT- und RTMP-Streaming-Gateway für Broadcast, gebaut auf einem 1HE-Rackserver mit
Blackmagic-DeckLink-Karte. Es wandelt SDI in SRT/RTMP und zurück und wird über eine
Weboberfläche bedient.

## Funktionen

- **SDI → SRT/RTMP:** Programmbild und Ton von der DeckLink-Karte kodieren und senden
  (H.264, H.265, MPEG-2; AAC, MP2, AC-3, Opus, PCM; 4:2:0/4:2:2, 8/10 Bit, CBR/VBR)
- **SRT/RTMP → SDI:** Ströme empfangen und auf einem DeckLink-Ausgang ausgeben, mit
  automatischem Wiederaufbau nach Verbindungsabbruch
- **RTMP-Annahme:** Encoder wie OBS senden per RTMP an das Gateway (integrierter
  MediaMTX-Hilfsserver, Zugang über Stream-Key)
- **SRT-Verschlüsselung** mit Passphrase und wählbarer Schlüssellänge
- **Mehrere DeckLink-Anschlüsse/Sub-Devices** mit eigenen Profilen und Audiokanalzahlen
- **Grafik:** Bauchbinden mit animiertem Ein- und Ausflug (eigene SVG-Vorlagen hochladbar),
  Text-Einblendung, Textbibliothek, **Key & Fill** auf zwei SDI-Ausgängen für den Bildmischer
- **Betrieb:** Presets, Testsignal, Live-Statistik (Bitrate, Verbindung, System),
  Netzwerkkonfiguration mit Bestätigung, Firewall-Verwaltung, zeitgesteuerter Neustart,
  Login mit Sperre nach Fehlversuchen

## Technik

| Bereich | Eingesetzt |
|---|---|
| Backend und Weboberfläche | PHP, Single-Page-Oberfläche, SQLite |
| Medienverarbeitung | GStreamer (Python-Skripte für Empfang, Key & Fill, A/V-Sync, Stromanalyse) |
| RTMP-Annahme | [MediaMTX](https://github.com/bluenviron/mediamtx) |
| Hardware | 1HE-Server, Blackmagic DeckLink |
| Betrieb | systemd-Dienst, Helferskripte für Netzwerk, Firewall und Neustart |

## Aufbau

```
src/        PHP-Klassen (Pipeline, Ingest, Bauchbinde, Netzwerk, Firewall, Auth, Statistik)
scripts/    Python/Shell: SRT→SDI-Empfänger, Key & Fill, DeckLink-Prüfung, Stromanalyse
public/     Weboberfläche und API (api.php)
assets/     Vorlagen für Bauchbinden (SVG)
deploy/     systemd-Dienst und Installationsskripte
```

## Installation (Kurzfassung)

Voraussetzungen: Linux, PHP 8 mit SQLite, GStreamer mit DeckLink-Plugin, Treiber der
DeckLink-Karte (von Blackmagic, nicht im Repo enthalten).

1. Repository nach `/opt/broadcast-gateway` klonen.
2. `deploy/install-mediamtx.sh` und `deploy/install-netconfig.sh` ausführen.
3. Passwort setzen: `php scripts/set-password.php`
4. Dienst installieren und starten: `deploy/broadcast-gateway.service`

## Hinweise zur Sicherheit

Die Oberfläche ist für den Betrieb in einem geschützten Netz gedacht. Solange kein
Passwort gesetzt ist, bleibt sie offen, gefährliche Funktionen (Firewall) sind dann
gesperrt. Bitte vor dem Einsatz ein Passwort setzen.
