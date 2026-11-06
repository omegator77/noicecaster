<?php

require_once __DIR__ . '/Compat.php';
require_once __DIR__ . '/Amcp.php';
require_once __DIR__ . '/CasparManager.php';

/**
 * Grafikvorlagen fuer CasparCG und ihre Steuerung.
 *
 * Eine Vorlage ist ein Ordner mit index.html (HTML-Vorlage im CasparCG-Stil: play(), stop(), update(daten)) und
 * manifest.json. Das Manifest beschreibt die Felder (Name, Typ, Standardwert, Grenzen); daraus baut die Oberflaeche die
 * Eingabemaske, und das Gateway prueft damit jede Eingabe, bevor sie an CasparCG geht.
 *
 * Eingebaute Vorlagen: assets/graphics/<id>/, eigene (spaeter): data/graphics/<id>/.
 * Daten gehen als JSON an die Vorlage (update-Aufruf mit einer JSON-Zeichenkette). Pro CasparCG-Ebene laeuft hoechstens
 * eine Vorlage; sie wird mit "CG ADD" geladen und mit "CG STOP" ausgeblendet.
 *
 * manifest.json:
 *   id            gleich dem Ordnernamen (a-z, 0-9, _, -; hoechstens 40 Zeichen)
 *   name, category, description
 *   default_layer Ebene (1-99), Vorgabe fuer die Oberflaeche
 *   out_ms        Dauer der Ausblendanimation in Millisekunden (0-5000)
 *   fields        Liste von {key, label, type, default, ...}; type: text | textarea | color | number | select | bool
 *                 text/textarea: max (Zeichen); number: min, max, step; select: options [[wert, beschriftung], ...]
 */
class GraphicsLibrary
{
    public const CHANNEL = 1;
    public const TYPES = ['text', 'textarea', 'color', 'number', 'select', 'bool'];

    public static function builtinDir(): string
    {
        return __DIR__ . '/../assets/graphics';
    }

    public static function customDir(): string
    {
        return __DIR__ . '/../data/graphics';
    }

    private static function statePath(): string
    {
        return __DIR__ . '/../data/graphics_state.json';
    }

