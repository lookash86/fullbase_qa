Pracujesz w repozytorium FULLBASE QA (`Pulpit\Fullbase\kod\fullbase-qa`). To rejestr błędów i testów dla systemów FULLBASE. Jestem właścicielem firmy, a Ty prowadzisz prace techniczne.

Najpierw przeczytaj `CLAUDE.md`, `README.md` i `docs/PLAN.md`. Plan jest jedynym źródłem zadań, a `CLAUDE.md` mówi, jak pisać kod.

Realizuj plan punkt po punkcie, w kolejności z pliku. Zasady:

1. **Punkty [C]** robisz sam, w całości. **Punkty [Ł+C]** przygotowujesz w całości, a mnie zostawiasz tylko to, czego nie zrobisz z komputera (np. kliknięcie w panelu GitHub albo home.pl). Wtedy dajesz mi instrukcję krok po kroku. **Punkty [Ł]** pomijasz: wypisujesz mi, co mam zrobić, i idziesz dalej, jeśli kolejne punkty od nich nie zależą.
2. **Po każdym punkcie:** uruchom testy (od punktu 1.9 `php narzedzia/test-e2e.php`, wcześniej ręczna ścieżka admin + tester opisana w CLAUDE.md), odhacz punkt `[x]` w `docs/PLAN.md`, dopisz linijkę w sekcji „Dziennik” i zrób osobny commit po polsku. Nie łącz kilku punktów w jeden commit.
3. **Etap 0:**
   - 0.1 — `git init`, pierwszy commit. Do założenia prywatnego repo `lookash86/fullbase-qa` użyj `gh`, jeśli jest zalogowany; jeśli nie, daj mi instrukcję.
   - 0.6 — przeczytaj `..\fullbase-silnik` (moduły, role, uprawnienia, `docs\`) i napisz 15–20 scenariuszy ścieżek krytycznych PZBall jako `docs/scenariusze_krytyczne_PZBall.csv` w formacie importu z punktu 1.8 (projekt, obszar, tytuł, warunki wstępne, kroki, oczekiwany wynik, priorytet). Obszary muszą zgadzać się z listą obszarów projektu PZB w `app/schema.php`. Do repozytorium silnika niczego nie zapisuj.
   - 0.7 — `docs/ZASADY_DLA_TESTERA.md`: jedna strona, prosty język, dla osoby, która nie jest programistą.
4. **Etap 1 (wersja 2.1):** zacznij od 1.0 (migracje). Bez tego nie zmieniaj schematu. Każda zmiana bazy musi działać na SQLite **i** MySQL. Jeśli lokalnie nie ma MySQL, powiedz mi, jak go uruchomić, a do tego czasu testuj na SQLite i zaznacz to w Dzienniku. Na koniec 1.10: podbij `QA_VERSION` do 2.1.0, uzupełnij README, zbuduj paczkę ZIP do `..\..\wydania\FULLBASE-QA-v2.1.zip` (bez `config.php`, `data/`, `.git`, `docs/`) i daj mi listę kroków do wgrania na `testy.fullbase.pl` (sama podmiana plików, migracje wykonają się automatycznie).
5. **Etap 2** zmienia kod silnika (`..\fullbase-silnik`). **Zatrzymaj się przed nim:** podsumuj, co zrobiłeś w Etapie 0 i 1, pokaż mi projekt zmian w silniku (pliki, punkty styku, wpływ na testy silnika) i czekaj na moją zgodę. Etap 3 robisz po Etapie 2. Punkt 3.3 (VPS) tylko opisujesz, nie wykonujesz.
6. **Nie pytaj o drobiazgi.** Decyzje techniczne podejmuj sam zgodnie z `CLAUDE.md` i zapisuj je w Dzienniku. Pytaj tylko wtedy, gdy decyzja jest nieodwracalna, dotyczy pieniędzy albo kont, albo plan jest sprzeczny z kodem.
7. **Bezpieczeństwo:** żadnych haseł, kluczy ani danych osobowych w repozytorium i w czacie. Każda nowa akcja API ma sprawdzenie roli i token CSRF, a do tego test w `test-e2e.php`, że osoba bez uprawnień dostaje 403.
8. **Na koniec każdej sesji** (albo gdy skończy Ci się kontekst): zaktualizuj Dziennik w `docs/PLAN.md` tak, żeby następna sesja mogła zacząć od komendy „kontynuuj plan”. Napisz mi krótko, co zostało zrobione, co czeka na mnie i jaki jest następny punkt.

Zacznij od punktu 0.1.
