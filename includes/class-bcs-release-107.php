<?php
if (!defined('ABSPATH')) exit;

/**
 * 1.07 – centralny audyt działań wszystkich modułów i w pełni polski widok logów.
 *
 * Założenia:
 * - istniejące, szczegółowe logi modułów pozostają źródłem informacji o wyniku,
 * - dodatkowa warstwa audytowa rejestruje każdą obsłużoną akcję Basketmania,
 * - nie kopiujemy do audytu treści formularzy, haseł, tokenów ani danych medycznych,
 * - Logi i Historia klienta nie pokazują technicznych angielskich identyfikatorów.
 */
final class BCS_Release_107 {
    private static ?array $pendingAdminAction = null;
    private static bool $adminShutdownRegistered = false;
    private static int $mailboxBeforeId = 0;
    private static ?array $mailingQueueBefore = null;
    private static ?array $publicMarketingAudit = null;
    private static bool $publicShutdownRegistered = false;

    public static function init(): void {
        add_action('admin_init', [__CLASS__, 'capture_admin_action'], 1);
        add_action('admin_menu', [__CLASS__, 'replace_logs_page'], 999);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets'], 50);

        // Poczta – cron oraz ręczna synchronizacja (ręczna jest domykana w shutdown).
        add_action('bcs_mailbox_sync_event', [__CLASS__, 'mailbox_sync_before'], 1);
        add_action('bcs_mailbox_sync_event', [__CLASS__, 'mailbox_sync_after'], 99);

        // Mailing – rejestrujemy tylko przebiegi, w których faktycznie zmieniły się liczniki wysyłki.
        add_action('bcs_marketing_queue_097', [__CLASS__, 'mailing_queue_before'], 1);
        add_action('bcs_marketing_queue_097', [__CLASS__, 'mailing_queue_after'], 99);
        add_action('bcs_marketing_queue_pump_098', [__CLASS__, 'mailing_queue_before'], 1);
        add_action('bcs_marketing_queue_pump_098', [__CLASS__, 'mailing_queue_after'], 99);

        // Publiczne akcje marketingowe nie przechodzą przez audyt administratora.
        foreach (['admin_post_bcs_marketing_unsubscribe_096', 'admin_post_nopriv_bcs_marketing_unsubscribe_096'] as $hook) {
            add_action($hook, [__CLASS__, 'prepare_unsubscribe_audit'], 1);
        }
        foreach (['admin_post_bcs_marketing_click_098', 'admin_post_nopriv_bcs_marketing_click_098'] as $hook) {
            add_action($hook, [__CLASS__, 'prepare_click_audit'], 1);
        }
    }

    public static function event_labels(): array {
        $base = class_exists('BCS_Utils') ? BCS_Utils::event_labels() : [];
        return $base + [
            'registration_price_changed' => 'Zmieniono indywidualną cenę zgłoszenia',
            'agreement_template_opened' => 'Rodzic otworzył wzór umowy',
            'agreement_opened_for_signature' => 'Rodzic otworzył umowę do podpisu',
            'agreement_withdrawn_before_signature' => 'Wycofano umowę przed podpisaniem',
            'stripe_link_email_failed' => 'Nie udało się wysłać linku do płatności Stripe',
            'document_downloaded' => 'Pobrano dokument',
            'document_download_denied' => 'Odrzucono próbę pobrania dokumentu',
            'crm_phone' => 'Wykonano telefon',
            'crm_note' => 'Dodano notatkę',
            'crm_task' => 'Dodano zadanie',
            'crm_email' => 'Wysłano wiadomość e-mail',
            'crm_invoice_sent' => 'Wysłano fakturę',
            'mailbox_sync_completed' => 'Zakończono synchronizację poczty',
            'mailbox_message_received' => 'Odebrano wiadomość e-mail',
            'mailbox_message_assigned' => 'Przypisano wiadomość do zgłoszenia',
            'mailbox_message_read' => 'Oznaczono wiadomość jako przeczytaną',
            'mailing_queue_processed' => 'Przetworzono kolejkę mailingu',
            'marketing_unsubscribed' => 'Odbiorca wypisał się z mailingu',
            'mailing_link_clicked' => 'Odbiorca kliknął link w mailingu',
            'marketing_consent_granted' => 'Wyrażono zgodę na mailing',
            'marketing_consent_withdrawn' => 'Cofnięto zgodę na mailing',
        ];
    }

