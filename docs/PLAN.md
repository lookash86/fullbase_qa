# FULLBASE QA — plan prac (od 24.11.2026)

Cel: każdy błąd przechodzi tę samą drogę, od **zgłoszenia** przez **przegląd**, **naprawę**, **wdrożenie** i **weryfikację** aż po **test, który nie pozwoli mu wrócić**.
Stan wyjściowy: wersja 2.0.1. Działa logowanie z rolami, zgłoszenia z załącznikami, weryfikacja przez testera, scenariusze, przebiegi testów, pulpit, tablica i CSV.

Oznaczenia: **[Ł]** robi właściciel · **[C]** robi Claude w VS Code · **[Ł+C]** razem.
Odhaczaj `[x]` od razu po zakończeniu punktu.

---

## Etap 0 — przygotowanie, zanim tester zacznie

- [x] **0.1 [Ł+C] Repozytorium.** W VS Code: `git init` w `kod\fullbase-qa`, pierwszy commit „FULLBASE QA 2.0.1”, prywatne repo GitHub `lookash86/fullbase_qa` (z podkreślnikiem).
- [x] **0.2 [C] Uruchomienie lokalne.** `narzedzia\uruchom-lokalnie.cmd` → instalacja na SQLite → przeklikanie: zgłoszenie, zrzut ekranu wklejony Ctrl+V, zmiana statusu.
- [ ] **0.3 [Ł] Serwer.** Subdomena `testy.fullbase.pl` na home.pl → katalog `/testy.fullbase.pl/public` („bez separacji serwisu”) → SSL → wgranie plików → `install.php` od razu po wgraniu → konto administratora (szczegóły w README.md).
- [ ] **0.4 [Ł] Konto testera** w rejestrze (Użytkownicy → rola Tester). Hasło tymczasowe przekazujesz SMS-em.
- [ ] **0.5 [Ł+C] Konta testowe w demo PZBall** dla każdej roli: admin związku, wydział gier, klub, trener, sędzia (lokalnie istnieją już jako `*@pzball.test`). Hasła trzymamy w menedżerze haseł, **nie** w rejestrze.
- [x] **0.6 [C] 15–20 scenariuszy ścieżek krytycznych PZBall,** spisanych na podstawie `kod\fullbase-silnik` (moduły i role), jako plik do importu (zadanie 1.8). Minimum: logowanie każdej roli, dodanie osoby/zawodnika, licencja zawodnika, licencja klubowa, terminarz i zmiana terminu, wpisanie wyniku i zamknięcie meczu, przeliczenie tabeli, obsada sędziów, opłaty, uprawnienia (klub nie widzi danych innego klubu).
- [x] **0.7 [C] `docs/ZASADY_DLA_TESTERA.md`** — jedna strona: jak zgłaszać, co jest krytyczne, jedno zgłoszenie = jeden problem, zawsze zrzut ekranu, jak weryfikować poprawkę. Wersja do druku albo PDF dla testera.
- [ ] **0.8 [Ł] Rytm pracy.** Codziennie ok. 10 minut na przegląd nowych zgłoszeń: potwierdź, odrzuć albo ustaw priorytet. Czas reakcji: krytyczny w 24 h, wysoki do najbliższej wersji, średni i niski według planu.

**Gotowe, gdy:** tester ma działający login, konta w demo i listę scenariuszy do przejścia.

---

## Etap 1 — wersja 2.1 rejestru (Claude, 1–2 sesje)

Kolejność ma znaczenie: 1.0 jest warunkiem dla 1.1–1.3.

- [x] **1.0 Migracje schematu.** Tabela `qa_meta.schema` zawiera numer wersji schematu. `app/migrate.php` przy każdym żądaniu porównuje go z kodem i dopisuje brakujące kolumny lub tabele (SQLite + MySQL). Instalacja 2.0 aktualizuje się sama po wgraniu nowych plików.
  *Kryterium:* baza z 2.0 po podmianie plików działa bez ręcznego SQL, a dane zostają.
