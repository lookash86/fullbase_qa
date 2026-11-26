<?php
/**
 * Dzieli scenariusze PZBall na zakres pilotażu 2027 (docs/KIERUNEK.md silnika, decyzja właściciela 26.11.2026): dopisuje do kolumny „Zestawy” zestaw „Pilotaż” albo „Poza pilotażem”,
 * a funkcjom zamrożonym — także „Zamrożone”. Powtarzalne (zestaw już wpisany nie dubluje się). Import CSV w rejestrze dopisuje brakujące zestawy scenariuszom, które już są.
 * Uruchom: php narzedzia/zakres-pilotazu.php
 */
const OBSZARY_POZA = ['Kadra narodowa', 'Organy i komisje', 'Szkolenia', 'Licencje klubowe'];
/** Tytuły scenariuszy funkcji zamrożonych w obszarach pilotażu (KIERUNEK, „Zamrożone”: koszt składu, podpowiedź obsady, redakcja, grafiki, szkice). */
const TYTULY_ZAMROZONE = ['Koszt składu sędziowskiego', 'Obsada całego okresu jednym kliknięciem', 'Redakcja: publikacja i wycofanie tekstu', 'Podsumowanie kolejki z wynikami',
    'Strona meczu: grafika wyniku do pobrania'];

foreach (['krytyczne', 'priorytetowe', 'rozszerzone', 'pilotaz'] as $nazwa) {
    $plik = __DIR__ . "/../docs/scenariusze_{$nazwa}_PZBall.csv";
    $t = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($plik));
    $h = fopen('php://memory', 'r+'); fwrite($h, $t); rewind($h);
    $wiersze = [];
    $naglowek = fgetcsv($h, 0, ';', '"', '');
    $zm = 0;
    while (($r = fgetcsv($h, 0, ';', '"', '')) !== false) {
        if (count($r) < 8) { $wiersze[] = $r; continue; }
        $zestawy = array_values(array_filter(array_map('trim', explode(';', $r[7])), fn($x) => $x !== ''));
        $zamrozone = in_array($r[2], TYTULY_ZAMROZONE, true) || in_array($r[1], OBSZARY_POZA, true);
        $dodaj = $zamrozone || in_array($r[1], OBSZARY_POZA, true) ? ['Poza pilotażem', 'Zamrożone'] : ['Pilotaż'];
        foreach ($dodaj as $z) if (!in_array($z, $zestawy, true)) { $zestawy[] = $z; $zm++; }
        $r[7] = implode('; ', $zestawy);
        $wiersze[] = $r;
    }
    $o = fopen($plik, 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, $naglowek, ';', '"', '');
    foreach ($wiersze as $r) fputcsv($o, $r, ';', '"', '');
    fclose($o);
    echo "$nazwa: " . count($wiersze) . " scenariuszy, dopisanych zestawów: $zm\n";
}
