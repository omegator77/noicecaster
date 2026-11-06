<?php

require_once __DIR__ . '/Amcp.php';

/**
 * CasparCG (Grafik- und Playout-Server) als vom Gateway verwalteter Prozess, nach dem Muster von IngestManager.
 *
 * Installation: deploy/install-casparcg.sh (entpackt die geprueften .deb-Pakete nach bin/casparcg, ohne sudo).
 * Ablauf: Das Gateway startet eine virtuelle Anzeige (Xvfb, :97) und CasparCG als normale Prozesse des Gateway-Benutzers
 * und beendet sie wieder. Die Konfiguration wird bei jedem Start aus den Einstellungen erzeugt (data/caspar.json).
 *
 * DeckLink-Anschluesse: Anschluesse mit der Rolle "caspar" gehoeren CasparCG. Das Gateway kann sie fuer Pipelines nicht
 * mehr verwenden (DecklinkPortManager::assertRole), und die Rolle laesst sich nicht aendern, solange CasparCG laeuft.
 * Einstellungen: fill (Anschluss der Grafik, Fill), key (Anschluss fuer den Key, optional), clip (eigener Videoausgang,
 * optional). Nummern sind wie ueberall im Gateway 0-indiziert (Anschluss 1 = 0); CasparCG zaehlt ab 1.
 *
 * Steuerung: AMCP auf 127.0.0.1:5250. CasparCG bindet diesen Port auf allen Adressen und bietet keine Anmeldung; er darf
 * von aussen nicht erreichbar sein (Firewall, "deny incoming").
 */
class CasparManager
{
    public const DISPLAY = ':97';
    public const MODE_MAP = [
        '1080i50' => '1080i5000', '1080i5994' => '1080i5994', '1080i60' => '1080i6000',
        '1080p50' => '1080p5000', '1080p5994' => '1080p5994', '1080p60' => '1080p6000',
        '1080p25' => '1080p2500', '1080p2997' => '1080p2997', '1080p30' => '1080p3000',
        '1080p24' => '1080p2400', '1080p2398' => '1080p2398',
        '720p50' => '720p5000', '720p5994' => '720p5994', '720p60' => '720p6000',
    ];
    public const DEFAULT_MODE = '1080i50';

    public static function root(): string
    {
        return __DIR__ . '/../bin/casparcg';
    }

    public static function binary(): string
    {
        return self::root() . '/root/usr/bin/casparcg-server-2.5';
    }

    public static function libDir(): string
    {
        return self::root() . '/root/usr/lib/casparcg-cef-142';
    }

    public static function installed(): bool
    {
        return is_file(self::binary()) && is_executable(self::binary());
    }

    public static function version(): string
    {
        return trim((string)@file_get_contents(self::root() . '/VERSION'));
    }

    public static function dataDir(): string
    {
        return __DIR__ . '/../data/caspar';
    }

    public static function settingsPath(): string
    {
        return __DIR__ . '/../data/caspar.json';
    }

    public static function configPath(): string
    {
        return self::dataDir() . '/casparcg.config';
    }

    public static function logPath(): string
    {
        return self::dataDir() . '/server.log';
    }

    public static function templateDir(): string
    {
        return self::dataDir() . '/template';
    }

    public static function mediaDir(): string
    {
        return self::dataDir() . '/media';
    }

    /** Fehlende Systembibliotheken (leer = alles da). */
    public static function missingLibs(): array
    {
        if (!self::installed()) {
            return [];
        }
        $out = (string)@shell_exec('LD_LIBRARY_PATH=' . escapeshellarg(self::libDir()) . ' ldd ' . escapeshellarg(self::binary()) . ' 2>&1');
        $missing = [];
        foreach (explode("\n", $out) as $line) {
            if (str_contains($line, 'not found')) {
                $missing[] = trim(explode('=>', $line)[0]);
            }
        }
        return $missing;
    }