- [x] **1.1 Waga obok priorytetu.** Nowe pole `severity`: blokuje pracę / psuje dane / utrudnia / kosmetyka. Priorytet zostaje i oznacza, kiedy naprawiamy. Wagę ustawia tester, priorytet administrator. Pulpit pokazuje „Krytyczne” według wagi.
- [x] **1.1b Konto i rola w testowanym systemie** (prośba właściciela, 24.11). Przy zgłoszeniu pola: rola w testowanym systemie (lista z projektu, np. admin związku, wydział gier, klub, trener, sędzia) i konto testowe (np. `klub@pzball.test`, bez hasła). Widać je na liście, w szczegółach, w filtrach, w CSV i w „Kopiuj dla programisty”. Listę ról definiuje się w projekcie (Ustawienia → Projekty).
- [x] **1.2 Powody odrzucenia.** Przy statusie „Odrzucone” administrator wybiera powód: duplikat (wymaga numeru oryginału i tworzy link w obie strony) / nie da się odtworzyć / to nie błąd / przeniesione do pomysłów. Lista zgłoszeń pokazuje powód.
- [x] **1.3 „Naprawiono w wersji”.** Pole `fixed_in` jest wymagane przy przejściu na „Do weryfikacji”. Panel weryfikacji testera pokazuje: „sprawdzaj na wersji ≥ X”. Zasada: na „Do weryfikacji” przestawiamy dopiero po wgraniu poprawki na demo.
- [x] **1.4 Przycisk „Kopiuj dla programisty”.** Kopiuje do schowka zgłoszenie w Markdownie, gotowe dla Claude Code: numer, tytuł, rodzaj, waga, kroki do odtworzenia problemu, faktyczny i oczekiwany wynik, środowisko, wersja, adres, linki do załączników, ostatnie komentarze oraz na końcu „Po naprawie: commit `fix PZB-XXXX`, dodaj test”.
- [x] **1.5 „Utwórz scenariusz regresji”.** Na zamkniętym zgłoszeniu jeden przycisk tworzy scenariusz z tymi samymi krokami i oczekiwanym wynikiem, powiązany ze zgłoszeniem. Przy zamykaniu weryfikacji pojawia się propozycja tego kroku.
- [x] **1.6 Powiadomienia e-mail** (`mail()`; na home.pl działa, na VPS przez msmtp):
  - do zgłaszającego: poprawka czeka na weryfikację;
  - do administratora: nowe zgłoszenie o wadze „blokuje pracę” lub „psuje dane”, ponowne otwarcie;
  - do przypisanego: przypisano Ci zgłoszenie.
  Każdy użytkownik może wyłączyć powiadomienia w „Moim koncie”. Konfiguracja nadawcy w `config.php`.
