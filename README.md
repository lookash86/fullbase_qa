# FULLBASE QA — rejestr błędów, braków i testów (wersja 2.1)

Aplikacja webowa dla testerów systemów FULLBASE (PZBall, Kluby i kolejne). PHP 8.1+ bez zewnętrznych bibliotek, baza **SQLite** (zero konfiguracji) albo **MySQL 8 / MariaDB**. Działa na komputerze i telefonie.

## Co potrafi

- **Logowanie i role:** Administrator / Tester / Obserwator. Konta zakłada administrator w panelu; nowe konto dostaje hasło tymczasowe, a użytkownik ustawia własne przy pierwszym logowaniu. Blokada po 8 nieudanych próbach (15 min), sesje z ochroną CSRF, hasła hashowane. **Reset hasła przez e-mail** („Nie pamiętasz hasła?”): link ważny 1 godzinę, działa jeden raz, odpowiedź nie zdradza, czy konto istnieje.
- **Zgłoszenia** w czterech rodzajach: błąd, brak funkcji, usprawnienie, pytanie. Pola: projekt, obszar, **waga** (blokuje pracę / psuje dane / utrudnia / kosmetyka — ustawia tester), **priorytet** (kiedy naprawiamy — ustawia administrator), **rola i konto w testowanym systemie** (np. „Zarząd klubu · klub@pzball.test”, bez hasła), faktyczny i oczekiwany wynik, kroki do odtworzenia problemu, środowisko, wersja, urządzenie, przeglądarka i adres strony (urządzenie i przeglądarka uzupełniają się same).
- **Załączniki:** zrzut ekranu można wkleić (Ctrl+V), przeciągnąć albo wybrać z dysku. Do jednego zgłoszenia trafia do 10 plików: obrazy, PDF, logi, CSV/JSON, nagrania MP4/WEBM albo ZIP. Duże zdjęcia z telefonu przeglądarka sama zmniejsza. Pliki leżą w `data/uploads` i są wydawane tylko zalogowanym.
- **Statusy i weryfikacja:** Nowe → Potwierdzone → W naprawie → Do weryfikacji. Przejście na „Do weryfikacji” wymaga numeru wersji z poprawką („naprawiono w wersji”) — ustawiamy je dopiero po wgraniu poprawki na demo; tester widzi „sprawdzaj na wersji ≥ X”. Tester potwierdza poprawkę („Działa — zamknij”) albo ją odrzuca („Nadal nie działa”, z komentarzem).
- **Odrzucenie z powodem:** duplikat (z numerem oryginału, link w obie strony), nie da się odtworzyć, to nie błąd, przeniesione do pomysłów.
- **„Kopiuj dla programisty”:** zgłoszenie w Markdownie, gotowe do wklejenia w Claude Code (z linkami do załączników i ostatnimi komentarzami).
- **Scenariusz regresji** z zamkniętego zgłoszenia jednym kliknięciem — te same kroki i oczekiwany wynik, żeby błąd nie wrócił.
- **Powiadomienia e-mail:** poprawka czeka na Twoją weryfikację; nowe zgłoszenie „blokuje pracę”/„psuje dane” i ponowne otwarcie (do administratorów); przypisano Ci zgłoszenie. Każdy może je wyłączyć w „Moim koncie”.
- **Komentarze i pełna historia zmian** (kto, co, kiedy).
- **Scenariusze testów** z warunkami wstępnymi, krokami i oczekiwanym wynikiem; **import z CSV** (administrator; najpierw podgląd, potem zapis).
- **Przebiegi testów:** sesja testowania na danym środowisku i wersji; wyniki zaliczony / niezaliczony / zablokowany / pominięty z notatką. Z niezaliczonego scenariusza jednym kliknięciem tworzy się zgłoszenie z wpisanymi już krokami.
- **Pulpit** („Krytyczne otwarte” według wagi), lista z filtrami (m.in. waga, rola), tablica (kanban), **eksport CSV** do Excela.
- **Projekty, obszary i role testowanego systemu** definiowane w panelu. Skrót projektu tworzy numer zgłoszenia, np. `PZB-0012`.
- **Aktualizacje bez ręcznego SQL:** po wgraniu nowych plików baza sama dochodzi do nowej wersji (migracje), dane zostają.

