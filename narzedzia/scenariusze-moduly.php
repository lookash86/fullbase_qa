<?php
/**
 * Scenariusze modułów PZBall, których nie było w plikach docs/scenariusze_*_PZBall.csv (26.11.2026, decyzja właściciela: „wszystkie moduły, nawet zamrożone, każda rola, telefon”).
 * Uruchom: php narzedzia/scenariusze-moduly.php  →  docs/scenariusze_moduly_PZBall.csv (separator „;”, UTF-8 z BOM — jak pozostałe pliki; import w rejestrze: Scenariusze → Import CSV).
 * Zestawy: „Pilotaż” — moduł w zakresie pilotażu PZBall 2027 (docs/KIERUNEK.md silnika), „Poza pilotażem” — reszta; „Zamrożone” — funkcja zamrożona (działa w demo, bez rozwoju);
 * „Telefon” — sprawdzić na telefonie; „Dzień meczowy na telefonie” — próba na prawdziwych telefonach.
 */
const P = 'Pilotaż';
const R = 'Poza pilotażem';
const Z = 'Zamrożone';
const T = 'Telefon';
const D = 'Dzień meczowy na telefonie';

$s = [];
$dodaj = function (string $obszar, string $tytul, string $warunki, array $kroki, string $wynik, string $priorytet, array $zestawy) use (&$s): void {
    $s[] = [$obszar, $tytul, $warunki, implode("\n", array_map(fn($k, $i) => ($i + 1) . '. ' . $k, $kroki, array_keys($kroki))), $wynik, $priorytet, implode('; ', $zestawy)];
};

/* ---------------- Dzień meczowy — nowe ---------------- */
$dodaj('Mecze i wyniki', 'Mecz się nie odbył (deszcz) — wpis w protokole i podpisy', 'Konto sedzia@ (sędzia główny meczu na dziś albo meczu pokazowego); klub2@ — kierownik drużyny gości.', [
    'Na telefonie zaloguj się jako sedzia@ i otwórz protokół organizacyjny meczu.',
    'Wybierz „Mecz się nie odbył”, powód „Warunki atmosferyczne (deszcz, burza)”, „czekano od” — godzina z terminarza, decyzja o odwołaniu — 2 godziny później, zaznacz obie drużyny obecne.',
    'Zapisz. Jako klub2@ otwórz ten sam protokół i kliknij „Akceptuję”.',
    'Jako sedzia@ podpisz wpis.',
], 'Mecz ma status „przełożony”, wpis pokazuje obie godziny; drużyny i sędziowie dostają powiadomienie; brak przycisków protestu i wniosku o ukaranie; druk protokołu pokazuje wpis i podpisy. Biuro widzi mecz do ustalenia nowego terminu.', 'Wysoki', [P, T, D]);
$dodaj('Mecze i wyniki', 'Mecz się nie odbył — drużyna się nie stawiła, propozycja walkowera', 'Konto sedzia@ jak wyżej.', [
    'W protokole wybierz „Mecz się nie odbył”, powód „Drużyna nie stawiła się”, zaznacz obecną tylko drużynę gospodarzy.',
    'Zapisz i sprawdź propozycję systemu.',
    'Kliknij „Zgłoś walkower”. Jako wydzial@ otwórz Walkowery i rozstrzygnij.',
], 'System tylko proponuje walkower (sam go nie wpisuje); po zgłoszeniu i decyzji biura mecz ma wynik walkowerowy, a klub, który się nie stawił, dostaje należność. Mecz bez zgłoszenia zostaje zaplanowany.', 'Wysoki', [P, D]);
$dodaj('Mecze i wyniki', 'Ostrzeżenie 15 minut przed meczem o brakach', 'Mecz pokazowy na dziś (Ustawienia → Tryb demonstracyjny); gospodarz bez wpisanego medyka; jedna drużyna niezweryfikowana.', [
    'Ustaw mecz tak, by zaczynał się za ok. 15 minut (biuro: karta meczu → godzina).',
    'Jako sedzia@ otwórz protokół organizacyjny tego meczu; jako klub@ — powiadomienia.',
], 'Na protokole ramka z brakami (medyk, drużyna bez weryfikacji); gospodarz i sędziowie mają powiadomienie; po uzupełnieniu medyka brak znika.', 'Średni', [P, T, D]);
$dodaj('Mecze i wyniki', 'Weryfikacja po meczu z pliku iScore', 'Mecz z wgranym box score (Wgraj statystyki iScore); w pliku zawodnik, którego nie było w kadrze meczowej.', [
    'Jako wydzial@ otwórz Rozbieżności weryfikacji → „Weryfikacja po meczu”.',
    'Znajdź mecz i rozbieżność „Zagrał bez sprawdzenia przez sędziego”; wpisz wyjaśnienie, potem je cofnij.',
], 'Rozbieżność jest na liście z rodzajem; „Wyjaśnione” zapisuje uwagę, cofnięcie przywraca; system nie zmienia wyniku sam.', 'Średni', [P]);
$dodaj('Mecze i wyniki', 'Wniosek o ukaranie i skarga na sędziego po meczu', 'Mecz zakończony w systemie dziś; klub@ — zarząd drużyny.', [
    'Jako klub@ na protokole kliknij „Zgłaszam…” → „Wniosek o ukaranie”, wskaż zawodnika drużyny przeciwnej, opisz, złóż.',
    'Złóż też skargę na sędziego.',
    'Jako wydzial@ (albo wd@) rozstrzygnij wniosek, jako zarzad@ — skargę.',
], 'Wniosek przyjęty w terminie 120 minut od „Zakończ mecz”, po terminie oznaczony; rozstrzygnięcie trafia do klubów komunikatem z potwierdzeniem przeczytania; kaucja jako należność klubu.', 'Średni', [P]);
$dodaj('Mecze i wyniki', 'Awaryjny Transfer Medyczny (ATM)', 'klub@ — zarząd; zawodnik z zatwierdzoną pozycją listy ligowej z wymaganymi występami.', [
    'Na liście startowej kliknij „ATM” przy zawodniku, zaznacz zgodę zawodnika, wgraj dokument (PDF), złóż.',
    'Sprawdź należność ATM w Opłatach klubu.',
    'Jako zarzad@ (Zarząd związku) otwórz wniosek: najpierw bez wpłaty, potem po zaznaczeniu wpłaty — zatwierdź.',
    'Jako lekarz@ otwórz dokumentację; jako wydzial@ spróbuj ją otworzyć.',
], 'Zgoda możliwa dopiero po wpłacie; zawodnik „wycofany w trybie ATM”; dokumentację otwiera Zarząd i lekarz (wpis w rejestrze), Wydział Gier — brak dostępu; klub widzi tylko liczbę plików.', 'Wysoki', [P]);

