<?php

/**
 * Kennung dieses Systems und Erreichbarkeit des Partnersystems (fuer die Kopfzeile der Oberflaeche).
 *
 * Ohne Konfiguration wird alles aus dem Hostnamen abgeleitet (openbroadcast1 <-> openbroadcast2). Optional
 * data/peer.json (nicht versioniert, gilt je System), z. B.:
 *   {"self_label":"System 1","peer_label":"System 2","peer_host":"openbroadcast2","peer_vpn":"10.1.1.12",
 *    "port":8000,"accent":"#38bdf8"}
 * Die Erreichbarkeit wird ueber die VPN-Adresse des Partners geprueft (peer_vpn, sonst peer_host) und 10 s
 * zwischengespeichert, damit offene Seiten keine PHP-Worker blockieren.
 */
class PeerInfo
{
    private const CACHE_SECONDS = 10;

    public static function get(): array
    {
        $dir = __DIR__ . '/../data';
        $cfg = [];
        if (is_file($dir . '/peer.json')) {
            $c = json_decode((string)@file_get_contents($dir . '/peer.json'), true);
            if (is_array($c)) {
                $cfg = $c;
            }
        }

        $host = (string)gethostname();
        $self = ['label' => $host, 'accent' => '#38bdf8'];
        $peer = ['label' => '', 'host' => ''];
        if (preg_match('/^(.*?)([12])$/', $host, $m)) {
            $n = (int)$m[2];
            $self = ['label' => "System $n", 'accent' => $n === 1 ? '#38bdf8' : '#c084fc'];
            $peer = ['label' => 'System ' . (3 - $n), 'host' => $m[1] . (3 - $n)];
        }
        $self['label'] = (string)($cfg['self_label'] ?? $self['label']);
        $self['accent'] = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($cfg['accent'] ?? '')) ? $cfg['accent'] : $self['accent'];
        $self['host'] = $host;
        $peer['label'] = (string)($cfg['peer_label'] ?? $peer['label']);
        $peer['host'] = (string)($cfg['peer_host'] ?? $peer['host']);
        $vpn = (string)($cfg['peer_vpn'] ?? '');
        $port = (int)($cfg['port'] ?? 8000);

        // nur plausible Adressen weitergeben (fliessen in einen Shell-Aufruf und in einen Link)
        $ok = '/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/';
        if ($peer['host'] !== '' && !preg_match($ok, $peer['host'])) {
            $peer['host'] = '';
        }
        if ($vpn !== '' && !preg_match($ok, $vpn)) {
            $vpn = '';
        }
        $probe = $vpn !== '' ? $vpn : $peer['host'];

        $peer['url'] = $peer['host'] !== '' ? "http://{$peer['host']}:{$port}/" : ($probe !== '' ? "http://{$probe}:{$port}/" : '');
        $peer['via'] = $vpn !== '' ? 'VPN' : 'Netz';
        $peer['reachable'] = null;
        $peer['ms'] = null;
        if ($probe !== '' && $port > 0 && $port < 65536) {
            $status = self::probe($dir . '/peer_status.json', $probe, $port);
            $peer['reachable'] = $status['reachable'];
            $peer['ms'] = $status['ms'];
        }
        return ['self' => $self, 'peer' => $peer];
    }

    private static function probe(string $cacheFile, string $addr, int $port): array
    {
        $key = "{$addr}:{$port}";
        if (is_file($cacheFile)) {
            $c = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($c) && ($c['key'] ?? '') === $key && time() - (int)($c['time'] ?? 0) < self::CACHE_SECONDS) {
                return ['reachable' => (bool)$c['reachable'], 'ms' => $c['ms']];
            }
        }
        $url = escapeshellarg("http://{$addr}:{$port}/");
        $out = trim((string)shell_exec("curl -s -o /dev/null -m 2 --connect-timeout 2 -w '%{http_code} %{time_total}' {$url} 2>/dev/null"));
        $parts = explode(' ', $out);
        $reachable = isset($parts[0]) && (int)$parts[0] > 0;
        $ms = $reachable && isset($parts[1]) ? (int)round(((float)$parts[1]) * 1000) : null;
        @file_put_contents($cacheFile, json_encode(['key' => $key, 'time' => time(), 'reachable' => $reachable, 'ms' => $ms]));
        return ['reachable' => $reachable, 'ms' => $ms];
    }
}