| Rola | Może |
|---|---|
| Administrator | wszystko: statusy, priorytety, przypisania, odrzucenia, rozwiązania, edycja i usuwanie, użytkownicy, projekty, import scenariuszy |
| Tester | zgłasza (z wagą), dodaje pliki i komentarze, edytuje własne nowe zgłoszenia, weryfikuje poprawki, pisze scenariusze (też regresji), prowadzi przebiegi |
| Obserwator | przegląda i komentuje (np. zarząd PZBall) |

## Struktura

```
public/      ← TYLKO ten katalog ma być katalogiem domeny (DocumentRoot)
app/         kod PHP (zablokowany dla WWW): bootstrap, schemat, migracje, poczta
data/        baza SQLite, załączniki, sesje (zablokowany dla WWW) — KOPIE ZAPASOWE
config.php   powstaje przy instalacji (dane bazy, nadawca e-maili) — nie publikować
docs/        PLAN.md (plan prac), zasady dla testera, scenariusze PZBall do importu
narzedzia/   uruchom-lokalnie.cmd (http://localhost:8091), test-e2e.php
```

Praca w VS Code z Claude Code: otwórz ten folder; instrukcje dla Claude są w `CLAUDE.md`, plan w `docs/PLAN.md`.

## Wdrożenie na home.pl (np. testy.fullbase.pl)

1. W panelu home.pl dodaj subdomenę (np. `testy.fullbase.pl`) i wskaż katalog `/testy.fullbase.pl/public` jako **„Podkatalog bez separacji serwisu”**. Przy włączonej separacji strona zwraca błąd 503, tak jak przy demo.
2. (Dla e-maili) w panelu poczty home.pl załóż skrzynkę nadawcy w tej samej domenie, np. `testy@fullbase.pl`.
3. Wgraj przez FTP **całą zawartość** paczki do `/testy.fullbase.pl/`, tak aby obok siebie leżały `app/`, `public/` (i `narzedzia/`). Katalog `data/` powstanie sam.
4. Włącz SSL dla subdomeny. Logowanie bez HTTPS przesyła hasła jawnie.
5. Otwórz `https://testy.fullbase.pl/install.php` **od razu po wgraniu plików**:
   - baza: **SQLite** jest najprostsza i wystarczy dla zespołu testowego. Przy MySQL załóż w panelu nową, osobną bazę i wpisz jej dane;
   - konto administratora (wpisz swój e-mail — przyda się do resetu hasła i powiadomień);
   - „Adres nadawcy” e-maili: skrzynka z punktu 2.
   Po instalacji instalator sam się blokuje.
6. Zaloguj się → **Użytkownicy** → „Dodaj użytkownika”, rola *Tester*, **z adresem e-mail**. Hasło tymczasowe przekaż innym kanałem niż login (np. SMS).
7. **Projekty i obszary:** sprawdź listy obszarów, środowisk i ról testowanego systemu (startowo: PZBall i FULLBASE Kluby).
8. **Scenariusze → Import z CSV:** wczytaj `docs/scenariusze_krytyczne_PZBall.csv` (plik jest w repozytorium, nie w paczce).
9. Po teście formularzy włącz WAF (zestawy standardowa, http, sql, php, bez wordpress) i sprawdź dodawanie zgłoszenia z załącznikiem.

Limit wielkości plików: `public/.user.ini` podnosi go do 12 MB (jeśli hosting na to pozwala). Aktualny limit aplikacja pokazuje przy polu załączników.

### Poczta (config.php)

