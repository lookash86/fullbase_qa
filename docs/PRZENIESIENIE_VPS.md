# FULLBASE QA — przeniesienie z home.pl na VPS OVH (punkt 3.3)

Opis do wykonania **razem z przenosinami silnika** (`fullbase-silnik/docs/WDROZENIE_VPS.md`). Ten sam serwer: Ubuntu 26.04, Apache 2.4 + PHP 8.5-FPM, MySQL 8.4, msmtp, certbot. Rejestr to osobna strona (`testy.fullbase.pl`) z własnym użytkownikiem systemu, żeby awaria jednego nie dotknęła drugiego. **Nic z tego nie jest jeszcze wykonane.**

## Decyzje (do potwierdzenia przez właściciela)

| Sprawa | Propozycja | Dlaczego |
|---|---|---|
| Baza | **zostaje SQLite** | jeden plik, kopia = kopiowanie katalogu, ruch rejestru jest mały; MySQL tylko gdyby testerów było kilkunastu naraz |
| Poczta | msmtp z serwera (już skonfigurowane dla silnika) | `mail()` w PHP wysyła przez msmtp — rejestr nie wymaga zmian |
| Adres | bez zmian: `testy.fullbase.pl` | zmienia się tylko wpis DNS — linki w e-mailach i klucze API działają dalej |
| Kopie | kopie dzienne rejestru (`data/kopie`) + cotygodniowe pobranie na komputer razem z kopiami silnika | jak w punkcie 9 instrukcji silnika |

## Kroki

**0. Przed przenosinami (home.pl)**
1. W rejestrze: **Kopie zapasowe → „Zrób kopię teraz”** i pobierz ją na komputer (zabezpieczenie).
2. Uprzedź testera: rejestr będzie niedostępny ok. 30 minut.

**1. Serwer (raz)** — użytkownik i katalogi, analogicznie do `instalacja.sh` silnika:
```
sudo adduser --system --group --home /srv/fullbase-qa fb-qa
sudo mkdir -p /srv/fullbase-qa/app && sudo chown -R fb-qa:fb-qa /srv/fullbase-qa
```
- pula PHP-FPM `fb-qa` (użytkownik `fb-qa`, `upload_max_filesize = 12M`, `post_max_size = 64M`);
- serwer wirtualny Apache: `DocumentRoot /srv/fullbase-qa/app/public`, `AllowOverride All` (plik `public/.htaccess`), przekazanie nagłówka `X-QA-Key` (domyślnie przechodzi);
- `certbot --apache -d testy.fullbase.pl` dopiero po zmianie DNS (krok 4).

**2. Przeniesienie plików**
1. Na home.pl przez FTP pobierz **cały katalog** `/testy.fullbase.pl/` (z `data/` i `config.php`).
2. Wgraj na VPS do `/srv/fullbase-qa/app/` (np. `scp -r`), potem `sudo chown -R fb-qa:fb-qa /srv/fullbase-qa/app`
   i `sudo chmod 640 /srv/fullbase-qa/app/config.php`.
3. `data/` musi być zapisywalny dla `fb-qa`; nie może być widoczny z WWW (leży poza `public/` — warunek spełniony).

**3. Konfiguracja** — w `config.php` zmienia się tylko sekcja `mail` (jeśli nadawca ma być inny), np.
`'mail' => ['from' => 'testy@fullbase.pl', 'from_name' => 'FULLBASE QA', 'transport' => 'mail', 'base_url' => 'https://testy.fullbase.pl/']`.
Baza SQLite przechodzi bez zmian (plik `data/qa.sqlite`).

**4. DNS i HTTPS** — w panelu domeny rekord A `testy` → IP VPS; po propagacji `certbot`. Na home.pl usuń subdomenę dopiero po sprawdzeniu (krok 5).

**5. Sprawdzenie**
1. Logowanie administratora i testera; lista zgłoszeń i otwarcie zgłoszenia z obrazem (pliki z `data/uploads`).
2. „Nie pamiętasz hasła?” na własnym koncie → e-mail dochodzi (msmtp).
3. Kopie zapasowe → „Zrób kopię teraz” → plik pojawia się na liście.
4. Na komputerze z repozytorium: `php narzedzia/qa.php lista` (ten sam `FBQA_URL` i klucz API).
5. Na serwerze (opcjonalnie, z kopii repozytorium): `php narzedzia/test-e2e.php` — instaluje się w katalogu tymczasowym, produkcji nie dotyka.

**6. Aktualizacje na VPS** — jak na home.pl: nowe `app/`, `public/`, `narzedzia/` w miejsce starych, `data/` i `config.php` zostają, migracje przy pierwszym wejściu. Można dopisać prosty `wgraj.sh` na wzór silnika (kopia katalogu przed podmianą, powrót = przywrócenie kopii).

**7. Kopie poza serwerem** — dopisać do cotygodniowego polecenia z punktu 9 instrukcji silnika:
`sudo cp -r /srv/fullbase-qa/app/data/kopie ~/kopie/qa` (baza) i raz w miesiącu `data/uploads` (załączniki).
Uwaga: kopie rejestru **nie są szyfrowane** (w przeciwieństwie do kopii silnika) — zawierają konta testerów i treść zgłoszeń, bez PESEL-i. Przechowuj je na zaszyfrowanym dysku.

## Ryzyka

- Tester ma w przeglądarce stare ciasteczko — po przeniesieniu zaloguje się ponownie (sesje są w `data/sessions`, przechodzą razem z katalogiem, ale zmiana serwera może je unieważnić).
- Jeśli kiedyś przejdziemy na MySQL: dziś nieprzetestowane lokalnie — najpierw `php narzedzia/test-e2e.php --mysql` na VPS (zmienne `FBQA_MYSQL_*`), potem nowa instalacja i przeniesienie danych skryptem (do napisania; kopia MySQL rejestru to JSON z `data/kopie`).
