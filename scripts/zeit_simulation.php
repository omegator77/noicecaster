<?php
// Simuliert einen Veranstaltungstag ueber die HTTP-Schnittstelle des laufenden Gateways (nur auf dem Server, ueber 127.0.0.1)
// und prueft dabei Rangliste, Stechen, Grafikdaten und das Nachschieben in CasparCG.
//   php scripts/zeit_simulation.php [Port=8000] [Pause in Millisekunden zwischen Schritten=0]
// ACHTUNG: setzt die Zeitnahme-Daten zurueck (Sicherung bleibt) und blendet das Grafikpaket auf Ebene 20 ein.
$port = (int)($argv[1] ?? 8000);
$pause = (int)($argv[2] ?? 0);
$base = "http://127.0.0.1:$port/api.php?action=";
$fehler = 0;
$grafikFehler = 0;

function call(string $url, ?array $post = null): array
{
    $ctx = ['http' => ['method' => $post === null ? 'GET' : 'POST', 'header' => "Content-Type: application/json\r\nX-GW-Request: 1\r\n",
        'content' => $post === null ? '' : json_encode($post), 'ignore_errors' => true, 'timeout' => 20]];
    $r = @file_get_contents($url, false, stream_context_create($ctx));
    return json_decode((string)$r, true) ?? ['_raw' => $r];
}
$act = function (array $a) use ($base, &$grafikFehler, $pause) {
    $r = call($base . 'zeit_aktion', $a);
    if (isset($r['grafikFehler'])) {
        $grafikFehler++;
        echo "  Grafik-Fehler: {$r['grafikFehler']}\n";
    }
    if ($pause) {
        usleep($pause * 1000);
    }
    return $r;
};
$ok = function (string $name, bool $cond, string $info = '') use (&$fehler) {
    echo ($cond ? 'ok     ' : 'FEHLER ') . $name . ($cond ? '' : "  $info") . "\n";
    if (!$cond) {
        $fehler++;
    }
};
$state = fn() => call($base . 'zeit_state');

$act(['typ' => 'zuruecksetzen', 'bestaetigung' => 'JA']);
$act(['typ' => 'einstellungen', 'name' => 'Simulation Fahrenbach', 'kurz' => 'SIM', 'fullPull' => 100]);
$g = call($base . 'zeit_gfx_start', []);
$ok('Grafik einblenden', !empty($g['live']), json_encode($g));
$act(['typ' => 'sendung', 'schalter' => ['bug' => true, 'sponsors' => true]]);

$klassen = ['2,5t-Klasse' => 9, '3,5t-Klasse' => 7, '4,5t-Klasse' => 11];
$text = '';
$n = 0;
foreach ($klassen as $k => $anz) {
    for ($i = 1; $i <= $anz; $i++) {
        $n++;
        $text .= "Fahrer $n\tTraktor $n\tModell $n\t$k\n";
    }
}
$r = $act(['typ' => 'importieren', 'text' => $text]);
$ok('Import ' . $n . ' Fahrer', ($r['neu'] ?? 0) === $n, json_encode($r));
$s = $state();
$ok('3 Klassen', count($s['klassen']) === 3);

