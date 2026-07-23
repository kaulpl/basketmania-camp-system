# Changelog

Wszystkie istotne zmiany w Basketmania Camp System są dokumentowane w tym pliku.

## [0.19.0] - 2026-07-23

### Dodano
- sekcję „Obsługa zgłoszenia” grupującą szybkie działania, akceptację formularza, notatki, zadania i kontakt telefoniczny,
- edycję ceny zgłoszenia bezpośrednio przy kafelku ceny; cena jest automatycznie przenoszona do wzoru umowy,
- blokadę edycji ceny po wysłaniu umowy do podpisu,
- akcję „Wycofaj umowę przed podpisem”, która cofa niepodpisaną umowę do etapu wzoru i ponownie odblokowuje cenę,
- powiadomienia AJAX: małe komunikaty w Feedback oraz centralne popupy sukcesu/błędu w obsłudze zgłoszenia,
- zapis pierwszego otwarcia wzoru i umowy do Historii klienta,
- datę pierwszego otwarcia umowy w sekcji cyfrowego potwierdzenia podpisu,
- pełny numer telefonu użyty do autoryzacji SMS w podpisie cyfrowym.

### Zmieniono
- słowo „draft” zastąpiono określeniem „wzór umowy” w interfejsie użytkownika,
- po zaakceptowaniu formularza Panel Rodzica informuje, że organizator przekazał wzór umowy,
- podpis SMS wymaga wcześniejszego otwarcia umowy i zaznaczenia wymaganych oświadczeń,
- komunikat przy umowie gotowej do podpisu opisuje pełny proces zapoznania się z dokumentem, załącznikami i podpisania kodem SMS,
- akcje Feedback oraz przyciski obsługi zgłoszenia wykonują się bez przeładowania strony,
- ustawienia powiadomień są osobną sekcją „Ustawienia powiadomień” umieszczoną pod sekcją E-mail,
- „Ustawienia wtyczki” przemianowano na „Ustawienia ogólne”, a nadmiarową górną sekcję usunięto,
- sekcja dokumentów i automatyzacji otrzymała pomarańczowy wyróżnik.

### Naprawiono
- usunięto zdublowany komunikat o zablokowanym formularzu w Panelu Rodzica,
- anulowane zgłoszenia nie udostępniają akcji wysyłania umów, faktur, SMS-ów ani kolejnych etapów workflow.

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
- rozwijane sekcje ustawień, zapamiętywanie ich stanu, dwukolorowe paski zapełnienia i informacyjny limit miejsc.

## [0.18.6] - 2026-07-22
- ustawienia kanałów workflow, edytowalne szablony i uproszczony proces rejestracji.

## [0.18.5] - 2026-07-22
- poprawione raporty oraz zbiorcze operacje na fakturach.

## [0.18.4] - 2026-07-22
- listy strojów i uczestników oraz roczny wykres zgłoszeń.

## [0.18.3] - 2026-07-22
- aktualizator korzystający z GitHub Releases bez limitowanego API.

## [0.18.2] - 2026-07-22
- poprawione ręczne sprawdzanie aktualizacji i cache.

## [0.18.1] - 2026-07-22
- Centrum serwisowe i diagnostyka systemu.

## [0.18.0] - 2026-07-22
- automatyczne aktualizacje przez GitHub Releases i paczki produkcyjne.

## [0.17.0]
- poprzednia stabilna wersja systemu.