/* ---------------- Licencje, badania, NNW, zmiana barw ---------------- */
$dodaj('Licencje zawodników', 'Biuro wnioskuje o licencję sędziego', 'wydzial@; osoba bez klubu z kartoteką.', [
    'Kartoteka osoby → Licencje → wniosek o licencję sędziego (dyscyplina), zatwierdź.',
    'Wygeneruj kod e-licencji i wyślij e-mailem.',
], 'Licencja sędziego bez klubu; kod można wygenerować; e-licencja sędziego pokazuje zakres „Może sędziować”.', 'Średni', [P]);
$dodaj('Licencje zawodników', 'Licencje z pliku (dane startowe biura)', 'wydzial@; szablon z ekranu „Licencje z pliku”.', [
    'Licencje → „Z pliku”: pobierz szablon, dopisz 3 wiersze (jeden z błędnym PESEL-em), wczytaj.',
    'Sprawdź podgląd, zapisz dobre wiersze.',
], 'Podgląd pokazuje błąd przy złym wierszu, zapis tylko dobrych; numer licencji stały dla osoby; w rejestrze zdarzeń brak PESEL-u.', 'Średni', [P]);
$dodaj('Licencje zawodników', 'Badania: prośba o lepsze zdjęcie', 'klub@ wgrał skan badań zawodnika; wydzial@ weryfikuje.', [
    'Jako wydzial@ otwórz badanie do weryfikacji → „Poproś klub o lepsze zdjęcie”, zaznacz powód.',
    'Jako klub@ otwórz powiadomienie, wgraj nowe zdjęcie w tym samym wpisie (na telefonie — zdjęcie z aparatu).',
], 'Badanie „do poprawy”, zawodnik bez prawa gry z opisem; po nowym zdjęciu wpis wraca do kolejki biura; stary plik znika.', 'Wysoki', [P, T]);
$dodaj('Licencje zawodników', 'Badania z pliku (biuro)', 'wydzial@.', ['Badania → „Z pliku”: szablon, wczytaj 2 wiersze, zapisz.'],
    'Zapisany tylko wynik (bez rozpoznań); badania widać w kartotekach.', 'Niski', [P]);
$dodaj('Finanse i opłaty', 'Polisy NNW z pliku od ubezpieczyciela', 'klub@; drużyna z listą startową.', [
    'NNW → „Z pliku”: wpisz polisę z kodem drużyny i drugą z PESEL-em zawodnika, wczytaj, zapisz.',
    'Jako wydzial@ zweryfikuj polisę.',
], 'Polisa grupowa obejmuje całą listę drużyny; do weryfikacji biura; po weryfikacji warunek NNW spełniony.', 'Średni', [P]);
$dodaj('Kluby', 'Wypożyczenie zawodnika', 'klub@ (BORSUKI) i klub2@ (KORMORANY).', [
    'Jako klub2@ złóż wniosek o wypożyczenie zawodnika BORSUKÓW na sezon.',
    'Jako klub@ wyraź zgodę; jako wydzial@ zatwierdź.',
    'Otwórz stronę rozgrywek → Wypożyczenia.',
], 'Zawodnik może grać w KORMORANACH w okresie wypożyczenia; ostrzeżenie przy klasie rozgrywek spoza zasad sezonu; wypożyczenie na publicznej liście.', 'Średni', [P]);
$dodaj('Kluby', 'Zmiana barw zawodnika bez PESEL-u (urodzony za granicą)', 'Zawodnik innego klubu z zaznaczonym „bez PESEL-u” i stałym numerem licencji.', [
    'Jako klub2@ → Zmiany barw → „Nowy wniosek” → zawodnik innego klubu bez PESEL-u: numer licencji i data urodzenia.',
    'Spróbuj z błędną datą.',
], 'Trafienie tylko przy zgodnym numerze i dacie; zła data — „nie znaleziono” bez ujawniania danych; wyszukiwanie w rejestrze zdarzeń.', 'Średni', [P]);
$dodaj('Kluby', 'Prośba zawodnika o zmianę barw', 'rodzic@ albo konto zawodnika (osoba).', [
    'Jako rodzic@ → Zmiana barw → poproś klub docelowy.',
    'Jako zarząd klubu docelowego wejdź z prośby w wniosek.',
], 'Prośba trafia do klubu docelowego; wniosek formalny dalej składa klub.', 'Niski', [R, Z]);

