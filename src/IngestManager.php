<?php

/**
 * RTMP-Annahme (Ingest) fuer Empfaenger-Anschluesse: Encoder wie OBS senden per RTMP an das Gateway, ein Empfaenger
 * (output_protocol 'rtmp_in') holt den Strom lokal ab und gibt ihn am SDI-Ausgang aus.
 *
 * GStreamer kann RTMP nicht als Server annehmen. Darum laeuft ein kleiner Hilfsserver (MediaMTX, nur RTMP, installiert mit
 * deploy/install-mediamtx.sh nach bin/mediamtx), den das Gateway selbst als normalen Prozess von "carlo" startet und stoppt:
 *  - Er laeuft nur, solange mindestens ein Empfaenger mit RTMP-Annahme laeuft.
 *  - In der Konfiguration (data/mediamtx.yml, wird bei jeder Aenderung neu geschrieben, MediaMTX uebernimmt sie im Betrieb
 *    ohne bestehende Verbindungen zu trennen) stehen ausschliesslich die Pfade "live/<Stream-Key>" der laufenden
 *    Empfaenger. Andere Pfade werden abgelehnt, der Stream-Key ist also die Zugangsberechtigung zum Senden.
 *  - Lesen darf nur der eigene Rechner (127.0.0.1), der Empfaenger-Prozess; von aussen kann niemand mitsehen.
 *  - Port: 1935 (TCP). Ob er von aussen erreichbar ist, entscheidet die Firewall (Abschnitt "Firewall").
 */
class IngestManager
{
    public const PORT = 1935;
    public const APP = 'live';

    public static function binary(): string
    {
        return __DIR__ . '/../bin/mediamtx';
    }

    public static function installed(): bool
    {
        return is_file(self::binary()) && is_executable(self::binary());
    }

    private static function dataDir(): string
    {
        return __DIR__ . '/../data';
    }

    public static function configPath(): string
    {
        return self::dataDir() . '/mediamtx.yml';
    }

    private static function pidPath(): string
    {
        return self::dataDir() . '/mediamtx.pid';
    }

    public static function logPath(): string
    {
        return self::dataDir() . '/mediamtx.log';
    }

    /** Lokale Adresse, unter der der Empfaenger den Strom abholt. */
    public static function localUrl(string $key): string
    {
        return 'rtmp://127.0.0.1:' . self::PORT . '/' . self::APP . '/' . $key;
    }

