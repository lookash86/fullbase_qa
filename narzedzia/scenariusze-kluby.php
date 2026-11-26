<?php
/**
 * Scenariusze FULLBASE dla klubów (projekt KLB; demo test-klub.fullbase.pl — konta prezes@, sekretarz@, skarbnik@, trener@, rodzic@, zawodnik@demo.fullbase.pl, operator@demo.fullbase.pl, kr@demo.fullbase.pl).
 * 26.11.2026, decyzja właściciela: każdy moduł (także zamrożony), każda rola, telefon. Uruchom: php narzedzia/scenariusze-kluby.php → docs/scenariusze_KLB.csv.
 * Zestawy: „Szybki test” (najważniejsze), „Pełna regresja”, „Telefon”, „Zamrożone” (funkcja zamrożona w docs/KIERUNEK.md silnika — działa w demo, bez rozwoju), „Operator” (panel FULLBASE).
 * Obszary — lista projektu KLB z app/schema.php.
 */
const S = 'Szybki test';
const R = 'Pełna regresja';
const T = 'Telefon';
const Z = 'Zamrożone';
const O = 'Operator';

$s = [];
$dodaj = function (string $obszar, string $tytul, string $warunki, array $kroki, string $wynik, string $priorytet, array $zestawy) use (&$s): void {
    $s[] = [$obszar, $tytul, $warunki, implode("\n", array_map(fn($k, $i) => ($i + 1) . '. ' . $k, $kroki, array_keys($kroki))), $wynik, $priorytet, implode('; ', $zestawy)];
};

/* ---------- Logowanie i konta ---------- */
$dodaj('Logowanie i konta', 'Logowanie każdej roli i menu', 'Konta demo (hasło w menedżerze haseł).', ['Zaloguj się kolejno: prezes@, sekretarz@, skarbnik@, trener@, rodzic@, zawodnik@.', 'Na każdym obejrzyj menu i pulpit.'],
    'Prezes i sekretarz — pełne menu zarządu; skarbnik — finanse, bez planów treningów; trener — swoje sekcje, zajęcia, treningi; rodzic — dzieci, zajęcia, opłaty, dokumenty; zawodnik — swoje sprawy.', 'Krytyczny', [S, R]);
$dodaj('Logowanie i konta', 'Rodzic nie widzi innych dzieci', 'rodzic@.', ['Otwórz kartotekę swojego dziecka.', 'Zmień w adresie numer osoby na inny.'],
    'Kartoteka cudzego dziecka — brak dostępu; rodzic widzi tylko swoje dzieci.', 'Krytyczny', [S, R]);
$dodaj('Logowanie i konta', 'Zaproszenie rodzica do konta', 'sekretarz@; dziecko bez konta rodzica.', ['Kartoteka dziecka → „Zaproś do systemu” (opiekun z e-mailem).', 'Otwórz link, ustaw hasło, zaloguj się.'],
    'Konto rodzica z dostępem do dziecka; link drugi raz nie działa.', 'Wysoki', [S, R]);
$dodaj('Logowanie i konta', 'Odcisk palca na telefonie', 'Dowolne konto, telefon z czytnikiem.', ['Zaloguj się hasłem — „Logować się odciskiem palca?” → Tak.', 'Wyloguj i zaloguj odciskiem.'],
    'Logowanie bez hasła; nowe urządzenie w Moje konto → Bezpieczeństwo.', 'Średni', [R, T]);

/* ---------- Ludzie ---------- */
$dodaj('Ludzie / członkowie', 'Dodanie zawodnika z rodzicem', 'sekretarz@.', ['Osoby → „Dodaj osobę”: dziecko 10 lat, sekcja, rodzic z telefonem.', 'Zakładka Rodzice: osoba upoważniona do odbioru, samodzielny powrót.'],
    'Kartoteka z członkostwem w sekcji i rodzicem; data i płeć z PESEL-u; odbiór widoczny u trenera.', 'Krytyczny', [S, R]);
$dodaj('Ludzie / członkowie', 'Osoby z pliku', 'sekretarz@.', ['Osoby → „Osoby z pliku”: szablon, 5 osób z grupą i opiekunem, jeden błędny wiersz.', 'Zapisz dobre wiersze.'],
    'Podgląd z błędem przy wierszu, PESEL zamaskowany; osoby w grupach; opiekunowie dopisani.', 'Wysoki', [R]);
