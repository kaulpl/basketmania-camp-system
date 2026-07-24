## [0.25.4] - 2026-07-24

### Dodano
- kompletny edytowalny szablon umowy udziału w obozie Basketmania Camp przygotowany na podstawie przekazanego wzoru,
- kartę kwalifikacyjną uczestnika jako integralną część szablonu umowy,
- dedykowany plik HTML szablonu, który można dalej edytować w module Szablony.

### Zmieniono
- pola opiekuna, uczestnika, turnusu, organizatora, płatności, danych do faktury, zdrowia i szczepień są uzupełniane automatycznie z danych systemowych,
- domyślny generator umowy korzysta z rozbudowanego wzoru dla wersji 0.25.4.

## [0.25.3] - 2026-07-24

### Dodano
- komplet danych opiekuna i uczestnika wymaganych przez umowę oraz kartę kwalifikacyjną,
- drugi numer telefonu, imiona i nazwiska rodziców, adres uczestnika, wagę i specjalne potrzeby edukacyjne,
- informacje o szczepieniach ochronnych,
- kompletne dane nabywcy do faktury wraz z NIP i dodatkowymi informacjami.

### Zmieniono
- PDF Formularza Obozowego obejmuje wszystkie nowe dane,
- generator umowy udostępnia nowe placeholdery,
- faktura korzysta z dedykowanych danych nabywcy, a przy ich braku z danych opiekuna.

## [0.25.2] - 2026-07-24

### Zmieniono
- anulowane zgłoszenia na liście nie wyświetlają żadnych szybkich akcji,
- cały wiersz anulowanego zgłoszenia ma delikatne czerwone tło i czerwony pasek po lewej stronie,
- czerwone wyróżnienie anulowania ma pierwszeństwo przed zielonym wyróżnieniem rozliczonego zgłoszenia.

# Changelog

Wszystkie istotne zmiany w Basketmania Camp System są dokumentowane w tym pliku.

## [0.25.1] - 2026-07-24

- Wydanie techniczne aktualizujące numer wersji wtyczki.

## [0.25] - 2026-07-24

- Zmieniono oznaczenie „Dane potwierdzone przez organizatora” na mały zielony badge.
- Dodano zielony ptaszek w okrągłym polu oraz zieloną kolorystykę napisu i obramowania.

## [0.20.24] - 2026-07-24

- Dodano modalny wybór daty ręcznej wpłaty na liście zgłoszeń i Karcie Zgłoszenia.
- Domyślna data to dzień bieżący; daty przyszłe są blokowane w interfejsie i na serwerze.
- Wybrana data jest zapisywana w płatności, logowana przy zgłoszeniu i prezentowana na fakturze.
- Usunięto zdublowaną sekcję „Dane rejestracyjne”.
- Tekstowy przycisk „Otwórz kartę CRM” zastąpiono pomarańczowym przyciskiem z białą ikoną.

## [0.20.23] - 2026-07-24

- Usunięto kolumnę „Kontakt” z listy zgłoszeń.
- Ustalono stałą szerokość kolumny „Postęp”, mieszczącą wszystkie wskaźniki.
- Przycisk podglądu prezentuje kompletny Formularz Obozowy w sekcjach.
- Usunięto ręczne składanie danych przez atrybuty HTML, które powodowało błędne podziały wierszy.

## [0.20.22] - 2026-07-24

### Zmieniono
- zmniejszono kółka postępu na liście zgłoszeń do rozmiaru standardowej ikony emoji,
- zachowano kolory etapów, stany oczekujące oraz podpowiedzi po najechaniu.

## [0.20.21] - 2026-07-24

### Naprawiono
- wysyłanie umowy z Karty Zgłoszenia korzysta z dedykowanego endpointu AJAX zamiast żądania z przekierowaniem,
- anulowanie zgłoszenia jawnie przekazuje klikniętą akcję do serwera,
- obie czynności weryfikują właściwy nonce, zwracają czytelny wynik JSON i odświeżają Kartę Zgłoszenia bez przeładowania całej strony.

## [0.20.20] - 2026-07-23

### Dodano
- osobne logi sukcesu, błędu i uzasadnionego pominięcia dla przypomnienia o umowie, przypomnienia o płatności oraz informacji przed obozem,
- przypisanie każdego zdarzenia automatyzacji do konkretnego zgłoszenia i istniejącej umowy,
- czytelne polskie etykiety błędów i pominięć w Historii klienta.

### Naprawiono
- nieudana wysyłka i wyłączony kanał nie kończą już automatyzacji bez dedykowanego śladu audytowego,
- identyczne pominięcia są deduplikowane, aby kolejne uruchomienia WP-Cronu nie powielały wpisów.

## [0.20.19] - 2026-07-23

### Zmieniono
- przypomnienie o podpisaniu umowy jest liczone od faktycznego wysłania bieżącej umowy i korzysta z własnego szablonu,
- przypomnienie o płatności jest liczone od terminu płatności, a nie od utworzenia zgłoszenia,
- informacje przed obozem są wysyłane wszystkim aktywnym uczestnikom, niezależnie od statusu płatności,
- każda automatyzacja respektuje osobny kanał wybrany w ustawieniach powiadomień.