mt_srand(42);
foreach ($s['klassen'] as $kl) {
    $act(['typ' => 'sendung', 'schalter' => ['klasse' => true, 'puller' => false]]);
    $act(['typ' => 'waehleKlasse', 'klasseId' => $kl['id']]);
    $act(['typ' => 'auslosen', 'klasseId' => $kl['id']]);
    $st = $state();
    $liste = array_values(array_filter($st['starter'], fn($x) => $x['klasseId'] === $kl['id']));
    usort($liste, fn($a, $b) => $a['reihe'] <=> $b['reihe']);
    $act(['typ' => 'sendung', 'schalter' => ['klasse' => false, 'puller' => true, 'next' => true]]);
    $fp = 0;
    foreach ($liste as $i => $x) {
        $act(['typ' => 'waehleStarter', 'starterId' => $x['id']]);
        $act(['typ' => 'live', 'bremswagen' => '12']);
        for ($m = 10; $m <= 60; $m += 25) {
            $act(['typ' => 'live', 'distanz' => $m]);
        }
        $w = $i % 5 === 3 ? 'dq' : ($i % 7 === 6 ? 'na' : 'ok');
        if ($i < 3 && $kl['name'] === '4,5t-Klasse') {
            $act(['typ' => 'ergebnis', 'starterId' => $x['id'], 'weite' => '100,00', 'bremswagen' => '12']);   // dreimal Full Pull, gleiche Weite
            $fp++;
        } elseif ($w === 'ok') {
            $act(['typ' => 'ergebnis', 'starterId' => $x['id'], 'weite' => number_format(mt_rand(3000, 9900) / 100, 2, ',', ''), 'bremswagen' => '12']);
        } else {
            $act(['typ' => 'ergebnis', 'starterId' => $x['id'], 'status' => $w]);
        }
    }
    $r = call($base . 'zeit_state')['ranglisten'][(string)$kl['id']];
    if ($kl['name'] === '4,5t-Klasse') {
        $ok('Stechen faellig bei 3x Full Pull', count($r['stechenWartend']) === 1 && count($r['stechenWartend'][0]['ids']) === 3, json_encode($r['stechenWartend']));
        $ids = $r['stechenWartend'][0]['ids'];
        $act(['typ' => 'waehleLauf', 'klasseId' => $kl['id'], 'lauf' => 1]);
        $ws = ['5,00', '7,50', '7,50'];
        foreach ($ids as $j => $id) {
            $act(['typ' => 'waehleStarter', 'starterId' => $id, 'lauf' => 1]);
            $act(['typ' => 'ergebnis', 'starterId' => $id, 'lauf' => 1, 'weite' => $ws[$j], 'bremswagen' => '16']);
        }
        $r = call($base . 'zeit_state')['ranglisten'][(string)$kl['id']];
        $ok('Stechen 2 noetig (zwei gleich)', count($r['stechenWartend']) === 1 && count($r['stechenWartend'][0]['ids']) === 2 && $r['stechenWartend'][0]['nr'] === 2, json_encode($r['stechenWartend']));
        $zw = $r['stechenWartend'][0]['ids'];
        $act(['typ' => 'waehleLauf', 'klasseId' => $kl['id'], 'lauf' => 2]);
        $act(['typ' => 'ergebnis', 'starterId' => $zw[0], 'lauf' => 2, 'weite' => '9,10']);
        $act(['typ' => 'ergebnis', 'starterId' => $zw[1], 'lauf' => 2, 'weite' => '8,20']);
        $r = call($base . 'zeit_state')['ranglisten'][(string)$kl['id']];
        $ok('Stechen entschieden', count($r['stechenWartend']) === 0);
        $plaetze = array_slice(array_column($r['rows'], 'platz'), 0, 4);
        $ok('Plaetze 1,2,3,4', $plaetze === [1, 2, 3, 4], json_encode($plaetze));
        $ok('Sieger ist der mit 9,10 im 2. Stechen', $r['rows'][0]['starterId'] === $zw[0]);
        $act(['typ' => 'waehleLauf', 'klasseId' => $kl['id'], 'lauf' => 0]);
    } else {
        $ok('Rangliste ' . $kl['name'] . ' ohne Stechen', count($r['stechenWartend']) === 0);
        $pl = array_values(array_filter(array_column($r['rows'], 'platz')));
        $ok('Plaetze lueckenlos ' . $kl['name'], $pl === range(1, count($pl)), json_encode($pl));
    }
    $act(['typ' => 'sendung', 'schalter' => ['puller' => false, 'next' => false, 'ranking' => true]]);
    $act(['typ' => 'sendung', 'schalter' => ['ranking' => false, 'sieger' => true]]);
    $act(['typ' => 'sendung', 'schalter' => ['sieger' => false]]);
}

// Durchsage, Bauchbinde, Pause, Zeitlupe
$act(['typ' => 'sendung', 'aufruf' => ['text' => 'Alle Fahrer der 4,5t-Klasse bitte zum Wiegen'], 'schalter' => ['aufruf' => true]]);
$act(['typ' => 'sendung', 'schalter' => ['aufruf' => false]]);
$act(['typ' => 'sendung', 'bb' => ['name' => 'Max "Mäx" Mustermann', 'funktion' => 'Sieger 4,5t', 'label' => 'Interview'], 'schalter' => ['bauchbinde' => true]]);
$act(['typ' => 'sendung', 'schalter' => ['bauchbinde' => false, 'pause' => true]]);
$act(['typ' => 'sendung', 'schalter' => ['pause' => false, 'slomo' => true]]);
$act(['typ' => 'sendung', 'leeren' => true]);

// Neuer Durchgang in der kleinsten Klasse
$k1 = $s['klassen'][0]['id'];
$r = $act(['typ' => 'neuerDurchgang', 'klasseId' => $k1, 'bremswagen' => '10', 'grund' => 'Simulation']);
$ok('Neuer Durchgang archiviert Ergebnisse', ($r['archiviert'] ?? 0) > 0, json_encode($r));
$ok('Rangliste nach neuem Durchgang leer', count(array_filter(call($base . 'zeit_state')['ranglisten'][(string)$k1]['rows'], fn($x) => $x['status'] === 'ok')) === 0);

// Kontrolle: Zuschauerseite, Excel, Druck
$h = @file_get_contents($base . 'zeit_ergebnisse');
$ok('Zuschauerseite enthaelt Klassen', is_string($h) && str_contains($h, '4,5t-Klasse') && str_contains($h, 'Full Pull'));
$x = @file_get_contents($base . 'zeit_export&was=ergebnisse');
$ok('Excel-Ergebnisse ist ein Zip', is_string($x) && str_starts_with($x, "PK"));
$gs = call($base . 'zeit_gfx_status');
$ok('Grafik weiter eingeblendet', !empty($gs['live']));
$ok('Keine Grafik-Fehler in ' . 'der ganzen Simulation', $grafikFehler === 0, "$grafikFehler");

call($base . 'zeit_gfx_stop', []);
echo $fehler ? "\n$fehler Fehler\n" : "\nSimulation ohne Fehler\n";
exit($fehler ? 1 : 0);
