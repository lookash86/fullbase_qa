# Wdrożenie rejestru FULLBASE QA na VPS — 26.11.2026

Nowa instalacja (na home.pl rejestru nie było) według `ZADANIE_rejestr_QA_testy_VPS_2026-11-26.md`, `docs/SERWER_VPS_DLA_QA.md` i `docs/PRZENIESIENIE_VPS.md`.
Dane dostępu do serwera: Bitwarden, wpis „Serwer OVH”.

| Co | Stan |
|---|---|
| Adres | https://testy.fullbase.pl (certyfikat Let's Encrypt, http → https) |
| Kod | commit `b2dd6e2` (stan z komputera; na GitHub wysyła właściciel) |
| Katalog | `/srv/fullbase-qa/app` (właściciel `fb-qa`), `data/` i `config.php` poza `public/` — z WWW 404 |
| Baza | SQLite `data/qa.sqlite` |
| PHP | pula `fb-qa` (`/etc/php/8.5/fpm/pool.d/fb-qa.conf`), upload 12M, post 64M, log `/srv/fullbase-qa/logi/php.log` |
| Apache | `/etc/apache2/sites-available/fb-qa.conf` + `fb-qa-le-ssl.conf` (certbot) |
| Konta | administrator `lukasz`, tester `jarek` — hasła tylko z linku e-mailem |
| Projekt PZB | role testowanego systemu (9), środowiska test-pzball, test-klub, testy, Lokalnie; 24 scenariusze pilotażu |

Zmiany we wspólnej konfiguracji serwera (bez edycji wspólnych plików):
- `www-data` dopisany do grupy `fb-qa` (tak jak do `fb-pzball` i `fb-klub`) — odczyt `public/`;
- `fb-qa` dopisany do grupy `fbpoczta` (tak jak `fb-pzball` i `fb-klub`) — odczyt `/etc/msmtprc` przez `mail()`; bez tego msmtp kończył się kodem 78.

Uwaga: dziennik `/var/log/msmtp/msmtp.log` należy do `fb-pzball`, więc wysyłki z `fb-qa` (i prawdopodobnie `fb-klub`) nie trafiają do niego. Poczta wychodzi (e-mail do właściciela doszedł 26.11 o 01:10).
Do decyzji: wspólny plik dziennika z grupą `fbpoczta` i prawem zapisu.

Kopie: `data/kopie` (rejestr, raz na dobę, 14 ostatnich). Dopisać do kopii poza serwerem (NAS / Hetzner), gdy ten mechanizm powstanie.

Testy: `php narzedzia/test-e2e.php` — na serwerze 247/248 (jeden test wymaga historii git, której kopia bez historii nie ma), na komputerze 258/258.
