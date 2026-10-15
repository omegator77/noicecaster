<?php

/**
 * Bauchbinde (Lower Third) fuer SDI-Ausgaenge: Grafik mit Hintergrund, Texten und optionalem Logo, die ein-/ausfliegt.
 *
 * Die Grafik ist eine SVG-Vorlage mit Platzhaltern ({{TITLE}}, {{MAIN}}, {{SUB}}, {{ACCENT}}, {{ACCENT2}}, {{LOGO}}, Schriftgroessen),
 * die hier eingesetzt und mit GStreamer (rsvgoverlay) in ein PNG mit Transparenz gerendert wird: kein weiteres Programm noetig.
 * Der Empfaenger (scripts/srt_to_sdi_receiver.py) legt das PNG per gdkpixbufoverlay ueber das Bild und animiert es (Position, Transparenz).
 * Eingebaute Vorlagen: assets/lowerthird/<id>/{template.svg,meta.json}; eigene SVG-Vorlagen: data/lowerthird/templates/<id>/...
 */
class LowerThird
{
    public const POSITIONS = ['bottom-left', 'bottom-center', 'bottom-right', 'top-left', 'top-center', 'top-right'];
    public const ANIMS = ['slide-left', 'slide-right', 'slide-up', 'slide-down', 'fade'];

    public static function dataDir(): string
    {
        $d = __DIR__ . '/../data/lowerthird';
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        return $d;
    }

    public static function logoDir(): string
    {
        $d = self::dataDir() . '/logos';
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        return $d;
    }

    public static function logoPath(string $name): string
    {
        return self::logoDir() . '/' . $name;
    }

    /** @return array<int, array{id: string, name: string, builtin: bool}> */
    public static function templates(): array
    {
        $out = [];
        foreach ([[__DIR__ . '/../assets/lowerthird', true], [self::dataDir() . '/templates', false]] as [$dir, $builtin]) {
            foreach (glob($dir . '/*/meta.json') ?: [] as $meta) {
                $id = basename(dirname($meta));
                if (!preg_match('/^[a-z0-9_-]{1,40}$/', $id) || !is_file(dirname($meta) . '/template.svg')) {
                    continue;
                }
                $m = json_decode((string)@file_get_contents($meta), true);
                if (!is_array($m)) {
                    continue;
                }
                $out[$id] = ['id' => $id, 'name' => (string)($m['name'] ?? $id), 'builtin' => $builtin,
                    'has_logo' => !empty($m['logo']), 'width' => (int)($m['width'] ?? 0), 'height' => (int)($m['height'] ?? 0)];
            }
        }
        ksort($out);
        return array_values($out);
    }

    private static function templateDir(string $id): ?string
    {
        foreach ([__DIR__ . '/../assets/lowerthird/' . $id, self::dataDir() . '/templates/' . $id] as $d) {
            if (preg_match('/^[a-z0-9_-]{1,40}$/', $id) && is_file($d . '/template.svg') && is_file($d . '/meta.json')) {
                return $d;
            }
        }
        return null;
    }

