<?php
/**
 * Zgodność scenariuszy z systemem (27.11.2026): nazwy ekranów, przycisków i pól w cudzysłowach „…” z kroków i oczekiwanych wyników muszą być napisami w kodzie silnika.
 * Uruchom: php narzedzia/sprawdz-scenariusze.php <katalog silnika> [plik.csv …]  (bez plików — wszystkie docs/scenariusze_*.csv)
 * Wypisuje scenariusze z napisami, których nie ma w kodzie (rdzen/, moduly/, pakiety/, public/js/) — do poprawy w scenariuszu albo w systemie. Porównanie bez wielkości liter.
 */
if (PHP_SAPI !== 'cli') exit;
$silnik = rtrim((string)($argv[1] ?? ''), '/\\');
if (!is_dir("$silnik/rdzen")) { fwrite(STDERR, "Podaj katalog silnika (z rdzen/).\n"); exit(2); }
$pliki = array_slice($argv, 2) ?: glob(__DIR__ . '/../docs/scenariusze_*.csv');

$kod = '';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($silnik, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (!preg_match('~/(rdzen|moduly|pakiety|public/js)/~', $p) || !preg_match('/\.(php|js)$/', $p) || str_contains($p, 'jsqr')) continue;
    $kod .= "\n" . file_get_contents($p);
}
$kod = mb_strtolower(html_entity_decode($kod, ENT_QUOTES, 'UTF-8'));
$kod = str_replace(['„', '”'], ['„', '”'], $kod);

// Nazwy z samego rejestru QA (zestawy, wagi zgłoszeń), nie z systemu — pomijane.
const NAZWY_QA = ['dzień meczowy na telefonie', 'pilotaż', 'poza pilotażem', 'zamrożone', 'telefon', 'szybki test', 'pełna regresja', 'kosmetyka', 'utrudnia', 'blokuje pracę', 'psuje dane'];
$brakow = 0; $napisow = 0; $scenariuszy = 0;
foreach ($pliki as $plik) {
    $t = preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($plik));
    $h = fopen('php://memory', 'r+'); fwrite($h, $t); rewind($h);
    fgetcsv($h, 0, ';', '"', '');
    while (($r = fgetcsv($h, 0, ';', '"', '')) !== false) {
        if (count($r) < 6) continue;
        $scenariuszy++;
        preg_match_all('/„([^”]{2,80})”/u', $r[3] . "\n" . $r[4] . "\n" . $r[5], $m);
        $braki = [];
        foreach (array_unique($m[1]) as $n) {
            $napisow++;
            $szukaj = mb_strtolower(trim(preg_replace('/\s*(…|\.\.\.)\s*$/u', '', $n)));
            // Napis z miejscem do wpisania (np. „Kurs KM”) albo z cyfrą — pomijany; liczy się sens z interfejsu.
            if ($szukaj === '' || in_array($szukaj, NAZWY_QA, true) || preg_match('/\bN\b/u', $n) || str_contains($kod, $szukaj)) continue;
            // Część przed „ — ” albo „ → ” (np. „Ustawienia → Moduły”) — każda część osobno.
            $czesci = preg_split('/\s*(?:→|—|->|\/)\s*/u', $szukaj);
            if (count($czesci) > 1 && !array_filter($czesci, fn($c) => $c !== '' && !str_contains($kod, $c))) continue;
            $braki[] = $n;
        }
        if ($braki) { $brakow += count($braki); echo basename($plik) . ' | ' . $r[2] . "\n    brak w kodzie: „" . implode('”, „', $braki) . "”\n"; }
    }
}
echo "\nScenariuszy: $scenariuszy, napisów w cudzysłowach: $napisow, nieznalezionych: $brakow.\n";
exit($brakow ? 1 : 0);
