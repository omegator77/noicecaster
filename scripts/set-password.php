<?php

/**
 * Setzt (oder aendert) das Passwort fuer das Webinterface.
 *
 *   php /opt/broadcast-gateway/scripts/set-password.php
 *
 * Das Passwort wird zweimal ohne Anzeige abgefragt und nur als Hash in
 * data/auth.json abgelegt. Beim Aendern werden alle bestehenden Anmeldungen ungueltig.
 * Zum Abschalten der Anmeldung: data/auth.json loeschen.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$dataDir = getenv('GW_DATA_DIR') ?: __DIR__ . '/../data';
$file = $dataDir . '/auth.json';

function ask(string $prompt): string
{
    echo $prompt;
    $tty = stripos(PHP_OS, 'WIN') !== 0 && function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo');
        echo "\n";
    }
    return rtrim((string)$line, "\r\n");
}

$pw = ask('Neues Passwort (mind. 10 Zeichen): ');
if (strlen($pw) < 10) {
    fwrite(STDERR, "Zu kurz.\n");
    exit(1);
}
if ($pw !== ask('Wiederholen: ')) {
    fwrite(STDERR, "Eingaben stimmen nicht ueberein.\n");
    exit(1);
}

$data = ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'updated' => time()];
if (file_put_contents($file, json_encode($data)) === false) {
    fwrite(STDERR, "Konnte $file nicht schreiben.\n");
    exit(1);
}
chmod($file, 0600);
echo "Passwort gesetzt. Die Anmeldung ist ab sofort aktiv.\n";
