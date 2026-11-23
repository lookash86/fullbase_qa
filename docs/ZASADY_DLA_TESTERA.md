# Zasady dla testera — FULLBASE QA

Rejestr: **https://testy.fullbase.pl** · Testujesz system na **demo** (nigdy na prawdziwych danych związku).

## 1. Jedno zgłoszenie = jeden problem
Dwa problemy na jednym ekranie to dwa zgłoszenia. Dzięki temu każdy można naprawić i sprawdzić osobno.
Zanim zgłosisz, zajrzyj na listę zgłoszeń: jeśli ktoś już to zgłosił, dopisz komentarz zamiast nowego zgłoszenia.

## 2. Jak dobrze zgłosić
- **Tytuł:** co i gdzie, np. „Wynik meczu znika po kliknięciu Zapisz”. Nie: „nie działa”.
- **Z jakiego konta:** wpisz konto i rolę, na której był problem (np. `klub@` — zarząd klubu). To bardzo ważne, bo każda rola widzi system inaczej.
- **Kroki do odtworzenia problemu:** ponumerowane, tak żeby ktoś inny zrobił dokładnie to samo.
  1. Zaloguj się jako klub@  2. Otwórz Mecze  3. Kliknij mecz z 5.11  4. Wpisz 7:5  5. Kliknij Zapisz
- **Co się stało** i **Oczekiwany wynik** (co powinno się stać).
- **Zawsze zrzut ekranu.** Naciśnij `Print Screen` (na telefonie: zrzut ekranu), potem w formularzu wklej `Ctrl+V` albo przeciągnij plik. Gdy problem to ruch (miganie, przewijanie) — krótkie nagranie.
- **Adres strony** z paska przeglądarki. Urządzenie i przeglądarka uzupełniają się same.

## 3. Rodzaj i waga
- **Rodzaj:** Błąd (coś działa źle) · Brak funkcji (czegoś nie ma) · Usprawnienie (działa, ale może lepiej) · Pytanie.
- **Waga — jak bardzo przeszkadza:**

| Waga | Kiedy | Przykład |
|---|---|---|
| **Blokuje pracę** | nie da się dokończyć ważnej czynności | nie można zapisać wyniku, zalogować się |
| **Psuje dane** | dane znikają, są złe albo widzi je ktoś niepowołany | klub widzi zawodników innego klubu |
| **Utrudnia** | da się, ale z obejściem lub kłopotem | filtr nie działa, trzeba szukać ręcznie |
| **Kosmetyka** | wygląd, literówka | przycisk wystaje poza ekran |

„Blokuje pracę” i „Psuje dane” to sprawy **krytyczne** — odpowiedź w ciągu 24 godzin. Jeśli widzisz cudze dane osobowe (PESEL, adres), zgłoś od razu i nie rób zrzutów z tymi danymi poza rejestrem.

## 4. Co dalej ze zgłoszeniem
Nowe → Potwierdzone → W naprawie → **Do weryfikacji** → Zamknięte. Administrator może też odrzucić zgłoszenie (z powodem, np. duplikat).

## 5. Jak sprawdzić poprawkę (weryfikacja)
1. Gdy zgłoszenie ma status **Do weryfikacji**, sprawdź, na jakiej wersji jest poprawka („sprawdzaj na wersji ≥ …”). Wersję widać w stopce demo.
2. Powtórz dokładnie swoje kroki, na tym samym koncie.
3. Działa → **„Działa — zamknij”**. Nie działa → **„Nadal nie działa”** i napisz, co dokładnie (najlepiej z nowym zrzutem).
4. Sprawdź też, czy obok nic się nie popsuło.

## 6. Przebieg testów
Przebieg to lista scenariuszy do przejścia na danej wersji. Przy każdym zaznacz: **Zaliczony**, **Niezaliczony**, **Zablokowany** (nie da się sprawdzić, bo coś wcześniej nie działa) albo **Pominięty**. Przy niezaliczonym kliknij „Utwórz zgłoszenie” — kroki wpiszą się same.

## 7. Czego nie robić
- Nie wpisuj **haseł** w zgłoszeniach ani komentarzach — tylko nazwę konta (np. `sedzia@`).
- Nie testuj na prawdziwych danych osobowych — używaj danych testowych z demo.
- Nie klikaj przycisków, które zmieniają całe demo, jeśli to nie jest celem testu („Wystaw wszystkie”, „Zamknij sezon”, „Ułóż od nowa”).