$dodaj('Ludzie / członkowie', 'Pełnoletność: rodzic traci wgląd', 'Zawodnik, który skończył 18 lat (albo zmiana daty w danych testowych).', ['Jako rodzic@ otwórz kartotekę pełnoletniego dziecka.', 'Jako zawodnik — „Pokaż moje dane” rodzicowi.'],
    'Bez zgody dorosłego rodzic nie widzi danych; po „Pokaż” — widzi; cofnięcie działa.', 'Średni', [R]);
$dodaj('Ludzie / członkowie', 'Zgłoszenie zmiany danych przez rodzica', 'rodzic@; sekretarz@.', ['Rodzic: kartoteka dziecka → zgłoś zmianę telefonu.', 'Sekretarz: Zmiany danych → przyjmij.'],
    'Zmiana dopiero po przyjęciu; rodzic dostaje powiadomienie.', 'Średni', [R]);
$dodaj('Ludzie / członkowie', 'Ewidencja członków stowarzyszenia i uchwała zbiorcza', 'sekretarz@ (moduł ewidencja członków).', ['Ewidencja członków: kandydaci, uchwała zbiorcza o przyjęciu.', 'Podejmij uchwałę na posiedzeniu zarządu.'],
    'Po podjęciu uchwały członkowie z numerami; poniżej 16 lat — wymagana zgoda rodzica.', 'Niski', [R, Z]);

/* ---------- Sekcje i zespoły ---------- */
$dodaj('Sekcje i zespoły', 'Sekcja, zespół i trener', 'prezes@.', ['Sekcje i zespoły → „Dodaj sekcję” (dyscyplina), „Dodaj zespół”.', 'Dopisz trenera i zawodników.', 'Zarchiwizuj zespół.'],
    'Trener widzi tylko swoje grupy; archiwum kończy przypisania, historia zostaje.', 'Wysoki', [S, R]);
$dodaj('Sekcje i zespoły', 'Trener widzi tylko swoje sekcje', 'trener@.', ['Otwórz Osoby i Zajęcia.', 'Spróbuj otworzyć zawodnika innej sekcji adresem.'],
    'Tylko zawodnicy swoich sekcji; inne — brak dostępu.', 'Krytyczny', [S, R]);

/* ---------- Kalendarz i treningi ---------- */
$dodaj('Kalendarz i treningi', 'Zajęcia: seria, „Będzie / Nie będzie”, obecność', 'trener@ i rodzic@.', ['Trener: Zajęcia → nowa seria (dzień tygodnia, obiekt).', 'Rodzic: „Nie będzie” przy dziecku.', 'Trener po zajęciach — lista obecności.'],
    'Deklaracja rodzica widoczna u trenera; obecność zapisana; frekwencja liczy się.', 'Krytyczny', [S, R, T]);
$dodaj('Kalendarz i treningi', 'Obecność na telefonie bez sieci', 'trener@ na telefonie.', ['Otwórz obecność na telefonie, pobierz zajęcia.', 'Tryb samolotowy, zaznacz obecność.', 'Włącz sieć.'],
    'Obecność wysyła się po powrocie sieci; „Kto odbiera” przy dziecku.', 'Wysoki', [R, T]);
$dodaj('Kalendarz i treningi', 'Grafik obiektów i kolizja', 'prezes@.', ['Dodaj zajęcia na tym samym boisku w tym samym czasie co inna grupa.', 'Obecność → Grafik obiektów.'],
    'Ostrzeżenie o kolizji (zapis i tak możliwy); grafik tygodnia z godzinami i kosztem wynajmu.', 'Średni', [R]);
$dodaj('Kalendarz i treningi', 'Plan treningu z biblioteki i dziennik zajęć', 'trener@.', ['Treningi: plan zajęć z blokami z biblioteki ćwiczeń.', 'Po zajęciach dziennik: realizacja bloków, notatka.'],
    'Plan przy zajęciach; ostrzeżenie, gdy plan dłuższy niż zajęcia; realizacja w raporcie trenera.', 'Średni', [R, Z]);