    private static function cleanLine(?string $s): ?string
    {
        $s = (string)$s;
        if (preg_match('//u', $s) !== 1) {
            throw new RuntimeException('Bauchbinden-Text ist kein gueltiges UTF-8');
        }
        $s = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s));
        if (preg_match_all('/./us', $s) > 80) {
            throw new RuntimeException('Bauchbinden-Texte duerfen hoechstens 80 Zeichen lang sein');
        }
        return $s !== '' ? $s : null;
    }

    /**
     * Felder der Bauchbinde pruefen/bereinigen (nur Empfaenger). Fehlende Werte ergeben Standardwerte.
     * @return array<string, mixed> lt_template, lt_title, lt_main, lt_sub, lt_color, lt_logo, lt_anim, lt_dur, lt_hold, lt_scale, lt_pos
     */
    public static function sanitize(array $d): array
    {
        $tpl = (string)($d['lt_template'] ?? 'classic');
        if (self::templateDir($tpl) === null) {
            $tpl = 'classic';
        }
        $color = strtoupper(trim((string)($d['lt_color'] ?? '#E1306C')));
        if (preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
            $color = '#E1306C';
        }
        $logo = trim((string)($d['lt_logo'] ?? ''));
        if ($logo !== '' && (preg_match('/^[a-f0-9]{16,64}\.(png|jpg)$/', $logo) !== 1 || !is_file(self::logoPath($logo)))) {
            $logo = '';
        }
        $animReq = $d['lt_anim'] ?? 'slide-left';
        $anim = in_array($animReq, self::ANIMS, true) ? $animReq : 'slide-left';
        $posReq = $d['lt_pos'] ?? 'bottom-left';
        $pos = in_array($posReq, self::POSITIONS, true) ? $posReq : 'bottom-left';
        $dur = (int)($d['lt_dur'] ?? 700);
        $dur = ($dur < 100 || $dur > 5000) ? 700 : $dur;
        $hold = (int)($d['lt_hold'] ?? 0);
        $hold = ($hold < 0 || $hold > 3600) ? 0 : $hold;
        $scale = (int)($d['lt_scale'] ?? 60);
        $scale = ($scale < 20 || $scale > 100) ? 60 : $scale;
        return [
            'lt_template' => $tpl,
            'lt_title' => self::cleanLine($d['lt_title'] ?? null),
            'lt_main' => self::cleanLine($d['lt_main'] ?? null),
            'lt_sub' => self::cleanLine($d['lt_sub'] ?? null),
            'lt_color' => $color,
            'lt_logo' => $logo !== '' ? $logo : null,
            'lt_anim' => $anim,
            'lt_dur' => $dur,
            'lt_hold' => $hold,
            'lt_scale' => $scale,
            'lt_pos' => $pos,
        ];
    }

    /**
     * Hochgeladenes Logo pruefen und ablegen (PNG oder JPEG, bis 2 MB, hoechstens 4000 x 4000 Pixel). Der Dateiname ist ein Hash
     * des Inhalts (gleiches Bild = gleiche Datei). Das Bild wird ueber getimagesize als Bild erkannt, nicht ueber die Endung.
     */
    public static function storeLogo(string $tmpFile, int $size): string
    {
        if (!is_uploaded_file($tmpFile) && !(PHP_SAPI === 'cli' && is_file($tmpFile))) {
            throw new RuntimeException('Ungueltiger Upload');
        }
        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            throw new RuntimeException('Logo darf hoechstens 2 MB gross sein');
        }
        $info = @getimagesize($tmpFile);
        if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new RuntimeException('Logo muss ein PNG- oder JPEG-Bild sein');
        }
        if ($info[0] > 4000 || $info[1] > 4000 || $info[0] < 8 || $info[1] < 8) {
            throw new RuntimeException('Logo-Aufloesung muss zwischen 8 und 4000 Pixeln liegen');
        }
        $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
        $name = substr(hash_file('sha256', $tmpFile), 0, 24) . '.' . $ext;
        $dest = self::logoPath($name);
        if (!is_file($dest) && !@copy($tmpFile, $dest)) {
            throw new RuntimeException('Logo nicht speicherbar');
        }
        return $name;
    }

    private const MAX_CUSTOM_TEMPLATES = 30;

    public static function customTemplateDir(): string
    {
        $d = self::dataDir() . '/templates';
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        return $d;
    }

    /** Eingebaute Vorlagen-IDs (duerfen nicht ueberschrieben werden). */
    private static function builtinIds(): array
    {
        return array_map('basename', glob(__DIR__ . '/../assets/lowerthird/*', GLOB_ONLYDIR) ?: []);
    }

    private static function slug(string $s): string
    {
        $s = strtolower(strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue']));
        $s = trim((string)preg_replace('/[^a-z0-9_-]+/', '-', $s), '-_');
        return substr($s, 0, 40);
    }

    /** SVG ueber scripts/svg_check.py pruefen (strenge Positivliste, siehe dort); liefert Groesse, Textboxen, Logo-Position. */
    private static function checkSvg(string $svg): array
    {
        if (strlen($svg) > 1024 * 1024) {
            throw new RuntimeException('Die Vorlage darf hoechstens 1 MB gross sein');
        }
        $proc = proc_open(['timeout', '15', 'python3', __DIR__ . '/../scripts/svg_check.py'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Die Pruefung der Vorlage konnte nicht gestartet werden');
        }
        fwrite($pipes[0], $svg);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $r = json_decode((string)$out, true);
        if (!is_array($r)) {
            throw new RuntimeException('Die Pruefung der Vorlage lieferte kein Ergebnis');
        }
        if (empty($r['ok'])) {
            throw new RuntimeException('Vorlage abgelehnt: ' . (string)($r['error'] ?? 'unbekannter Grund'));
        }
        return $r;
    }

    /**
     * Eigene SVG-Vorlage installieren: pruefen, Name/ID bestimmen, ablegen und zur Probe mit Beispieltexten rendern (Fill und Key).
     * Schlaegt etwas fehl, bleibt nichts zurueck. Eine vorhandene eigene Vorlage mit demselben Namen wird nur mit $overwrite ersetzt.
     * @return array{id: string, name: string, builtin: bool, has_logo: bool, width: int, height: int, replaced: bool}
     */
    public static function installTemplate(string $tmpFile, int $size, string $name = '', bool $overwrite = false, string $fileName = ''): array
    {
        if (!is_uploaded_file($tmpFile) && !(PHP_SAPI === 'cli' && is_file($tmpFile))) {
            throw new RuntimeException('Ungueltiger Upload');
        }
        if ($size <= 0 || $size > 1024 * 1024) {
            throw new RuntimeException('Die Vorlage darf hoechstens 1 MB gross sein');
        }
        $svg = (string)file_get_contents($tmpFile);
        $check = self::checkSvg($svg);
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
        if ($name === '' || preg_match('//u', $name) !== 1) {
            $name = (string)($check['name'] ?: '');
        }
        if ($name === '' && $fileName !== '') {
            // kein Name angegeben und kein Name im Kommentar: Dateiname ohne Endung
            $base = (string)pathinfo(basename($fileName), PATHINFO_FILENAME);
            $name = preg_match('//u', $base) === 1 ? trim(str_replace(['_', '-'], ' ', $base)) : '';
        }
        $id = self::slug($name);
        if ($id === '') {
            throw new RuntimeException('Bitte einen Namen fuer die Vorlage angeben (Buchstaben oder Ziffern)');
        }
        if (preg_match_all('/./us', $name) > 60) {
            throw new RuntimeException('Der Name darf hoechstens 60 Zeichen lang sein');
        }
        if (in_array($id, self::builtinIds(), true)) {
            throw new RuntimeException("Der Name \"{$id}\" gehoert einer eingebauten Vorlage, bitte einen anderen waehlen");
        }
        $dir = self::customTemplateDir() . '/' . $id;
        $exists = is_dir($dir);
        if ($exists && !$overwrite) {
            throw new RuntimeException("Eine eigene Vorlage \"{$id}\" gibt es schon (zum Ersetzen 'Vorhandene ersetzen' waehlen)");
        }
        if (!$exists && count(glob(self::customTemplateDir() . '/*/meta.json') ?: []) >= self::MAX_CUSTOM_TEMPLATES) {
            throw new RuntimeException('Es sind hoechstens ' . self::MAX_CUSTOM_TEMPLATES . ' eigene Vorlagen moeglich');
        }
        $meta = ['name' => $name !== '' ? $name : $id, 'width' => $check['width'], 'height' => $check['height'], 'boxes' => $check['boxes']];
        if (!empty($check['logo'])) {
            $meta['logo'] = $check['logo'];
        }
        // In ein Arbeitsverzeichnis schreiben, dort Probe rendern, erst dann an die Stelle der Vorlage verschieben
        $work = self::customTemplateDir() . '/.work_' . bin2hex(random_bytes(5));
        if (!@mkdir($work, 0775, true)) {
            throw new RuntimeException('Vorlagenordner nicht schreibbar');
        }
        try {
            file_put_contents($work . '/template.svg', $svg);
            file_put_contents($work . '/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            // Probe: gleiche Wege wie im Betrieb (Fill und Key), mit der Vorlage im Arbeitsverzeichnis
            $svgText = self::fillTemplate($svg, $meta, ['lt_title' => 'Titel', 'lt_main' => 'Haupttext Beispiel', 'lt_sub' => 'Untertext', 'lt_color' => '#E1306C', 'lt_logo' => null], false);
            self::probeRender($svgText, $work . '/probe.png', (int)$meta['width']);
            self::probeRender(self::toKeySvg($svgText), $work . '/probe_key.png', (int)$meta['width']);
            if ($exists) {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
            }
            if (!@rename($work, $dir)) {
                throw new RuntimeException('Vorlage nicht speicherbar');
            }
            @unlink($dir . '/probe.png');
            @unlink($dir . '/probe_key.png');
        } finally {
            if (is_dir($work)) {
                foreach (glob($work . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($work);
            }
        }
        return ['id' => $id, 'name' => $meta['name'], 'builtin' => false, 'has_logo' => !empty($meta['logo']),
            'width' => (int)$meta['width'], 'height' => (int)$meta['height'], 'replaced' => $exists];
    }

    /** Probe-Render eines fertigen SVG-Textes (ohne eine Vorlage zu kennen). */
    private static function probeRender(string $svgText, string $outPng, int $width): void
    {
        $svgPath = $outPng . '.svg';
        file_put_contents($svgPath, $svgText);
        $meta = null;
        $h = 0;
        if (preg_match('/viewBox="[^"]*?\s([0-9.]+)\s+([0-9.]+)"/', $svgText, $m)) {
            $h = (int)round(((float)$m[2]) * 480 / max(1.0, (float)$m[1]));
        }
        $w = 480;
        $h = $h > 0 ? $h : 120;
        $w -= $w % 2;
        $h -= $h % 2;
        $cmd = 'timeout 30 gst-launch-1.0 -q videotestsrc num-buffers=1 pattern=solid-color foreground-color=0 ! ' .
            "video/x-raw,format=BGRA,width={$w},height={$h} ! rsvgoverlay location=" . escapeshellarg($svgPath) .
            ' fit-to-frame=true ! videoconvert ! video/x-raw,format=RGBA ! pngenc ! filesink location=' . escapeshellarg($outPng) . ' 2>&1';
        $out = [];
        exec($cmd, $out, $rc);
        @unlink($svgPath);
        if ($rc !== 0 || !is_file($outPng) || filesize($outPng) < 100) {
            throw new RuntimeException('Die Vorlage laesst sich nicht rendern: ' . substr(implode(' ', $out), 0, 300));
        }
    }

    /** Eigene Vorlage loeschen (eingebaute nicht); nicht, solange ein Ausgang sie benutzt. */
    public static function deleteTemplate(string $id, PDO $db): void
    {
        if (preg_match('/^[a-z0-9_-]{1,40}$/', $id) !== 1 || in_array($id, self::builtinIds(), true)) {
            throw new RuntimeException('Eingebaute Vorlagen lassen sich nicht loeschen');
        }
        $dir = self::customTemplateDir() . '/' . $id;
        if (!is_dir($dir)) {
            throw new RuntimeException('Vorlage nicht gefunden');
        }
        $st = $db->prepare('SELECT name FROM pipelines WHERE lt_template = :t');
        $st->execute([':t' => $id]);
        $users = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($users) {
            throw new RuntimeException('Die Vorlage wird noch von ' . implode(', ', $users) . ' benutzt. Dort zuerst eine andere Vorlage waehlen.');
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    /** SVG-Text einer Vorlage (zum Herunterladen, als Ausgangspunkt fuer eigene Vorlagen). */
    public static function templateSvg(string $id): ?string
    {
        $dir = self::templateDir($id);
        return $dir !== null ? (string)file_get_contents($dir . '/template.svg') : null;
    }

    /** Ist eine Bauchbinde eingerichtet (mindestens ein Text oder ein Logo)? Nur dann bekommt der Empfaenger das Grafik-Element. */
    public static function configured(array $row): bool
    {
        return !empty($row['lt_title']) || !empty($row['lt_main']) || !empty($row['lt_sub']) || !empty($row['lt_logo']);
    }

    private static function darken(string $hex, float $f): string
    {
        $c = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        return sprintf('#%02X%02X%02X', (int)round($c[0] * (1 - $f)), (int)round($c[1] * (1 - $f)), (int)round($c[2] * (1 - $f)));
    }

    /** Schriftgroesse so waehlen, dass der Text in die Box passt (grobe Breitenschaetzung, Mindestgroesse 14). */
    private static function fit(?string $text, array $box, bool $upper): int
    {
        $size = (int)($box['size'] ?? 30);
        if ($text === null) {
            return $size;
        }
        $chars = preg_match_all('/./us', $text);
        $factor = $upper ? 0.66 : 0.54;
        $est = $chars * $factor * $size;
        $w = (float)($box['w'] ?? 800);
        return $est > $w ? max(14, (int)floor($w / max(1, $chars * $factor))) : $size;
    }

    /** SVG-Text aus Vorlage und Werten bauen. */
    public static function buildSvg(array $cfg, bool $key = false): string
    {
        $dir = self::templateDir((string)$cfg['lt_template']) ?? throw new RuntimeException('Vorlage nicht gefunden');
        $svg = (string)file_get_contents($dir . '/template.svg');
        $meta = json_decode((string)file_get_contents($dir . '/meta.json'), true) ?: [];
        return self::fillTemplate($svg, $meta, $cfg, $key);
    }

    /** Platzhalter einer Vorlage ersetzen (Texte maskiert, Schrift eingepasst, Logo eingebettet); $key = Key-Fassung. */
    private static function fillTemplate(string $svg, array $meta, array $cfg, bool $key): string
    {
        $boxes = $meta['boxes'] ?? [];
        $esc = fn(?string $s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = $cfg['lt_title'] !== null ? (!empty($boxes['title']['upper']) ? mb_strtoupper_compat($cfg['lt_title']) : $cfg['lt_title']) : null;
        $logo = '';
        if (!empty($cfg['lt_logo']) && isset($meta['logo'])) {
            $file = self::logoPath((string)$cfg['lt_logo']);
            $mime = str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg';
            $l = $meta['logo'];
            $logo = sprintf('<image x="%d" y="%d" width="%d" height="%d" preserveAspectRatio="xMidYMid meet" xlink:href="data:%s;base64,%s"/>',
                $l['x'], $l['y'], $l['w'], $l['h'], $mime, base64_encode((string)file_get_contents($file)));
        }
        $svg = strtr($svg, [
            '{{ACCENT}}' => $cfg['lt_color'],
            '{{ACCENT2}}' => self::darken($cfg['lt_color'], 0.38),
            '{{TITLE}}' => $esc($title),
            '{{MAIN}}' => $esc($cfg['lt_main']),
            '{{SUB}}' => $esc($cfg['lt_sub']),
            '{{TITLE_SIZE}}' => (string)self::fit($title, $boxes['title'] ?? [], !empty($boxes['title']['upper'])),
            '{{MAIN_SIZE}}' => (string)self::fit($cfg['lt_main'], $boxes['main'] ?? [], false),
            '{{SUB_SIZE}}' => (string)self::fit($cfg['lt_sub'], $boxes['sub'] ?? [], false),
            '{{LOGO}}' => $logo,
        ]);
        return $key ? self::toKeySvg($svg) : $svg;
    }

    /**
     * Key-Fassung: alles in Weiss mit derselben Deckkraft (feColorMatrix setzt RGB = 1 und behaelt Alpha). Auf Schwarz gelegt ergibt das
     * Graustufen = Deckkraft der Grafik, also das Key-Signal; Schatten und weiche Kanten bleiben als Grauwerte erhalten.
     */
    private static function toKeySvg(string $svg): string
    {
        if (preg_match('/<svg(?:\s[^>]*)?>/i', $svg, $m, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException('Vorlage ist kein SVG');
        }
        $end = $m[0][1] + strlen($m[0][0]);
        $close = strripos($svg, '</svg>');
        if ($close === false) {
            throw new RuntimeException('Vorlage ist kein SVG');
        }
        $filter = '<defs><filter id="gwKeyWhite" color-interpolation-filters="sRGB" x="0" y="0" width="100%" height="100%">' .
            '<feColorMatrix type="matrix" values="0 0 0 0 1  0 0 0 0 1  0 0 0 0 1  0 0 0 1 0"/></filter></defs><g filter="url(#gwKeyWhite)">';
        return substr($svg, 0, $end) . $filter . substr($svg, $end, $close - $end) . '</g>' . substr($svg, $close);
    }

    /**
     * Grafik als PNG mit Transparenz rendern. Groesse: $scale Prozent der Bildbreite des Ausgangs.
     * @return array{w: int, h: int, png: string}
     */
    public static function render(array $cfg, int $frameWidth, string $outPng, bool $key = false): array
    {
        $dir = self::templateDir((string)$cfg['lt_template']) ?? throw new RuntimeException('Vorlage nicht gefunden');
        $meta = json_decode((string)file_get_contents($dir . '/meta.json'), true) ?: [];
        $w = max(64, (int)round($frameWidth * ((int)$cfg['lt_scale']) / 100));
        $h = max(16, (int)round($w * ((int)($meta['height'] ?? 270)) / max(1, (int)($meta['width'] ?? 1500))));
        $w -= $w % 2;
        $h -= $h % 2;
        $svgPath = $outPng . '.svg';
        if (@file_put_contents($svgPath, self::buildSvg($cfg, $key)) === false) {
            throw new RuntimeException('Bauchbinden-Vorlage nicht schreibbar');
        }
        $tmp = $outPng . '.tmp.png';
        $cmd = 'timeout 30 gst-launch-1.0 -q videotestsrc num-buffers=1 pattern=solid-color foreground-color=0 ! ' .
            "video/x-raw,format=BGRA,width={$w},height={$h} ! rsvgoverlay location=" . escapeshellarg($svgPath) .
            ' fit-to-frame=true ! videoconvert ! video/x-raw,format=RGBA ! pngenc ! filesink location=' . escapeshellarg($tmp) . ' 2>&1';
        $out = [];
        exec($cmd, $out, $rc);
        @unlink($svgPath);
        if ($rc !== 0 || !is_file($tmp) || filesize($tmp) < 100) {
            @unlink($tmp);
            throw new RuntimeException('Bauchbinde konnte nicht gerendert werden: ' . substr(implode(' ', $out), 0, 300));
        }
        rename($tmp, $outPng);
        return ['w' => $w, 'h' => $h, 'png' => $outPng];
    }
}

/** Grossbuchstaben ohne mbstring (auf den Servern fehlt es): ASCII plus Umlaute. */
function mb_strtoupper_compat(string $s): string
{
    return strtr(strtoupper($s), ['ä' => 'Ä', 'ö' => 'Ö', 'ü' => 'Ü', 'é' => 'É', 'è' => 'È', 'à' => 'À', 'ç' => 'Ç', 'ñ' => 'Ñ']);
}
