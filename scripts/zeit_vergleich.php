<?php
// Vergleichstest: spielt die Aktionen eines mit dem Original (Node) aufgezeichneten Szenarios in PHP nach und vergleicht
// nach jedem Schritt Zustand, Grafikdaten, Fehlermeldung und Ergebnis.   php scripts/zeit_vergleich.php szenario.json
require_once __DIR__ . '/../src/Zeitnahme.php';

$steps = json_decode((string)file_get_contents($argv[1] ?? 'szenario.json'), true);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$z = new Zeitnahme($pdo);

function maskiere($v)
{
    if (is_array($v)) {
        $o = [];
        foreach ($v as $k => $x) {
            if (($k === 'zeit' || $k === 'ende') && is_string($x)) {
                $o[$k] = 'T';
            } elseif ($k === 'adressen' || $k === 'rev') {
                continue;
            } else {
                $o[$k] = maskiere($x);
            }
        }
        return $o;
    }
    if (is_object($v)) {
        return [];
    }
    return $v;
}

/** Zahlen normieren (JSON kennt 1 und 1.0 nicht getrennt) und Schluessel sortieren, damit nur echte Unterschiede auffallen. */
function kanon($v)
{
    if (is_array($v)) {
        $l = array_is_list($v);
        $o = [];
        foreach ($v as $k => $x) {
            $o[$k] = kanon($x);
        }
        if (!$l) {
            ksort($o);
        }
        return $o;
    }
    if (is_float($v) && $v == floor($v) && abs($v) < 1e15) {
        return (int)$v;
    }
    return $v;
}

$fehler = 0;
// Start wie das Original: _reset() = leer()
$z->aktion(['typ' => 'zuruecksetzen', 'bestaetigung' => 'JA']);
foreach ($steps as $i => $s) {
    $a = $s['a'];
    $err = null;
    $res = null;
    if (($a['typ'] ?? '') === 'zuruecksetzen' && ($a['bestaetigung'] ?? '') === 'JA') {
        // gleicher Aufruf wie im Original
    }
    try {
        $res = $z->aktion($a);
        unset($res['rev']);
    } catch (ZeitFehler $e) {
        $err = $e->getMessage();
    }
    $erw = ['err' => $s['err'], 'res' => $s['res'], 'zustand' => $s['zustand'], 'grafik' => $s['grafik']];
    $ist = ['err' => $err, 'res' => $err ? null : $res, 'zustand' => maskiere($z->zustand()), 'grafik' => maskiere($z->grafik())];
    $erw['zustand'] = maskiere($erw['zustand']);
    $erw['grafik'] = maskiere($erw['grafik']);
    // Fehlermeldungen: das Original nutzt echte Umlaute-freie Texte; hier gleiche Texte erwartet
    $x = json_encode(kanon($erw), JSON_UNESCAPED_UNICODE);
    $y = json_encode(kanon($ist), JSON_UNESCAPED_UNICODE);
    if ($x !== $y) {
        $fehler++;
        echo "ABWEICHUNG bei Schritt $i: " . json_encode($a, JSON_UNESCAPED_UNICODE) . "\n";
        $ea = kanon($erw);
        $ia = kanon($ist);
        foreach (['err', 'res', 'zustand', 'grafik'] as $part) {
            if (json_encode($ea[$part]) !== json_encode($ia[$part])) {
                echo "  Teil $part\n";
                diffe($ea[$part], $ia[$part], $part);
            }
        }
        if ($fehler > 6) {
            break;
        }
    }
}
echo $fehler ? "\n$fehler Schritte weichen ab.\n" : 'Alle ' . count($steps) . " Schritte stimmen mit dem Original ueberein.\n";
exit($fehler ? 1 : 0);

function diffe($a, $b, string $pfad, int &$n = 0): void
{
    if ($n > 8) {
        return;
    }
    if (is_array($a) && is_array($b)) {
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            diffe($a[$k] ?? '<fehlt>', $b[$k] ?? '<fehlt>', "$pfad.$k", $n);
        }
        return;
    }
    if ($a !== $b) {
        $n++;
        echo '    ' . $pfad . ': erwartet ' . json_encode($a, JSON_UNESCAPED_UNICODE) . ' / PHP ' . json_encode($b, JSON_UNESCAPED_UNICODE) . "\n";
    }
}