$dodaj('Kalendarz i treningi', 'Zagrania i znaki drużyny (zeszyt zawodnika)', 'trener@; zawodnik@.', ['Trener: zagranie z kilkoma klatkami, udostępnij grupie; zestaw znaków.', 'Zawodnik: zeszyt zagrań i quiz znaków na telefonie.'],
    'Zawodnik widzi tylko udostępnione; quiz działa na telefonie.', 'Niski', [R, Z, T]);
$dodaj('Kalendarz i treningi', 'Rozwój zawodnika: wysiłek, testy, karta', 'trener@; rodzic@.', ['Rodzic: „Jak było na treningu?” — wysiłek 1–10.', 'Trener: wynik testu, karta zawodnika do druku.'],
    'Obciążenie tygodnia dopiero po 3 tygodniach danych; porównanie z rówieśnikami tej samej płci.', 'Niski', [R, Z]);
$dodaj('Kalendarz i treningi', 'Ligi i mistrzostwa drużyny klubu', 'prezes@.', ['Rozgrywki → Ligi i mistrzostwa: dodaj ligę i mecz „do zajęć”.', 'Rodzic: „Mecze drużyny”.', 'Skład na mecz i publikacja.'],
    'Mecz w kalendarzu z „Będzie / Nie będzie”; rodzic widzi terminarz i wyniki.', 'Średni', [R]);
$dodaj('Kalendarz i treningi', 'Turniej organizowany przez klub', 'prezes@.', ['Turnieje → nowe zawody: drużyny, harmonogram (warianty), wyniki.', 'Otwórz publiczną stronę turnieju.'],
    'Harmonogram na boiska bez kolizji; strona publiczna bez danych osób, odświeża się.', 'Niski', [R]);
$dodaj('Kalendarz i treningi', 'Obozy i półkolonie: zapis i płatności', 'prezes@; rodzic@.', ['Obozy: nowy wyjazd z zaliczką.', 'Rodzic zapisuje dziecko; zarząd kwalifikuje.', 'Karta kwalifikacyjna do druku.'],
    'Należności zaliczki i dopłaty; karta bez danych o zdrowiu; lista rezerwowa przy limicie.', 'Średni', [R]);
$dodaj('Kalendarz i treningi', 'Nabór online', 'Bez logowania; sekretarz@.', ['Publiczny formularz naboru: zgłoś dziecko, potwierdź e-mailem.', 'Sekretarz: przyjmij zgłoszenie do grupy.'],
    'Po przyjęciu kartoteka dziecka, opiekun i zaproszenie rodzica; bez PESEL-u w formularzu.', 'Średni', [R]);
$dodaj('Kalendarz i treningi', 'Kontuzje i badania sportowe', 'trener@; rodzic@.', ['Rodzic zgłasza przerwę dziecka.', 'Trener: dopuszczenie po kontuzji; badania sportowe — data ważności.'],
    'Plakietka w kartotece i na liście; bez opisu urazu; przypomnienie przed końcem badań.', 'Średni', [R]);

/* ---------- Składki i wpłaty ---------- */
$dodaj('Składki i wpłaty', 'Naliczenie składek i zniżka rodzinna', 'skarbnik@.', ['Składki → naliczenie miesięczne dla sekcji (zniżka dla rodzeństwa).', 'Sprawdź podgląd i zapisz.'],
    'Raty dla członków; rodzeństwo ze zniżką; ostrzeżenie o osobach spoza ewidencji przy składce członkowskiej.', 'Krytyczny', [S, R]);
$dodaj('Składki i wpłaty', 'Rodzic: jeden przelew za wszystko', 'rodzic@ z dwiema zaległościami.', ['Opłaty → „Jeden przelew za wszystko” (kod QR).', 'Skarbnik wczytuje wyciąg z tytułem RODZ-.'],
    'Wpłata rozbita na należności od najstarszej; nadwyżka na ostatniej; wyciąg drugi raz bez dubli.', 'Wysoki', [R, T]);
$dodaj('Składki i wpłaty', 'Gotówka u trenera', 'trener@; skarbnik@.', ['Trener: Gotówka od rodziców → przyjmij wpłatę.', 'Skarbnik: odbierz gotówkę od trenera.'],
    'Rodzic widzi wpłatę od razu; skarbnik widzi „u trenera” do odbioru.', 'Średni', [R]);
