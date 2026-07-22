# Workflow

Blokada administratora dotyczy wyłącznie edycji Formularza Obozowego przed jego akceptacją. Po ustawieniu `form_verified_at` blokada nie jest tworzona, pokazywana ani sprawdzana.

OTP jest osobnym etapem. Wysyłka kodu zależy od ważności poprzedniego kodu oraz limitu `otp_send_limit`, ale nie od blokady Formularza Obozowego.
