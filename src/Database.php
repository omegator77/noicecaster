<?php

class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Seit PHP_CLI_SERVER_WORKERS>1 koennen mehrere Requests gleichzeitig schreiben wollen -
        // ohne busy_timeout wirft SQLite dann sofort "database is locked" statt kurz zu warten.
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->initSchema();
    }

    private function initSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS pipelines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                source_type TEXT NOT NULL DEFAULT 'videotestsrc',
                source_pattern TEXT DEFAULT 'smpte',
                width INTEGER DEFAULT 1280,
                height INTEGER DEFAULT 720,
                framerate INTEGER DEFAULT 25,
                bitrate INTEGER DEFAULT 2000,
                srt_port INTEGER DEFAULT 8888,
                srt_latency INTEGER DEFAULT 120,
                pid INTEGER DEFAULT NULL,
                status TEXT DEFAULT 'stopped',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $existing = array_column($this->pdo->query("PRAGMA table_info(pipelines)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        $columns = [
            'srt_mode' => "TEXT DEFAULT 'listener'",
            'srt_target_host' => "TEXT DEFAULT NULL",
            'source_file' => "TEXT DEFAULT NULL",
            'include_audio' => "INTEGER DEFAULT 0",
            'started_at' => "DATETIME DEFAULT NULL",
            'decklink_device_number' => "INTEGER DEFAULT 0",
            'decklink_connection' => "TEXT DEFAULT 'auto'",
            'decklink_mode' => "TEXT DEFAULT 'auto'",
            'decklink_profile' => "TEXT DEFAULT 'two-sub-devices-half'",
            'decklink_audio_channels' => "INTEGER DEFAULT 2",
            'pipeline_direction' => "TEXT DEFAULT 'sdi_to_srt'",
            'audio_codec' => "TEXT DEFAULT 'aac'",
            'audio_output_channels' => "INTEGER DEFAULT 2",
            'audio_bitrate' => "INTEGER DEFAULT 192",
            'video_codec' => "TEXT DEFAULT 'h264'",
            'video_rate_control' => "TEXT DEFAULT 'cbr'",
            'video_keyframe_interval' => "INTEGER DEFAULT 0",
            'decklink_scale_enabled' => "INTEGER DEFAULT 0",
            'video_scale_enabled' => "INTEGER DEFAULT 0",
            'srt_passphrase' => "TEXT DEFAULT NULL",
            'srt_pbkeylen' => "INTEGER DEFAULT 0",
            'srt_streamid' => "TEXT DEFAULT NULL",
            'clock_overlay_enabled' => "INTEGER DEFAULT 0",
            'audio_test_wave' => "TEXT DEFAULT 'sine'",
            'preview_enabled' => "INTEGER DEFAULT 0",
            'test_pattern_enabled' => "INTEGER DEFAULT 0",
            // Fuer SDI-Eingaenge mit mehreren SRT-Zielen (siehe srt_targets-Tabelle unten): ein
            // gemeinsamer "Verteiler"-Prozess oeffnet die Buchse einmal und speist die Rohbilder in
            // Shared Memory (shmsink) ein. status/pid oben bleiben "Ziel 1" (das urspruengliche,
            // einzelne SRT-Ziel dieser Pipeline) - distributor_pid/-status sind der zusaetzliche,
            // von allen Zielen gemeinsam genutzte Aufnahme-Prozess.
            'distributor_pid' => "INTEGER DEFAULT NULL",
            'distributor_status' => "TEXT DEFAULT 'stopped'",
        ];
        foreach ($columns as $name => $definition) {
            if (!in_array($name, $existing, true)) {
                $this->pdo->exec("ALTER TABLE pipelines ADD COLUMN {$name} {$definition}");
            }
        }

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS decklink_ports (
                device_number INTEGER PRIMARY KEY,
                role TEXT NOT NULL DEFAULT 'unused',
                label TEXT DEFAULT NULL
            )
        ");
        $insertPort = $this->pdo->prepare("INSERT OR IGNORE INTO decklink_ports (device_number, role) VALUES (:n, 'unused')");
        for ($i = 0; $i < 4; $i++) {
            $insertPort->execute([':n' => $i]);
        }

        $existingPortColumns = array_column($this->pdo->query("PRAGMA table_info(decklink_ports)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('mode', $existingPortColumns, true)) {
            $this->pdo->exec("ALTER TABLE decklink_ports ADD COLUMN mode TEXT DEFAULT 'auto'");

            // Der SDI-Modus wandert von der Pipeline in den Anschluss - bereits konfigurierte
            // Modi aus bestehenden Pipelines übernehmen, damit die Migration keine laufende
            // Konfiguration stillschweigend auf 'auto' zurücksetzt.
            $modeByDevice = [];
            $existingPipelineModes = $this->pdo->query("
                SELECT decklink_device_number, decklink_mode FROM pipelines
                WHERE decklink_mode IS NOT NULL AND decklink_mode != 'auto'
                ORDER BY id DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($existingPipelineModes as $row) {
                $deviceNumber = (int)$row['decklink_device_number'];
                if (!isset($modeByDevice[$deviceNumber])) {
                    $modeByDevice[$deviceNumber] = $row['decklink_mode'];
                }
            }
            $updatePortMode = $this->pdo->prepare("UPDATE decklink_ports SET mode = :mode WHERE device_number = :device_number");
            foreach ($modeByDevice as $deviceNumber => $mode) {
                $updatePortMode->execute([':mode' => $mode, ':device_number' => $deviceNumber]);
            }

            // Ausgänge können nicht im Auto-Modus laufen - Sicherheitsnetz für alles, was oben nicht abgedeckt wurde
            $this->pdo->exec("UPDATE decklink_ports SET mode = '1080i50' WHERE role = 'output' AND mode = 'auto'");
        }

        // Zusaetzliche SRT-Ziele fuer SDI-Eingaenge (Advanced-Menue) - "Ziel 1" bleibt weiterhin auf
        // der pipelines-Zeile selbst (srt_mode/srt_port/video_codec/... dort, unveraendert), diese
        // Tabelle haelt nur Ziel 2, 3, ... Jedes Ziel hat seine eigenen Codec-/Bitrate-/SRT-Einstellungen
        // und laeuft als eigener Prozess, der sich per shmsrc an den gemeinsamen Verteiler-Prozess der
        // Pipeline (siehe distributor_pid/-status oben) anhaengt.
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS srt_targets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pipeline_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                srt_mode TEXT DEFAULT 'listener',
                srt_port INTEGER DEFAULT 8888,
                srt_target_host TEXT DEFAULT NULL,
                srt_latency INTEGER DEFAULT 120,
                srt_passphrase TEXT DEFAULT NULL,
                srt_pbkeylen INTEGER DEFAULT 0,
                srt_streamid TEXT DEFAULT NULL,
                video_codec TEXT DEFAULT 'h264',
                bitrate INTEGER DEFAULT 2000,
                video_rate_control TEXT DEFAULT 'cbr',
                video_keyframe_interval INTEGER DEFAULT 0,
                video_scale_enabled INTEGER DEFAULT 0,
                width INTEGER DEFAULT 1920,
                height INTEGER DEFAULT 1080,
                audio_codec TEXT DEFAULT 'aac',
                audio_output_channels INTEGER DEFAULT 2,
                audio_bitrate INTEGER DEFAULT 192,
                pid INTEGER DEFAULT NULL,
                status TEXT DEFAULT 'stopped',
                started_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
