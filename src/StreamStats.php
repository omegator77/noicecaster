<?php

/**
 * Statistik-Uebersicht: Kennzahlen der laufenden Sender (aus dem Transportstrom-Abgriff
 * auf 127.0.0.1, ausgewertet von scripts/ts_probe.py), Soll-Werte aus der Konfiguration
 * und Zaehler der Netzwerkkarten.
 */
class StreamStats
{
    private const PROBE_SECONDS = 2.5;

    private static function dataDir(): string
    {
        return __DIR__ . '/../data';
    }

    public static function collect(PipelineManager $manager): array
    {
        // Nur eine Messung gleichzeitig: parallele Aufrufe (mehrere Browser) bekommen das Ergebnis der laufenden
        $lock = @fopen(self::dataDir() . '/streamstats.lock', 'c');
        $locked = $lock && flock($lock, LOCK_EX | LOCK_NB);
        if (!$locked) {
            if ($lock) {
                fclose($lock);
            }
            $cached = self::readCache();
            if ($cached) {
                $cached['cached'] = true;
                $cached['nics'] = NetworkManager::counters();
                return $cached;
            }
            return ['items' => [], 'nics' => NetworkManager::counters(), 'time' => microtime(true), 'cached' => true];
        }

        try {
            $previous = self::readCache();
            $items = self::buildItems($manager);
            self::runProbes($items, $previous['items'] ?? []);
            self::attachReceiverStats($items);
            $result = ['items' => $items, 'time' => microtime(true), 'cached' => false];
            @file_put_contents(self::dataDir() . '/streamstats_cache.json', json_encode($result));
            $result['nics'] = NetworkManager::counters();
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function readCache(): ?array
    {
        $raw = @file_get_contents(self::dataDir() . '/streamstats_cache.json');
        $data = $raw ? json_decode($raw, true) : null;
        return is_array($data) ? $data : null;
    }

    /** Laufende Pipelines und Zusatzziele samt Soll-Konfiguration. */
    private static function buildItems(PipelineManager $manager): array
    {
        $items = [];
        foreach ($manager->list() as $p) {
            if (($p['status'] ?? '') !== 'running') {
                continue;
            }
            $sender = ($p['pipeline_direction'] ?? 'sdi_to_srt') === 'sdi_to_srt';
            $testPattern = !$sender && !empty($p['test_pattern_enabled']);
            $item = [
                'key' => 'p' . $p['id'],
                'name' => (string)$p['name'] . ($sender ? ' / ' . ($p['target_name'] ?? 'Ziel 1') : ''),
                'direction' => $sender ? 'sender' : 'receiver',
                'port' => ($testPattern || self::isRtmp($p) || self::isRtmpIn($p)) ? null : PipelineManager::tapPort($sender ? 'pipeline' : 'receiver', (int)$p['id']),
                'config' => self::config($p),
            ];
            if (self::isRtmp($p)) {
                $item['note'] = $sender ? self::RTMP_NOTE : self::RTMP_RX_NOTE;
            }
            if (self::isRtmpIn($p)) {
                $item['note'] = self::RTMP_IN_NOTE;
            }
            if ($testPattern) {
                $item['note'] = 'Der Ausgang gibt ein Testbild aus (kein SRT-Empfang), daher gibt es keine Stromdaten.';
            }
            $items[] = $item;
            if (!$sender) {
                continue;
            }
            foreach ($manager->listTargets((int)$p['id']) as $t) {
                if (($t['status'] ?? '') !== 'running') {
                    continue;
                }
                $targetItem = [
                    'key' => 't' . $t['id'],
                    'name' => (string)$p['name'] . ' / ' . ($t['name'] ?? 'Ziel'),
                    'direction' => 'sender',
                    'port' => self::isRtmp($t) ? null : PipelineManager::tapPort('target', (int)$t['id']),
                    'config' => self::config($t),
                ];
                if (self::isRtmp($t)) {
                    $targetItem['note'] = self::RTMP_NOTE;
                }
                $items[] = $targetItem;
            }
        }
        return $items;
    }

    private const RTMP_IN_NOTE = 'RTMP-Annahme: Ein Encoder sendet per RTMP an dieses Gateway, der Empfaenger holt den Strom lokal ab. Eine Stromanalyse gibt es hier nicht, angezeigt werden die Verbindungs- und Ausgabe-Zaehler.';

    private const RTMP_RX_NOTE = 'RTMP-Empfang: Der Strom wird von einem RTMP-Server abgerufen (FLV). Eine Stromanalyse gibt es hier nicht, angezeigt werden die Verbindungs- und Ausgabe-Zaehler.';

    private const RTMP_NOTE = 'RTMP-Ziel: Der Strom wird als FLV gesendet, eine Stromanalyse (Bitrate, GOP, Zeitstempel) gibt es hier nicht. Ob die Gegenstelle ihn annimmt, zeigt deren Oberflaeche.';

    private static function isRtmp(array $row): bool
    {
        return ($row['output_protocol'] ?? 'srt') === 'rtmp';
    }

    private static function isRtmpIn(array $row): bool
    {
        return ($row['output_protocol'] ?? 'srt') === 'rtmp_in';
    }

    private static function config(array $row): array
    {
        return [
            'source_type' => $row['source_type'] ?? 'decklink',
            // Beim SDI-Eingang gilt die Bildgroesse der Buchse; width/height zaehlen nur bei Skalierung
            'scaled' => !empty($row['video_scale_enabled']),
            'sdi_out_channels' => isset($row['decklink_audio_channels']) ? (int)$row['decklink_audio_channels'] : null,
            'video_kbit' => isset($row['bitrate']) ? (int)$row['bitrate'] : null,
            'width' => isset($row['width']) ? (int)$row['width'] : null,
            'height' => isset($row['height']) ? (int)$row['height'] : null,
            'framerate' => isset($row['framerate']) ? (int)$row['framerate'] : null,
            'video_codec' => $row['video_codec'] ?? null,
            'speed_preset' => $row['video_speed_preset'] ?? null,
            'audio_codec' => $row['audio_codec'] ?? null,
            'audio_kbit' => isset($row['audio_bitrate']) ? (int)$row['audio_bitrate'] : null,
            'audio_channels' => isset($row['audio_output_channels']) ? (int)$row['audio_output_channels'] : null,
            'audio_track_mode' => $row['audio_track_mode'] ?? 'multi',
            'protocol' => self::isRtmp($row) ? 'rtmp' : (self::isRtmpIn($row) ? 'rtmp_in' : 'srt'),
            'rtmp_in_port' => self::isRtmpIn($row) ? IngestManager::PORT : null,
            // nur Server und Anwendung, der Stream-Key verlaesst den Server nie
            'rtmp_url' => self::isRtmp($row) ? ($row['rtmp_url'] ?? null) : null,
            'rtmp_deinterlace' => self::isRtmp($row) ? !empty($row['rtmp_deinterlace']) : null,
            'srt_mode' => $row['srt_mode'] ?? null,
            'srt_port' => isset($row['srt_port']) ? (int)$row['srt_port'] : null,
            'srt_host' => $row['srt_target_host'] ?? null,
            'srt_latency' => isset($row['srt_latency']) ? (int)$row['srt_latency'] : null,
            'encrypted' => !empty($row['srt_passphrase']),
        ];
    }

    /** SRT-Zahlen der Empfaenger: der Empfaenger schreibt sie jede Sekunde in eine Datei (aelter als 6 s = veraltet). */
    private static function attachReceiverStats(array &$items): void
    {
        foreach ($items as $i => $item) {
            if ($item['direction'] !== 'receiver' || strpos($item['key'], 'p') !== 0) {
                continue;
            }
            $raw = @file_get_contents(PipelineManager::receiverStatsPath((int)substr($item['key'], 1)));
            $data = $raw ? json_decode($raw, true) : null;
            if (is_array($data) && (microtime(true) - (float)($data['time'] ?? 0)) < 6) {
                $items[$i]['srt'] = $data;
            }
        }
    }

    /** Startet alle Auswertungen gleichzeitig und sammelt die Ergebnisse. */
    private static function runProbes(array &$items, array $previous): void
    {
        $script = __DIR__ . '/../scripts/ts_probe.py';
        $procs = [];
        foreach ($items as $i => $item) {
            if ($item['port'] === null) {
                continue;
            }
            $proc = proc_open(
                ['python3', $script, '--port', (string)$item['port'], '--seconds', (string)self::PROBE_SECONDS],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (is_resource($proc)) {
                $procs[$i] = [$proc, $pipes];
            }
        }
        $prevByKey = [];
        foreach ($previous as $old) {
            $prevByKey[$old['key'] ?? ''] = $old;
        }
        foreach ($procs as $i => [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $probe = json_decode((string)$out, true);
            if (!is_array($probe)) {
                $probe = ['active' => false, 'error' => 'Auswertung ohne Ergebnis'];
            }
            // Selten gesendete Angaben (SPS nur am Keyframe) vom letzten Mal uebernehmen
            $old = $prevByKey[$items[$i]['key']]['probe'] ?? null;
            if ($old && !empty($probe['video']) && empty($probe['video']['width']) && !empty($old['video']['width'])) {
                foreach (['profile', 'level', 'width', 'height', 'interlaced'] as $k) {
                    $probe['video'][$k] = $old['video'][$k] ?? null;
                }
            }
            $items[$i]['probe'] = $probe;
        }
    }
}