$dodaj('Składki i wpłaty', 'Płatności online od rodziców (konfiguracja)', 'prezes@ (administrator).', ['Płatności od rodziców: wybierz operatora i wpisz dane testowe.', 'Rodzic: „Zapłać online”.'],
    'Przekierowanie do bramki w trybie testowym; po powrocie wpłata raz; klucz zapisany zaszyfrowany.', 'Średni', [R]);
$dodaj('Składki i wpłaty', 'Biuro prezesa: koszt z paragonem', 'trener@; prezes@; skarbnik@.', ['Trener: zgłoś koszt z zdjęciem paragonu (telefon).', 'Prezes zatwierdza; skarbnik wypłaca.'],
    'Paragon widzi zgłaszający, prezes i skarbnik; koszt w „Przychody i koszty”.', 'Średni', [R, T, Z]);
$dodaj('Składki i wpłaty', 'Sponsorzy: umowa, świadczenia, raport', 'prezes@.', ['Sponsorzy: firma, umowa z pakietu, świadczenia, wpływ.', 'Raport dla firmy i podziękowanie do druku.'],
    'Numer umowy SP/…; przypomnienie o końcu umowy; raport z okresu umowy.', 'Niski', [R, Z]);
$dodaj('Składki i wpłaty', 'Dotacje: projekt i sprawozdanie', 'prezes@.', ['Dotacje: projekt z budżetem, koszty, wskaźniki z obecności.', 'Sprawozdanie do druku.'],
    'Wskaźniki z danych systemu; ten sam koszt nie wchodzi dwa razy.', 'Niski', [R, Z]);

/* ---------- Komunikacja ---------- */
$dodaj('Komunikacja', 'Komunikator grupy i ogłoszenie z potwierdzeniem', 'trener@; rodzic@.', ['Trener: ogłoszenie z potwierdzeniem i terminem w rozmowie zespołu.', 'Rodzic: przeczytaj, potwierdź, odpowiedz (telefon).'],
    'Lista potwierdzeń u trenera; chmurka push; kontakty uczestników niewidoczne; niepełnoletni bez dostępu.', 'Krytyczny', [S, R, T]);
$dodaj('Komunikacja', 'Komunikat zarządu do rodziców', 'sekretarz@.', ['Komunikaty: grupa rodzice sekcji, potwierdzenie przeczytania, opublikuj.', 'Lista odbiorców i eksport.'],
    'Przypomnienia dla nieczytających; rodzic potwierdza raz.', 'Wysoki', [R]);
$dodaj('Komunikacja', 'Ankieta anonimowa', 'sekretarz@; rodzice.', ['Ankiety → z szablonu „Jak oceniasz zajęcia?” (anonimowa), otwórz.', 'Kilku rodziców odpowiada.'],
    'Wyniki dopiero od 5 odpowiedzi; brak informacji, kto co odpowiedział.', 'Niski', [R]);
$dodaj('Komunikacja', 'Wolontariat: dyżur i kod na miejscu', 'prezes@; wolontariusz z kontem.', ['Grafik dyżurów: dyżur z miejscami, kod na miejsce.', 'Wolontariusz zapisuje się i skanuje kod na początku i końcu.'],
    'Godziny dyżuru zapisane jako wykonane; zaświadczenie z godzinami.', 'Niski', [R, T]);

/* ---------- Dokumenty ---------- */
$dodaj('Dokumenty', 'Dokument do potwierdzenia kodem z e-maila', 'sekretarz@; rodzic@.', ['Dokumenty: regulamin dla grupy z potwierdzeniem.', 'Rodzic potwierdza za dziecko kodem.', 'Nowa wersja z ponowną akceptacją.'],
    'Potwierdzenie konkretnej wersji z datą; lista brakujących potwierdzeń; osoba bez konta — link.', 'Wysoki', [S, R]);
$dodaj('Dokumenty', 'Ochrona małoletnich: standardy i weryfikacje', 'prezes@.', ['Ochrona małoletnich: standardy klubu, weryfikacja osoby z kadry.', 'Ponowienie.'],
    'Lista osób z kadry ze stanem; dokument standardów do druku.', 'Średni', [R]);
