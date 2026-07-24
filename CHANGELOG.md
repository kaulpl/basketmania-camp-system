# Changelog

Wszystkie istotne zmiany w Basketmania Camp System są dokumentowane w tym pliku.

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

### Naprawiono
- usunięto użycie szablonu potwierdzenia rezerwacji jako przypomnienia o umowie,
- dodano czytelne nazwy automatycznych zdarzeń w Historii klienta,
- doprecyzowano opisy terminów automatyzacji w module Ustawienia.

## [0.20.18] - 2026-07-23

### Zmieniono
- „Historia klienta” na Karcie Zgłoszenia jest rozwijaną sekcją,
- historia pobiera wszystkie logi powiązane przez `registration_id` z danym zgłoszeniem,
- ręczne wpisy CRM pozostają widoczne, a wpisy posiadają czytelną nazwę, autora i dostępny opis.

### Naprawiono
- zdarzenia są sortowane stabilnie według daty, źródła i identyfikatora,
- usunięto dopisywanie zdarzeń umowy po renderowaniu strony, które umieszczało je poza chronologią.

## [0.20.17] - 2026-07-23

### Zmieniono
- wszystkie szybkie czynności na Karcie Zgłoszenia są wykonywane przez AJAX, bez przeładowania strony,
- po powodzeniu widok Karty Zgłoszenia jest podmieniany aktualnym stanem zwróconym przez serwer.

### Naprawiono
- przywrócono centralne, automatycznie znikające po 2 sekundach komunikaty wyniku,
- udana akcja jest oznaczona dużym zielonym ptaszkiem, a nieudana dużym czerwonym krzyżykiem,
- wycofanie umowy nie wymusza już przeładowania strony.

## [0.20.16] - 2026-07-23

### Zmieniono
- wiadomość potwierdzająca Formularz Obozowy rozpoczyna wskazane akapity od „Informujemy, że dane…” oraz „Wzór umowy…”,
- istniejący szablon jest aktualizowany punktowo, bez nadpisywania pozostałych zmian administratora.

### Naprawiono
- usunięto własne zakończenie „Pozdrawiamy / Basketmania Camp”, dzięki czemu wiadomość zawiera tylko wspólną stopkę „Z pozdrowieniami, Zespół Basketmania Camp”.

## [0.20.14] - 2026-07-23

### Zmieniono
- przycisk „Wyślij link Stripe” na Karcie Zgłoszenia działa przez zabezpieczony endpoint AJAX i pokazuje czytelny wynik bez przeładowania strony,
- każdy organizator ma własną roczną sekwencję numerów umów; istniejące numery i treści dokumentów nie są zmieniane,
- indeks unikalności umów, podobnie jak faktur, uwzględnia organizatora.

### Naprawiono
- błąd utworzenia sesji Stripe lub wysyłki wiadomości jest przekazywany administratorowi zamiast kończyć się nieskutecznym powrotem do Karty Zgłoszenia,
- numer porządkowy nowej umowy nie jest już globalnym ID zgłoszenia,
- zachowano pełną weryfikację pozytywnej płatności Stripe: powiązanie płatności, zgłoszenia, organizatora i sesji oraz zgodność kwoty, waluty i statusu `paid`.

## [0.20.13] - 2026-07-23

### Zmieniono
- po zapisaniu formularza wstępnego zgłoszenie od razu otrzymuje dostęp do Panelu Rodzica i Formularza Obozowego,
- rodzic jest automatycznie przekierowywany do Panelu Rodzica z otwartym Formularzem Obozowym,
- wiadomość po rejestracji zawiera podziękowanie, informację o oczekiwaniu na pełny formularz oraz przycisk prowadzący bezpośrednio do Formularza Obozowego,
- dotychczasowy standardowy szablon wiadomości rejestracyjnej jest bezpiecznie migrowany bez nadpisywania własnych, zmodyfikowanych treści.