/* ---------------- Rozgrywki: kreator ligi i sezonu ---------------- */
$dodaj('Rozgrywki i terminarze', 'Kreator ligi: kalendarz, udział klubów i format', 'wydzial@; sezon planowany otwarty.', [
    'Rozgrywki → klasa D1 → Kreator: kalendarz (od–do, dni gry, daty zablokowane, terminy rezerwowe).',
    'Krok „Potwierdzenie udziału”: wyślij prośbę do klubów z terminem.',
    'Jako klub@ → „Udział w rozgrywkach” → potwierdź z nazwą drużyny; jako klub2@ — rezygnacja z powodem.',
    'W kreatorze krok Format: dwie grupy, dwumecze, play-off.',
], 'Odpowiedzi klubów widoczne w kreatorze („odpowiedziało N z M”); Format podpowiada tylko kluby potwierdzone; wersja robocza niewidoczna publicznie.', 'Wysoki', [P]);
$dodaj('Rozgrywki i terminarze', 'Kreator ligi: terminy klubów, daty sporne, generator i publikacja', 'Klasa po kroku Format.', [
    'Wyślij prośbę o terminy; jako klub@ zgłoś datę „Nie możemy tylko u siebie” z powodem.',
    'Jako wydzial@ rozstrzygnij datę sporną (Kreator sezonu albo Daty sporne).',
    'Wygeneruj warianty terminarza, wybierz wariant, przestaw jeden mecz (sprawdzenie na żywo).',
    'Wyślij do uwag klubów, rozpatrz uwagę, zatwierdź i opublikuj.',
], 'Generator nie układa meczu w dacie „klub nie gra”; ostrzeżenia na żywo; po publikacji mecze widać w terminarzu, na stronie rozgrywek i w kalendarzu klubów; przed publikacją — nigdzie.', 'Wysoki', [P]);
$dodaj('Rozgrywki i terminarze', 'Kolejność w tabeli i decyzja Wydziału przy remisie', 'Rozgrywki z dwiema drużynami o tym samym bilansie.', [
    'Rozgrywki → „Kolejność w tabeli”: sprawdź kryteria sezonu, zmień kolejność kryteriów (z podstawą, jeśli zasady przyjęte).',
    'Na karcie rozgrywek przy remisie wpisz decyzję Wydziału.',
], 'Miejsca się nie powtarzają; „wymaga decyzji Wydziału” przy remisie po ostatnim meczu; zasady jawne pod tabelą i na stronie rozgrywek.', 'Średni', [P]);
$dodaj('Rozgrywki i terminarze', 'Schemat awansów play-off i zatwierdzenie pary', 'Faza grupowa D1 zakończona (dane pokazowe).', [
    'Kreator → Play-off: wzór PLB D1, propozycja par z tabel.',
    'Zatwierdź parę, zmień gospodarza z powodem.',
], 'Mecze serii powstają według układu; drużyna z remisu w tabeli nie wchodzi bez decyzji; zwycięzca przechodzi dalej po wynikach.', 'Średni', [P]);
$dodaj('Sezony i ustawienia', 'Kreator sezonu: zasady, cennik, przyjęcie uchwałą', 'wydzial@ (albo admin@); sezon planowany.', [
    'Menu „Kreator sezonu”: przejdź kroki 1–6; w „Zasady sezonu” zatwierdź działy ustawień.',
    'Cennik opłat: zmień kwotę.',
    'Przyjmij zasady uchwałą (numer i data).',
    'Spróbuj potem zmienić ustawienie sezonu bez podstawy, potem z podstawą „od jutra” i komunikatem do klubów.',
], 'Po przyjęciu zmiana tylko z podstawą; zmiana od jutra czeka do dnia; kluby dostają komunikat „pole: było → jest” z potwierdzeniem przeczytania.', 'Wysoki', [P]);
$dodaj('Sezony i ustawienia', 'Zamknięcie sezonu i blokada zapisu', 'admin@; środowisko testowe (operacja zmienia sezon!) — tylko po uzgodnieniu.', [
    'Sezony → „Zamknij sezon”: przeczytaj podgląd i ostrzeżenia.',
    'Po zamknięciu spróbuj zmienić listę startową zamkniętego sezonu.',
    'Przywróć sezon do edycji z powodem.',
], 'Zamknięcie otwiera następny sezon (jeśli nie było planowanego); w zamkniętym sezonie zapis zablokowany z komunikatem; przywrócenie w rejestrze.', 'Niski', [P]);

