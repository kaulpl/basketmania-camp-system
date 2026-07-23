=== Basketmania Camp System ===
Contributors: basketmania
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.19.4.1

System zapisów, CRM, panelu rodzica, umów, płatności, poczty i dokumentów Basketmania Camp.

== Changelog ==

= 0.17.0 =
* Dodano moduł Feedback do zgłaszania błędów, poprawek i nowych funkcjonalności.
* Dodano przycisk „Zgłoś uwagę” w modułach administracyjnych.
* Dodano statusy Nowe, W trakcie, Rozwiązano i Anulowano.

= 0.16.4 =
* Edytowalny draft umowy w karcie CRM jest jedynym źródłem dokumentu wysyłanego rodzicowi.
* Wysłanie umowy publikuje zapisany draft bez ponownego generowania treści.
* Po wysłaniu draft zostaje zablokowany przed edycją, a podpis OTP dotyczy dokładnie opublikowanej wersji i jej skrótu SHA-256.
* Podpisana umowa zachowuje treść wysłanej wersji; nie jest regenerowana po podpisaniu.

= 0.16.3 =
* Usunięto dodatkowe kolorowe koło generowane pod znacznikiem każdego kroku osi procesu.
* Poprawiono zawijanie opisów kroków, aby tekst nie wychodził poza obramowanie kafelka.
* Uporządkowano szerokości, odstępy i zachowanie opisów na komputerze oraz urządzeniach mobilnych.

= 0.14.0 =
* Wydzielono centralny Workflow Engine jako jedyny punkt dostępu modułów do reguł procesu.
* Wydzielono centralny Template Engine dla e-maili, SMS-ów i dokumentów.
* Wydzielono centralny Document Engine dla generowania, przechowywania i pobierania PDF.
* Wydzielono centralny Communication Engine dla e-maili, SMS-ów, logowania i wyników wysyłki.
* Dodano rejestr BCS_Core i numer wersji architektury.
* Moduły CRM, płatności, umów, faktur, frontendu i panelu administracyjnego korzystają z nowych silników.
* Zachowano kompatybilność z dotychczasowymi klasami wykonawczymi i danymi wersji 0.13.2.

= 0.13.2 =
* Centralizacja wzoru umowy, regulaminu i wiadomości 7 dni przed obozem.
* Regulamin PDF automatycznie dołączany do wiadomości przed obozem.
* Uproszczony formularz turnusu i przypomnienie o płatności EMAIL + SMS.
* Dynamiczne ustawianie daty końcowej turnusu.

= 0.12.3 =
* Panel Rodzica: stały podgląd danych zgłoszenia i formularza obozowego także po ich zaakceptowaniu.
* Pełne dane odbiorcy przelewu w Panelu Rodzica i przypomnieniu e-mail o płatności.
* Po opłaceniu zgłoszenia dane przelewowe są ukrywane, pozostaje komunikat ZAPŁACONO.

= 0.12.0 =
* Nowy moduł Faktury z listą, podglądem PDF, pobieraniem i usuwaniem.
* Automatyczne wysyłanie faktury e-mailem i powiadomienie SMS.
* Rejestrowanie wysłania oraz pobrania faktury przez Panel Rodzica.
* Nowoczesny szablon faktury powiązany z organizatorem turnusu.
* Rozszerzony system dokumentów i numeracja faktur per organizator i rok.

= 0.11.8 =
* Odświeżony nagłówek i statusy Panelu Rodzica.
* Publiczne pobieranie dokumentów zabezpieczone tokenem.
* Edycja formularza obozowego do czasu akceptacji organizatora.
* Dziesięciominutowa blokada edycji podczas przeglądania Karty zgłoszenia.
* Stany płatności w czerwonym i zielonym bloku.
* Zielone potwierdzenia formularza, umowy i płatności w podsumowaniu CRM.

= 0.12.2 =
* Czytelne, polskie nazwy zdarzeń w module Logi.
* Oznaczenie wykonawcy zdarzenia: system, rodzic lub administrator.
* Rozszerzony reset fabryczny zachowujący wyłącznie Organizatorów i Turnusy.

= 0.12.1 =
* Ujednolicone zielone ikony wykonania w podsumowaniu Karty zgłoszenia.
* Tryb testowy obejmuje ograniczenie daty podpisu umowy i generowania faktury.
* Po opłaceniu Stripe lub przelewem faktura odblokowuje się zgodnie z workflow.
* Zakończone zgłoszenia z wysłaną fakturą mają delikatnie zielone tło na liście.
* Kolumna Kontakt otwiera wspólny popup wysyłki e-mail.