### Naprawiono
- wszystkie przyciski mogące pojawić się w kolumnie „Szybkie akcje” korzystają z jednego endpointu AJAX,
- wykonanie szybkiej akcji nie otwiera Karty Zgłoszenia i nie przeładowuje listy,
- po sukcesie właściwy wiersz otrzymuje aktualny etap, rozliczenie, postęp i kolejną dostępną akcję,
- wynik działania jest prezentowany przez dwusekundowy centralny popup sukcesu lub błędu.

## [0.20.11] - 2026-07-23

### Zmieniono
- pod przyciskiem „Potwierdź podpis umowy SMS-em” dodano informację o warunkach jego aktywacji,
- oczekująca płatność Stripe jest opisana czytelnym statusem bez technicznego identyfikatora sesji `cs_...`.

### Naprawiono
- poprawny powrót z Checkout Stripe jest dodatkowo weryfikowany bezpośrednio przez API Stripe i może zaksięgować płatność niezależnie od opóźnienia webhooka,
- potwierdzenie płatności zawsze aktualizuje powiązanie `payment_id`, kwotę `paid_amount` i status całego zgłoszenia,
- ponowne potwierdzenie odbudowuje sumę wpłat z rekordów oznaczonych jako opłacone bez podwójnego naliczania.

## [0.20.10] - 2026-07-23

### Zmieniono
- po przesłaniu Formularza Obozowego rodzic może otworzyć jego podgląd w trybie edycji aż do akceptacji przez administratora,
- blok Umowa pokazuje komunikat „Wzór umowy będzie dostępny po zaakceptowaniu Formularza Obozowego”,
- nazwa „Formularz Obozowy” jest stosowana spójnie w elementach interfejsu objętych zmianą.

### Naprawiono
- Panel Rodzica cyklicznie sprawdza blokadę i odświeża widok, gdy administrator otworzy Kartę Zgłoszenia,
- zapis Formularza Obozowego nadal jest chroniony serwerowo przed równoczesną edycją,
- szybkie akcje listy zgłoszeń są wykonywane przez AJAX, bez przejścia do Karty Zgłoszenia, z dwusekundowym zielonym potwierdzeniem.

## [0.20.9] - 2026-07-23

### Zmieniono
- moduł Ustawienia ma pięć spójnych, rozwijanych sekcji: Ustawienia Ogólne, Bramka SMS, E-Mail, Ustawienia dokumentów i automatyzacji oraz Ustawienia powiadomień SMS / E-Mail,
- wszystkie sekcje są domyślnie rozwinięte i zawierają od razu właściwe pola oraz przyciski zapisu,
- sekcja ustawień powiadomień jest renderowana bezpośrednio na stronie, bez przenoszenia jej przez JavaScript.

### Naprawiono
- przycisk zapisu ustawień dokumentów i automatyzacji pozostaje we właściwym formularzu i uruchamia zapis serwerowy,
- starsze skrypty zgodności nie przepinają już formularzy ani nie tworzą dodatkowych sekcji na ekranie Ustawienia.

## [0.20.8] - 2026-07-23

### Zmieniono
- szablon wiadomości z linkiem Stripe korzysta z przycisku zgodnego z pozostałymi wiadomościami systemowymi,
- obok daty płatności na liście zgłoszeń wyświetlana jest ikona Stripe, gdy płatność została potwierdzona przez Stripe.

### Naprawiono
- status wysłania linku Stripe jest zapisywany dopiero po skutecznym przekazaniu wiadomości e-mail,
- webhook Stripe weryfikuje identyfikator sesji, kwotę i walutę przed zaksięgowaniem,
- ponowne dostarczenie tego samego zdarzenia Stripe nie nalicza płatności drugi raz ani nie wysyła kolejnego potwierdzenia.

## [0.20.7] - 2026-07-23

### Zmieniono
- lista zgłoszeń jest domyślnie sortowana zgodnie z kolejnością etapów procesu,
- najnowsze i oczekujące na Formularz Obozowy zgłoszenia są na górze, a zakończone „Opłacone” i „Anulowane” na dole,
- w obrębie każdego etapu nowsze zgłoszenia są wyświetlane wyżej.

