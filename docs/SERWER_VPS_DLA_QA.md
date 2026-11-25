# Serwer VPS — informacje dla czatu, który wdraża FULLBASE QA (25.11.2026)

Ten plik jest dla osobnej rozmowy z Claude, która ma postawić rejestr QA na serwerze. Rejestr **nigdy nie był na home.pl** — punkt 0.3 planu nie został wykonany,
więc to **nowa instalacja**, nie przenosiny. Kroki od 1 do 5 w `PRZENIESIENIE_VPS.md` stosuj z tą różnicą: zamiast pobierania plików z home.pl — świeże pliki z repozytorium,
pusta baza SQLite, `install.php` i konto administratora.

## Serwer

| Co | Wartość |
|---|---|
| Dostawca | OVH, VPS-3 w Polsce |
| Dane dostępu | Dane dostępu: Bitwarden, wpis Serwer OVH |
| System | Ubuntu 26.04 — PHP 8.5 (FPM), Apache 2.4, MySQL 8.4, certbot, msmtp, fail2ban, zapora (22/80/443) |
| Logowanie | Dane dostępu: Bitwarden, wpis Serwer OVH. Po zalogowaniu `sudo` (bez hasła). **root@ nie działa**, hasło przez SSH nie działa — tylko klucz |
| Uwaga | 3 nieudane logowania SSH = blokada adresu (fail2ban). Odblokowanie: z innego adresu albo konsola KVM w panelu OVH, `sudo fail2ban-client unban --all` |
| Ubuntu 26.04 | `tail` wymaga `-n 5` (nie `-5`) |

## Co już działa na serwerze — NIE ruszać

- `/srv/fullbase/pzball` → **test-pzball.fullbase.pl** (silnik, instalacja PZBall, pokazowa).
- `/srv/fullbase/klub` → **test-klub.fullbase.pl** (silnik, wspólna instalacja klubów, pokazowa).
- Skrypty silnika w `/root/vps/` (`wgraj.sh`, `ustawienia.sh`, `instalacja.sh`, `poczta.sh`, `wroc.sh`). Ich nie uruchamiaj — rejestr QA ma własnego użytkownika i własny serwer wirtualny.
- Każda instalacja ma własnego użytkownika systemu i własną pulę PHP-FPM. Rejestr QA: użytkownik `fb-qa`, katalog `/srv/fullbase-qa`, pula `fb-qa` (jak w `PRZENIESIENIE_VPS.md`, krok 1).

## Poczta

- msmtp jest skonfigurowany (`/etc/msmtprc`): SMTP home.pl `serwer2697660.hosting-home.pl:587`, nadawca `noreply@fullbase.pl`, odpowiedzi na `kontakt@fullbase.pl`.
  `mail()` w PHP wysyła przez msmtp — rejestr nie potrzebuje zmian. Nadawca rejestru: `'from' => 'noreply@fullbase.pl', 'from_name' => 'FULLBASE QA'`.
- Właściciel planuje przenieść pocztę z home.pl (skończyły się darmowe adresy). Po przenosinach zmieni się tylko `/etc/msmtprc` — dla rejestru nic.

## DNS i HTTPS

- Domena `fullbase.pl` i jej **strefa DNS są w home.pl** (serwer jest w OVH, ale rekordy domeny ustawia się w panelu home.pl).
- Właściciel dodaje w home.pl rekord **A `testy` → adres serwera (Dane dostępu: Bitwarden, wpis Serwer OVH)**. Po propagacji (sprawdź `nslookup testy.fullbase.pl`): `sudo certbot --apache -d testy.fullbase.pl --redirect`.

## Kopie

- Kopie dzienne rejestru: `data/kopie` (funkcja rejestru, 14 ostatnich). Kopie silnika: `/srv/fullbase/<nazwa>/kopie/zapasowe/` (zaszyfrowane).
- Właściciel stawia automatyczną kopię poza serwerem: NAS w domu i Hetzner Storage Box (1 TB). Dopisz katalog `/srv/fullbase-qa/app/data/kopie` do tego samego mechanizmu, gdy powstanie.
  Kopie rejestru nie są szyfrowane — zawierają konta testerów i treść zgłoszeń (bez PESEL-i).

## Po instalacji (checklista)

1. Konto administratora (właściciel) i **konto testera Jarka** (rola Tester) — adres e-mail poda właściciel; hasło tymczasowe do wymiany przy pierwszym logowaniu.
2. Projekt **PZB** (PZBall) z rolami testowanego systemu: admin, wydział gier, Wydział ds. Sędziów (kolegium), Wydział Dyscypliny (wd), klub, trener, sędzia, rodzic, redaktor.
3. Import scenariuszy: najpierw `docs/scenariusze_pilotaz_PZBall.csv` (24 scenariusze, zestawy „Dzień meczowy na telefonie” i „Pilotaż”, aktualne konta: klub@ = BORSUKI, klub2@ = KORMORANY).
   Starsze pliki (`krytyczne`, `priorytetowe`, `rozszerzone`) mówią o klubach DĘBY i STAL i o licencji klubowej (zamrożona) — importować dopiero po poprawce nazw.
4. Testy demo, które opisują scenariusze: **test-pzball.fullbase.pl**, konta `*@pzball.test` (hasła w `kod/fullbase-silnik/wydania/vps-2026-11-20/fullbase-v0.8-test-pzball-konta.txt` na komputerze właściciela — nie wpisuj ich do rejestru ani do repozytorium).
5. Sprawdzenie z `PRZENIESIENIE_VPS.md`, krok 5 (logowanie, reset hasła e-mailem, kopia, `narzedzia/qa.php lista`).
6. Wgranie tylko na polecenie właściciela.