/* ---------------- Sędziowie ---------------- */
$dodaj('Sędziowie i obsada', 'Wytyczne obsady przyjęte decyzją Wydziału', 'kolegium@ (Wydział ds. Sędziów).', [
    'Sędziowie → Wytyczne obsady: zmień najdłuższy dojazd i kolejność kryteriów, przyjmij decyzją.',
    'Otwórz obsadę meczu i sprawdź grupy kandydatów.',
], 'Kandydaci w grupach „Polecani / Poza wytycznymi / Niezalecani” według nowych wytycznych; zmiana w historii.', 'Średni', [P]);
$dodaj('Sędziowie i obsada', 'Zakres sędziego i podpowiedź z klasy', 'kolegium@.', [
    'Profil sędziego → Zakres rozgrywek: dodaj zakres z podpowiedzi klasy i wyjątek „bez uprawnień” dla jednych rozgrywek.',
    'Jako sedzia@ otwórz e-licencję sędziego.',
], 'E-licencja pokazuje „Może sędziować” z klasami; sędzia poza zakresem jest w obsadzie w grupie „Poza wytycznymi” z ostrzeżeniem.', 'Średni', [P]);
$dodaj('Sędziowie i obsada', 'Bilet zamiast kilometrówki', 'sedzia@ z podpisaną obsadą i delegacją do podpisu.', [
    'W delegacji wybierz bilet (kolej), kwota, zdjęcie biletu.',
    'Jako kolegium@ → Koszty sędziów → bilety: przyjmij do limitu.',
], 'Do decyzji podpis czeka; po przyjęciu bilet zastępuje kilometry w pokwitowaniu i PIT.', 'Średni', [P, T]);
$dodaj('Sędziowie i obsada', 'Koszty sędziów: należność klubu i rozliczenie', 'wydzial@; dwumecz z pełnym składem.', [
    'Sędziowie → Koszty sędziów: nalicz należność za zawody.',
    'Jako klub@ → Koszty sędziów: sprawdź kwoty bez nazwisk sędziów.',
    'Po podpisaniu delegacji — „Rozlicz”.',
], 'Należność raz, termin — ostatni dzień roboczy przed meczem; rozliczenie koryguje kwotę do kosztu z delegacji.', 'Średni', [P]);
$dodaj('Sędziowie i obsada', 'Roczne oświadczenie sędziego o funkcjach w klubie', 'sedzia@.', ['Moje sędziowanie → Oświadczenie: wpisz funkcję trenera w klubie, zapisz.', 'Jako kolegium@ otwórz obsadę meczu tego klubu.'],
    'Sędzia z oświadczeniem ma konflikt interesów z klubem w obsadzie.', 'Niski', [P]);
$dodaj('Sędziowie i obsada', 'Kandydat na sędziego z formularza publicznego', 'Bez logowania; potem kolegium@.', [
    'Otwórz publiczny formularz kandydata na sędziego, wyślij zgłoszenie z e-mailem.',
    'Potwierdź link z e-maila.',
    'Jako kolegium@ → Kandydaci na sędziów: przyjmij kandydata (stopień, kurs).',
], 'Kandydat potwierdzony linkiem; przyjęcie zakłada kartotekę, wniosek o licencję i zaproszenie do konta.', 'Niski', [R, Z]);

/* ---------------- Opłaty i finanse ---------------- */
$dodaj('Finanse i opłaty', 'Cennik opłat sezonu i progi dat', 'wydzial@; klub@.', [
    'Cennik opłat: zmień stawkę licencji zawodnika w progu po dacie.',
    'Jako klub@ otwórz Cennik opłat (tylko odczyt).',
], 'Zmiana zapisana tylko tam, gdzie inna; klub widzi cennik bez formularza; należności naliczone wcześniej się nie zmieniają.', 'Średni', [P]);
$dodaj('Finanse i opłaty', 'Wyciąg z banku: wpłaty klubów', 'wydzial@ (albo admin@); plik CSV z tytułem NAL-<numer>.', [
    'Należności → „Wyciąg z banku”: wczytaj plik, sprawdź dopasowanie po numerze i kwocie.',
    'Zatwierdź dopasowane, jedną wpłatę przypisz ręcznie; wczytaj plik drugi raz.',
], 'Wpłaty zaksięgowane raz (drugi raz — bez dubli); należności zapłacone; klub widzi wpłatę.', 'Średni', [P]);
$dodaj('Finanse i opłaty', 'Składka członkowska klubu w związku', 'wydzial@; klub@.', ['Kluby → karta klubu: składka opłacona do; finanse — zestawienie.', 'Jako klub@ sprawdź swoją kartę.'],
    'Data składki na karcie i liście klubów; klub widzi swoją, innych — nie.', 'Niski', [P]);

