<?php

require_once __DIR__ . '/Compat.php';
require_once __DIR__ . '/GraphicsLibrary.php';

/**
 * Bibliothek gespeicherter Grafik-Eintraege: Texte und Einstellungen einer Vorlage (oder eines Elements der Szene) vorab
 * eingeben, unter einem Namen speichern und bei Bedarf laden oder direkt einblenden.
 *
 * Ein Eintrag gehoert zu einer Vorlage ('template') und bei der Szene zusaetzlich zu einem Element ('grp', zum Beispiel
 * "lt" fuer die Bauchbinde). Gespeichert werden nur Felder, die das Manifest der Vorlage kennt, jeweils bereinigt; die
 * Schalter "Sichtbar" und der Ausloese-Zaehler gehoeren nicht zum Inhalt und werden nie gespeichert. Die Reihenfolge ist frei
 * (sort), damit sie wie ein Ablauf von oben nach unten durchgegangen werden kann. Die Eintraege gelten fuer das ganze System.
 */
class GraphicsEntries
{
    private const MAX_ITEMS = 1000;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS gfx_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                template TEXT NOT NULL,
                grp TEXT NOT NULL DEFAULT '',
                name TEXT NOT NULL,
                data TEXT NOT NULL,
                sort INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS gfx_entries_name ON gfx_entries (template, grp, name COLLATE NOCASE)");
    }

    /** @return array<int, array{id:int, template:string, grp:string, name:string, sort:int, data:array, updated_at:string}> */
    public function list(?string $template = null): array
    {
        $sql = 'SELECT id, template, grp, name, data, sort, updated_at FROM gfx_entries' . ($template !== null ? ' WHERE template = :t' : '')
            . ' ORDER BY template, grp, sort, name COLLATE NOCASE';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($template !== null ? [':t' => $template] : []);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['sort'] = (int)$r['sort'];
            $r['data'] = json_decode($r['data'], true) ?: [];
        }
        return $rows;
    }

    /** Felder, die zu einem Element der Szene gehoeren (oder alle Felder einer einfachen Vorlage), ohne Schalter und Zaehler. */
    private static function contentKeys(array $manifest, string $grp): array
    {
        $skip = [];
        foreach ($manifest['groups'] as $g) {
            foreach (['toggle', 'trigger'] as $k) {
                if (isset($g[$k])) {
                    $skip[$g[$k]] = true;
                }
            }
        }
        $keys = [];
        foreach ($manifest['fields'] as $f) {
            if (isset($skip[$f['key']])) {
                continue;
            }
            if ($grp !== '' && ($f['group'] ?? '') !== $grp) {
                continue;
            }
            $keys[$f['key']] = true;
        }
        return $keys;
    }

    private static function cleanName($raw): string
    {
        $name = trim((string)$raw);
        if ($name === '' || !preg_match('//u', $name) || mb_strlen($name) > 60) {
            throw new InvalidArgumentException('Der Name muss 1 bis 60 Zeichen lang sein.');
        }
        return (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name);
    }

    /** Eingabe pruefen: Vorlage und Element bekannt, Felder bereinigt, nur Inhaltsfelder. */
    private static function clean(string $template, string $grp, $data): array
    {
        $manifest = GraphicsLibrary::manifest($template);
        if ($manifest === null) {
            throw new InvalidArgumentException('Unbekannte Vorlage.');
        }
        if ($grp !== '' && !in_array($grp, array_column($manifest['groups'], 'id'), true)) {
            throw new InvalidArgumentException('Unbekanntes Element der Vorlage.');
        }
        if ($grp === '' && $manifest['groups']) {
            throw new InvalidArgumentException('Bei dieser Vorlage muss ein Element gewaehlt werden.');
        }
        if (!is_array($data)) {
            throw new InvalidArgumentException('Keine Daten.');
        }
        $keys = self::contentKeys($manifest, $grp);
        $given = array_intersect_key($data, $keys);
        if (!$given) {
            throw new InvalidArgumentException('Der Eintrag enthaelt keine Felder.');
        }
        $clean = GraphicsLibrary::sanitizeFields($manifest, $given);
        return array_intersect_key($clean, $given);
    }

    /**
     * Legt einen Eintrag an oder ueberschreibt ihn (nur mit 'overwrite'). Existiert der Name schon und 'overwrite' fehlt, kommt
     * ['exists' => true, 'id' => ...] zurueck, ohne etwas zu aendern.
     */
    public function save(array $in): array
    {
        $template = (string)($in['template'] ?? '');
        $grp = (string)($in['grp'] ?? '');
        $name = self::cleanName($in['name'] ?? '');
        $json = json_encode(self::clean($template, $grp, $in['data'] ?? null), JSON_UNESCAPED_UNICODE);

        $stmt = $this->pdo->prepare('SELECT id FROM gfx_entries WHERE template = :t AND grp = :g AND name = :n COLLATE NOCASE');
        $stmt->execute([':t' => $template, ':g' => $grp, ':n' => $name]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            if (empty($in['overwrite'])) {
                return ['exists' => true, 'id' => (int)$existing];
            }
            $upd = $this->pdo->prepare('UPDATE gfx_entries SET name = :n, data = :d, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $upd->execute([':n' => $name, ':d' => $json, ':id' => $existing]);
            return ['id' => (int)$existing, 'created' => false];
        }
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM gfx_entries')->fetchColumn();
        if ($count >= self::MAX_ITEMS) {
            throw new InvalidArgumentException('Die Bibliothek ist voll (' . self::MAX_ITEMS . ' Eintraege).');
        }
        $q = $this->pdo->prepare('SELECT COALESCE(MAX(sort), 0) FROM gfx_entries WHERE template = :t AND grp = :g');
        $q->execute([':t' => $template, ':g' => $grp]);
        $ins = $this->pdo->prepare('INSERT INTO gfx_entries (template, grp, name, data, sort) VALUES (:t, :g, :n, :d, :s)');
        $ins->execute([':t' => $template, ':g' => $grp, ':n' => $name, ':d' => $json, ':s' => (int)$q->fetchColumn() + 1]);
        return ['id' => (int)$this->pdo->lastInsertId(), 'created' => true];
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM gfx_entries WHERE id = :id')->execute([':id' => $id]);
    }

    /** Eintrag innerhalb seiner Vorlage/seines Elements um eine Stelle nach oben ('up') oder unten ('down') schieben. */
    public function move(int $id, string $dir): void
    {
        if (!in_array($dir, ['up', 'down'], true)) {
            throw new InvalidArgumentException('Unbekannte Richtung.');
        }
        $stmt = $this->pdo->prepare('SELECT template, grp FROM gfx_entries WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Eintrag nicht gefunden.');
        }
        $ids = [];
        foreach ($this->list((string)$row['template']) as $e) {
            if ($e['grp'] === $row['grp']) {
                $ids[] = $e['id'];
            }
        }
        $pos = array_search($id, $ids, true);
        $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
        if ($swap >= 0 && $swap < count($ids)) {
            [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
        }
        $upd = $this->pdo->prepare('UPDATE gfx_entries SET sort = :s WHERE id = :id');
        $this->pdo->beginTransaction();
        foreach ($ids as $i => $rowId) {
            $upd->execute([':s' => $i + 1, ':id' => $rowId]);
        }
        $this->pdo->commit();
    }

    /**
     * Mehrere Eintraege auf einmal anlegen (Import). Jeder wird wie beim Speichern geprueft; ungueltige werden uebersprungen,
     * gleichnamige nicht ueberschrieben.
     * @return array{added:int, skipped:int}
     */
    public function import(array $items): array
    {
        $added = 0;
        $skipped = 0;
        foreach (array_slice($items, 0, 500) as $item) {
            try {
                $r = is_array($item) ? $this->save([
                    'template' => $item['template'] ?? '', 'grp' => $item['grp'] ?? '', 'name' => $item['name'] ?? '', 'data' => $item['data'] ?? null,
                ]) : ['exists' => true];
                empty($r['exists']) ? $added++ : $skipped++;
            } catch (Throwable $e) {
                $skipped++;
            }
        }
        return ['added' => $added, 'skipped' => $skipped];
    }
}