Instalator zapisuje nadawcę, jeśli go podasz. Później można dopisać albo zmienić ręcznie w `config.php`:

```php
'mail' => ['from' => 'testy@fullbase.pl', 'from_name' => 'FULLBASE QA', 'transport' => 'mail'],
```

Bez sekcji `mail` wysyłka jest wyłączona (aplikacja działa, ale bez powiadomień i resetu hasła przez e-mail). Na VPS `mail()` działa przez msmtp.

## Aktualizacja do nowej wersji

1. Zrób kopię `data/` i `config.php` (przy MySQL także zrzut bazy).
2. Wgraj przez FTP nowe `app/`, `public/` i `narzedzia/`, nadpisując stare. **Nie ruszaj** `data/` ani `config.php`.
3. Otwórz stronę i zaloguj się. Baza zaktualizuje się sama przy pierwszym wejściu (migracje w `app/migrate.php`); dane zostają. Przeglądarki pobiorą nowy JS i CSS same (numer wersji w adresach plików).

## Wdrożenie na VPS (Caddy/Nginx + PHP-FPM)

Katalog strony → `public/`. Upewnij się, że użytkownik PHP może zapisywać do `data/` i (jednorazowo, na czas instalacji) do katalogu głównego aplikacji, gdzie powstaje `config.php`. Nginx nie czyta `.htaccess`, więc `app/`, `data/` i `config.php` muszą leżeć poza katalogiem strony. Przy tej strukturze to jest spełnione.

## Claude Code i rejestr (klucz API)

1. W rejestrze: **Użytkownicy → Klucze API → „Nowy klucz”**. Klucz pokazuje się raz.
2. W terminalu VS Code (PowerShell) ustaw zmienne i otwórz VS Code ponownie:
   ```
   setx FBQA_URL "https://testy.fullbase.pl/"
   setx FBQA_KEY "fbqa_…"
   ```
3. W Claude Code (w dowolnym repozytorium, np. `fullbase-silnik`) wystarczy napisać: „napraw PZB-0012, zgłoszenie pobierzesz przez `php ../fullbase-qa/narzedzia/qa.php pokaz PZB-0012`”. Polecenia: `lista`, `pokaz`, `komentarz`, `status`, `do-weryfikacji`, `gotowosc` (pomoc: `php narzedzia/qa.php pomoc`).

Klucz działa z uprawnieniami osoby, która go utworzyła, ale tylko do czytania zgłoszeń i plików, komentarzy i zmiany statusu. Gdy wycieknie: „Wycofaj”.

## Testy

```
php narzedzia/test-e2e.php            # czysta baza SQLite, cała ścieżka admin + tester + obserwator
php narzedzia/test-e2e.php --mysql    # to samo na MySQL (dane w zmiennych FBQA_MYSQL_HOST/PORT/USER/PASS)
```

Skrypt kopiuje aplikację do katalogu tymczasowego, instaluje ją i przechodzi przez API (uprawnienia, CSRF, statusy, pliki, e-maile do plików, reset hasła, import CSV, aktualizacja z wersji 2.0.1). Lokalnej bazy nie dotyka.

## Kopie zapasowe

- SQLite: kopiuj cały katalog `data/` (baza + załączniki) i `config.php`.
- MySQL: zrzut bazy (tabele `qa_*`) + katalog `data/uploads/` + `config.php`.

## Ponowna instalacja

Usuń `config.php`, a przy SQLite także `data/qa.sqlite*`. Instalator odmówi pracy, jeśli w bazie MySQL są już tabele `qa_*`.

## Czego jeszcze nie ma (kandydaci na kolejną wersję)

Zgłaszania prosto z systemu FULLBASE (przycisk „Zgłoś problem” w silniku — Etap 2 planu), bramki wydania i miar jakości na pulpicie (Etap 3) oraz importu starych zgłoszeń z wersji 1 (eksport JSON z przeglądarki).