## [0.20.6] - 2026-07-23

### Zmieniono
- tabela konfiguracji kanałów workflow znajduje się w osobnej rozwijanej sekcji „Powiadomienia SMS/EMAIL”,
- sekcja korzysta z tego samego układu, ikony, nagłówka i mechanizmu rozwijania co „Bramka SMS” oraz „E-MAIL”.

## [0.20.5] - 2026-07-23

### Naprawiono
- definicje indeksów tabel `messages` i `invoices` są zapisane po jednym kluczu na wiersz, zgodnie z wymaganiami parsera WordPress `dbDelta()`,
- usunięto przyczynę błędnych poleceń `ADD PRIMARY KEY`, w których `KEY` i `UNIQUE` były traktowane jak nazwy kolumn,
- podniesiono wersję schematu bazy, aby poprawna migracja wykonała się przy aktualizacji istniejącej instalacji.

## [0.20.4] - 2026-07-23

### Zmieniono
- numer umowy ma format `[prefiks umów z Ustawień]/[prefiks organizatora]/[ROK]/[NUMER]`,
- numer faktury ma format `[prefiks faktur z Ustawień]/[prefiks organizatora]/[ROK]/[NUMER]`,
- pole prefiksu organizatora jest wspólne dla umów i faktur; pierwszy człon obu numerów pozostaje konfigurowany w module Ustawienia.

## [0.20.3] - 2026-07-23

### Dodano
- pole prefiksu faktur w ustawieniach każdego organizatora,
- format numeru `FV/[prefiks organizatora]/[ROK]/[kolejny numer]`.

### Zmieniono
- każdy organizator prowadzi własną roczną sekwencję numerów faktur,
- indeks numerów faktur uwzględnia organizatora, dzięki czemu różni organizatorzy mogą mieć niezależną numerację.

## [0.20.2] - 2026-07-23

### Naprawiono
- numeracja faktur uwzględnia wszystkie faktury z tym samym prefiksem i rokiem, niezależnie od organizatora, zgodnie z globalnym indeksem unikalnym `invoice_number`,
- wspólna blokada przydzielania numerów zapobiega wybraniu tego samego numeru przez dwa równoległe żądania,
- generator ponownie sprawdza istnienie faktury po uzyskaniu blokady.

## [0.20.1] - 2026-07-23

### Naprawiono
- generowanie faktury nie korzysta już z niedostępnej na części hostingów funkcji MySQL `GET_LOCK`, która zanieczyszczała odpowiedź AJAX kodem HTML,
- endpoint faktury wywołuje jawnie operację silnika workflow i przechwytuje błędy serwera,
- interfejs pokazuje czytelny komunikat zamiast technicznego błędu `Unexpected token '<'`.

## [0.20.0] - 2026-07-23

### Naprawiono
- generowanie faktury z listy i karty zgłoszenia zapisuje oraz weryfikuje rekord w module Faktury,
- brak obsługi MySQL `GET_LOCK` nie blokuje generowania, a błąd zapisu faktury nie jest już zgłaszany jako sukces,
- karta zgłoszenia aktualizuje stan akcji faktury przez AJAX i udostępnia podgląd oraz pobranie PDF,
- blok obsługi zgłoszenia nie pojawia się na liście, a wycofanie niepodpisanej umowy pozostaje w Szybkich czynnościach karty,
- blok Dane i formularze w Panelu Rodzica zawiera jedną właściwą akcję Formularza Obozowego,
- sekcje Ustawień mają pojedyncze tytuły, ikony, poprawną kolejność i wyrównanie opisów do lewej.

## [0.19.4.4] - 2026-07-23

