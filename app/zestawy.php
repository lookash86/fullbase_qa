<?php
declare(strict_types=1);

/* Pomocnicze funkcje zestawów i list tekstowych — wspólne dla api.php i narzedzia/import-scenariuszy.php. */

/** Zestawy scenariuszy: z tekstu („a; b, c”) albo tablicy → uporządkowana lista bez powtórzeń. */
function suites_in(mixed $v): array
{
    $list = is_array($v) ? $v : preg_split('/[;,\n]+/u', (string)$v);
    $out = [];
    foreach ($list as $x) {
        $x = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$x)), 0, 40);
        if ($x !== '' && !in_array(mb_strtolower($x), array_map('mb_strtolower', $out), true)) $out[] = $x;
    }
    return array_slice($out, 0, 12);
}
function suites_str(array $s): string { return mb_substr(implode('; ', $s), 0, 500); }

function lines(string $s): array
{
    // Flaga /u jest konieczna: bez niej \R łapie bajt 0x85, który jest częścią litery „ą” (c4 85), i psuje UTF-8.
    return array_values(array_filter(array_map('trim', preg_split('/\R/u', $s) ?: []), fn($x) => $x !== ''));
}
