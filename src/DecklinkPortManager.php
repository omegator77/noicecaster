<?php

class DecklinkPortManager
{
    private PDO $db;
    private const ROLES = ['input', 'output', 'unused'];
    public const MODES = [
        'auto',
        'ntsc', 'ntsc2398', 'pal', 'ntsc-p', 'pal-p',
        'ntsc-widescreen', 'ntsc2398-widescreen', 'pal-widescreen', 'ntsc-p-widescreen', 'pal-p-widescreen',
        '720p50', '720p5994', '720p60',
        '1080p2398', '1080p24', '1080p25', '1080p2997', '1080p30',
        '1080i50', '1080i5994', '1080i60',
        '1080p50', '1080p5994', '1080p60',
    ];
    public const DEFAULT_OUTPUT_MODE = '1080i50';

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function list(): array
    {
        $stmt = $this->db->query("SELECT device_number, role, label, mode FROM decklink_ports ORDER BY device_number");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function update(array $ports): void
    {
        $stmt = $this->db->prepare("UPDATE decklink_ports SET role = :role, label = :label, mode = :mode WHERE device_number = :device_number");
        foreach ($ports as $port) {
            $deviceNumber = (int)($port['device_number'] ?? -1);
            if ($deviceNumber < 0 || $deviceNumber > 3) {
                continue;
            }
            $role = $port['role'] ?? 'unused';
            if (!in_array($role, self::ROLES, true)) {
                $role = 'unused';
            }
            $label = trim((string)($port['label'] ?? ''));

            $mode = $port['mode'] ?? 'auto';
            if (!in_array($mode, self::MODES, true)) {
                $mode = 'auto';
            }
            if ($role === 'output' && $mode === 'auto') {
                // Ein Ausgang kann kein Format "automatisch erkennen" - er muss aktiv eines erzeugen
                $mode = self::DEFAULT_OUTPUT_MODE;
            }

            $stmt->execute([
                ':role' => $role,
                ':label' => $label !== '' ? $label : null,
                ':mode' => $mode,
                ':device_number' => $deviceNumber,
            ]);
        }
    }

    public function getRole(int $deviceNumber): string
    {
        $stmt = $this->db->prepare("SELECT role FROM decklink_ports WHERE device_number = :device_number");
        $stmt->execute([':device_number' => $deviceNumber]);
        $role = $stmt->fetchColumn();
        return $role !== false ? $role : 'unused';
    }

    public function getMode(int $deviceNumber): string
    {
        $stmt = $this->db->prepare("SELECT mode FROM decklink_ports WHERE device_number = :device_number");
        $stmt->execute([':device_number' => $deviceNumber]);
        $mode = $stmt->fetchColumn();
        return ($mode !== false && in_array($mode, self::MODES, true)) ? $mode : 'auto';
    }

    public function assertRole(int $deviceNumber, string $expectedRole): void
    {
        $role = $this->getRole($deviceNumber);
        if ($role !== $expectedRole) {
            $roleLabels = ['input' => 'Eingang', 'output' => 'Ausgang', 'unused' => 'nicht verwendet'];
            $expectedLabel = $roleLabels[$expectedRole] ?? $expectedRole;
            $actualLabel = $roleLabels[$role] ?? $role;
            throw new RuntimeException(
                "SDI-Anschluss {$deviceNumber} ist als \"{$actualLabel}\" konfiguriert, wird hier aber als \"{$expectedLabel}\" benötigt. " .
                "Bitte zuerst unter \"SDI-Anschlüsse\" die Rolle anpassen."
            );
        }
    }
}