### Naprawiono
- puste dane opcjonalne Formularza Obozowego są zamieniane na pusty tekst przed przekazaniem do funkcji WordPressa `esc_attr()` i `esc_textarea()`,
- usunięto ostrzeżenia PHP 8.1+ `htmlspecialchars(): Passing null to parameter #1`, które mogły pojawiać się wewnątrz pól formularza w Panelu Rodzica,
- ujednolicono szybkie akcje, obsługę generowania faktur, sekcje ustawień i komunikaty blokady Formularza Obozowego zgodnie z poprawkami zebranymi po wersji 0.19.4.3.

## [0.19.4.2] - 2026-07-23

### Naprawiono
- poprawiono znaczniki powrotu do PHP w `BCS_Release_0194`, których błędna składnia uniemożliwiała domknięcie metod i aktywację wtyczki.

### Zmieniono
- proces publikacji wykonuje kontrolę składni wszystkich plików PHP przed zbudowaniem paczki ZIP.

## [0.19.4.1] - 2026-07-23

### Zmieniono
- opublikowano techniczne wydanie poprawkowe na bazie stabilnej wersji 0.19.4,
- zsynchronizowano numer wersji w nagłówku wtyczki, stałej systemowej oraz pliku `readme.txt`,
- zachowano komplet edytowalnych szablonów e-mail i SMS dla wszystkich etapów workflow.

## [0.19.4] - 2026-07-23

### Naprawiono
- panel „Szybkie czynności” na karcie zgłoszenia jest stale widoczny i znajduje się bezpośrednio nad formularzem wysyłki e-mail,
- sekcja „Ustawienia powiadomień” nie jest już zagnieżdżona i działa jako pojedynczy blok umieszczony pod sekcją E-MAIL,
- sekcja „Ustawienia dokumentów i automatyzacji” otrzymała taki sam układ i styl jak sekcja E-MAIL,
- edycja ceny korzysta z centralnego, estetycznego modalu z przyciemnionym tłem, walidacją oraz przyciskami „Anuluj” i „Zapisz cenę”.

## [0.19.3] - 2026-07-23

### Naprawiono
- komunikat „Dane zostały zapisane i ponownie przekazane organizatorowi.” w Panelu Rodzica znika automatycznie po 3 sekundach,
- checkbox potwierdzenia w pierwotnym formularzu zapisów korzysta z zielonego przesuwanego przełącznika,
- przy kwocie płatności na karcie zgłoszenia dodano widoczny przycisk „Edytuj cenę”.

### Zasady edycji ceny
- cena może być zmieniona wyłącznie przed wysłaniem umowy do podpisu,
- zmieniona cena jest zapisywana w zgłoszeniu i przenoszona do istniejącego wzoru umowy,
- po wysłaniu lub podpisaniu umowy edycja ceny jest zablokowana,
- po wycofaniu niepodpisanej umowy cena ponownie staje się edytowalna.

## [0.19.2] - 2026-07-23

### Naprawiono
- pierwsze otwarcie wzoru umowy oraz pierwsze otwarcie umowy do podpisu są widoczne w Historii klienta na karcie zgłoszenia,
- podpisana umowa zawiera tylko jedną sekcję „Cyfrowe potwierdzenie podpisania umowy”,
- usunięto odstępy pomiędzy kolejnymi wierszami danych podpisu cyfrowego,
- sekcja podpisu cyfrowego otrzymała cienką pomarańczową ramkę,
- komunikat „Wysłano umowę do akceptacji” zmieniono na „Wysłano umowę do podpisu”.

## [0.19.1] - 2026-07-23

### Zmieniono
- usunięto dodatkową sekcję „Obsługa zgłoszenia” i przywrócono dotychczasowy blok „Szybkie czynności”,
- blok „Szybkie czynności” ponownie działa jako prawy sidebar karty zgłoszenia,
- „Notatka z telefonu” oraz „Dodaj notatkę” zostały przeniesione na sam dół bloku,
- akcję „Dodaj zadanie” usunięto z interfejsu,
- akcja „Wycofaj umowę przed podpisem” jest widoczna przy umowie oczekującej na podpis i działa przez AJAX.

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