$dodaj('Dokumenty', 'Kancelaria klubu: pismo przychodzące', 'sekretarz@ (moduł wyłączony na start — włącz w Ustawienia → Moduły).', ['Kancelaria: pismo z gminy ze skanem i terminem.', 'Odpowiedź.'],
    'Numer w rejestrze klubu; przypomnienie o terminie; skan tylko dla zarządu.', 'Niski', [R]);

/* ---------- Sprzęt i obiekty ---------- */
$dodaj('Sprzęt i obiekty', 'Sprzęt: wydanie i zwrot', 'prezes@; trener@.', ['Sprzęt: pozycja w ewidencji, wydaj zawodnikowi.', 'Zwrot.'],
    'Zakładka „Sprzęt” w kartotece zawodnika; nie wydasz więcej sztuk niż jest.', 'Niski', [R, Z]);
$dodaj('Sprzęt i obiekty', 'Obiekty klubu i dostępność', 'prezes@.', ['Obiekty: obiekt z boiskami, godziny, wyłączenie (wakacje).', 'Zajęcia w czasie wyłączenia.'],
    'Ostrzeżenie o niedostępności obiektu.', 'Niski', [R]);

/* ---------- Raporty ---------- */
$dodaj('Raporty', 'Kokpit zarządu i raport miesięczny', 'prezes@.', ['Kokpit: wskaźniki miesiąca z porównaniem.', 'Raport miesięczny do druku.'],
    'Zmiany do poprzedniego miesiąca i roku; „Czeka na decyzję”.', 'Średni', [R]);
$dodaj('Raporty', 'Lista obecności dla gminy i zaświadczenie', 'skarbnik@ albo trener@.', ['Obecność → raport dla gminy, lista do druku i Excel.', 'Zaświadczenie o uczestnictwie.'],
    'Mieszkańcy gminy z kartoteki, bez adresów; lista z podpisami.', 'Średni', [R]);
$dodaj('Raporty', 'Ewidencja godzin trenerów', 'trener@; prezes@.', ['Trener: godziny z zajęć w miesiącu, złóż.', 'Prezes: zatwierdź ze stawką albo zwróć.'],
    'Zatwierdzony miesiąc z kwotą w „Przychody i koszty”.', 'Niski', [R]);
$dodaj('Raporty', 'Komisja Rewizyjna: kontrola i zalecenie', 'kr@demo.fullbase.pl (włącz moduł w Ustawienia → Moduły, jeśli wyłączony).', ['Nowa kontrola, zalecenie z terminem.', 'Prezes odpowiada; komisja ocenia.'],
    'Wgląd komisji tylko do odczytu; zalecenie „czeka na ocenę komisji”.', 'Niski', [R]);
$dodaj('Raporty', 'Posiedzenie zarządu i walne zebranie', 'prezes@.', ['Posiedzenie: kreator, głosowanie na telefonach.', 'Walne: lista uprawnionych z ewidencji, rejestracja kodem, uchwała.'],
    'Kworum, uchwała z numerem; protokół do druku.', 'Niski', [R, T, Z]);

/* ---------- Strona klubu ---------- */
$dodaj('Strona klubu', 'Strona WWW klubu i widżet', 'prezes@ (pakiet Klub Pro).', ['Redakcja strony WWW: wpis, publikacja.', 'Otwórz stronę klubu i widżet terminarza.'],
    'Strona tylko po publikacji; widżet bez formularzy; pakiet niższy — strona niedostępna.', 'Niski', [R, Z]);
$dodaj('Strona klubu', 'Strefa kibica: wynik na żywo i typowanie', 'trener@; kibic bez konta w systemie.', ['Trener: wynik na żywo z telefonu.', 'Kibic: konto e-mailem, typ wyniku, głos na zawodnika meczu.'],
    'Wynik odświeża się u kibica; tylko profile za zgodą; grafika meczu do pobrania.', 'Niski', [R, Z, T]);
$dodaj('Strona klubu', 'Akademia: quiz od trenera', 'trener@; zawodnik@.', ['Trener przypisuje quiz grupie.', 'Zawodnik rozwiązuje na telefonie.'],
    'Wynik u trenera; zawodnik widzi swój postęp.', 'Niski', [R, Z, T]);

