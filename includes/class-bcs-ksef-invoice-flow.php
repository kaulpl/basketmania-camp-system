<?php
if (!defined('ABSPATH')) exit;

/**
 * Operacyjny przepływ przycisku „Generuj fakturę”.
 * Tworzy dokument lokalny, generuje FA(3), wysyła do aktywnego środowiska KSeF,
 * a w PRODUKCJI przekazuje PDF rodzicowi dopiero po przyjęciu przez KSeF.
 */
final class BCS_KSeF_Invoice_Flow {
    private static array $lastResult = [];

    public static function init(): void {
        add_action('bcs_ksef_finalize_invoice_076', [__CLASS__, 'finalize'], 10, 1);
    }

    public static function last_result(): array { return self::$lastResult; }

    private static function invoice(int $registrationId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT i.*, o.ksef_enabled, o.ksef_environment, o.ksef_context_nip, '
            .'o.ksef_token_ciphertext, o.ksef_token_nonce, o.ksef_production_token_ciphertext, o.ksef_production_token_nonce '
            .'FROM '.BCS_DB::table('invoices').' i '
            .'JOIN '.BCS_DB::table('organizers').' o ON o.id=i.organizer_id '
            .'WHERE i.registration_id=%d ORDER BY i.id DESC LIMIT 1',
            $registrationId
        )) ?: null;
    }

    private static function registration(int $registrationId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT r.*, c.name camp_name, c.organizer_id, o.name organizer_name '
            .'FROM '.BCS_DB::table('registrations').' r '
            .'JOIN '.BCS_DB::table('camps').' c ON c.id=r.camp_id '
            .'JOIN '.BCS_DB::table('organizers').' o ON o.id=c.organizer_id WHERE r.id=%d',
            $registrationId
        )) ?: null;
    }

    private static function set_result(bool $success, string $message, string $status = '', array $extra = []): bool {
        self::$lastResult = ['success'=>$success, 'message'=>$message, 'status'=>$status] + $extra;
        return $success;
    }

    public static function generate_and_submit(int $registrationId): bool {
        self::$lastResult = [];
        $registration = self::registration($registrationId);
        if (!$registration) return self::set_result(false, 'Nie znaleziono zgłoszenia.');
        if ((string)$registration->status === 'cancelled') return self::set_result(false, 'Anulowane zgłoszenie nie może otrzymać faktury.');

        $existing = self::invoice($registrationId);
        if (!$existing) {
            $path = BCS_Invoices::ensure_invoice($registrationId);
            if ($path === '' || !is_file($path)) return self::set_result(false, 'Nie udało się utworzyć dokumentu faktury.');
            $existing = self::invoice($registrationId);
        }
        if (!$existing) return self::set_result(false, 'Nie udało się odczytać utworzonej faktury.');

        $environment = BCS_KSeF_Config::allowed_environment((string)$existing->ksef_environment);
        if ((int)$existing->ksef_enabled !== 1) {
            return self::set_result(false, 'KSeF nie jest włączony dla Organizatora. Włącz integrację w module Organizatorzy.');
        }
        if (!BCS_KSeF_Secret::configured($existing, $environment)) {
            return self::set_result(false, 'Brak zapisanego tokenu KSeF dla środowiska '.BCS_KSeF_Config::label($environment).'.');
        }

        global $wpdb;
        $wpdb->update(BCS_DB::table('invoices'), ['ksef_environment_used'=>$environment], ['id'=>(int)$existing->id]);
        $prepared = BCS_KSeF_FA3::prepare_and_save((int)$existing->id);
        if (empty($prepared['success'])) {
            $message = (string)($prepared['message'] ?? 'Nie udało się przygotować faktury FA(3).');
            if (!empty($prepared['errors'])) $message .= ' '.implode(' ', array_map('strval', (array)$prepared['errors']));
            return self::set_result(false, $message, 'rejected', ['invoice_id'=>(int)$existing->id]);
        }

        $sent = BCS_KSeF_Service::send((int)$existing->id);
        if (empty($sent['success'])) {
            return self::set_result(false, (string)($sent['message'] ?? 'Nie udało się wysłać faktury do KSeF.'), (string)($sent['status'] ?? 'connection_error'), ['invoice_id'=>(int)$existing->id, 'environment'=>$environment]);
        }

        $status = (string)($sent['status'] ?? 'processing');
        if ($status === 'accepted') {
            return self::after_acceptance((int)$existing->id, $registrationId, $environment, (string)($sent['message'] ?? 'Faktura została przyjęta przez KSeF.'));
        }

        self::schedule_finalize((int)$existing->id);
        $wpdb->update(BCS_DB::table('registrations'), ['invoice_status'=>'generated', 'updated_at'=>BCS_Utils::now()], ['id'=>$registrationId]);
        return self::set_result(true,
            'Faktura została wygenerowana i przesłana do KSeF '.BCS_KSeF_Config::label($environment).'. KSeF nadal ją przetwarza; status zostanie sprawdzony automatycznie.',
            'processing',
            ['invoice_id'=>(int)$existing->id, 'environment'=>$environment]
        );
    }

    private static function schedule_finalize(int $invoiceId): void {
        if (!wp_next_scheduled('bcs_ksef_finalize_invoice_076', [$invoiceId])) {
            wp_schedule_single_event(time() + 30, 'bcs_ksef_finalize_invoice_076', [$invoiceId]);
        }
    }

    public static function finalize(int $invoiceId): void {
        global $wpdb;
        $invoice = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.BCS_DB::table('invoices').' WHERE id=%d', $invoiceId));
        if (!$invoice) return;
        if ((string)$invoice->ksef_status === 'accepted') {
            self::after_acceptance($invoiceId, (int)$invoice->registration_id, BCS_KSeF_Config::allowed_environment((string)($invoice->ksef_environment_used ?: 'test')), 'Faktura została przyjęta przez KSeF.');
            return;
        }
        if (!in_array((string)$invoice->ksef_status, ['processing','sending'], true)) return;
        $result = BCS_KSeF_Service::refresh_status($invoiceId);
        if (empty($result['success'])) return;
        if ((string)($result['status'] ?? '') === 'accepted') {
            self::after_acceptance($invoiceId, (int)$invoice->registration_id, BCS_KSeF_Config::allowed_environment((string)($result['environment'] ?? $invoice->ksef_environment_used ?? 'test')), (string)$result['message']);
            return;
        }
        self::schedule_finalize($invoiceId);
    }

    private static function after_acceptance(int $invoiceId, int $registrationId, string $environment, string $message): bool {
        global $wpdb;
        $invoice = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.BCS_DB::table('invoices').' WHERE id=%d', $invoiceId));
        if (!$invoice) return self::set_result(false, 'Nie znaleziono faktury po przyjęciu przez KSeF.');

        if ($environment === 'production') {
            if (empty($invoice->ksef_delivery_completed_at)) {
                $delivery = self::deliver($invoice, $registrationId);
                if (!$delivery['success']) {
                    return self::set_result(false, 'KSeF przyjął fakturę, ale nie udało się przekazać jej rodzicowi: '.$delivery['message'], 'accepted', ['invoice_id'=>$invoiceId,'environment'=>$environment,'ksef_number'=>(string)$invoice->ksef_number]);
                }
                $wpdb->update(BCS_DB::table('invoices'), ['ksef_delivery_completed_at'=>BCS_Utils::now()], ['id'=>$invoiceId]);
            }
            return self::set_result(true, $message.' PDF został przekazany rodzicowi.', 'accepted', ['invoice_id'=>$invoiceId,'environment'=>$environment,'ksef_number'=>(string)$invoice->ksef_number]);
        }

        // Środowisko TEST służy do weryfikacji integracji i nie wysyła dokumentu testowego rodzicowi.
        $wpdb->update(BCS_DB::table('registrations'), ['invoice_status'=>'generated', 'updated_at'=>BCS_Utils::now()], ['id'=>$registrationId]);
        return self::set_result(true, $message.' To środowisko TEST – dokument nie został automatycznie wysłany rodzicowi.', 'accepted', ['invoice_id'=>$invoiceId,'environment'=>$environment,'ksef_number'=>(string)$invoice->ksef_number]);
    }

    private static function deliver(object $invoice, int $registrationId): array {
        global $wpdb;
        $registration = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.BCS_DB::table('registrations').' WHERE id=%d', $registrationId));
        if (!$registration) return ['success'=>false,'message'=>'Nie znaleziono zgłoszenia.'];
        $path = (string)$invoice->file_path;
        if ($path === '' || !is_file($path)) return ['success'=>false,'message'=>'Brak pliku PDF faktury.'];

        $ctx = BCS_Communication_Engine::registration_context($registrationId);
        $vars = $ctx['vars'] ?? [];
        $vars['{{INVOICE_NUMBER}}'] = (string)$invoice->invoice_number;
        $vars['{{INVOICE_AMOUNT}}'] = number_format((float)$invoice->gross_amount,2,',',' ');
        $vars['{{INVOICE_URL}}'] = BCS_Document_Engine::download_url($registrationId,'invoice');
        $vars['{{KSEF_NUMBER}}'] = (string)($invoice->ksef_number ?? '');
        $tpl = BCS_Communication_Engine::templates()['invoice_issued'] ?? [];
        $subject = BCS_Template_Engine::render((string)($tpl['subject'] ?? 'Faktura {{INVOICE_NUMBER}}'), $vars);
        $body = BCS_Template_Engine::render((string)($tpl['body'] ?? 'W załączeniu przesyłamy fakturę.'), $vars);
        $sms = BCS_SMS::strip_links(BCS_SMS::to_ascii(BCS_Template_Engine::render((string)($tpl['sms'] ?? 'Zostala wystawiona faktura. Prosze sprawdzic skrzynke pocztowa.'), $vars)));
        $emailOk = BCS_Mailer::send((string)$registration->parent_email, $subject, $body, [], [$path], $registrationId);
        $smsResult = BCS_SMS::send((string)$registration->parent_phone, $sms);
        $smsOk = !empty($smsResult['success']);
        $now = BCS_Utils::now();
        $wpdb->update(BCS_DB::table('invoices'), [
            'status'=>$emailOk?'sent':'generated',
            'sent_at'=>$emailOk?$now:null,
            'email_status'=>$emailOk?'sent':'failed',
            'sms_status'=>$smsOk?'sent':'failed',
        ], ['id'=>(int)$invoice->id]);
        $wpdb->update(BCS_DB::table('registrations'), [
            'invoice_status'=>$emailOk?'sent':'generated',
            'invoice_sent_at'=>$emailOk?$now:null,
            'invoice_requested'=>0,
            'updated_at'=>$now,
        ], ['id'=>$registrationId]);
        BCS_Utils::log('invoice_delivery_after_ksef', [
            'invoice_id'=>(int)$invoice->id,
            'invoice_number'=>(string)$invoice->invoice_number,
            'ksef_number'=>(string)($invoice->ksef_number ?? ''),
            'email_success'=>$emailOk,
            'sms_success'=>$smsOk,
        ], $registrationId, null);
        return ['success'=>$emailOk, 'message'=>$emailOk ? 'Wysłano e-mail z fakturą.' : BCS_Mailer::last_error()];
    }
}
