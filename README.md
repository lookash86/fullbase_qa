# FULLBASE QA — rejestr błędów, braków i testów (wersja 2.0)

Aplikacja webowa dla testerów systemów FULLBASE (PZBall, Kluby i kolejne). PHP 8.1+ bez zewnętrznych bibliotek, baza **SQLite** (zero konfiguracji) albo **MySQL 8 / MariaDB**. Działa na komputerze i telefonie.

## Co potrafi

- **Logowanie i role:** Administrator / Tester / Obserwator. Konta zakłada administrator w panelu; nowe konto dostaje hasło tymczasowe, a użytkownik ustawia własne przy pierwszym logowaniu. Blokada po 8 nieudanych próbach (15 min), sesje z ochroną CSRF, hasła hashowane.
- **Zgłoszenia** w czterech rodzajach: błąd, brak funkcji, usprawnienie, pytanie. Pola: projekt, obszar, priorytet, faktyczny i oczekiwany wynik, kroki do odtworzenia problemu, środowisko, wersja, urządzenie, przeglądarka i adres strony (urządzenie i przeglądarka uzupełniają się same).
- **Załączniki:** zrzut ekranu można wkleić (Ctrl+V), przeciągnąć albo wybrać z dysku. Do jednego zgłoszenia trafia do 10 plików: obrazy, PDF, logi, CSV/JSON, nagrania MP4/WEBM albo ZIP. Duże zdjęcia z telefonu przeglądarka sama zmniejsza. Pliki leżą w `data/uploads` i są wydawane tylko zalogowanym.
- **Statusy i weryfikacja:** zgłoszenie przechodzi przez etapy Nowe → Potwierdzone → W naprawie → Do weryfikacji. Na etapie weryfikacji tester albo potwierdza poprawkę („Działa — zamknij”), albo ją odrzuca („Nadal nie działa”, z wymaganym komentarzem), co otwiera zgłoszenie ponownie.
- **Komentarze i pełna historia zmian** (kto, co, kiedy).
- **Scenariusze testów:** opisują warunki wstępne, kroki i oczekiwany wynik, z podziałem na obszary.
- **Przebiegi testów:** przebieg to sesja testowania na danym środowisku i wersji. Każdy scenariusz dostaje wynik: zaliczony, niezaliczony, zablokowany albo pominięty, do tego notatkę. Z niezaliczonego scenariusza jednym kliknięciem tworzy się zgłoszenie z wpisanymi już krokami do odtworzenia problemu.
- **Pulpit, lista z filtrami, tablica (kanban), eksport CSV** do Excela.
- **Projekty i obszary** definiowane w panelu. Skrót projektu tworzy numer zgłoszenia, np. `PZB-0012`.

| Rola | Może |
|---|---|
| Administrator | wszystko: statusy, przypisania, rozwiązania, edycja i usuwanie, użytkownicy, projekty |
| Tester | zgłasza, dodaje pliki i komentarze, edytuje własne nowe zgłoszenia, weryfikuje poprawki, pisze scenariusze, prowadzi przebiegi |
| Obserwator | przegląda i komentuje (np. zarząd PZBall) |

## Struktura

```
public/      ← TYLKO ten katalog ma być katalogiem domeny (DocumentRoot)
app/         kod PHP (zablokowany dla WWW)
data/        baza SQLite, załączniki, sesje (zablokowany dla WWW) — KOPIE ZAPASOWE
config.php   powstaje przy instalacji (dane bazy) — nie publikować
docs/        PLAN.md (plan prac), zasady dla testera
narzedzia/   uruchom-lokalnie.cmd (http://localhost:8091), testy
```

Praca w VS Code z Claude Code: otwórz ten folder; instrukcje dla Claude są w `CLAUDE.md`, plan w `docs/PLAN.md`.

## Wdrożenie na home.pl (np. testy.fullbase.pl)

1. W panelu home.pl dodaj subdomenę (np. `testy.fullbase.pl`) i wskaż katalog `/testy.fullbase.pl/public` jako **„Podkatalog bez separacji serwisu”**. Przy włączonej separacji strona zwraca błąd 503, tak jak przy demo.
2. Wgraj przez FTP **całą zawartość** paczki do `/testy.fullbase.pl/`, tak aby obok siebie leżały `app/`, `data/` i `public/`.
3. Włącz SSL dla subdomeny. Logowanie bez HTTPS przesyła hasła jawnie.
4. Otwórz `https://testy.fullbase.pl/install.php`:
   - baza: **SQLite** jest najprostsza i wystarczy dla zespołu testowego. Przy MySQL załóż w panelu nową, osobną bazę i wpisz jej dane;
   - załóż konto administratora.
   Po instalacji instalator sam się blokuje. Zrób ten krok od razu po wgraniu plików.
5. Zaloguj się → **Użytkownicy** → „Dodaj użytkownika”, rola *Tester*. Hasło tymczasowe przekaż innym kanałem niż login (np. SMS).
6. **Projekty i obszary:** sprawdź listy obszarów i środowisk (startowo: PZBall i FULLBASE Kluby).
7. Po teście formularzy włącz WAF (zestawy standardowa, http, sql, php, bez wordpress) i sprawdź dodawanie zgłoszenia z załącznikiem.

Limit wielkości plików: `public/.user.ini` podnosi go do 12 MB (jeśli hosting na to pozwala). Aktualny limit aplikacja pokazuje przy polu załączników.

## Wdrożenie na VPS (Caddy/Nginx + PHP-FPM)

Katalog strony → `public/`. Upewnij się, że użytkownik PHP może zapisywać do `data/` i (jednorazowo, na czas instalacji) do katalogu głównego aplikacji, gdzie powstaje `config.php`. Nginx nie czyta `.htaccess`, więc `app/`, `data/` i `config.php` muszą leżeć poza katalogiem strony. Przy tej strukturze to jest spełnione.

## Kopie zapasowe

- SQLite: kopiuj cały katalog `data/` (baza + załączniki) i `config.php`.
- MySQL: zrzut bazy (tabele `qa_*`) + katalog `data/uploads/` + `config.php`.

## Ponowna instalacja

Usuń `config.php`, a przy SQLite także `data/qa.sqlite*`. Instalator odmówi pracy, jeśli w bazie MySQL są już tabele `qa_*`.

## Czego jeszcze nie ma (kandydaci na kolejną wersję)

Powiadomień e-mail (np. „poprawka czeka na Twoją weryfikację”), resetu hasła przez e-mail (dziś hasło resetuje administrator) i importu starych zgłoszeń z wersji 1 (eksport JSON z przeglądarki).