    public static function audit_action_labels(): array {
        return [
            'save_settings' => 'Zapisano ustawienia systemu',
            'test_email' => 'Wysłano testową wiadomość e-mail',
            'test_sms' => 'Wysłano testową wiadomość SMS',
            'reset_test_data' => 'Zresetowano dane testowe',
            'save_organizer' => 'Zapisano dane organizatora',
            'delete_organizer' => 'Usunięto organizatora',
            'save_camp' => 'Zapisano turnus',
            'delete_camp' => 'Usunięto turnus',
            'save_registration' => 'Zapisano zmiany w zgłoszeniu',
            'delete_registration' => 'Trwale usunięto zgłoszenie',
            'crm_phone' => 'Zapisano wykonanie telefonu',
            'crm_note' => 'Dodano notatkę w CRM',
            'crm_task' => 'Dodano zadanie w CRM',
            'crm_portal_send' => 'Wysłano dostęp do Panelu Rodzica',
            'crm_verify_form' => 'Zweryfikowano formularz obozowy',
            'crm_mark_paid' => 'Zaksięgowano wpłatę',
            'crm_invoice_generate' => 'Uruchomiono generowanie faktury',
            'crm_invoice_send' => 'Wysłano fakturę',
            'crm_cancel_registration' => 'Anulowano zgłoszenie',
            'crm_save_agreement_draft' => 'Zapisano zmiany w drafcie umowy',
            'crm_email' => 'Wysłano wiadomość e-mail z CRM',
            'workflow_confirm_registration' => 'Potwierdzono rejestrację',
            'workflow_verify_form' => 'Zweryfikowano formularz obozowy',
            'workflow_send_agreement' => 'Uruchomiono wysyłkę umowy',
            'workflow_send_stripe_link' => 'Uruchomiono wysyłkę linku Stripe',
            'workflow_mark_bank_paid' => 'Zaksięgowano przelew bankowy',
            'workflow_remind_payment' => 'Uruchomiono przypomnienie o płatności',
            'workflow_generate_invoice' => 'Uruchomiono generowanie faktury',
            'mail_sync' => 'Uruchomiono synchronizację poczty',
            'mail_assign' => 'Przypisano wiadomość do zgłoszenia',
            'mail_reply' => 'Wysłano odpowiedź z modułu Poczta',
            'mail_create_registration' => 'Utworzono zgłoszenie z wiadomości e-mail',
            'mail_read' => 'Oznaczono wiadomość jako przeczytaną',
            'marketing_import' => 'Zaimportowano kontakty do mailingu',
            'marketing_campaign_save' => 'Zapisano kampanię mailingową',
            'marketing_campaign_launch' => 'Uruchomiono kampanię mailingową',
            'marketing_campaign_test' => 'Wysłano test kampanii mailingowej',
            'marketing_campaign_pause' => 'Wstrzymano kampanię mailingową',
            'marketing_campaign_resume' => 'Wznowiono kampanię mailingową',
            'marketing_settings_save' => 'Zapisano ustawienia mailingu',
            'agreement_view' => 'Otworzono podgląd umowy',
            'agreement_version_preview_054' => 'Otworzono podgląd wersji umowy',
            'camp_bracket_pdf_094' => 'Wygenerowano drabinkę turniejową',
            'camp_report' => 'Wygenerowano raport turnusu',
            'invoice_download' => 'Pobrano fakturę',
            'invoice_generate' => 'Wygenerowano fakturę',
            'ksef_send' => 'Wysłano fakturę do KSeF',
            'ksef_status' => 'Sprawdzono status dokumentu w KSeF',
            'ksef_download' => 'Pobrano dokument z KSeF',
            'template_save' => 'Zapisano szablon',
            'feedback_save' => 'Zapisano zgłoszenie Feedback',
        ];
    }

    public static function event_title(string $event): string {
        $known = self::event_labels();
        if (isset($known[$event])) return $known[$event];
        if (str_starts_with($event, 'audit_')) return self::audit_action_label(substr($event, 6));
        if (str_starts_with($event, 'crm_')) return self::audit_action_label($event);
        return 'Zdarzenie systemowe';
    }

    public static function audit_action_label(string $key): string {
        $key = self::normalize_action_key($key);
        $labels = self::audit_action_labels();
        if (isset($labels[$key])) return $labels[$key];
        $module = self::module_for_action($key);
        return match ($module) {
            'Mailing' => 'Wykonano działanie w module Mailing',
            'Poczta' => 'Wykonano działanie w module Poczta',
            'Faktury i KSeF' => 'Wykonano działanie w module Faktury i KSeF',
            'Umowy' => 'Wykonano działanie w module Umowy',
            'Płatności' => 'Wykonano działanie w module Płatności',
            'CRM – Zgłoszenia' => 'Wykonano działanie w obsłudze zgłoszeń',
            'Turnusy i raporty' => 'Wykonano działanie dotyczące turnusu lub raportu',
            'Organizatorzy' => 'Wykonano działanie dotyczące organizatora',
            'Szablony' => 'Wykonano działanie w module Szablony',
            'Dokumenty' => 'Wykonano działanie dotyczące dokumentu',
            default => 'Wykonano działanie w systemie',
        };
    }

    public static function normalize_action_key(string $key): string {
        $key = strtolower(trim($key));
        $key = preg_replace('/^bcs_/', '', $key);
        $key = preg_replace('/_\d{3}$/', '', (string)$key);
        $key = preg_replace('/[^a-z0-9_]+/', '_', (string)$key);
        return trim((string)$key, '_');
    }

