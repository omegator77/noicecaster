<?php

/**
 * Presets: komplette Einstellungen eines SDI-Anschlusses (Ziel 1 plus weitere Ziele), die der Nutzer
 * unter einem Namen ablegt und spaeter in einen Anschluss derselben Richtung laedt.
 *
 * Die Daten liegen als JSON in der Tabelle presets: {"tile": {...Formular von Ziel 1...}, "targets": [{...}, ...]}.
 * Das Gateway wertet den Inhalt nicht selbst aus; beim Laden fuellt die Weboberflaeche das Formular, und
 * erst das Speichern laeuft ueber die bekannten, pruefenden Wege (create/update, target_create/target_update).
 * Achtung: Presets enthalten die SRT-Passphrase im Klartext.
 */
class PresetManager
{
    private const DIRECTIONS = ['sdi_to_srt', 'srt_to_sdi'];
    private const MAX_TARGETS = 16;
    private const MAX_BYTES = 200000;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS presets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                direction TEXT NOT NULL,
                data TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function list(): array
    {
        $rows = $this->pdo->query("SELECT id, name, direction, data, updated_at FROM presets ORDER BY name COLLATE NOCASE")
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['data'] = json_decode($row['data'], true) ?: ['tile' => [], 'targets' => []];
        }
        return $rows;
    }

    /**
     * Legt ein Preset an oder ueberschreibt es (nur mit $overwrite). Existiert der Name schon und ist
     * $overwrite nicht gesetzt, kommt ['exists' => true] zurueck, ohne etwas zu aendern.
     */
    public function save(array $in): array
    {
        $name = trim((string)($in['name'] ?? ''));
        // ohne mbstring: gueltiges UTF-8 und Zeichen (nicht Bytes) zaehlen
        if ($name === '' || !preg_match('//u', $name) || preg_match_all('/./us', $name) > 60) {
            throw new InvalidArgumentException('Der Name des Presets muss 1 bis 60 Zeichen lang sein.');
        }
        $direction = (string)($in['direction'] ?? '');
        if (!in_array($direction, self::DIRECTIONS, true)) {
            throw new InvalidArgumentException('Unbekannte Richtung.');
        }
        $data = $in['data'] ?? null;
        if (!is_array($data) || !isset($data['tile']) || !is_array($data['tile'])) {
            throw new InvalidArgumentException('Das Preset enthaelt keine Anschluss-Einstellungen.');
        }
        $targets = $data['targets'] ?? [];
        if (!is_array($targets) || count($targets) > self::MAX_TARGETS) {
            throw new InvalidArgumentException('Zu viele Ziele im Preset.');
        }
        foreach ($targets as $t) {
            if (!is_array($t)) {
                throw new InvalidArgumentException('Ungueltiges Ziel im Preset.');
            }
        }
        $clean = ['tile' => $data['tile'], 'targets' => array_values($targets)];
        unset($clean['tile']['id']);
        foreach ($clean['targets'] as &$t) {
            unset($t['id'], $t['pipeline_id'], $t['pid'], $t['status'], $t['started_at'], $t['created_at']);
        }
        unset($t);
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Das Preset ist zu gross.');
        }

        $stmt = $this->pdo->prepare("SELECT id, direction FROM presets WHERE name = :name COLLATE NOCASE");
        $stmt->execute([':name' => $name]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (empty($in['overwrite'])) {
                return ['exists' => true, 'id' => (int)$existing['id']];
            }
            $upd = $this->pdo->prepare("UPDATE presets SET name = :name, direction = :dir, data = :data, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $upd->execute([':name' => $name, ':dir' => $direction, ':data' => $json, ':id' => $existing['id']]);
            return ['id' => (int)$existing['id'], 'created' => false];
        }
        $ins = $this->pdo->prepare("INSERT INTO presets (name, direction, data) VALUES (:name, :dir, :data)");
        $ins->execute([':name' => $name, ':dir' => $direction, ':data' => $json]);
        return ['id' => (int)$this->pdo->lastInsertId(), 'created' => true];
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM presets WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }
}
