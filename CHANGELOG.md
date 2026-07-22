# Changelog

Wszystkie istotne zmiany w Basketmania Camp System są dokumentowane w tym pliku.

## [0.18.7] - 2026-07-22

### Dodano
- rozwijane sekcje na ekranie Ustawienia dla ustawień wtyczki, powiadomień oraz dokumentów i automatyzacji,
- zapamiętywanie stanu rozwinięcia sekcji ustawień w przeglądarce,
- dwukolorowy pasek zapełnienia turnusu: pomarańczowy dla zgłoszeń z potwierdzoną pełną wpłatą i szary dla pozostałych rozpoczętych, nieanulowanych zgłoszeń,
- czytelną legendę liczbową przy paskach zapełnienia turnusów.

### Zmieniono
- limit miejsc turnusu ma charakter informacyjny i nie blokuje formularza zapisów,
- otwarte turnusy pozostają dostępne w formularzu także po przekroczeniu ustawionego limitu,
- wszystkie nowe zgłoszenia rodziców są przyjmowane niezależnie od liczby dotychczasowych uczestników,
- ustawienia wtyczki są domyślnie zwinięte po pierwszym wejściu na ekran.

### Naprawiono
- usunięto z karty zgłoszenia zbędny przycisk „Rejestracja – potwierdzono”, pozostawiony po uproszczeniu workflow,
- ujednolicono interpretację zapełnienia turnusu na Dashboardzie i w module Turnusy.

## [0.18.6] - 2026-07-22

### Dodano
- sekcję „Powiadomienia workflow” w ustawieniach wtyczki,
- możliwość wyboru osobno dla każdego etapu: brak powiadomienia, e-mail, SMS albo e-mail i SMS,
- bezpośredni odnośnik z ustawień powiadomień do edycji właściwego szablonu e-mail i SMS,
- kontrolę kompletności szablonów dla wszystkich zdarzeń workflow,
- oznaczenie komunikatów jednorazowych i automatyczną blokadę ponownej wysyłki tego samego skutecznego powiadomienia.

### Zmieniono
- po wstępnej rejestracji Formularz Obozowy jest dostępny od razu, bez ręcznej akceptacji administratora,
- pierwszy e-mail jednocześnie dziękuje za zgłoszenie i zawiera przycisk prowadzący do Formularza Obozowego,
- usunięto z interfejsu akcje „Potwierdź rejestrację”, „Zaakceptuj rejestrację” i „Wyślij formularz po akceptacji”,
- ustawienia powiadomień są połączone z edytowalnym systemem szablonów,
- rozwiązane zgłoszenia Feedback nie udostępniają żadnych dalszych akcji.

### Naprawiono
- zabezpieczono potwierdzenie podpisania umowy przed wysłaniem zdublowanej wiadomości e-mail,
- centralny silnik komunikacji respektuje ustawienia kanałów niezależnie od miejsca wywołania powiadomienia.

## [0.18.5] - 2026-07-22

### Naprawiono
- poprawiono generowanie odnośników do raportów „Lista strojów” i „Lista uczestników”; nonce nie jest już zapisywany w adresie jako zakodowane `&amp;`, co powodowało komunikat „Wybrany odnośnik jest nieaktualny”.

### Dodano
- zaznaczanie wielu faktur na liście faktur,
- zbiorcze pobieranie wybranych faktur w jednym pliku PDF,
- zbiorczą wysyłkę wybranych faktur do organizatorów; dokumenty są automatycznie grupowane według organizatora,
- ustawienie dnia miesiąca od 1 do 28 dla automatycznej wysyłki faktur z poprzedniego miesiąca,
- ręczny przycisk wysyłki faktur za poprzedni miesiąc,
- generowanie osobnego zbiorczego PDF dla każdego organizatora i wysyłkę na adres e-mail zapisany w danych organizatora.

### Zmieniono
- mechanizm przed wysyłką weryfikuje liczbę zaznaczonych lub miesięcznych faktur,
- istniejące pole e-mail organizatora jest wykorzystywane jako adres odbiorcy zestawień faktur.