    public static function xvfbAvailable(): bool
    {
        return is_executable('/usr/bin/Xvfb');
    }

    // ----- Einstellungen -------------------------------------------------------------------------------------------

    public static function defaults(): array
    {
        return ['fill' => null, 'key' => null, 'clip' => null, 'test_screen' => false];
    }

    public static function settings(): array
    {
        $raw = json_decode((string)@file_get_contents(self::settingsPath()), true);
        $s = self::defaults();
        if (is_array($raw)) {
            foreach (['fill', 'key', 'clip'] as $k) {
                if (isset($raw[$k]) && is_int($raw[$k]) && $raw[$k] >= 0 && $raw[$k] <= 3) {
                    $s[$k] = $raw[$k];
                }
            }
            $s['test_screen'] = !empty($raw['test_screen']);
        }
        return $s;
    }

    /**
     * Einstellungen pruefen und speichern. Nur bei gestopptem CasparCG.
     * @throws RuntimeException bei ungueltigen Einstellungen
     */
    public static function saveSettings(PDO $db, array $in): array
    {
        if (self::running()) {
            throw new RuntimeException('CasparCG laeuft: zum Aendern der Einstellungen bitte zuerst stoppen.');
        }
        $ports = new DecklinkPortManager($db);
        $s = self::defaults();
        foreach (['fill', 'key', 'clip'] as $k) {
            $v = $in[$k] ?? null;
            if ($v === null || $v === '' || $v === false) {
                continue;
            }
            $n = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 3]]);
            if ($n === false) {
                throw new RuntimeException('Ungueltiger Anschluss fuer ' . $k);
            }
            $s[$k] = $n;
        }
        $s['test_screen'] = !empty($in['test_screen']);
        $used = array_filter([$s['fill'], $s['key'], $s['clip']], fn($v) => $v !== null);
        if (count($used) !== count(array_unique($used))) {
            throw new RuntimeException('Jeder Anschluss darf nur einer Aufgabe zugeordnet sein.');
        }
        if ($s['key'] !== null && $s['fill'] === null) {
            throw new RuntimeException('Ein Key-Anschluss braucht auch einen Fill-Anschluss.');
        }
        foreach ($used as $n) {
            if ($ports->getRole((int)$n) !== 'caspar') {
                throw new RuntimeException('SDI-Anschluss ' . ($n + 1) . ' hat nicht die Rolle "CasparCG". Bitte zuerst unter "SDI-Anschluesse" die Rolle setzen.');
            }
        }
        if ($used && $s['test_screen']) {
            throw new RuntimeException('Der Testmodus ohne Karte ist nur ohne zugeordnete Anschluesse moeglich.');
        }
        if ($s['fill'] !== null) {
            self::videoMode($ports->getMode((int)$s['fill']));
        }
        $tmp = self::settingsPath() . '.tmp';
        if (@file_put_contents($tmp, json_encode($s, JSON_PRETTY_PRINT)) === false || !@rename($tmp, self::settingsPath())) {
            throw new RuntimeException('Einstellungen nicht schreibbar');
        }
        return $s;
    }

    /** Videoformat des Gateway-Anschlusses in CasparCG-Schreibweise. */
    public static function videoMode(string $gatewayMode): string
    {
        if (!isset(self::MODE_MAP[$gatewayMode])) {
            throw new RuntimeException('Das Videoformat "' . $gatewayMode . '" des Fill-Anschlusses wird von CasparCG nicht unterstuetzt. Bitte ein festes Format (zum Beispiel 1080i50) einstellen.');
        }
        return self::MODE_MAP[$gatewayMode];
    }

    // ----- Konfiguration --------------------------------------------------------------------------------------------

    public static function buildConfig(array $s, string $fillMode, string $clipMode): string
    {
        $esc = fn(string $x) => htmlspecialchars($x, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $dir = realpath(self::dataDir()) ?: self::dataDir();
        $channels = '';
        if ($s['fill'] !== null) {
            $key = $s['key'] !== null
                ? "          <key-device>" . ($s['key'] + 1) . "</key-device>\n          <keyer>external_separate_device</keyer>\n"
                : '';
            $channels .= "    <channel>\n      <video-mode>{$fillMode}</video-mode>\n      <consumers>\n        <decklink>\n          <device>" . ($s['fill'] + 1) . "</device>\n{$key}          <embedded-audio>false</embedded-audio>\n        </decklink>\n      </consumers>\n    </channel>\n";
        } else {
            // Ohne Karte (Entwicklung/Test): Bild auf der virtuellen Anzeige
            $channels .= "    <channel>\n      <video-mode>{$fillMode}</video-mode>\n      <consumers>\n        <screen/>\n      </consumers>\n    </channel>\n";
        }
        if ($s['clip'] !== null) {
            $channels .= "    <channel>\n      <video-mode>{$clipMode}</video-mode>\n      <consumers>\n        <decklink>\n          <device>" . ($s['clip'] + 1) . "</device>\n          <embedded-audio>true</embedded-audio>\n        </decklink>\n      </consumers>\n    </channel>\n";
        }
        return "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<!-- Von CasparManager.php erzeugt, nicht von Hand aendern. -->\n<configuration>\n  <paths>\n"
            . "    <media-path>" . $esc($dir . '/media/') . "</media-path>\n"
            . "    <log-path disable=\"false\">" . $esc($dir . '/log/') . "</log-path>\n"
            . "    <data-path>" . $esc($dir . '/data/') . "</data-path>\n"
            . "    <template-path>" . $esc($dir . '/template/') . "</template-path>\n"
            . "  </paths>\n  <log-level>info</log-level>\n  <channels>\n{$channels}  </channels>\n"
            . "  <controllers>\n    <tcp>\n      <port>" . Amcp::PORT . "</port>\n      <protocol>AMCP</protocol>\n    </tcp>\n  </controllers>\n</configuration>\n";
    }

    // ----- Prozesse -------------------------------------------------------------------------------------------------

    /** PIDs eigener Prozesse, deren Kommandozeile alle Teile enthaelt. */
    private static function findPids(array $needles): array
    {
        $pids = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $f) {
            $cmd = @file_get_contents($f);
            if ($cmd === false || $cmd === '') {
                continue;
            }
            $ok = true;
            foreach ($needles as $n) {
                if (!str_contains($cmd, $n)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $pid = (int)explode('/', $f)[2];
                if ($pid !== getmypid()) {
                    $pids[] = $pid;
                }
            }
        }
        return $pids;
    }

    public static function pids(): array
    {
        return self::findPids(["casparcg-server-2.5\0" . self::configPath()]);
    }

    private static function xvfbPids(): array
    {
        return self::findPids(["Xvfb\0" . self::DISPLAY . "\0"]);
    }

    public static function running(): bool
    {
        return self::pids() !== [];
    }

    public static function amcpReachable(): bool
    {
        $fp = @stream_socket_client('tcp://' . Amcp::HOST . ':' . Amcp::PORT, $e, $s, 0.5);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    /** Eingebaute Vorlagen (und spaeter hochgeladene) als Verknuepfungen in den Vorlagenordner von CasparCG legen. */
    public static function syncTemplates(): void
    {
        $dir = self::templateDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException('Vorlagenordner nicht anlegbar');
        }
        $wanted = [];
        foreach (GraphicsLibrary::templateDirs() as $id => $path) {
            $wanted[$id] = $path;
        }
        foreach (glob($dir . '/*', GLOB_NOSORT) ?: [] as $link) {
            if (is_link($link) && !isset($wanted[basename($link)])) {
                @unlink($link);
            }
        }
        foreach ($wanted as $id => $path) {
            $link = $dir . '/' . $id;
            if (is_link($link) && readlink($link) === $path) {
                continue;
            }
            if (is_link($link)) {
                @unlink($link);
            }
            if (!file_exists($link)) {
                @symlink($path, $link);
            }
        }
    }

    /**
     * CasparCG (und die virtuelle Anzeige) starten.
     * @throws RuntimeException mit verstaendlicher Meldung
     */
    public static function start(PDO $db): void
    {
        if (!self::installed()) {
            throw new RuntimeException('CasparCG ist nicht installiert (deploy/install-casparcg.sh ausfuehren).');
        }
        $missing = self::missingLibs();
        if ($missing) {
            throw new RuntimeException('Es fehlen Systembibliotheken: ' . implode(', ', $missing) . ' (siehe Ausgabe von deploy/install-casparcg.sh).');
        }
        if (!self::xvfbAvailable()) {
            throw new RuntimeException('Xvfb (virtuelle Anzeige) fehlt: sudo apt install xvfb');
        }
        if (self::running()) {
            return;
        }
        if (self::amcpReachable()) {
            throw new RuntimeException('Port ' . Amcp::PORT . ' ist schon belegt (laeuft ein anderes CasparCG?).');
        }
        $s = self::settings();
        $ports = new DecklinkPortManager($db);
        $fillMode = $s['fill'] !== null ? self::videoMode($ports->getMode((int)$s['fill'])) : self::MODE_MAP[self::DEFAULT_MODE];
        $clipMode = $s['clip'] !== null ? self::videoMode($ports->getMode((int)$s['clip'])) : $fillMode;
        if ($s['fill'] === null && $s['clip'] === null && !$s['test_screen']) {
            throw new RuntimeException('Es ist noch kein Ausgang zugeordnet (Fill-Anschluss oder Testmodus ohne Karte waehlen).');
        }
        foreach (['', '/media', '/data', '/log'] as $sub) {
            if (!is_dir(self::dataDir() . $sub) && !@mkdir(self::dataDir() . $sub, 0775, true)) {
                throw new RuntimeException('Ordner nicht anlegbar: data/caspar' . $sub);
            }
        }
        self::syncTemplates();
        $cfg = self::buildConfig($s, $fillMode, $clipMode);
        $tmp = self::configPath() . '.tmp';
        if (@file_put_contents($tmp, $cfg) === false || !@rename($tmp, self::configPath())) {
            throw new RuntimeException('CasparCG-Konfiguration nicht schreibbar');
        }

        // CasparCG: Konsole (stdin) an eine Named Pipe im Lese-/Schreibmodus haengen, damit sie nie EOF sieht
        $fifo = self::dataDir() . '/stdin.fifo';
        if (!file_exists($fifo)) {
            shell_exec('mkfifo ' . escapeshellarg($fifo));
        }
        $env = 'LD_LIBRARY_PATH=' . escapeshellarg(self::libDir()) . ' DISPLAY=' . escapeshellarg(self::DISPLAY) . ' LIBGL_ALWAYS_SOFTWARE=1';
        $cmd = 'cd ' . escapeshellarg(self::dataDir()) . ' && setsid nohup env ' . $env . ' ' . escapeshellarg(self::binary()) . ' '
            . escapeshellarg(self::configPath()) . ' <> ' . escapeshellarg($fifo) . ' > ' . escapeshellarg(self::logPath()) . ' 2>&1 &';

        // Zwei Versuche: laeuft CasparCG nicht hoch (zum Beispiel weil die virtuelle Anzeige noch nicht bereit war),
        // wird alles zurueckgesetzt und einmal neu gestartet
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            self::startDisplay();
            shell_exec($cmd);
            for ($i = 0; $i < 60; $i++) {
                usleep(250000);
                if (self::amcpReachable()) {
                    return;
                }
                if (!self::running()) {
                    break;
                }
            }
            $tail = trim((string)@shell_exec('tail -n 4 ' . escapeshellarg(self::logPath()) . ' 2>/dev/null'));
            self::stop();
            if ($attempt === 2) {
                throw new RuntimeException('CasparCG ist nicht gestartet: ' . substr($tail, -300));
            }
        }
    }

    /** Virtuelle Anzeige starten und warten, bis sie Verbindungen annimmt. */
    private static function startDisplay(): void
    {
        $num = substr(self::DISPLAY, 1);
        $socket = '/tmp/.X11-unix/X' . $num;
        if (!self::xvfbPids()) {
            @unlink($socket);
            @unlink('/tmp/.X' . $num . '-lock');
            shell_exec('setsid nohup Xvfb ' . escapeshellarg(self::DISPLAY) . ' -screen 0 1920x1080x24 -nolisten tcp > /dev/null 2>&1 &');
        }
        // Bereit ist sie erst, wenn eine Verbindung gelingt (die Datei allein genuegt nicht)
        for ($i = 0; $i < 40; $i++) {
            $fp = @stream_socket_client('unix://' . $socket, $e, $s, 0.5);
            if ($fp) {
                fclose($fp);
                usleep(300000);
                return;
            }
            usleep(200000);
        }
        throw new RuntimeException('Die virtuelle Anzeige (Xvfb) startet nicht.');
    }

    public static function stop(): void
    {
        if (self::amcpReachable()) {
            try {
                Amcp::send('KILL', 2.0);
            } catch (Throwable $e) {
                // wird unten per Signal beendet
            }
            for ($i = 0; $i < 40 && self::running(); $i++) {
                usleep(150000);
            }
        }
        foreach (self::pids() as $pid) {
            @posix_kill($pid, SIGTERM);
        }
        for ($i = 0; $i < 30 && self::running(); $i++) {
            usleep(100000);
        }
        foreach (self::pids() as $pid) {
            @posix_kill($pid, SIGKILL);
        }
        foreach (self::xvfbPids() as $pid) {
            @posix_kill($pid, SIGTERM);
        }
        // Warten, bis die virtuelle Anzeige wirklich weg ist: ein sofortiger Neustart wuerde sonst auf eine sterbende
        // Anzeige treffen
        for ($i = 0; $i < 30 && self::xvfbPids(); $i++) {
            usleep(100000);
        }
        foreach (self::xvfbPids() as $pid) {
            @posix_kill($pid, SIGKILL);
        }
        for ($i = 0; $i < 10 && self::xvfbPids(); $i++) {
            usleep(100000);
        }
        $num = substr(self::DISPLAY, 1);
        @unlink('/tmp/.X11-unix/X' . $num);
        @unlink('/tmp/.X' . $num . '-lock');
    }

    /** Zustand fuer die Oberflaeche. */
    public static function status(PDO $db): array
    {
        $running = self::running();
        $version = '';
        if ($running && self::amcpReachable()) {
            try {
                $v = Amcp::send('VERSION SERVER', 2.0);
                $version = $v['data'][0] ?? '';
            } catch (Throwable $e) {
                $version = '';
            }
        }
        $ports = new DecklinkPortManager($db);
        $s = self::settings();
        return [
            'installed' => self::installed(),
            'installed_version' => self::version(),
            'missing_libs' => $running ? [] : self::missingLibs(), // laeuft CasparCG, sind die Bibliotheken da (ldd spart man sich)
            'xvfb' => self::xvfbAvailable(),
            'running' => $running,
            'amcp' => $running && self::amcpReachable(),
            'server_version' => $version,
            'settings' => $s,
            'modes' => [
                'fill' => $s['fill'] !== null ? $ports->getMode((int)$s['fill']) : null,
                'clip' => $s['clip'] !== null ? $ports->getMode((int)$s['clip']) : null,
            ],
            'caspar_ports' => array_values(array_map(
                fn($p) => (int)$p['device_number'],
                array_filter($ports->list(), fn($p) => $p['role'] === 'caspar')
            )),
        ];
    }
}
