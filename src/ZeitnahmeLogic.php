<?php

require_once __DIR__ . '/Compat.php';

/** Fehler mit einer Meldung, die der Bediener lesen soll (im Original: throw 'Text'). */
final class ZeitFehler extends RuntimeException
{
}

/**
 * Zeitnahme und Ranking fuer Veranstaltungen (Traktor-Pulling): Fahrer, Klassen, Weiten, Wertung mit Full Pull und Stechen,
 * Sendung (was im Bild eingeblendet ist) und die Daten fuer das Grafikpaket.
 *
 * Reine Logik ohne Datenbank und ohne Ausgabe: alle Funktionen arbeiten auf dem Zustandsdokument $D (ein Array, im Original
 * ein JSON-Objekt) und aendern es direkt. Gespeichert wird in Zeitnahme.php. Die Logik ist eine Uebertragung von server.js des
 * Windows-Werkzeugs "tpf-zeitnahme"; Verhalten und Fehlermeldungen sind gleich gehalten und werden in Tests gegen das Original
 * verglichen (scripts/zeit_*). Stellen, an denen PHP und JavaScript verschieden rechnen, sind hier ausdruecklich nachgebaut:
 *  - rund(): Math.round(x * 100) / 100 (halbe Werte nach oben) statt PHP-round
 *  - zahl()/pInt(): parseFloat/parseInt lesen den Anfang eines Textes ("12abc" -> 12), PHP-Umwandlung taete es anders
 *  - Schluessel von Weiten sind Texte (ein Float als Array-Schluessel wuerde abgeschnitten)
 *  - Vergleiche liefern nur das Vorzeichen (usort wuerde 0,01 zu 0 abschneiden)
 */
final class ZeitnahmeLogic
{
    // ---------------------------------------------------------------- Grundbausteine

