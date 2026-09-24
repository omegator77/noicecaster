<?php

class SystemStats
{
    public static function collect(): array
    {
        return [
            'cpu_percent' => self::sampleCpuPercent(),
            'cpu_cores' => self::coreCount(),
            'mem_total_kb' => self::memInfo()['total'],
            'mem_used_kb' => self::memInfo()['used'],
            'temp_c' => self::cpuTemp(),
            'load' => sys_getloadavg(),
        ];
    }

    private static function coreCount(): int
    {
        $nproc = (int)trim((string)shell_exec('nproc'));
        return $nproc > 0 ? $nproc : 1;
    }

    private static function readProcStatTotals(): ?array
    {
        $content = @file_get_contents('/proc/stat');
        if (!$content) {
            return null;
        }
        $firstLine = strtok($content, "\n");
        $parts = preg_split('/\s+/', trim($firstLine));
        array_shift($parts); // "cpu" Label entfernen
        $values = array_map('intval', $parts);
        $idle = ($values[3] ?? 0) + ($values[4] ?? 0); // idle + iowait
        return ['total' => array_sum($values), 'idle' => $idle];
    }

    private static function sampleCpuPercent(): ?float
    {
        // Zwei Momentaufnahmen von /proc/stat mit kurzem Abstand ergeben die
        // Gesamtauslastung der Maschine (0-100%, nicht pro Kern wie bei ps)
        $first = self::readProcStatTotals();
        if (!$first) {
            return null;
        }
        usleep(200000);
        $second = self::readProcStatTotals();
        if (!$second) {
            return null;
        }
        $totalDelta = $second['total'] - $first['total'];
        $idleDelta = $second['idle'] - $first['idle'];
        if ($totalDelta <= 0) {
            return null;
        }
        return round((1 - $idleDelta / $totalDelta) * 100, 1);
    }

    private static function memInfo(): array
    {
        $content = @file_get_contents('/proc/meminfo');
        $total = 0;
        $available = 0;
        if ($content) {
            if (preg_match('/MemTotal:\s+(\d+)/', $content, $m)) {
                $total = (int)$m[1];
            }
            if (preg_match('/MemAvailable:\s+(\d+)/', $content, $m)) {
                $available = (int)$m[1];
            }
        }
        return ['total' => $total, 'used' => max(0, $total - $available)];
    }

    private static function cpuTemp(): ?float
    {
        // Kein lm-sensors auf diesem Server installiert - direkt aus den Kernel-Thermal-Zonen lesen.
        // x86_pkg_temp ist die tatsächliche CPU-Package-Temperatur, nicht nur ein ACPI-Sensor.
        $best = null;
        foreach (glob('/sys/class/thermal/thermal_zone*') as $zoneDir) {
            $type = @file_get_contents($zoneDir . '/type');
            $rawTemp = @file_get_contents($zoneDir . '/temp');
            if ($type === false || $rawTemp === false) {
                continue;
            }
            $celsius = (int)trim($rawTemp) / 1000;
            if (trim($type) === 'x86_pkg_temp') {
                return round($celsius, 1);
            }
            if ($best === null || $celsius > $best) {
                $best = $celsius;
            }
        }
        return $best !== null ? round($best, 1) : null;
    }
}
