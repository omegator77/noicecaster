<?php

class PipelineManager
{
    private PDO $db;
    private DecklinkPortManager $ports;
    private static ?int $cpuCoreCount = null;

    private const DECKLINK_CONNECTIONS = ['auto', 'sdi', 'optical-sdi'];
    private const DECKLINK_PROFILES = [
        'default',
        'one-sub-device-full',
        'one-sub-device-half',
        'two-sub-devices-full',
        'two-sub-devices-half',
        'four-sub-devices-half',
    ];
    private const DECKLINK_AUDIO_CHANNELS = [2, 8, 16, 0];
    private const DIRECTIONS = ['sdi_to_srt', 'srt_to_sdi'];
    private const AUDIO_CODECS = ['aac', 'mp2', 'ac3', 'opus', 'pcm'];
    // VORERST nur 2 und 4 Ausgabekanaele (4 = 4.0, vollbandig; Entscheidung 2026-10-07). Mehr geht mit dem
    // Magewell nicht sauber: AAC mit 6/8 Kanaelen hat einen LFE-Platz (Kanal 4, nur bis ca. 250 Hz), AAC mit
    // eigenem Layout (PCE) ist mitten im Strom nicht dekodierbar, Opus/AC-3/PCM spielt der Magewell nicht.
    // Technisch moegliche Obergrenzen je Codec (spaeter wieder freigeben): aac/opus 2,4,8,16 - pcm 2,4,6,8 -
    // ac3 2,4,6 - mp2 2.
    private const AUDIO_OUTPUT_CHANNELS = [2, 4];
    // Stereo-Spuren (je 2 Kanaele eine eigene Spur, eigene PID) haben die Probleme der Mehrkanal-Spur nicht (kein
    // LFE-Platz, kein PCE): jede Spur ist ein normales Stereo-Paar. Hier sind alle geraden Kanalzahlen bis 16
    // (8 Spuren) frei; mehr als der Aufnahme (decklink_audio_channels) gibt es nicht.
    private const AUDIO_PAIR_CHANNELS = [2, 4, 6, 8, 10, 12, 14, 16];
    // Kanalzahlen, die der jeweilige Encoder tatsaechlich unterstuetzt (Codec-Spezifikation) -
    // z.B. akzeptiert avenc_mp2 nur Mono/Stereo, avenc_s302m (PCM) max. 8 Kanaele.
    private const AUDIO_CODEC_CHANNELS = [
        'aac' => [2, 4],
        'opus' => [2, 4],
        'pcm' => [2, 4],
        'ac3' => [2, 4],
        'mp2' => [2],
    ];
    private const VIDEO_CODECS = ['h264', 'h265', 'mpeg2'];
    private const VIDEO_RATE_CONTROLS = ['cbr', 'vbr'];
    // x264enc/x265enc speed-preset - nur bis 'medium' erlaubt, langsamere Presets (slow/slower/
    // veryslow/placebo) schaffen es bei 1080i/1080p-Echtzeitkodierung typischerweise nicht mehr
    // rechtzeitig und wuerden Frames droppen statt Latenz/Qualitaet zu verbessern.
    private const VIDEO_SPEED_PRESETS = ['ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium'];
    private const SRT_PBKEYLEN = [0, 16, 24, 32];
    private const AUDIO_TEST_WAVES = ['sine', 'silence'];

    // Feste Raw-Caps pro Decklink-Modus, noetig fuer den Verteiler/Ziel-Mechanismus (mehrere SRT-Ziele
    // pro SDI-Eingang, siehe [[project-noicecaster-srt-to-sdi]]): shmsrc uebertraegt Caps NICHT
    // automatisch vom Verteiler-Prozess - jedes Ziel muss sie explizit kennen, um sein eigenes
    // videoconvert/encoder korrekt zu verhandeln. Verifiziert 2026-09-30 per echtem shmsink/shmsrc-Test.
    // Nur HD-Modi abgedeckt - SD (ntsc/pal) und "auto" werden fuer mehrere Ziele bewusst nicht
    // unterstuetzt (bei "auto" ist die tatsaechliche Aufloesung/Framerate erst zur Laufzeit bekannt,
    // je nachdem was angeschlossen ist - das laesst sich nicht im Voraus in Caps giessen).
    private const HD_MODE_CAPS = [
        '720p50'    => ['width' => 1280, 'height' => 720,  'fps_num' => 50,    'fps_den' => 1,    'interlaced' => false],
        '720p5994'  => ['width' => 1280, 'height' => 720,  'fps_num' => 60000, 'fps_den' => 1001, 'interlaced' => false],
        '720p60'    => ['width' => 1280, 'height' => 720,  'fps_num' => 60,    'fps_den' => 1,    'interlaced' => false],
        '1080p2398' => ['width' => 1920, 'height' => 1080, 'fps_num' => 24000, 'fps_den' => 1001, 'interlaced' => false],
        '1080p24'   => ['width' => 1920, 'height' => 1080, 'fps_num' => 24,    'fps_den' => 1,    'interlaced' => false],
        '1080p25'   => ['width' => 1920, 'height' => 1080, 'fps_num' => 25,    'fps_den' => 1,    'interlaced' => false],
        '1080p2997' => ['width' => 1920, 'height' => 1080, 'fps_num' => 30000, 'fps_den' => 1001, 'interlaced' => false],
        '1080p30'   => ['width' => 1920, 'height' => 1080, 'fps_num' => 30,    'fps_den' => 1,    'interlaced' => false],
        '1080i50'   => ['width' => 1920, 'height' => 1080, 'fps_num' => 25,    'fps_den' => 1,    'interlaced' => true],
        '1080i5994' => ['width' => 1920, 'height' => 1080, 'fps_num' => 30000, 'fps_den' => 1001, 'interlaced' => true],
        '1080i60'   => ['width' => 1920, 'height' => 1080, 'fps_num' => 30,    'fps_den' => 1,    'interlaced' => true],
        '1080p50'   => ['width' => 1920, 'height' => 1080, 'fps_num' => 50,    'fps_den' => 1,    'interlaced' => false],
        '1080p5994' => ['width' => 1920, 'height' => 1080, 'fps_num' => 60000, 'fps_den' => 1001, 'interlaced' => false],
        '1080p60'   => ['width' => 1920, 'height' => 1080, 'fps_num' => 60,    'fps_den' => 1,    'interlaced' => false],
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ports = new DecklinkPortManager($db);
    }

    public function list(): array
    {
        $stmt = $this->db->query("SELECT * FROM pipelines ORDER BY id");
        $pipelines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Status live nachprüfen, falls ein Prozess von außen gestorben ist
        foreach ($pipelines as &$p) {
            if ($p['status'] === 'running' && !$this->isProcRunning($p['pid'] !== null ? (int)$p['pid'] : null)) {
                $this->updateStatus((int)$p['id'], 'stopped', null);
                $p['status'] = 'stopped';
                $p['pid'] = null;
            }
        }

        return $pipelines;
    }

    private function validatePortUsage(array $data, array $decklink, string $direction, ?int $excludeId = null): void
    {
        $sourceType = $data['source_type'] ?? 'videotestsrc';
        $isPortBound = $direction === 'srt_to_sdi' || $sourceType === 'decklink';

        if ($direction === 'srt_to_sdi') {
            $this->ports->assertRole($decklink['device_number'], 'output');
        } elseif ($sourceType === 'decklink') {
            $this->ports->assertRole($decklink['device_number'], 'input');
        }

        if ($isPortBound) {
            // Jeder Anschluss darf pro Richtung nur eine Pipeline haben (feste 1:1-Zuordnung Anschluss <-> Pipeline-Kachel)
            $sql = "SELECT id FROM pipelines WHERE decklink_device_number = :device_number AND pipeline_direction = :direction";
            $params = [':device_number' => $decklink['device_number'], ':direction' => $direction];
            if ($direction === 'sdi_to_srt') {
                $sql .= " AND source_type = 'decklink'";
            }
            if ($excludeId !== null) {
                $sql .= " AND id != :exclude_id";
                $params[':exclude_id'] = $excludeId;
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            if ($stmt->fetchColumn() !== false) {
                throw new RuntimeException("Für Anschluss " . ($decklink['device_number'] + 1) . " existiert in dieser Richtung bereits eine Pipeline.");
            }
        }
    }

    /**
     * Prueft Zahlenbereiche, Aufzaehlungen und die Zieladresse. Nur vorhandene Felder werden geprueft.
     * Wichtig auch fuer die Sicherheit: die Zieladresse landet im Shell-Befehl der Pipeline (srt://HOST:PORT),
     * ein Wert wie $(...) wuerde dort ausgefuehrt. Darum nur Hostnamen und IP-Adressen zulassen.
     */
    private function validateSettings(array $data): void
    {
        $ranges = [
            'srt_port' => ['SRT-Port', 1, 65535],
            'srt_latency' => ['SRT-Latenz', 20, 30000],
            'bitrate' => ['Video-Bitrate', 100, 100000],
            'audio_bitrate' => ['Audio-Bitrate', 32, 2048],
            'width' => ['Breite', 64, 8192],
            'height' => ['Hoehe', 64, 8192],
            'framerate' => ['Bildrate', 1, 120],
            'video_keyframe_interval' => ['Keyframe-Intervall', 0, 1000],
            'decklink_device_number' => ['SDI-Anschluss', 0, 3],
        ];
        foreach ($ranges as $key => [$label, $min, $max]) {
            if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                continue;
            }
            if (!is_numeric($data[$key]) || (float)$data[$key] != (int)$data[$key] || (int)$data[$key] < $min || (int)$data[$key] > $max) {
                throw new RuntimeException("{$label} muss eine ganze Zahl zwischen {$min} und {$max} sein");
            }
        }
        if (isset($data['srt_mode']) && !in_array($data['srt_mode'], ['listener', 'caller'], true)) {
            throw new RuntimeException("SRT-Modus muss 'listener' oder 'caller' sein");
        }
        $host = $data['srt_target_host'] ?? null;
        if ($host !== null && $host !== '' &&
            !preg_match('/^[A-Za-z0-9]([A-Za-z0-9.:-]{0,251}[A-Za-z0-9])?$/', (string)$host)) {
            throw new RuntimeException("Ziel-Adresse darf nur Buchstaben, Ziffern, Punkt, Doppelpunkt und Bindestrich enthalten");
        }
        foreach (['name' => 100, 'target_name' => 100, 'srt_streamid' => 512] as $key => $max) {
            if (isset($data[$key]) && strlen((string)$data[$key]) > $max) {
                throw new RuntimeException("{$key} ist zu lang (maximal {$max} Zeichen)");
            }
        }
    }