    public static function module_for_action(string $key): string {
        $key = self::normalize_action_key($key);
        if (str_contains($key, 'marketing') || str_contains($key, 'mailing') || str_contains($key, 'campaign')) return 'Mailing';
        if (str_contains($key, 'mail')) return 'Poczta';
        if (str_contains($key, 'invoice') || str_contains($key, 'ksef')) return 'Faktury i KSeF';
        if (str_contains($key, 'agreement') || str_contains($key, 'otp')) return 'Umowy';
        if (str_contains($key, 'payment') || str_contains($key, 'stripe') || str_contains($key, 'paid')) return 'Płatności';
        if (str_contains($key, 'registration') || str_contains($key, 'crm') || str_contains($key, 'workflow') || str_contains($key, 'signup') || str_contains($key, 'form')) return 'CRM – Zgłoszenia';
        if (str_contains($key, 'camp') || str_contains($key, 'bracket') || str_contains($key, 'report')) return 'Turnusy i raporty';
        if (str_contains($key, 'organizer')) return 'Organizatorzy';
        if (str_contains($key, 'template')) return 'Szablony';
        if (str_contains($key, 'document') || str_contains($key, 'pdf')) return 'Dokumenty';
        if (str_contains($key, 'feedback')) return 'Feedback';
        if (str_contains($key, 'setting') || str_contains($key, 'test_') || str_contains($key, 'reset')) return 'Ustawienia i system';
        return 'System';
    }

    /**
     * Czysta funkcja używana również przez test regresyjny.
     */
    public static function detect_admin_action(array $request): ?array {
        $explicit = isset($request['action']) ? (string)$request['action'] : '';
        if ($explicit !== '' && str_starts_with($explicit, 'bcs_')) {
            $key = self::normalize_action_key($explicit);
            if ($key === 'workflow_single' && !empty($request['workflow'])) {
                $key = 'workflow_'.self::normalize_action_key((string)$request['workflow']);
            }
            return ['action_key'=>$key, 'module'=>self::module_for_action($key), 'label'=>self::audit_action_label($key)];
        }

        foreach (['bcs_crm_action'=>'crm_', 'bcs_workflow_action'=>'workflow_', 'quick_action'=>'quick_', 'card_action'=>'card_'] as $field=>$prefix) {
            if (!empty($request[$field])) {
                $key = $prefix.self::normalize_action_key((string)$request[$field]);
                return ['action_key'=>$key, 'module'=>self::module_for_action($key), 'label'=>self::audit_action_label($key)];
            }
        }

        if (!empty($request['bcs_mail_read'])) {
            return ['action_key'=>'mail_read', 'module'=>'Poczta', 'label'=>self::audit_action_label('mail_read')];
        }

        $preferred = [
            'bcs_save_settings','bcs_test_email','bcs_test_sms','bcs_reset_test_data',
            'bcs_save_organizer','bcs_delete_organizer','bcs_save_camp','bcs_delete_camp',
            'bcs_save_registration','bcs_delete_registration','bcs_mail_sync','bcs_mail_assign',
            'bcs_mail_reply','bcs_mail_create_registration','bcs_save_template','bcs_save_templates',
        ];
        foreach ($preferred as $field) {
            if (!empty($request[$field])) {
                $key = self::normalize_action_key($field);
                return ['action_key'=>$key, 'module'=>self::module_for_action($key), 'label'=>self::audit_action_label($key)];
            }
        }

        foreach ($request as $field=>$value) {
            $field = (string)$field;
            if (!str_starts_with($field, 'bcs_') || empty($value)) continue;
            if (!preg_match('/(?:save|delete|send|generate|sync|import|export|create|update|archive|restore|test|mark|verify|cancel|reply|assign|read|download|preview|sign|accept|launch|pause|resume|reset)/', $field)) continue;
            $key = self::normalize_action_key($field);
            return ['action_key'=>$key, 'module'=>self::module_for_action($key), 'label'=>self::audit_action_label($key)];
        }
        return null;
    }

    public static function capture_admin_action(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        $request = array_merge((array)$_GET, (array)$_POST);
        $action = self::detect_admin_action($request);
        if (!$action) return;

        $action['registration_id'] = absint($request['registration_id'] ?? $request['registration'] ?? 0);
        $action['agreement_id'] = absint($request['agreement_id'] ?? $request['agreement'] ?? 0);
        $action['invoice_id'] = absint($request['invoice_id'] ?? $request['invoice'] ?? 0);
        $action['campaign_id'] = absint($request['campaign_id'] ?? 0);
        $action['contact_id'] = absint($request['contact_id'] ?? 0);
        $action['message_id'] = absint($request['message_id'] ?? $request['bcs_mail_read'] ?? 0);
        $action['camp_id'] = absint($request['camp_id'] ?? 0);
        $action['organizer_id'] = absint($request['organizer_id'] ?? 0);
        $action['page'] = sanitize_key(wp_unslash($request['page'] ?? ''));
        $action['method'] = sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        self::$pendingAdminAction = $action;

        if ($action['action_key'] === 'mail_sync') self::$mailboxBeforeId = self::latest_inbound_id();
        if (!self::$adminShutdownRegistered) {
            self::$adminShutdownRegistered = true;
            add_action('shutdown', [__CLASS__, 'flush_admin_action'], 999);
        }
    }

