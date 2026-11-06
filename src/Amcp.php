<?php

/**
 * Minimaler AMCP-Client fuer CasparCG (Textprotokoll ueber TCP, Port 5250, zeilenorientiert).
 *
 * AMCP kennt keine Anmeldung und ist zeilenorientiert: ein Zeilenumbruch in einem Text wuerde einen zweiten Befehl
 * einschleusen. Darum gilt hier durchgehend:
 *  - Befehle werden aus festen Bausteinen und mit quote() maskierten Werten zusammengesetzt, nie aus rohen Eingaben,
 *  - send() lehnt jede Zeile mit Steuerzeichen (ausser Tabulator) ab, bevor sie das Netz erreicht,
 *  - nur 127.0.0.1 wird angesprochen (CasparCG laeuft auf demselben Rechner wie das Gateway).
 */
class Amcp
{
    public const HOST = '127.0.0.1';
    public const PORT = 5250;

    /** Wert als AMCP-Zeichenkette in Anfuehrungszeichen (Backslash und Anfuehrungszeichen werden maskiert). */
    public static function quote(string $value): string
    {
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Steuerzeichen sind in AMCP-Werten nicht erlaubt');
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * Daten fuer eine Vorlage als AMCP-Zeichenkette: JSON, als Base64 kodiert.
     *
     * Grund: CasparCG setzt die Daten beim Aufruf der Vorlage in einen JavaScript-Ausdruck ein, ohne alle Zeichen sicher
     * zu maskieren. Anfuehrungszeichen und Backslashes in einem Text (zum Beispiel ein Name wie O'Neil oder
     * "Max") brachen den Aufruf, und ein gezielter Text haette Code in die Vorlage einschleusen koennen. Der Inhalt geht
     * deshalb als {"b64":"<Base64>"}: Base64 besteht nur aus A-Z a-z 0-9 + / =, damit ist beides ausgeschlossen. Die aeussere
     * Klammer ist noetig, weil CasparCG einen Datenparameter, der nicht mit { oder < beginnt, fuer den Namen einer
     * gespeicherten Datendatei haelt. Die Vorlagen dekodieren die Daten selbst.
     */
    public static function quoteJson($data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new InvalidArgumentException('Daten nicht als JSON darstellbar');
        }
        return self::quote('{"b64":"' . base64_encode($json) . '"}');
    }

    /**
     * Einen Befehl senden und die Antwort lesen.
     * @return array{code:int, status:string, data:string[]}
     */
    public static function send(string $command, float $timeout = 4.0): array
    {
        if ($command === '' || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $command)) {
            throw new InvalidArgumentException('Ungueltiger AMCP-Befehl (leer oder mit Steuerzeichen)');
        }
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client('tcp://' . self::HOST . ':' . self::PORT, $errno, $errstr, min(2.0, $timeout));
        if (!$fp) {
            throw new RuntimeException('CasparCG ist nicht erreichbar (' . ($errstr !== '' ? $errstr : 'keine Verbindung') . ')');
        }
        try {
            stream_set_timeout($fp, (int)$timeout, (int)(($timeout - (int)$timeout) * 1000000));
            fwrite($fp, $command . "\r\n");
            $first = fgets($fp);
            if ($first === false) {
                throw new RuntimeException('CasparCG hat nicht geantwortet');
            }
            $first = rtrim($first, "\r\n");
            if (!preg_match('/^(\d{3})\s*(.*)$/', $first, $m)) {
                throw new RuntimeException('Unerwartete Antwort von CasparCG: ' . substr($first, 0, 80));
            }
            $code = (int)$m[1];
            $data = [];
            if ($code === 200) {
                // Mehrzeilige Daten, beendet durch eine Leerzeile
                $guard = 0;
                while (($line = fgets($fp)) !== false && $guard++ < 20000) {
                    $line = rtrim($line, "\r\n");
                    if ($line === '') {
                        break;
                    }
                    $data[] = $line;
                }
            } elseif ($code === 201) {
                // Eine Datenzeile (VERSION), bei INFO aber ein mehrzeiliges Dokument, beendet durch eine Leerzeile:
                // nach der ersten Zeile weiterlesen, bis eine Leerzeile kommt oder kurz nichts mehr eintrifft
                $line = fgets($fp);
                if ($line !== false) {
                    $data[] = rtrim($line, "\r\n");
                }
                // Nur ein Dokument (beginnt mit "<") hat weitere Zeilen; eine einzelne Datenzeile ist sofort fertig
                if ($line !== false && ltrim($line)[0] === '<') {
                    stream_set_timeout($fp, 0, 150000);
                    $guard = 0;
                    while (($line = fgets($fp)) !== false && $guard++ < 20000) {
                        $line = rtrim($line, "\r\n");
                        if ($line === '') {
                            break;
                        }
                        $data[] = $line;
                    }
                }
            }
            return ['code' => $code, 'status' => trim($m[2]), 'data' => $data];
        } finally {
            fclose($fp);
        }
    }

    /** Wie send(), wirft aber bei Fehlerantwort (4xx/5xx) eine Ausnahme. */
    public static function ok(string $command, float $timeout = 4.0): array
    {
        $r = self::send($command, $timeout);
        if ($r['code'] >= 400) {
            throw new RuntimeException('CasparCG meldet einen Fehler: ' . $r['code'] . ' ' . $r['status']);
        }
        return $r;
    }
}
