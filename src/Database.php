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
            'audio_track_mode' => "TEXT DEFAULT 'multi'",
            'video_chroma' => "TEXT DEFAULT '420'",
            'video_bitdepth' => "INTEGER DEFAULT 8",
            'video_framing' => "TEXT DEFAULT 'IP'",
            'video_framerate' => "INTEGER DEFAULT 0",
            'video_scenecut' => "INTEGER DEFAULT 1",
            'video_open_gop' => "INTEGER DEFAULT 0",
            'ts_video_pid' => "INTEGER DEFAULT 0",
            'ts_audio_pid' => "INTEGER DEFAULT 0",
            'ts_pmt_pid' => "INTEGER DEFAULT 0",
            'ts_program' => "INTEGER DEFAULT 0",
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
            // Anzeigename fuer "Ziel 1" (die Pipeline selbst) - analog zum "name"-Feld der
            // srt_targets-Tabelle, damit das Hauptziel in der UI genauso benennbar/anzeigbar ist
            // wie die zusaetzlichen Ziele.
            'target_name' => "TEXT DEFAULT 'Ziel 1'",
            // x264enc/x265enc speed-preset - steuert nur Kompressionsaufwand/CPU-Last, nicht die
            // Latenz (die haengt allein an tune=zerolatency + Keyframe-Abstand). 'veryfast' war bisher
            // fest einprogrammiert; jetzt pro Ziel einstellbar (siehe [[project-noicecaster-repo]]).
            'video_speed_preset' => "TEXT DEFAULT 'veryfast'",
            // Ausgabeprotokoll je Ziel: 'srt' (bisher, Standard) oder 'rtmp' (nur Senden, H.264 + AAC Stereo).
            // rtmp_url ohne Stream-Key, der Key steht getrennt in rtmp_key (wird von der API nie ausgegeben).
            'output_protocol' => "TEXT DEFAULT 'srt'",
            'rtmp_url' => "TEXT DEFAULT NULL",
            'rtmp_key' => "TEXT DEFAULT NULL",
            'rtmp_deinterlace' => "INTEGER DEFAULT 0",
            // Text-Einblendung (Ziel 1 / Empfaenger-Ausgang): Text, Lage (9 Felder), Groesse in % der Bildhoehe, Farbe, Hintergrund
            'overlay_enabled' => "INTEGER DEFAULT 0",
            'overlay_text' => "TEXT DEFAULT NULL",
            'overlay_pos' => "TEXT DEFAULT 'bottom-center'",
            'overlay_size' => "INTEGER DEFAULT 5",
            'overlay_color' => "TEXT DEFAULT '#FFFFFF'",
            'overlay_bg' => "INTEGER DEFAULT 0",
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
                video_speed_preset TEXT DEFAULT 'veryfast',
                width INTEGER DEFAULT 1920,
                height INTEGER DEFAULT 1080,
                audio_codec TEXT DEFAULT 'aac',
                audio_output_channels INTEGER DEFAULT 2,
                audio_track_mode TEXT DEFAULT 'multi',
                video_chroma TEXT DEFAULT '420',
                video_bitdepth INTEGER DEFAULT 8,
                video_framing TEXT DEFAULT 'IP',
                video_framerate INTEGER DEFAULT 0,
                video_scenecut INTEGER DEFAULT 1,
                video_open_gop INTEGER DEFAULT 0,
                ts_video_pid INTEGER DEFAULT 0,
                ts_audio_pid INTEGER DEFAULT 0,
                ts_pmt_pid INTEGER DEFAULT 0,
                ts_program INTEGER DEFAULT 0,
                audio_bitrate INTEGER DEFAULT 192,
                pid INTEGER DEFAULT NULL,
                status TEXT DEFAULT 'stopped',
                started_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $existingTargetColumns = array_column($this->pdo->query("PRAGMA table_info(srt_targets)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('video_speed_preset', $existingTargetColumns, true)) {
            $this->pdo->exec("ALTER TABLE srt_targets ADD COLUMN video_speed_preset TEXT DEFAULT 'veryfast'");
        }
        if (!in_array('audio_track_mode', $existingTargetColumns, true)) {
            $this->pdo->exec("ALTER TABLE srt_targets ADD COLUMN audio_track_mode TEXT DEFAULT 'multi'");
        }
        foreach (['video_bitdepth' => 'INTEGER DEFAULT 8', 'video_chroma' => "TEXT DEFAULT '420'",
                  'video_framing' => "TEXT DEFAULT 'IP'", 'video_framerate' => 'INTEGER DEFAULT 0', 'video_scenecut' => 'INTEGER DEFAULT 1',
                  'video_open_gop' => 'INTEGER DEFAULT 0'] as $vfColumn => $vfType) {
            if (!in_array($vfColumn, $existingTargetColumns, true)) {
                $this->pdo->exec("ALTER TABLE srt_targets ADD COLUMN {$vfColumn} {$vfType}");
            }
        }
        foreach (['ts_video_pid', 'ts_audio_pid', 'ts_pmt_pid', 'ts_program'] as $tsColumn) {
            if (!in_array($tsColumn, $existingTargetColumns, true)) {
                $this->pdo->exec("ALTER TABLE srt_targets ADD COLUMN {$tsColumn} INTEGER DEFAULT 0");
            }
        }
        foreach (['output_protocol' => "TEXT DEFAULT 'srt'", 'rtmp_url' => 'TEXT DEFAULT NULL',
                  'rtmp_key' => 'TEXT DEFAULT NULL', 'rtmp_deinterlace' => 'INTEGER DEFAULT 0',
                  'overlay_enabled' => 'INTEGER DEFAULT 0', 'overlay_text' => 'TEXT DEFAULT NULL',
                  'overlay_pos' => "TEXT DEFAULT 'bottom-center'", 'overlay_size' => 'INTEGER DEFAULT 5',
                  'overlay_color' => "TEXT DEFAULT '#FFFFFF'", 'overlay_bg' => 'INTEGER DEFAULT 0'] as $rtmpColumn => $rtmpType) {
            if (!in_array($rtmpColumn, $existingTargetColumns, true)) {
                $this->pdo->exec("ALTER TABLE srt_targets ADD COLUMN {$rtmpColumn} {$rtmpType}");
            }
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
