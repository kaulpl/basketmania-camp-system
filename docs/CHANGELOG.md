# 0.16.4

- Naprawiono pełny obieg edytowalnego draftu umowy.
- Akcja wysłania umowy publikuje aktualny draft bez ponownego renderowania szablonu.
- Po publikacji treść jest blokowana, a wersja `sent` otrzymuje identyczny HTML i hash jak zatwierdzony draft.
- Podpis OTP zapisuje wersję podpisaną na podstawie tej samej, niezmienionej treści.

# 0.16.3

- Usunięto dodatkowe kolorowe koło wyświetlane pod głównym znacznikiem kroku.
- Naprawiono konflikt ogólnego stylu elementów `span` z kontenerem opisu etapu.
- Opisy kroków poprawnie zawijają się i pozostają wewnątrz obramowania kafelków.
- Poprawiono szerokości oraz odstępy etykiety „TERAZ” na komputerze i telefonie.

# 0.16.2

- Uproszczono oś procesu w Panelu Rodzica do pięciu głównych etapów: Rejestracja, Formularz, Umowa, Płatność i Faktura.
- Na komputerze etapy są prezentowane w poziomej osi procesu.
- Na urządzeniach mobilnych oś automatycznie zmienia się w pionową listę połączonych etapów.
- Zaktualizowano licznik postępu do skali 5 etapów.
- Opisy etapów zmieniają się zgodnie z rzeczywistym statusem zgłoszenia.

# 0.16.1

## Panel Rodzica
- Przebudowano wizualnie nagłówek, kartę turnusu oraz układ głównych kart.
- Dodano responsywną oś procesu z ośmioma etapami i licznikiem postępu.
- Rozdzielono etap przygotowania umowy od podpisu OTP oraz fakturę od płatności.
- Aktywny etap procesu jest oznaczony etykietą „TERAZ”.

## Dane adresowe
- Podgląd zgłoszenia i formularza obozowego pokazuje osobno kod pocztowy, miejscowość, ulicę i numer domu/lokalu.
- Karta Zgłoszenia CRM pokazuje te same pola osobno.
- Generowanie faktury pozostaje bez zmian: adres nabywcy jest składany do jednego pełnego bloku.

# 0.16.0

## Dodano
- Rozdzielone pola adresu rodzica: kod pocztowy, miejscowość, ulica oraz numer domu/lokalu.
- Filtrowanie i szybkie wyszukiwanie na liście faktur.
- Kolumnę Organizator na liście faktur.
- Ujednolicony sposób składania adresu w umowach, fakturach, CRM i dokumentach.

## Zmieniono
- Pole kwoty zgłoszenia opisano jako indywidualną cenę turnusu; nadal jest ono niezależne od ceny bazowej turnusu i zasila płatności, umowy i faktury.
- Podgląd poczty oczyszcza pełne dokumenty HTML, arkusze CSS i techniczne elementy koperty.
- Logi działań administratora zapisują ID użytkownika WordPress, login i nazwę wyświetlaną.
- Wyrównano ikony w przyciskach akcji faktur.

## Kompatybilność
- Stare pole adresu pozostaje zachowane jako snapshot, dlatego aktualizacja nie usuwa dotychczasowych danych.

# Changelog

## 0.15.4
- przebudowano renderowanie korespondencji e-mail w Karcie Zgłoszenia i module Poczta;
- podgląd pokazuje wyłącznie właściwą treść wiadomości, bez tematu powielonego w treści, prefiksów technicznych, nagłówków HTML i arkusza CSS;
- usunięto problem wyświetlania fragmentu kodu `body,table,td,a{...}` przed wizualizacją wiadomości;
- skróty wiadomości są generowane z oczyszczonej treści także dla starszych rekordów zapisanych w bazie;
- wiadomości wysłane i odebrane korzystają z jednego mechanizmu czyszczenia i renderowania;
- nowe rekordy pocztowe zapisują poprawnie oczyszczone pole tekstowe.

## 0.15.3

- Naprawiono podwójne prezentowanie treści wiadomości e-mail w Karcie Zgłoszenia.
- Przycisk „Otwórz wiadomość” uruchamia jednolity podgląd HTML w oknie modalnym.
- Logi czynności administratorów zapisują ID, login i nazwę użytkownika WordPress.
- Dodano warunkowe bloki „Alergie / specjalne potrzeby” oraz „Dodatkowe informacje dla organizatora”.
- Ujednolicono wygląd bloku Faktura z pozostałymi elementami podsumowania.
- Ujednolicono modalne podglądy danych oraz pionowe wyrównanie ikon w przyciskach.

## 0.15.2
- naprawiono przyciski pobierania podpisanej umowy i faktury w wiadomościach e-mail;
- wszystkie publiczne linki do dokumentów zawierają token powiązany z identyfikatorem zgłoszenia i typem dokumentu;
- pobieranie bez tokenu lub z tokenem innego dokumentu kończy się odpowiedzią 403;
- Panel Rodzica, CRM i wiadomości e-mail korzystają z jednego kontrolera pobierania dokumentów;
- po podpisaniu umowy wysyłany jest jeden e-mail łączący potwierdzenie podpisu, podziękowanie, bezpieczny przycisk pobrania umowy oraz warunki i dane płatności;
- usunięto dwie dodatkowe, dublujące wiadomości wysyłane po podpisaniu umowy;
- dodano logowanie poprawnych i odrzuconych prób pobrania dokumentów.

## 0.15.1
- Limit wysyłek kodu OTP na godzinę jest pobierany z ustawienia `otp_send_limit` i sprawdzany po stronie serwera.
- Rozdzielono limit wysyłek OTP od limitu błędnych prób wpisania kodu.
- Wysyłka OTP nie jest już powiązana z blokadą edycji Formularza Obozowego.
- Blokada Formularza Obozowego działa tylko do chwili zaakceptowania formularza.
- Po akceptacji formularza komunikaty i ikony blokady nie są wyświetlane.
- Pole czasu blokady przeniesiono do pierwszej sekcji „Ustawienia wtyczki”.
- Wszystkie podglądy danych formularzy otrzymały jednolity wygląd zgodny z podglądem na liście CRM.

## 0.15.0
- Konfigurowalna blokada Formularza Obozowego.
- Licznik blokady w Panelu Rodzica.
- Rozbudowane logowanie OTP i ikona blokady w CRM.