    /** Nur sichere Pfad-Bestandteile in die Konfiguration schreiben (der Key ist bereits validiert, das hier ist ein zweites Netz). */
    private static function safeKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9._~-]{1,128}$/', $key) === 1;
    }

    /**
     * Konfiguration fuer die gegebenen Stream-Keys.
     * @param string[] $keys
     */
    public static function buildConfig(array $keys): string
    {
        $keys = array_values(array_unique(array_filter($keys, fn($k) => self::safeKey((string)$k))));
        sort($keys);
        $yml = <<<YML
# Von IngestManager.php erzeugt, nicht von Hand aendern (wird bei jeder Aenderung neu geschrieben).
logLevel: info
api: false
metrics: false
pprof: false
playback: false
rtsp: false
hls: false
webrtc: false
srt: false
moq: false
rtmp: true
rtmpAddress: :%d
rtmpEncryption: "no"
authMethod: internal
authInternalUsers:
  # Lesen nur vom eigenen Rechner (der Empfaenger-Prozess)
  - user: any
    ips: [127.0.0.1, "::1"]
    permissions:
      - action: read
  # Senden von ueberall, aber nur auf die unten eingetragenen Pfade (der Stream-Key)
  - user: any
    ips: []
    permissions:
      - action: publish
paths:

YML;
        $yml = sprintf($yml, self::PORT);
        if (!$keys) {
            return str_replace("paths:\n", "paths: {}\n", $yml);
        }
        foreach ($keys as $key) {
            $yml .= '  ' . self::APP . '/' . $key . ":\n";
        }
        return $yml;
    }

    /** Stream-Keys aller laufenden Empfaenger mit RTMP-Annahme. */
    public static function runningKeys(PDO $db): array
    {
        $rows = $db->query("SELECT rtmp_key FROM pipelines WHERE pipeline_direction = 'srt_to_sdi' AND output_protocol = 'rtmp_in' AND status = 'running' AND rtmp_key IS NOT NULL")
            ->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    public static function pid(): ?int
    {
        $pid = (int)@file_get_contents(self::pidPath());
        if ($pid <= 0 || !function_exists('posix_kill') || !@posix_kill($pid, 0)) {
            return null;
        }
        // PID-Wiederverwendung ausschliessen: es muss wirklich unser mediamtx sein
        $cmd = @file_get_contents("/proc/{$pid}/cmdline");
        return ($cmd !== false && str_contains($cmd, 'mediamtx')) ? $pid : null;
    }

    public static function running(): bool
    {
        return self::pid() !== null;
    }

    /** Konfiguration auf den Stand der laufenden Empfaenger bringen und den Server bei Bedarf starten (kein Eintrag: stoppen). */
    public static function sync(PDO $db, array $extraKeys = []): void
    {
        // $extraKeys: Empfaenger, der gerade gestartet wird und in der DB noch nicht als "laufend" steht
        $keys = array_values(array_unique(array_merge(self::runningKeys($db), array_map('strval', $extraKeys))));
        if (!$keys) {
            self::stop();
            return;
        }
        if (!self::installed()) {
            throw new RuntimeException('Der RTMP-Server ist nicht installiert (deploy/install-mediamtx.sh ausfuehren).');
        }
        $yml = self::buildConfig($keys);
        $path = self::configPath();
        if (@file_get_contents($path) !== $yml) {
            $tmp = $path . '.tmp';
            if (@file_put_contents($tmp, $yml) === false || !@rename($tmp, $path)) {
                throw new RuntimeException('RTMP-Server-Konfiguration nicht schreibbar');
            }
        }
        if (self::running()) {
            return; // MediaMTX liest die geaenderte Datei selbst neu ein
        }
        $cmd = 'nohup ' . escapeshellarg(self::binary()) . ' ' . escapeshellarg($path) . ' > ' . escapeshellarg(self::logPath()) . ' 2>&1 & echo $!';
        $pid = (int)shell_exec($cmd);
        @file_put_contents(self::pidPath(), (string)$pid);
        // Kurz warten: bindet der Server den Port nicht (z. B. schon belegt), beendet er sich sofort
        for ($i = 0; $i < 10; $i++) {
            usleep(200000);
            if (!self::running()) {
                break;
            }
        }
        usleep(300000);
        if (!self::running()) {
            $tail = trim((string)@shell_exec('tail -n 3 ' . escapeshellarg(self::logPath()) . ' 2>/dev/null'));
            throw new RuntimeException('Der RTMP-Server ist nicht gestartet (Port ' . self::PORT . ' belegt?): ' . $tail);
        }
    }

    public static function stop(): void
    {
        $pid = self::pid();
        if ($pid !== null) {
            @posix_kill($pid, SIGTERM);
            for ($i = 0; $i < 30 && self::running(); $i++) {
                usleep(100000);
            }
            if (self::running()) {
                @posix_kill($pid, SIGKILL);
            }
        }
        @unlink(self::pidPath());
    }

    /** Fuer die Oberflaeche: Zustand des Servers und die Adresse, an die Encoder senden (Host je nach Aufruf der Seite). */
    public static function status(PDO $db): array
    {
        return [
            'installed' => self::installed(),
            'running' => self::running(),
            'port' => self::PORT,
            'app' => self::APP,
            'keys_active' => count(self::runningKeys($db)),
        ];
    }
}
