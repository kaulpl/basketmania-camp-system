# Basketmania Camp System 0.16.4 – aktualizacja

## Najważniejsza poprawka

Obieg umowy korzysta teraz z jednej, kontrolowanej treści:

1. Po zatwierdzeniu formularza system tworzy draft umowy.
2. Administrator może edytować draft w karcie CRM i zapisać zmiany.
3. Akcja „Wyślij umowę” publikuje dokładnie zapisany draft — bez ponownego generowania z szablonu.
4. Po wysłaniu edycja zostaje zablokowana.
5. Kod OTP podpisuje tę samą treść i ten sam skrót SHA-256.
6. Po podpisaniu dokument nie jest regenerowany ani nadpisywany.

## Instalacja

Przed aktualizacją wykonaj kopię plików i bazy danych. W panelu WordPress wgraj ZIP jako nową wersję istniejącej wtyczki i zaakceptuj zastąpienie plików.
