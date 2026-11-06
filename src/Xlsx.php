<?php

/**
 * Minimaler XLSX-Leser und -Schreiber (nur PHP mit zlib, ohne ZipArchive). Uebertragung von xlsx.js der Zeitnahme.
 *   schreiben($tabellen): [['name'=>, 'zeilen'=>[[...]], 'breiten'=>[...]]] -> Dateiinhalt (erste Zeile = Kopf, fett)
 *   lesen($daten): erstes Tabellenblatt als Text-Zeilen
 */
final class Xlsx
{
    /** @param array<int,array{0:string,1:string}> $dateien [Name, Inhalt] */
    public static function zip(array $dateien): string
    {
        $teile = '';
        $zentral = '';
        $pos = 0;
        foreach ($dateien as [$name, $data]) {
            $crc = crc32($data);
            $len = strlen($data);
            $nl = strlen($name);
            $teile .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, 0, 0x21, $crc, $len, $len, $nl, 0) . $name . $data;
            $zentral .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, 0, 0x21, $crc, $len, $len, $nl, 0, 0, 0, 0, 0, $pos) . $name;
            $pos += 30 + $nl + $len;
        }
        $n = count($dateien);
        return $teile . $zentral . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($zentral), $pos, 0);
    }

    /**
     * @param int $maxGesamt Obergrenze fuer die entpackte Gesamtgroesse (Schutz vor Zip-Bomben)
     * @return array<string,string> Name => Inhalt
     */
    public static function entpacken(string $buf, int $maxGesamt = 64000000): array
    {
        $e = strlen($buf) - 22;
        while ($e >= 0 && substr($buf, $e, 4) !== "PK\x05\x06") {
            $e--;
        }
        if ($e < 0) {
            throw new ZeitFehler('Das ist keine gueltige Excel-Datei (.xlsx).');
        }
        $n = unpack('v', substr($buf, $e + 10, 2))[1];
        $p = unpack('V', substr($buf, $e + 16, 4))[1];
        $out = [];
        $summe = 0;
        for ($i = 0; $i < $n; $i++) {
            if (substr($buf, $p, 4) !== "PK\x01\x02") {
                break;
            }
            $h = unpack('vmethode/x8/Vcsize/x4/vnl/vel/vcl/x8/Voff', substr($buf, $p + 10, 36));
            $name = substr($buf, $p + 46, $h['nl']);
            $summe += $h['csize'];
            if ($summe > $maxGesamt) {
                throw new ZeitFehler('Die Datei ist entpackt zu gross.');
            }
            $l = unpack('vlnl/vlel', substr($buf, $h['off'] + 26, 4));
            $daten = substr($buf, $h['off'] + 30 + $l['lnl'] + $l['lel'], $h['csize']);
            if ($h['methode'] === 0) {
                $out[$name] = $daten;
            } else {
                $d = @gzinflate($daten, $maxGesamt);
                if ($d === false) {
                    throw new ZeitFehler('Die Excel-Datei ist beschaedigt.');
                }
                $out[$name] = $d;
            }
            $p += 46 + $h['nl'] + $h['el'] + $h['cl'];
        }
        return $out;
    }

    private static function xesc($s): string
    {
        $s = htmlspecialchars((string)$s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $s = str_replace('"', '&quot;', $s);
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;
    }

    private static function xun(string $s): string
    {
        $s = str_replace(['&lt;', '&gt;', '&quot;', '&apos;'], ['<', '>', '"', "'"], $s);
        $s = preg_replace_callback('/&#(\d+);/', fn($m) => mb_chr((int)$m[1]), $s) ?? $s;
        $s = preg_replace_callback('/&#x([0-9a-fA-F]+);/', fn($m) => mb_chr(hexdec($m[1])), $s) ?? $s;
        return str_replace('&amp;', '&', $s);
    }

    private static function spalte(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m) . $s;
            $i = intdiv($i - 1, 26);
        }
        return $s;
    }

    private static function blatXml(array $t): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if (!empty($t['breiten'])) {
            $x .= '<cols>';
            foreach ($t['breiten'] as $i => $b) {
                $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $b . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        foreach ($t['zeilen'] as $r => $z) {
            $x .= '<row r="' . ($r + 1) . '">';
            foreach ($z as $c => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $ref = self::spalte($c) . ($r + 1);
                $st = $r === 0 ? ' s="1"' : '';
                if ((is_int($v) || is_float($v)) && is_finite((float)$v)) {
                    $x .= '<c r="' . $ref . '"' . $st . '><v>' . ZeitnahmeLogic::jsNum($v) . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '" t="inlineStr"' . $st . '><is><t xml:space="preserve">' . self::xesc($v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        return $x . '</sheetData></worksheet>';
    }

    public static function schreiben(array $tabellen): string
    {
        $namen = [];
        $bl = [];
        foreach ($tabellen as $t) {
            $b = trim(mb_substr(trim(preg_replace('/[\\\\\/?*\[\]:]/', ' ', (string)$t['name']) ?? ''), 0, 31)) ?: 'Tabelle';
            $o = $b;
            $k = 2;
            while (in_array(strtolower($b), $namen, true)) {
                $b = mb_substr($o, 0, 28) . ' ' . $k++;
            }
            $namen[] = strtolower($b);
            $bl[] = ['name' => $b, 'xml' => self::blatXml($t)];
        }
        $k = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $ct = $k . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $sheets = '';
        $rels = '';
        foreach ($bl as $i => $b) {
            $n = $i + 1;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheets .= '<sheet name="' . self::xesc($b['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $ct .= '</Types>';
        $rels .= '<Relationship Id="rId' . (count($bl) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $files = [
            ['[Content_Types].xml', $ct],
            ['_rels/.rels', $k . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>'],
            ['xl/workbook.xml', $k . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $sheets . '</sheets></workbook>'],
            ['xl/_rels/workbook.xml.rels', $k . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>'],
            ['xl/styles.xml', $k . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>'],
        ];
        foreach ($bl as $i => $b) {
            $files[] = ['xl/worksheets/sheet' . ($i + 1) . '.xml', $b['xml']];
        }
        return self::zip($files);
    }

    /** @return array<int,array<int,string>> */
    public static function lesen(string $buf): array
    {
        $f = self::entpacken($buf);
        $geteilt = [];
        if (isset($f['xl/sharedStrings.xml'])) {
            preg_match_all('/<si\b[^>]*>([\s\S]*?)<\/si>/', $f['xl/sharedStrings.xml'], $sis);
            foreach ($sis[1] as $si) {
                preg_match_all('/<t\b[^>]*>([\s\S]*?)<\/t>/', $si, $ts);
                $geteilt[] = implode('', array_map([self::class, 'xun'], $ts[1]));
            }
        }
        $blaetter = array_values(array_filter(array_keys($f), fn($n) => preg_match('/^xl\/worksheets\/sheet\d+\.xml$/', $n)));
        usort($blaetter, fn($a, $b) => (int)preg_replace('/\D/', '', $a) <=> (int)preg_replace('/\D/', '', $b));
        if (!$blaetter) {
            throw new ZeitFehler('In der Excel-Datei wurde kein Tabellenblatt gefunden.');
        }
        $x = $f[$blaetter[0]];
        $zeilen = [];
        preg_match_all('/<row\b[^>]*>([\s\S]*?)<\/row>/', $x, $rows);
        foreach ($rows[1] as $rx) {
            $z = [];
            preg_match_all('/<c\b([^>]*?)(?:\/>|>([\s\S]*?)<\/c>)/', $rx, $cells, PREG_SET_ORDER);
            foreach ($cells as $cm) {
                $attr = $cm[1];
                $inh = $cm[2] ?? '';
                if (!preg_match('/\br="([A-Z]+)\d+"/', $attr, $rm)) {
                    continue;
                }
                $ci = 0;
                foreach (str_split($rm[1]) as $ch) {
                    $ci = $ci * 26 + (ord($ch) - 64);
                }
                $ci--;
                $typ = preg_match('/\bt="(\w+)"/', $attr, $tm) ? $tm[1] : null;
                if ($typ === 'inlineStr') {
                    preg_match_all('/<t\b[^>]*>([\s\S]*?)<\/t>/', $inh, $ts);
                    $v = implode('', array_map([self::class, 'xun'], $ts[1]));
                } else {
                    $v = preg_match('/<v>([\s\S]*?)<\/v>/', $inh, $vm) ? self::xun($vm[1]) : '';
                    if ($typ === 's') {
                        $v = $geteilt[(int)$v] ?? '';
                    }
                }
                $z[$ci] = $v;
            }
            $max = $z ? max(array_keys($z)) : -1;
            $voll = [];
            for ($i = 0; $i <= $max; $i++) {
                $voll[] = $z[$i] ?? '';
            }
            $zeilen[] = $voll;
        }
        return $zeilen;
    }
}
