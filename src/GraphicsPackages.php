<?php

require_once __DIR__ . '/GraphicsLibrary.php';
require_once __DIR__ . '/Xlsx.php';

/**
 * Grafik-Vorlagen hoch- und herunterladen: eine Vorlage ist ein Ordner mit index.html (und manifest.json, Bildern, Schriften).
 * Hochladen geht als ZIP oder als einzelne HTML-Datei (zum Beispiel tpf-grafikpaket.html); heruntergeladen wird immer ein ZIP.
 * Hochgeladene Vorlagen landen in data/graphics/ (eigene) und ersetzen nie eine eingebaute.
 */
class GraphicsPackages
{
    private const MAX_FILES = 300;
    private const MAX_TOTAL = 25000000;
    private const EXT = ['html', 'htm', 'css', 'js', 'json', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'woff', 'woff2', 'ttf', 'otf', 'mp4', 'webm', 'mp3', 'wav', 'ogg', 'txt'];

    /**
     * Wird in die hochgeladene index.html eingefuegt: das Gateway sendet die Daten als {"b64":"..."} (damit Anfuehrungszeichen im Text
     * CasparCG nicht stoeren); aeltere Vorlagen kennen das nicht. Die Huelle entschluesselt und ruft update() mit dem fertigen Objekt auf.
     */
    private const SHIM = '<script>/*gwB64Shim*/(function(){function d(x){try{if(typeof x==="string"&&x.charAt(0)==="{"){var j=JSON.parse(x);if(j&&typeof j.b64==="string")return JSON.parse(decodeURIComponent(escape(atob(j.b64))));}}catch(e){}return x;}'
        . 'function w(){var o=window.update;if(typeof o==="function"&&!o.__gw){var n=function(x){return o.call(this,d(x));};n.__gw=1;window.update=n;}}'
        . 'w();document.addEventListener("DOMContentLoaded",w);window.addEventListener("load",w);})();</script>';

