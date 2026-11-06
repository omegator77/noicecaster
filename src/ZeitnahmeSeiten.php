<?php

require_once __DIR__ . '/ZeitnahmeLogic.php';
require_once __DIR__ . '/Xlsx.php';

/**
 * Ausgaben der Zeitnahme: Druckseiten, Ergebnisseite fuer Zuschauer, Excel-Dateien und Excel-Import.
 * Uebertragung der entsprechenden Teile von server.js (tpf-zeitnahme).
 */
final class ZeitnahmeSeiten
{
    private const CSS = '
  *{box-sizing:border-box} body{font-family:"Segoe UI",Arial,sans-serif;color:#111;margin:0;padding:24px;max-width:900px;margin:0 auto}
  h1{font-size:28px;margin:0 0 2px} .sub{color:#666;margin-bottom:22px}
  h2{font-size:22px;margin:26px 0 8px;padding:6px 10px;background:#f47c1c;color:#111;page-break-after:avoid}
  table{width:100%;border-collapse:collapse;font-size:17px} th{text-align:left;font-size:13px;text-transform:uppercase;letter-spacing:1px;color:#555;border-bottom:2px solid #111;padding:6px}
  td{padding:7px 6px;border-bottom:1px solid #ddd} td.r,th.r{text-align:right} .fp{color:#1a7f2e;font-weight:700} .dq{color:#c62828} .grau{color:#888}
  .platz{font-weight:800;width:50px} nav{margin-bottom:16px} nav a{display:inline-block;margin:0 6px 6px 0;padding:8px 14px;background:#222;color:#fff;border-radius:6px;text-decoration:none}
  @media print{body{padding:0} nav,.noprint{display:none} h2{background:#ddd !important;-webkit-print-color-adjust:exact} tr{page-break-inside:avoid}}
';

    private static function e($s): string
    {
        return htmlspecialchars((string)($s ?? ''), ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function fmt($w): string
    {
        return ($w === null) ? '' : str_replace('.', ',', number_format((float)$w, 2, '.', ''));
    }

    private static function kopf(array $D, string $titel, string $extra = ''): string
    {
        return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
            . self::e($titel) . '</title>' . $extra . '<style>' . self::CSS . '</style></head><body><h1>' . self::e($D['event']['name'])
            . '</h1><div class="sub">' . self::e($titel) . ' &middot; Stand ' . date('j.n.Y, H:i:s') . '</div>';
    }

    public static function startliste(array $D): string
    {
        $h = self::kopf($D, 'Startliste') . '<button class="noprint" onclick="print()" style="font-size:18px;padding:10px 18px;margin-bottom:10px">Drucken</button>';
        foreach ($D['klassen'] as $k) {
            $l = ZeitnahmeLogic::inKlasse($D, $k['id']);
            $h .= '<h2>' . self::e($k['name']) . ' <small>(' . count($l) . ' Starter)</small></h2><table><tr><th>Reihe</th><th>Nr.</th><th>Fahrer</th><th>Traktor</th><th>Typ</th><th>Ort</th></tr>';
            foreach ($l as $i => $s) {
                $h .= '<tr><td>' . ($i + 1) . '</td><td>' . $s['startnr'] . '</td><td>' . self::e($s['name']) . '</td><td>' . self::e($s['traktor']) . '</td><td>'
                    . self::e($s['modell'] ?? '') . '</td><td>' . self::e($s['ort'] ?? '') . '</td></tr>';
            }
            $h .= '</table>';
        }
        return $h . '</body></html>';
    }

    private static function tabelle(array $D, $kid): string
    {
        $r = ZeitnahmeLogic::rangliste($D, $kid);
        $h = '<table><tr><th>Platz</th><th>Nr.</th><th>Fahrer</th><th>Traktor</th><th>Typ</th><th class="r">Weite</th></tr>';
        foreach ($r['rows'] as $x) {
            $s = ZeitnahmeLogic::starter($D, $x['starterId']);
            if ($x['status'] === 'ok') {
                $sw = implode(' · ', array_map([self::class, 'fmt'], ZeitnahmeLogic::stechenListe($s, $x)));
                $w = self::fmt($x['weite']) . ' m' . ($x['fullPull'] ? ' <span class="fp">Full Pull' . ($sw !== '' ? ' · Stechen ' . $sw . ' m' : '') . '</span>' : '');
            } elseif ($x['status'] === 'dq') {
                $w = '<span class="dq">DQ</span>';
            } elseif ($x['status'] === 'na') {
                $w = '<span class="grau">nicht angetreten</span>';
            } else {
                $w = '<span class="grau">offen</span>';
            }
            $h .= '<tr><td class="platz">' . ($x['platz'] ?? '') . '</td><td>' . $s['startnr'] . '</td><td>' . self::e($s['name']) . '</td><td>' . self::e($s['traktor'])
                . '</td><td>' . self::e($s['modell'] ?? '') . '</td><td class="r">' . $w . '</td></tr>';
        }
        return $h . '</table>';
    }

    /**
     * Ergebnisse. $nurKlasse = Klassen-ID oder null; $mitNav = Zuschauerseite mit Klassenauswahl; $refresh = Sekunden bis zum Neuladen.
     * $basis = Adresse der Seite fuer die Klassenauswahl (zum Beispiel "api.php?action=zeit_ergebnisse").
     */
    public static function ergebnis(array $D, ?int $nurKlasse, bool $mitNav, int $refresh = 0, string $basis = ''): string
    {
        $extra = $refresh ? '<meta http-equiv="refresh" content="' . $refresh . '">' : '';
        $h = self::kopf($D, 'Ergebnisse', $extra);
        if ($mitNav) {
            $h .= '<nav><a href="' . self::e($basis) . '">Alle Klassen</a>';
            foreach ($D['klassen'] as $k) {
                $h .= '<a href="' . self::e($basis . '&klasse=' . $k['id']) . '">' . self::e($k['name']) . '</a>';
            }
            $h .= '</nav>';
        } else {
            $h .= '<button class="noprint" onclick="print()" style="font-size:18px;padding:10px 18px;margin-bottom:10px">Drucken</button>';
        }
        foreach ($D['klassen'] as $k) {
            if ($nurKlasse && $k['id'] !== $nurKlasse) {
                continue;
            }
            $h .= '<h2>' . self::e($k['name']) . '</h2>' . self::tabelle($D, $k['id']);
        }
        return $h . '</body></html>';
    }

    // ---------------------------------------------------------------- Excel

    public static function excelStartliste(array $D): string
    {
        $z = [['Startnr', 'Name', 'Traktor', 'Typ', 'Ort', 'Klasse', 'Reihenfolge']];
        foreach ($D['klassen'] as $k) {
            foreach (ZeitnahmeLogic::inKlasse($D, $k['id']) as $s) {
                $z[] = [$s['startnr'], $s['name'], $s['traktor'], $s['modell'] ?? '', $s['ort'] ?? '', $k['name'], $s['reihe']];
            }
        }
        return Xlsx::schreiben([['name' => 'Fahrer', 'zeilen' => $z, 'breiten' => [9, 28, 22, 26, 18, 18, 13]]]);
    }

    public static function excelErgebnisse(array $D): string
    {
        $bl = [];
        foreach ($D['klassen'] as $k) {
            $r = ZeitnahmeLogic::rangliste($D, $k['id']);
            $z = [['Platz', 'Startnr', 'Name', 'Traktor', 'Typ', 'Weite (m)', 'Full Pull', 'Stechen (m)', 'Bremswagen (t)', 'Status']];
            foreach ($r['rows'] as $x) {
                $s = ZeitnahmeLogic::starter($D, $x['starterId']);
                $e = $s['ergebnis'] ?? null;
                $z[] = [$x['platz'] ?? '', $s['startnr'], $s['name'], $s['traktor'], $s['modell'] ?? '', $x['status'] === 'ok' ? $x['weite'] : '',
                    $x['fullPull'] ? 'ja' : '', implode(' / ', array_map([self::class, 'fmt'], ZeitnahmeLogic::stechenListe($s, $x))),
                    ($e && !empty($e['bremswagen'])) ? $e['bremswagen'] : '',
                    $x['status'] === 'ok' ? '' : ($x['status'] === 'dq' ? 'disqualifiziert' : ($x['status'] === 'na' ? 'nicht angetreten' : 'offen'))];
            }
            $bl[] = ['name' => $k['name'], 'zeilen' => $z, 'breiten' => [7, 9, 26, 22, 26, 11, 10, 22, 15, 18]];
        }
        if (!$bl) {
            $bl[] = ['name' => 'Ergebnisse', 'zeilen' => [['Noch keine Klassen']]];
        }
        return Xlsx::schreiben($bl);
    }

    public static function excelSponsoren(array $D): string
    {
        $z = [['Name', 'Spruch', 'Paket']];
        foreach ($D['sponsoren'] as $x) {
            $z[] = [$x['name'], $x['claim'], $x['paket']];
        }
        return Xlsx::schreiben([['name' => 'Sponsoren', 'zeilen' => $z, 'breiten' => [30, 36, 18]]]);
    }

    private static function kopfSpalte(array $kopf, array $namen): int
    {
        foreach ($kopf as $i => $h) {
            $l = ZeitnahmeLogic::lower(ZeitnahmeLogic::t($h));
            foreach ($namen as $n) {
                if (str_starts_with($l, $n)) {
                    return $i;
                }
            }
        }
        return -1;
    }

    /** Zeilen einer Fahrerliste (mit oder ohne Kopfzeile) in den eingefuegten Text umwandeln, den die Aktion "importieren" kennt. */
    public static function fahrerZuText(array $zeilen): string
    {
        $k = $zeilen[0] ?? [];
        $iName = self::kopfSpalte($k, ['name', 'fahrer']);
        if ($iName < 0) {
            return implode("\n", array_map(fn($z) => implode("\t", $z), $zeilen));
        }
        $iTr = self::kopfSpalte($k, ['traktor', 'maschine']);
        $iMo = self::kopfSpalte($k, ['typ', 'modell', 'fabrikat']);
        $iKl = self::kopfSpalte($k, ['klasse']);
        $iNr = self::kopfSpalte($k, ['start', 'nr', 'nummer']);
        $cell = fn($z, $i) => $i >= 0 ? ZeitnahmeLogic::t($z[$i] ?? '') : '';
        $out = [];
        foreach (array_slice($zeilen, 1) as $z) {
            if ($cell($z, $iName) === '') {
                continue;
            }
            $nr = preg_replace('/\.0+$/', '', $cell($z, $iNr)) ?? '';
            $t = [$cell($z, $iName), $cell($z, $iTr), $cell($z, $iMo), $cell($z, $iKl)];
            $out[] = implode("\t", preg_match('/^\d+$/', $nr) ? array_merge([$nr], $t) : $t);
        }
        return implode("\n", $out);
    }

    /** Eingelesene Datei (xlsx oder Text) in Zeilen. */
    public static function dateiZeilen(string $inhalt, string $name): array
    {
        if (preg_match('/\.(csv|txt|tsv)$/i', $name)) {
            $t = preg_replace('/^\xEF\xBB\xBF/', '', $inhalt) ?? $inhalt;
            $zeilen = [];
            foreach (preg_split('/\r?\n/', $t) as $z) {
                if (trim($z) !== '') {
                    $zeilen[] = preg_split('/\t|;/', $z);
                }
            }
            return $zeilen;
        }
        return Xlsx::lesen($inhalt);
    }
}