/* ---------------- Zawieszenia i Wydział Dyscypliny ---------------- */
$dodaj('Sprawy i kary', 'Zawieszenie ręczne i uchylenie', 'wd@ albo wydzial@.', [
    'Kartoteka zawodnika → Zawieszenia: wpisz zawieszenie na 2 mecze w rozgrywkach.',
    'Sprawdź protokół uprawnień najbliższego meczu jego drużyny i e-licencję.',
    'Uchyl zawieszenie z powodem.',
], 'Zawodnik nieuprawniony z powodem „zawieszenie”; e-licencja „Nieuprawniony”; po uchyleniu wraca prawo gry.', 'Wysoki', [P]);
$dodaj('Sprawy i kary', 'Raport sędziego do Wydziału Dyscypliny', 'sedzia@ z obsadą meczu zakończonego.', ['Na protokole „Raport do Wydziału Dyscypliny”: wskaż osobę z listy, opisz.', 'Jako wd@ otwórz sprawę.'],
    'Sprawa w toku u Wydziału; klub nie widzi sprawy w toku, tylko orzeczenia.', 'Średni', [P]);
$dodaj('Sprawy i kary', 'Rejestr orzeczeń do Excela i orzeczenie do druku', 'wd@.', ['Sprawy dyscyplinarne → eksport rejestru.', 'Otwórz orzeczenie → druk/PDF.'],
    'Eksport bez uzasadnień i opisów; wpis eksportu w rejestrze; dokument ze składem orzekającym.', 'Niski', [P]);

/* ---------------- Strona rozgrywek ---------------- */
$dodaj('Strona rozgrywek / redakcja', 'Osobne strony PLB, PLSK i Pucharu Polski', 'Bez logowania.', [
    'Otwórz adresy /plb, /plsk i /pp.',
    'Na każdej sprawdź terminarz, tabele, statystyki i kluby; na PLSK — napisy „Zawodniczki”, „Seniorki”.',
], 'Każda strona tylko ze swoimi rozgrywkami; formy żeńskie tylko na PLSK; stopka z linkami do pozostałych stron.', 'Wysoki', [P, T]);
$dodaj('Strona rozgrywek / redakcja', 'Kalendarz kibica, RSS i widżet na stronę klubu', 'Bez logowania; potem redaktor@.', [
    'Na stronie drużyny „Dodaj mecze do kalendarza” — dodaj do kalendarza w telefonie.',
    'Otwórz kanał RSS aktualności.',
    'Jako redaktor@ → Gotowość strony → skopiuj kod widżetu, otwórz adres widżetu.',
], 'Kalendarz z meczami drużyny (odwołane jako odwołane); RSS z opublikowanymi tekstami; widżet bez formularzy, działa w ramce.', 'Niski', [P, T]);
$dodaj('Strona rozgrywek / redakcja', 'Strona zawodnika i statystyki na stronie', 'Bez logowania; redaktor@.', [
    'Otwórz stronę zawodnika z tabeli statystyk.',
    'Jako redaktor@ → Statystyki na stronie: ukryj kolumnę OPS w młodzieży.',
], 'Zawodnik z liczbami sezonu i miejscem w lidze, bez daty urodzenia i PESEL-u; ukryta kolumna znika tylko w młodzieży.', 'Średni', [P]);
$dodaj('Strona rozgrywek / redakcja', 'Szkice tekstów pisane przez system', 'redaktor@; kolejka z kompletem zatwierdzonych wyników.', ['Redakcja strony: otwórz szkic „Podsumowanie kolejki”, popraw i opublikuj.'],
    'Szkic powstaje sam i czeka na redaktora; nic nie trafia na stronę bez publikacji.', 'Niski', [R, Z]);