/* ---------- Ustawienia i usługa ---------- */
$dodaj('Ustawienia', 'Moduły: włączenie modułu wyłączonego na start', 'prezes@ (administrator) nowego klubu.', ['Ustawienia → Moduły: grupa „Do włączenia”.', 'Włącz Ankiety; wyłącz z powrotem.'],
    'Moduł pojawia się w menu po włączeniu; dane po wyłączeniu zostają; moduły z wyższego pakietu — „W wyższym pakiecie”.', 'Wysoki', [S, R]);
$dodaj('Ustawienia', 'Role i uprawnienia klubu', 'prezes@ (administrator).', ['Role i uprawnienia: nadaj skarbnikowi plany treningów.', 'Zaloguj się jako skarbnik@.'],
    'Skarbnik widzi Treningi dopiero po nadaniu; zmiana w rejestrze.', 'Średni', [R]);
$dodaj('Ustawienia', 'Zamówienie i kreator startu klubu', 'Bez logowania; potem operator@.', ['Zamów FULLBASE na stronie (kreator doboru pakietu).', 'Przejdź kreator startu: umowa powierzenia, dane, sekcje, „Co chcesz robić”.'],
    'Klient w okresie próbnym; do końca kreatora tylko jego kroki; moduły z niewybranych zadań wyłączone, moduły wyłączone na start — wyłączone.', 'Krytyczny', [S, R, O]);
$dodaj('Ustawienia', 'Zmiana pakietu i rezygnacja w panelu', 'prezes@ (administrator), klub po okresie próbnym.', ['Płatności za FULLBASE → Pakiet: wyższy (dopłata), potem niższy (od następnego okresu).', 'Zakończenie usługi: rezygnacja i cofnięcie.'],
    'Wyższy od razu z rachunkiem dopłaty; niższy zaplanowany; rezygnacja ze skutkiem po opłaconym okresie.', 'Wysoki', [R, O]);
$dodaj('Ustawienia', 'Eksport danych klubu', 'prezes@ (administrator).', ['Eksport danych klubu → pobierz.'],
    'Plik z danymi klubu, bez danych innych klubów; działa też w trybie tylko do odczytu.', 'Wysoki', [R]);
$dodaj('Ustawienia', 'Panel operatora: klient, faktura, wyciąg', 'operator@demo.fullbase.pl.', ['Klienci: karta klienta, wystaw fakturę.', 'Sprzedaż → Wyciąg z banku: zapłata z tytułu FB/…', 'Mój dzień: karta „Zadzwoń do klubu”.'],
    'Zapłata przedłuża okres usługi; „Mój dzień” pokazuje klub kończący próbę z telefonem; operator nie widzi danych osób klubu.', 'Średni', [R, O, T]);

/* ---------- Aplikacja mobilna ---------- */
$dodaj('Aplikacja mobilna', 'Instalacja na telefonie i powiadomienia push', 'rodzic@ na Androidzie i iPhonie.', ['Moje konto → Telefon → zainstaluj.', 'Włącz powiadomienia; trener wysyła ogłoszenie.'],
    'Ikona na ekranie; chmurka; dotknięcie otwiera sprawę.', 'Wysoki', [R, T]);
$dodaj('Aplikacja mobilna', 'Menu i tabele na małym ekranie', 'Telefon 360 px; prezes@, rodzic@.', ['Przejdź po pozycjach menu.', 'Otwórz listy osób, składek, zajęć.'],
    'Nic nie wystaje w bok; przyciski min. 44 px; tabele przewijają się w karcie.', 'Średni', [R, T]);
$dodaj('Aplikacja mobilna', '„Udostępnij do FULLBASE” (zdjęcie paragonu)', 'trener@ na Androidzie z zainstalowaną aplikacją.', ['W galerii: Udostępnij → FULLBASE.', 'Wybierz „Zgłoś koszt”.'],
    'Plik trafia do formularza kosztu.', 'Niski', [R, T]);

$plik = __DIR__ . '/../docs/scenariusze_KLB.csv';
$h = fopen($plik, 'w');
fwrite($h, "\xEF\xBB\xBF");
fputcsv($h, ['Projekt', 'Obszar', 'Tytuł', 'Warunki wstępne', 'Kroki', 'Oczekiwany wynik', 'Priorytet', 'Zestawy'], ';', '"', '');
foreach ($s as $r) fputcsv($h, ['KLB', ...$r], ';', '"', '');
fclose($h);
echo count($s) . " scenariuszy → $plik\n";