    private static function validId(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $id) === 1;
    }

    private static function slug(string $name): string
    {
        $n = preg_replace('/\.[A-Za-z0-9]+$/', '', $name) ?? $name;
        $n = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $n) ?? '', '-'));
        return substr($n, 0, 40);
    }

    private static function builtinIds(): array
    {
        return array_map('basename', glob(GraphicsLibrary::builtinDir() . '/*', GLOB_ONLYDIR) ?: []);
    }

    // ---------------------------------------------------------------- Download

    /** @return array{name:string, data:string} */
    public static function export(string $id): array
    {
        $dirs = GraphicsLibrary::templateDirs();
        if (!isset($dirs[$id])) {
            throw new RuntimeException('Unbekannte Vorlage.');
        }
        $root = $dirs[$id];
        $files = [];
        $total = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS));
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            $total += $f->getSize();
            if (count($files) >= self::MAX_FILES || $total > self::MAX_TOTAL) {
                throw new RuntimeException('Die Vorlage ist zu gross zum Herunterladen.');
            }
            $files[] = [$rel, (string)file_get_contents($f->getPathname())];
        }
        usort($files, fn($a, $b) => strcmp($a[0], $b[0]));
        return ['name' => $id . '.zip', 'data' => Xlsx::zip($files)];
    }

    // ---------------------------------------------------------------- Upload

    private static function safeName(string $n): ?string
    {
        $n = ltrim(str_replace('\\', '/', $n), '/');
        if ($n === '' || str_ends_with($n, '/') || str_contains($n, '..') || !preg_match('#^[A-Za-z0-9._ /-]+$#', $n) || str_contains($n, '//')) {
            return null;
        }
        $ext = strtolower(pathinfo($n, PATHINFO_EXTENSION));
        return in_array($ext, self::EXT, true) ? $n : null;
    }

    /**
     * Vorlage aus ZIP oder HTML speichern.
     * @return array{id:string, replaced:bool, files:int, generated_manifest:bool}
     */
    public static function import(string $filename, string $data, ?string $wantedId = null): array
    {
        if ($data === '' || strlen($data) > self::MAX_TOTAL) {
            throw new RuntimeException('Die Datei ist leer oder zu gross (hoechstens 25 MB).');
        }
        $files = [];
        if (str_starts_with($data, "PK")) {
            try {
                $raw = Xlsx::entpacken($data, self::MAX_TOTAL);
            } catch (Throwable $e) {
                throw new RuntimeException('Die ZIP-Datei konnte nicht gelesen werden.');
            }
            // gemeinsamen Oberordner entfernen (ZIP aus einem Ordner heraus gepackt)
            $names = array_filter(array_keys($raw), fn($n) => !str_ends_with($n, '/'));
            $prefix = '';
            if (!in_array('index.html', $names, true)) {
                $first = null;
                foreach ($names as $n) {
                    $top = explode('/', $n, 2)[0] . '/';
                    $first ??= $top;
                    if ($top !== $first || !str_contains($n, '/')) {
                        $first = null;
                        break;
                    }
                }
                $prefix = $first ?? '';
            }
            foreach ($names as $n) {
                $rel = $prefix !== '' ? substr($n, strlen($prefix)) : $n;
                if (str_starts_with($rel, '__MACOSX') || basename($rel)[0] === '.') {
                    continue;
                }
                $safe = self::safeName($rel);
                if ($safe === null) {
                    throw new RuntimeException('Nicht erlaubte Datei in der ZIP: ' . substr($rel, 0, 60));
                }
                $files[$safe] = $raw[$n];
            }
            if (count($files) > self::MAX_FILES) {
                throw new RuntimeException('Zu viele Dateien in der ZIP.');
            }
        } else {
            if (!preg_match('/\.html?$/i', $filename)) {
                throw new RuntimeException('Bitte eine ZIP-Datei oder eine HTML-Datei hochladen.');
            }
            $files['index.html'] = $data;
        }
        if (!isset($files['index.html'])) {
            throw new RuntimeException('In der Vorlage fehlt die Datei index.html.');
        }

        // Manifest und Kennung
        $manifest = null;
        if (isset($files['manifest.json'])) {
            $manifest = json_decode($files['manifest.json'], true);
            if (!is_array($manifest)) {
                throw new RuntimeException('manifest.json ist kein gueltiges JSON.');
            }
        }
        $id = $wantedId !== null && $wantedId !== '' ? $wantedId : (string)($manifest['id'] ?? self::slug($filename));
        if (!self::validId($id)) {
            throw new RuntimeException('Ungueltige Kennung "' . substr($id, 0, 40) . '" (erlaubt: kleine Buchstaben, Ziffern, - und _).');
        }
        if (in_array($id, self::builtinIds(), true)) {
            throw new RuntimeException('Die Kennung "' . $id . '" gehoert zu einer eingebauten Vorlage. Bitte die Datei anders benennen.');
        }
        $generated = false;
        if ($manifest !== null) {
            $manifest['id'] = $id;
            if (GraphicsLibrary::sanitizeManifest($id, $manifest) === null) {
                throw new RuntimeException('manifest.json ist ungueltig.');
            }
        } else {
            $generated = true;
            $manifest = ['id' => $id, 'name' => ucfirst(str_replace(['-', '_'], ' ', $id)), 'category' => 'Eigene', 'scene' => false,
                'description' => 'Hochgeladene Vorlage (' . substr(preg_replace('/[^\w.\- ]+/u', '', $filename) ?? '', 0, 60) . ').',
                'default_layer' => 20, 'out_ms' => 800, 'fields' => []];
        }
        $files['manifest.json'] = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        // Huelle fuer das Datenformat des Gateways einfuegen (einmalig)
        $html = $files['index.html'];
        if (!str_contains($html, 'gwB64Shim')) {
            $html = preg_match('/<head[^>]*>/i', $html) ? preg_replace('/(<head[^>]*>)/i', '$1' . self::SHIM, $html, 1) : self::SHIM . $html;
            $files['index.html'] = (string)$html;
        }

        // Schreiben: erst in einen Nebenordner, dann tauschen
        $base = GraphicsLibrary::customDir();
        if (!is_dir($base) && !@mkdir($base, 0775, true)) {
            throw new RuntimeException('Vorlagenordner nicht anlegbar.');
        }
        $tmp = $base . '/.neu-' . $id . '-' . bin2hex(random_bytes(4));
        foreach ($files as $rel => $content) {
            $path = $tmp . '/' . $rel;
            if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true)) {
                self::rmTree($tmp);
                throw new RuntimeException('Ordner nicht anlegbar.');
            }
            if (@file_put_contents($path, $content) === false) {
                self::rmTree($tmp);
                throw new RuntimeException('Datei nicht schreibbar.');
            }
        }
        $dest = $base . '/' . $id;
        $replaced = is_dir($dest);
        $alt = $base . '/.alt-' . $id . '-' . bin2hex(random_bytes(4));
        if ($replaced && !@rename($dest, $alt)) {
            self::rmTree($tmp);
            throw new RuntimeException('Die bisherige Vorlage laesst sich nicht ersetzen.');
        }
        if (!@rename($tmp, $dest)) {
            if ($replaced) {
                @rename($alt, $dest);
            }
            self::rmTree($tmp);
            throw new RuntimeException('Die Vorlage konnte nicht gespeichert werden.');
        }
        if ($replaced) {
            self::rmTree($alt);
        }
        try {
            CasparManager::syncTemplates();
        } catch (Throwable $e) {
        }
        return ['id' => $id, 'replaced' => $replaced, 'files' => count($files), 'generated_manifest' => $generated];
    }

    public static function delete(string $id): void
    {
        if (!self::validId($id) || in_array($id, self::builtinIds(), true)) {
            throw new RuntimeException('Eingebaute Vorlagen lassen sich nicht loeschen.');
        }
        $dir = GraphicsLibrary::customDir() . '/' . $id;
        if (!is_dir($dir)) {
            throw new RuntimeException('Unbekannte Vorlage.');
        }
        foreach (GraphicsLibrary::state() as $l => $e) {
            if (($e['template'] ?? '') === $id) {
                throw new RuntimeException('Die Vorlage ist gerade eingeblendet (Ebene ' . $l . '). Bitte zuerst ausblenden.');
            }
        }
        self::rmTree($dir);
        try {
            CasparManager::syncTemplates();
        } catch (Throwable $e) {
        }
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