/* ---------------- Rdzeń: konta, start klubów, komunikacja ---------------- */
$dodaj('Kluby', 'Start klubu: link konta i władze klubu', 'admin@; nowy klub z adresem e-mail (w demo poczta do pliku — link z podglądu).', [
    'Kluby → Dodaj klub z e-mailem → link do konta klubu.',
    'Otwórz link, załóż konto (imię, nazwisko, funkcja, hasło).',
    'Przejdź Start klubu: władze klubu (zarząd), pokaz „Co klub zrobi w systemie”.',
], 'Do końca startu klub widzi tylko kroki startu; po zakończeniu — pełne menu; biuro widzi stan startu klubów.', 'Wysoki', [P]);
$dodaj('Członkowie / osoby', 'Osoby z pliku (dane startowe klubu)', 'klub@.', [
    'Osoby → „Osoby z pliku”: szablon, 3 osoby (dorosły, niepełnoletni z opiekunem, osoba bez PESEL-u z datą i płcią), wczytaj, zapisz.',
], 'Podgląd z zamaskowanym PESEL-em; data i płeć z PESEL-u; opiekun dopisany; osoba z innego klubu — błąd bez ujawniania danych.', 'Wysoki', [P]);
$dodaj('Członkowie / osoby', 'Zaproszenie kierownika do konta', 'klub@.', [
    'Kartoteka osoby → „Zaproś do systemu” z rolą kierownika.',
    'Otwórz link z e-maila, ustaw hasło, zaloguj się.',
], 'Konto z rolą kierownika w klubie; zaproszenie na liście „Zaproszenia” jako przyjęte; link drugi raz nie działa.', 'Średni', [P]);
$dodaj('Członkowie / osoby', 'Zgłoszenie zmiany danych przez osobę', 'rodzic@ (dziecko w kartotece).', [
    'Kartoteka dziecka → zakładka Dane → zgłoś zmianę telefonu.',
    'Jako klub@ → Zmiany danych → przyjmij.',
], 'Kartoteka zmieniona dopiero po przyjęciu; rodzic dostaje powiadomienie; PESEL-u nie da się zgłosić.', 'Niski', [P]);
$dodaj('Powiadomienia i ankiety', 'Zapytanie do klubów i ankieta z wynikami', 'Dane pokazowe: „Stan klubu przed sezonem” i „Pilotaż 2027 — gotowość klubu”.', [
    'Jako klub2@ → Ankiety → wypełnij zapytanie (sprawdź podpowiedź „W systemie: N”).',
    'Jako wydzial@ otwórz wyniki obu ankiet, eksport do Excela.',
    'Utwórz nową ankietę z szablonu i pytaniem warunkowym, otwórz ją.',
], 'Jedna odpowiedź na klub; wyniki z liczbami; pytanie warunkowe pokazuje się tylko przy wybranej odpowiedzi; powiadomienie do adresatów.', 'Średni', [P]);
$dodaj('Powiadomienia i ankiety', 'Komunikat biura do klubów w rozgrywkach', 'wydzial@.', [
    'Komunikaty → nowy: grupa „Kluby w rozgrywkach”, potwierdzenie przeczytania z terminem, załącznik PDF, opublikuj.',
    'Jako klub@ przeczytaj i potwierdź; jako wydzial@ sprawdź listę odbiorców i eksport.',
], 'Odbiorcy z migawki; klub bez konta — e-mail na adres klubu; po potwierdzeniu przypomnienia znikają.', 'Średni', [P]);
$dodaj('Rozgrywki i terminarze', 'Spotkanie w kalendarzu z zaproszonymi', 'wydzial@.', [
    'Kalendarz → „Dodaj wydarzenie” → spotkanie, zaproś kolegium@ i klub@.',
    'Jako kolegium@ odpowiedz „przyjmuję”; jako wydzial@ zmień godzinę, potem odwołaj.',
], 'Zaproszenie, zmiana i odwołanie — powiadomienia; odpowiedzi widoczne u zapraszającego.', 'Niski', [P]);
$dodaj('Kluby', 'Obiekty i boiska: weryfikacja boiska', 'admin@ albo komisja licencyjna; klub@.', [
    'Obiekty i boiska → obiekt klubu: dodaj boisko, godziny dostępności, wyłączenie.',
    'Wpisz weryfikację boiska z datą ważności przed meczem.',
    'Otwórz kartę meczu na tym boisku.',
], 'Ostrzeżenie na karcie meczu o wygasłej weryfikacji i kolizji; klub zmienia tylko swoje obiekty.', 'Niski', [P]);
$dodaj('Panel administracyjny', 'Role klubów wspólne dla wszystkich klubów', 'admin@; klub@.', [
    'Role i uprawnienia: odbierz trenerowi „listy startowe” dla wszystkich klubów.',
    'Jako trener@ otwórz listę startową.',
    'Jako klub@ otwórz Role i uprawnienia.',
], 'Trener bez zapisu listy, okno „Brak dostępu” z informacją, kto to robi; klub nie zmienia ról w związku.', 'Średni', [P]);
$dodaj('Logowanie i konta', 'Odcisk palca (klucz dostępu) i nowe urządzenie', 'Dowolne konto; telefon z czytnikiem.', [
    'Zaloguj się hasłem na telefonie — w oknie „Logować się odciskiem palca?” wybierz „Tak”.',
    'Wyloguj się i zaloguj odciskiem palca.',
    'Zaloguj się na drugim urządzeniu; otwórz Moje konto → Bezpieczeństwo.',
], 'Logowanie odciskiem bez hasła; e-mail o nowym urządzeniu; lista urządzeń z „Usuń”; „Wyloguj na innych urządzeniach” działa.', 'Wysoki', [P, T]);
$dodaj('Logowanie i konta', 'Kto oglądał moje dane', 'rodzic@ i klub@.', ['Jako klub@ otwórz kartotekę dziecka rodzica.', 'Jako rodzic@ → Moje konto → Bezpieczeństwo → „Kto oglądał moje dane”.'],
    'Wpis z rolą i klubem, bez nazwiska pracownika; własne wejścia rodzica się nie liczą.', 'Niski', [P]);
$dodaj('Logowanie i konta', 'Pomoc w systemie dla każdej roli', 'Kolejno: klub@, klub2@, sedzia@, wydzial@, kolegium@, rodzic@.', ['Menu konta → Pomoc: przejrzyj tematy, kliknij „Przejdź” przy dwóch.'],
    'Każda rola widzi tylko swoje tematy; „Przejdź” otwiera właściwy ekran; treść zgodna z ekranem (zgłoś każdą różnicę).', 'Średni', [P, T]);
$dodaj('Aplikacja mobilna', 'E-licencja po angielsku', 'Telefon z aktywną e-licencją.', ['Karta → Więcej → język English.', 'Pokaż kod sędziemu do skanu.'],
    'Teksty po angielsku, nazwy własne bez zmian; kod QR ten sam i przechodzi skan.', 'Niski', [P, T]);
