<?php
if (!defined('ABSPATH')) exit;

class BCS_Utils {
    public static function timezone(): DateTimeZone { return new DateTimeZone('Europe/Warsaw'); }

    public static function timestamp(): int {
        return (new DateTimeImmutable('now', self::timezone()))->getTimestamp();
    }

    public static function now(): string {
        return (new DateTimeImmutable('now', self::timezone()))->format('Y-m-d H:i:s');
    }

    public static function today(string $format = 'Y-m-d'): string {
        return (new DateTimeImmutable('now', self::timezone()))->format($format);
    }

    public static function format_datetime(?string $value, string $format = 'd.m.Y H:i'): string {
        if (!$value) return '';
        try {
            // Daty zapisywane przez system są datami lokalnymi Basketmanii (Europe/Warsaw).
            $date = new DateTimeImmutable($value, self::timezone());
            return $date->setTimezone(self::timezone())->format($format);
        } catch (Exception $e) {
            return (string)$value;
        }
    }

    public static function client_ip(): string {
        $keys = ['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $raw = sanitize_text_field(wp_unslash($_SERVER[$key]));
                return trim(explode(',', $raw)[0]);
            }
        }
        return '';
    }

    public static function normalize_phone(string $phone): string {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) === 9) $digits = '48' . $digits;
        return $digits;
    }

    public static function mask_phone(string $phone): string {
        $p = self::normalize_phone($phone);
        if (strlen($p) < 5) return '***';
        return '+' . substr($p, 0, 2) . ' *** *** ' . substr($p, -3);
    }

    public static function event_labels(): array {
        return [
            'registration_created' => 'Utworzono zgłoszenie',
            'registration_created_from_email' => 'Utworzono zgłoszenie z wiadomości e-mail',
            'registration_admin_confirmed' => 'Potwierdzono rejestrację',
            'registration_edit_lock_started' => 'Administrator otworzył Kartę Zgłoszenia — włączono blokadę edycji',
            'parent_form_save_blocked' => 'Odrzucono zapis Formularza Obozowego — aktywna blokada administratora',
            'registration_edited_by_admin' => 'Zmieniono dane zgłoszenia w panelu administratora',
            'registration_cancelled' => 'Anulowano zgłoszenie',
            'parent_portal_invite_sent' => 'Wysłano dostęp do Panelu Rodzica',
            'parent_form_completed' => 'Rodzic przesłał formularz obozowy',
            'parent_form_updated' => 'Rodzic zaktualizował formularz obozowy',
            'camp_form_verified' => 'Organizator zaakceptował formularz obozowy',
            'agreement_draft_created' => 'Utworzono draft umowy',
            'agreement_created' => 'Utworzono umowę',
            'agreement_draft_edited' => 'Zmieniono treść draftu umowy',
            'agreement_sent_by_admin' => 'Wysłano umowę do podpisu',
            'agreement_signature_reminder_sent' => 'Wysłano przypomnienie o podpisaniu umowy',
            'auto_agreement_reminder' => 'Automatycznie wysłano przypomnienie o podpisaniu umowy',
            'auto_agreement_reminder_failed' => 'Nie udało się automatycznie wysłać przypomnienia o podpisaniu umowy',
            'auto_agreement_reminder_skipped' => 'Pominięto automatyczne przypomnienie o podpisaniu umowy',
            'auto_payment' => 'Automatycznie wysłano przypomnienie o płatności',
            'auto_payment_failed' => 'Nie udało się automatycznie wysłać przypomnienia o płatności',
            'auto_payment_skipped' => 'Pominięto automatyczne przypomnienie o płatności',
            'auto_pre_camp' => 'Automatycznie wysłano informacje przed obozem',
            'auto_pre_camp_failed' => 'Nie udało się automatycznie wysłać informacji przed obozem',
            'auto_pre_camp_skipped' => 'Pominięto automatyczne informacje przed obozem',
            'auto_reservation' => 'Automatycznie wysłano starsze przypomnienie o umowie',
            'otp_send_requested' => 'Rozpoczęto wysyłkę kodu SMS do podpisania umowy',
            'otp_sent' => 'Wysłano kod SMS do podpisania umowy',
            'otp_send_blocked_admin_lock' => 'Odrzucono wysyłkę kodu SMS — aktywna blokada administratora',
            'otp_send_blocked_active_code' => 'Odrzucono wysyłkę kodu SMS — poprzedni kod nadal aktywny',
            'otp_send_blocked_hourly_limit' => 'Odrzucono wysyłkę kodu SMS — limit godzinowy',
            'otp_send_failed' => 'Nie udało się wysłać kodu SMS do podpisania umowy',
            'otp_invalid' => 'Wprowadzono nieprawidłowy kod podpisu',
            'agreement_accepted' => 'Umowa została podpisana kodem SMS',
            'stripe_link_sent' => 'Wysłano link do płatności Stripe',
            'stripe_payment_confirmed' => 'Potwierdzono płatność Stripe',
            'bank_payment_marked_paid' => 'Zaksięgowano wpłatę tradycyjną',
            'payment_confirmation_sent' => 'Wysłano potwierdzenie płatności',
            'payment_reminder_sent' => 'Wysłano przypomnienie o płatności',
            'invoice_created' => 'Wygenerowano fakturę',
            'invoice_generated_manually' => 'Administrator uruchomił generowanie faktury',
            'invoice_delivery' => 'Wysłano fakturę e-mailem i powiadomiono SMS-em',
            'invoice_downloaded_by_parent' => 'Rodzic pobrał fakturę',
            'invoice_deleted' => 'Usunięto fakturę',
            'invoice_duplicate_generation_blocked' => 'Zablokowano próbę ponownego wygenerowania faktury',
            'communication_sent' => 'Wysłano wiadomość do klienta',
            'email_send_result' => 'Zakończono próbę wysyłki e-mail',
            'communication_email_error' => 'Błąd wysyłki wiadomości e-mail',
            'communication_sms_error' => 'Błąd wysyłki wiadomości SMS',
            'mailbox_reply' => 'Wysłano odpowiedź z modułu Poczta',
            'pdf_error' => 'Błąd generowania dokumentu PDF',
        ];
    }

    public static function event_label(string $event): string {
        $labels = self::event_labels();
        if (isset($labels[$event])) return $labels[$event];
        if (str_starts_with($event, 'crm_')) {
            $name = trim(str_replace(['crm_', '_'], ['', ' '], $event));
            return 'Działanie CRM: ' . ucfirst($name);
        }
        return ucfirst(str_replace('_', ' ', $event));
    }

    public static function event_categories(): array {
        return [
            'email' => ['label' => 'E-mail', 'icon' => 'email-alt'],
            'sms' => ['label' => 'SMS', 'icon' => 'smartphone'],
            'correspondence' => ['label' => 'Korespondencja', 'icon' => 'format-chat'],
            'invoice' => ['label' => 'Faktura', 'icon' => 'media-spreadsheet'],
            'agreement' => ['label' => 'Umowa', 'icon' => 'media-document'],
            'registration_form' => ['label' => 'Formularz zgłoszeniowy', 'icon' => 'feedback'],
            'payment' => ['label' => 'Płatność', 'icon' => 'money-alt'],
            'warning' => ['label' => 'Ostrzeżenie', 'icon' => 'warning'],
            'system_error' => ['label' => 'Błąd systemowy', 'icon' => 'dismiss'],
            'automatic_task' => ['label' => 'Zadanie automatyczne', 'icon' => 'update'],
        ];
    }

    public static function event_category(string $event, array $data = []): string {
        $event = strtolower($event);
        if (str_contains($event, 'error') || str_contains($event, 'failed') || $event === 'pdf_error') return 'system_error';
        if (str_contains($event, 'warning') || str_contains($event, 'blocked') || str_contains($event, 'invalid')) return 'warning';
        if (str_contains($event, 'invoice')) return 'invoice';
        if (str_contains($event, 'payment') || str_contains($event, 'stripe') || str_contains($event, 'bank_')) return 'payment';
        if (str_contains($event, 'agreement')) return 'agreement';
        if (str_contains($event, 'form') || str_contains($event, 'registration')) return 'registration_form';
        if (str_contains($event, 'sms') || str_contains($event, 'otp')) return 'sms';
        if (str_contains($event, 'communication') || str_contains($event, 'mailbox') || str_contains($event, 'message')) return 'correspondence';
        if (str_contains($event, 'email') || str_contains($event, 'mail')) return 'email';
        if (self::infer_actor_type($event, $data) === 'system') return 'automatic_task';
        return 'automatic_task';
    }

    public static function event_category_meta(string $event, array $data = []): array {
        $key = self::event_category($event, $data);
        $all = self::event_categories();
        return ['key' => $key] + ($all[$key] ?? $all['automatic_task']);
    }

    public static function detect_actor_type(string $event = ''): string {
        if (wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || str_contains($event, 'stripe_payment_confirmed')) return 'system';
        if (is_admin() && is_user_logged_in() && current_user_can('manage_options')) return 'administrator';
        if (str_starts_with($event, 'parent_') || in_array($event, ['agreement_accepted','otp_invalid','invoice_downloaded_by_parent'], true)) return 'parent';
        if (is_user_logged_in() && current_user_can('manage_options')) return 'administrator';
        return 'system';
    }

    public static function actor_label(string $actor): string {
        return match ($actor) {
            'administrator' => 'Administrator',
            'parent' => 'Rodzic',
            default => 'System',
        };
    }

    public static function infer_actor_type(string $event, array $data = []): string {
        if (!empty($data['_actor_type'])) return sanitize_key((string)$data['_actor_type']);
        if (str_starts_with($event, 'parent_') || in_array($event, ['agreement_accepted','otp_invalid','invoice_downloaded_by_parent'], true)) return 'parent';
        if (str_contains($event, '_by_admin') || str_contains($event, '_manually') || in_array($event, ['registration_admin_confirmed','registration_edited_by_admin','camp_form_verified','agreement_sent_by_admin','agreement_signature_reminder_sent','bank_payment_marked_paid','payment_reminder_sent','registration_cancelled','invoice_deleted'], true)) return 'administrator';
        return 'system';
    }

    public static function log(string $event, array $data = [], ?int $registration_id = null, ?int $agreement_id = null): void {
        global $wpdb;
        if (empty($data['_actor_type'])) $data['_actor_type'] = self::detect_actor_type($event);
        // Każda ręczna czynność wykonana w zapleczu jest przypisana do zalogowanego użytkownika WordPress.
        if (is_admin() && get_current_user_id() && $data['_actor_type'] !== 'parent') $data['_actor_type'] = 'administrator';
        if ($data['_actor_type'] === 'administrator') {
            $user_id = get_current_user_id();
            if ($user_id) {
                $user = get_userdata($user_id);
                $data['_actor_user_id'] = $user_id;
                $data['_actor_display_name'] = $user ? $user->display_name : ('Użytkownik #'.$user_id);
                $data['_actor_login'] = $user ? $user->user_login : '';
            }
        }
        $wpdb->insert(BCS_DB::table('logs'), [
            'registration_id' => $registration_id,
            'agreement_id' => $agreement_id,
            'event_type' => $event,
            'event_data' => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
            'ip' => self::client_ip(),
            'created_at' => self::now(),
        ]);
    }

    public static function registration_address(object $registration): string {
        $parts = [];
        $street = trim((string)($registration->parent_street ?? ''));
        $number = trim((string)($registration->parent_house_number ?? ''));
        if ($street !== '' || $number !== '') $parts[] = trim($street . ' ' . $number);
        $postal = trim((string)($registration->parent_postal_code ?? ''));
        $city = trim((string)($registration->parent_city ?? ''));
        if ($postal !== '' || $city !== '') $parts[] = trim($postal . ' ' . $city);
        if ($parts) return implode("
", $parts);
        return trim((string)($registration->parent_address ?? ''));
    }

    public static function compose_address(array $data): string {
        $street = trim((string)($data['parent_street'] ?? ''));
        $number = trim((string)($data['parent_house_number'] ?? ''));
        $postal = trim((string)($data['parent_postal_code'] ?? ''));
        $city = trim((string)($data['parent_city'] ?? ''));
        $parts = [];
        if ($street !== '' || $number !== '') $parts[] = trim($street . ' ' . $number);
        if ($postal !== '' || $city !== '') $parts[] = trim($postal . ' ' . $city);
        return implode("
", $parts);
    }

    public static function random_token(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }
    public static function format_bank_account(string $account): string {
        $clean = preg_replace('/\s+/', '', $account);
        return trim(chunk_split($clean, 4, ' '));
    }

}
