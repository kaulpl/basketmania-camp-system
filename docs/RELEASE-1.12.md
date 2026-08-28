# Basketmania Camp System 1.12

## Obieg dokumentów

1. Organizator zatwierdza formularz obozowy. Umowa robocza pozostaje wewnętrzna.
2. Organizator klika wysłanie umowy do podpisu. Dotychczasowy obieg SMS umowy pozostaje aktywny.
3. Pełna wpłata za turnus uruchamia przygotowanie osobnej karty kwalifikacyjnej i osobne zaproszenia rodziców.
4. Każdy rodzic otwiera swój link i podpisuje kodem wysłanym wyłącznie na swój numer.
5. Przy oświadczeniu o samodzielnej opiece wymagany jest jeden podpis rodzica; dokument zawiera treść oświadczenia.
6. Po wszystkich wymaganych podpisach rodziców organizator otwiera kartę w Dokumentach zgłoszenia i podpisuje ją SMS-em.
7. Podpisana karta PDF, z dowodem wszystkich podpisów, jest dostępna w Dokumentach. Podpisujący mogą pobrać ją również przez własne linki.

## Dane i bezpieczeństwo

- Drugi rodzic: oddzielne imię, nazwisko, e-mail i telefon. Bez oświadczenia o samodzielnej opiece wszystkie te dane są obowiązkowe.
- Adresy e-mail i znormalizowane numery rodziców muszą być różne.
- Nie przypisujemy automatycznie samodzielnej opieki starym zgłoszeniom. Brak danych blokuje wysyłkę; organizator może uzupełnić dane drugiego rodzica w sekcji karty.
- Treść, dane podpisujących, logo i stopka są zapisywane przed zaproszeniami. Ponowienie zaproszenia nie zmienia dokumentu ani zebranych podpisów.
- Każdy link jest ograniczony do jednej roli i karty, ważny 30 dni. Ponowienie zastępuje wcześniejszy link niezakończonego podpisu.
- Kody SMS są haszowane, ograniczone czasowo i limitem prób/wysyłek. Nie są umieszczane w logach ani PDF.
- Operacje na jednej karcie są serializowane blokadą MySQL. Powtórzony webhook płatności nie tworzy drugiej karty i nie wysyła ponownie skutecznie przekazanych zaproszeń.
- PDF jest generowany po sprawdzeniu uprawnień i nie trafia do publicznego katalogu plików.
- Wysłane i podpisane umowy historyczne nie są przepisywane. Oddzielenie karty odbywa się przy przygotowywaniu/publikacji nowej umowy.

## Wzór

Opracowano na podstawie przekazanego pliku D2021000154801.pdf. Zachowano części I-VI, usunięto oznaczenia publikacji Dziennika Ustaw i „WZÓR”. Zaznaczono obóz i pozytywną decyzję kwalifikacyjną. Części IV-VI nie poświadczają przyszłego pobytu: pozostają do późniejszego uzupełnienia przez kierownika/wychowawcę.

## Weryfikacja i wdrożenie

- GitHub Actions: składnia PHP/JS, dotychczasowy zestaw regresyjny, nowe testy walidacji rodziców, rozdzielenia dokumentów, autoryzacji linków, kodów SMS, przejść statusów i dowodów.
- Dompdf: wygenerowanie kart dla dwóch rodziców oraz samodzielnego opiekuna; wizualna kontrola PDF.
- Testy korzystają z fikcyjnych danych i atrap dostawców SMS/poczty. Nie wysyłano testowych wiadomości do rzeczywistych rodziców.
- Przed aktualizacją wykonaj kopię bazy i plików. Aktualizacja dodaje pola w zgłoszeniach i tabelę bcs_qualification_cards.
- Po instalacji sprawdź pełny scenariusz na kontrolowanym zgłoszeniu z własnymi adresami e-mail/numerami i aktualnym numerem organizatora. Dostarczanie zależy od konfiguracji poczty/SMS.