$dodaj('Aplikacja mobilna', 'Powiadomienia push na telefonie', 'Konto sedzia@ na telefonie z zainstalowaną aplikacją.', [
    'Moje konto → Telefon / Powiadomienia: włącz powiadomienia push.',
    'Jako kolegium@ wyślij sędziemu zapytanie o obsadę.',
], 'Na telefonie powiadomienie; dotknięcie otwiera sprawę; liczba na ikonie aplikacji.', 'Średni', [P, T]);

/* ---------------- Turnieje (w pilotażu na start wyłączone) ---------------- */
$dodaj('Rozgrywki i terminarze', 'Mistrzostwa: skład na turniej i weryfikacja komisji', 'Dane pokazowe: Mistrzostwa Polski Kadetów U15 (przed turniejem); klub@ z drużyną.', [
    'Jako klub@ → turniej → Skład: dodaj zawodników, sprawdź warunki gry.',
    'Jako komisja techniczna (admin@) zweryfikuj osoby i zatwierdź drużynę.',
    'Wydrukuj listę do podpisu.',
], 'Po terminie składów zmienia tylko komisja; osoba nieuprawniona oznaczona; lista do druku z podpisami.', 'Średni', [R, T]);
$dodaj('Rozgrywki i terminarze', 'Mistrzostwa: weryfikacja składu bez sieci', 'Konto komisji technicznej; telefon; zawodnicy z e-licencją.', [
    'Otwórz „Weryfikacja składu” mistrzostw, pobierz turniej.',
    'Włącz tryb samolotowy, zeskanuj e-licencję zawodnika.',
    'Wyłącz tryb samolotowy.',
], 'Skan działa bez sieci; decyzja wysyła się po powrocie sieci; drużyna zweryfikowana.', 'Średni', [R, T]);
$dodaj('Rozgrywki i terminarze', 'Harmonogram, akceptacja związku i raport', 'Turniej klubu w kalendarzu związku.', [
    'Organizator układa harmonogram (warianty), wysyła do akceptacji.',
    'Związek akceptuje; organizator wpisuje wyniki i klasyfikację, wysyła.',
    'Otwórz raport dzienny i końcowy, dyplomy.',
], 'Publicznie tylko zaakceptowany harmonogram; po zatwierdzeniu wyników poprawia tylko związek; raport i dyplomy do druku.', 'Niski', [R]);

/* ---------------- Zamrożone moduły (w demo działają) ---------------- */
$dodaj('Kadra narodowa', 'Narzędzia sztabu i skład na zawody', 'admin@ (albo selekcjoner).', [
    'Kadra narodowa → kadra → Narzędzia sztabu: notatka o zawodniku, obserwowany spoza kadry.',
    'Zgrupowanie → skład na zawody z limitem, ogłoś skład.',
    'Jako klub@ sprawdź „Powołania do kadry”; jako rodzic@ — „Moja reprezentacja”.',
], 'Notatki widzi tylko sztab tej kadry; ogłoszony skład — powiadomienia; klub widzi tylko swoich zawodników.', 'Niski', [R, Z]);
$dodaj('Organy i komisje', 'Pismo do klubów przez system', 'wydzial@ (Kancelaria).', [
    'Kancelaria → „Pismo do klubów przez system”: treść, adresaci — kluby w rozgrywkach, termin odpowiedzi, wyślij.',
    'Jako klub@ → Pisma ze związkiem: otwórz pismo, odpowiedz.',
], 'Numer pisma nadany przy wysłaniu; doręczenie i odpowiedź widoczne u związku; treść po wysłaniu niezmienna.', 'Niski', [R, Z]);
$dodaj('Organy i komisje', 'Pismo z firmówką do druku', 'wydzial@.', ['Kancelaria → nowe pismo pisane w systemie z firmówką, zatwierdź numer, druk.'],
    'Wersja robocza bez numeru; po numerze treść zablokowana; druk A4 z firmówką.', 'Niski', [R, Z]);
