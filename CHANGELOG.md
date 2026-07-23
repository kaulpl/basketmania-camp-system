# Changelog

Wszystkie istotne zmiany w Basketmania Camp System są dokumentowane w tym pliku.

## [0.18.9] - 2026-07-23

### Zmieniono
- lista strojów oraz lista uczestników dla każdego turnusu obejmują wyłącznie zgłoszenia z podpisaną umową i potwierdzoną pełną wpłatą,
- w wiadomości po rejestracji komunikat o braku konieczności akceptacji administratora zastąpiono zdaniem: „Przejdź od razu do Panelu Rodzica i go wypełnij.”,
- wszystkie checkboxy w systemie otrzymały jednolity wygląd przełączników,
- sekcje ustawień ogólnych i powiadomień workflow korzystają z tego samego stylu rozwijania co osobne sekcje E-mail i SMS,
- nagłówki sekcji Formularza Obozowego otrzymały pomarańczowe wyróżniki,
- Panel Rodzica jest prezentowany jako samodzielna strona bez nagłówka i stopki motywu WordPress,
- w górnym bloku turnusu wyświetlany jest wyśrodkowany tytuł „Panel Rodzica”,
- komunikat trybu testowego został skrócony do „Wersja testowa systemu”,
- przy kroku „Umowa” dla turnusów w przyszłym roku dodano informację o wysyłce umów od początku stycznia.

### Usunięto
- element „Rejestracja potwierdzona – wykonano” z sekcji „Szybkie czynności”,
- zielony komunikat „Bezpieczny dostęp” z Panelu Rodzica,
- osobny górny blok „Strefa uczestnika / Panel Rodzica”,
- nagłówek, menu i stopkę aktywnego motywu WordPress z widoku Panelu Rodzica.

## [0.18.8] - 2026-07-23

### Przywrócono i skonsolidowano
- kompletne ustawienia kanałów powiadomień dla każdego etapu workflow: brak, e-mail, SMS albo oba kanały,
- edytowalne szablony e-mail i SMS dla wszystkich zdarzeń workflow,
- walidację kompletności szablonów przed zapisaniem aktywnego kanału,
- automatyczne uzupełnianie brakujących domyślnych treści SMS bez nadpisywania zmian administratora,
- bezpośrednie odnośniki z ustawień powiadomień do właściwego szablonu,
- status kompletności e-mail/SMS w module Szablony,
- zabezpieczenie przed ponowną wysyłką komunikatów jednorazowych.

### Zawiera również
- uproszczony workflow bez ręcznego potwierdzania wstępnej rejestracji,
- rozwijane i zapamiętywane sekcje na ekranie Ustawienia,
- usunięcie zbędnego przycisku „Rejestracja – potwierdzono”,
- dwukolorowy pasek zapełnienia turnusu: pomarańczowy dla zgłoszeń podpisanych i w pełni opłaconych, szary dla pozostałych rozpoczętych i nieanulowanych,
- informacyjny limit uczestników, który nigdy nie blokuje kolejnych rejestracji.

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

## [0.18.4] - 2026-07-22

### Dodano
- przyciski „Lista strojów” przy kafelkach turnusów na Dashboardzie i ekranie Turnusy,
- PDF z listą strojów posortowaną od najmniejszego do największego rozmiaru, zawierający numer koszulki z prefiksem `#`, rozmiar oraz imię i nazwisko uczestnika,
- przyciski „Lista uczestników” przy kafelkach turnusów,
- PDF z aktualną listą uczestników posortowaną według daty urodzenia, zawierający numer kolejny, imię, nazwisko, alergie, potrzeby specjalne, inne informacje od rodzica oraz wiek obliczony na dzień rozpoczęcia turnusu,
- roczny wykres zgłoszeń na Dashboardzie z podziałem na wszystkie miesiące i łączną liczbą zgłoszeń.

