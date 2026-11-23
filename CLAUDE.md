# FULLBASE QA — instrukcja dla Claude Code

Rejestr błędów, braków i testów dla systemów FULLBASE (PZBall, Kluby). Narzędzie wewnętrzne firmy, z którego korzysta tester. Plan prac jest w `docs/PLAN.md`. Przed rozpoczęciem pracy sprawdź, który punkt jest następny, a po skończeniu go odhacz.

## Stos i zasady (zgodne z `kod/fullbase-silnik`)
- PHP 8.1+ (produkcja: 8.5 na home.pl, później VPS OVH), **bez zewnętrznych bibliotek** i bez frameworka. Frontend to czysty JS (`public/assets/app.js`) bez bundlera.
- Baza: **SQLite i MySQL 8 jednocześnie**. Każda zmiana schematu musi działać na obu. Daty trzymamy jako tekst `RRRR-MM-DD GG:MM:SS`, ustawiany w PHP.
- Zmiany schematu robimy wyłącznie jako migracje (patrz zadanie 1.0 w planie). Instalacja na serwerze ma przejść bez ręcznego SQL.
- Interfejs i komunikaty po polsku, prostym językiem. Tester nie jest programistą.
- Bezpieczeństwo: każda akcja POST z tokenem CSRF (`X-CSRF-Token`), uprawnienia sprawdzane w `api.php` (nie tylko w UI), zapytania wyłącznie z parametrami (PDO prepare), pliki tylko przez `api.php?action=file` po zalogowaniu.
- `public/` to jedyny katalog widoczny z WWW. `app/`, `data/` i `config.php` są poza nim.
- Sesje zapisujemy w `data/sessions`, bo na home.pl domyślne sesje nie działają.

## Struktura
```
app/bootstrap.php   konfiguracja, połączenie z bazą, sesja, słowniki (role, statusy, rodzaje…)
app/schema.php      schemat bazy + startowe projekty
public/api.php      całe API (?action=…)
public/install.php  instalator w przeglądarce
public/assets/      app.js (SPA, routing po #/…), app.css
narzedzia/          uruchamianie lokalne, testy
docs/               plan, zasady dla testera
```

## Uruchomienie i testy
- Lokalnie: `narzedzia\uruchom-lokalnie.cmd` → http://localhost:8091 (przy pierwszym wejściu instalator; wybierz SQLite).
- Reset lokalny: usuń `config.php` i `data/qa.sqlite*`.
- Przed oddaniem zmiany przejdź ścieżkę „admin + tester” na obu bazach: zgłoszenie z załącznikiem → naprawa → weryfikacja → przebieg testów. Docelowo robi to skrypt `narzedzia/test-e2e` (zadanie 1.9).
- Po każdej zmianie front-endu podbij `QA_VERSION` w `app/bootstrap.php`, żeby przeglądarki nie trzymały starego JS i CSS.

## Słownictwo w interfejsie
Zgłoszenie (nie „ticket”), Kroki do odtworzenia problemu, Oczekiwany wynik, Do weryfikacji, Przebieg testów, Scenariusz. Numery zgłoszeń: `<SKRÓT>-0001` (np. PZB-0012). Numery scenariuszy: `TC-001`.

## Commity
Opis po polsku, krótko. Jeśli zmiana naprawia zgłoszenie z rejestru, podaj jego numer, np. `fix PZB-0012: …`.