    public static function flush_admin_action(): void {
        if (!self::$pendingAdminAction || !class_exists('BCS_DB') || !class_exists('BCS_Utils')) return;
        $a = self::$pendingAdminAction;
        self::$pendingAdminAction = null;

        $registrationId = (int)$a['registration_id'];
        if (!$registrationId && !empty($a['message_id'])) $registrationId = self::registration_for_message((int)$a['message_id']);
        if (!$registrationId && !empty($a['invoice_id'])) $registrationId = self::registration_for_invoice((int)$a['invoice_id']);

        $event = 'audit_'.substr(self::normalize_action_key((string)$a['action_key']), 0, 80);
        BCS_Utils::log($event, [
            'module'=>(string)$a['module'],
            'action_label'=>(string)$a['label'],
            'action_key'=>(string)$a['action_key'],
            'request_method'=>(string)$a['method'],
            'page'=>(string)$a['page'],
            'invoice_id'=>(int)$a['invoice_id'],
            'campaign_id'=>(int)$a['campaign_id'],
            'contact_id'=>(int)$a['contact_id'],
            'message_id'=>(int)$a['message_id'],
            'camp_id'=>(int)$a['camp_id'],
            'organizer_id'=>(int)$a['organizer_id'],
            'audit_status'=>'handled',
            '_actor_type'=>'administrator',
        ], $registrationId ?: null, (int)$a['agreement_id'] ?: null);

        if ((string)$a['action_key'] === 'mail_sync') {
            $count = self::log_new_inbound_since(self::$mailboxBeforeId, 'manual');
            $last = get_option('bcs_last_imap_result', []);
            BCS_Utils::log('mailbox_sync_completed', [
                'source'=>'manual',
                'new_messages'=>$count,
                'errors'=>(int)($last['errors'] ?? 0),
                '_actor_type'=>'administrator',
            ], null, null);
        } elseif ((string)$a['action_key'] === 'mail_assign' && !empty($a['message_id'])) {
            BCS_Utils::log('mailbox_message_assigned', ['message_id'=>(int)$a['message_id']], $registrationId ?: null, null);
        } elseif ((string)$a['action_key'] === 'mail_read' && !empty($a['message_id'])) {
            BCS_Utils::log('mailbox_message_read', ['message_id'=>(int)$a['message_id']], $registrationId ?: null, null);
        }
    }

    private static function registration_for_message(int $messageId): int {
        if ($messageId <= 0) return 0;
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT registration_id FROM '.BCS_DB::table('mail_messages').' WHERE id=%d', $messageId));
    }

    private static function registration_for_invoice(int $invoiceId): int {
        if ($invoiceId <= 0) return 0;
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT registration_id FROM '.BCS_DB::table('invoices').' WHERE id=%d', $invoiceId));
    }

    private static function latest_inbound_id(): int {
        if (!class_exists('BCS_DB')) return 0;
        global $wpdb;
        return (int)$wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM ".BCS_DB::table('mail_messages')." WHERE direction='inbound'");
    }

