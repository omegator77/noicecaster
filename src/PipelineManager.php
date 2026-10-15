<?php

require_once __DIR__ . '/TestSignal.php';
require_once __DIR__ . '/IngestManager.php';
require_once __DIR__ . '/LowerThird.php';

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
    // Ausgabeprotokoll je Ziel. RTMP (nur Senden): H.264 4:2:0 8 Bit + AAC Stereo in FLV, keine PIDs, kein Abgriff.
    // Bei Empfaengern (SRT/RTMP -> SDI): 'rtmp' = Strom von einem RTMP-Server abrufen, 'rtmp_in' = ein Encoder sendet per RTMP
    // an das Gateway (Hilfsserver, siehe IngestManager).
    private const OUTPUT_PROTOCOLS = ['srt', 'rtmp', 'rtmp_in'];
    // Text-Einblendung: Lage im Bild (senkrecht-waagerecht)
    private const OVERLAY_POSITIONS = ['top-left', 'top-center', 'top-right', 'middle-left', 'middle-center', 'middle-right',
        'bottom-left', 'bottom-center', 'bottom-right'];
    private const VIDEO_RATE_CONTROLS = ['cbr', 'vbr'];
    // Chroma-Subsampling und Bittiefe des Videostroms (H.264/H.265; MPEG-2 nur 8 Bit)
    private const VIDEO_CHROMA = ['420', '422'];
    private const VIDEO_BITDEPTHS = [8, 10];
    private const VIDEO_FRAMINGS = ['I', 'IP', 'IBP', 'IBBP', 'IBBBP', 'IBBBBP'];
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
            if (($p['pipeline_direction'] ?? '') === 'srt_to_sdi') {
                $p['testsignal_running'] = TestSignal::isRunning((int)$p['decklink_device_number']);
            }
        }

        return array_map(fn($row) => $this->maskOutputSecrets($row), $pipelines);
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
        if (isset($data['output_protocol']) && !in_array($data['output_protocol'], self::OUTPUT_PROTOCOLS, true)) {
            throw new RuntimeException("Ausgabeprotokoll muss 'srt' oder 'rtmp' sein");
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
        $data = $this->applyRtmpLimits($data);
        $ov = $this->sanitizeOverlay($data);
        $lt = LowerThird::sanitize($data);
        $out = $this->sanitizeOutput($data);
        $decklink = $this->sanitizeDecklink($data);
        $direction = $this->sanitizeDirection($data);
        $audio = $this->sanitizeAudio($data);
        $ts = $this->sanitizeTsIds($data, $audio);
        $videoCodec = $this->sanitizeVideoCodec($data);
        $vf = $this->sanitizeVideoFormat($data, $videoCodec);
        $gop = $this->sanitizeGop($data, $videoCodec);
        $rateControl = $this->sanitizeVideoRateControl($data);
        $keyframeInterval = (int)($data['video_keyframe_interval'] ?? 0);
        $scaleEnabled = (int)($data['video_scale_enabled'] ?? 0) === 1 ? 1 : 0;
        $srtSecurity = $this->sanitizeSrtSecurity($data);
        $this->validatePortUsage($data, $decklink, $direction);
        $stmt = $this->db->prepare("
            INSERT INTO pipelines (name, source_type, source_pattern, width, height, framerate, bitrate, srt_port, srt_latency, srt_mode, srt_target_host, source_file, decklink_device_number, decklink_connection, decklink_profile, decklink_audio_channels, pipeline_direction, audio_codec, audio_output_channels, audio_track_mode, ts_video_pid, ts_audio_pid, ts_pmt_pid, ts_program, video_chroma, video_bitdepth, video_framing, video_framerate, video_scenecut, video_open_gop, audio_bitrate, video_codec, video_rate_control, video_keyframe_interval, video_scale_enabled, srt_passphrase, srt_pbkeylen, srt_streamid, clock_overlay_enabled, audio_test_wave, preview_enabled, test_pattern_enabled, target_name, video_speed_preset, output_protocol, rtmp_url, rtmp_key, rtmp_deinterlace, overlay_enabled, overlay_text, overlay_pos, overlay_size, overlay_color, overlay_bg, lt_template, lt_title, lt_main, lt_sub, lt_color, lt_logo, lt_anim, lt_dur, lt_hold, lt_scale, lt_pos)
            VALUES (:name, :source_type, :source_pattern, :width, :height, :framerate, :bitrate, :srt_port, :srt_latency, :srt_mode, :srt_target_host, :source_file, :decklink_device_number, :decklink_connection, :decklink_profile, :decklink_audio_channels, :pipeline_direction, :audio_codec, :audio_output_channels, :audio_track_mode, :ts_video_pid, :ts_audio_pid, :ts_pmt_pid, :ts_program, :video_chroma, :video_bitdepth, :video_framing, :video_framerate, :video_scenecut, :video_open_gop, :audio_bitrate, :video_codec, :video_rate_control, :video_keyframe_interval, :video_scale_enabled, :srt_passphrase, :srt_pbkeylen, :srt_streamid, :clock_overlay_enabled, :audio_test_wave, :preview_enabled, :test_pattern_enabled, :target_name, :video_speed_preset, :output_protocol, :rtmp_url, :rtmp_key, :rtmp_deinterlace, :overlay_enabled, :overlay_text, :overlay_pos, :overlay_size, :overlay_color, :overlay_bg, :lt_template, :lt_title, :lt_main, :lt_sub, :lt_color, :lt_logo, :lt_anim, :lt_dur, :lt_hold, :lt_scale, :lt_pos)
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
            ':video_chroma' => $vf['chroma'],
            ':video_bitdepth' => $vf['depth'],
            ':video_framing' => $gop['framing'],
            ':video_framerate' => $gop['framerate'],
            ':video_scenecut' => $gop['scenecut'],
            ':video_open_gop' => $gop['open'],
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
            ':output_protocol' => $out['protocol'],
            ':rtmp_url' => $out['url'],
            ':rtmp_key' => $out['key'],
            ':rtmp_deinterlace' => $out['deinterlace'],
            ':overlay_enabled' => $ov['enabled'],
            ':overlay_text' => $ov['text'],
            ':overlay_pos' => $ov['pos'],
            ':overlay_size' => $ov['size'],
            ':overlay_color' => $ov['color'],
            ':overlay_bg' => $ov['bg'],
            ':lt_template' => $lt['lt_template'],
            ':lt_title' => $lt['lt_title'],
            ':lt_main' => $lt['lt_main'],
            ':lt_sub' => $lt['lt_sub'],
            ':lt_color' => $lt['lt_color'],
            ':lt_logo' => $lt['lt_logo'],
            ':lt_anim' => $lt['lt_anim'],
            ':lt_dur' => $lt['lt_dur'],
            ':lt_hold' => $lt['lt_hold'],
            ':lt_scale' => $lt['lt_scale'],
            ':lt_pos' => $lt['lt_pos'],
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

    /** @return array{chroma: string, depth: int} gueltige Kombination aus Chroma-Subsampling und Bittiefe fuer den Codec */
    private function sanitizeVideoFormat(array $data, string $codec): array
    {
        $chroma = (string)($data['video_chroma'] ?? '420');
        if (!in_array($chroma, self::VIDEO_CHROMA, true)) {
            $chroma = '420';
        }
        $depth = (int)($data['video_bitdepth'] ?? 8);
        if (!in_array($depth, self::VIDEO_BITDEPTHS, true) || $codec === 'mpeg2') {
            $depth = 8; // avenc_mpeg2video kennt nur 8 Bit
        }
        return ['chroma' => $chroma, 'depth' => $depth];
    }

    /**
     * Framing (wie bei Hardware-Encodern): I, IP, IBP, IBBP, IBBBP oder IBBBBP, dazu Bildrate (0 = wie der Eingang)
     * sowie Szenenwechsel-Keyframes und offene/geschlossene GOP. Die GOP-Groesse ist das Keyframe-Intervall.
     * Standard (IP, Bildrate automatisch, Szenenwechsel an, geschlossen) = bisheriges Verhalten.
     * @return array{framing: string, bframes: int, intra: bool, framerate: int, scenecut: int, open: int}
     */
    private function sanitizeGop(array $data, string $codec): array
    {
        $framing = strtoupper((string)($data['video_framing'] ?? 'IP'));
        if (!in_array($framing, self::VIDEO_FRAMINGS, true)) {
            $framing = 'IP';
        }
        $bframes = substr_count($framing, 'B');
        $framerate = (int)($data['video_framerate'] ?? 0);
        if ($framerate < 0 || $framerate > 60) {
            $framerate = 0;
        }
        return [
            'framing' => $framing,
            'bframes' => $bframes,
            'intra' => $framing === 'I',
            'framerate' => $framerate,
            'scenecut' => (int)($data['video_scenecut'] ?? 1) === 0 ? 0 : 1,
            // offen oder geschlossen gibt es nur mit B-Frames
            'open' => ($bframes > 0 && (int)($data['video_open_gop'] ?? 0) === 1) ? 1 : 0,
        ];
    }

    private function videoFormatForCodec(string $codec, array $vf = ['chroma' => '420', 'depth' => 8]): string
    {
        if ($vf['depth'] === 10) {
            return $vf['chroma'] === '422' ? 'I422_10LE' : 'I420_10LE';
        }
        if ($vf['chroma'] === '422') {
            return 'Y42B';
        }
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


    /** Optionale Bildraten-Reduzierung (nur Bilder weglassen) und der Encoder dahinter. */
    private function buildVideoEncoder(string $codec, int $bitrate, int $keyframeInterval, string $rateControl, string $speedPreset = 'veryfast', array $gop = ['framing' => 'IP', 'bframes' => 0, 'intra' => false, 'framerate' => 0, 'scenecut' => 1, 'open' => 0]): string
    {
        $rate = ((int)($gop['framerate'] ?? 0)) > 0 ? "videorate ! video/x-raw,framerate={$gop['framerate']}/1 ! " : '';
        return $rate . $this->buildVideoEncoderCore($codec, $bitrate, $keyframeInterval, $rateControl, $speedPreset, $gop);
    }

    private function buildVideoEncoderCore(string $codec, int $bitrate, int $keyframeInterval, string $rateControl, string $speedPreset = 'veryfast', array $gop = ['framing' => 'IP', 'bframes' => 0, 'intra' => false, 'framerate' => 0, 'scenecut' => 1, 'open' => 0]): string
    {
        $bf = (int)$gop['bframes'];
        if (!empty($gop['intra'])) {
            $keyframeInterval = 1; // nur I-Bilder: jede GOP hat die Groesse 1
        }
        switch ($codec) {
            case 'h265':
                // Mit B-Frames kein zerolatency-Tuning (es schaltet B-Frames ab); feste Struktur ohne Vorausschau
                $chain = "x265enc " . ($bf > 0 ? '' : 'tune=zerolatency ') . "speed-preset={$speedPreset} bitrate={$bitrate} key-int-max={$keyframeInterval}";
                $opts = [];
                if ($rateControl === 'cbr') {
                    // x265 hat keine eigene VBV-Property, daher ueber die x265-Optionszeile erzwungen
                    $opts[] = "vbv-bufsize={$bitrate}";
                    $opts[] = "vbv-maxrate={$bitrate}";
                }
                if ($bf > 0) {
                    $opts[] = "bframes={$bf}";
                    $opts[] = 'b-adapt=0';
                    $opts[] = 'rc-lookahead=' . ($bf + 4);
                    $opts[] = 'open-gop=' . ($gop['open'] ? '1' : '0');
                }
                if (!$gop['scenecut']) {
                    $opts[] = 'scenecut=0';
                }
                if ($opts) {
                    $chain .= ' option-string=\"' . implode(':', $opts) . '\"';
                }
                return $chain . " ! h265parse config-interval=-1";
            case 'mpeg2':
                // avenc_mpeg2video kennt kein speed-preset - Parameter wird fuer diesen Codec ignoriert
                $bps = $bitrate * 1000;
                $chain = "avenc_mpeg2video bitrate={$bps} gop-size={$keyframeInterval}";
                // sc-threshold=1000000000 schaltet die Szenenwechsel-Erkennung ab; mit geschlossener GOP ist das Pflicht
                // (libavcodec: "closed gop with scene change detection are not supported yet")
                $noScenecut = !$gop['scenecut'];
                if ($bf > 0) {
                    $chain .= " max-bframes={$bf}";
                    if (!$gop['open']) {
                        $chain .= ' flags=cgop';
                        $noScenecut = true;
                    }
                }
                if ($noScenecut) {
                    $chain .= ' sc-threshold=1000000000';
                }
                if ($rateControl === 'cbr') {
                    $chain .= " bufsize={$bps} maxrate={$bps} minrate={$bps}";
                }
                return $chain . " ! mpegvideoparse";
            default:
                $chain = "x264enc " . ($bf > 0 ? '' : 'tune=zerolatency ') . "speed-preset={$speedPreset} bitrate={$bitrate} key-int-max={$keyframeInterval}";
                if ($bf > 0) {
                    // feste Struktur (I B B P ...) ohne Vorausschau, damit die Verzoegerung klein und vorhersagbar bleibt
                    $chain .= " bframes={$bf} b-adapt=false rc-lookahead=0";
                }
                if ($rateControl === 'cbr') {
                    // 1s VBV-Puffer erzwingt eine echte Konstant-Bitrate statt nur eines Zielwerts
                    $chain .= " vbv-buf-capacity=1000";
                }
                $opts = [];
                if (!$gop['scenecut']) {
                    $opts[] = 'scenecut=0';
                }
                if ($gop['open']) {
                    $opts[] = 'open-gop=1';
                }
                if ($opts) {
                    $chain .= ' option-string=\"' . implode(':', $opts) . '\"';
                }
                return $chain . " ! h264parse config-interval=-1";
        }
    }

    private function isRtmp(array $row): bool
    {
        return ($row['output_protocol'] ?? 'srt') === 'rtmp';
    }

    private function isRtmpIn(array $row): bool
    {
        return ($row['output_protocol'] ?? 'srt') === 'rtmp_in' && $this->isReceiverRow($row);
    }

    /** Empfaenger-Pipeline (SRT/RTMP -> SDI-Ausgang), also ohne Encoder. */
    private function isReceiverRow(array $row): bool
    {
        return ($row['pipeline_direction'] ?? 'sdi_to_srt') === 'srt_to_sdi';
    }

    /**
     * Vollstaendige RTMP-Adresse: URL plus Stream-Key bzw. Stream-Name. Beim Senden Pflicht, beim Empfang optional
     * (dann steht der Stream-Name schon in der URL).
     */
    private function rtmpLocation(array $row): string
    {
        $out = $this->sanitizeOutput($row);
        if ($out['url'] === null) {
            throw new RuntimeException('RTMP benoetigt eine URL');
        }
        return $out['key'] !== null ? $out['url'] . '/' . $out['key'] : $out['url'];
    }

    /**
     * RTMP kann nur H.264 (4:2:0, 8 Bit) und AAC in einer Stereo-Spur: diese Werte werden bei RTMP-Zielen fest gesetzt
     * (beim Speichern und beim Bauen des Befehls), PIDs/Programm gibt es im FLV-Strom nicht. Bei SRT aendert sich nichts.
     */
    private function applyRtmpLimits(array $data): array
    {
        if (!$this->isRtmp($data) || $this->isReceiverRow($data)) {
            return $data;
        }
        return array_merge($data, [
            'video_codec' => 'h264', 'video_chroma' => '420', 'video_bitdepth' => 8,
            'audio_codec' => 'aac', 'audio_output_channels' => 2, 'audio_track_mode' => 'multi',
            'ts_video_pid' => 0, 'ts_audio_pid' => 0, 'ts_pmt_pid' => 0, 'ts_program' => 0,
        ]);
    }

    private const RTMP_URL_PATTERN = '#^rtmps?://[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(:[0-9]{1,5})?(/[A-Za-z0-9._~/-]*)?$#';

    /**
     * RTMP-Ziel pruefen: URL ohne Stream-Key (rtmp:// oder rtmps://, Host[:Port][/Anwendung]) und der Key getrennt.
     * Beides landet im Shell-Befehl, deshalb nur eine enge Zeichenmenge (keine Leerzeichen, Anfuehrungszeichen, ! $ ` ; |).
     * Ein leer gelassener Key behaelt den gespeicherten ($existingKey), ausser 'rtmp_key_clear' ist 1.
     * @return array{protocol: string, url: ?string, key: ?string, deinterlace: int}
     */
    private function sanitizeOutput(array $data, ?string $existingKey = null): array
    {
        $requested = $data['output_protocol'] ?? 'srt';
        $protocol = in_array($requested, self::OUTPUT_PROTOCOLS, true) ? $requested : 'srt';
        $url = rtrim(trim((string)($data['rtmp_url'] ?? '')), '/');
        $key = trim((string)($data['rtmp_key'] ?? ''));
        // Bei der RTMP-Annahme wird der Key immer mitgeschickt (er ist in der Oberflaeche sichtbar): leer heisst leer, nicht behalten
        if ($key === '' && $existingKey !== null && $protocol !== 'rtmp_in' && (int)($data['rtmp_key_clear'] ?? 0) !== 1) {
            $key = $existingKey;
        }
        $urlValid = $url !== '' && strlen($url) <= 300 && preg_match(self::RTMP_URL_PATTERN, $url) === 1;
        if ($protocol === 'rtmp_in') {
            if (!$this->isReceiverRow($data)) {
                throw new RuntimeException('RTMP-Annahme gibt es nur bei Ausgangs-Anschluessen (Empfang -> SDI)');
            }
            // Der Key ist der Pfad im Hilfsserver und damit die Zugangsberechtigung zum Senden: eigene, engere Zeichenmenge
            if ($key === '') {
                throw new RuntimeException('RTMP-Annahme benoetigt einen Stream-Key (so wie ihn der Encoder eintraegt)');
            }
            if (preg_match('/^[A-Za-z0-9._~-]{4,128}$/', $key) !== 1) {
                throw new RuntimeException('Stream-Key fuer die RTMP-Annahme: 4 bis 128 Zeichen aus Buchstaben, Ziffern und . _ ~ -');
            }
            $url = '';
        } elseif ($protocol === 'rtmp') {
            if ($url === '') {
                throw new RuntimeException('RTMP-Ziel benoetigt eine Server-URL (z. B. rtmp://a.rtmp.youtube.com/live2)');
            }
            if (!$urlValid) {
                throw new RuntimeException('RTMP-URL muss mit rtmp:// oder rtmps:// beginnen und darf nur Buchstaben, Ziffern und . - _ ~ / enthalten (ohne Stream-Key)');
            }
            if ($key === '' && !$this->isReceiverRow($data)) {
                throw new RuntimeException('RTMP-Ziel benoetigt einen Stream-Key');
            }
        } elseif (!$urlValid) {
            $url = ''; // bei SRT-Zielen nur Gueltiges ablegen
        }
        if ($key !== '' && (strlen($key) > 256 || preg_match('/^[A-Za-z0-9._~?=&%:+-]+$/', $key) !== 1)) {
            throw new RuntimeException('Stream-Key darf nur Buchstaben, Ziffern und . _ ~ ? = & % : + - enthalten (maximal 256 Zeichen)');
        }
        return [
            'protocol' => $protocol,
            'url' => $url !== '' ? $url : null,
            'key' => $key !== '' ? $key : null,
            'deinterlace' => (int)($data['rtmp_deinterlace'] ?? 0) === 1 ? 1 : 0,
        ];
    }

    /** Was nicht an den Browser darf, entfernen: der Stream-Key bleibt serverseitig (gemeldet wird nur, ob einer gesetzt ist). */
    private function maskOutputSecrets(array $row): array
    {
        $row['rtmp_key_set'] = !empty($row['rtmp_key']) ? 1 : 0;
        // Bei der RTMP-Annahme ist der Key die Adresse, an die der Encoder sendet (rtmp://Gateway:1935/live/KEY): er muss
        // in der Oberflaeche lesbar bleiben (Zugriff nur mit Anmeldung, wie die SRT-Passphrase). Sonst bleibt er serverseitig.
        if (($row['output_protocol'] ?? 'srt') !== 'rtmp_in') {
            unset($row['rtmp_key']);
        }
        return $row;
    }

    /** Zeilensprung entfernen (nur RTMP und nur wenn gewaehlt): SDI-1080i50 ergibt 1080p50 (fuer 25p zusaetzlich Bildrate 25 setzen); YouTube/Twitch erwarten Vollbilder. */
    private function deinterlaceElement(array $row): string
    {
        return ($this->isRtmp($row) && (int)($row['rtmp_deinterlace'] ?? 0) === 1) ? 'deinterlace ! ' : '';
    }

    /**
     * Letzter Abschnitt einer Sender-Kette (hinter dem Encoder): bei SRT Muxer, Statistik-Abgriff und srtsink - Zeichen fuer
     * Zeichen wie bisher; bei RTMP flvmux und rtmp2sink. Der Audio-Zweig muendet in beiden Faellen in "mux.".
     * @param bool $waitFalse SRT: wait-for-connection=false (bei Live-Quellen)
     */
    private function buildSenderTail(array $row, array $mux, string $srtUri, int $latency, string $srtSecurityProps, string $tap, bool $waitFalse): string
    {
        if ($this->isRtmp($row)) {
            return $this->buildRtmpTail($row);
        }
        return "{$mux['videoPad']}mpegtsmux name=mux latency=200000000{$mux['props']} ! tee name=tap ! " .
            "srtsink uri=\"{$srtUri}\" latency={$latency}{$srtSecurityProps}" . ($waitFalse ? ' wait-for-connection=false' : '') . " {$tap}";
    }

    private function buildRtmpTail(array $row): string
    {
        $out = $this->sanitizeOutput($row);
        if ($out['url'] === null || $out['key'] === null) {
            throw new RuntimeException('RTMP-Ziel benoetigt Server-URL und Stream-Key');
        }
        $location = escapeshellarg($this->rtmpLocation($row));
        // avc/au: flvmux verlangt H.264 ohne Startcodes; latency wie beim Transportstrom, damit der Ton beim Start nicht fehlt.
        // Queues: vor flvmux (Latenzabfrage, siehe muxSetupFor) und vor rtmp2sink (3 s Puffer, damit ein kurzes Stocken des
        // Netzes die Encoder-Kette und den Verteiler nicht blockiert).
        return "queue ! video/x-h264,stream-format=avc,alignment=au ! flvmux name=mux streamable=true latency=200000000 ! " .
            "queue max-size-buffers=0 max-size-bytes=0 max-size-time=3000000000 ! rtmp2sink location={$location} async=false ";
    }

    /**
     * Text-Einblendung pruefen: Text (bis 200 Zeichen, bis 4 Zeilen, UTF-8, keine Steuerzeichen ausser Zeilenumbruch), Lage in
     * 9 Feldern, Groesse in Prozent der Bildhoehe (1 bis 30), Farbe #RRGGBB, optionaler Hintergrund. Nicht gesetzte Werte ergeben
     * "aus" und die Standardwerte (unten mittig, 5 %, weiss, ohne Hintergrund).
     * @return array{enabled: int, text: ?string, pos: string, size: int, color: string, bg: int}
     */
    public static function sanitizeOverlay(array $data): array
    {
        $text = (string)($data['overlay_text'] ?? '');
        if (preg_match('//u', $text) !== 1) {
            throw new RuntimeException('Einblend-Text ist kein gueltiges UTF-8');
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $text);
        $text = trim($text);
        if (preg_match_all('/./us', $text) > 200) {
            throw new RuntimeException('Einblend-Text darf hoechstens 200 Zeichen lang sein');
        }
        if (substr_count($text, "\n") >= 4) {
            throw new RuntimeException('Einblend-Text darf hoechstens 4 Zeilen haben');
        }
        $pos = (string)($data['overlay_pos'] ?? 'bottom-center');
        if (!in_array($pos, self::OVERLAY_POSITIONS, true)) {
            $pos = 'bottom-center';
        }
        $size = (int)($data['overlay_size'] ?? 5);
        if ($size < 1 || $size > 30) {
            $size = 5;
        }
        $color = strtoupper(trim((string)($data['overlay_color'] ?? '#FFFFFF')));
        if (preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
            $color = '#FFFFFF';
        }
        return [
            'enabled' => (int)($data['overlay_enabled'] ?? 0) === 1 ? 1 : 0,
            'text' => $text !== '' ? $text : null,
            'pos' => $pos,
            'size' => $size,
            'color' => $color,
            'bg' => (int)($data['overlay_bg'] ?? 0) === 1 ? 1 : 0,
        ];
    }

    /** Ist fuer diese Zeile eine Einblendung eingerichtet (Text vorhanden oder eingeschaltet)? Nur dann bekommt die Kette ein Element. */
    private function overlayConfigured(array $row): bool
    {
        $ov = $this->sanitizeOverlay($row);
        return $ov['enabled'] === 1 || $ov['text'] !== null;
    }

    /**
     * Gemeinsame Eigenschaften der Einblendung fuer ein Bild der Hoehe $frameHeight: Schrift in Pixeln (Prozent der Bildhoehe,
     * ohne auto-resize, damit die Groesse bei jeder Aufloesung gleich wirkt), Ausrichtung, Farbe als ARGB, Rand.
     * @return array{valign: string, halign: string, font: string, color: int, shaded: bool, pad: int}
     */
    private function overlayProps(array $ov, int $frameHeight): array
    {
        [$v, $h] = explode('-', $ov['pos']);
        $px = max(8, (int)round($ov['size'] * max(1, $frameHeight) / 100));
        return [
            'valign' => $v === 'middle' ? 'center' : $v,
            'halign' => $h,
            'font' => 'Sans Bold ' . $px,
            'color' => 0xFF000000 | hexdec(substr($ov['color'], 1)),
            'shaded' => $ov['bg'] === 1,
            'pad' => max(8, (int)round($frameHeight * 0.02)),
        ];
    }

    /**
     * textoverlay-Element fuer eine gst-launch-Kette (Sender), leer wenn keine Einblendung eingeschaltet ist. Der Text ist
     * Pango-Markup, deshalb werden & < > ersetzt (er erscheint woertlich); fuer gst-launch steht er in Anfuehrungszeichen
     * (\ und " mit Rueckstrich), als EIN Shell-Argument: damit sind Leerzeichen, ! ; $ ` Klammern und fuehrende Minuszeichen
     * im Text unkritisch (geprueft).
     */
    private function buildOverlayElement(array $row, int $frameHeight): string
    {
        $ov = $this->sanitizeOverlay($row);
        if ($ov['enabled'] !== 1 || $ov['text'] === null) {
            return '';
        }
        $p = $this->overlayProps($ov, $frameHeight);
        $text = htmlspecialchars($ov['text'], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = str_replace(['\\', '"'], ['\\\\', '\\"'], $text);
        return 'textoverlay ' . escapeshellarg('text="' . $text . '"') . ' ' . escapeshellarg('font-desc="' . $p['font'] . '"') .
            " auto-resize=false halignment={$p['halign']} valignment={$p['valign']} color={$p['color']} " .
            'shaded-background=' . ($p['shaded'] ? 'true' : 'false') . " xpad={$p['pad']} ypad={$p['pad']} ! ";
    }

    /** Bildhoehe des SDI-Modus (fuer die Schriftgroesse); unbekannte Modi (auto, SD) rechnen mit 1080. */
    private function modeFrameHeight(string $mode): int
    {
        return (int)(self::HD_MODE_CAPS[$mode]['height'] ?? 1080);
    }

    public static function overlayPath(int $id): string
    {
        return __DIR__ . "/../data/overlay_{$id}.json";
    }

    /** Steuerdatei fuer den laufenden Empfaenger (liest scripts/srt_to_sdi_receiver.py jede halbe Sekunde). */
    private function writeOverlayFile(array $p): void
    {
        $ov = $this->sanitizeOverlay($p);
        $json = json_encode([
            'enabled' => $ov['enabled'] === 1, 'text' => $ov['text'] ?? '', 'pos' => $ov['pos'], 'size' => $ov['size'],
            'color' => $ov['color'], 'bg' => $ov['bg'] === 1,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = self::overlayPath((int)$p['id']);
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $path)) {
            throw new RuntimeException('Einblend-Steuerdatei nicht schreibbar');
        }
    }

    /** Argumente fuer den Empfaenger: nur wenn eine Einblendung eingerichtet ist (sonst bleibt der Befehl wie bisher). */
    private function receiverOverlayArgs(array $p, string $outputMode): string
    {
        if (!$this->overlayConfigured($p)) {
            return '';
        }
        return ' --overlay-file ' . escapeshellarg(self::overlayPath((int)$p['id'])) .
            ' --overlay-height ' . $this->modeFrameHeight($outputMode);
    }

    /**
     * Einblendung eines Empfaengers im Betrieb aendern (ohne Neustart): speichert die Werte und schreibt die Steuerdatei.
     * "live" ist nur wahr, wenn der laufende Empfaenger mit Einblendung gestartet wurde (sonst gilt der neue Wert ab dem naechsten Start).
     * @return array{live: bool, running: bool}
     */
    public function setOverlayLive(int $id, array $data): array
    {
        $p = $this->getPipelineOrFail($id);
        if (!$this->isReceiverRow($p)) {
            throw new RuntimeException('Live-Einblendung gibt es nur bei Ausgangs-Anschluessen');
        }
        $ov = $this->sanitizeOverlay($data);
        $stmt = $this->db->prepare('UPDATE pipelines SET overlay_enabled = :e, overlay_text = :t, overlay_pos = :p, overlay_size = :s, overlay_color = :c, overlay_bg = :b WHERE id = :id');
        $stmt->execute([':e' => $ov['enabled'], ':t' => $ov['text'], ':p' => $ov['pos'], ':s' => $ov['size'], ':c' => $ov['color'], ':b' => $ov['bg'], ':id' => $id]);
        $p = $this->getPipelineOrFail($id);
        $running = $p['status'] === 'running' && $this->isProcRunning($p['pid'] !== null ? (int)$p['pid'] : null);
        $live = false;
        if ($running) {
            $cmdline = (string)@file_get_contents('/proc/' . (int)$p['pid'] . '/cmdline');
            $live = str_contains($cmdline, '--overlay-file');
            if ($live) {
                $this->writeOverlayFile($p);
            }
        }
        return ['live' => $live, 'running' => $running];
    }

    // --- Bauchbinde (Lower Third) am SDI-Ausgang: Grafik mit Hintergrund, die ein-/ausfliegt (siehe LowerThird.php) ---

    public static function lowerThirdPath(int $id): string
    {
        return __DIR__ . "/../data/lowerthird_{$id}.json";
    }

    public static function lowerThirdPng(int $id): string
    {
        return LowerThird::dataDir() . "/lt_{$id}.png";
    }

    /** Ausgangsmodus des Anschlusses (fuer Bildgroesse der Bauchbinde). */
    private function ltOutputMode(array $p): string
    {
        $mode = $this->ports->getMode((int)($p['decklink_device_number'] ?? 0));
        return $mode === 'auto' ? DecklinkPortManager::DEFAULT_OUTPUT_MODE : $mode;
    }

    /**
     * Grafik rendern und die Steuerdatei fuer den Empfaenger schreiben. $cmd: 'none' (nur Werte), 'show' oder 'hide'
     * (erhoeht die Laufnummer seq, der Empfaenger fuehrt jeden neuen Befehl genau einmal aus).
     * @return array{seq: int, cmd: string}
     */
    private function writeLowerThirdFile(array $p, string $cmd = 'none'): array
    {
        $id = (int)$p['id'];
        $path = self::lowerThirdPath($id);
        $old = json_decode((string)@file_get_contents($path), true);
        $seq = is_array($old) ? (int)($old['seq'] ?? 0) : 0;
        $data = ['png' => null, 'w' => 0, 'h' => 0, 'pos' => 'bottom-left', 'anim' => 'slide-left', 'dur' => 700, 'hold' => 0, 'seq' => $seq, 'cmd' => 'none'];
        $cfg = LowerThird::sanitize($p);
        if (LowerThird::configured($cfg)) {
            $mode = $this->ltOutputMode($p);
            $frameW = (int)(self::HD_MODE_CAPS[$mode]['width'] ?? 1920);
            $r = LowerThird::render($cfg, $frameW, self::lowerThirdPng($id));
            $data['png'] = $r['png'] . '?' . filemtime($r['png']);
            $data['w'] = $r['w'];
            $data['h'] = $r['h'];
            $data['pos'] = $cfg['lt_pos'];
            $data['anim'] = $cfg['lt_anim'];
            $data['dur'] = $cfg['lt_dur'];
            $data['hold'] = $cfg['lt_hold'];
        }
        if ($cmd === 'show' || $cmd === 'hide') {
            $data['seq'] = $seq + 1;
            $data['cmd'] = $cmd;
        }
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES)) === false || !@rename($tmp, $path)) {
            throw new RuntimeException('Bauchbinden-Steuerdatei nicht schreibbar');
        }
        return ['seq' => $data['seq'], 'cmd' => $data['cmd']];
    }

    /** Argumente fuer den Empfaenger: nur wenn eine Bauchbinde eingerichtet ist (sonst bleibt der Befehl wie bisher). */
    private function receiverLowerThirdArgs(array $p, string $outputMode): string
    {
        if (!LowerThird::configured(LowerThird::sanitize($p))) {
            return '';
        }
        return ' --lt-file ' . escapeshellarg(self::lowerThirdPath((int)$p['id'])) .
            ' --frame-width ' . (int)(self::HD_MODE_CAPS[$outputMode]['width'] ?? 1920) .
            ' --frame-height ' . $this->modeFrameHeight($outputMode);
    }

    /**
     * Bauchbinde eines Empfaengers bedienen. $action: 'apply' (Werte speichern, Grafik neu rendern), 'show', 'hide', 'toggle'.
     * Bei 'show'/'apply' koennen lt_*-Felder mitgegeben werden (werden gespeichert). "live" ist nur wahr, wenn der laufende
     * Empfaenger mit Bauchbinde gestartet wurde (--lt-file); sonst gelten die gespeicherten Werte ab dem naechsten Start.
     * @return array{live: bool, running: bool, seq: int, state: string}
     */
    public function lowerThirdLive(int $id, array $data, string $action): array
    {
        $p = $this->getPipelineOrFail($id);
        if (!$this->isReceiverRow($p)) {
            throw new RuntimeException('Bauchbinden gibt es nur bei Ausgangs-Anschluessen');
        }
        if (!in_array($action, ['apply', 'show', 'hide', 'toggle'], true)) {
            throw new RuntimeException('Unbekannte Aktion');
        }
        // Mitgegebene lt_*-Felder ueberschreiben die gespeicherten (fehlende bleiben, sonst wuerden 'show'/'hide' ohne Felder alles auf
        // Standard setzen); gespeichert wird bei 'apply' und bei 'show' mit Feldern
        $given = array_intersect_key($data, array_flip(array_filter(array_keys(LowerThird::sanitize([])), fn($k) => true)));
        $lt = LowerThird::sanitize(array_merge($p, $given));
        if ($action === 'show' && !LowerThird::configured($lt)) {
            throw new RuntimeException('Fuer die Bauchbinde fehlen Texte oder ein Logo');
        }
        if ($action === 'apply' || ($action === 'show' && $given)) {
            $set = [];
            $params = [':id' => $id];
            foreach ($lt as $k => $v) {
                $set[] = "{$k} = :{$k}";
                $params[':' . $k] = $v;
            }
            $this->db->prepare('UPDATE pipelines SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
            $p = $this->getPipelineOrFail($id);
        }
        $running = $p['status'] === 'running' && $this->isProcRunning($p['pid'] !== null ? (int)$p['pid'] : null);
        $live = $running && str_contains((string)@file_get_contents('/proc/' . (int)$p['pid'] . '/cmdline'), '--lt-file');
        $seq = 0;
        $state = 'unbekannt';
        if ($live) {
            $stats = json_decode((string)@file_get_contents(self::receiverStatsPath($id)), true);
            $state = (string)($stats['lowerthird']['state'] ?? 'hidden');
            if ($action === 'toggle') {
                $action = ($state === 'hidden' || $state === 'out') ? 'show' : 'hide';
            }
            $res = $this->writeLowerThirdFile($p, in_array($action, ['show', 'hide'], true) ? $action : 'none');
            $seq = $res['seq'];
        }
        return ['live' => $live, 'running' => $running, 'seq' => $seq, 'state' => $state];
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
        if ($this->isRtmp($row)) {
            // flvmux braucht vor jedem Eingang eine Queue, sonst meldet er tausendfach "clock problem" (Latenz nicht konfigurierbar)
            return ['videoPad' => '', 'props' => '', 'audioPad' => fn(int $i) => 'queue ! mux.'];
        }
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
        $target = $this->applyRtmpLimits($target);
        $deint = $this->deinterlaceElement($target);
        $decklink = $this->sanitizeDecklink([
            'decklink_device_number' => $p['decklink_device_number'] ?? 0,
            'decklink_connection' => $p['decklink_connection'] ?? 'auto',
            'decklink_profile' => $p['decklink_profile'] ?? 'default',
            'decklink_audio_channels' => $p['decklink_audio_channels'] ?? 2,
        ]);
        $decklinkMode = $this->ports->getMode($decklink['device_number']);
        $rawCaps = $this->rawCapsStringForMode($decklinkMode);
        $overlay = $this->buildOverlayElement($target, $this->modeFrameHeight($decklinkMode));

        $videoCodec = $this->sanitizeVideoCodec($target);
        $videoFormat = $this->videoFormatForCodec($videoCodec, $this->sanitizeVideoFormat($target, $videoCodec));
        $bitrate = (int)($target['bitrate'] ?? 2000);
        $framerate = self::HD_MODE_CAPS[$decklinkMode]['fps_num'] / self::HD_MODE_CAPS[$decklinkMode]['fps_den'];
        $keyframeInterval = $this->sanitizeKeyframeInterval($target, (int)round($framerate));
        $rateControl = $this->sanitizeVideoRateControl($target);
        $speedPreset = $this->sanitizeSpeedPreset($target);
        $videoEncoder = $this->buildVideoEncoder($videoCodec, $bitrate, $keyframeInterval, $rateControl, $speedPreset, $this->sanitizeGop($target, $videoCodec));

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
        if ($mode === 'caller' && !$this->isRtmp($target)) {
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

        $prefix = "env GST_DEBUG=" . ($this->isRtmp($target) ? 'rtmp2sink:3' : 'srtsink:5') . " GST_DEBUG_NO_COLOR=1 gst-launch-1.0 -v ";
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
        $videoBranch = "shmsrc socket-path={$videoShm} is-live=true ! {$rawCaps} ! rawvideoparse use-sink-caps=true ! {$deint}{$overlay}videoconvert ! {$scaleElement}{$formatCaps} ! " .
            "{$videoEncoder} ! " . $this->buildSenderTail($target, $mux, $srtUri, $latency, $srtSecurityProps, $tap, true);

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
        return array_map(fn($row) => $this->maskOutputSecrets($row), $targets);
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
        $data = $this->applyRtmpLimits($data);
        $ov = $this->sanitizeOverlay($data);
        $out = $this->sanitizeOutput($data);
        $audio = $this->sanitizeAudio($data);
        $ts = $this->sanitizeTsIds($data, $audio);
        $videoCodec = $this->sanitizeVideoCodec($data);
        $vf = $this->sanitizeVideoFormat($data, $videoCodec);
        $gop = $this->sanitizeGop($data, $videoCodec);
        $rateControl = $this->sanitizeVideoRateControl($data);
        $srtSecurity = $this->sanitizeSrtSecurity($data);

        $stmt = $this->db->prepare("
            INSERT INTO srt_targets (pipeline_id, name, srt_mode, srt_port, srt_target_host, srt_latency, srt_passphrase, srt_pbkeylen, srt_streamid, video_codec, bitrate, video_rate_control, video_keyframe_interval, video_scale_enabled, width, height, audio_codec, audio_output_channels, audio_track_mode, ts_video_pid, ts_audio_pid, ts_pmt_pid, ts_program, video_chroma, video_bitdepth, video_framing, video_framerate, video_scenecut, video_open_gop, audio_bitrate, video_speed_preset, output_protocol, rtmp_url, rtmp_key, rtmp_deinterlace, overlay_enabled, overlay_text, overlay_pos, overlay_size, overlay_color, overlay_bg)
            VALUES (:pipeline_id, :name, :srt_mode, :srt_port, :srt_target_host, :srt_latency, :srt_passphrase, :srt_pbkeylen, :srt_streamid, :video_codec, :bitrate, :video_rate_control, :video_keyframe_interval, :video_scale_enabled, :width, :height, :audio_codec, :audio_output_channels, :audio_track_mode, :ts_video_pid, :ts_audio_pid, :ts_pmt_pid, :ts_program, :video_chroma, :video_bitdepth, :video_framing, :video_framerate, :video_scenecut, :video_open_gop, :audio_bitrate, :video_speed_preset, :output_protocol, :rtmp_url, :rtmp_key, :rtmp_deinterlace, :overlay_enabled, :overlay_text, :overlay_pos, :overlay_size, :overlay_color, :overlay_bg)
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
            ':video_chroma' => $vf['chroma'],
            ':video_bitdepth' => $vf['depth'],
            ':video_framing' => $gop['framing'],
            ':video_framerate' => $gop['framerate'],
            ':video_scenecut' => $gop['scenecut'],
            ':video_open_gop' => $gop['open'],
            ':audio_bitrate' => $audio['bitrate'],
            ':video_speed_preset' => $this->sanitizeSpeedPreset($data),
            ':output_protocol' => $out['protocol'],
            ':rtmp_url' => $out['url'],
            ':rtmp_key' => $out['key'],
            ':rtmp_deinterlace' => $out['deinterlace'],
            ':overlay_enabled' => $ov['enabled'],
            ':overlay_text' => $ov['text'],
            ':overlay_pos' => $ov['pos'],
            ':overlay_size' => $ov['size'],
            ':overlay_color' => $ov['color'],
            ':overlay_bg' => $ov['bg'],
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
        $data = $this->applyRtmpLimits($data);
        $ov = $this->sanitizeOverlay($data);
        $out = $this->sanitizeOutput($data, $target['rtmp_key'] ?? null);
        $audio = $this->sanitizeAudio($data);
        $ts = $this->sanitizeTsIds($data, $audio);
        $videoCodec = $this->sanitizeVideoCodec($data);
        $vf = $this->sanitizeVideoFormat($data, $videoCodec);
        $gop = $this->sanitizeGop($data, $videoCodec);
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
                video_chroma = :video_chroma,
                video_bitdepth = :video_bitdepth,
                video_framing = :video_framing,
                video_framerate = :video_framerate,
                video_scenecut = :video_scenecut,
                video_open_gop = :video_open_gop,
                audio_bitrate = :audio_bitrate,
                video_speed_preset = :video_speed_preset,
                output_protocol = :output_protocol,
                rtmp_url = :rtmp_url,
                rtmp_key = :rtmp_key,
                rtmp_deinterlace = :rtmp_deinterlace,
                overlay_enabled = :overlay_enabled,
                overlay_text = :overlay_text,
                overlay_pos = :overlay_pos,
                overlay_size = :overlay_size,
                overlay_color = :overlay_color,
                overlay_bg = :overlay_bg
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
            ':video_chroma' => $vf['chroma'],
            ':video_bitdepth' => $vf['depth'],
            ':video_framing' => $gop['framing'],
            ':video_framerate' => $gop['framerate'],
            ':video_scenecut' => $gop['scenecut'],
            ':video_open_gop' => $gop['open'],
            ':audio_bitrate' => $audio['bitrate'],
            ':video_speed_preset' => $this->sanitizeSpeedPreset($data),
            ':output_protocol' => $out['protocol'],
            ':rtmp_url' => $out['url'],
            ':rtmp_key' => $out['key'],
            ':rtmp_deinterlace' => $out['deinterlace'],
            ':overlay_enabled' => $ov['enabled'],
            ':overlay_text' => $ov['text'],
            ':overlay_pos' => $ov['pos'],
            ':overlay_size' => $ov['size'],
            ':overlay_color' => $ov['color'],
            ':overlay_bg' => $ov['bg'],
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
        $pid = $this->relaunchIfShmRace($pid, $fullCmd, $logFile);

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

    /**
     * Das Ziel verbindet sich per shmsrc mit dem Verteiler. Direkt nach dessen Start kann das scheitern ("Internal data
     * stream error" nach wenigen Millisekunden), weil noch keine Bilder da sind. Dann bis zu zweimal neu starten.
     */
    private function relaunchIfShmRace(int $pid, string $fullCmd, string $logFile): int
    {
        for ($try = 0; $try < 2; $try++) {
            usleep(1500000);
            if ($this->isProcRunning($pid)) {
                return $pid;
            }
            if (strpos((string)@file_get_contents($logFile), 'shmsrc') === false) {
                return $pid;
            }
            $pid = (int)shell_exec($fullCmd);
        }
        return $pid;
    }

    public function start(int $id): void
    {
        $p = $this->getPipelineOrFail($id);

        if ($p['status'] === 'running' && $this->isProcRunning($p['pid'] !== null ? (int)$p['pid'] : null)) {
            throw new RuntimeException("Pipeline läuft bereits");
        }

        // Ein Testsignal auf dieser Karte beenden, bevor die Pipeline die Karte braucht
        if (($p['pipeline_direction'] ?? '') === 'srt_to_sdi') {
            TestSignal::stop((int)$p['decklink_device_number']);
        }

        // Einblendung am SDI-Ausgang: Steuerdatei mit den gespeicherten Werten, der Empfaenger liest sie beim Start und danach laufend
        if ($this->isReceiverRow($p) && (int)($p['test_pattern_enabled'] ?? 0) !== 1 && $this->overlayConfigured($p)) {
            $this->writeOverlayFile($p);
        }

        // Bauchbinde: Grafik rendern, Steuerdatei schreiben (beim Start immer ausgeblendet)
        if ($this->isReceiverRow($p) && (int)($p['test_pattern_enabled'] ?? 0) !== 1 && LowerThird::configured(LowerThird::sanitize($p))) {
            $this->writeLowerThirdFile($p, 'none');
        }

        // RTMP-Annahme: der Hilfsserver muss laufen (und den Pfad dieses Empfaengers kennen), bevor der Empfaenger abholt
        if ($this->isRtmpIn($p) && (int)($p['test_pattern_enabled'] ?? 0) !== 1) {
            IngestManager::sync($this->db, [(string)$p['rtmp_key']]);
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
        if ($this->isMultiTargetEligible($p)) {
            $pid = $this->relaunchIfShmRace($pid, $fullCmd, $logFile);
        }

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
        if ($this->isRtmpIn($p)) {
            IngestManager::sync($this->db); // Pfad entfernen, beim letzten Empfaenger den Hilfsserver beenden
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

        $p = $this->applyRtmpLimits($p);
        $deint = $this->deinterlaceElement($p);
        $width = (int)$p['width'];
        $height = (int)$p['height'];
        $framerate = (int)$p['framerate'];
        $bitrate = (int)$p['bitrate'];
        $port = (int)$p['srt_port'];
        $latency = (int)$p['srt_latency'];
        $mode = $p['srt_mode'] ?? 'listener';
        $sourceType = $p['source_type'] ?? 'videotestsrc';
        $videoCodec = $this->sanitizeVideoCodec($p);
        $videoFormat = $this->videoFormatForCodec($videoCodec, $this->sanitizeVideoFormat($p, $videoCodec));
        $keyframeInterval = $this->sanitizeKeyframeInterval($p, $framerate);
        $rateControl = $this->sanitizeVideoRateControl($p);
        $speedPreset = $this->sanitizeSpeedPreset($p);
        $videoEncoder = $this->buildVideoEncoder($videoCodec, $bitrate, $keyframeInterval, $rateControl, $speedPreset, $this->sanitizeGop($p, $videoCodec));
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

        if ($mode === 'caller' && !$this->isRtmp($p)) {
            $host = $p['srt_target_host'] ?? '';
            if (empty($host)) {
                throw new RuntimeException('Caller-Modus benoetigt eine Ziel-Adresse (srt_target_host)');
            }
            $srtUri = "srt://{$host}:{$port}?mode=caller";
        } else {
            $srtUri = "srt://:{$port}?mode=listener";
        }

        // TZ nur für die Pipeline selbst gesetzt (fuer clockoverlay) - aendert nicht die Systemzeitzone des Servers
        $prefix = "env TZ=Europe/Berlin GST_DEBUG=" . ($this->isRtmp($p) ? 'rtmp2sink:3' : 'srtsink:5') . " GST_DEBUG_NO_COLOR=1 gst-launch-1.0 -v ";
        $tap = $this->tapBranch(self::tapPort('pipeline', (int)$p['id']));
        $mux = $this->muxSetupFor($p);

        $overlayFile = $this->buildOverlayElement($p, (int)$height);
        $overlayTest = $overlayFile;
        if ($sourceType === 'decklink') {
            $decklink = $this->sanitizeDecklink([
                'decklink_device_number' => $p['decklink_device_number'] ?? 0,
                'decklink_connection' => $p['decklink_connection'] ?? 'auto',
                'decklink_profile' => $p['decklink_profile'] ?? 'default',
                'decklink_audio_channels' => $p['decklink_audio_channels'] ?? 2,
            ]);
            $this->ports->assertRole($decklink['device_number'], 'input');
            $decklinkMode = $this->ports->getMode($decklink['device_number']);
            $overlay = $this->buildOverlayElement($p, $this->modeFrameHeight($decklinkMode));

            // decklinkvideosrc taggt HD-SDI-Signale faelschlich als colorimetry=bt2020 (bestaetigt per
            // Monitor-Test 2026-09-28 - siehe [[project-noicecaster-decklink-hardware]]), obwohl alle
            // hier unterstuetzten Modi (720p/1080i/1080p) immer BT.709 sind. capssetter ueberschreibt
            // nur das Caps-Feld (reines Relabeling, keine echte Farbraum-Umrechnung) - ein einfacher
            // capsfilter scheitert hier an der Negotiation, weil decklinkvideosrc bt2020 als fixen Wert
            // meldet.
            $videoBranch = "decklinkvideosrc device-number={$decklink['device_number']} " .
                "connection={$decklink['connection']} mode={$decklinkMode} profile={$decklink['profile']} ! " .
                "capssetter caps=\"video/x-raw,colorimetry=(string)bt709\" join=true ! " .
                "{$deint}{$overlay}videoconvert ! {$previewElement}{$clockOverlayElement}{$scaleElement}{$formatCaps} ! " .
                "{$videoEncoder} ! " .
                $this->buildSenderTail($p, $mux, $srtUri, $latency, $srtSecurityProps, $tap, true);

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

            $videoBranch = "demux.video_0 ! queue ! decodebin ! videoconvert ! {$clockOverlayElement}{$overlayFile}{$scaleElement}{$formatCaps} ! " .
                "{$videoEncoder} ! " .
                $this->buildSenderTail($p, $mux, $srtUri, $latency, $srtSecurityProps, $tap, false);

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
            "videoconvert ! {$clockOverlayElement}{$overlayTest}" .
            "{$videoEncoder} ! " .
            $this->buildSenderTail($p, $mux, $srtUri, $latency, $srtSecurityProps, $tap, true);

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
        if ($this->isRtmp($p) || $this->isRtmpIn($p)) {
            return $this->buildRtmpReceiveCommand($p);
        }
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
            ' --tap-port ' . self::tapPort('receiver', (int)$p['id']) .
            $this->receiverOverlayArgs($p, $outputMode) .
            $this->receiverLowerThirdArgs($p, $outputMode);
        if ($srtSecurity['passphrase'] !== null) {
            $args .= ' --passphrase ' . escapeshellarg($srtSecurity['passphrase']) . ' --pbkeylen ' . (int)$srtSecurity['pbkeylen'];
        }
        if ($srtSecurity['streamid'] !== null) {
            $args .= ' --streamid ' . escapeshellarg($srtSecurity['streamid']);
        }

        return "env GST_DEBUG=srtsrc:5 GST_DEBUG_NO_COLOR=1 python3 {$helper}{$args}";
    }

    /**
     * RTMP-Empfang: derselbe Python-Empfaenger wie bei SRT, aber mit --rtmp-uri (rtmp2src holt den Strom von einem Server ab,
     * flvdemux, danach der gleiche Ausgabeweg). Kein Stromabgriff, kein SRT-Latenz-/Verschluesselungsteil.
     */
    private function buildRtmpReceiveCommand(array $p): string
    {
        $location = $this->isRtmpIn($p)
            ? IngestManager::localUrl((string)$this->sanitizeOutput($p)['key'])
            : $this->rtmpLocation($p);
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
        $helper = escapeshellarg(__DIR__ . '/../scripts/srt_to_sdi_receiver.py');
        $args = ' --rtmp-uri ' . escapeshellarg($location) .
            ' --device ' . (int)$decklink['device_number'] .
            ' --mode ' . escapeshellarg($outputMode) .
            ' --profile ' . escapeshellarg($decklink['profile']) .
            ' --audio-channels ' . $this->deviceAudioChannels((int)$decklink['audio_channels']) .
            ' --audio-used ' . max(2, (int)$decklink['audio_channels']) .
            ' --stats-file ' . escapeshellarg(self::receiverStatsPath((int)$p['id'])) .
            $this->receiverOverlayArgs($p, $outputMode) .
            $this->receiverLowerThirdArgs($p, $outputMode);
        return "env GST_DEBUG=rtmp2src:3 GST_DEBUG_NO_COLOR=1 python3 {$helper}{$args}";
    }

    private function updateStatus(int $id, string $status, ?int $pid): void
    {
        $stmt = $this->db->prepare("UPDATE pipelines SET status = :status, pid = :pid WHERE id = :id");
        $stmt->execute([':status' => $status, ':pid' => $pid, ':id' => $id]);
    }


    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare("SELECT status, rtmp_key FROM pipelines WHERE id = :id");
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
        $data = $this->applyRtmpLimits($data);
        $ov = $this->sanitizeOverlay($data);
        $lt = LowerThird::sanitize($data);
        $out = $this->sanitizeOutput($data, $current['rtmp_key'] ?? null);
        $audio = $this->sanitizeAudio($data);
        $ts = $this->sanitizeTsIds($data, $audio);
        $videoCodec = $this->sanitizeVideoCodec($data);
        $vf = $this->sanitizeVideoFormat($data, $videoCodec);
        $gop = $this->sanitizeGop($data, $videoCodec);
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
                video_chroma = :video_chroma,
                video_bitdepth = :video_bitdepth,
                video_framing = :video_framing,
                video_framerate = :video_framerate,
                video_scenecut = :video_scenecut,
                video_open_gop = :video_open_gop,
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
                video_speed_preset = :video_speed_preset,
                output_protocol = :output_protocol,
                rtmp_url = :rtmp_url,
                rtmp_key = :rtmp_key,
                rtmp_deinterlace = :rtmp_deinterlace,
                overlay_enabled = :overlay_enabled,
                overlay_text = :overlay_text,
                overlay_pos = :overlay_pos,
                overlay_size = :overlay_size,
                overlay_color = :overlay_color,
                overlay_bg = :overlay_bg,
                lt_template = :lt_template,
                lt_title = :lt_title,
                lt_main = :lt_main,
                lt_sub = :lt_sub,
                lt_color = :lt_color,
                lt_logo = :lt_logo,
                lt_anim = :lt_anim,
                lt_dur = :lt_dur,
                lt_hold = :lt_hold,
                lt_scale = :lt_scale,
                lt_pos = :lt_pos
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
            ':video_chroma' => $vf['chroma'],
            ':video_bitdepth' => $vf['depth'],
            ':video_framing' => $gop['framing'],
            ':video_framerate' => $gop['framerate'],
            ':video_scenecut' => $gop['scenecut'],
            ':video_open_gop' => $gop['open'],
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
            ':output_protocol' => $out['protocol'],
            ':rtmp_url' => $out['url'],
            ':rtmp_key' => $out['key'],
            ':rtmp_deinterlace' => $out['deinterlace'],
            ':overlay_enabled' => $ov['enabled'],
            ':overlay_text' => $ov['text'],
            ':overlay_pos' => $ov['pos'],
            ':overlay_size' => $ov['size'],
            ':overlay_color' => $ov['color'],
            ':overlay_bg' => $ov['bg'],
            ':lt_template' => $lt['lt_template'],
            ':lt_title' => $lt['lt_title'],
            ':lt_main' => $lt['lt_main'],
            ':lt_sub' => $lt['lt_sub'],
            ':lt_color' => $lt['lt_color'],
            ':lt_logo' => $lt['lt_logo'],
            ':lt_anim' => $lt['lt_anim'],
            ':lt_dur' => $lt['lt_dur'],
            ':lt_hold' => $lt['lt_hold'],
            ':lt_scale' => $lt['lt_scale'],
            ':lt_pos' => $lt['lt_pos'],
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

    /** Testsignal (16 Toene) auf einem Ausgang ein- oder ausschalten. */
    public function setTestSignal(int $device, bool $on, string $systemLabel): void
    {
        if ($device < 0 || $device > 3) {
            throw new RuntimeException('Ungültiger Anschluss');
        }
        if (!$on) {
            TestSignal::stop($device);
            return;
        }
        $this->ports->assertRole($device, 'output');
        foreach ($this->list() as $p) {
            if (($p['pipeline_direction'] ?? '') === 'srt_to_sdi' && (int)$p['decklink_device_number'] === $device && $p['status'] === 'running') {
                throw new RuntimeException('Auf diesem Anschluss läuft eine Pipeline. Erst stoppen, dann das Testsignal einschalten.');
            }
        }
        $mode = $this->ports->getMode($device);
        if ($mode === 'auto') {
            $mode = DecklinkPortManager::DEFAULT_OUTPUT_MODE;
        }
        TestSignal::start($device, $mode, strtoupper($systemLabel) . ', ANSCHLUSS ' . ($device + 1) . ', 16 TOENE');
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
