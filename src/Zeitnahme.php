<?php

require_once __DIR__ . '/ZeitnahmeLogic.php';

/**
 * Speicher der Zeitnahme: ein Dokument (Fahrer, Klassen, Ergebnisse, Sendung, Sponsoren) in SQLite, dazu laufende Sicherungen.
 *
 * Jede Aenderung laeuft in einer eigenen Transaktion mit Schreibsperre (BEGIN IMMEDIATE): lesen, aendern, Revision erhoehen,
 * schreiben. Gleichzeitige Bedienplaetze ueberschreiben sich dadurch nie gegenseitig. Alle 15 Revisionen und vor riskanten
 * Aktionen (neuer Durchgang, Zuruecksetzen) wird eine Sicherung abgelegt; die letzten 60 bleiben erhalten.
 */
class Zeitnahme
{
    private const BACKUP_EVERY = 15;
    private const BACKUP_KEEP = 60;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS zeit_doc (id INTEGER PRIMARY KEY CHECK (id = 1), rev INTEGER NOT NULL, data TEXT NOT NULL, updated_at TEXT)");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS zeit_backup (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, grund TEXT, rev INTEGER, data TEXT NOT NULL)");
    }

    private function load(): array
    {
        $raw = $this->pdo->query('SELECT data FROM zeit_doc WHERE id = 1')->fetchColumn();
        $D = $raw === false ? [] : (json_decode((string)$raw, true) ?: []);
        ZeitnahmeLogic::normalisieren($D);
        return $D;
    }

    private function save(array $D): void
    {
        $json = json_encode($D, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $st = $this->pdo->prepare("INSERT INTO zeit_doc (id, rev, data, updated_at) VALUES (1, :r, :d, datetime('now'))
            ON CONFLICT(id) DO UPDATE SET rev = :r, data = :d, updated_at = datetime('now')");
        $st->execute([':r' => $D['rev'], ':d' => $json]);
    }

    private function backup(array $D, string $grund): void
    {
        $json = json_encode($D, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $this->pdo->prepare("INSERT INTO zeit_backup (created_at, grund, rev, data) VALUES (datetime('now'), :g, :r, :d)")
            ->execute([':g' => $grund, ':r' => $D['rev'], ':d' => $json]);
        $this->pdo->exec('DELETE FROM zeit_backup WHERE id NOT IN (SELECT id FROM zeit_backup ORDER BY id DESC LIMIT ' . self::BACKUP_KEEP . ')');
    }

    /** Dokument lesen (ohne Sperre). */
    public function dokument(): array
    {
        return $this->load();
    }

    /**
     * Aktion ausfuehren und speichern. Bei Fehlern (ZeitFehler) bleibt alles unveraendert.
     * @return array Ergebnis der Aktion (zum Beispiel ['id' => 5]) plus 'rev'
     */
    public function aktion(array $a): array
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $D = $this->load();
            $grund = null;
            $res = ZeitnahmeLogic::aktion($D, $a, function (string $g) use (&$D, &$grund) {
                // Sicherung des Standes vor der Aktion
                $this->backup($D, $g);
                $grund = $g;
            });
            $D['rev']++;
            if ($grund === null && $D['rev'] % self::BACKUP_EVERY === 0) {
                $this->backup($D, 'auto');
            }
            $this->save($D);
            $this->pdo->exec('COMMIT');
            return $res + ['rev' => $D['rev']];
        } catch (Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable $e2) {
            }
            throw $e;
        }
    }

    /** Ganzes Dokument ersetzen (Wiederherstellen einer Sicherung, Import). */
    public function ersetzen(array $D, string $grund = 'ersetzen'): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $alt = $this->load();
            $this->backup($alt, 'vor-' . $grund);
            ZeitnahmeLogic::normalisieren($D);
            $D['rev'] = max($alt['rev'], (int)($D['rev'] ?? 0)) + 1;
            $this->save($D);
            $this->pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable $e2) {
            }
            throw $e;
        }
    }

    public function zustand(): array
    {
        return ZeitnahmeLogic::zustand($this->load());
    }

    public function grafik(): array
    {
        return ZeitnahmeLogic::grafik($this->load());
    }

    /** @return array<int, array{id:int, created_at:string, grund:string, rev:int}> */
    public function sicherungen(): array
    {
        return $this->pdo->query('SELECT id, created_at, grund, rev FROM zeit_backup ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    // ----- Zugangsschluessel fuer das Messsystem (Weite per Link melden) -------------------------------------------------

    private static function keyPath(): string
    {
        return __DIR__ . '/../data/zeit_key.txt';
    }

    /** Schluessel lesen; wird beim ersten Aufruf (oder mit $neu) erzeugt. */
    public static function schluessel(bool $neu = false): string
    {
        $k = trim((string)@file_get_contents(self::keyPath()));
        if ($neu || $k === '') {
            $k = bin2hex(random_bytes(12));
            $tmp = self::keyPath() . '.tmp';
            @file_put_contents($tmp, $k, LOCK_EX);
            @chmod($tmp, 0600);
            @rename($tmp, self::keyPath());
        }
        return $k;
    }
}