- [x] **1.7 Reset hasła przez e-mail.** Link ważny 1 godzinę, jednorazowy, a komunikat nie zdradza, czy konto istnieje.
- [x] **1.8 Import scenariuszy z CSV** (administrator). Kolumny: projekt, obszar, tytuł, warunki wstępne, kroki, oczekiwany wynik, priorytet. Najpierw podgląd, potem zapis. Wczytujemy tu scenariusze z 0.6.
- [x] **1.9 Test automatyczny `narzedzia/test-e2e.php`.** Skrypt PHP CLI uruchamia serwer wbudowany na czystej bazie SQLite i przechodzi przez API całą ścieżkę: admin, tester, zgłoszenie, plik, statusy, uprawnienia (tester nie może zamknąć cudzego zgłoszenia z „Nowe”, bez logowania 401) i przebieg testów. Z parametrem `--mysql` powtarza to samo na bazie MySQL.
- [x] **1.10 Wydanie 2.1.** README, podbicie `QA_VERSION`, paczka ZIP w `Fullbase\wydania\`, wgranie na `testy.fullbase.pl`, notatka w projekcie Claude.

**Gotowe, gdy:** 1.9 przechodzi na SQLite i MySQL, a serwer zaktualizował się bez ręcznego SQL.

---

## Etap 2 — zgłaszanie prosto z systemu (po ok. 2 tygodniach testów)

> **ODŁOŻONY (decyzja właściciela 24.11.2026):** bez zmian w silniku. Tester zgłasza ręcznie w rejestrze (pola, zrzut Ctrl+V, konto i rola z 1.1b). Wracamy do tego tylko na wyraźne polecenie.

- [ ] **2.1 Klucz API rejestru dla instalacji.** Osobny klucz dla demo, pzball i kluby, z ograniczeniem do jednej akcji `report`.
- [ ] **2.2 Przycisk „Zgłoś problem” w silniku** (tylko środowisko „Próba”/demo, włączany w `config.php`). Okienko z polami „co się stało” i „czego oczekiwałeś”. Automatycznie dołącza adres strony, wersję silnika, rolę i ID użytkownika testowego, przeglądarkę, zrzut ekranu i ostatnie błędy JS. Wymaga zmian w `kod\fullbase-silnik` i ma przejść jego testy.
- [ ] **2.3 Powiązanie z dziennikiem błędów silnika.** Jeśli w ciągu ±2 minut od zgłoszenia silnik zapisał wyjątek, jego treść trafia do zgłoszenia jako komentarz systemowy, widoczny tylko dla administratora.

---

## Etap 3 — proces wydań i miary

- [ ] **3.1 Bramka wydania.** Przed każdą wersją PZBall robimy przebieg regresji (wszystkie scenariusze krytyczne). W rejestrze strona „Gotowość wydania”: otwarte zgłoszenia „blokuje pracę”/„psuje dane” = 0, a niezaliczone scenariusze krytyczne = 0.
- [ ] **3.2 Miary na pulpicie:** średni czas od zgłoszenia do zamknięcia, odsetek ponownych otwarć, liczba błędów według obszaru w ostatnich 30 dniach. Pokazują, który moduł silnika wymaga przebudowy, a nie łatania. Przydadzą się też we wnioskach dotacyjnych jako opis procesu jakości.
- [ ] **3.3 Przeniesienie na VPS OVH** razem z resztą instalacji (MySQL, kopie poza serwerem zgodnie z `Decyzje_technologiczne`).

---

## Dziennik
- 2026-11-23 — wersja 2.0 / 2.0.1 gotowa i przetestowana (SQLite + MariaDB); plan spisany.
- 2026-11-23 — 0.1 (część C): `git init` (gałąź `main`), commit „FULLBASE QA 2.0.1”. Brak `gh` na komputerze, więc repo GitHub `lookash86/fullbase-qa` zakłada właściciel (instrukcja w czacie); po `git push` odhaczyć 0.1. Następny punkt: 0.6.
- 2026-11-24 — 0.1 zakończone: repo prywatne `github.com/lookash86/fullbase_qa` (nazwa z podkreślnikiem, nie z myślnikiem), `main` wypchnięty. Następny punkt: 0.6.
- 2026-11-24 — Decyzja właściciela: najpierw cała wersja 2.1 (Etap 0 [C] + Etap 1), potem serwer home.pl (0.3) i konto testera (0.4). VPS niepotrzebny do 3.3, home.pl wystarcza.
- 2026-11-24 — 0.2 zrobione przez Claude ([Ł]→[C]). Decyzja: zalążek `narzedzia/test-e2e.php` już teraz (kopia app/ i public/ w katalogu tymczasowym, instalacja przez install.php, ścieżka admin + tester + obserwator przez API, hasła losowane przy uruchomieniu; lokalnej bazy nie rusza). Zastępuje ręczną ścieżkę; w 1.9 dojdzie `--mysql`. Wynik: 60/60 na SQLite. Lokalny PHP 8.3 nie ma pdo_mysql, więc MySQL nietestowany. Do sprawdzenia ręcznie przy okazji: wklejanie zrzutu Ctrl+V. Następny punkt: 0.6.
- 2026-11-24 — 0.6: `docs/scenariusze_krytyczne_PZBall.csv`, 18 scenariuszy (źródła: MACIERZ_UPRAWNIEN, DEMO_TESTY, DEMO_SCENARIUSZ w silniku; do silnika nic nie zapisano). Format: UTF-8 z BOM, separator `;`, kolumny jak w 1.8, projekt = skrót `PZB`, priorytet = polska nazwa. Obszary sprawdzone skryptem z listą PZB w schema.php (0 rozbieżności). Haseł kont demo nie przepisano. Test e2e: bez zmian w kodzie.
- 2026-11-24 — 0.7: `docs/ZASADY_DLA_TESTERA.md` (jedna strona: jedno zgłoszenie = jeden problem, konto i rola, kroki, zrzut Ctrl+V, waga z 1.1, weryfikacja, przebieg, czego nie robić). Do druku: otworzyć w VS Code (podgląd Markdown) albo na GitHubie i „Drukuj → PDF”. Test e2e: bez zmian w kodzie.
- 2026-11-24 — 1.0: `app/migrate.php` — lista kroków `qa_migrations()` (numer → funkcja), idempotentne pomocniki `qa_add_column`/`qa_add_index`/`qa_has_table` dla SQLite i MySQL; uruchamiane w `db()` przy każdym żądaniu (koszt: 1 SELECT z qa_meta), a w install.php po schemacie bazowym (schema.php = wersja 1, nie zmieniamy go). Błąd migracji → 503 z prostym komunikatem, szczegóły w error_log. `session` zwraca numer schematu zalogowanym. Test e2e: instaluje prawdziwą 2.0.1 z commita 10b7c55, dodaje zgłoszenie, podmienia pliki → dane są, schemat = 2. Wynik 67/67 na SQLite. **MySQL nietestowany** (brak pdo_mysql lokalnie). Decyzja: QA_VERSION podbijam raz, w 1.10 (nic pośredniego nie trafia na serwer).
- 2026-11-24 — 1.1: migracja 3 (`qa_issues.severity`, indeks; stare „Krytyczny” → waga „Blokuje pracę”). Wagi: blocker/data/annoying/cosmetic (domyślnie „Utrudnia”). Priorytet od testera serwer ignoruje (nowe = Średni, przy edycji zostaje stary) — pole priorytetu widzi tylko admin. Pulpit „Krytyczne otwarte” = waga blokuje/psuje dane; filtr wagi (z opcją „Krytyczne”), plakietka na liście, tablicy i w szczegółach, kolumna w CSV, wpis w historii przy zmianie wagi. Testy 79/79 (SQLite), `node --check` app.js OK.
- 2026-11-24 — 1.1b: migracja 4 (`qa_projects.tested_roles`, `qa_issues.sys_role`, `qa_issues.sys_account`; startowe role PZB z macierzy uprawnień silnika, KLB z demo-kluby). Formularz: lista ról z projektu + konto testowe (zapamiętywane w przeglądarce); serwer odrzuca pole konta zawierające „hasło/password” (422). Widać na liście (👤), w szczegółach, w filtrze „Każda rola”, w wyszukiwarce i w CSV; role edytuje admin w Projektach. **Naprawiony błąd z 2.0.1:** `lines()` dzieliło po `\R` bez flagi /u, więc „ą” (bajt 0x85) psuło JSON i całe `meta` — wystarczyło dodać obszar ze słowem „Zarząd”. Testy 87/87.
- 2026-11-24 — 1.2: migracja 5 (`reject_reason`, `duplicate_of` + indeks; bez klucza obcego, bo SQLite nie dodaje FK przez ALTER). Odrzucenie wymaga powodu (komentarz już opcjonalny); duplikat wymaga numeru oryginału (`PZB-0012`, `#12` albo `12`), nie może wskazywać siebie ani nieistniejącego. Oryginał dostaje wpis w historii i listę „Duplikaty tego zgłoszenia”. Ponowne otwarcie czyści powód (historia zostaje). Powód na liście, w szczegółach i w CSV. Testy 103/103.
- 2026-11-24 — 1.3: migracja 6 (`fixed_in`). Przejście na „Do weryfikacji” wymaga wersji (z formularza albo zapamiętanej wcześniej) → 422 bez niej. Okno admina przypomina: „dopiero po wgraniu poprawki na demo”. Panel weryfikacji testera: „Sprawdzaj na wersji ≥ X”; wersja w szczegółach, na liście (status Do weryfikacji), w historii i CSV. Testy 107/107.
- 2026-11-24 — 1.4: przycisk „⧉ Kopiuj dla programisty” w szczegółach (dla każdej roli, tylko odczyt). Markdown: numer, tytuł, rodzaj, waga, priorytet, status, projekt/obszar, rola i konto, środowisko, wersje, urządzenie, adres, link do rejestru, opis, kroki, oczekiwany wynik, linki do załączników (wymagają zalogowania), 5 ostatnich komentarzy, stopka „commit `fix PZB-XXXX`, dodaj test”. Schowek przez Clipboard API, a bez HTTPS — execCommand albo okno do ręcznego skopiowania. Bez zmian w API; wygenerowany Markdown sprawdzony w node. E2E 107/107.
- 2026-11-24 — 1.5: migracja 7 (`qa_cases.issue_id` + indeks). Nowa akcja `case_from_issue` (POST, CSRF, admin/tester; obserwator 403, bez tokenu 419, otwarte zgłoszenie 422): tytuł „Regresja PZB-XXXX: …”, kroki (albo opis, gdy brak kroków), oczekiwany wynik, warunki wstępne z kontem/rolą i wersją ≥ fixed_in, priorytet Wysoki dla wagi blokuje/psuje dane. Drugie kliknięcie zwraca istniejący scenariusz. Przycisk na zamkniętym zgłoszeniu + pytanie po „Działa — zamknij”. Testy 117/117.
- 2026-11-24 — 1.6: `app/mail.php` (mail() z nagłówkami UTF-8, ochrona przed wstrzyknięciem nagłówków, transport `file` do testów). Konfiguracja `mail` w config.php (`from`, `from_name`, `transport`, opcjonalnie `base_url`) — **bez niej wysyłka jest wyłączona** (decyzja: nic nie wychodzi przypadkiem; właściciel dopisuje 2 linijki przy wdrożeniu 1.10). Migracja 8 (`qa_users.notify`, domyślnie 1). Zdarzenia: nowe zgłoszenie z wagą blokuje/psuje dane → admini; „Do weryfikacji” → zgłaszający (z wersją); ponowne otwarcie → admini + przypisany; przypisanie → przypisany. Autor zmiany nie dostaje maila o własnej zmianie. Nowa akcja `account_save` (POST, CSRF, każdy zalogowany: 401 bez logowania, 419 bez tokenu) i przełącznik w „Moim koncie”. Błąd wysyłki nie przerywa żądania. Testy 134/134.
- 2026-11-24 — 1.7: migracja 9 (tabela `qa_password_resets`: tylko skrót SHA-256 tokenu, IP, ważność, użycie). `reset_request` i `reset_confirm` działają bez logowania, ale z tokenem CSRF sesji (419 bez niego). Login albo e-mail; ta sama odpowiedź dla istniejącego i nieistniejącego konta; limit 5 próśb na IP w 15 min (429), liczony w osobnej tabeli, żeby nie blokował logowania. Token 32 bajty, ważny 1 h, jednorazowy; nowa prośba unieważnia poprzednie linki; link w części `#/reset/…` (nie trafia do logów serwera). Po resecie znika blokada logowania. Ekrany „Nie pamiętasz hasła?” i „Ustaw nowe hasło”. Wymaga poczty z 1.6. Testy 148/148.
- 2026-11-24 — 1.8: akcja `cases_import` (POST, CSRF, tylko admin: tester 403). Przeglądarka czyta plik (UTF-8, a gdy się nie da — Windows-1250 z polskiego Excela), serwer parsuje fgetcsv (pola wieloliniowe w cudzysłowach), sam wykrywa separator `;`/`,`, rozpoznaje nagłówki bez polskich znaków i wielkości liter. Projekt po skrócie albo nazwie, priorytet po polskiej nazwie albo kluczu. Najpierw podgląd (`commit:false`: do zapisu / już jest / błąd z opisem), potem zapis w transakcji. Duplikaty (projekt + tytuł) pomijane, limit 1 MB / 500 wierszy. Plik z 0.6 importuje się w całości: 18/18, obszary zgodne. Testy 162/162.
- 2026-11-24 — 1.9: `narzedzia/test-e2e.php` kompletny: sprawdza składnię PHP i JS (`node --check`, gdy jest node), instaluje przez install.php, przechodzi admin + tester + obserwator, zgłoszenie z plikiem, statusy, weryfikację, uprawnienia (401 bez logowania, 403 dla każdej nowej akcji, 419 bez CSRF; tester nie zamyka cudzego zgłoszenia z „Nowe”), przebieg testów, punkty 1.1–1.8 i aktualizację z prawdziwej 2.0.1. `--mysql`: dane tylko ze zmiennych `FBQA_MYSQL_*`, tymczasowe bazy `fbqa_e2e_*` zakładane i usuwane przez skrypt, a bez pdo_mysql czytelny komunikat i kod 2. Wynik: **SQLite 172/172. MySQL NIETESTOWANY**: lokalnie brak serwera MySQL i pdo_mysql wyłączone w php.ini (plik DLL jest). Instrukcja dla właściciela w czacie. Kod MySQL pisany pod MySQL 8 (information_schema, bez `IF NOT EXISTS` przy indeksach).
- 2026-11-24 — 1.10: QA_VERSION 2.1.0, README (nowe funkcje, poczta, aktualizacja, testy), instalator z opcjonalnym polem „adres nadawcy e-maili” (zapisuje sekcję `mail` w config.php — nie trzeba edytować pliku ręcznie). Paczka `Fullbase\wydania\FULLBASE-QA-v2.1.zip` z `git archive` (bez config.php, data/, .git, docs/). Testy 173/173 (SQLite). Do zrobienia przez właściciela: wgranie na testy.fullbase.pl (0.3 jeszcze niezrobione, więc to świeża instalacja) i notatka w projekcie Claude.
- 2026-11-24 — **STAN NA KONIEC SESJI.** Zrobione: 0.1, 0.2, 0.6, 0.7, 1.0–1.10 (z 1.1b). Wersja 2.1.0, paczka w `Fullbase\wydania\FULLBASE-QA-v2.1.zip`, testy SQLite 173/173. Czeka na właściciela: (a) `git push` — od commita 37a9726 commity są tylko lokalnie, bo automatyczne wysyłanie zostało zablokowane; (b) 0.3 serwer + wgranie 2.1 (świeża instalacja, lista kroków w README i w czacie); (c) 0.4 konto testera z e-mailem; (d) 0.5 konta demo PZBall w menedżerze haseł; (e) 0.8 rytm przeglądu; (f) MySQL lokalnie do `test-e2e.php --mysql`; (g) notatka w projekcie Claude. **Następny krok: Etap 2 — NIE zaczynać bez zgody właściciela** (projekt zmian w silniku przedstawiony w czacie 24.11). Po zgodzie: 2.1 → 2.2 → 2.3, potem Etap 3 (3.3 tylko opis).
- 2026-11-24 — Decyzja właściciela: Etap 2 odłożony, w `fullbase-silnik` nic nie zmieniamy; zgłaszanie zostaje ręczne (rejestr + zrzut Ctrl+V). Uwaga: zapis błędów serwera „w pliku” (2.3) to też zmiana w silniku, więc odłożony razem z resztą. Następny punkt: Etap 3 (3.1, 3.2 w rejestrze; 3.3 tylko opis) — do potwierdzenia, czy przed wdrożeniem 2.1, czy po.
