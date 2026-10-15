<?php

/**
 * Text-Bibliothek: Texte (und ihre Gestaltung) vorab eingeben, unter einem Namen speichern und bei Bedarf laden oder direkt
 * einblenden. Zwei Arten:
 *  - 'lowerthird': Felder der Bauchbinde (lt_*, siehe LowerThird.php), z. B. Name, Funktion, Logo, Farbe
 *  - 'text': Felder der einfachen Text-Einblendung (overlay_*)
 * Die Eintraege gelten fuer das ganze System (jeder Ausgang kann sie laden). Die Reihenfolge ist frei (sort), damit sie wie ein
 * Ablauf (Gaeste, Zwischentitel ...) von oben nach unten durchgegangen werden kann. Gespeichert wird nur Bereinigtes; geladen wird
 * ueber die Weboberflaeche in die Formularfelder, angewendet ueber die bekannten, pruefenden Wege (overlay_live, lowerthird_live).
 */
class TextLibrary
{
    private const KINDS = ['lowerthird', 'text'];
    private const MAX_ITEMS = 500;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS text_library (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                kind TEXT NOT NULL,
                name TEXT NOT NULL,
                data TEXT NOT NULL,
                sort INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS text_library_name ON text_library (kind, name COLLATE NOCASE)");
    }

    /** @return array<int, array{id: int, kind: string, name: string, sort: int, data: array, updated_at: string}> */
    public function list(?string $kind = null): array
    {
        if ($kind !== null && !in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unbekannte Art.');
        }
        $sql = 'SELECT id, kind, name, data, sort, updated_at FROM text_library' . ($kind !== null ? ' WHERE kind = :k' : '') .
            ' ORDER BY kind, sort, name COLLATE NOCASE';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($kind !== null ? [':k' => $kind] : []);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['sort'] = (int)$r['sort'];
            $r['data'] = json_decode($r['data'], true) ?: [];
        }
        return $rows;
    }

    /** Daten bereinigen (gleiche Pruefung wie beim Speichern der Pipeline) und auf die Felder der Art beschraenken. */
    private function clean(string $kind, array $data): array
    {
        if ($kind === 'lowerthird') {
            $c = LowerThird::sanitize($data);
            if (!LowerThird::configured($c)) {
                throw new InvalidArgumentException('Die Bauchbinde braucht mindestens einen Text oder ein Logo.');
            }
            return $c;
        }
        $o = PipelineManager::sanitizeOverlay($data);
        if ($o['text'] === null) {
            throw new InvalidArgumentException('Der Text darf nicht leer sein.');
        }
        return [
            'overlay_text' => $o['text'], 'overlay_pos' => $o['pos'], 'overlay_size' => $o['size'],
            'overlay_color' => $o['color'], 'overlay_bg' => $o['bg'],
        ];
    }

    /**
     * Legt einen Eintrag an oder ueberschreibt ihn (nur mit 'overwrite'). Existiert der Name schon und 'overwrite' fehlt, kommt
     * ['exists' => true, 'id' => ...] zurueck, ohne etwas zu aendern.
     */
    public function save(array $in): array
    {
        $kind = (string)($in['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unbekannte Art.');
        }
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '' || !preg_match('//u', $name) || preg_match_all('/./us', $name) > 60) {
            throw new InvalidArgumentException('Der Name muss 1 bis 60 Zeichen lang sein.');
        }
        $name = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name);
        $data = $in['data'] ?? null;
        if (!is_array($data)) {
            throw new InvalidArgumentException('Keine Daten.');
        }
        $json = json_encode($this->clean($kind, $data), JSON_UNESCAPED_UNICODE);

        $stmt = $this->pdo->prepare('SELECT id FROM text_library WHERE kind = :k AND name = :n COLLATE NOCASE');
        $stmt->execute([':k' => $kind, ':n' => $name]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            if (empty($in['overwrite'])) {
                return ['exists' => true, 'id' => (int)$existing];
            }
            $upd = $this->pdo->prepare('UPDATE text_library SET name = :n, data = :d, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $upd->execute([':n' => $name, ':d' => $json, ':id' => $existing]);
            return ['id' => (int)$existing, 'created' => false];
        }
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM text_library')->fetchColumn();
        if ($count >= self::MAX_ITEMS) {
            throw new InvalidArgumentException('Die Bibliothek ist voll (' . self::MAX_ITEMS . ' Eintraege).');
        }
        $max = (int)$this->pdo->query("SELECT COALESCE(MAX(sort), 0) FROM text_library WHERE kind = " . $this->pdo->quote($kind))->fetchColumn();
        $ins = $this->pdo->prepare('INSERT INTO text_library (kind, name, data, sort) VALUES (:k, :n, :d, :s)');
        $ins->execute([':k' => $kind, ':n' => $name, ':d' => $json, ':s' => $max + 1]);
        return ['id' => (int)$this->pdo->lastInsertId(), 'created' => true];
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM text_library WHERE id = :id')->execute([':id' => $id]);
    }

    /** Eintrag in der Reihenfolge einer Art um eine Stelle nach oben ('up') oder unten ('down') schieben. */
    public function move(int $id, string $dir): void
    {
        if (!in_array($dir, ['up', 'down'], true)) {
            throw new InvalidArgumentException('Unbekannte Richtung.');
        }
        $stmt = $this->pdo->prepare('SELECT kind FROM text_library WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $kind = $stmt->fetchColumn();
        if ($kind === false) {
            throw new InvalidArgumentException('Eintrag nicht gefunden.');
        }
        // Reihenfolge der Art neu durchnummerieren (1..n), dann zwei Nachbarn tauschen
        $ids = array_map('intval', array_column($this->list((string)$kind), 'id'));
        $pos = array_search($id, $ids, true);
        $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
        if ($swap >= 0 && $swap < count($ids)) {
            [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
        }
        $upd = $this->pdo->prepare('UPDATE text_library SET sort = :s WHERE id = :id');
        $this->pdo->beginTransaction();
        foreach ($ids as $i => $rowId) {
            $upd->execute([':s' => $i + 1, ':id' => $rowId]);
        }
        $this->pdo->commit();
    }
}