    private static function log_new_inbound_since(int $beforeId, string $source): int {
        if (!class_exists('BCS_DB') || !class_exists('BCS_Utils')) return 0;
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,registration_id,sender_email,subject FROM ".BCS_DB::table('mail_messages')." WHERE direction='inbound' AND id>%d ORDER BY id ASC LIMIT 500",
            $beforeId
        ));
        foreach ((array)$rows as $row) {
            BCS_Utils::log('mailbox_message_received', [
                'message_id'=>(int)$row->id,
                'sender_email'=>(string)$row->sender_email,
                'subject'=>(string)$row->subject,
                'source'=>$source,
                '_actor_type'=>'system',
            ], !empty($row->registration_id) ? (int)$row->registration_id : null, null);
        }
        return count((array)$rows);
    }

    public static function mailbox_sync_before(): void {
        self::$mailboxBeforeId = self::latest_inbound_id();
    }

    public static function mailbox_sync_after(): void {
        $new = self::log_new_inbound_since(self::$mailboxBeforeId, 'automatic');
        $last = get_option('bcs_last_imap_result', []);
        $errors = (int)($last['errors'] ?? 0);
        if ($new > 0 || $errors > 0) {
            BCS_Utils::log('mailbox_sync_completed', [
                'source'=>'automatic',
                'new_messages'=>$new,
                'errors'=>$errors,
                '_actor_type'=>'system',
            ], null, null);
        }
    }

    private static function mailing_stats(): array {
        if (!class_exists('BCS_Release_097')) return ['queued'=>0,'sending'=>0,'sent'=>0,'failed'=>0];
        global $wpdb;
        $table = BCS_Release_097::recipients_table();
        $out = ['queued'=>0,'sending'=>0,'sent'=>0,'failed'=>0];
        $rows = $wpdb->get_results("SELECT status,COUNT(*) cnt FROM {$table} GROUP BY status");
        foreach ((array)$rows as $row) if (isset($out[$row->status])) $out[$row->status] = (int)$row->cnt;
        return $out;
    }

    public static function mailing_queue_before(): void {
        self::$mailingQueueBefore = self::mailing_stats();
    }

    public static function mailing_queue_after(): void {
        if (!self::$mailingQueueBefore || !class_exists('BCS_Utils')) return;
        $before = self::$mailingQueueBefore;
        self::$mailingQueueBefore = null;
        $after = self::mailing_stats();
        $sent = max(0, (int)$after['sent'] - (int)$before['sent']);
        $failed = max(0, (int)$after['failed'] - (int)$before['failed']);
        if ($sent === 0 && $failed === 0) return;
        BCS_Utils::log('mailing_queue_processed', [
            'sent_count'=>$sent,
            'failed_count'=>$failed,
            'queued_remaining'=>(int)$after['queued'],
            '_actor_type'=>'system',
        ], null, null);
    }

    private static function register_public_shutdown(): void {
        if (self::$publicShutdownRegistered) return;
        self::$publicShutdownRegistered = true;
        add_action('shutdown', [__CLASS__, 'flush_public_marketing_audit'], 999);
    }

    public static function prepare_unsubscribe_audit(): void {
        if (!class_exists('BCS_Release_096')) return;
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        if ($token === '') return;
        global $wpdb;
        $table = BCS_Release_096::contacts_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,last_registration_id,status,consent_status FROM {$table} WHERE unsubscribe_token=%s", $token));
        if (!$row) return;
        self::$publicMarketingAudit = [
            'type'=>'unsubscribe','id'=>(int)$row->id,'registration_id'=>(int)$row->last_registration_id,
            'status_before'=>(string)$row->status,'consent_before'=>(string)$row->consent_status,
        ];
        self::register_public_shutdown();
    }

    public static function prepare_click_audit(): void {
        if (!class_exists('BCS_Release_097') || !class_exists('BCS_Release_096')) return;
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        if ($token === '') return;
        global $wpdb;
        $recipients = BCS_Release_097::recipients_table();
        $contacts = BCS_Release_096::contacts_table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.id,r.campaign_id,r.contact_id,r.clicked_at,c.last_registration_id FROM {$recipients} r LEFT JOIN {$contacts} c ON c.id=r.contact_id WHERE r.click_token=%s",
            $token
        ));
        if (!$row) return;
        self::$publicMarketingAudit = [
            'type'=>'click','id'=>(int)$row->id,'campaign_id'=>(int)$row->campaign_id,'contact_id'=>(int)$row->contact_id,
            'registration_id'=>(int)$row->last_registration_id,'clicked_before'=>(string)$row->clicked_at,
        ];
        self::register_public_shutdown();
    }

    public static function flush_public_marketing_audit(): void {
        if (!self::$publicMarketingAudit || !class_exists('BCS_Utils')) return;
        $audit = self::$publicMarketingAudit;
        self::$publicMarketingAudit = null;
        global $wpdb;

        if ($audit['type'] === 'unsubscribe' && class_exists('BCS_Release_096')) {
            $row = $wpdb->get_row($wpdb->prepare('SELECT status,consent_status FROM '.BCS_Release_096::contacts_table().' WHERE id=%d', (int)$audit['id']));
            if ($row && ((string)$row->status !== (string)$audit['status_before'] || (string)$row->consent_status !== (string)$audit['consent_before'])) {
                BCS_Utils::log('marketing_unsubscribed', [
                    'contact_id'=>(int)$audit['id'],
                    'consent_status'=>(string)$row->consent_status,
                    '_actor_type'=>'parent',
                ], !empty($audit['registration_id']) ? (int)$audit['registration_id'] : null, null);
            }
        }

        if ($audit['type'] === 'click' && class_exists('BCS_Release_097')) {
            $clicked = (string)$wpdb->get_var($wpdb->prepare('SELECT clicked_at FROM '.BCS_Release_097::recipients_table().' WHERE id=%d', (int)$audit['id']));
            if ((string)$audit['clicked_before'] === '' && $clicked !== '') {
                BCS_Utils::log('mailing_link_clicked', [
                    'campaign_id'=>(int)$audit['campaign_id'],
                    'contact_id'=>(int)$audit['contact_id'],
                    'clicked_at'=>$clicked,
                    '_actor_type'=>'parent',
                ], !empty($audit['registration_id']) ? (int)$audit['registration_id'] : null, null);
            }
        }
    }

    public static function replace_logs_page(): void {
        if (!function_exists('get_plugin_page_hookname')) return;
        $hook = get_plugin_page_hookname('bcs-logs', 'bcs-dashboard');
        remove_action($hook, ['BCS_Admin', 'logs']);
        remove_submenu_page('bcs-dashboard', 'bcs-logs');
        add_submenu_page('bcs-dashboard', 'Logi', 'Logi', 'manage_options', 'bcs-logs', [__CLASS__, 'logs_page']);
    }

    public static function assets(string $hook): void {
        if (strpos($hook, 'bcs-') === false && sanitize_key(wp_unslash($_GET['page'] ?? '')) !== 'bcs-registrations') return;
        wp_enqueue_script('bcs-audit-polish-107', BCS_URL.'assets/js/audit-polish-107.js', [], BCS_VERSION, true);
        wp_localize_script('bcs-audit-polish-107', 'BCSAudit107', [
            'eventLabels'=>self::event_labels(),
            'actionLabels'=>self::audit_action_labels(),
            'unknownLabel'=>'Zdarzenie systemowe',
        ]);
    }

    private static function categories(): array {
        return [
            'registration_form'=>['label'=>'Zgłoszenia i formularze','icon'=>'feedback'],
            'agreement'=>['label'=>'Umowy','icon'=>'media-document'],
            'payment'=>['label'=>'Płatności','icon'=>'money-alt'],
            'invoice'=>['label'=>'Faktury i KSeF','icon'=>'media-spreadsheet'],
            'poczta'=>['label'=>'Poczta','icon'=>'email-alt'],
            'mailing'=>['label'=>'Mailing','icon'=>'megaphone'],
            'document'=>['label'=>'Dokumenty i raporty','icon'=>'media-text'],
            'administration'=>['label'=>'Administracja systemem','icon'=>'admin-tools'],
            'sms'=>['label'=>'SMS','icon'=>'smartphone'],
            'warning'=>['label'=>'Ostrzeżenia','icon'=>'warning'],
            'system_error'=>['label'=>'Błędy systemowe','icon'=>'dismiss'],
            'automatic_task'=>['label'=>'Automaty systemowe','icon'=>'update'],
        ];
    }

    private static function category_key(string $event, array $data): string {
        $e = strtolower($event);
        if (str_contains($e, 'error') || str_contains($e, 'failed') || $e === 'pdf_error') return 'system_error';
        if (str_contains($e, 'warning') || str_contains($e, 'blocked') || str_contains($e, 'invalid')) return 'warning';
        if (str_contains($e, 'mailing') || str_contains($e, 'marketing') || str_contains($e, 'campaign')) return 'mailing';
        if (str_contains($e, 'mailbox')) return 'poczta';
        if (str_contains($e, 'invoice') || str_contains($e, 'ksef')) return 'invoice';
        if (str_contains($e, 'payment') || str_contains($e, 'stripe') || str_contains($e, 'bank_')) return 'payment';
        if (str_contains($e, 'agreement')) return 'agreement';
        if (str_contains($e, 'document') || str_contains($e, 'pdf') || str_contains($e, 'bracket') || str_contains($e, 'report')) return 'document';
        if (str_contains($e, 'form') || str_contains($e, 'registration') || str_starts_with($e, 'crm_')) return 'registration_form';
        if (str_contains($e, 'sms') || str_contains($e, 'otp')) return 'sms';
        if (str_starts_with($e, 'audit_')) {
            $module = (string)($data['module'] ?? self::module_for_action(substr($e, 6)));
            return match ($module) {
                'Mailing'=>'mailing','Poczta'=>'poczta','Faktury i KSeF'=>'invoice','Umowy'=>'agreement','Płatności'=>'payment',
                'CRM – Zgłoszenia'=>'registration_form','Dokumenty','Turnusy i raporty'=>'document',default=>'administration',
            };
        }
        if (str_contains($e, 'email') || str_contains($e, 'communication')) return 'poczta';
        return 'automatic_task';
    }

    private static function category_meta(string $event, array $data): array {
        $key = self::category_key($event, $data);
        $all = self::categories();
        return ['key'=>$key] + ($all[$key] ?? $all['automatic_task']);
    }

    public static function logs_page(): void {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $category = sanitize_key(wp_unslash($_GET['category'] ?? ''));
        $registrationId = absint($_GET['registration_id'] ?? 0);
        $pageNum = max(1, absint($_GET['paged'] ?? 1));
        $perPage = 50;

        $where = '1=1';
        $args = [];
        if ($registrationId) { $where .= ' AND registration_id=%d'; $args[] = $registrationId; }
        $sql = 'SELECT * FROM '.BCS_DB::table('logs').' WHERE '.$where.' ORDER BY id DESC LIMIT 10000';
        $allRows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args)) : $wpdb->get_results($sql);
        $filtered = [];
        foreach ((array)$allRows as $row) {
            $data = json_decode((string)$row->event_data, true);
            if (!is_array($data)) $data = ['value'=>(string)$row->event_data];
            $meta = self::category_meta((string)$row->event_type, $data);
            if ($category === '' || $meta['key'] === $category) $filtered[] = $row;
        }
        $total = count($filtered);
        $rows = array_slice($filtered, ($pageNum-1)*$perPage, $perPage);

        echo '<div class="wrap bcs-admin"><div class="bcs-page-head"><div><h1>Logi systemowe</h1><p>Pełny dziennik działań modułów Basketmania Camp. Nazwy zdarzeń i szczegóły są prezentowane po polsku.</p></div><span class="bcs-count">'.number_format_i18n($total).' wpisów</span></div>';
        echo '<section class="bcs-panel"><form method="get" class="bcs-log-filters"><input type="hidden" name="page" value="bcs-logs"><label><span>Kategoria</span><select name="category"><option value="">Wszystkie kategorie</option>';
        foreach (self::categories() as $key=>$meta) echo '<option value="'.esc_attr($key).'" '.selected($category,$key,false).'>'.esc_html($meta['label']).'</option>';
        echo '</select></label><label><span>ID zgłoszenia</span><input type="number" min="1" name="registration_id" value="'.($registrationId ?: '').'"></label><button class="button button-primary">Filtruj</button><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-logs')).'">Wyczyść</a></form></section>';

        echo '<section class="bcs-panel"><div class="bcs-table-wrap"><table class="widefat striped"><thead><tr><th>Data</th><th>Zdarzenie</th><th>Wykonawca</th><th>Zgłoszenie</th><th>Szczegóły</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="5">Brak logów dla wybranych filtrów.</td></tr>';
        foreach ($rows as $row) {
            $data = json_decode((string)$row->event_data, true);
            if (!is_array($data)) $data = ['value'=>(string)$row->event_data;
            $actor = BCS_Utils::infer_actor_type((string)$row->event_type, $data);
            $actorLabel = BCS_Utils::actor_label($actor);
            if ($actor === 'administrator' && !empty($data['_actor_display_name'])) {
                $actorLabel = (string)$data['_actor_display_name'].(!empty($data['_actor_login']) ? ' ('.(string)$data['_actor_login'].')' : '');
            }
            $meta = self::category_meta((string)$row->event_type, $data);
            $details = self::details_text($data);
            $isError = $meta['key'] === 'system_error';
            echo '<tr'.($isError?' class="bcs-log-error"':'').'><td>'.esc_html(BCS_Utils::format_datetime($row->created_at)).'</td><td><div class="bcs-log-event"><span class="bcs-log-category-icon bcs-log-category-'.esc_attr($meta['key']).' dashicons dashicons-'.esc_attr($meta['icon']).'" title="'.esc_attr($meta['label']).'"></span><div><strong>'.esc_html(self::event_title((string)$row->event_type)).'</strong><br><small class="bcs-log-category-label">'.esc_html($meta['label']).'</small></div></div></td><td><span class="bcs-log-actor bcs-log-actor-'.esc_attr(sanitize_html_class($actor)).'">'.esc_html($actorLabel).'</span></td><td>'.($row->registration_id?'<a href="'.esc_url(admin_url('admin.php?page=bcs-registrations&view='.(int)$row->registration_id)).'">#'.(int)$row->registration_id.'</a>':'—').'</td><td><button type="button" class="button bcs-log-details" data-title="'.esc_attr(self::event_title((string)$row->event_type)).'" data-details="'.esc_attr($details).'" aria-label="Pokaż szczegóły"><span class="dashicons dashicons-visibility"></span><span>Szczegóły</span></button></td></tr>';
        }
        echo '</tbody></table></div></section>';
        $pages = (int)ceil($total/$perPage);
        if ($pages > 1) echo '<div class="tablenav"><div class="tablenav-pages">'.paginate_links(['base'=>add_query_arg('paged','%#%'),'format'=>'','current'=>$pageNum,'total'=>$pages]).'</div></div>';
        echo '<div id="bcs-log-modal" class="bcs-log-modal" hidden><div class="bcs-log-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="bcs-log-modal-title"><button type="button" class="bcs-log-modal__close" aria-label="Zamknij"><span class="dashicons dashicons-no-alt"></span></button><h2 id="bcs-log-modal-title">Szczegóły zdarzenia</h2><pre class="bcs-log-data"></pre></div></div></div>';
    }

    public static function detail_key_label(string $key): string {
        $map = [
            'module'=>'Moduł','action_label'=>'Akcja','action_key'=>'Rodzaj akcji','request_method'=>'Metoda żądania','page'=>'Ekran systemu',
            'audit_status'=>'Status audytu','source'=>'Źródło','status'=>'Status','success'=>'Powodzenie','message'=>'Komunikat','error'=>'Błąd','errors'=>'Liczba błędów','reason'=>'Powód','title'=>'Tytuł','subject'=>'Temat wiadomości',
            'registration_id'=>'ID zgłoszenia','agreement_id'=>'ID umowy','invoice_id'=>'ID faktury','payment_id'=>'ID płatności','campaign_id'=>'ID kampanii','contact_id'=>'ID kontaktu','message_id'=>'ID wiadomości','camp_id'=>'ID turnusu','organizer_id'=>'ID organizatora',
            'sender_email'=>'Adres e-mail nadawcy','recipient_email'=>'Adres e-mail odbiorcy','email'=>'Adres e-mail','phone'=>'Numer telefonu','channel'=>'Kanał komunikacji','provider'=>'Dostawca usługi',
            'amount_due'=>'Kwota do zapłaty','paid_amount'=>'Kwota wpłacona','total_amount'=>'Kwota całkowita','new_messages'=>'Nowe wiadomości','sent_count'=>'Wysłane wiadomości','failed_count'=>'Błędy wysyłki','queued_remaining'=>'Pozostało w kolejce',
            'communication_success'=>'Wynik komunikacji','email_sent'=>'Wysłano e-mail','draft_ready'=>'Draft gotowy','draft_pdf'=>'Plik draftu PDF','clicked_at'=>'Data kliknięcia','consent_status'=>'Status zgody',
            'document_hash'=>'Skrót dokumentu','file'=>'Plik','path'=>'Ścieżka','url'=>'Adres URL','created_at'=>'Data utworzenia','updated_at'=>'Data aktualizacji','sent_at'=>'Data wysłania','payment_date'=>'Data płatności',
        ];
        if (isset($map[$key])) return $map[$key];
        $tokens = [
            'id'=>'ID','count'=>'liczba','new'=>'nowe','sent'=>'wysłane','failed'=>'błędy','queued'=>'kolejka','remaining'=>'pozostało','date'=>'data','time'=>'czas',
            'created'=>'utworzenie','updated'=>'aktualizacja','message'=>'wiadomość','email'=>'e-mail','sms'=>'SMS','mail'=>'poczta','mailing'=>'mailing','campaign'=>'kampania','contact'=>'kontakt','recipient'=>'odbiorca',
            'invoice'=>'faktura','payment'=>'płatność','agreement'=>'umowa','registration'=>'zgłoszenie','camp'=>'turnus','organizer'=>'organizator','amount'=>'kwota','channel'=>'kanał','provider'=>'dostawca','external'=>'zewnętrzny',
            'status'=>'status','source'=>'źródło','result'=>'wynik','action'=>'akcja','module'=>'moduł','method'=>'metoda','page'=>'ekran','document'=>'dokument','file'=>'plik','path'=>'ścieżka','hash'=>'skrót','url'=>'adres',
            'subject'=>'temat','sender'=>'nadawca','recipient'=>'odbiorca','click'=>'kliknięcie','clicked'=>'kliknięto','consent'=>'zgoda','value'=>'wartość','automatic'=>'automatycznie','manual'=>'ręcznie','ready'=>'gotowe','draft'=>'draft',
        ];
        $parts = array_values(array_filter(explode('_', strtolower($key))));
        if (!$parts) return 'Informacja dodatkowa';
        $translated = [];
        foreach ($parts as $part) $translated[] = $tokens[$part] ?? 'informacja';
        $label = trim(implode(' ', $translated));
        return $label !== '' ? mb_strtoupper(mb_substr($label,0,1),'UTF-8').mb_substr($label,1) : 'Informacja dodatkowa';
    }

    private static function value_label($value): string {
        if (is_bool($value)) return $value ? 'Tak' : 'Nie';
        if ($value === null || $value === '') return '—';
        if (is_numeric($value)) return (string)$value;
        $string = trim((string)$value);
        $map = [
            'yes'=>'Tak','no'=>'Nie','true'=>'Tak','false'=>'Nie','active'=>'Aktywny','inactive'=>'Nieaktywny','unsubscribed'=>'Wypisany',
            'sent'=>'Wysłano','failed'=>'Błąd','queued'=>'W kolejce','sending'=>'Wysyłanie','paused'=>'Wstrzymana','completed'=>'Zakończona','draft'=>'Szkic','pending'=>'Oczekuje','accepted'=>'Zaakceptowana','cancelled'=>'Anulowana',
            'system'=>'System','administrator'=>'Administrator','parent'=>'Rodzic','manual'=>'Ręcznie','automatic'=>'Automatycznie','website_form'=>'Formularz internetowy','registration_form'=>'Formularz zgłoszeniowy','legacy_registration'=>'Starsze zgłoszenie',
            'email'=>'E-mail','sms'=>'SMS','email_sms'=>'E-mail i SMS','handled'=>'Obsłużono','get'=>'GET','post'=>'POST',
        ];
        return $map[strtolower($string)] ?? $string;
    }

    public static function details_text(array $data): string {
        $lines = self::flatten_details($data);
        return $lines ? implode("\n", $lines) : 'Brak dodatkowych szczegółów.';
    }

    private static function flatten_details(array $data, string $prefix = ''): array {
        $lines = [];
        foreach ($data as $key=>$value) {
            $key = (string)$key;
            if (str_starts_with($key, '_actor_')) continue;
            if (preg_match('/(?:password|secret|token|authorization|api_key)/i', $key)) continue;
            if ((int)$value === 0 && in_array($key, ['invoice_id','campaign_id','contact_id','message_id','camp_id','organizer_id','agreement_id','registration_id'], true)) continue;
            $label = self::detail_key_label($key);
            $fullLabel = $prefix !== '' ? $prefix.' – '.$label : $label;
            if (is_array($value)) {
                if (array_is_list($value) && !array_filter($value, 'is_array')) {
                    $lines[] = $fullLabel.': '.implode(', ', array_map([__CLASS__, 'value_label'], $value));
                } else {
                    $lines = array_merge($lines, self::flatten_details($value, $fullLabel));
                }
                continue;
            }
            if (is_object($value)) {
                $lines = array_merge($lines, self::flatten_details((array)$value, $fullLabel));
                continue;
            }
            $lines[] = $fullLabel.': '.self::value_label($value);
        }
        return $lines;
    }
}