    public function create(array $data): int
    {
        $this->validateSettings($data);
        $decklink = $this->sanitizeDecklink($data);
        $direction = $this->sanitizeDirection($data);
        $audio = $this->sanitizeAudio($data);
        $ts = $this->sanitizeTsIds($data, $audio);
        $videoCodec = $this->sanitizeVideoCodec($data);
        $rateControl = $this->sanitizeVideoRateControl($data);
        $keyframeInterval = (int)($data['video_keyframe_interval'] ?? 0);
        $scaleEnabled = (int)($data['video_scale_enabled'] ?? 0) === 1 ? 1 : 0;
        $srtSecurity = $this->sanitizeSrtSecurity($data);
        $this->validatePortUsage($data, $decklink, $direction);
        $stmt = $this->db->prepare("
            INSERT INTO pipelines (name, source_type, source_pattern, width, height, framerate, bitrate, srt_port, srt_latency, srt_mode, srt_target_host, source_file, decklink_device_number, decklink_connection, decklink_profile, decklink_audio_channels, pipeline_direction, audio_codec, audio_output_channels, audio_track_mode, ts_video_pid, ts_audio_pid, ts_pmt_pid, ts_program, audio_bitrate, video_codec, video_rate_control, video_keyframe_interval, video_scale_enabled, srt_passphrase, srt_pbkeylen, srt_streamid, clock_overlay_enabled, audio_test_wave, preview_enabled, test_pattern_enabled, target_name, video_speed_preset)
            VALUES (:name, :source_type, :source_pattern, :width, :height, :framerate, :bitrate, :srt_port, :srt_latency, :srt_mode, :srt_target_host, :source_file, :decklink_device_number, :decklink_connection, :decklink_profile, :decklink_audio_channels, :pipeline_direction, :audio_codec, :audio_output_channels, :audio_track_mode, :ts_video_pid, :ts_audio_pid, :ts_pmt_pid, :ts_program, :audio_bitrate, :video_codec, :video_rate_control, :video_keyframe_interval, :video_scale_enabled, :srt_passphrase, :srt_pbkeylen, :srt_streamid, :clock_overlay_enabled, :audio_test_wave, :preview_enabled, :test_pattern_enabled, :target_name, :video_speed_preset)
        ");
        $stmt->execute([
            ':name' => $data['name'],
            ':source_type' => $data['source_type'] ?? 'videotestsrc',
            ':source_pattern' => $data['source_pattern'] ?? 'smpte',
            ':width' => (int)($data['width'] ?? 1280),
            ':height' => (int)($data['height'] ?? 720),
            ':framerate' => (int)($data['framerate'] ?? 25),
            ':bitrate' => (int)($data['bitrate'] ?? 2000),
            ':srt_port' => (int)($data['srt_port'] ?? 8888),
            ':srt_latency' => (int)($data['srt_latency'] ?? 120),
            ':srt_mode' => $data['srt_mode'] ?? 'listener',
            ':srt_target_host' => $data['srt_target_host'] ?? null,
            ':source_file' => $data['source_file'] ?? null,
            ':decklink_device_number' => $decklink['device_number'],
            ':decklink_connection' => $decklink['connection'],
            ':decklink_profile' => $decklink['profile'],
            ':decklink_audio_channels' => $decklink['audio_channels'],
            ':pipeline_direction' => $direction,
            ':audio_codec' => $audio['codec'],
            ':audio_output_channels' => $audio['channels'],
            ':audio_track_mode' => $audio['track_mode'],
            ':ts_video_pid' => $ts['video'],
            ':ts_audio_pid' => $ts['audio'],
            ':ts_pmt_pid' => $ts['pmt'],
            ':ts_program' => $ts['program'],
            ':video_codec' => $videoCodec,
            ':audio_bitrate' => $audio['bitrate'],
            ':video_rate_control' => $rateControl,
            ':video_keyframe_interval' => $keyframeInterval,
            ':video_scale_enabled' => $scaleEnabled,
            ':srt_passphrase' => $srtSecurity['passphrase'],
            ':srt_pbkeylen' => $srtSecurity['pbkeylen'],
            ':srt_streamid' => $srtSecurity['streamid'],
            ':clock_overlay_enabled' => (int)($data['clock_overlay_enabled'] ?? 0) === 1 ? 1 : 0,
            ':audio_test_wave' => $this->sanitizeAudioTestWave($data),
            ':preview_enabled' => (int)($data['preview_enabled'] ?? 0) === 1 ? 1 : 0,
            ':test_pattern_enabled' => (int)($data['test_pattern_enabled'] ?? 0) === 1 ? 1 : 0,
            ':target_name' => $this->sanitizeTargetName($data),
            ':video_speed_preset' => $this->sanitizeSpeedPreset($data),
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function sanitizeDirection(array $data): string
    {
        $direction = $data['pipeline_direction'] ?? 'sdi_to_srt';
        return in_array($direction, self::DIRECTIONS, true) ? $direction : 'sdi_to_srt';
    }

    private function sanitizeDecklink(array $data): array
    {
        $deviceNumber = (int)($data['decklink_device_number'] ?? 0);
        if ($deviceNumber < 0 || $deviceNumber > 3) {
            $deviceNumber = 0;
        }

        $connection = $data['decklink_connection'] ?? 'auto';
        if (!in_array($connection, self::DECKLINK_CONNECTIONS, true)) {
            $connection = 'auto';
        }

        // Werkseinstellung der Duo 2 gruppiert die Anschluesse (SDI 1&2), was unabhaengige
        // In-/Out-Nutzung blockiert (siehe [[project-noicecaster-decklink-hardware]] fuer die
        // Diagnose) - two-sub-devices-half entkoppelt die Anschluesse, wie von Blackmagic/Softron
        // fuer diesen Anwendungsfall empfohlen.
        $profile = $data['decklink_profile'] ?? 'two-sub-devices-half';
        // "default" ist der alte, inzwischen als fehlerhaft erkannte Wert - auch bereits in der DB
        // gespeicherte Pipelines (die noch woertlich 'default' stehen haben) auf den funktionierenden
        // Wert ummuenzen, nicht nur wenn das Feld komplett fehlt.
        if ($profile === 'default' || !in_array($profile, self::DECKLINK_PROFILES, true)) {
            $profile = 'two-sub-devices-half';
        }

        $audioChannels = (int)($data['decklink_audio_channels'] ?? 2);
        // Aufnahme (SDI -> SRT): die Karte kennt nur 2, 8 oder 16 Kanaele. Ausgabe (SRT -> SDI): jede gerade Zahl
        // bis 16 ist waehlbar, die Karte bekommt dann die naechstgroessere Kanalzahl (2/8/16) und die ueberzaehligen
        // Kanaele bleiben still (siehe deviceAudioChannels und --audio-used).
        $allowed = ($data['pipeline_direction'] ?? '') === 'srt_to_sdi'
            ? [0, 2, 4, 6, 8, 10, 12, 14, 16]
            : self::DECKLINK_AUDIO_CHANNELS;
        if (!in_array($audioChannels, $allowed, true)) {
            $audioChannels = 2;
        }

        return [
            'device_number' => $deviceNumber,
            'connection' => $connection,
            'profile' => $profile,
            'audio_channels' => $audioChannels,
        ];
    }

    /** Kanalzahl, die der DeckLink-Treiber tatsaechlich oeffnet: 2, 8 oder 16 (die naechstgroessere zur gewaehlten). */
    private function deviceAudioChannels(int $used): int
    {
        return $used <= 2 ? 2 : ($used <= 8 ? 8 : 16);
    }

    private function sanitizeAudio(array $data): array
    {
        $codec = $data['audio_codec'] ?? 'aac';
        if (!in_array($codec, self::AUDIO_CODECS, true)) {
            $codec = 'aac';
        }

        // 'multi' = ein Mehrkanal-Stream (Kanalzahlen je Codec begrenzt), 'pairs' = je 2 Kanaele eine eigene
        // Stereo-Spur (4/8/16 Kanaele ergeben 2/4/8 Spuren, fuer jeden Codec moeglich)
        $trackMode = ($data['audio_track_mode'] ?? 'multi') === 'pairs' ? 'pairs' : 'multi';
        $allowedChannels = $trackMode === 'pairs'
            ? self::AUDIO_PAIR_CHANNELS
            : (self::AUDIO_CODEC_CHANNELS[$codec] ?? self::AUDIO_OUTPUT_CHANNELS);
        $channels = (int)($data['audio_output_channels'] ?? 2);
        if (!in_array($channels, $allowedChannels, true)) {
            $channels = $allowedChannels[0];
        }

        $bitrate = (int)($data['audio_bitrate'] ?? 192);
        if ($bitrate < 32 || $bitrate > 448) {
            $bitrate = 192;
        }

        return [
            'codec' => $codec,
            'channels' => $channels,
            'bitrate' => $bitrate,
            'track_mode' => $trackMode,
        ];
    }

    private function sanitizeVideoCodec(array $data): string
    {
        $codec = $data['video_codec'] ?? 'h264';
        return in_array($codec, self::VIDEO_CODECS, true) ? $codec : 'h264';
    }

    private function videoFormatForCodec(string $codec): string
    {
        // x265enc/avenc_mpeg2video akzeptieren kein NV12, nur planare Formate wie I420
        return $codec === 'h264' ? 'NV12' : 'I420';
    }

    private function sanitizeKeyframeInterval(array $data, int $framerate): int
    {
        $interval = (int)($data['video_keyframe_interval'] ?? 0);
        if ($interval <= 0) {
            // 0 = automatisch, an Framerate gekoppelt (1s GOP), bisheriges Verhalten
            return $framerate > 0 ? $framerate : 25;
        }
        return min($interval, 500);
    }

    private function sanitizeVideoRateControl(array $data): string
    {
        $rc = $data['video_rate_control'] ?? 'cbr';
        return in_array($rc, self::VIDEO_RATE_CONTROLS, true) ? $rc : 'cbr';
    }

    private function sanitizeSpeedPreset(array $data): string
    {
        $preset = $data['video_speed_preset'] ?? 'veryfast';
        return in_array($preset, self::VIDEO_SPEED_PRESETS, true) ? $preset : 'veryfast';
    }

    private function sanitizeAudioTestWave(array $data): string
    {
        $wave = $data['audio_test_wave'] ?? 'sine';
        return in_array($wave, self::AUDIO_TEST_WAVES, true) ? $wave : 'sine';
    }

    private function sanitizeTargetName(array $data): string
    {
        $name = trim((string)($data['target_name'] ?? ''));
        return $name !== '' ? $name : 'Ziel 1';
    }

    public static function previewImagePath(int $id): string
    {
        return __DIR__ . "/../data/preview_{$id}.jpg";
    }

    private function buildPreviewElement(array $p): string
    {
        $enabled = (int)($p['preview_enabled'] ?? 0) === 1;
        if (!$enabled) {
            return "";
        }
        // Ressourcenschonende Vorschau: zweigt die schon decodierten Frames per tee ab,
        // auf 1 Bild alle 3s heruntergebremst und klein skaliert, statt eines echten
        // Live-Streams. leaky=downstream verwirft Frames im Vorschau-Zweig lieber, statt
        // je den Haupt-Encoder-Pfad zu blockieren.
        $path = escapeshellarg(self::previewImagePath((int)($p['id'] ?? 0)));
        return "tee name=vtee " .
            "vtee. ! queue leaky=downstream max-size-buffers=1 ! videorate ! video/x-raw,framerate=1/3 ! " .
            "videoscale ! video/x-raw,width=320,height=180 ! jpegenc ! multifilesink location={$path} sync=false " .
            "vtee. ! queue ! ";
    }

    private function buildClockOverlayElement(array $data): string
    {
        $enabled = (int)($data['clock_overlay_enabled'] ?? 0) === 1;
        // Zeigt die Wanduhrzeit ins Bild ein - zum visuellen Vergleich der Latenz beim Empfänger
        return $enabled ? "clockoverlay time-format=\"%H:%M:%S\" halignment=right valignment=bottom shaded-background=true ! " : "";
    }


    private function buildVideoEncoder(string $codec, int $bitrate, int $keyframeInterval, string $rateControl, string $speedPreset = 'veryfast'): string
    {
        switch ($codec) {
            case 'h265':
                $chain = "x265enc tune=zerolatency speed-preset={$speedPreset} bitrate={$bitrate} key-int-max={$keyframeInterval}";
                if ($rateControl === 'cbr') {
                    // x265 hat keine eigene VBV-Property, daher ueber die x265-Optionszeile erzwungen
                    $chain .= " option-string=\"vbv-bufsize={$bitrate}:vbv-maxrate={$bitrate}\"";
                }
                return $chain . " ! h265parse config-interval=-1";
            case 'mpeg2':
                // avenc_mpeg2video kennt kein speed-preset - Parameter wird fuer diesen Codec ignoriert
                $bps = $bitrate * 1000;
                $chain = "avenc_mpeg2video bitrate={$bps} gop-size={$keyframeInterval}";
                if ($rateControl === 'cbr') {
                    $chain .= " bufsize={$bps} maxrate={$bps} minrate={$bps}";
                }
                return $chain . " ! mpegvideoparse";
            default:
                $chain = "x264enc tune=zerolatency speed-preset={$speedPreset} bitrate={$bitrate} key-int-max={$keyframeInterval}";
                if ($rateControl === 'cbr') {
                    // 1s VBV-Puffer erzwingt eine echte Konstant-Bitrate statt nur eines Zielwerts
                    $chain .= " vbv-buf-capacity=1000";
                }
                return $chain . " ! h264parse config-interval=-1";
        }
    }

    private function sanitizeSrtSecurity(array $data): array
    {
        $passphrase = trim((string)($data['srt_passphrase'] ?? ''));
        $pbkeylen = (int)($data['srt_pbkeylen'] ?? 0);
        if (!in_array($pbkeylen, self::SRT_PBKEYLEN, true)) {
            $pbkeylen = 0;
        }
        if ($passphrase !== '') {
            if (strlen($passphrase) < 10 || strlen($passphrase) > 79) {
                throw new RuntimeException('SRT-Passphrase muss zwischen 10 und 79 Zeichen lang sein (Vorgabe von SRT).');
            }
            if ($pbkeylen === 0) {
                $pbkeylen = 16;
            }
        }

        $streamid = trim((string)($data['srt_streamid'] ?? ''));

        return [
            'passphrase' => $passphrase !== '' ? $passphrase : null,
            'pbkeylen' => $pbkeylen,
            'streamid' => $streamid !== '' ? $streamid : null,
        ];
    }

    private function buildSrtSecurityProps(array $security): string
    {
        $extra = '';
        if ($security['passphrase'] !== null) {
            $extra .= ' passphrase=' . escapeshellarg($security['passphrase']) . ' pbkeylen=' . $security['pbkeylen'];
        }
        if ($security['streamid'] !== null) {
            $extra .= ' streamid=' . escapeshellarg($security['streamid']);
        }
        return $extra;
    }

    /**
     * Ausgangskanaele auf die aufgenommenen begrenzen: der Mixer holt aus 2 Kanaelen keine 6 heraus,
     * die Encoder-Kette bekaeme sonst Caps, die sie nie erfuellt, und der Strom hat keinen Ton.
     */
    private function clampAudioToCapture(array $audio, int $captureChannels): array
    {
        if ($audio['channels'] <= $captureChannels) {
            return $audio;
        }
        $allowed = ($audio['track_mode'] ?? 'multi') === 'pairs'
            ? self::AUDIO_PAIR_CHANNELS
            : (self::AUDIO_CODEC_CHANNELS[$audio['codec']] ?? self::AUDIO_OUTPUT_CHANNELS);
        $fit = array_filter($allowed, fn($c) => $c <= $captureChannels);
        $audio['channels'] = $fit ? max($fit) : 2;
        return $audio;
    }

    private function buildAudioChannelSelectElement(int $captureChannels, int $outputChannels, string $codec = ''): string
    {
        if ($captureChannels <= 2) {
            // decklinkaudiosrc liefert bei 2 aufgenommenen Kanaelen bereits eine gueltige
            // Standard-Kanalbelegung - hier ist nichts zu reparieren
            return "";
        }
        // Eingebettetes SDI-Mehrkanalaudio (8/16 Kanaele) ist in GStreamer IMMER "positionless"
        // (channel-mask=0), auch wenn Aufnahme- und Ausgabekanalzahl gleich sind. Viele Encoder
        // (avenc_aac, avenc_s302m, ...) lehnen das trotz passender Kanalzahl ab. audiomixmatrix im
        // first-channels-Modus greift die ersten N Kanaele heraus (bei Gleichstand quasi alle) und
        // setzt dabei eine gueltige Standard-Kanalbelegung (channel-mask=-1 = automatisch).
        // AAC mit 4 Kanaelen: das automatische Layout ist "quadro" (FL FR RL RR), dafuer gibt es keine
        // AAC-Standardkonfiguration. avenc_aac schreibt dann channel_configuration=0 und die Belegung nur
        // selten in einem Zusatzblock (PCE); ein Empfaenger, der mitten im Strom einsteigt, kann den Ton
        // nicht dekodieren. Mit 4.0 (FL FR FC RC, channel-mask 0x107) ist es die Standardkonfiguration 4.
        $layout = ($codec === 'aac' && $outputChannels === 4) ? ',channel-mask=(bitmask)0x107' : '';
        return "audiomixmatrix mode=first-channels channel-mask=-1 ! \"audio/x-raw,channels={$outputChannels}{$layout}\" ! ";
    }

    // Standardwerte, die der Muxer von sich aus vergibt (so waren die Streams bisher; 0 = automatisch)
    private const TS_DEFAULT_VIDEO_PID = 65;
    private const TS_DEFAULT_AUDIO_PID = 66;
    private const TS_DEFAULT_PMT_PID = 32;

    /**
     * Feste PIDs und Programmnummer fuer den Transportstrom pruefen (0 = automatisch). Erlaubt sind PIDs von 32 bis
     * 8190 (17 ist fuer die SDT reserviert); Video, PMT und alle Audio-Spuren (Audio-PID, +1, +2, ...) muessen
     * verschieden sein.
     */
    private function sanitizeTsIds(array $data, array $audio): array
    {
        $ts = [
            'video' => (int)($data['ts_video_pid'] ?? 0),
            'audio' => (int)($data['ts_audio_pid'] ?? 0),
            'pmt' => (int)($data['ts_pmt_pid'] ?? 0),
            'program' => (int)($data['ts_program'] ?? 0),
        ];
        foreach (['video' => 'Video-PID', 'audio' => 'Audio-PID', 'pmt' => 'PMT-PID'] as $key => $label) {
            if ($ts[$key] !== 0 && ($ts[$key] < 32 || $ts[$key] > 8190 || $ts[$key] === 17)) {
                throw new RuntimeException("{$label} muss 0 (automatisch) oder zwischen 32 und 8190 liegen (17 ist reserviert)");
            }
        }
        if ($ts['program'] < 0 || $ts['program'] > 65535) {
            throw new RuntimeException('Programmnummer muss 0 (automatisch) oder zwischen 1 und 65535 liegen');
        }
        if (!($ts['video'] || $ts['audio'] || $ts['pmt'] || $ts['program'])) {
            return $ts;
        }
        $tracks = ($audio['track_mode'] ?? 'multi') === 'pairs' ? max(1, intdiv((int)$audio['channels'], 2)) : 1;
        $first = $ts['audio'] ?: self::TS_DEFAULT_AUDIO_PID;
        if ($first + $tracks - 1 > 8190) {
            throw new RuntimeException("Audio-PID {$first} reicht fuer {$tracks} Audio-Spuren nicht (letzte PID waere " . ($first + $tracks - 1) . ', maximal 8190)');
        }
        $used = [];
        $assign = function (int $pid, string $label) use (&$used) {
            if (isset($used[$pid])) {
                throw new RuntimeException("PID {$pid} ist doppelt vergeben ({$used[$pid]} und {$label})");
            }
            $used[$pid] = $label;
        };
        $assign($ts['video'] ?: self::TS_DEFAULT_VIDEO_PID, 'Video');
        $assign($ts['pmt'] ?: self::TS_DEFAULT_PMT_PID, 'PMT');
        for ($i = 0; $i < $tracks; $i++) {
            $assign($first + $i, $tracks > 1 ? 'Audio-Spur ' . ($i + 1) : 'Audio');
        }
        return $ts;
    }

    /**
     * Muxer-Einstellungen fuer feste PIDs/Programmnummer. Ohne eigene Angaben bleibt alles wie bisher
     * (automatische Pads "mux." und keine prog-map). Mit Angaben werden alle Eingaenge ueber ihre PID benannt
     * (request pad "sink_<PID>"); die fehlenden Werte nehmen die bisherigen Standardwerte, damit die
     * Programm-Zuordnung vollstaendig ist. In prog-map muss die PMT-PID als (uint) stehen, mit (int) wirkt sie nicht.
     */
    private function muxSetupFor(array $row): array
    {
        $audio = $this->sanitizeAudio($row);
        $ts = $this->sanitizeTsIds($row, $audio);
        if (!($ts['video'] || $ts['audio'] || $ts['pmt'] || $ts['program'])) {
            return ['videoPad' => '', 'props' => '', 'audioPad' => fn(int $i) => 'mux.'];
        }
        $video = $ts['video'] ?: self::TS_DEFAULT_VIDEO_PID;
        $first = $ts['audio'] ?: self::TS_DEFAULT_AUDIO_PID;
        $pmt = $ts['pmt'] ?: self::TS_DEFAULT_PMT_PID;
        $program = $ts['program'] ?: 1;
        $tracks = $audio['track_mode'] === 'pairs' ? max(1, intdiv((int)$audio['channels'], 2)) : 1;
        $map = "sink_{$video}=(int){$program}";
        for ($i = 0; $i < $tracks; $i++) {
            $map .= ',sink_' . ($first + $i) . "=(int){$program}";
        }
        $map .= ",PMT_{$program}=(uint){$pmt},PCR_{$program}=sink_{$video}";
        return [
            'videoPad' => "mux.sink_{$video} ",
            'props' => " prog-map=\"program_map,{$map}\"",
            'audioPad' => fn(int $i) => 'mux.sink_' . ($first + $i),
        ];
    }

    /**
     * Kette vom geparsten Rohton bis zu den Encodern ("mux." am Ende).
     * 'multi': eine Spur mit allen Kanaelen. 'pairs': je zwei Kanaele eine eigene Stereo-Spur; jedes Paar wird
     * ueber audiomixmatrix (manueller Modus, channel-mask=3 = Stereo) aus dem Mehrkanalton gezogen und einzeln
     * kodiert, der Muxer legt jede Spur auf eine eigene PID. Die Bitrate gilt pro Spur.
     */
    private function buildAudioProcessing(int $captureChannels, array $audio, string $channelSelect, array $mux): string
    {
        if (($audio['track_mode'] ?? 'multi') !== 'pairs' || $captureChannels <= 2 || $audio['channels'] <= 2) {
            return "{$channelSelect}audioconvert ! audioresample ! " . $this->buildAudioEncoderChain($audio, $mux['audioPad'](0));
        }
        $pairAudio = ['codec' => $audio['codec'], 'channels' => 2, 'bitrate' => $audio['bitrate'], 'track_mode' => 'multi'];
        $chain = "tee name=atee ";
        for ($k = 0; $k < intdiv($audio['channels'], 2); $k++) {
            $rows = [];
            foreach ([2 * $k, 2 * $k + 1] as $col) {
                $cells = [];
                for ($c = 0; $c < $captureChannels; $c++) {
                    $cells[] = '(double)' . ($c === $col ? '1.0' : '0.0');
                }
                $rows[] = '<' . implode(',', $cells) . '>';
            }
            $matrix = '<' . implode(',', $rows) . '>';
            $chain .= "atee. ! queue ! audiomixmatrix in-channels={$captureChannels} out-channels=2 mode=manual channel-mask=3 " .
                "matrix=\"{$matrix}\" ! audioconvert ! audioresample ! " . $this->buildAudioEncoderChain($pairAudio, $mux['audioPad']($k)) . " ";
        }
        return $chain;
    }

    private function buildAudioEncoderChain(array $audio, string $muxPad = 'mux.'): string
    {
        $channels = $audio['channels'];
        $bps = $audio['bitrate'] * 1000;

        if ($audio['codec'] === 'pcm') {
            // SMPTE 302M: unkomprimiertes PCM, kein Bitrate-Parameter, feste Samplerate 48kHz
            return "audio/x-raw,channels={$channels},rate=48000 ! avenc_s302m strict=experimental ! {$muxPad}";
        }

        switch ($audio['codec']) {
            case 'mp2':
                $encoder = "avenc_mp2 bitrate={$bps} ! mpegaudioparse";
                break;
            case 'ac3':
                $encoder = "avenc_ac3 bitrate={$bps} ! ac3parse";
                break;
            case 'opus':
                $encoder = "opusenc bitrate={$bps} ! opusparse";
                break;
            default:
                $encoder = "avenc_aac bitrate={$bps} ! aacparse";
                break;
        }

        // AAC mit 4 Kanaelen immer als 4.0 (FL FR FC RC, Maske 0x107 = AAC-Standardkonfiguration 4). Ohne
        // Angabe waehlt GStreamer "quadro" (0x33), dafuer gibt es keine Standardkonfiguration: der Empfaenger
        // bekommt dann keinen dekodierbaren Ton (bei Datei- und Testquelle trat das auf).
        $layout = ($audio['codec'] === 'aac' && $channels === 4) ? ',channel-mask=(bitmask)0x107' : '';
        return "\"audio/x-raw,channels={$channels}{$layout}\" ! {$encoder} ! {$muxPad}";
    }

    // --- Mehrere SRT-Ziele pro SDI-Eingang (Advanced-Menue) ---
    // Architektur: ein gemeinsamer "Verteiler"-Prozess oeffnet die SDI-Buchse einmal und schreibt
    // Rohbild/-ton in zwei shmsink-Sockets. Jedes Ziel (die Pipeline selbst als "Ziel 1", plus
    // beliebig viele srt_targets-Zeilen) ist ein EIGENER Prozess, der per shmsrc andockt - Start/Stop
    // eines Ziels beeinflusst keine anderen. Der Verteiler startet automatisch mit dem ersten Ziel und
    // stoppt automatisch mit dem letzten. Siehe [[project-noicecaster-srt-to-sdi]] fuer die Herleitung
    // und den verifizierten shmsink/shmsrc-Mehrfachleser-Test vom 2026-09-30.

    private function isMultiTargetEligible(array $p): bool
    {
        return ($p['pipeline_direction'] ?? 'sdi_to_srt') === 'sdi_to_srt'
            && ($p['source_type'] ?? 'videotestsrc') === 'decklink'
            && (int)($p['test_pattern_enabled'] ?? 0) !== 1;
    }

    private function rawCapsStringForMode(string $mode): string
    {
        if (!isset(self::HD_MODE_CAPS[$mode])) {
            throw new RuntimeException(
                "Mehrere SRT-Ziele werden fuer den SDI-Modus \"{$mode}\" noch nicht unterstuetzt " .
                "(nur feste HD-Modi: 720p/1080i/1080p, kein \"Auto\" und keine SD-Modi). Bitte unter " .
                "\"SDI-Anschluesse\" einen passenden Modus waehlen."
            );
        }
        $m = self::HD_MODE_CAPS[$mode];
        $caps = "video/x-raw,format=UYVY,width={$m['width']},height={$m['height']},framerate={$m['fps_num']}/{$m['fps_den']}";
        $caps .= $m['interlaced'] ? ",interlace-mode=interleaved,field-order=top-field-first" : ",interlace-mode=progressive";
        return $caps;
    }

    private function shmVideoPath(int $pipelineId): string
    {
        return __DIR__ . "/../data/shm_video_{$pipelineId}.sock";
    }

    private function shmAudioPath(int $pipelineId): string
    {
        return __DIR__ . "/../data/shm_audio_{$pipelineId}.sock";
    }

    private function buildDistributorCommand(array $p): string
    {
        $decklink = $this->sanitizeDecklink([
            'decklink_device_number' => $p['decklink_device_number'] ?? 0,
            'decklink_connection' => $p['decklink_connection'] ?? 'auto',
            'decklink_profile' => $p['decklink_profile'] ?? 'default',
            'decklink_audio_channels' => $p['decklink_audio_channels'] ?? 2,
        ]);
        $this->ports->assertRole($decklink['device_number'], 'input');
        $decklinkMode = $this->ports->getMode($decklink['device_number']);
        // Wirft eine klare Fehlermeldung, falls der Modus nicht in HD_MODE_CAPS steht (z.B. "auto")
        $this->rawCapsStringForMode($decklinkMode);

        $clockOverlayElement = $this->buildClockOverlayElement($p);
        $previewElement = $this->buildPreviewElement($p);
        $videoShm = escapeshellarg($this->shmVideoPath((int)$p['id']));
        $audioShm = escapeshellarg($this->shmAudioPath((int)$p['id']));

        $prefix = "env TZ=Europe/Berlin gst-launch-1.0 -v ";

        // decklinkvideosrc taggt HD-SDI-Signale faelschlich als colorimetry=bt2020 (bestaetigt per
        // Monitor-Test 2026-09-28 - siehe [[project-noicecaster-decklink-hardware]]), obwohl alle
        // hier unterstuetzten Modi immer BT.709 sind.
        $videoBranch = "decklinkvideosrc device-number={$decklink['device_number']} " .
            "connection={$decklink['connection']} mode={$decklinkMode} profile={$decklink['profile']} ! " .
            "capssetter caps=\"video/x-raw,colorimetry=(string)bt709\" join=true ! " .
            "videoconvert ! {$previewElement}{$clockOverlayElement}" .
            "shmsink socket-path={$videoShm} shm-size=40000000 wait-for-connection=false sync=false ";

        // wait-for-connection=false: der Verteiler laeuft unabhaengig davon, ob gerade ein Ziel
        // angedockt ist - sonst wuerde er blockieren, bis das erste Ziel startet
        $audioBranch = "decklinkaudiosrc device-number={$decklink['device_number']} channels={$decklink['audio_channels']} ! " .
            "shmsink socket-path={$audioShm} shm-size=4000000 wait-for-connection=false sync=false";

        return $prefix . $videoBranch . $audioBranch;
    }

    /**
     * Loopback-UDP-Port, auf dem ein Sender seinen Transportstrom fuer die Statistik ausgibt
     * (Pipeline-Prozess 47000+id, Zusatzziel 49000+id). Siehe scripts/ts_probe.py.
     */
    public static function tapPort(string $kind, int $id): int
    {
        $base = ['pipeline' => 47000, 'target' => 49000, 'receiver' => 50000][$kind] ?? 47000;
        return $base + $id;
    }

    /** JSON-Datei, in die der Empfaenger jede Sekunde seine SRT-Zahlen schreibt (siehe scripts/srt_to_sdi_receiver.py). */
    public static function receiverStatsPath(int $id): string
    {
        return __DIR__ . "/../data/rxstats_{$id}.json";
    }

    /**
     * Zweiter Zweig hinter dem Muxer (tee name=tap): Kopie des Transportstroms auf 127.0.0.1,
     * nur fuer die Statistik. Leckere Queue, damit ein fehlender oder langsamer Leser den
     * eigentlichen SRT-Weg nie bremst; tsparse buendelt 7 TS-Pakete je Datagramm (1316 Byte).
     */
    private function tapBranch(int $port): string
    {
        return "tap. ! queue leaky=downstream max-size-buffers=300 ! tsparse alignment=7 ! " .
            "udpsink host=127.0.0.1 port={$port} sync=false async=false ";
    }

    private function buildShmTargetCommand(array $p, array $target): string
    {
        $decklink = $this->sanitizeDecklink([
            'decklink_device_number' => $p['decklink_device_number'] ?? 0,
            'decklink_connection' => $p['decklink_connection'] ?? 'auto',
            'decklink_profile' => $p['decklink_profile'] ?? 'default',
            'decklink_audio_channels' => $p['decklink_audio_channels'] ?? 2,
        ]);
        $decklinkMode = $this->ports->getMode($decklink['device_number']);
        $rawCaps = $this->rawCapsStringForMode($decklinkMode);

        $videoCodec = $this->sanitizeVideoCodec($target);
        $videoFormat = $this->videoFormatForCodec($videoCodec);
        $bitrate = (int)($target['bitrate'] ?? 2000);
        $framerate = self::HD_MODE_CAPS[$decklinkMode]['fps_num'] / self::HD_MODE_CAPS[$decklinkMode]['fps_den'];
        $keyframeInterval = $this->sanitizeKeyframeInterval($target, (int)round($framerate));
        $rateControl = $this->sanitizeVideoRateControl($target);
        $speedPreset = $this->sanitizeSpeedPreset($target);
        $videoEncoder = $this->buildVideoEncoder($videoCodec, $bitrate, $keyframeInterval, $rateControl, $speedPreset);

        $audio = $this->clampAudioToCapture($this->sanitizeAudio($target), (int)$decklink['audio_channels']);
        $channelSelect = $this->buildAudioChannelSelectElement($decklink['audio_channels'], $audio['channels'], $audio['codec']);

        $scaleEnabled = (int)($target['video_scale_enabled'] ?? 0) === 1;
        if ($scaleEnabled) {
            $width = (int)($target['width'] ?? 1280);
            $height = (int)($target['height'] ?? 720);
            $scaleElement = "videoscale ! ";
            $formatCaps = "video/x-raw,format={$videoFormat},width={$width},height={$height}";
        } else {
            $scaleElement = "";
            $formatCaps = "video/x-raw,format={$videoFormat}";
        }

        $port = (int)($target['srt_port'] ?? 8888);
        $latency = (int)($target['srt_latency'] ?? 120);
        $mode = $target['srt_mode'] ?? 'listener';
        if ($mode === 'caller') {
            $host = $target['srt_target_host'] ?? '';
            if (empty($host)) {
                throw new RuntimeException('Caller-Modus benoetigt eine Ziel-Adresse (srt_target_host)');
            }
            $srtUri = "srt://{$host}:{$port}?mode=caller";
        } else {
            $srtUri = "srt://:{$port}?mode=listener";
        }
        $srtSecurity = $this->sanitizeSrtSecurity($target);
        $srtSecurityProps = $this->buildSrtSecurityProps($srtSecurity);

        $videoShm = escapeshellarg($this->shmVideoPath((int)$p['id']));
        $audioShm = escapeshellarg($this->shmAudioPath((int)$p['id']));

        $prefix = "env GST_DEBUG=srtsink:5 GST_DEBUG_NO_COLOR=1 gst-launch-1.0 -v ";
        $tap = $this->tapBranch(isset($target['pipeline_id'])
            ? self::tapPort('target', (int)$target['id'])
            : self::tapPort('pipeline', (int)$p['id']));
        $mux = $this->muxSetupFor($target);

        // shmsrc liefert nur einen rohen Byte-Strom ohne eigenes Timing - der Segment-Event, den es
        // erzeugt, steht in Format "bytes", nicht "time" (verifiziert 2026-09-30 per identity-Probe
        // auf dem laufenden Socket). Downstream-Elemente wie avenc_s302m verlangen aber ein
        // Zeit-Segment und werfen sonst "assertion 'segment->format == format' failed" - stumm bei
        // Video, aber bei Audio klar sichtbar, und in beiden Faellen verhindert es offenbar, dass
        // mpegtsmux ueberhaupt jemals ein Muxed-Paket ausgibt (SRT-Verbindung baut sauber auf, aber
        // es fliessen nur Keepalives, nie echte Daten - reproduziert per echtem SRT-Client-Verbindungstest).
        // rawvideoparse/rawaudioparse (gst-plugins-bad) lesen die vorher gesetzten Caps
        // (use-sink-caps=true) und wandeln den Byte-Strom in einen korrekt getakteten Zeit-Strom um.
        // mpegtsmux latency=200000000: Der Muxer schreibt seine erste PAT/PMT, sobald das Video da ist,
        // und srtsink schickt diese als Stream-Header an jeden neuen Empfaenger. Kommt der Ton (Verteiler/
        // AAC-Encoder) etwas spaeter, steht in dieser ersten PMT nur das Video und Empfaenger wie
        // Magewell ordnen die Tonspur falsch zu oder finden sie nicht. 0,2 s Wartezeit beim Start genuegen.
        $videoBranch = "shmsrc socket-path={$videoShm} is-live=true ! {$rawCaps} ! rawvideoparse use-sink-caps=true ! videoconvert ! {$scaleElement}{$formatCaps} ! " .
            "{$videoEncoder} ! {$mux['videoPad']}mpegtsmux name=mux latency=200000000{$mux['props']} ! tee name=tap ! srtsink uri=\"{$srtUri}\" latency={$latency}{$srtSecurityProps} wait-for-connection=false {$tap}";

        // shmsrc uebertraegt Caps NICHT automatisch (siehe rawCaps oben fuer Video) - decklinkaudiosrc
        // liefert immer S32LE/interleaved/48kHz, nur die Kanalzahl variiert je nach Aufnahme-Konfiguration.
        // Ab 3 Kanaelen verlangt GStreamer eine channel-mask in den Caps (0 = unpositioniert; SDI-Mehrkanal hat keine
        // feste Belegung). Fehlt sie, scheitert die Caps-Verhandlung zwischen shmsrc und rawaudioparse und es kommt
        // gar kein Ton an (kein Fehler im Log, im Strom fehlt die Audiospur). Bei 2 Kanaelen reicht der Standard.
        $captureChannels = (int)$decklink['audio_channels'];
        $channelMaskCaps = $captureChannels > 2 ? ',channel-mask=(bitmask)0' : '';
        $rawAudioCaps = "\"audio/x-raw,format=S32LE,layout=interleaved,rate=48000,channels={$captureChannels}{$channelMaskCaps}\"";
        $audioBranch = "shmsrc socket-path={$audioShm} is-live=true ! {$rawAudioCaps} ! rawaudioparse use-sink-caps=true ! " .
            $this->buildAudioProcessing($captureChannels, $audio, $channelSelect, $mux);

        return $prefix . $videoBranch . $audioBranch;
    }

    private function isProcRunning(?int $pid): bool
    {
        return $pid !== null && $pid > 0 && posix_kill($pid, 0);
    }

    private function stopProcess(?int $pid): void
    {
        if (!$this->isProcRunning($pid)) {
            return;
        }
        posix_kill($pid, SIGTERM);
        // Die DeckLink-Karte wird erst frei, wenn der Prozess sauber beendet ist; ein zu frueher SIGKILL oder ein
        // sofortiger Neustart liess den naechsten Start sporadisch scheitern ("Failed to stop streams").
        for ($i = 0; $i < 40 && $this->isProcRunning($pid); $i++) {
            usleep(100000);
        }
        if ($this->isProcRunning($pid)) {
            posix_kill($pid, SIGKILL);
            usleep(500000);
        }
    }

    private function ensureDistributorRunning(array $p): void
    {
        $stmt = $this->db->prepare("SELECT distributor_pid, distributor_status FROM pipelines WHERE id = :id");
        $stmt->execute([':id' => $p['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['distributor_status'] === 'running' && $this->isProcRunning((int)$row['distributor_pid'])) {
            return; // laeuft schon - andere Ziele nicht anfassen
        }

        // Alte shm-Socket-Dateien von einem frueheren (z.B. hart abgestuerzten/gekillten) Verteiler
        // koennen liegen bleiben, ohne dass jemand mehr dahinter lauscht - file_exists() alleine
        // wuerde das faelschlich als "bereit" durchgehen lassen und Ziele bekaemen "Connection
        // refused" von einem toten Socket-Pfad (reproduziert 2026-09-30). Erst aufraeumen, dann
        // den neuen Verteiler starten, damit die Existenzpruefung unten wirklich den NEUEN Prozess meint.
        $videoShmFile = $this->shmVideoPath((int)$p['id']);
        $audioShmFile = $this->shmAudioPath((int)$p['id']);
        @unlink($videoShmFile);
        @unlink($audioShmFile);

        $cmd = $this->buildDistributorCommand($p);
        $logFile = __DIR__ . "/../data/distributor_{$p['id']}.log";
        $fullCmd = "nohup {$cmd} > " . escapeshellarg($logFile) . " 2>&1 & echo $!";

        // Zwei Versuche: direkt nach dem Beenden einer vorherigen Aufnahme ist die Karte manchmal noch kurz belegt
        // und der Verteiler bricht sofort ab (Logzeile "Failed to stop streams ... 0x80000009").
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $pid = (int)shell_exec($fullCmd);

            $stmt = $this->db->prepare("UPDATE pipelines SET distributor_status = 'running', distributor_pid = :pid WHERE id = :id");
            $stmt->execute([':pid' => $pid, ':id' => $p['id']]);

            // Auf die shm-Sockets warten, statt blind eine feste Zeit zu schlafen - eine feste Wartezeit
            // war zu kurz und fuehrte zu "Connection refused" beim ersten Ziel (Race Condition,
            // beobachtet 2026-09-30). Timeout nach 5s; stirbt der Prozess vorher, nicht weiter warten.
            $waitedMs = 0;
            while ((!file_exists($videoShmFile) || !file_exists($audioShmFile)) && $waitedMs < 5000 && $this->isProcRunning($pid)) {
                usleep(100000);
                $waitedMs += 100;
            }
            if (file_exists($videoShmFile) && file_exists($audioShmFile)) {
                return;
            }
            $this->stopProcess($pid);
            @unlink($videoShmFile);
            @unlink($audioShmFile);
            if ($attempt === 1) {
                sleep(2);
            }
        }
        $stmt = $this->db->prepare("UPDATE pipelines SET distributor_status = 'stopped', distributor_pid = NULL WHERE id = :id");
        $stmt->execute([':id' => $p['id']]);
        throw new RuntimeException("Verteiler-Prozess hat die Shared-Memory-Sockets nicht rechtzeitig angelegt - siehe distributor_{$p['id']}.log");
    }

    private function anyTargetRunning(int $pipelineId): bool
    {
        $stmt = $this->db->prepare("SELECT status, pid FROM pipelines WHERE id = :id");
        $stmt->execute([':id' => $pipelineId]);
        $primary = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($primary && $primary['status'] === 'running' && $this->isProcRunning((int)$primary['pid'])) {
            return true;
        }

        $stmt = $this->db->prepare("SELECT status, pid FROM srt_targets WHERE pipeline_id = :pid");
        $stmt->execute([':pid' => $pipelineId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $target) {
            if ($target['status'] === 'running' && $this->isProcRunning((int)$target['pid'])) {
                return true;
            }
        }
        return false;
    }

    private function maybeStopDistributor(int $pipelineId): void
    {
        if ($this->anyTargetRunning($pipelineId)) {
            return; // noch mindestens ein Ziel aktiv - Verteiler bleibt fuer die anderen erhalten
        }
        $stmt = $this->db->prepare("SELECT distributor_pid FROM pipelines WHERE id = :id");
        $stmt->execute([':id' => $pipelineId]);
        $pid = $stmt->fetchColumn();
        $this->stopProcess($pid !== false ? (int)$pid : null);

        $stmt = $this->db->prepare("UPDATE pipelines SET distributor_status = 'stopped', distributor_pid = NULL WHERE id = :id");
        $stmt->execute([':id' => $pipelineId]);
    }

    public function listTargets(int $pipelineId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM srt_targets WHERE pipeline_id = :pid ORDER BY id");
        $stmt->execute([':pid' => $pipelineId]);
        $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($targets as &$t) {
            if ($t['status'] === 'running' && !$this->isProcRunning((int)$t['pid'])) {
                $this->db->prepare("UPDATE srt_targets SET status = 'stopped', pid = NULL WHERE id = :id")
                    ->execute([':id' => $t['id']]);
                $t['status'] = 'stopped';
                $t['pid'] = null;
            }
        }
        return $targets;
    }

    private function getTarget(int $targetId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM srt_targets WHERE id = :id");
        $stmt->execute([':id' => $targetId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$target) {
            throw new RuntimeException("Ziel $targetId nicht gefunden");
        }
        return $target;
    }

    private function getPipelineOrFail(int $pipelineId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM pipelines WHERE id = :id");
        $stmt->execute([':id' => $pipelineId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            throw new RuntimeException("Pipeline $pipelineId nicht gefunden");
        }
        return $p;
    }

    public function createTarget(int $pipelineId, array $data): int
    {
        $p = $this->getPipelineOrFail($pipelineId);
        if (!$this->isMultiTargetEligible($p)) {
            throw new RuntimeException("Zusaetzliche SRT-Ziele sind nur fuer SDI-Eingangs-Pipelines moeglich.");
        }
        $this->validateSettings($data);
        $audio = $this->sanitizeAudio($data);
        $ts = $this->sanitizeTsIds($data, $audio);
        $videoCodec = $this->sanitizeVideoCodec($data);
        $rateControl = $this->sanitizeVideoRateControl($data);
        $srtSecurity = $this->sanitizeSrtSecurity($data);

        $stmt = $this->db->prepare("
            INSERT INTO srt_targets (pipeline_id, name, srt_mode, srt_port, srt_target_host, srt_latency, srt_passphrase, srt_pbkeylen, srt_streamid, video_codec, bitrate, video_rate_control, video_keyframe_interval, video_scale_enabled, width, height, audio_codec, audio_output_channels, audio_track_mode, ts_video_pid, ts_audio_pid, ts_pmt_pid, ts_program, audio_bitrate, video_speed_preset)
            VALUES (:pipeline_id, :name, :srt_mode, :srt_port, :srt_target_host, :srt_latency, :srt_passphrase, :srt_pbkeylen, :srt_streamid, :video_codec, :bitrate, :video_rate_control, :video_keyframe_interval, :video_scale_enabled, :width, :height, :audio_codec, :audio_output_channels, :audio_track_mode, :ts_video_pid, :ts_audio_pid, :ts_pmt_pid, :ts_program, :audio_bitrate, :video_speed_preset)
        ");
        $stmt->execute([
            ':pipeline_id' => $pipelineId,
            ':name' => trim((string)($data['name'] ?? '')) !== '' ? $data['name'] : 'Ziel',
            ':srt_mode' => ($data['srt_mode'] ?? 'listener') === 'caller' ? 'caller' : 'listener',
            ':srt_port' => (int)($data['srt_port'] ?? 8888),
            ':srt_target_host' => $data['srt_target_host'] ?? null,
            ':srt_latency' => (int)($data['srt_latency'] ?? 120),
            ':srt_passphrase' => $srtSecurity['passphrase'],
            ':srt_pbkeylen' => $srtSecurity['pbkeylen'],
            ':srt_streamid' => $srtSecurity['streamid'],
            ':video_codec' => $videoCodec,
            ':bitrate' => (int)($data['bitrate'] ?? 2000),
            ':video_rate_control' => $rateControl,
            ':video_keyframe_interval' => (int)($data['video_keyframe_interval'] ?? 0),
            ':video_scale_enabled' => (int)($data['video_scale_enabled'] ?? 0) === 1 ? 1 : 0,
            ':width' => (int)($data['width'] ?? 1920),
            ':height' => (int)($data['height'] ?? 1080),
            ':audio_codec' => $audio['codec'],
            ':audio_output_channels' => $audio['channels'],
            ':audio_track_mode' => $audio['track_mode'],
            ':ts_video_pid' => $ts['video'],
            ':ts_audio_pid' => $ts['audio'],
            ':ts_pmt_pid' => $ts['pmt'],
            ':ts_program' => $ts['program'],
            ':audio_bitrate' => $audio['bitrate'],
            ':video_speed_preset' => $this->sanitizeSpeedPreset($data),
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateTarget(int $targetId, array $data): void
    {
        $target = $this->getTarget($targetId);
        if ($target['status'] === 'running') {
            throw new RuntimeException("Laufendes Ziel kann nicht bearbeitet werden. Bitte zuerst stoppen.");
        }
        $this->validateSettings($data);
        $audio = $this->sanitizeAudio($data);
        $ts = $this->sanitizeTsIds($data, $audio);
        $videoCodec = $this->sanitizeVideoCodec($data);
        $rateControl = $this->sanitizeVideoRateControl($data);
        $srtSecurity = $this->sanitizeSrtSecurity($data);

        $stmt = $this->db->prepare("
            UPDATE srt_targets SET
                name = :name,
                srt_mode = :srt_mode,
                srt_port = :srt_port,
                srt_target_host = :srt_target_host,
                srt_latency = :srt_latency,
                srt_passphrase = :srt_passphrase,
                srt_pbkeylen = :srt_pbkeylen,
                srt_streamid = :srt_streamid,
                video_codec = :video_codec,
                bitrate = :bitrate,
                video_rate_control = :video_rate_control,
                video_keyframe_interval = :video_keyframe_interval,
                video_scale_enabled = :video_scale_enabled,
                width = :width,
                height = :height,
                audio_codec = :audio_codec,
                audio_output_channels = :audio_output_channels,
                audio_track_mode = :audio_track_mode,
                ts_video_pid = :ts_video_pid,
                ts_audio_pid = :ts_audio_pid,
                ts_pmt_pid = :ts_pmt_pid,
                ts_program = :ts_program,
                audio_bitrate = :audio_bitrate,
                video_speed_preset = :video_speed_preset
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $targetId,
            ':name' => trim((string)($data['name'] ?? '')) !== '' ? $data['name'] : 'Ziel',
            ':srt_mode' => ($data['srt_mode'] ?? 'listener') === 'caller' ? 'caller' : 'listener',
            ':srt_port' => (int)($data['srt_port'] ?? 8888),
            ':srt_target_host' => $data['srt_target_host'] ?? null,
            ':srt_latency' => (int)($data['srt_latency'] ?? 120),
            ':srt_passphrase' => $srtSecurity['passphrase'],
            ':srt_pbkeylen' => $srtSecurity['pbkeylen'],
            ':srt_streamid' => $srtSecurity['streamid'],
            ':video_codec' => $videoCodec,
            ':bitrate' => (int)($data['bitrate'] ?? 2000),
            ':video_rate_control' => $rateControl,
            ':video_keyframe_interval' => (int)($data['video_keyframe_interval'] ?? 0),
            ':video_scale_enabled' => (int)($data['video_scale_enabled'] ?? 0) === 1 ? 1 : 0,
            ':width' => (int)($data['width'] ?? 1920),
            ':height' => (int)($data['height'] ?? 1080),
            ':audio_codec' => $audio['codec'],
            ':audio_output_channels' => $audio['channels'],
            ':audio_track_mode' => $audio['track_mode'],
            ':ts_video_pid' => $ts['video'],
            ':ts_audio_pid' => $ts['audio'],
            ':ts_pmt_pid' => $ts['pmt'],
            ':ts_program' => $ts['program'],
            ':audio_bitrate' => $audio['bitrate'],
            ':video_speed_preset' => $this->sanitizeSpeedPreset($data),
        ]);
    }

    public function deleteTarget(int $targetId): void
    {
        try {
            $this->stopTarget($targetId);
        } catch (Throwable $e) {
            // ignorieren, falls schon gestoppt
        }
        $stmt = $this->db->prepare("DELETE FROM srt_targets WHERE id = :id");
        $stmt->execute([':id' => $targetId]);
    }

    public function startTarget(int $targetId): void
    {
        $target = $this->getTarget($targetId);
        if ($target['status'] === 'running' && $this->isProcRunning((int)$target['pid'])) {
            throw new RuntimeException("Ziel läuft bereits");
        }
        $p = $this->getPipelineOrFail((int)$target['pipeline_id']);

        $this->ensureDistributorRunning($p);
        $cmd = $this->buildShmTargetCommand($p, $target);

        $logFile = __DIR__ . "/../data/target_{$targetId}.log";
        $fullCmd = "nohup {$cmd} > " . escapeshellarg($logFile) . " 2>&1 & echo $!";
        $pid = (int)shell_exec($fullCmd);

        $stmt = $this->db->prepare("UPDATE srt_targets SET status = 'running', pid = :pid, started_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':pid' => $pid, ':id' => $targetId]);
    }

    public function stopTarget(int $targetId): void
    {
        $target = $this->getTarget($targetId);
        $this->stopProcess($target['pid'] !== null ? (int)$target['pid'] : null);

        $stmt = $this->db->prepare("UPDATE srt_targets SET status = 'stopped', pid = NULL, started_at = NULL WHERE id = :id");
        $stmt->execute([':id' => $targetId]);

        $this->maybeStopDistributor((int)$target['pipeline_id']);
    }

    public function getTargetStats(int $targetId): array
    {
        $target = $this->getTarget($targetId);
        return $this->processStats(
            $target['status'] === 'running' ? (int)$target['pid'] : null,
            $target['started_at'],
            __DIR__ . "/../data/target_{$targetId}.log"
        );
    }

    public function start(int $id): void
    {
        $p = $this->getPipelineOrFail($id);

        if ($p['status'] === 'running' && $this->isProcRunning($p['pid'] !== null ? (int)$p['pid'] : null)) {
            throw new RuntimeException("Pipeline läuft bereits");
        }

        if ($this->isMultiTargetEligible($p)) {
            // "Ziel 1" ist die Pipeline selbst - laeuft wie jedes andere Ziel per shmsrc gegen den
            // gemeinsamen Verteiler, damit weitere Ziele jederzeit unabhaengig dazukommen koennen.
            $this->ensureDistributorRunning($p);
            $cmd = $this->buildShmTargetCommand($p, $p);
        } else {
            $cmd = $this->buildCommand($p);
        }

        // Im Hintergrund starten, Ausgabe in Logdatei, PID erfassen
        $logFile = __DIR__ . "/../data/pipeline_{$id}.log";
        $fullCmd = "nohup {$cmd} > " . escapeshellarg($logFile) . " 2>&1 & echo $!";

        $pid = (int)shell_exec($fullCmd);

        $stmt = $this->db->prepare("UPDATE pipelines SET status = 'running', pid = :pid, started_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':pid' => $pid, ':id' => $id]);
    }

    public function stop(int $id): void
    {
        $p = $this->getPipelineOrFail($id);
        $this->stopProcess($p['pid'] !== null ? (int)$p['pid'] : null);

        $stmt = $this->db->prepare("UPDATE pipelines SET status = 'stopped', pid = NULL, started_at = NULL WHERE id = :id");
        $stmt->execute([':id' => $id]);

        if ($this->isMultiTargetEligible($p)) {
            $this->maybeStopDistributor($id);
        }
    }

    public function delete(int $id): void
    {
        // Erst zusaetzliche Ziele stoppen+loeschen, dann die Pipeline selbst ("Ziel 1")
        $stmt = $this->db->prepare("SELECT id FROM srt_targets WHERE pipeline_id = :id");
        $stmt->execute([':id' => $id]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $targetId) {
            $this->deleteTarget((int)$targetId);
        }

        try {
            $this->stop($id);
        } catch (Throwable $e) {
            // Ignorieren, falls schon gestoppt
        }
        $stmt = $this->db->prepare("DELETE FROM pipelines WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    private function buildCommand(array $p): string
    {
        // Testbild-Haekchen ueberschreibt alles andere: statt SRT zu empfangen/senden gibt die
        // Pipeline nur noch ein lokales Testmuster auf ihrem konfigurierten Decklink-Ausgang aus -
        // fuer schnelle Hardware-/Monitor-Checks ohne echten Signalweg. Ersetzt die zuvor manuell
        // auf dem Server laufenden gst-launch-Prozesse (siehe [[project-noicecaster-srt-to-sdi]]).
        if ((int)($p['test_pattern_enabled'] ?? 0) === 1) {
            return $this->buildTestPatternCommand($p);
        }

        $direction = $p['pipeline_direction'] ?? 'sdi_to_srt';
        if ($direction === 'srt_to_sdi') {
            return $this->buildReceiveCommand($p);
        }

        $width = (int)$p['width'];
        $height = (int)$p['height'];
        $framerate = (int)$p['framerate'];
        $bitrate = (int)$p['bitrate'];
        $port = (int)$p['srt_port'];
        $latency = (int)$p['srt_latency'];
        $mode = $p['srt_mode'] ?? 'listener';
        $sourceType = $p['source_type'] ?? 'videotestsrc';
        $videoCodec = $this->sanitizeVideoCodec($p);
        $videoFormat = $this->videoFormatForCodec($videoCodec);
        $keyframeInterval = $this->sanitizeKeyframeInterval($p, $framerate);
        $rateControl = $this->sanitizeVideoRateControl($p);
        $speedPreset = $this->sanitizeSpeedPreset($p);
        $videoEncoder = $this->buildVideoEncoder($videoCodec, $bitrate, $keyframeInterval, $rateControl, $speedPreset);
        $srtSecurity = $this->sanitizeSrtSecurity($p);
        $srtSecurityProps = $this->buildSrtSecurityProps($srtSecurity);
        $clockOverlayElement = $this->buildClockOverlayElement($p);
        $previewElement = $this->buildPreviewElement($p);
        $scaleEnabled = (int)($p['video_scale_enabled'] ?? 0) === 1;
        if ($scaleEnabled) {
            $scaleElement = "videoscale ! ";
            $formatCaps = "video/x-raw,format={$videoFormat},width={$width},height={$height}";
        } else {
            $scaleElement = "";
            $formatCaps = "video/x-raw,format={$videoFormat}";
        }

        if ($mode === 'caller') {
            $host = $p['srt_target_host'] ?? '';
            if (empty($host)) {
                throw new RuntimeException('Caller-Modus benoetigt eine Ziel-Adresse (srt_target_host)');
            }
            $srtUri = "srt://{$host}:{$port}?mode=caller";
        } else {
            $srtUri = "srt://:{$port}?mode=listener";
        }

        // TZ nur für die Pipeline selbst gesetzt (fuer clockoverlay) - aendert nicht die Systemzeitzone des Servers
        $prefix = "env TZ=Europe/Berlin GST_DEBUG=srtsink:5 GST_DEBUG_NO_COLOR=1 gst-launch-1.0 -v ";
        $tap = $this->tapBranch(self::tapPort('pipeline', (int)$p['id']));
        $mux = $this->muxSetupFor($p);

        if ($sourceType === 'decklink') {
            $decklink = $this->sanitizeDecklink([
                'decklink_device_number' => $p['decklink_device_number'] ?? 0,
                'decklink_connection' => $p['decklink_connection'] ?? 'auto',
                'decklink_profile' => $p['decklink_profile'] ?? 'default',
                'decklink_audio_channels' => $p['decklink_audio_channels'] ?? 2,
            ]);
            $this->ports->assertRole($decklink['device_number'], 'input');
            $decklinkMode = $this->ports->getMode($decklink['device_number']);

            // decklinkvideosrc taggt HD-SDI-Signale faelschlich als colorimetry=bt2020 (bestaetigt per
            // Monitor-Test 2026-09-28 - siehe [[project-noicecaster-decklink-hardware]]), obwohl alle
            // hier unterstuetzten Modi (720p/1080i/1080p) immer BT.709 sind. capssetter ueberschreibt
            // nur das Caps-Feld (reines Relabeling, keine echte Farbraum-Umrechnung) - ein einfacher
            // capsfilter scheitert hier an der Negotiation, weil decklinkvideosrc bt2020 als fixen Wert
            // meldet.
            $videoBranch = "decklinkvideosrc device-number={$decklink['device_number']} " .
                "connection={$decklink['connection']} mode={$decklinkMode} profile={$decklink['profile']} ! " .
                "capssetter caps=\"video/x-raw,colorimetry=(string)bt709\" join=true ! " .
                "videoconvert ! {$previewElement}{$clockOverlayElement}{$scaleElement}{$formatCaps} ! " .
                "{$videoEncoder} ! " .
                "{$mux['videoPad']}mpegtsmux name=mux latency=200000000{$mux['props']} ! tee name=tap ! " .
                "srtsink uri=\"{$srtUri}\" latency={$latency}{$srtSecurityProps} wait-for-connection=false {$tap}";

            $audio = $this->clampAudioToCapture($this->sanitizeAudio($p), (int)$decklink['audio_channels']);
            $channelSelect = $this->buildAudioChannelSelectElement($decklink['audio_channels'], $audio['channels'], $audio['codec']);
            $audioBranch = "decklinkaudiosrc device-number={$decklink['device_number']} channels={$decklink['audio_channels']} ! " .
                $this->buildAudioProcessing((int)$decklink['audio_channels'], $audio, $channelSelect, $mux);

            return $prefix . $videoBranch . $audioBranch;
        }

        if ($sourceType === 'file') {
            $file = $p['source_file'] ?? '';
            if (empty($file) || !file_exists($file)) {
                throw new RuntimeException('Videodatei nicht gefunden: ' . $file);
            }
            $fileArg = escapeshellarg($file);

            $videoBranch = "demux.video_0 ! queue ! decodebin ! videoconvert ! {$clockOverlayElement}{$scaleElement}{$formatCaps} ! " .
                "{$videoEncoder} ! " .
                "{$mux['videoPad']}mpegtsmux name=mux latency=200000000{$mux['props']} ! tee name=tap ! " .
                "srtsink uri=\"{$srtUri}\" latency={$latency}{$srtSecurityProps} {$tap}";

            $audio = $this->sanitizeAudio(array_merge($p, ['audio_track_mode' => 'multi']));
            // Mehr als 2 Ausgabekanaele: die ersten N Kanaele der Datei, der Rest bleibt still (wie beim
            // SDI-Eingang). Ein reines audioconvert mischt Stereo auf "quadro" hoch und verteilt den Ton.
            $fileChannelSelect = $audio['channels'] > 2 ? "audiomixmatrix mode=first-channels channel-mask=-1 ! " : "";
            $audioBranch = "demux.audio_0 ! queue ! decodebin ! audioconvert ! audioresample ! {$fileChannelSelect}" .
                $this->buildAudioEncoderChain($audio, $mux['audioPad'](0));

            return $prefix . "filesrc location={$fileArg} ! qtdemux name=demux " . $videoBranch . $audioBranch;
        }

        // Standard: synthetisches Testmuster
        $pattern = escapeshellarg($p['source_pattern']);
        $source = "videotestsrc pattern={$pattern} is-live=true";

        $videoBranch = "{$source} ! " .
            "video/x-raw,format={$videoFormat},width={$width},height={$height},framerate={$framerate}/1 ! " .
            "videoconvert ! {$clockOverlayElement}" .
            "{$videoEncoder} ! " .
            "{$mux['videoPad']}mpegtsmux name=mux latency=200000000{$mux['props']} ! tee name=tap ! " .
            "srtsink uri=\"{$srtUri}\" latency={$latency}{$srtSecurityProps} wait-for-connection=false {$tap}";

        $audio = $this->sanitizeAudio(array_merge($p, ['audio_track_mode' => 'multi']));
        $audioTestWave = $this->sanitizeAudioTestWave($p);
        $audioBranch = "audiotestsrc wave={$audioTestWave} freq=1000 is-live=true ! " . $this->buildAudioEncoderChain($audio, $mux['audioPad'](0));

        return $prefix . $videoBranch . $audioBranch;
    }

    private function buildTestPatternCommand(array $p): string
    {
        $decklink = $this->sanitizeDecklink([
            'decklink_device_number' => $p['decklink_device_number'] ?? 0,
            'decklink_connection' => $p['decklink_connection'] ?? 'auto',
            'decklink_profile' => $p['decklink_profile'] ?? 'default',
            'decklink_audio_channels' => $p['decklink_audio_channels'] ?? 2,
            'pipeline_direction' => 'srt_to_sdi',
        ]);
        $this->ports->assertRole($decklink['device_number'], 'output');
        $outputMode = $this->ports->getMode($decklink['device_number']);
        if ($outputMode === 'auto') {
            $outputMode = DecklinkPortManager::DEFAULT_OUTPUT_MODE;
        }

        // Nur unkritische Zeichen zulassen - der Text landet direkt (nicht ueber escapeshellarg) in
        // der gst-launch-Kommandozeile, siehe Escaping-Hinweis unten.
        $label = preg_replace('/[^a-zA-Z0-9 .:_-]/', '', $p['name'] ?? 'Testbild');
        if (trim($label) === '') {
            $label = 'Testbild';
        }
        // gst-launch fuegt am Ende alle argv wieder mit Leerzeichen zusammen, bevor es seine eigene
        // Pipeline-Beschreibung parst - Shell-Quotierung allein wuerde dabei verlorengehen (die
        // Anfuehrungszeichen werden schon von der Shell konsumiert, bevor gst-launch sie sieht).
        // Die literalen \"-Zeichen ueberleben das Zusammenfuegen und werden von gst-launch selbst
        // als Quotierung erkannt - reproduziert und verifiziert 2026-09-28.
        $textOverlay = 'textoverlay text=\"' . $label . '\" halignment=center valignment=top font-desc=\"Sans Bold 36\" ! ';

        return "gst-launch-1.0 -v videotestsrc pattern=smpte is-live=true ! {$textOverlay}" .
            "decklinkvideosink device-number={$decklink['device_number']} mode={$outputMode} profile={$decklink['profile']} " .
            "audiotestsrc wave=sine freq=1000 is-live=true ! decklinkaudiosink device-number={$decklink['device_number']}";
    }

    private function buildReceiveCommand(array $p): string
    {
        $port = (int)$p['srt_port'];
        $latency = (int)$p['srt_latency'];
        $mode = $p['srt_mode'] ?? 'listener';
        $srtSecurity = $this->sanitizeSrtSecurity($p);

        if ($mode === 'caller') {
            $host = $p['srt_target_host'] ?? '';
            if (empty($host)) {
                throw new RuntimeException('Caller-Modus benoetigt eine Ziel-Adresse (srt_target_host)');
            }
            $srtUri = "srt://{$host}:{$port}?mode=caller";
        } else {
            $srtUri = "srt://:{$port}?mode=listener";
        }

        $decklink = $this->sanitizeDecklink([
            'decklink_device_number' => $p['decklink_device_number'] ?? 0,
            'decklink_connection' => $p['decklink_connection'] ?? 'auto',
            'decklink_profile' => $p['decklink_profile'] ?? 'default',
            'decklink_audio_channels' => $p['decklink_audio_channels'] ?? 2,
            'pipeline_direction' => 'srt_to_sdi',
        ]);
        $this->ports->assertRole($decklink['device_number'], 'output');
        $outputMode = $this->ports->getMode($decklink['device_number']);
        if ($outputMode === 'auto') {
            // DecklinkPortManager verhindert das beim Speichern bereits, das hier ist nur ein
            // Sicherheitsnetz gegen ältere Datensätze - decklinkvideosink kann nicht im Auto-Modus ausgeben
            $outputMode = DecklinkPortManager::DEFAULT_OUTPUT_MODE;
        }

        // Empfaenger laeuft als kleines Python-Hilfsprogramm statt als gst-launch-Zeile: tsdemux legt
        // seine Pads beim Verbindungsaufbau neu an (z. B. audio_0_0042 -> audio_1_0042). Eine
        // gst-launch-Zeile verbindet die "demux."-Verweise nur einmal, der Audio-Zweig bleibt dabei
        // haengen (Ton bricht nach ~2 Puffern ab). Das Hilfsprogramm verbindet jedes neue Pad selbst
        // (pad-added) und nimmt den Codec ueber decodebin, es braucht daher keine Caps-Filter mehr
        // (auch SMPTE 302M aus dem eigenen Sender wird so dekodiert). Beim Stop (SIGTERM) schliesst es
        // die SRT-Verbindung sauber, damit ein Sender nicht auf eine tote Verbindung wartet.
        $helper = escapeshellarg(__DIR__ . '/../scripts/srt_to_sdi_receiver.py');
        $args = ' --uri ' . escapeshellarg($srtUri) .
            ' --latency ' . $latency .
            ' --device ' . (int)$decklink['device_number'] .
            ' --mode ' . escapeshellarg($outputMode) .
            ' --profile ' . escapeshellarg($decklink['profile']) .
            ' --audio-channels ' . $this->deviceAudioChannels((int)$decklink['audio_channels']) .
            ' --audio-used ' . max(2, (int)$decklink['audio_channels']) .
            ' --stats-file ' . escapeshellarg(self::receiverStatsPath((int)$p['id'])) .
            ' --tap-port ' . self::tapPort('receiver', (int)$p['id']);
        if ($srtSecurity['passphrase'] !== null) {
            $args .= ' --passphrase ' . escapeshellarg($srtSecurity['passphrase']) . ' --pbkeylen ' . (int)$srtSecurity['pbkeylen'];
        }
        if ($srtSecurity['streamid'] !== null) {
            $args .= ' --streamid ' . escapeshellarg($srtSecurity['streamid']);
        }

        return "env GST_DEBUG=srtsrc:5 GST_DEBUG_NO_COLOR=1 python3 {$helper}{$args}";
    }

    private function updateStatus(int $id, string $status, ?int $pid): void
    {
        $stmt = $this->db->prepare("UPDATE pipelines SET status = :status, pid = :pid WHERE id = :id");
        $stmt->execute([':status' => $status, ':pid' => $pid, ':id' => $id]);
    }


    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare("SELECT status FROM pipelines WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$current) {
            throw new RuntimeException("Pipeline $id nicht gefunden");
        }
        if ($current['status'] === 'running') {
            throw new RuntimeException("Laufende Pipeline kann nicht bearbeitet werden. Bitte zuerst stoppen.");
        }

        $decklink = $this->sanitizeDecklink($data);
        $direction = $this->sanitizeDirection($data);
        $this->validateSettings($data);
        $audio = $this->sanitizeAudio($data);
        $ts = $this->sanitizeTsIds($data, $audio);
        $videoCodec = $this->sanitizeVideoCodec($data);
        $rateControl = $this->sanitizeVideoRateControl($data);
        $keyframeInterval = (int)($data['video_keyframe_interval'] ?? 0);
        $scaleEnabled = (int)($data['video_scale_enabled'] ?? 0) === 1 ? 1 : 0;
        $srtSecurity = $this->sanitizeSrtSecurity($data);
        $this->validatePortUsage($data, $decklink, $direction, $id);
        $stmt = $this->db->prepare("
            UPDATE pipelines SET
                name = :name,
                source_type = :source_type,
                source_pattern = :source_pattern,
                width = :width,
                height = :height,
                framerate = :framerate,
                bitrate = :bitrate,
                srt_port = :srt_port,
                srt_latency = :srt_latency,
                srt_mode = :srt_mode,
                srt_target_host = :srt_target_host,
                source_file = :source_file,
                decklink_device_number = :decklink_device_number,
                decklink_connection = :decklink_connection,
                decklink_profile = :decklink_profile,
                decklink_audio_channels = :decklink_audio_channels,
                pipeline_direction = :pipeline_direction,
                audio_codec = :audio_codec,
                audio_output_channels = :audio_output_channels,
                audio_track_mode = :audio_track_mode,
                ts_video_pid = :ts_video_pid,
                ts_audio_pid = :ts_audio_pid,
                ts_pmt_pid = :ts_pmt_pid,
                ts_program = :ts_program,
                audio_bitrate = :audio_bitrate,
                video_codec = :video_codec,
                video_rate_control = :video_rate_control,
                video_keyframe_interval = :video_keyframe_interval,
                video_scale_enabled = :video_scale_enabled,
                srt_passphrase = :srt_passphrase,
                srt_pbkeylen = :srt_pbkeylen,
                srt_streamid = :srt_streamid,
                clock_overlay_enabled = :clock_overlay_enabled,
                audio_test_wave = :audio_test_wave,
                preview_enabled = :preview_enabled,
                test_pattern_enabled = :test_pattern_enabled,
                target_name = :target_name,
                video_speed_preset = :video_speed_preset
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':name' => $data['name'],
            ':source_type' => $data['source_type'] ?? 'videotestsrc',
            ':source_pattern' => $data['source_pattern'] ?? 'smpte',
            ':width' => (int)($data['width'] ?? 1280),
            ':height' => (int)($data['height'] ?? 720),
            ':framerate' => (int)($data['framerate'] ?? 25),
            ':bitrate' => (int)($data['bitrate'] ?? 2000),
            ':srt_port' => (int)($data['srt_port'] ?? 8888),
            ':srt_latency' => (int)($data['srt_latency'] ?? 120),
            ':srt_mode' => $data['srt_mode'] ?? 'listener',
            ':srt_target_host' => $data['srt_target_host'] ?? null,
            ':source_file' => $data['source_file'] ?? null,
            ':decklink_device_number' => $decklink['device_number'],
            ':decklink_connection' => $decklink['connection'],
            ':decklink_profile' => $decklink['profile'],
            ':decklink_audio_channels' => $decklink['audio_channels'],
            ':pipeline_direction' => $direction,
            ':audio_codec' => $audio['codec'],
            ':audio_output_channels' => $audio['channels'],
            ':audio_track_mode' => $audio['track_mode'],
            ':ts_video_pid' => $ts['video'],
            ':ts_audio_pid' => $ts['audio'],
            ':ts_pmt_pid' => $ts['pmt'],
            ':ts_program' => $ts['program'],
            ':audio_bitrate' => $audio['bitrate'],
            ':video_codec' => $videoCodec,
            ':video_rate_control' => $rateControl,
            ':video_keyframe_interval' => $keyframeInterval,
            ':video_scale_enabled' => $scaleEnabled,
            ':srt_passphrase' => $srtSecurity['passphrase'],
            ':srt_pbkeylen' => $srtSecurity['pbkeylen'],
            ':srt_streamid' => $srtSecurity['streamid'],
            ':clock_overlay_enabled' => (int)($data['clock_overlay_enabled'] ?? 0) === 1 ? 1 : 0,
            ':audio_test_wave' => $this->sanitizeAudioTestWave($data),
            ':preview_enabled' => (int)($data['preview_enabled'] ?? 0) === 1 ? 1 : 0,
            ':test_pattern_enabled' => (int)($data['test_pattern_enabled'] ?? 0) === 1 ? 1 : 0,
            ':target_name' => $this->sanitizeTargetName($data),
            ':video_speed_preset' => $this->sanitizeSpeedPreset($data),
        ]);
    }


    public function getStats(int $id): array
    {
        $p = $this->getPipelineOrFail($id);
        return $this->processStats(
            $p['status'] === 'running' ? (int)$p['pid'] : null,
            $p['started_at'],
            __DIR__ . "/../data/pipeline_{$id}.log"
        );
    }

    private function processStats(?int $pid, ?string $startedAt, string $logFile): array
    {
        $result = [
            'uptime_seconds' => null,
            'cpu_percent' => null,
            'cpu_cores' => $this->getCpuCoreCount(),
            'memory_kb' => null,
            'client_connected' => false,
            'log_tail' => '',
        ];

        if ($this->isProcRunning($pid)) {
            if ($startedAt) {
                $started = new DateTime($startedAt, new DateTimeZone('UTC'));
                $now = new DateTime('now', new DateTimeZone('UTC'));
                $result['uptime_seconds'] = $now->getTimestamp() - $started->getTimestamp();
            }

            $psOutput = shell_exec("ps -o %cpu,rss --no-headers -p " . (int)$pid);
            if ($psOutput) {
                $parts = preg_split('/\\s+/', trim($psOutput));
                if (count($parts) === 2) {
                    $result['cpu_percent'] = (float)$parts[0];
                    $result['memory_kb'] = (int)$parts[1];
                }
            }
        }

        if (file_exists($logFile)) {
            $lines = shell_exec("tail -n 30 " . escapeshellarg($logFile));
            $result['log_tail'] = $lines ?? '';
            if (str_contains($result['log_tail'], 'Accepted') || str_contains($result['log_tail'], 'caller-added')) {
                $result['client_connected'] = true;
            }
        }

        return $result;
    }

    private function getCpuCoreCount(): int
    {
        if (self::$cpuCoreCount === null) {
            $nproc = (int)trim((string)shell_exec('nproc'));
            self::$cpuCoreCount = $nproc > 0 ? $nproc : 1;
        }
        return self::$cpuCoreCount;
    }

    public function probeDecklinkSignal(int $deviceNumber, string $connection, bool $fresh = false): array
    {
        if ($deviceNumber < 0 || $deviceNumber > 3) {
            $deviceNumber = 0;
        }
        if (!in_array($connection, self::DECKLINK_CONNECTIONS, true)) {
            $connection = 'auto';
        }

        // Eine Probe oeffnet die Karte fuer bis zu 4,7 s und belegt dabei einen der wenigen PHP-Worker. Mehrere offene
        // Seiten fragen alle 15 s je Eingang nach: darum Ergebnis 12 s zwischenspeichern, und waehrend schon eine Probe
        // laeuft das letzte Ergebnis liefern, statt eine zweite zu starten ($fresh = true: Schaltflaeche "Signal pruefen").
        $cacheFile = __DIR__ . "/../data/probe_{$deviceNumber}.json";
        $lockFile = __DIR__ . "/../data/probe_{$deviceNumber}.lock";
        $cached = null;
        if (is_file($cacheFile)) {
            $c = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($c) && isset($c['result'], $c['time'])) {
                $cached = $c;
                if (!$fresh && time() - (int)$c['time'] < 12) {
                    return $c['result'];
                }
            }
        }
        $lock = @fopen($lockFile, 'c');
        if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            if ($cached !== null) {
                return $cached['result'];
            }
            return ['signal' => false, 'width' => null, 'height' => null, 'framerate' => null, 'error' => 'Pruefung laeuft gerade'];
        }

        $script = escapeshellarg(__DIR__ . '/../scripts/probe_decklink.py');
        $cmd = "timeout 6 python3 {$script} " . (int)$deviceNumber . " " . escapeshellarg($connection) . " 4 2>&1";
        $output = shell_exec($cmd);

        $json = json_decode(trim((string)$output), true);
        if (is_array($json) && !isset($json['error'])) {
            @file_put_contents($cacheFile, json_encode(['time' => time(), 'result' => $json]));
        }
        if ($lock !== false) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if (!is_array($json)) {
            return [
                'signal' => false,
                'width' => null,
                'height' => null,
                'framerate' => null,
                'error' => 'Probe fehlgeschlagen oder Zeitüberschreitung',
                'raw' => $output,
            ];
        }
        return $json;
    }
}