$dodaj('Organy i komisje', 'Rozmowy grup związku (komunikator)', 'kolegium@ i sedzia@.', [
    'Rozmowy → rozmowa „Sędziowie”: kolegium@ pisze ważną wiadomość ze zdjęciem.',
    'Jako sedzia@ na telefonie: przeczytaj, zareaguj, spróbuj napisać.',
    'Wycisz rozmowę; napisz wiadomość do kolegium@ (rozmowa dwóch osób).',
], 'Sędzia czyta, nie pisze w rozmowie sędziów; chmurka push; „Przeczytane”; wyciszona rozmowa bez liczby w menu; kluby nie mają rozmów.', 'Niski', [R, Z, T]);
$dodaj('Organy i komisje', 'Posiedzenie zarządu z głosowaniem na telefonach', 'Moduł posiedzeń włączony (demo); prezes@, sekretarz@, czlonek@.', [
    'Jako admin@ zwołaj posiedzenie Zarządu kreatorem (porządek, materiały, zaproszeni).',
    'Rozpocznij, obecność, głosowanie na telefonach — członkowie głosują.',
    'Zakończ, protokół do druku; głosowanie obiegowe uchwały.',
], 'Kworum liczone; uchwała z numerem w rejestrze; tajne — bez imiennych głosów; protokół z wynikami.', 'Niski', [R, Z, T]);
$dodaj('Organy i komisje', 'Walny zjazd: delegaci klubów i rejestracja na sali', 'Moduł walne włączony; delegat@.', [
    'Jako klub@ → „Delegaci na walny zjazd”: wpisz delegata.',
    'Jako admin@ otwórz ekran rejestracji na rzutnik; delegat@ skanuje kod telefonem i potwierdza.',
    'Głosowanie jawne w mandatach.',
], 'Kworum i obecni bez nazwisk na ekranie; kod zmienia się co kilka sekund; uchwała w rejestrze.', 'Niski', [R, Z, T]);
$dodaj('Organy i komisje', 'Komisja Rewizyjna: kontrola i zalecenie', 'kr@ (Komisja Rewizyjna).', [
    'Komisja Rewizyjna → nowa kontrola, ustalenia, zalecenie z terminem.',
    'Jako zarzad@ odpowiedz na zalecenie; jako kr@ oceń odpowiedź.',
], 'Zalecenie z odpowiedzią „czeka na ocenę komisji”; wgląd komisji tylko do odczytu; wpisy w rejestrze.', 'Niski', [R, Z]);
$dodaj('Szkolenia', 'Kurs z płatnikiem, egzaminem i zaświadczeniem', 'admin@; klub@.', [
    'Szkolenia → nowy kurs trenerów z opłatą „do wyboru”, otwórz zapisy.',
    'Jako klub@ zapisz trenera (płaci klub); drugą osobę — płaci uczestnik.',
    'Obecność, wynik egzaminu, zakończ kurs, zaświadczenie.',
], 'Należność klubu tylko za płacącego klub; uczestnik ma dane do przelewu; zaświadczenie z numerem; stopień w kartotece.', 'Niski', [R, Z]);
$dodaj('Organy i komisje', 'Przepisy: nowa wersja i uwagi klubów', 'admin@; klub@.', ['Przepisy → nowa wersja regulaminu, uwagi klubów do terminu.', 'Jako klub@ dodaj uwagę.', 'Przyjmij wersję, porównaj z poprzednią.'],
    'Porównanie słowo po słowie; przyjęta wersja opublikowana na stronie rozgrywek.', 'Niski', [R, Z]);
$dodaj('Organy i komisje', 'Dotacje: projekt, koszty z systemu, sprawozdanie', 'admin@.', [
    'Dotacje → nowy projekt z budżetem i harmonogramem.',
    'Koszty: dodaj podpowiedź z delegacji sędziów.',
    'Wskaźniki: przelicz; sprawozdanie do druku i Excela.',
], 'Ten sam koszt nie wchodzi dwa razy; wskaźniki z danych systemu; sprawozdanie bez nazwisk sędziów.', 'Niski', [R, Z]);
$dodaj('Panel administracyjny', 'Asystent: szkic komunikatu', 'admin@; asystent z kluczem w konfiguracji (bez klucza — podpowiedź).', ['Asystent → napisz szkic komunikatu do klubów.'],
    'Szkic do poprawy, nic się nie zapisuje samo; procent puli; bez klucza — jasna informacja.', 'Niski', [R, Z]);
$dodaj('Członkowie / osoby', 'Rozwój zawodnika: testy i karta', 'admin@ albo trener kadry.', ['Rozwój zawodnika → wynik testu, ocena umiejętności.', 'Karta zawodnika do druku.'],
    'Porównanie z rówieśnikami tej samej płci; karta bez danych o zdrowiu.', 'Niski', [R, Z]);
$dodaj('Członkowie / osoby', 'Ochrona małoletnich w związku', 'admin@; klub@.', ['Ochrona małoletnich → osoby związku pracujące z dziećmi: wpis weryfikacji.', 'Jako klub@ → Standardy ochrony: wgraj standardy klubu.'],
    'Wpisy tylko biura; klub tylko standardy, bez danych z rejestru i KRK.', 'Niski', [R, Z]);
$dodaj('Kluby', 'Import danych z klubów (paczka)', 'admin@; paczka eksportu z FULLBASE dla klubów.', ['Import z klubów → wczytaj paczkę, sprawdź osoby, zatwierdź.'],
    'Osoby dopasowane po PESEL-u; nic nie zapisuje się bez zatwierdzenia.', 'Niski', [R, Z]);
$dodaj('Panel administracyjny', 'Zamrożone i użycie modułów', 'Konto operatora FULLBASE.', ['Operator → Zamrożone: obie zakładki.'],
    'Lista zamrożonych z „co by odmroziło”, stan w instalacji i w pilotażu, użycie z 8 tygodni.', 'Niski', [R]);

$plik = __DIR__ . '/../docs/scenariusze_moduly_PZBall.csv';
$h = fopen($plik, 'w');
fwrite($h, "\xEF\xBB\xBF");
fputcsv($h, ['Projekt', 'Obszar', 'Tytuł', 'Warunki wstępne', 'Kroki', 'Oczekiwany wynik', 'Priorytet', 'Zestawy'], ';', '"', '');
foreach ($s as $r) fputcsv($h, ['PZB', ...$r], ';', '"', '');
fclose($h);
echo count($s) . " scenariuszy → $plik\n";
