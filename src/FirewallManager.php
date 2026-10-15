<?php

class FirewallManager
{
    private const HELPER = '/usr/local/sbin/gateway-firewall';

    /** Firewall-Zustand plus Abgleich mit den Ports, die die Pipelines von aussen brauchen. */
    public static function status(PipelineManager $manager): array
    {
        $fw = self::callHelper('status');
        $rules = $fw['rules'] ?? [];
        $active = $fw['active'] ?? null;

        return [
            'available' => $fw !== null,
            'active' => $active,
            'default' => $fw['default'] ?? '',
            'rules' => $rules,
            'unparsed' => $fw['unparsed'] ?? [],
            'pending' => $fw['pending'] ?? null,
            'needed' => self::neededPorts($manager, $rules, $fw !== null ? (bool)$active : null),
            'presets' => self::sourcePresets(),
            'ifaces' => self::interfaceNames(),
        ];
    }

    public static function add(array $req): array
    {
        Auth::requireConfigured();
        return self::requireHelper('add', json_encode($req));
    }

    public static function delete(array $req): array
    {
        Auth::requireConfigured();
        return self::requireHelper('delete', json_encode($req));
    }

    public static function confirm(): array
    {
        return self::requireHelper('confirm');
    }

    public static function rollback(): array
    {
        return self::requireHelper('rollback');
    }

    /** Eingehende UDP-Ports (SRT-Listener) aller Pipelines und Ziele samt Firewall-Zustand. */
    private static function neededPorts(PipelineManager $manager, array $rules, ?bool $active): array
    {
        $ports = [];
        $add = function (int $port, string $label, string $status) use (&$ports) {
            $ports[$port]['port'] = $port;
            $ports[$port]['users'][] = ['name' => $label, 'running' => $status === 'running'];
        };
        $tcp = [];
        $addTcp = function (int $port, string $label, string $status) use (&$tcp) {
            $tcp[$port]['port'] = $port;
            $tcp[$port]['proto'] = 'tcp';
            $tcp[$port]['users'][] = ['name' => $label, 'running' => $status === 'running'];
        };
        foreach ($manager->list() as $p) {
            if (($p['output_protocol'] ?? 'srt') === 'rtmp_in') {
                $addTcp(IngestManager::PORT, (string)$p['name'] . ' (RTMP-Annahme)', (string)($p['status'] ?? ''));
            } elseif (($p['srt_mode'] ?? 'listener') === 'listener' && ($p['output_protocol'] ?? 'srt') !== 'rtmp') {
                $add((int)$p['srt_port'], (string)$p['name'], (string)($p['status'] ?? ''));
            }
            foreach ($manager->listTargets((int)$p['id']) as $t) {
                if (($t['srt_mode'] ?? 'listener') === 'listener' && ($t['output_protocol'] ?? 'srt') !== 'rtmp') {
                    $add((int)$t['srt_port'], $p['name'] . ' / ' . ($t['name'] ?? 'Ziel'), (string)($t['status'] ?? ''));
                }
            }
        }
        ksort($ports);

        foreach ($ports as &$entry) {
            $entry['proto'] = 'udp';
            $entry['state'] = self::portState($entry['port'], $rules, $active, 'udp');
        }
        unset($entry);
        ksort($tcp);
        foreach ($tcp as &$entry) {
            $entry['state'] = self::portState($entry['port'], $rules, $active, 'tcp');
        }
        unset($entry);
        return array_merge(array_values($ports), array_values($tcp));
    }

    private static function portState(int $port, array $rules, ?bool $active, string $proto = 'udp'): array
    {
        if ($active === null) {
            return ['code' => 'unknown', 'sources' => []];
        }
        if (!$active) {
            return ['code' => 'inactive', 'sources' => []];
        }
        $sources = [];
        foreach ($rules as $r) {
            $dir = (string)($r['direction'] ?? '');
            if (($r['action'] ?? '') !== 'allow' || ($dir !== 'in' && strpos($dir, 'in_') !== 0)) {
                continue;
            }
            if (!in_array($r['proto'], [$proto, 'any'], true)) {
                continue;
            }
            $range = explode(':', (string)$r['port']);
            $lo = (int)$range[0];
            $hi = (int)($range[1] ?? $range[0]);
            if ($port >= $lo && $port <= $hi) {
                $iface = (string)($r['iface'] ?? '');
                $src = $r['source'] === 'any' ? ($iface ? 'alle auf ' . $iface : 'any') : $r['source'] . ($iface ? ' auf ' . $iface : '');
                $sources[] = $src;
            }
        }
        if (in_array('any', $sources, true)) {
            return ['code' => 'open_all', 'sources' => ['any']];
        }
        return $sources ? ['code' => 'open_limited', 'sources' => array_values(array_unique($sources))]
                        : ['code' => 'closed', 'sources' => []];
    }

    /** Schnittstellen, fuer die sich Regeln anlegen lassen (physische NICs und VPN). */
    private static function interfaceNames(): array
    {
        $out = [];
        foreach (NetworkManager::list()['interfaces'] ?? [] as $nic) {
            $out[] = ['name' => $nic['name'], 'label' => $nic['name'] . ($nic['is_vpn'] ? ' (VPN)' : '')];
        }
        return $out;
    }

    /** Vorschlaege fuer die Quelle: LAN- und VPN-Netz aus den aktuellen Adressen. */
    private static function sourcePresets(): array
    {
        $presets = [];
        $nics = NetworkManager::list()['interfaces'] ?? [];
        foreach ($nics as $nic) {
            $kind = $nic['is_vpn'] ? 'VPN' : 'LAN';
            if (isset($presets[$kind]) || empty($nic['ipv4'])) {
                continue;
            }
            [$ip, $prefix] = explode('/', $nic['ipv4'][0]);
            $mask = $prefix == 0 ? 0 : (~0 << (32 - (int)$prefix)) & 0xFFFFFFFF;
            $net = long2ip(ip2long($ip) & $mask);
            $presets[$kind] = ['label' => $kind . ' (' . $net . '/' . $prefix . ')', 'value' => $net . '/' . $prefix];
        }
        return array_values($presets);
    }

    private static function callHelper(string $action, ?string $stdin = null): ?array
    {
        if (!is_executable(self::HELPER)) {
            return null;
        }
        $proc = proc_open(
            ['sudo', '-n', self::HELPER, $action],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($proc)) {
            return null;
        }
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $data = json_decode((string)$out, true);
        return is_array($data) ? $data : null;
    }

    private static function requireHelper(string $action, ?string $stdin = null): array
    {
        $res = self::callHelper($action, $stdin);
        if ($res === null) {
            throw new RuntimeException('Firewall-Helfer nicht installiert (deploy/install-netconfig.sh mit sudo ausführen)');
        }
        if (isset($res['error'])) {
            throw new RuntimeException($res['error']);
        }
        return $res;
    }
}