    /** JavaScript: String(v).trim() mit null/undefined als leerem Text. */
    public static function t($v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_array($v)) {
            return '';
        }
        $s = is_float($v) ? self::jsNum($v) : (string)$v;
        return preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u', '', $s) ?? $s;
    }

    /** Zahl so als Text, wie JavaScript String(zahl) sie schreibt (37 -> "37", 37.5 -> "37.5"). */
    public static function jsNum($v): string
    {
        if (is_int($v)) {
            return (string)$v;
        }
        $f = (float)$v;
        if (is_nan($f) || is_infinite($f)) {
            return is_nan($f) ? 'NaN' : ($f > 0 ? 'Infinity' : '-Infinity');
        }
        if ($f == floor($f) && abs($f) < 1e15) {
            return (string)(int)$f;
        }
        return (string)json_encode($f);
    }

    public static function isNum($v): bool
    {
        return (is_int($v) || is_float($v)) && is_finite((float)$v);
    }

    /** parseFloat auf dem Anfang eines Textes; null, wenn keine Zahl am Anfang steht (NaN). */
    private static function parseFloatPrefix(string $s): ?float
    {
        if (preg_match('/^\s*([+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?)/', $s, $m)) {
            return (float)$m[1];
        }
        return null;
    }

    /** Eingabe als Zahl (Komma wie Punkt) oder null. */
    public static function zahl($v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return is_finite((float)$v) ? (float)$v : null;
        }
        $t = self::t($v);
        $t = preg_replace('/,/', '.', $t, 1) ?? $t;
        if ($t === '') {
            return null;
        }
        $n = self::parseFloatPrefix($t);
        return ($n !== null && is_finite($n)) ? $n : null;
    }

    /** parseInt(v, 10): ganze Zahl vom Anfang des Textes, sonst null (NaN). */
    public static function pInt($v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return is_finite($v) ? (int)$v : null;
        }
        if (is_bool($v) || $v === null || is_array($v)) {
            return null;
        }
        if (preg_match('/^\s*([+-]?\d+)/', (string)$v, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /** parseInt(v) || 0, mindestens 0 (Math.max(0, parseInt(v,10) || 0)). */
    private static function lauf($v): int
    {
        return max(0, self::pInt($v) ?? 0);
    }

    /** Math.round(x * 100) / 100 */
    public static function rund($x): float
    {
        return floor((float)$x * 100 + 0.5) / 100;
    }

    /** toFixed(2) fuer bereits auf 2 Stellen gerundete Werte. */
    private static function fix2($w): string
    {
        return number_format((float)$w, 2, '.', '');
    }

    /** Kleinschreibung fuer Namensvergleiche (JavaScript toLowerCase; mbstring gibt es auf den Servern nicht). */
    public static function lower(string $s): string
    {
        return strtr(strtolower($s), ['Ä' => 'ä', 'Ö' => 'ö', 'Ü' => 'ü', 'ẞ' => 'ß', 'É' => 'é', 'È' => 'è', 'À' => 'à']);
    }

    private static function key(float $w): string
    {
        return sprintf('%.2F', $w);
    }

    private static function iso(): string
    {
        $m = (int)floor(microtime(true) * 1000);
        return gmdate('Y-m-d\TH:i:s', intdiv($m, 1000)) . sprintf('.%03dZ', $m % 1000);
    }

    // ---------------------------------------------------------------- Zustand

    public static function sendungStandard(): array
    {
        return [
            'show' => ['bug' => true, 'sponsors' => true, 'puller' => false, 'next' => false, 'klasse' => false, 'ranking' => false,
                'sieger' => false, 'pause' => false, 'slomo' => false, 'aufruf' => false, 'bauchbinde' => false],
            'aufruf' => ['kopf' => 'Durchsage', 'text' => 'Alle Fahrer bitte zum Wiegen'],
            'bb' => ['label' => 'Interview', 'name' => '', 'funktion' => ''],
            'sp' => ['slomo' => '', 'fullPull' => '', 'klasse' => '', 'ranking' => '', 'sieger' => '', 'bb' => ''],
            'balken' => [],
        ];
    }

    public static function leer(): array
    {
        $sendung = self::sendungStandard();
        $sendung['sp'] = ['slomo' => 1, 'fullPull' => 1, 'klasse' => 1, 'ranking' => 2, 'sieger' => 1, 'bb' => 2];
        $sendung['balken'] = [1, 2];
        return [
            'v' => 1,
            'rev' => 0,
            'nextId' => 3,
            'event' => ['name' => 'Traktor-Pulling Fahrenbach', 'kurz' => 'TPF26', 'fullPull' => 100],
            'klassen' => [],
            'starter' => [],
            'aktuell' => ['klasseId' => null, 'starterId' => null, 'lauf' => 0],
            'live' => ['distanz' => 0, 'bremswagen' => ''],
            'sponsoren' => [
                ['id' => 1, 'name' => 'Autohaus Beispiel', 'claim' => 'Ihr Partner vor Ort', 'paket' => '', 'logo' => ''],
                ['id' => 2, 'name' => 'Landtechnik Muster', 'claim' => 'Technik, die zieht', 'paket' => '', 'logo' => ''],
            ],
            'sendung' => $sendung,
        ];
    }

    /** Geladenes Dokument auf den aktuellen Stand bringen (fehlende Felder, aeltere Formen): sendungPruefen + stechenMigrieren. */
    public static function normalisieren(array &$D): void
    {
        $D = array_replace(self::leer(), $D);
        $st = self::sendungStandard();
        $sd = is_array($D['sendung'] ?? null) ? $D['sendung'] : [];
        foreach (['show', 'aufruf', 'bb', 'sp'] as $k) {
            $sd[$k] = array_replace($st[$k], is_array($sd[$k] ?? null) ? $sd[$k] : []);
        }
        $D['sendung'] = $sd;
        if (!is_array($D['sponsoren'] ?? null)) {
            $D['sponsoren'] = [];
        }
        $finde = function ($name, $claim = '') use (&$D) {
            $name = self::t($name);
            if ($name === '') {
                return '';
            }
            foreach ($D['sponsoren'] as $y) {
                if (self::lower($y['name']) === self::lower($name)) {
                    return $y['id'];
                }
            }
            $x = ['id' => $D['nextId']++, 'name' => $name, 'claim' => self::t($claim), 'paket' => '', 'logo' => ''];
            $D['sponsoren'][] = $x;
            return $x['id'];
        };
        foreach ($D['sendung']['sp'] as $k => $v) {
            if (is_string($v) && $v !== '' && !preg_match('/^\d+$/', $v)) {
                $D['sendung']['sp'][$k] = $finde($v);
            }
        }
        if (!is_array($D['sendung']['balken'] ?? null)) {
            $D['sendung']['balken'] = [];
            if (is_array($D['sendung']['liste'] ?? null)) {
                foreach ($D['sendung']['liste'] as $x) {
                    $i = $finde($x['name'] ?? '', $x['claim'] ?? '');
                    if ($i !== '' && $i !== 0 && $i !== null) {
                        $D['sendung']['balken'][] = $i;
                    }
                }
            }
        }
        unset($D['sendung']['liste']);
        foreach ($D['starter'] as &$s) {
            if (!is_array($s['stechen'] ?? null)) {
                $s['stechen'] = [];
                continue;
            }
            $s['stechen'] = array_map(function ($x) {
                if (is_int($x) || is_float($x)) {
                    return ['status' => 'ok', 'weite' => $x, 'bremswagen' => '', 'zeit' => null];
                }
                return is_array($x) ? $x : null;
            }, $s['stechen']);
        }
        unset($s);
        $D['aktuell'] = array_replace(['klasseId' => null, 'starterId' => null, 'lauf' => 0], is_array($D['aktuell'] ?? null) ? $D['aktuell'] : []);
    }

    // ---------------------------------------------------------------- Zugriffe

    public static function klasse(array $D, $id): ?array
    {
        foreach ($D['klassen'] as $k) {
            if ($k['id'] === $id) {
                return $k;
            }
        }
        return null;
    }

    private static function klasseIndex(array $D, $id): ?int
    {
        foreach ($D['klassen'] as $i => $k) {
            if ($k['id'] === $id) {
                return $i;
            }
        }
        return null;
    }

    public static function starter(array $D, $id): ?array
    {
        foreach ($D['starter'] as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }
        return null;
    }

    private static function starterIndex(array $D, $id): ?int
    {
        foreach ($D['starter'] as $i => $s) {
            if ($s['id'] === $id) {
                return $i;
            }
        }
        return null;
    }

    /** Fahrer einer Klasse nach Reihenfolge (reihe, dann id). */
    public static function inKlasse(array $D, $kid): array
    {
        $l = array_values(array_filter($D['starter'], fn($s) => $s['klasseId'] === $kid));
        usort($l, fn($a, $b) => ($a['reihe'] <=> $b['reihe']) ?: ($a['id'] <=> $b['id']));
        return $l;
    }

    // ---------------------------------------------------------------- Laeufe (0 = Hauptlauf, n = Stechen n)

    public static function ergOf(array $s, int $lauf): ?array
    {
        if ($lauf > 0) {
            $e = $s['stechen'][$lauf - 1] ?? null;
            return is_array($e) ? $e : null;
        }
        $e = $s['ergebnis'] ?? null;
        return is_array($e) ? $e : null;
    }

    private static function setErg(array &$s, int $lauf, ?array $e): void
    {
        if ($lauf > 0) {
            $s['stechen'] = $s['stechen'] ?? [];
            while (count($s['stechen']) < $lauf - 1) {
                $s['stechen'][] = null;
            }
            $s['stechen'][$lauf - 1] = $e;
            while (count($s['stechen']) && !end($s['stechen'])) {
                array_pop($s['stechen']);
            }
        } else {
            $s['ergebnis'] = $e;
        }
    }

    // ---------------------------------------------------------------- Wertung

    private static function ok(array $s): bool
    {
        $e = $s['ergebnis'] ?? null;
        return is_array($e) && ($e['status'] ?? null) === 'ok' && self::isNumRaw($e['weite'] ?? null);
    }

    /** typeof x === 'number' */
    private static function isNumRaw($v): bool
    {
        return is_int($v) || is_float($v);
    }

    private static function schluessel(array $D, array $s): array
    {
        $w = self::rund($s['ergebnis']['weite']);
        $k = [$w];
        if ($w >= $D['event']['fullPull']) {
            foreach (($s['stechen'] ?? []) as $x) {
                $k[] = (is_array($x) && ($x['status'] ?? null) === 'ok' && self::isNumRaw($x['weite'] ?? null)) ? self::rund($x['weite']) : -1;
            }
        }
        return $k;
    }

    /** Vergleich zweier Schluessel: absteigend; liefert nur das Vorzeichen. */
    private static function vergleich(array $a, array $b): int
    {
        $n = max(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $x = $a[$i] ?? -1;
            $y = $b[$i] ?? -1;
            if ($x != $y) {
                return $y <=> $x;
            }
        }
        return 0;
    }

    public static function rangliste(array $D, $kid): array
    {
        $alle = self::inKlasse($D, $kid);
        $gueltig = [];
        foreach ($alle as $s) {
            if (self::ok($s)) {
                $gueltig[] = ['s' => $s, 'k' => self::schluessel($D, $s)];
            }
        }
        usort($gueltig, fn($a, $b) => self::vergleich($a['k'], $b['k']) ?: ($a['s']['reihe'] <=> $b['s']['reihe']));
        $rows = [];
        foreach ($gueltig as $i => $g) {
            $gleich = $i > 0 && self::vergleich($gueltig[$i - 1]['k'], $g['k']) === 0;
            $platz = $gleich ? $rows[$i - 1]['platz'] : $i + 1;
            $w = self::rund($g['s']['ergebnis']['weite']);
            $rows[] = ['platz' => $platz, 'starterId' => $g['s']['id'], 'weite' => $w, 'status' => 'ok',
                'fullPull' => $w >= $D['event']['fullPull']];
        }
        foreach ($alle as $s) {
            if (is_array($s['ergebnis'] ?? null) && $s['ergebnis']['status'] === 'dq') {
                $rows[] = ['platz' => null, 'starterId' => $s['id'], 'weite' => null, 'status' => 'dq', 'fullPull' => false];
            }
        }
        foreach ($alle as $s) {
            if (is_array($s['ergebnis'] ?? null) && $s['ergebnis']['status'] === 'na') {
                $rows[] = ['platz' => null, 'starterId' => $s['id'], 'weite' => null, 'status' => 'na', 'fullPull' => false];
            }
        }
        foreach ($alle as $s) {
            if (!is_array($s['ergebnis'] ?? null)) {
                $rows[] = ['platz' => null, 'starterId' => $s['id'], 'weite' => null, 'status' => 'offen', 'fullPull' => false];
            }
        }

        // Stechen: wer muss in welchem Lauf noch ziehen? (gleiche Weite -> Stechen 1; wieder gleich -> Stechen 2 ...)
        $fps = [];
        foreach ($gueltig as $g) {
            if ($g['k'][0] >= $D['event']['fullPull']) {
                $fps[] = $g['s'];
            }
        }
        $wartend = [];   // [ ['nr' => , 'ids' => []] ]
        $nachWeite = [];
        foreach ($fps as $x) {
            $w = self::rund($x['ergebnis']['weite']);
            $kk = self::key($w);
            if (!isset($nachWeite[$kk])) {
                $nachWeite[$kk] = [];
            }
            $nachWeite[$kk][] = $x;
        }
        $teilen = null;
        $teilen = function (array $gruppe, int $r) use (&$teilen, &$wartend) {
            if (count($gruppe) < 2) {
                return;
            }
            foreach ($gruppe as $x) {
                if (self::ergOf($x, $r + 1) === null) {
                    $wartend[] = ['nr' => $r + 1, 'ids' => array_map(fn($y) => $y['id'], $gruppe)];
                    return;
                }
            }
            $m = [];
            foreach ($gruppe as $x) {
                $e = self::ergOf($x, $r + 1);
                $v = ($e['status'] === 'ok' && self::isNumRaw($e['weite'] ?? null)) ? self::rund($e['weite']) : -1.0;
                $kk = self::key((float)$v);
                if (!isset($m[$kk])) {
                    $m[$kk] = ['v' => $v, 'liste' => []];
                }
                $m[$kk]['liste'][] = $x;
            }
            foreach ($m as $entry) {
                if ($entry['v'] != -1) {
                    $teilen($entry['liste'], $r + 1);
                }
            }
        };
        foreach ($nachWeite as $g) {
            $teilen($g, 0);
        }
        // Plaetze: wer noch im Stechen "wartet", teilt sich den Platz
        $inWartend = [];
        foreach ($wartend as $gi => $w) {
            foreach ($w['ids'] as $i) {
                $inWartend[$i] = $gi;
            }
        }
        for ($i = 1; $i < count($rows); $i++) {
            if ($rows[$i]['status'] !== 'ok') {
                continue;
            }
            $a = $inWartend[$rows[$i - 1]['starterId']] ?? null;
            $b = $inWartend[$rows[$i]['starterId']] ?? null;
            if ($a !== null && $a === $b) {
                $rows[$i]['platz'] = $rows[$i - 1]['platz'];
            }
        }
        // Uebersicht der Stechen-Laeufe fuer die Oberflaeche
        $maxNr = 0;
        foreach ($fps as $x) {
            $maxNr = max($maxNr, count($x['stechen'] ?? []));
        }
        foreach ($wartend as $w) {
            $maxNr = max($maxNr, $w['nr']);
        }
        $stechenLaeufe = [];
        for ($nr = 1; $nr <= $maxNr; $nr++) {
            $ids = [];
            foreach ($fps as $x) {
                if (self::ergOf($x, $nr) !== null) {
                    $ids[$x['id']] = true;
                }
            }
            foreach ($wartend as $w) {
                if ($w['nr'] === $nr) {
                    foreach ($w['ids'] as $i) {
                        $ids[$i] = true;
                    }
                }
            }
            $idList = array_keys($ids);
            $offen = 0;
            foreach ($idList as $i) {
                if (self::ergOf(self::starter($D, $i), $nr) === null) {
                    $offen++;
                }
            }
            $stechenLaeufe[] = ['nr' => $nr, 'ids' => $idList, 'offen' => $offen];
        }
        return [
            'rows' => $rows,
            'stechenGruppen' => array_map(fn($w) => $w['ids'], $wartend),
            'stechenWartend' => $wartend,
            'stechenLaeufe' => $stechenLaeufe,
            'fullPuller' => array_map(fn($x) => $x['id'], $fps),
            'offen' => count(array_filter($rows, fn($r) => $r['status'] === 'offen')),
        ];
    }

    // ---------------------------------------------------------------- Hilfen fuer Aktionen

    private static function id(array &$D): int
    {
        return $D['nextId']++;
    }

    private static function naechsteStartnr(array $D): int
    {
        $m = 0;
        foreach ($D['starter'] as $s) {
            if ($s['startnr'] > $m) {
                $m = $s['startnr'];
            }
        }
        return $m + 1;
    }

    private static function neueReihe(array $D, $kid): int
    {
        $m = 0;
        foreach ($D['starter'] as $s) {
            if ($s['klasseId'] === $kid && $s['reihe'] > $m) {
                $m = $s['reihe'];
            }
        }
        return $m + 1;
    }

    private static function klasseFinden(array &$D, $name, $info = ''): array
    {
        $n = self::t($name);
        foreach ($D['klassen'] as $x) {
            if (self::lower($x['name']) === self::lower($n)) {
                return $x;
            }
        }
        $k = ['id' => self::id($D), 'name' => $n, 'info' => self::t($info)];
        $D['klassen'][] = $k;
        return $k;
    }

    /** Wie ein Sponsor angezeigt wird: 'logo' (nur Bild), 'beides' (Bild + Name), 'text' (nur Name). */
    public static function zeigeArt(array $x): string
    {
        if (empty($x['logo'])) {
            return 'text';
        }
        $z = $x['zeige'] ?? null;
        return ($z === 'beides' || $z === 'text') ? $z : 'logo';
    }

    private static function logoPruefen($l): string
    {
        $l = (string)$l;
        if (!preg_match('/^data:image\/(png|jpeg|webp|svg\+xml);base64,[A-Za-z0-9+\/=]+$/', $l)) {
            throw new ZeitFehler('Das Logo muss eine Bilddatei sein (PNG, JPG oder SVG).');
        }
        if (strlen($l) > 600000) {
            throw new ZeitFehler('Das Logo ist zu gross (max. ca. 400 KB).');
        }
        return $l;
    }

    private static function has(array $a, string $k): bool
    {
        return array_key_exists($k, $a);
    }

    // ---------------------------------------------------------------- Aktionen

    /**
     * Eine Aktion ausfuehren und das Dokument aendern. Gibt das Ergebnis der Aktion zurueck (zum Beispiel ['id' => 5]).
     * $backup($grund) wird aufgerufen, wo das Original eine Sicherung anlegt (vor neuem Durchgang und vor dem Zuruecksetzen).
     * @throws ZeitFehler mit Meldung fuer den Bediener
     */
    public static function aktion(array &$D, array $a, ?callable $backup = null): array
    {
        $typ = $a['typ'] ?? null;
        switch ($typ) {
            case 'klasseNeu':
                if (self::t($a['name'] ?? null) === '') {
                    throw new ZeitFehler('Bitte einen Namen eingeben.');
                }
                $k = self::klasseFinden($D, $a['name'], $a['info'] ?? null);
                if (!$D['aktuell']['klasseId']) {
                    $D['aktuell']['klasseId'] = $k['id'];
                }
                return ['id' => $k['id']];

            case 'klasseAendern':
                $i = self::klasseIndex($D, $a['id'] ?? null);
                if ($i === null) {
                    throw new ZeitFehler('Klasse nicht gefunden.');
                }
                if (self::has($a, 'name')) {
                    $D['klassen'][$i]['name'] = self::t($a['name']) ?: $D['klassen'][$i]['name'];
                }
                if (self::has($a, 'info')) {
                    $D['klassen'][$i]['info'] = self::t($a['info']);
                }
                return [];

            case 'klasseLoeschen':
                $id = $a['id'] ?? null;
                if (count(self::inKlasse($D, $id))) {
                    throw new ZeitFehler('In dieser Klasse sind noch Fahrer angemeldet.');
                }
                $D['klassen'] = array_values(array_filter($D['klassen'], fn($k) => $k['id'] !== $id));
                if ($D['aktuell']['klasseId'] === $id) {
                    $D['aktuell'] = ['klasseId' => $D['klassen'][0]['id'] ?? null, 'starterId' => null, 'lauf' => 0];
                }
                return [];

            case 'starterNeu':
                if (self::t($a['name'] ?? null) === '') {
                    throw new ZeitFehler('Bitte den Namen des Fahrers eingeben.');
                }
                if (self::klasse($D, $a['klasseId'] ?? null) === null) {
                    throw new ZeitFehler('Bitte eine Klasse waehlen.');
                }
                $nr = self::pInt($a['startnr'] ?? null);
                if (!$nr) {
                    $nr = self::naechsteStartnr($D);
                }
                foreach ($D['starter'] as $x) {
                    if ($x['startnr'] === $nr) {
                        throw new ZeitFehler('Die Startnummer ' . $nr . ' ist schon vergeben.');
                    }
                }
                $s = ['id' => self::id($D), 'startnr' => $nr, 'name' => self::t($a['name']), 'traktor' => self::t($a['traktor'] ?? null),
                    'modell' => self::t($a['modell'] ?? null), 'ort' => self::t($a['ort'] ?? null), 'klasseId' => $a['klasseId'],
                    'reihe' => self::neueReihe($D, $a['klasseId']), 'ergebnis' => null, 'stechen' => []];
                $D['starter'][] = $s;
                return ['id' => $s['id']];

            case 'starterAendern':
                $i = self::starterIndex($D, $a['id'] ?? null);
                if ($i === null) {
                    throw new ZeitFehler('Fahrer nicht gefunden.');
                }
                $s = &$D['starter'][$i];
                if (self::has($a, 'name')) {
                    $s['name'] = self::t($a['name']) ?: $s['name'];
                }
                if (self::has($a, 'traktor')) {
                    $s['traktor'] = self::t($a['traktor']);
                }
                if (self::has($a, 'modell')) {
                    $s['modell'] = self::t($a['modell']);
                }
                if (self::has($a, 'ort')) {
                    $s['ort'] = self::t($a['ort']);
                }
                if (self::has($a, 'startnr')) {
                    $nr = self::pInt($a['startnr']);
                    if ($nr && $nr !== $s['startnr']) {
                        foreach ($D['starter'] as $x) {
                            if ($x['startnr'] === $nr && $x['id'] !== $s['id']) {
                                throw new ZeitFehler('Die Startnummer ' . $nr . ' ist schon vergeben.');
                            }
                        }
                        $s['startnr'] = $nr;
                    }
                }
                if (self::has($a, 'klasseId') && $a['klasseId'] !== $s['klasseId'] && self::klasse($D, $a['klasseId']) !== null) {
                    $s['klasseId'] = $a['klasseId'];
                    $s['reihe'] = self::neueReihe($D, $a['klasseId']);
                }
                unset($s);
                return [];

            case 'starterLoeschen':
                $id = $a['id'] ?? null;
                $D['starter'] = array_values(array_filter($D['starter'], fn($s) => $s['id'] !== $id));
                if ($D['aktuell']['starterId'] === $id) {
                    $D['aktuell']['starterId'] = null;
                }
                return [];

            case 'importieren':
                return self::importieren($D, (string)($a['text'] ?? ''));

            case 'auslosen':
                $l = self::inKlasse($D, $a['klasseId'] ?? null);
                for ($i = count($l) - 1; $i > 0; $i--) {
                    $j = random_int(0, $i);
                    [$l[$i], $l[$j]] = [$l[$j], $l[$i]];
                }
                foreach ($l as $n => $x) {
                    $idx = self::starterIndex($D, $x['id']);
                    $D['starter'][$idx]['reihe'] = $n + 1;
                }
                return [];

            case 'verschieben':
                $si = self::starterIndex($D, $a['id'] ?? null);
                if ($si === null) {
                    throw new ZeitFehler('Fahrer nicht gefunden.');
                }
                $l = self::inKlasse($D, $D['starter'][$si]['klasseId']);
                $i = null;
                foreach ($l as $n => $x) {
                    if ($x['id'] === $D['starter'][$si]['id']) {
                        $i = $n;
                    }
                }
                $j = $i + (($a['richtung'] ?? 0) < 0 ? -1 : 1);
                if ($j < 0 || $j >= count($l)) {
                    return [];
                }
                $ii = self::starterIndex($D, $l[$i]['id']);
                $jj = self::starterIndex($D, $l[$j]['id']);
                $t = $D['starter'][$ii]['reihe'];
                $D['starter'][$ii]['reihe'] = $D['starter'][$jj]['reihe'];
                $D['starter'][$jj]['reihe'] = $t;
                if ($D['starter'][$ii]['reihe'] === $D['starter'][$jj]['reihe']) {
                    foreach ($l as $n => $x) {
                        $D['starter'][self::starterIndex($D, $x['id'])]['reihe'] = $n + 1;
                    }
                    $t2 = $D['starter'][$ii]['reihe'];
                    $D['starter'][$ii]['reihe'] = $D['starter'][$jj]['reihe'];
                    $D['starter'][$jj]['reihe'] = $t2;
                }
                return [];

            case 'waehleKlasse':
                if (self::klasse($D, $a['klasseId'] ?? null) === null) {
                    throw new ZeitFehler('Klasse nicht gefunden.');
                }
                if ($D['aktuell']['klasseId'] !== $a['klasseId']) {
                    $D['aktuell'] = ['klasseId' => $a['klasseId'], 'starterId' => null, 'lauf' => 0];
                }
                $D['live'] = ['distanz' => 0, 'bremswagen' => ''];
                return [];

            case 'waehleLauf':
                // 0 = Hauptlauf, 1.. = Stechen. Das Bremswagengewicht des Stechens kommt vom letzten Fahrer dieses Laufs.
                $k = self::klasse($D, ($a['klasseId'] ?? null) ?: $D['aktuell']['klasseId']);
                if ($k === null) {
                    throw new ZeitFehler('Klasse nicht gefunden.');
                }
                $lauf = self::lauf($a['lauf'] ?? null);
                $bw = '';
                if ($lauf > 0) {
                    foreach (self::inKlasse($D, $k['id']) as $x) {
                        $e = self::ergOf($x, $lauf);
                        if ($e && !empty($e['bremswagen'])) {
                            $bw = $e['bremswagen'];
                        }
                    }
                }
                $D['aktuell'] = ['klasseId' => $k['id'], 'starterId' => null, 'lauf' => $lauf];
                $D['live'] = ['distanz' => 0, 'bremswagen' => $bw];
                return [];

            case 'waehleStarter':
                $s = self::starter($D, $a['starterId'] ?? null);
                if ($s === null) {
                    throw new ZeitFehler('Fahrer nicht gefunden.');
                }
                $lauf = self::has($a, 'lauf') ? self::lauf($a['lauf'])
                    : ($D['aktuell']['klasseId'] === $s['klasseId'] ? (int)($D['aktuell']['lauf'] ?: 0) : 0);
                $e = self::ergOf($s, $lauf);
                $D['aktuell'] = ['klasseId' => $s['klasseId'], 'starterId' => $s['id'], 'lauf' => $lauf];
                $D['live'] = ['distanz' => 0, 'bremswagen' => ($e !== null && array_key_exists('bremswagen', $e)) ? $e['bremswagen'] : ($D['live']['bremswagen'] ?: '')];
                return [];

            case 'live':
                if (self::has($a, 'distanz')) {
                    $n = self::zahl($a['distanz']);
                    $D['live']['distanz'] = $n === null ? 0 : max(0, $n);
                }
                if (self::has($a, 'delta')) {
                    $D['live']['distanz'] = max(0, self::rund(($D['live']['distanz'] ?: 0) + (float)$a['delta']));
                }
                if (self::has($a, 'bremswagen')) {
                    $D['live']['bremswagen'] = self::t($a['bremswagen']);
                }
                return [];

            case 'ergebnis':
                $si = self::starterIndex($D, $a['starterId'] ?? null);
                if ($si === null) {
                    throw new ZeitFehler('Fahrer nicht gefunden.');
                }
                $s = $D['starter'][$si];
                $lauf = self::has($a, 'lauf') ? self::lauf($a['lauf'])
                    : ($D['aktuell']['klasseId'] === $s['klasseId'] ? (int)($D['aktuell']['lauf'] ?: 0) : 0);
                $status = ($a['status'] ?? null) ?: 'ok';
                $e = ['status' => $status, 'zeit' => self::iso(),
                    'bremswagen' => self::t(self::has($a, 'bremswagen') ? $a['bremswagen'] : $D['live']['bremswagen'])];
                if ($status === 'ok') {
                    $w = self::zahl($a['weite'] ?? null);
                    if ($w === null || $w < 0 || $w > 300) {
                        throw new ZeitFehler('Bitte eine gueltige Weite eingeben (z.B. 68,95).');
                    }
                    $e['weite'] = self::rund($w);
                } else {
                    $e['weite'] = null;
                }
                self::setErg($s, $lauf, $e);
                $D['starter'][$si] = $s;
                if ($D['aktuell']['starterId'] === $s['id']) {
                    $D['live']['distanz'] = $e['weite'] ?: 0;
                }
                return [];

            case 'ergebnisLoeschen':
                $si = self::starterIndex($D, $a['starterId'] ?? null);
                if ($si === null) {
                    throw new ZeitFehler('Fahrer nicht gefunden.');
                }
                $s = $D['starter'][$si];
                $lauf = self::has($a, 'lauf') ? self::lauf($a['lauf'])
                    : ($D['aktuell']['klasseId'] === $s['klasseId'] ? (int)($D['aktuell']['lauf'] ?: 0) : 0);
                if ($lauf > 0) {
                    self::setErg($s, $lauf, null);
                } else {
                    $s['ergebnis'] = null;   // Hauptlauf loeschen = auch das Stechen faellt weg
                    $s['stechen'] = [];
                }
                $D['starter'][$si] = $s;
                return [];

            case 'neuerDurchgang':
                // Bremswagen wurde angepasst: alle Fahrer der Klasse ziehen neu. Alte Weiten bleiben als Protokoll.
                $ki = self::klasseIndex($D, $a['klasseId'] ?? null);
                if ($ki === null) {
                    throw new ZeitFehler('Klasse nicht gefunden.');
                }
                if ($backup) {
                    $backup('vor-durchgang');
                }
                $k = $D['klassen'][$ki];
                $nr = ($k['durchgang'] ?? 0) ?: 1;
                $bw = self::t($a['bremswagen'] ?? null);
                $archiviert = 0;
                foreach (self::inKlasse($D, $k['id']) as $x) {
                    $idx = self::starterIndex($D, $x['id']);
                    $s = $D['starter'][$idx];
                    if (is_array($s['ergebnis'] ?? null) || count($s['stechen'] ?? [])) {
                        $s['historie'] = $s['historie'] ?? [];
                        $s['historie'][] = ['durchgang' => $nr, 'ergebnis' => $s['ergebnis'] ?? null, 'stechen' => $s['stechen'] ?? []];
                        $archiviert++;
                    }
                    $s['ergebnis'] = null;
                    $s['stechen'] = [];
                    $D['starter'][$idx] = $s;
                }
                $k['durchgaenge'] = $k['durchgaenge'] ?? [];
                $k['durchgaenge'][] = ['nr' => $nr, 'ende' => self::iso(), 'bremswagenNeu' => $bw, 'grund' => self::t($a['grund'] ?? null)];
                $k['durchgang'] = $nr + 1;
                $D['klassen'][$ki] = $k;
                $D['aktuell'] = ['klasseId' => $k['id'], 'starterId' => null, 'lauf' => 0];
                $D['live'] = ['distanz' => 0, 'bremswagen' => $bw];
                return ['archiviert' => $archiviert];

            case 'aufsteiger':
                // Fahrer (z.B. Klassensieger) startet zusaetzlich in einer anderen Klasse, mit eigener Wertung.
                $o = self::starter($D, $a['starterId'] ?? null);
                if ($o === null) {
                    throw new ZeitFehler('Fahrer nicht gefunden.');
                }
                $k = self::klasse($D, $a['klasseId'] ?? null);
                if ($k === null) {
                    throw new ZeitFehler('Klasse nicht gefunden.');
                }
                if ($k['id'] === $o['klasseId']) {
                    throw new ZeitFehler('Das ist schon seine Klasse.');
                }
                $ursprung = ($o['aufsteigerVon'] ?? null) ?: $o['id'];
                foreach ($D['starter'] as $x) {
                    if ($x['klasseId'] === $k['id'] && ($x['id'] === $ursprung || ($x['aufsteigerVon'] ?? null) === $ursprung)) {
                        throw new ZeitFehler($o['name'] . ' startet in dieser Klasse schon.');
                    }
                }
                $von = self::klasse($D, $o['klasseId']);
                $n = ['id' => self::id($D), 'startnr' => $o['startnr'], 'name' => $o['name'], 'traktor' => $o['traktor'], 'modell' => $o['modell'] ?? '',
                    'ort' => $o['ort'] ?? '', 'klasseId' => $k['id'], 'reihe' => self::neueReihe($D, $k['id']), 'ergebnis' => null, 'stechen' => [],
                    'aufsteigerVon' => $ursprung, 'vonKlasse' => $von['name'] ?? ''];
                $D['starter'][] = $n;
                return ['id' => $n['id']];

            case 'einstellungen':
                if (self::has($a, 'name')) {
                    $D['event']['name'] = self::t($a['name']) ?: $D['event']['name'];
                }
                if (self::has($a, 'kurz')) {
                    $D['event']['kurz'] = self::t($a['kurz']) ?: $D['event']['kurz'];
                }
                if (self::has($a, 'fullPull')) {
                    $n = self::zahl($a['fullPull']);
                    if ($n && $n > 0) {
                        $D['event']['fullPull'] = $n;
                    }
                }
                return [];

            case 'demo':
                return self::demo($D);

            case 'sendung':
                return self::sendung($D, $a);

            case 'sponsorNeu':
                $name = self::t($a['name'] ?? null);
                if ($name === '') {
                    throw new ZeitFehler('Bitte einen Namen eingeben.');
                }
                foreach ($D['sponsoren'] as $x) {
                    if (self::lower($x['name']) === self::lower($name)) {
                        throw new ZeitFehler('Diesen Sponsor gibt es schon.');
                    }
                }
                $x = ['id' => self::id($D), 'name' => $name, 'claim' => self::t($a['claim'] ?? null), 'paket' => self::t($a['paket'] ?? null), 'logo' => ''];
                if (!empty($a['logo'])) {
                    $x['logo'] = self::logoPruefen($a['logo']);
                }
                if (in_array($a['zeige'] ?? null, ['logo', 'beides', 'text'], true)) {
                    $x['zeige'] = $a['zeige'];
                }
                $D['sponsoren'][] = $x;
                if (!empty($a['imBalken'])) {
                    $D['sendung']['balken'][] = $x['id'];
                }
                return ['id' => $x['id']];

            case 'sponsorAendern':
                $xi = null;
                foreach ($D['sponsoren'] as $i => $y) {
                    if ($y['id'] === ($a['id'] ?? null)) {
                        $xi = $i;
                    }
                }
                if ($xi === null) {
                    throw new ZeitFehler('Sponsor nicht gefunden.');
                }
                $x = $D['sponsoren'][$xi];
                if (self::has($a, 'name')) {
                    $n = self::t($a['name']);
                    if ($n === '') {
                        throw new ZeitFehler('Bitte einen Namen eingeben.');
                    }
                    foreach ($D['sponsoren'] as $y) {
                        if ($y['id'] !== $x['id'] && self::lower($y['name']) === self::lower($n)) {
                            throw new ZeitFehler('Diesen Sponsor gibt es schon.');
                        }
                    }
                    $x['name'] = $n;
                }
                if (self::has($a, 'claim')) {
                    $x['claim'] = self::t($a['claim']);
                }
                if (self::has($a, 'paket')) {
                    $x['paket'] = self::t($a['paket']);
                }
                if (self::has($a, 'logo')) {
                    $x['logo'] = !empty($a['logo']) ? self::logoPruefen($a['logo']) : '';
                }
                if (self::has($a, 'zeige')) {
                    $x['zeige'] = in_array($a['zeige'], ['logo', 'beides', 'text'], true) ? $a['zeige'] : 'logo';
                }
                $D['sponsoren'][$xi] = $x;
                return [];

            case 'sponsorLoeschen':
                $xi = null;
                foreach ($D['sponsoren'] as $i => $y) {
                    if ($y['id'] === ($a['id'] ?? null)) {
                        $xi = $i;
                        break;
                    }
                }
                if ($xi === null) {
                    throw new ZeitFehler('Sponsor nicht gefunden.');
                }
                array_splice($D['sponsoren'], $xi, 1);
                foreach ($D['sendung']['sp'] as $k => $v) {
                    if ($v === $a['id']) {
                        $D['sendung']['sp'][$k] = '';
                    }
                }
                $D['sendung']['balken'] = array_values(array_filter($D['sendung']['balken'], fn($b) => $b !== $a['id']));
                return [];

            case 'sponsorenImportieren':
                return self::sponsorenImportieren($D, (string)($a['text'] ?? ''));

            case 'zuruecksetzen':
                if (($a['bestaetigung'] ?? null) !== 'JA') {
                    throw new ZeitFehler('Zum Zuruecksetzen bitte JA eingeben.');
                }
                if ($backup) {
                    $backup('vor-reset');
                }
                $ev = $D['event'];
                $sd = $D['sendung'];
                $sps = $D['sponsoren'];
                $nid = $D['nextId'];
                $rev = $D['rev'];
                $D = self::leer();
                $D['event'] = $ev;
                $D['sendung'] = $sd;
                $D['sponsoren'] = $sps;
                $D['nextId'] = max($D['nextId'], $nid);
                $D['rev'] = $rev;
                return [];

            default:
                throw new ZeitFehler('Unbekannte Aktion: ' . self::t($typ));
        }
    }

    private static function importieren(array &$D, string $text): array
    {
        $neu = 0;
        $fehler = [];
        $zeilen = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text)), fn($z) => $z !== ''));
        foreach ($zeilen as $z) {
            $t = array_map('trim', preg_split('/\t|;/', $z));
            if (preg_match('/^(start|nr|nummer|name)/i', $t[0]) && self::pInt($t[0]) === null) {
                continue;   // Kopfzeile
            }
            $nr = null;
            if (preg_match('/^\d+$/', $t[0]) && count($t) >= 4) {
                $nr = (int)array_shift($t);
            }
            $modell = '';
            if (count($t) >= 4) {
                [$name, $traktor, $modell, $kl] = $t;
            } else {
                $name = $t[0] ?? null;
                $traktor = $t[1] ?? null;
                $kl = $t[2] ?? null;
            }
            if (!$name || !$kl) {
                $fehler[] = $z;
                continue;
            }
            $doppelt = false;
            if ($nr) {
                foreach ($D['starter'] as $x) {
                    if ($x['startnr'] === $nr) {
                        $doppelt = true;
                    }
                }
            }
            if ($doppelt) {
                $fehler[] = $z . '  (Startnummer doppelt)';
                continue;
            }
            $k = self::klasseFinden($D, $kl);
            if (!$D['aktuell']['klasseId']) {
                $D['aktuell']['klasseId'] = $k['id'];
            }
            $D['starter'][] = ['id' => self::id($D), 'startnr' => $nr ?: self::naechsteStartnr($D), 'name' => $name, 'traktor' => $traktor ?: '',
                'modell' => $modell ?: '', 'ort' => '', 'klasseId' => $k['id'], 'reihe' => self::neueReihe($D, $k['id']), 'ergebnis' => null, 'stechen' => []];
            $neu++;
        }
        return ['neu' => $neu, 'fehler' => $fehler];
    }

    private static function sponsorenImportieren(array &$D, string $text): array
    {
        $neu = 0;
        $aktualisiert = 0;
        $zeilen = [];
        foreach (preg_split('/\r?\n/', $text) as $z) {
            $t = array_map('trim', preg_split('/\t|;/', $z));
            if (($t[0] ?? '') !== '') {
                $zeilen[] = $t;
            }
        }
        foreach ($zeilen as $t) {
            if (preg_match('/^(name|sponsor|firma)/i', $t[0]) && preg_match('/^(spruch|claim|slogan|paket)/i', $t[1] ?? '')) {
                continue;   // Kopfzeile
            }
            $xi = null;
            foreach ($D['sponsoren'] as $i => $y) {
                if (self::lower($y['name']) === self::lower($t[0])) {
                    $xi = $i;
                    break;
                }
            }
            if ($xi !== null) {
                if (array_key_exists(1, $t)) {
                    $D['sponsoren'][$xi]['claim'] = $t[1];
                }
                if (array_key_exists(2, $t)) {
                    $D['sponsoren'][$xi]['paket'] = $t[2];
                }
                $aktualisiert++;
            } else {
                $D['sponsoren'][] = ['id' => self::id($D), 'name' => $t[0], 'claim' => $t[1] ?? '', 'paket' => $t[2] ?? '', 'logo' => ''];
                $neu++;
            }
        }
        return ['neu' => $neu, 'aktualisiert' => $aktualisiert];
    }

    private static function demo(array &$D): array
    {
        $daten = [
            '2,5t-Klasse' => [['Hans Müller', 'Red Thunder', 'Fendt 411'], ['Peter Schmidt', 'Black Beast', 'Deutz D 6206'], ['Lukas Weber', 'Green Power', 'John Deere 3140'], ['Jonas Bauer', 'Iron Horse', 'Case 1494']],
            '3,5t-Klasse' => [['Stefan Fischer', 'Blue Diesel', 'New Holland TM 165'], ['Michael Koch', 'Farmer Fury', 'Claas Arion 640'], ['Tim Wagner', 'Deutz Power', 'Deutz-Fahr Agrotron 150']],
            '4,5t-Klasse' => [['Max Mustermann', 'Big Green', 'John Deere 4870'], ['Felix Braun', 'Blitz', 'Fendt 818'], ['Paul Hoffmann', 'Puma Power', 'Case Puma 230'], ['Nico Becker', 'Axion Attack', 'Claas Axion 950']],
        ];
        foreach ($daten as $kn => $liste) {
            $k = self::klasseFinden($D, $kn, '');
            if (!$D['aktuell']['klasseId']) {
                $D['aktuell']['klasseId'] = $k['id'];
            }
            foreach ($liste as [$n, $t, $m]) {
                $D['starter'][] = ['id' => self::id($D), 'startnr' => self::naechsteStartnr($D), 'name' => $n, 'traktor' => $t, 'modell' => $m ?: '', 'ort' => '',
                    'klasseId' => $k['id'], 'reihe' => self::neueReihe($D, $k['id']), 'ergebnis' => null, 'stechen' => []];
            }
        }
        return [];
    }

    private static function sendung(array &$D, array $a): array
    {
        $sd = &$D['sendung'];
        if (!empty($a['schalter']) && is_array($a['schalter'])) {
            foreach ($a['schalter'] as $k => $v) {
                if (array_key_exists($k, $sd['show'])) {
                    $sd['show'][$k] = (bool)$v;
                }
            }
        }
        if (!empty($a['aufruf']) && is_array($a['aufruf'])) {
            $sd['aufruf']['text'] = self::t($a['aufruf']['text'] ?? null) ?: $sd['aufruf']['text'];
            if (array_key_exists('kopf', $a['aufruf'])) {
                $sd['aufruf']['kopf'] = self::t($a['aufruf']['kopf']) ?: 'Durchsage';
            }
        }
        if (!empty($a['bb']) && is_array($a['bb'])) {
            foreach (['label', 'name', 'funktion'] as $f) {
                if (array_key_exists($f, $a['bb'])) {
                    $sd['bb'][$f] = self::t($a['bb'][$f]);
                }
            }
        }
        if (!empty($a['sp']) && is_array($a['sp'])) {
            foreach ($sd['sp'] as $k => $_) {
                if (array_key_exists($k, $a['sp'])) {
                    $raw = $a['sp'][$k];
                    $v = ($raw === '' || $raw === null) ? '' : self::pInt($raw);
                    $gueltig = false;
                    foreach ($D['sponsoren'] as $x) {
                        if ($x['id'] === $v) {
                            $gueltig = true;
                        }
                    }
                    $sd['sp'][$k] = ($v && $gueltig) ? $v : '';
                }
            }
        }
        if (array_key_exists('balken', $a)) {
            $ids = [];
            foreach ((is_array($a['balken']) ? $a['balken'] : []) as $i) {
                $n = self::pInt($i);
                foreach ($D['sponsoren'] as $x) {
                    if ($x['id'] === $n) {
                        $ids[] = $n;
                        break;
                    }
                }
            }
            $sd['balken'] = $ids;
        }
        if (!empty($a['leeren'])) {
            foreach ($sd['show'] as $k => $_) {
                if ($k !== 'bug' && $k !== 'sponsors') {
                    $sd['show'][$k] = false;
                }
            }
        }
        unset($sd);
        return [];
    }

    // ---------------------------------------------------------------- Ausgabe fuer Oberflaeche und Grafik

    public static function zustand(array $D): array
    {
        $ranglisten = [];
        foreach ($D['klassen'] as $k) {
            $ranglisten[$k['id']] = self::rangliste($D, $k['id']);
        }
        return ['event' => $D['event'], 'klassen' => $D['klassen'], 'starter' => $D['starter'], 'aktuell' => $D['aktuell'], 'live' => $D['live'],
            'ranglisten' => $ranglisten ?: new stdClass(), 'rev' => $D['rev'], 'sendung' => $D['sendung'], 'sponsoren' => $D['sponsoren']];
    }

    /** Stechen-Weiten fuer die Grafik (nur wer im Full Pull ist und tatsaechlich im Stechen war). */
    private static function stechenListe(array $st, array $row): array
    {
        if (!$row['fullPull']) {
            return [];
        }
        $out = [];
        foreach (($st['stechen'] ?? []) as $e) {
            if (is_array($e) && ($e['status'] ?? null) === 'ok' && self::isNumRaw($e['weite'] ?? null)) {
                $out[] = $e['weite'];
            }
        }
        return $out;
    }

    /** Das bekommt das Grafikpaket (Format = update()-Felder der Vorlage). */
    public static function grafik(array $D): array
    {
        $k = self::klasse($D, $D['aktuell']['klasseId']);
        $s = self::starter($D, $D['aktuell']['starterId']);
        $lauf = (int)($D['aktuell']['lauf'] ?: 0);
        $out = ['fullPull' => $D['event']['fullPull']];
        $sd = $D['sendung'];
        foreach ($sd['show'] as $key => $v) {
            $out['show' . strtoupper($key[0]) . substr($key, 1)] = $v;
        }
        $out['aufrufKopf'] = $sd['aufruf']['kopf'];
        $out['aufrufText'] = $sd['aufruf']['text'];
        $out['bbLabel'] = $sd['bb']['label'];
        $out['bbName'] = $sd['bb']['name'];
        $out['bbFunktion'] = $sd['bb']['funktion'];
        $spOf = function ($key) use ($D, $sd) {
            foreach ($D['sponsoren'] as $x) {
                if ($x['id'] === ($sd['sp'][$key] ?? null)) {
                    return $x;
                }
            }
            return null;
        };
        foreach ([['slomo', 'slomoSponsor'], ['fullPull', 'fullPullSponsor'], ['klasse', 'klasseSponsor'], ['ranking', 'rankingSponsor'], ['sieger', 'siegerSponsor'], ['bb', 'bbSponsor']] as [$kk, $feld]) {
            $x = $spOf($kk);
            $z = $x ? self::zeigeArt($x) : 'text';
            $out[$feld] = ($x && ($z !== 'logo' || $feld === 'bbSponsor')) ? $x['name'] : '';
            if ($feld !== 'bbSponsor') {
                $out[$feld . 'Logo'] = ($x && $z !== 'text') ? $x['logo'] : '';
            }
        }
        $bal = [];
        foreach ($sd['balken'] as $i) {
            foreach ($D['sponsoren'] as $x) {
                if ($x['id'] === $i) {
                    $bal[] = $x;
                    break;
                }
            }
        }
        if ($bal) {
            $out['sponsors'] = array_map(function ($x) {
                $z = self::zeigeArt($x);
                return ['name' => $z === 'logo' ? '' : $x['name'], 'logo' => $z === 'text' ? '' : $x['logo'], 'claim' => $x['claim']];
            }, $bal);
        }
        if ($k) {
            $r = self::rangliste($D, $k['id']);
            $rows = array_values(array_filter($r['rows'], fn($x) => $x['status'] === 'ok' || $x['status'] === 'dq'));
            $laufTxt = $lauf > 0 ? 'Stechen ' . $lauf : '';
            $out['klasseName'] = $k['name'];
            $out['klasse'] = $k['name'] . ($laufTxt ? ' · ' . $laufTxt : '');
            $out['klasseInfo'] = (!empty($k['info']) ? $k['info'] . '  |  ' : '') . count(self::inKlasse($D, $k['id'])) . ' Starter'
                . ((($k['durchgang'] ?? 1) ?: 1) > 1 ? '  |  Durchgang ' . $k['durchgang'] : '');
            $out['rankingTitel'] = $k['name'];
            $out['siegerKlasse'] = $k['name'];
            $out['ranking'] = array_map(function ($x) use ($D) {
                $st = self::starter($D, $x['starterId']);
                return ['name' => $st['name'], 'traktor' => $st['traktor'], 'modell' => $st['modell'] ?? '',
                    'dist' => $x['status'] === 'dq' ? 'DQ' : self::fix2($x['weite']), 'stechen' => self::stechenListe($st, $x)];
            }, array_slice($rows, 0, 8));
            $okRows = array_values(array_filter($r['rows'], fn($x) => $x['status'] === 'ok'));
            $out['sieger'] = array_map(function ($x) use ($D) {
                $st = self::starter($D, $x['starterId']);
                return ['name' => $st['name'], 'traktor' => $st['traktor'], 'modell' => $st['modell'] ?? '',
                    'dist' => self::fix2($x['weite']), 'stechen' => self::stechenListe($st, $x)];
            }, array_slice($okRows, 0, 3));
            $liste = self::inKlasse($D, $k['id']);
            if ($lauf > 0) {
                $sl = $r['stechenLaeufe'][$lauf - 1] ?? null;
                $liste = array_values(array_filter($liste, fn($x) => $sl && in_array($x['id'], $sl['ids'], true)));
            }
            $ab = -1;
            if ($s) {
                foreach ($liste as $i => $x) {
                    if ($x['id'] === $s['id']) {
                        $ab = $i;
                        break;
                    }
                }
            }
            $folge = array_merge(array_slice($liste, $ab + 1), array_slice($liste, 0, max($ab, 0)));
            $folge = array_values(array_filter($folge, fn($x) => self::ergOf($x, $lauf) === null && (!$s || $x['id'] !== $s['id'])));
            $out['next'] = array_map(fn($x) => ['nr' => (string)$x['startnr'], 'name' => $x['name']], array_slice($folge, 0, 3));
        }
        if ($s) {
            $out['startnr'] = (string)$s['startnr'];
            $out['fahrer'] = $s['name'];
            $out['traktor'] = $s['traktor'];
            $out['modell'] = $s['modell'] ?? '';
            $e = self::ergOf($s, $lauf);
            if ($e) {
                $out['modus'] = 'final';
                $out['status'] = $e['status'] === 'dq' ? 'DQ' : ($e['status'] === 'na' ? 'n.a.' : '');
                $out['distanz'] = $e['weite'] !== null ? self::fix2($e['weite']) : '0.00';
                $out['bremswagen'] = $e['bremswagen'] ?? '';
            } else {
                $out['modus'] = 'live';
                $out['status'] = '';
                $out['distanz'] = self::jsNum($D['live']['distanz'] ?: 0);
                $out['bremswagen'] = $D['live']['bremswagen'] ?: '';
            }
        }
        return $out;
    }
}