### Zmieniono
- moduł „Nowe zgłoszenia” na Dashboardzie zastąpiono czytelnym wykresem zgłoszeń dla bieżącego roku.

## [0.18.3] - 2026-07-22

### Naprawiono
- usunięto zależność mechanizmu aktualizacji od limitowanego endpointu GitHub API,
- sprawdzanie najnowszej wersji korzysta teraz z publicznego przekierowania strony GitHub Releases,
- wyeliminowano błędy HTTP 403 powodowane wyczerpaniem limitu 60 anonimowych zapytań na godzinę,
- adres paczki ZIP jest budowany na podstawie numeru najnowszego taga i nazwy pliku generowanego przez workflow.

### Zmieniono
- Centrum serwisowe pokazuje stan połączenia z GitHub Releases zamiast testu GitHub API,
- diagnostyka informuje, że sprawdzanie aktualizacji nie zużywa limitu GitHub API.

## [0.18.2] - 2026-07-22

### Naprawiono
- ręczne użycie opcji „Sprawdź ponownie” w WordPressie zawsze pobiera świeże dane najnowszego Release z GitHuba,
- własny cache aktualizatora nie blokuje już wykrycia nowej wersji przez kilka godzin,
- aktualizator poprawnie obsługuje pusty lub niepełny obiekt transientu WordPressa,
- wpis aktualizacji i wpis „brak aktualizacji” są poprawnie porządkowane w transientach WordPressa.

### Zmieniono
- czas przechowywania danych Release w cache skrócono z 6 godzin do 30 minut,
- Centrum serwisowe pokazuje ostatni wynik sprawdzenia, wersję z GitHuba, dostępność paczki oraz wynik porównania wersji,
- dodano przycisk „Sprawdź aktualizacje GitHub teraz”, który czyści cache i wykonuje pełne sprawdzenie.

## [0.18.1] - 2026-07-22

### Dodano
- Centrum serwisowe dostępne z menu Basketmania Camp,
- diagnostykę wersji wtyczki, WordPressa i PHP,
- kontrolę wersji schematu bazy danych i obecności najważniejszych tabel,
- kontrolę katalogu uploads, autoloadera Composer i silnika DOMPDF,
- kontrolę połączenia z GitHub API i numeru najnowszego wydania,
- narzędzia do czyszczenia cache aktualizacji, ręcznego uruchamiania migracji oraz generowania testowego PDF,
- ikonę kopiowania pełnej treści opisu bezpośrednio na liście zgłoszeń Feedback.

### Zmieniono
- numer wersji wtyczki z `0.18.0` na `0.18.1`,
- lista Feedback nadal wyświetla pełny opis, a obok niego udostępnia szybkie kopiowanie do schowka.

## [0.18.0] - 2026-07-22

### Dodano
- automatyczne sprawdzanie nowych wersji przez GitHub Releases,
- wyświetlanie aktualizacji na standardowym ekranie wtyczek WordPress,
- pobieranie paczki ZIP bezpośrednio z publicznego repozytorium GitHub,
- obsługę informacji o wydaniu i listy zmian w oknie szczegółów wtyczki,
- automatyczne czyszczenie pamięci podręcznej aktualizatora po instalacji nowej wersji,
- workflow GitHub Actions budujący paczkę ZIP po utworzeniu tagu `v*`,
- automatyczną instalację produkcyjnych zależności Composer podczas budowania wydania,
- kontrolę obecności DOMPDF przed opublikowaniem paczki ZIP.

### Zmieniono
- numer wersji z `0.18.0-dev` na stabilne `0.18.0`,
- źródło aktualizacji z planowanego serwera licencyjnego na GitHub Releases,
- paczka instalacyjna zawiera teraz katalog `vendor` wraz z DOMPDF i autoloaderem Composer.

### Usunięto
- tymczasowy mechanizm blokowania działania wtyczki kodem licencyjnym.

## [0.17.0]
- poprzednia stabilna wersja systemu.