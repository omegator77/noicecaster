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
    private const AUDIO_OUTPUT_CHANNELS = [1, 2, 6];
    private const VIDEO_CODECS = ['h264', 'h265', 'mpeg2'];
    private const VIDEO_RATE_CONTROLS = ['cbr', 'vbr'];
    private const SRT_PBKEYLEN = [0, 16, 24, 32];
    private const AUDIO_TEST_WAVES = ['sine', 'silence'];

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
            if ($p['status'] === 'running' && $p['pid'] && !$this->isRunning((int)$p['pid'])) {
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
                throw new RuntimeException("Für Anschluss {$decklink['device_number']} existiert in dieser Richtung bereits eine Pipeline.");
            }
        }
    }

    public function create(array $data): int
    {
        $decklink = $this->sanitizeDecklink($data);
        $direction = $this->sanitizeDirection($data);
        $audio = $this->sanitizeAudio($data);
        $videoCodec = $this->sanitizeVideoCodec($data);
        $rateControl = $this->sanitizeVideoRateControl($data);
        $keyframeInterval = (int)($data['video_keyframe_interval'] ?? 0);
        $scaleEnabled = (int)($data['video_scale_enabled'] ?? 0) === 1 ? 1 : 0;
        $srtSecurity = $this->sanitizeSrtSecurity($data);
        $this->validatePortUsage($data, $decklink, $direction);
        $stmt = $this->db->prepare("
            INSERT INTO pipelines (name, source_type, source_pattern, width, height, framerate, bitrate, srt_port, srt_latency, srt_mode, srt_target_host, source_file, decklink_device_number, decklink_connection, decklink_profile, decklink_audio_channels, pipeline_direction, audio_codec, audio_output_channels, audio_bitrate, video_codec, video_rate_control, video_keyframe_interval, video_scale_enabled, srt_passphrase, srt_pbkeylen, srt_streamid, clock_overlay_enabled, audio_test_wave)
            VALUES (:name, :source_type, :source_pattern, :width, :height, :framerate, :bitrate, :srt_port, :srt_latency, :srt_mode, :srt_target_host, :source_file, :decklink_device_number, :decklink_connection, :decklink_profile, :decklink_audio_channels, :pipeline_direction, :audio_codec, :audio_output_channels, :audio_bitrate, :video_codec, :video_rate_control, :video_keyframe_interval, :video_scale_enabled, :srt_passphrase, :srt_pbkeylen, :srt_streamid, :clock_overlay_enabled, :audio_test_wave)
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

        $profile = $data['decklink_profile'] ?? 'default';
        if (!in_array($profile, self::DECKLINK_PROFILES, true)) {
            $profile = 'default';
        }

        $audioChannels = (int)($data['decklink_audio_channels'] ?? 2);
        if (!in_array($audioChannels, self::DECKLINK_AUDIO_CHANNELS, true)) {
            $audioChannels = 2;
        }

        return [
            'device_number' => $deviceNumber,
            'connection' => $connection,
            'profile' => $profile,
            'audio_channels' => $audioChannels,
        ];
    }

    private function sanitizeAudio(array $data): array
    {
        $codec = $data['audio_codec'] ?? 'aac';
        if (!in_array($codec, self::AUDIO_CODECS, true)) {
            $codec = 'aac';
        }

        $channels = (int)($data['audio_output_channels'] ?? 2);
        if (!in_array($channels, self::AUDIO_OUTPUT_CHANNELS, true)) {
            $channels = 2;
        }

        $bitrate = (int)($data['audio_bitrate'] ?? 192);
        if ($bitrate < 32 || $bitrate > 448) {
            $bitrate = 192;
        }

        return [
            'codec' => $codec,
            'channels' => $channels,
            'bitrate' => $bitrate,
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

    private function sanitizeAudioTestWave(array $data): string
    {
        $wave = $data['audio_test_wave'] ?? 'sine';
        return in_array($wave, self::AUDIO_TEST_WAVES, true) ? $wave : 'sine';
    }

    private function buildClockOverlayElement(array $data): string
    {
        $enabled = (int)($data['clock_overlay_enabled'] ?? 0) === 1;
        // Zeigt die Wanduhrzeit ins Bild ein - zum visuellen Vergleich der Latenz beim Empfänger
        return $enabled ? "clockoverlay time-format=\"%H:%M:%S\" halignment=right valignment=bottom shaded-background=true ! " : "";
    }

    private function buildVideoEncoder(string $codec, int $bitrate, int $keyframeInterval, string $rateControl): string
    {
        switch ($codec) {
            case 'h265':
                $chain = "x265enc tune=zerolatency bitrate={$bitrate} key-int-max={$keyframeInterval}";
                if ($rateControl === 'cbr') {
                    // x265 hat keine eigene VBV-Property, daher ueber die x265-Optionszeile erzwungen
                    $chain .= " option-string=\"vbv-bufsize={$bitrate}:vbv-maxrate={$bitrate}\"";
                }
                return $chain . " ! h265parse config-interval=-1";
            case 'mpeg2':
                $bps = $bitrate * 1000;
                $chain = "avenc_mpeg2video bitrate={$bps} gop-size={$keyframeInterval}";
                if ($rateControl === 'cbr') {
                    $chain .= " bufsize={$bps} maxrate={$bps} minrate={$bps}";
                }
                return $chain . " ! mpegvideoparse";
            default:
                $chain = "x264enc tune=zerolatency speed-preset=veryfast bitrate={$bitrate} key-int-max={$keyframeInterval}";
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

    private function buildAudioEncoderChain(array $audio): string
    {
        $channels = $audio['channels'];
        $bps = $audio['bitrate'] * 1000;

        if ($audio['codec'] === 'pcm') {
            // SMPTE 302M: unkomprimiertes PCM, kein Bitrate-Parameter, feste Samplerate 48kHz
            return "audio/x-raw,channels={$channels},rate=48000 ! avenc_s302m strict=experimental ! mux.";
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

        return "audio/x-raw,channels={$channels} ! {$encoder} ! mux.";
    }

    public function start(int $id): void
    {
        $stmt = $this->db->prepare("SELECT * FROM pipelines WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$p) {
            throw new RuntimeException("Pipeline $id nicht gefunden");
        }
        if ($p['status'] === 'running' && $this->isRunning((int)$p['pid'])) {
            throw new RuntimeException("Pipeline läuft bereits");
        }

        $cmd = $this->buildCommand($p);

        // Im Hintergrund starten, Ausgabe in Logdatei, PID erfassen
        $logFile = __DIR__ . "/../data/pipeline_{$id}.log";
        $fullCmd = "nohup {$cmd} > " . escapeshellarg($logFile) . " 2>&1 & echo $!";

        $pid = (int)shell_exec($fullCmd);

        $stmt = $this->db->prepare("UPDATE pipelines SET status = 'running', pid = :pid, started_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':pid' => $pid, ':id' => $id]);
    }

    public function stop(int $id): void
    {
        $stmt = $this->db->prepare("SELECT pid FROM pipelines WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $pid = $stmt->fetchColumn();

        if ($pid && $this->isRunning((int)$pid)) {
            // SIGTERM an die Prozessgruppe, damit gst-launch sauber beendet
            posix_kill((int)$pid, SIGTERM);
            usleep(500000); // 0.5s warten
            if ($this->isRunning((int)$pid)) {
                posix_kill((int)$pid, SIGKILL);
            }
        }

        $stmt = $this->db->prepare("UPDATE pipelines SET status = 'stopped', pid = NULL, started_at = NULL WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    public function delete(int $id): void
    {
        // Erst stoppen, falls noch aktiv
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
        $videoEncoder = $this->buildVideoEncoder($videoCodec, $bitrate, $keyframeInterval, $rateControl);
        $srtSecurity = $this->sanitizeSrtSecurity($p);
        $srtSecurityProps = $this->buildSrtSecurityProps($srtSecurity);
        $clockOverlayElement = $this->buildClockOverlayElement($p);
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

        if ($sourceType === 'decklink') {
            $decklink = $this->sanitizeDecklink([
                'decklink_device_number' => $p['decklink_device_number'] ?? 0,
                'decklink_connection' => $p['decklink_connection'] ?? 'auto',
                'decklink_profile' => $p['decklink_profile'] ?? 'default',
                'decklink_audio_channels' => $p['decklink_audio_channels'] ?? 2,
            ]);
            $this->ports->assertRole($decklink['device_number'], 'input');
            $decklinkMode = $this->ports->getMode($decklink['device_number']);

            $videoBranch = "decklinkvideosrc device-number={$decklink['device_number']} " .
                "connection={$decklink['connection']} mode={$decklinkMode} profile={$decklink['profile']} ! " .
                "videoconvert ! {$clockOverlayElement}{$scaleElement}{$formatCaps} ! " .
                "{$videoEncoder} ! " .
                "mpegtsmux name=mux ! " .
                "srtsink uri=\"{$srtUri}\" latency={$latency}{$srtSecurityProps} ";

            $audio = $this->sanitizeAudio($p);
            $audioBranch = "decklinkaudiosrc device-number={$decklink['device_number']} channels={$decklink['audio_channels']} ! " .
                "audioconvert ! audioresample ! " . $this->buildAudioEncoderChain($audio);

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
                "mpegtsmux name=mux ! " .
                "srtsink uri=\"{$srtUri}\" latency={$latency}{$srtSecurityProps} ";

            $audio = $this->sanitizeAudio($p);
            $audioBranch = "demux.audio_0 ! queue ! decodebin ! audioconvert ! audioresample ! " .
                $this->buildAudioEncoderChain($audio);

            return $prefix . "filesrc location={$fileArg} ! qtdemux name=demux " . $videoBranch . $audioBranch;
        }

        // Standard: synthetisches Testmuster
        $pattern = escapeshellarg($p['source_pattern']);
        $source = "videotestsrc pattern={$pattern} is-live=true";

        $videoBranch = "{$source} ! " .
            "video/x-raw,format={$videoFormat},width={$width},height={$height},framerate={$framerate}/1 ! " .
            "videoconvert ! {$clockOverlayElement}" .
            "{$videoEncoder} ! " .
            "mpegtsmux name=mux ! " .
            "srtsink uri=\"{$srtUri}\" latency={$latency}{$srtSecurityProps} ";

        $audio = $this->sanitizeAudio($p);
        $audioTestWave = $this->sanitizeAudioTestWave($p);
        $audioBranch = "audiotestsrc wave={$audioTestWave} freq=1000 is-live=true ! " . $this->buildAudioEncoderChain($audio);

        return $prefix . $videoBranch . $audioBranch;
    }

    private function buildReceiveCommand(array $p): string
    {
        $port = (int)$p['srt_port'];
        $latency = (int)$p['srt_latency'];
        $mode = $p['srt_mode'] ?? 'listener';
        $srtSecurity = $this->sanitizeSrtSecurity($p);
        $srtSecurityProps = $this->buildSrtSecurityProps($srtSecurity);

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
        ]);
        $this->ports->assertRole($decklink['device_number'], 'output');
        $outputMode = $this->ports->getMode($decklink['device_number']);
        if ($outputMode === 'auto') {
            // DecklinkPortManager verhindert das beim Speichern bereits, das hier ist nur ein
            // Sicherheitsnetz gegen ältere Datensätze - decklinkvideosink kann nicht im Auto-Modus ausgeben
            $outputMode = DecklinkPortManager::DEFAULT_OUTPUT_MODE;
        }

        $prefix = "env GST_DEBUG=srtsrc:5 GST_DEBUG_NO_COLOR=1 gst-launch-1.0 -v ";

        // decodebin statt festem Codec, da der eingehende SRT-Stream H.264, H.265 oder MPEG-2 enthalten kann
        $videoBranch = "demux. ! queue ! decodebin ! videoconvert ! videoscale ! videorate ! " .
            "decklinkvideosink device-number={$decklink['device_number']} mode={$outputMode} profile={$decklink['profile']}";

        // decodebin statt festem Codec, da der eingehende SRT-Stream AAC, MP2 oder AC3 enthalten kann
        $audioBranch = " demux. ! queue ! decodebin ! audioconvert ! audioresample ! " .
            "decklinkaudiosink device-number={$decklink['device_number']}";

        // keep-listening=true: bei Trennung des Senders auf einen Reconnect warten statt die Pipeline
        // per EOS zu beenden (Default ist false - waere fuer ein unbeaufsichtigtes Gateway ungeeignet)
        return $prefix . "srtsrc uri=\"{$srtUri}\" latency={$latency} keep-listening=true{$srtSecurityProps} ! tsdemux name=demux " . $videoBranch . $audioBranch;
    }

    private function updateStatus(int $id, string $status, ?int $pid): void
    {
        $stmt = $this->db->prepare("UPDATE pipelines SET status = :status, pid = :pid WHERE id = :id");
        $stmt->execute([':status' => $status, ':pid' => $pid, ':id' => $id]);
    }

    private function isRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        return posix_kill($pid, 0);
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
        $audio = $this->sanitizeAudio($data);
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
                audio_bitrate = :audio_bitrate,
                video_codec = :video_codec,
                video_rate_control = :video_rate_control,
                video_keyframe_interval = :video_keyframe_interval,
                video_scale_enabled = :video_scale_enabled,
                srt_passphrase = :srt_passphrase,
                srt_pbkeylen = :srt_pbkeylen,
                srt_streamid = :srt_streamid,
                clock_overlay_enabled = :clock_overlay_enabled,
                audio_test_wave = :audio_test_wave
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
        ]);
    }


    public function getStats(int $id): array
    {
        $stmt = $this->db->prepare("SELECT * FROM pipelines WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$p) {
            throw new RuntimeException("Pipeline $id nicht gefunden");
        }

        $result = [
            'uptime_seconds' => null,
            'cpu_percent' => null,
            'cpu_cores' => $this->getCpuCoreCount(),
            'memory_kb' => null,
            'client_connected' => false,
            'log_tail' => '',
        ];

        if ($p['status'] === 'running' && $p['pid'] && $this->isRunning((int)$p['pid'])) {
            if ($p['started_at']) {
                $started = new DateTime($p['started_at'], new DateTimeZone('UTC'));
                $now = new DateTime('now', new DateTimeZone('UTC'));
                $result['uptime_seconds'] = $now->getTimestamp() - $started->getTimestamp();
            }

            $psOutput = shell_exec("ps -o %cpu,rss --no-headers -p " . (int)$p['pid']);
            if ($psOutput) {
                $parts = preg_split('/\\s+/', trim($psOutput));
                if (count($parts) === 2) {
                    $result['cpu_percent'] = (float)$parts[0];
                    $result['memory_kb'] = (int)$parts[1];
                }
            }
        }

        $logFile = __DIR__ . "/../data/pipeline_{$id}.log";
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

    public function probeDecklinkSignal(int $deviceNumber, string $connection): array
    {
        if ($deviceNumber < 0 || $deviceNumber > 3) {
            $deviceNumber = 0;
        }
        if (!in_array($connection, self::DECKLINK_CONNECTIONS, true)) {
            $connection = 'auto';
        }

        $script = escapeshellarg(__DIR__ . '/../scripts/probe_decklink.py');
        $cmd = "timeout 6 python3 {$script} " . (int)$deviceNumber . " " . escapeshellarg($connection) . " 4 2>&1";
        $output = shell_exec($cmd);

        $json = json_decode(trim((string)$output), true);
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
