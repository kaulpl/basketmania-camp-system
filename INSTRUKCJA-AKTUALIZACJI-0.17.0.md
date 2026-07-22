# Basketmania Camp System 0.17.0

## Nowy moduł Feedback

Wersja dodaje wewnętrzny system zgłaszania błędów, poprawek i nowych funkcjonalności przez administratorów.

- Przycisk „Zgłoś uwagę” jest dostępny u góry każdego modułu Basketmania Camp.
- Zgłoszenie zawiera rodzaj i krótki opis.
- System automatycznie zapisuje administratora, moduł, adres strony oraz datę.
- Nowa pozycja „Feedback” w menu zawiera listę zgłoszeń.
- Dostępne statusy: Nowe, W trakcie, Rozwiązano, Anulowano.
- Liczba nowych zgłoszeń jest widoczna w menu.

Po aktualizacji wystarczy wejść do panelu WordPress. Migracja bazy uruchomi się automatycznie i utworzy tabelę `wp_bcs_feedback` (z właściwym prefiksem instalacji).
