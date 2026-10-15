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
                $out[$id] = ['id' => $id, 'name' => (string)($m['name'] ?? $id), 'builtin' => $builtin];
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
    public static function buildSvg(array $cfg): string
    {
        $dir = self::templateDir((string)$cfg['lt_template']) ?? throw new RuntimeException('Vorlage nicht gefunden');
        $svg = (string)file_get_contents($dir . '/template.svg');
        $meta = json_decode((string)file_get_contents($dir . '/meta.json'), true) ?: [];
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
        return strtr($svg, [
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
    }

    /**
     * Grafik als PNG mit Transparenz rendern. Groesse: $scale Prozent der Bildbreite des Ausgangs.
     * @return array{w: int, h: int, png: string}
     */
    public static function render(array $cfg, int $frameWidth, string $outPng): array
    {
        $dir = self::templateDir((string)$cfg['lt_template']) ?? throw new RuntimeException('Vorlage nicht gefunden');
        $meta = json_decode((string)file_get_contents($dir . '/meta.json'), true) ?: [];
        $w = max(64, (int)round($frameWidth * ((int)$cfg['lt_scale']) / 100));
        $h = max(16, (int)round($w * ((int)($meta['height'] ?? 270)) / max(1, (int)($meta['width'] ?? 1500))));
        $w -= $w % 2;
        $h -= $h % 2;
        $svgPath = $outPng . '.svg';
        if (@file_put_contents($svgPath, self::buildSvg($cfg)) === false) {
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
