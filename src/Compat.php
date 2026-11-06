<?php

/**
 * Ersatz fuer die PHP-Erweiterung mbstring, die auf den Gateway-Servern nicht installiert ist.
 * Es gibt nur die beiden Funktionen, die das Gateway braucht (UTF-8, Zeichen statt Bytes). Ist mbstring vorhanden,
 * wird nichts definiert.
 */
if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        $chars = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            $chars = str_split($string); // kein gueltiges UTF-8: Bytes
        }
        return implode('', array_slice($chars, $start, $length));
    }
}

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        $chars = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        return $chars === false ? strlen($string) : count($chars);
    }
}
