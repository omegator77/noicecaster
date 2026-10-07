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
            $items[] = [
                'key' => 'p' . $p['id'],
                'name' => (string)$p['name'] . ($sender ? ' / ' . ($p['target_name'] ?? 'Ziel 1') : ''),
                'direction' => $sender ? 'sender' : 'receiver',
                'port' => $sender ? PipelineManager::tapPort('pipeline', (int)$p['id']) : null,
                'config' => self::config($p),
            ];
            if (!$sender) {
                continue;
            }
            foreach ($manager->listTargets((int)$p['id']) as $t) {
                if (($t['status'] ?? '') !== 'running') {
                    continue;
                }
                $items[] = [
                    'key' => 't' . $t['id'],
                    'name' => (string)$p['name'] . ' / ' . ($t['name'] ?? 'Ziel'),
                    'direction' => 'sender',
                    'port' => PipelineManager::tapPort('target', (int)$t['id']),
                    'config' => self::config($t),
                ];
            }
        }
        return $items;
    }

    private static function config(array $row): array
    {
        return [
            'source_type' => $row['source_type'] ?? 'decklink',
            // Beim SDI-Eingang gilt die Bildgroesse der Buchse; width/height zaehlen nur bei Skalierung
            'scaled' => !empty($row['video_scale_enabled']),
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
            'srt_mode' => $row['srt_mode'] ?? null,
            'srt_port' => isset($row['srt_port']) ? (int)$row['srt_port'] : null,
            'srt_host' => $row['srt_target_host'] ?? null,
            'srt_latency' => isset($row['srt_latency']) ? (int)$row['srt_latency'] : null,
            'encrypted' => !empty($row['srt_passphrase']),
        ];
    }

    /** Startet alle Auswertungen gleichzeitig und sammelt die Ergebnisse. */
    private static function runProbes(array &$items, array $previous): void
    {
        $script = __DIR__ . '/../scripts/ts_probe.py';
        $procs = [];
        foreach ($items as $i => $item) {
            if ($item['direction'] !== 'sender') {
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
