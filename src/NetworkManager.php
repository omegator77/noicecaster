<?php

class NetworkManager
{
    private const HELPER = '/usr/local/sbin/gateway-netconfig';
    private const PENDING_FILE = '/var/lib/gateway-netconfig/pending.json';

    public static function list(): array
    {
        $addrs = self::ipJson(['addr', 'show']);
        $routes = self::ipJson(['route', 'show']);
        $helper = self::callHelper('show');
        $configured = $helper['interfaces'] ?? [];

        $nics = [];
        foreach (self::physicalNics() as $name) {
            $info = null;
            foreach ($addrs as $a) {
                if (($a['ifname'] ?? '') === $name) {
                    $info = $a;
                }
            }
            $ipv4 = [];
            $ipv6 = [];
            $dynamic = false;
            foreach (($info['addr_info'] ?? []) as $ai) {
                if (($ai['family'] ?? '') === 'inet') {
                    $ipv4[] = $ai['local'] . '/' . $ai['prefixlen'];
                    $dynamic = $dynamic || !empty($ai['dynamic']);
                } elseif (($ai['family'] ?? '') === 'inet6' && ($ai['scope'] ?? '') === 'global') {
                    $ipv6[] = $ai['local'] . '/' . $ai['prefixlen'];
                }
            }
            $gateway = '';
            foreach ($routes as $r) {
                if (($r['dst'] ?? '') === 'default' && ($r['dev'] ?? '') === $name) {
                    $gateway = $r['gateway'] ?? '';
                }
            }
            $staticRoutes = [];
            foreach ($routes as $r) {
                if (($r['dev'] ?? '') === $name && ($r['dst'] ?? '') !== 'default'
                    && ($r['protocol'] ?? '') === 'static' && !empty($r['gateway'])) {
                    $staticRoutes[] = $r['dst'] . ' via ' . $r['gateway'];
                }
            }
            $speed = (int)self::readSys($name, 'speed');
            $nics[] = [
                'name' => $name,
                'is_vpn' => strpos($name, 'vpn_') === 0,
                'mac' => self::readSys($name, 'address'),
                'state' => $info['operstate'] ?? 'UNKNOWN',
                'carrier' => self::readSys($name, 'carrier') === '1',
                'speed_mbit' => $speed > 0 ? $speed : null,
                'driver' => self::driver($name),
                'ipv4' => $ipv4,
                'ipv6' => $ipv6,
                'gateway' => $gateway,
                'static_routes' => $staticRoutes,
                'dns' => self::dns($name),
                'dhcp_active' => $dynamic,
                'configured' => $configured[$name] ?? null,
            ];
        }

        return [
            'interfaces' => $nics,
            'editable' => $helper !== null,
            'pending' => self::pending(),
            'vpn' => self::vpnStatus(),
        ];
    }

    /** Neue Einstellungen anwenden (mit Rueckfall-Timer im Helfer). */
    public static function apply(array $interfaces): array
    {
        return self::requireHelper('apply', json_encode(['interfaces' => $interfaces]));
    }

    public static function confirm(): array
    {
        return self::requireHelper('confirm');
    }

    public static function rollback(): array
    {
        return self::requireHelper('rollback');
    }

    /** VPN-Status des SoftEther-Clients; null, wenn der Helfer fehlt oder veraltet ist. */
    public static function vpnStatus(): ?array
    {
        $res = self::callHelper('vpn-status');
        if ($res === null || isset($res['error'])) {
            return null;
        }
        // Nur harmlose Felder weitergeben (u.a. nicht den Session-Key)
        $allowed = ['Session Status', 'Session Name', 'Current Session has been Established since',
            'Number of Established Sessions', 'Encryption', 'UDP Acceleration is Active'];
        $res['details'] = array_intersect_key($res['details'] ?? [], array_flip($allowed));
        return $res;
    }

    public static function vpnConnect(): array
    {
        return self::requireHelper('vpn-connect');
    }

    public static function vpnReconnect(): array
    {
        return self::requireHelper('vpn-reconnect');
    }

    public static function pending(): ?array
    {
        $raw = @file_get_contents(self::PENDING_FILE);
        $data = $raw ? json_decode($raw, true) : null;
        return is_array($data) ? $data : null;
    }

    private static function physicalNics(): array
    {
        $names = [];
        $vpn = [];
        foreach (scandir('/sys/class/net') ?: [] as $name) {
            $base = '/sys/class/net/' . $name;
            if (strpos($name, 'vpn_') === 0) {
                $vpn[] = $name;
                continue;
            }
            if ($name[0] === '.' || !file_exists($base . '/device') || file_exists($base . '/wireless')) {
                continue;
            }
            if (strpos((string)realpath($base), '/virtual/') !== false) {
                continue;
            }
            $names[] = $name;
        }
        natsort($names);
        sort($vpn);
        return array_merge(array_values($names), $vpn);
    }

    private static function readSys(string $name, string $file): string
    {
        $v = @file_get_contents('/sys/class/net/' . $name . '/' . $file);
        return $v === false ? '' : trim($v);
    }

    private static function driver(string $name): string
    {
        $link = @readlink('/sys/class/net/' . $name . '/device/driver');
        return $link ? basename($link) : '';
    }

    private static function ipJson(array $args): array
    {
        $out = shell_exec('ip -j ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null');
        $data = $out ? json_decode($out, true) : null;
        return is_array($data) ? $data : [];
    }

    private static function dns(string $name): array
    {
        $out = (string)shell_exec('resolvectl dns ' . escapeshellarg($name) . ' 2>/dev/null');
        $pos = strpos($out, ':');
        if ($pos === false) {
            return [];
        }
        return array_values(array_filter(preg_split('/\s+/', trim(substr($out, $pos + 1)))));
    }

    /** null, wenn der sudo-Helfer nicht installiert ist. */
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
            throw new RuntimeException('Netzwerk-Helfer nicht installiert (deploy/install-netconfig.sh mit sudo ausführen)');
        }
        if (isset($res['error'])) {
            throw new RuntimeException($res['error']);
        }
        return $res;
    }
}
