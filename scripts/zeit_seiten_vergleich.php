<?php
// Vergleicht Druckseiten und Excel-Dateien mit denen des Originals (Node). Verzeichnis mit doc.json und out_* als Argument.
require_once __DIR__ . '/../src/ZeitnahmeSeiten.php';
$dir = rtrim($argv[1] ?? '.', '/\\');
$D = json_decode((string)file_get_contents($dir . '/doc.json'), true);
ZeitnahmeLogic::normalisieren($D);
$fehler = 0;
$pr = function (string $n, bool $ok, string $info = '') use (&$fehler) {
    echo ($ok ? 'ok     ' : 'FEHLER ') . $n . ($ok ? '' : ' ' . $info) . "\n";
    if (!$ok) {
        $fehler++;
    }
};
$maske = fn($h) => preg_replace('/Stand [^<]*</', 'Stand <', $h);
$k1 = $D['klassen'][1]['id'];
$seiten = [
    'startliste.html' => ZeitnahmeSeiten::startliste($D),
    'ergebnis.html' => ZeitnahmeSeiten::ergebnis($D, null, false),
    'ergebnis1.html' => ZeitnahmeSeiten::ergebnis($D, $k1, false),
    'export.html' => ZeitnahmeSeiten::ergebnis($D, null, false),
];
foreach ($seiten as $f => $php) {
    $node = (string)file_get_contents($dir . '/out_' . $f);
    $ok = $maske($node) === $maske($php);
    $info = '';
    if (!$ok) {
        $a = $maske($node);
        $b = $maske($php);
        $i = 0;
        while ($i < min(strlen($a), strlen($b)) && $a[$i] === $b[$i]) {
            $i++;
        }
        $info = "bei Zeichen $i: Node '" . substr($a, max(0, $i - 30), 80) . "' / PHP '" . substr($b, max(0, $i - 30), 80) . "'";
    }
    $pr($f, $ok, $info);
}
$x = [
    'startliste.xlsx' => ZeitnahmeSeiten::excelStartliste($D),
    'ergebnisse.xlsx' => ZeitnahmeSeiten::excelErgebnisse($D),
    'sponsoren.xlsx' => ZeitnahmeSeiten::excelSponsoren($D),
];
foreach ($x as $f => $php) {
    file_put_contents($dir . '/php_' . $f, $php);
    $node = (string)file_get_contents($dir . '/out_' . $f);
    $pr($f . ' (PHP liest Node-Datei = PHP liest eigene)', Xlsx::lesen($node) === Xlsx::lesen($php));
}
// Import-Text aus Excel
$z = Xlsx::lesen((string)file_get_contents($dir . '/out_startliste.xlsx'));
$t = ZeitnahmeSeiten::fahrerZuText($z);
$pr('fahrerZuText Zeilen', substr_count($t, "\n") + 1 === count($z) - 1, substr_count($t, "\n") + 1 . ' vs ' . (count($z) - 1));
echo $fehler ? "\n$fehler Abweichungen\n" : "\nalles gleich\n";
exit($fehler ? 1 : 0);
