<?php
declare(strict_types=1);

/**
 * Convertit un nombre en toutes lettres (français)
 */
function nombreEnLettres(float $nombre): string
{
    $nombre = (int)round($nombre);
    if ($nombre === 0) return 'zéro';

    $unites = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf',
               'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize',
               'dix-sept', 'dix-huit', 'dix-neuf'];

    $dizaines = ['', '', 'vingt', 'trente', 'quarante', 'cinquante',
                 'soixante', 'soixante', 'quatre-vingt', 'quatre-vingt'];

    $convertir = function(int $n) use (&$convertir, $unites, $dizaines): string {
        if ($n < 20) return $unites[$n];

        if ($n < 100) {
            $d = intdiv($n, 10);
            $u = $n % 10;

            if ($d === 7 || $d === 9) {
                $base = $dizaines[$d];
                $reste = $n - ($d * 10);
                return $base . '-' . $unites[10 + $reste];
            }

            $txt = $dizaines[$d];
            if ($u === 1 && $d !== 8) $txt .= '-et-un';
            elseif ($u > 0) $txt .= '-' . $unites[$u];
            elseif ($d === 8) $txt .= 's';
            return $txt;
        }

        if ($n < 1000) {
            $c = intdiv($n, 100);
            $r = $n % 100;
            $txt = ($c === 1 ? 'cent' : $unites[$c] . ' cent');
            if ($r > 0) $txt .= ' ' . $convertir($r);
            elseif ($c > 1) $txt .= 's';
            return $txt;
        }

        if ($n < 1_000_000) {
            $m = intdiv($n, 1000);
            $r = $n % 1000;
            $txt = ($m === 1 ? 'mille' : $convertir($m) . ' mille');
            if ($r > 0) $txt .= ' ' . $convertir($r);
            return $txt;
        }

        if ($n < 1_000_000_000) {
            $mi = intdiv($n, 1_000_000);
            $r = $n % 1_000_000;
            $txt = ($mi === 1 ? 'un million' : $convertir($mi) . ' millions');
            if ($r > 0) $txt .= ' ' . $convertir($r);
            return $txt;
        }
        

        return (string)$n;
    };

    return $convertir($nombre);
}
?>