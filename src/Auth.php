<?php

/**
 * Anmeldung fuer das Webinterface.
 *
 * Das Passwort-Hash liegt in data/auth.json (Skript scripts/set-password.php).
 * Solange dort kein Hash steht, bleibt das Interface offen (Uebergang), meldet
 * das aber an die Oberflaeche und gefaehrliche Funktionen (Firewall) bleiben gesperrt.
 * Aufrufe vom Server selbst (Loopback, z.B. status.sh) brauchen keine Anmeldung.
 */
class Auth
{
    private const MAX_FAILURES = 5;
    private const WINDOW = 600;      // Zeitfenster fuer Fehlversuche (s)
    private const LOCKOUT = 300;     // Sperrzeit nach zu vielen Fehlversuchen (s)
    private const SESSION_LIFETIME = 43200; // 12 h

    private static function dataDir(): string
    {
        return getenv('GW_DATA_DIR') ?: __DIR__ . '/../data';
    }

    private static function authFile(): string
    {
        return self::dataDir() . '/auth.json';
    }

    public static function config(): ?array
    {
        $raw = @file_get_contents(self::authFile());
        $data = $raw ? json_decode($raw, true) : null;
        return (is_array($data) && !empty($data['hash'])) ? $data : null;
    }

    public static function isConfigured(): bool
    {
        return self::config() !== null;
    }

    private static function isLoopback(): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return $ip === '127.0.0.1' || $ip === '::1';
    }

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = self::dataDir() . '/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        session_save_path($dir);
        session_name('gw_session');
        ini_set('session.gc_maxlifetime', (string)self::SESSION_LIFETIME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function isLoggedIn(): bool
    {
        $cfg = self::config();
        if ($cfg === null) {
            return false;
        }
        self::startSession();
        $t = $_SESSION['login_time'] ?? 0;
        // Sessions vor einer Passwortaenderung und abgelaufene Sessions sind ungueltig
        return $t > ($cfg['updated'] ?? 0) && (time() - $t) < self::SESSION_LIFETIME;
    }

    /** Wird oben in api.php aufgerufen; beendet die Anfrage bei Bedarf. */
    public static function handle(): void
    {
        $action = $_GET['action'] ?? '';

        if (!self::isLoopback()) {
            // Gegen Cross-Site-Anfragen: ein eigener Header ist per Browser nur von der eigenen Seite moeglich.
            // Vorschau-Bilder (<img>) koennen keinen Header senden und sind nur lesend.
            if ($action !== 'preview_image' && ($_SERVER['HTTP_X_GW_REQUEST'] ?? '') !== '1') {
                self::deny(403, 'Ungueltige Anfrage');
            }
        }

        switch ($action) {
            case 'auth_status':
                echo json_encode([
                    'configured' => self::isConfigured(),
                    'logged_in' => self::isConfigured() ? self::isLoggedIn() : false,
                    'loopback' => self::isLoopback(),
                ]);
                exit;
            case 'login':
                self::login();
                exit;
            case 'logout':
                self::startSession();
                $_SESSION = [];
                session_destroy();
                echo json_encode(['success' => true]);
                exit;
        }

        if (self::isLoopback() || !self::isConfigured()) {
            return;
        }
        if (!self::isLoggedIn()) {
            self::deny(401, 'Anmeldung erforderlich', ['auth' => true]);
        }
    }

    /** Fuer riskante Funktionen: ohne gesetztes Passwort nicht erlaubt. */
    public static function requireConfigured(): void
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('Zuerst ein Passwort setzen: php /opt/broadcast-gateway/scripts/set-password.php');
        }
    }

    private static function deny(int $code, string $msg, array $extra = []): void
    {
        http_response_code($code);
        echo json_encode(['error' => $msg] + $extra);
        exit;
    }

    private static function login(): void
    {
        $cfg = self::config();
        if ($cfg === null) {
            self::deny(400, 'Kein Passwort gesetzt');
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $wait = self::lockedSeconds($ip);
        if ($wait > 0) {
            self::deny(429, 'Zu viele Fehlversuche, bitte in ' . $wait . ' s erneut versuchen');
        }
        $in = json_decode(file_get_contents('php://input'), true);
        $password = is_array($in) ? (string)($in['password'] ?? '') : '';
        if ($password === '' || !password_verify($password, $cfg['hash'])) {
            self::recordFailure($ip);
            usleep(400000);
            self::deny(401, 'Passwort falsch');
        }
        self::clearFailures($ip);
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['login_time'] = time();
        echo json_encode(['success' => true]);
    }

    // ---- Fehlversuche je IP in data/auth_attempts.json (mit Dateisperre)

    private static function withAttempts(callable $fn)  // $fn bekommt die Daten per Referenz
    {
        $path = self::dataDir() . '/auth_attempts.json';
        $fh = fopen($path, 'c+');
        if (!$fh) {
            return $fn([]);
        }
        flock($fh, LOCK_EX);
        $data = json_decode((string)stream_get_contents($fh), true);
        $data = is_array($data) ? $data : [];
        $now = time();
        foreach ($data as $k => $v) {
            if (($v['last'] ?? 0) < $now - self::WINDOW && ($v['locked_until'] ?? 0) < $now) {
                unset($data[$k]);
            }
        }
        $result = $fn($data);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data));
        flock($fh, LOCK_UN);
        fclose($fh);
        @chmod($path, 0600);
        return $result;
    }

    private static function lockedSeconds(string $ip): int
    {
        return self::withAttempts(function (array &$data) use ($ip) {
            $until = $data[$ip]['locked_until'] ?? 0;
            return max(0, $until - time());
        });
    }

    private static function recordFailure(string $ip): void
    {
        self::withAttempts(function (array &$data) use ($ip) {
            $e = $data[$ip] ?? ['count' => 0];
            $e['count']++;
            $e['last'] = time();
            if ($e['count'] >= self::MAX_FAILURES) {
                $e['locked_until'] = time() + self::LOCKOUT;
                $e['count'] = 0;
            }
            $data[$ip] = $e;
            return null;
        });
    }

    private static function clearFailures(string $ip): void
    {
        self::withAttempts(function (array &$data) use ($ip) {
            unset($data[$ip]);
            return null;
        });
    }
}
