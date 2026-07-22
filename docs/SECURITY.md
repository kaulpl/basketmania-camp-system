# Bezpieczeństwo dokumentów

Od wersji 0.15.2 każdy link pobierania dokumentu zawiera kryptograficzny token HMAC powiązany jednocześnie z identyfikatorem zgłoszenia i typem dokumentu. Token umowy nie pozwala pobrać faktury, a token jednego zgłoszenia nie pozwala pobrać dokumentów innego zgłoszenia.

Walidacja odbywa się po stronie serwera przed odczytaniem pliku. Brak tokenu lub token niepoprawny powoduje odpowiedź HTTP 403 i zapis zdarzenia w logach. Linki tworzone w Panelu Rodzica, CRM oraz wiadomościach e-mail korzystają z tego samego kontrolera dokumentów.

Tokenów nie należy kopiować do publicznych postów ani udostępniać osobom trzecim. Zmiana kluczy bezpieczeństwa WordPress unieważni wcześniej utworzone linki.
