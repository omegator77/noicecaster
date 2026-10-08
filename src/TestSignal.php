<?php

/**
 * Testsignal fuer einen SDI-Ausgang: bewegtes Bild (Ball, Laufzeit, Beschriftung) und 16 Toene mit
 * unterschiedlichen Pegeln (Kanal 1: -6 dBFS, jeder weitere 3 dB leiser bis -51 dBFS bei Kanal 16).
 *
 * Laeuft als eigener gst-launch-Prozess neben der Pipeline-Verwaltung; die PID steht in
 * data/testsignal_<Karte>.json. Es gibt nur ein Signal je Karte, und es wird beendet, sobald auf dieser
 * Karte eine Ausgangs-Pipeline startet.
 */
class TestSignal
{
    private const FREQS = [220, 262, 311, 370, 440, 523, 622, 740, 880, 1047, 1245, 1480, 1760, 2093, 2489, 2960];
    private const RATES = [
        '24' => '24/1', '2398' => '24000/1001', '25' => '25/1', '30' => '30/1', '2997' => '30000/1001',
        '50' => '50/1', '5994' => '60000/1001', '60' => '60/1',
    ];

    private static function stateFile(int $device): string
    {
        return __DIR__ . "/../data/testsignal_{$device}.json";
    }

    private static function pid(int $device): int
    {
        $st = is_file(self::stateFile($device)) ? json_decode((string)@file_get_contents(self::stateFile($device)), true) : null;
        return is_array($st) ? (int)($st['pid'] ?? 0) : 0;
    }

    private static function alive(int $pid): bool
    {
        if ($pid <= 1 || !is_dir("/proc/{$pid}")) {
            return false;
        }
        // PID koennte inzwischen von einem fremden Prozess wiederverwendet worden sein
        $cmd = (string)@file_get_contents("/proc/{$pid}/cmdline");
        return strpos($cmd, 'gst-launch') !== false;
    }

    public static function isRunning(int $device): bool
    {
        return self::alive(self::pid($device));
    }

    public static function start(int $device, string $mode, string $label): void
    {
        if (self::isRunning($device)) {
            return;
        }
        $cmd = self::buildCommand($device, $mode, $label);
        $log = __DIR__ . "/../data/testsignal_{$device}.log";
        $pid = (int)shell_exec("nohup {$cmd} > " . escapeshellarg($log) . " 2>&1 & echo $!");
        @file_put_contents(self::stateFile($device), json_encode(['pid' => $pid, 'started' => time(), 'mode' => $mode]));
        // kurz warten: scheitert der Start (Karte belegt, Modus nicht moeglich), nicht "an" anzeigen
        for ($i = 0; $i < 25 && self::alive($pid); $i++) {
            usleep(100000);
        }
        if (!self::alive($pid)) {
            @unlink(self::stateFile($device));
            $tail = trim((string)shell_exec('tail -n 3 ' . escapeshellarg($log) . ' 2>/dev/null'));
            throw new RuntimeException('Testsignal konnte nicht starten' . ($tail !== '' ? ': ' . substr($tail, -200) : ''));
        }
    }

    public static function stop(int $device): void
    {
        $pid = self::pid($device);
        if (self::alive($pid)) {
            posix_kill($pid, SIGTERM);
            for ($i = 0; $i < 40 && self::alive($pid); $i++) {
                usleep(100000);
            }
            if (self::alive($pid)) {
                posix_kill($pid, SIGKILL);
                usleep(500000);
            }
        }
        @unlink(self::stateFile($device));
    }

    private static function buildCommand(int $device, string $mode, string $label): string
    {
        $height = 1080;
        $interlaced = true;
        $rate = '50/1';
        if (preg_match('/^(\d{3,4})([ip])(\d{2,4})$/', $mode, $m) && isset(self::RATES[$m[3]])) {
            $height = (int)$m[1];
            $interlaced = $m[2] === 'i';
            $rate = self::RATES[$m[3]];
        }
        $width = $height === 720 ? 1280 : ($height === 2160 ? 3840 : 1920);
        $label = preg_replace('/[^A-Za-z0-9 .:_-]/', '', $label);
        // Der Text steht ohne echte Anfuehrungszeichen in der Shell-Zeile und gst-launch liest jedes Wort einzeln:
        // keine Klammern und keine Woerter, die mit "-" beginnen (sonst "Unknown option")
        $legend = 'Kanal 1 bis 16: 220 bis 2960 Hz, Pegel bei Kanal 1 minus 6 dBFS, jeder weitere Kanal 3 dB leiser bis minus 51 dBFS';

        $video = "videotestsrc pattern=ball is-live=true background-color=0 ! video/x-raw,width={$width},height={$height},framerate={$rate} ! " .
            'textoverlay text=\"' . $label . '\" halignment=center valignment=top font-desc=\"Sans Bold 40\" ! ' .
            'textoverlay text=\"' . $legend . '\" halignment=center valignment=bottom font-desc=\"Sans 26\" ! ' .
            'timeoverlay halignment=center valignment=center font-desc=\"Sans Bold 60\" ! ' .
            ($interlaced ? 'interlace field-pattern=0 top-field-first=true ! ' : '') .
            "decklinkvideosink device-number={$device} mode={$mode} profile=two-sub-devices-half";

        $tones = '';
        foreach (self::FREQS as $k => $freq) {
            $vol = round(pow(10, (-6 - 3 * $k) / 20), 4);
            $tones .= " audiotestsrc is-live=true wave=sine freq={$freq} volume={$vol} ! audio/x-raw,channels=1,rate=48000 ! queue ! il.sink_{$k}";
        }
        $audio = 'interleave name=il ! audio/x-raw,channels=16,channel-mask=\(bitmask\)0 ! audioconvert ! ' .
            "audio/x-raw,format=S32LE,rate=48000,channels=16,channel-mask=\(bitmask\)0 ! decklinkaudiosink device-number={$device}";

        return "gst-launch-1.0 -v {$video} {$audio}{$tones}";
    }
}