    private static function validId(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $id) === 1;
    }

    /** @return array<string,string> id => absoluter Ordner (nur Ordner mit lesbarem Manifest und index.html) */
    public static function templateDirs(): array
    {
        $out = [];
        foreach ([self::builtinDir(), self::customDir()] as $base) {
            foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $id = basename($dir);
                $real = realpath($dir);
                if (isset($out[$id])) {
                    continue; // eigene Vorlagen duerfen eingebaute nicht ersetzen
                }
                if ($real !== false && self::validId($id) && is_file($real . '/index.html') && is_file($real . '/manifest.json')) {
                    $out[$id] = $real;
                }
            }
        }
        return $out;
    }

    /** Manifest lesen und bereinigen; null bei ungueltigem Manifest. */
    public static function manifest(string $id): ?array
    {
        $dirs = self::templateDirs();
        if (!isset($dirs[$id])) {
            return null;
        }
        $raw = json_decode((string)@file_get_contents($dirs[$id] . '/manifest.json'), true);
        return is_array($raw) ? self::sanitizeManifest($id, $raw) : null;
    }

    public static function sanitizeManifest(string $id, array $raw): ?array
    {
        if (($raw['id'] ?? $id) !== $id) {
            return null;
        }
        $fields = [];
        $seen = [];
        foreach ((array)($raw['fields'] ?? []) as $f) {
            if (!is_array($f)) {
                continue;
            }
            $key = (string)($f['key'] ?? '');
            $type = (string)($f['type'] ?? '');
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/', $key) || isset($seen[$key]) || !in_array($type, self::TYPES, true)) {
                continue;
            }
            $seen[$key] = true;
            $field = ['key' => $key, 'type' => $type, 'label' => mb_substr((string)($f['label'] ?? $key), 0, 60)];
            if (!empty($f['hidden'])) {
                $field['hidden'] = true; // wird in der Oberflaeche nicht als Eingabe gezeigt (zum Beispiel ein Ausloese-Zaehler)
            }
            if (isset($f['group']) && preg_match('/^[a-z][a-z0-9]{0,9}$/', (string)$f['group'])) {
                $field['group'] = (string)$f['group'];
            }
            switch ($type) {
                case 'text':
                case 'textarea':
                    $field['max'] = max(1, min(2000, (int)($f['max'] ?? ($type === 'text' ? 80 : 400))));
                    $field['default'] = mb_substr((string)($f['default'] ?? ''), 0, $field['max']);
                    break;
                case 'color':
                    $d = (string)($f['default'] ?? '#ffffff');
                    $field['default'] = preg_match('/^#[0-9a-fA-F]{6}$/', $d) ? strtolower($d) : '#ffffff';
                    break;
                case 'number':
                    $field['min'] = (float)($f['min'] ?? 0);
                    $field['max'] = (float)($f['max'] ?? 100000);
                    $field['step'] = (float)($f['step'] ?? 1);
                    $field['default'] = max($field['min'], min($field['max'], (float)($f['default'] ?? 0)));
                    break;
                case 'select':
                    $opts = [];
                    foreach ((array)($f['options'] ?? []) as $o) {
                        if (is_array($o) && isset($o[0])) {
                            $opts[] = [(string)$o[0], mb_substr((string)($o[1] ?? $o[0]), 0, 60)];
                        }
                    }
                    if (!$opts) {
                        continue 2;
                    }
                    $field['options'] = $opts;
                    $vals = array_column($opts, 0);
                    $field['default'] = in_array((string)($f['default'] ?? ''), $vals, true) ? (string)$f['default'] : $vals[0];
                    break;
                case 'bool':
                    $field['default'] = !empty($f['default']);
                    break;
            }
            $fields[] = $field;
        }
        // Gruppen: Elemente einer Szene (eine Vorlage mit mehreren unabhaengig schaltbaren Teilen). "toggle" ist das
        // Ein/Aus-Feld (bool), "trigger" ein Ausloese-Zaehler (number), beide muessen als Feld existieren.
        $groups = [];
        $fieldTypes = array_column($fields, 'type', 'key');
        foreach ((array)($raw['groups'] ?? []) as $g) {
            if (!is_array($g) || !preg_match('/^[a-z][a-z0-9]{0,9}$/', (string)($g['id'] ?? '')) || isset($groups[$g['id']])) {
                continue;
            }
            $group = ['id' => (string)$g['id'], 'name' => mb_substr((string)($g['name'] ?? $g['id']), 0, 60)];
            if (isset($g['toggle']) && ($fieldTypes[(string)$g['toggle']] ?? '') === 'bool') {
                $group['toggle'] = (string)$g['toggle'];
            }
            if (isset($g['trigger']) && ($fieldTypes[(string)$g['trigger']] ?? '') === 'number') {
                $group['trigger'] = (string)$g['trigger'];
            }
            $groups[$group['id']] = $group;
        }
        return [
            'id' => $id,
            'name' => mb_substr((string)($raw['name'] ?? $id), 0, 80),
            'groups' => array_values($groups),
            'scene' => !empty($raw['scene']),
            'category' => mb_substr((string)($raw['category'] ?? 'Sonstige'), 0, 40),
            'description' => mb_substr((string)($raw['description'] ?? ''), 0, 300),
            'default_layer' => max(1, min(99, (int)($raw['default_layer'] ?? 20))),
            'out_ms' => max(0, min(5000, (int)($raw['out_ms'] ?? 600))),
            'builtin' => str_starts_with((string)(self::templateDirs()[$id] ?? ''), (string)realpath(self::builtinDir())),
            'fields' => $fields,
        ];
    }

    public static function templates(): array
    {
        $out = [];
        foreach (array_keys(self::templateDirs()) as $id) {
            $m = self::manifest($id);
            if ($m !== null) {
                $out[] = $m;
            }
        }
        usort($out, fn($a, $b) => [$a['category'], $a['name']] <=> [$b['category'], $b['name']]);
        return $out;
    }

    /** Eingabe gegen das Manifest pruefen und auf bekannte Felder begrenzen; fehlende Felder bekommen den Standardwert. */
    public static function sanitizeFields(array $manifest, array $in): array
    {
        $out = [];
        foreach ($manifest['fields'] as $f) {
            $k = $f['key'];
            $v = array_key_exists($k, $in) ? $in[$k] : $f['default'];
            switch ($f['type']) {
                case 'text':
                    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$v) ?? '';
                    $out[$k] = mb_substr(trim($v), 0, $f['max']);
                    break;
                case 'textarea':
                    $v = str_replace(["\r\n", "\r"], "\n", (string)$v);
                    $v = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', '', $v) ?? '';
                    $out[$k] = mb_substr($v, 0, $f['max']);
                    break;
                case 'color':
                    $out[$k] = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$v) ? strtolower((string)$v) : $f['default'];
                    break;
                case 'number':
                    if (!is_numeric($v)) {
                        throw new RuntimeException('Feld "' . $f['label'] . '" braucht eine Zahl.');
                    }
                    $out[$k] = max($f['min'], min($f['max'], (float)$v));
                    break;
                case 'select':
                    if (!in_array((string)$v, array_column($f['options'], 0), true)) {
                        throw new RuntimeException('Ungueltige Auswahl bei "' . $f['label'] . '".');
                    }
                    $out[$k] = (string)$v;
                    break;
                case 'bool':
                    $out[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                    break;
            }
        }
        return $out;
    }

    private static function layer($layer): int
    {
        $n = filter_var($layer, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]);
        if ($n === false) {
            throw new RuntimeException('Ungueltige Ebene (1 bis 99).');
        }
        return (int)$n;
    }

    private static function requireRunning(): void
    {
        if (!CasparManager::running() || !CasparManager::amcpReachable()) {
            throw new RuntimeException('CasparCG laeuft nicht.');
        }
    }

    // ----- Zustand (welche Vorlage liegt auf welcher Ebene) ----------------------------------------------------------

    public static function state(): array
    {
        $s = json_decode((string)@file_get_contents(self::statePath()), true);
        return is_array($s) ? $s : [];
    }

    private static function saveState(array $s): void
    {
        $tmp = self::statePath() . '.tmp';
        @file_put_contents($tmp, json_encode($s, JSON_UNESCAPED_UNICODE), LOCK_EX);
        @rename($tmp, self::statePath());
    }

    /** Nach einem Neustart von CasparCG ist nichts mehr eingeblendet. */
    public static function resetState(): void
    {
        self::saveState([]);
    }

    // ----- Steuerung -------------------------------------------------------------------------------------------------

    /** Vorlage laden und einblenden (ersetzt, was auf der Ebene liegt). */
    public static function take(string $id, $layer, array $fields): array
    {
        self::requireRunning();
        $m = self::manifest($id);
        if ($m === null) {
            throw new RuntimeException('Unbekannte Vorlage.');
        }
        $layer = self::layer($layer);
        $data = self::sanitizeFields($m, $fields);
        Amcp::ok('CG ' . self::CHANNEL . '-' . $layer . ' ADD 1 ' . Amcp::quote($id . '/index') . ' 1 ' . Amcp::quoteJson($data));
        $st = self::state();
        $st[(string)$layer] = ['template' => $id, 'fields' => $data, 'since' => time()];
        self::saveState($st);
        return ['layer' => $layer, 'template' => $id, 'fields' => $data];
    }

    /** Felder der eingeblendeten Vorlage aendern, ohne sie neu zu laden. */
    public static function update($layer, array $fields): array
    {
        self::requireRunning();
        $layer = self::layer($layer);
        $st = self::state();
        $cur = $st[(string)$layer] ?? null;
        if (!$cur) {
            throw new RuntimeException('Auf dieser Ebene liegt nichts.');
        }
        $m = self::manifest((string)$cur['template']);
        if ($m === null) {
            throw new RuntimeException('Die Vorlage gibt es nicht mehr.');
        }
        $data = self::sanitizeFields($m, array_merge((array)$cur['fields'], $fields));
        Amcp::ok('CG ' . self::CHANNEL . '-' . $layer . ' UPDATE 1 ' . Amcp::quoteJson($data));
        $st[(string)$layer]['fields'] = $data;
        self::saveState($st);
        return ['layer' => $layer, 'fields' => $data];
    }

    /** Ausblenden (die Vorlage spielt ihre Ausblendanimation und bleibt unsichtbar geladen). */
    public static function out($layer): array
    {
        self::requireRunning();
        $layer = self::layer($layer);
        Amcp::ok('CG ' . self::CHANNEL . '-' . $layer . ' STOP 1');
        $st = self::state();
        unset($st[(string)$layer]);
        self::saveState($st);
        return ['layer' => $layer];
    }

    /** Ebene leeren (sofort, ohne Animation); ohne Ebene: alle Grafik-Ebenen. */
    public static function clear($layer = null): array
    {
        self::requireRunning();
        if ($layer === null || $layer === '') {
            Amcp::ok('CLEAR ' . self::CHANNEL);
            self::saveState([]);
            return ['layer' => null];
        }
        $layer = self::layer($layer);
        Amcp::ok('CLEAR ' . self::CHANNEL . '-' . $layer);
        $st = self::state();
        unset($st[(string)$layer]);
        self::saveState($st);
        return ['layer' => $layer];
    }

    // ----- Dateien fuer die Vorschau im Browser -----------------------------------------------------------------------

    private const MIME = [
        'html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'webp' => 'image/webp',
    ];

    /** @return array{path:string, mime:string}|null */
    public static function previewFile(string $id, string $file): ?array
    {
        $dirs = self::templateDirs();
        if (!isset($dirs[$id]) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}$/', $file)) {
            return null;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!isset(self::MIME[$ext]) || $file === 'manifest.json') {
            return null;
        }
        $path = realpath($dirs[$id] . '/' . $file);
        if ($path === false || !str_starts_with($path, $dirs[$id] . '/') || !is_file($path)) {
            return null;
        }
        return ['path' => $path, 'mime' => self::MIME[$ext]];
    }
}
