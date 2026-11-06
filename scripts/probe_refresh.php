<?php

/**
 * Aktualisiert im Hintergrund den Zwischenspeicher der DeckLink-Signalpruefung (data/probe_<n>.json).
 * Wird von PipelineManager::probeDecklinkSignal gestartet, damit die Pruefung (bis zu 6 s) keinen der wenigen PHP-Arbeitsprozesse
 * blockiert: die Weboberflaeche bekommt sofort das letzte Ergebnis, und der naechste Abruf sieht das neue.
 * Aufruf: php probe_refresh.php <device 0-3> <auto|sdi|optical-sdi>
 * Eine Sperrdatei (data/probe_<n>.lock) stellt sicher, dass je Anschluss hoechstens eine Pruefung gleichzeitig laeuft.
 */
$device = (int)($argv[1] ?? -1);
$connection = (string)($argv[2] ?? 'auto');
if ($device < 0 || $device > 3 || !in_array($connection, ['auto', 'sdi', 'optical-sdi'], true)) {
    exit(2);
}
$dataDir = dirname(__DIR__) . '/data';
$lock = @fopen($dataDir . "/probe_{$device}.lock", 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // laeuft schon (oder nicht schreibbar): nichts doppelt starten
}
$cmd = 'timeout 6 python3 ' . escapeshellarg(__DIR__ . '/probe_decklink.py') . ' ' . $device . ' ' . escapeshellarg($connection) . ' 4 2>&1';
$output = (string)shell_exec($cmd);
$json = json_decode(trim($output), true);
if (!is_array($json)) {
    // Fehlschlag mit speichern, sonst bliebe ein altes "Signal vorhanden" ewig stehen
    $json = ['signal' => false, 'width' => null, 'height' => null, 'framerate' => null, 'error' => 'Probe fehlgeschlagen oder Zeitüberschreitung'];
}
$tmp = $dataDir . "/probe_{$device}.json.tmp";
if (@file_put_contents($tmp, json_encode(['time' => time(), 'result' => $json])) !== false) {
    @rename($tmp, $dataDir . "/probe_{$device}.json");
}
flock($lock, LOCK_UN);
fclose($lock);
