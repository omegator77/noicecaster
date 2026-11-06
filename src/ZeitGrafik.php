<?php

require_once __DIR__ . '/Zeitnahme.php';
require_once __DIR__ . '/GraphicsLibrary.php';
require_once __DIR__ . '/Amcp.php';

/**
 * Bringt die Daten der Zeitnahme ins Grafikpaket in CasparCG: einmal einblenden (Vorlage "zeitnahme" auf einer Ebene),
 * danach nach jeder Aenderung nur die geaenderten Felder per CG UPDATE nachschieben, damit Animationen nicht neu laufen.
 *
 * Ob das Paket eingeblendet ist, steht in der Zustandsdatei der Grafikbibliothek (so stimmen "Alles ausblenden" und der
 * Neustart von CasparCG automatisch). Zusaetzlich merkt sich data/zeit_push.json den zuletzt gesendeten Stand.
 */
class ZeitGrafik
{
    public const TEMPLATE = 'zeitnahme';
    public const DEFAULT_LAYER = 20;

    private static function pushPath(): string
    {
        return __DIR__ . '/../data/zeit_push.json';
    }

    private static function lastLoad(): array
    {
        $j = json_decode((string)@file_get_contents(self::pushPath()), true);
        return is_array($j) ? $j : [];
    }

    private static function lastSave(array $j): void
    {
        $tmp = self::pushPath() . '.tmp';
        @file_put_contents($tmp, json_encode($j, JSON_UNESCAPED_UNICODE), LOCK_EX);
        @rename($tmp, self::pushPath());
    }

    /** Ebene, auf der das Paket gerade liegt (null = nicht eingeblendet). */
    public static function layer(): ?int
    {
        foreach (GraphicsLibrary::state() as $l => $e) {
            if (($e['template'] ?? '') === self::TEMPLATE) {
                return (int)$l;
            }
        }
        return null;
    }

    public static function status(): array
    {
        $l = self::layer();
        return ['live' => $l !== null, 'layer' => $l, 'caspar' => CasparManager::running() && CasparManager::amcpReachable()];
    }

    /** Paket laden und mit dem aktuellen Stand einblenden (ersetzt, was auf der Ebene liegt). */
    public static function start(Zeitnahme $z, $layer = null): array
    {
        if (GraphicsLibrary::manifest(self::TEMPLATE) === null) {
            throw new RuntimeException('Die Vorlage "zeitnahme" fehlt.');
        }
        if (!CasparManager::running() || !CasparManager::amcpReachable()) {
            throw new RuntimeException('CasparCG laeuft nicht.');
        }
        $n = filter_var($layer ?? self::DEFAULT_LAYER, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]);
        if ($n === false) {
            throw new RuntimeException('Ungueltige Ebene (1 bis 99).');
        }
        $data = $z->grafik();
        Amcp::ok('CG ' . GraphicsLibrary::CHANNEL . '-' . $n . ' ADD 1 ' . Amcp::quote(self::TEMPLATE . '/index') . ' 1 ' . Amcp::quoteJson($data), 8.0);
        $shows = array_filter($data, fn($k) => str_starts_with((string)$k, 'show'), ARRAY_FILTER_USE_KEY);
        GraphicsLibrary::register((int)$n, self::TEMPLATE, $shows);
        self::lastSave(['layer' => (int)$n, 'data' => $data]);
        return ['layer' => (int)$n];
    }

    /** Geaenderte Felder nachschieben. Gibt die Zahl der gesendeten Felder zurueck (0 = nichts zu tun oder nicht eingeblendet). */
    public static function push(Zeitnahme $z): int
    {
        $layer = self::layer();
        if ($layer === null) {
            return 0;
        }
        $last = self::lastLoad();
        $old = ($last['layer'] ?? null) === $layer && is_array($last['data'] ?? null) ? $last['data'] : [];
        $new = $z->grafik();
        $diff = [];
        foreach ($new as $k => $v) {
            if (!array_key_exists($k, $old) || json_encode($old[$k]) !== json_encode($v)) {
                $diff[$k] = $v;
            }
        }
        if (!$diff) {
            return 0;
        }
        Amcp::ok('CG ' . GraphicsLibrary::CHANNEL . '-' . $layer . ' UPDATE 1 ' . Amcp::quoteJson($diff), 6.0);
        self::lastSave(['layer' => $layer, 'data' => $new]);
        return count($diff);
    }

    /** Ausblenden (das Paket spielt seine Ausblendanimation). */
    public static function stop(): void
    {
        $layer = self::layer();
        if ($layer === null) {
            return;
        }
        GraphicsLibrary::out($layer);
        self::lastSave([]);
    }
}
