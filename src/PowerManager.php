<?php

/**
 * Neustart und Herunterfahren des Rechners aus dem Webinterface.
 *
 * Absicherung gegen versehentliches Ausloesen: nur mit gesetztem Passwort, nur per POST mit dem
 * Gateway-Header, die Bestaetigung muss den Namen des Systems enthalten (der Server prueft das selbst),
 * und die Aktion laeuft erst nach einer Wartezeit von 15 s, in der sie abbrechbar ist.
 */
class PowerManager
{
    private const HELPER = '/usr/local/sbin/gateway-power';
    private const STATE = '/run/gateway-power.json';

    public static function status(): array
    {
        $res = ['available' => is_executable(self::HELPER), 'pending' => false, 'action' => null, 'deadline' => null];
        $st = is_file(self::STATE) ? json_decode((string)@file_get_contents(self::STATE), true) : null;
        if (is_array($st) && isset($st['action'], $st['deadline']) && time() <= (int)$st['deadline'] + 30) {
            $res['pending'] = true;
            $res['action'] = $st['action'];
            $res['deadline'] = (int)$st['deadline'];
        }
        $res['now'] = time();
        return $res;
    }

    public static function schedule(array $req): array
    {
        Auth::requireConfigured();
        $action = (string)($req['action'] ?? '');
        if (!in_array($action, ['reboot', 'poweroff'], true)) {
            throw new RuntimeException('Aktion muss "reboot" oder "poweroff" sein');
        }
        $info = PeerInfo::get();
        $typed = strtolower(trim((string)($req['confirm'] ?? '')));
        $accepted = [strtolower($info['self']['label']), strtolower($info['self']['host'])];
        if ($typed === '' || !in_array($typed, $accepted, true)) {
            throw new RuntimeException('Bestätigung stimmt nicht: bitte den Namen des Systems eintippen (' . $info['self']['label'] . ')');
        }
        return self::call('schedule', json_encode(['action' => $action]));
    }

    public static function cancel(): array
    {
        return self::call('cancel');
    }

    private static function call(string $action, ?string $stdin = null): array
    {
        if (!is_executable(self::HELPER)) {
            throw new RuntimeException('Helfer nicht installiert (deploy/install-netconfig.sh mit sudo ausführen)');
        }
        $proc = proc_open(['sudo', '-n', self::HELPER, $action], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Helfer konnte nicht gestartet werden');
        }
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $data = json_decode((string)$out, true);
        if (!is_array($data)) {
            throw new RuntimeException('Helfer lieferte keine Antwort');
        }
        if (isset($data['error'])) {
            throw new RuntimeException($data['error']);
        }
        return $data;
    }
}
