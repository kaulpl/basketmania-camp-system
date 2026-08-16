<?php
if (!defined('ABSPATH')) exit;

/**
 * Wersja 0.78 – uszczelnienie sekcji dowodowej podpisu Organizatora.
 *
 * - identyfikator SMS jest wiązany z dokładnie tym kodem OTP, który został zweryfikowany,
 * - data podpisu powstaje dopiero po poprawnej weryfikacji kodu w Europe/Warsaw,
 * - dowód jest cofany, jeżeli zapis stanu lub budowa finalnego dokumentu się nie powiedzie,
 * - stare, osierocone dowody Organizatora są usuwane dla etapu parent_signed,
 * - zachowano zgodność z historycznym kluczem sms_id.
 */
final class BCS_Release_078 {
    private const OTP_TTL = 600;

    public static function init(): void {
        // 0.51 zastąpiło weryfikację z 0.46, ale wysyłka nadal pochodziła z 0.46.
        // Od 0.78 oba końce procesu obsługuje jedna implementacja, aby dowód był spójny.
        remove_action('wp_ajax_bcs_046_organizer_otp_send', ['BCS_Release_046', 'ajax_send_organizer_otp']);
        remove_action('wp_ajax_bcs_046_organizer_otp_verify', ['BCS_Release_046', 'ajax_verify_organizer_otp']);
        remove_action('wp_ajax_bcs_046_organizer_otp_verify', ['BCS_Release_051', 'ajax_verify_organizer_otp']);

        add_action('wp_ajax_bcs_046_organizer_otp_send', [__CLASS__, 'ajax_send_organizer_otp']);
        add_action('wp_ajax_bcs_046_organizer_otp_verify', [__CLASS__, 'ajax_verify_organizer_otp']);
        add_action('admin_init', [__CLASS__, 'cleanup_stale_proofs'], 8);
    }

    private static function request_key(int $registration_id): string {
        return 'bcs_046_org_otp_' . get_current_user_id() . '_' . $registration_id;
    }

    private static function proof_key(int $agreement_id): string {
        return 'bcs_org_proof_' . $agreement_id;
    }

    /** @return array{exists:bool,value:mixed} */
    private static function proof_snapshot(string $key): array {
        $sentinel = '__bcs_078_missing_'.wp_generate_uuid4();
        $value = get_option($key, $sentinel);
        return ['exists'=>$value !== $sentinel, 'value'=>$value];
    }

    /** @param array{exists:bool,value:mixed} $snapshot */
    private static function restore_proof(string $key, array $snapshot): void {
        if (!empty($snapshot['exists'])) update_option($key, $snapshot['value'], false);
        else delete_option($key);
    }

    /**
     * Jeżeli zgłoszenie jest ponownie na etapie „podpis rodzica”, podpis Organizatora
     * nie jest jeszcze ważny. Czyścimy pozostawiony przez historyczny nieudany przebieg
     * snapshot, aby podgląd nie pokazywał starej daty ani starego ID SMS.
     */
    public static function cleanup_stale_proofs(): void {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id,agreement_id FROM ".BCS_DB::table('registrations')."
             WHERE agreement_status='parent_signed' AND agreement_id IS NOT NULL AND agreement_id>0"
        );
        foreach ($rows as $row) {
            $key = self::proof_key((int)$row->agreement_id);
            $proof = get_option($key, null);
            if (!is_array($proof) || !$proof) continue;
            delete_option($key);
            BCS_Utils::log('organizer_agreement_stale_proof_cleared_078', [
                'previous_accepted_at'=>(string)($proof['accepted_at'] ?? ''),
                'previous_sms_message_id'=>(string)($proof['sms_message_id'] ?? $proof['sms_id'] ?? ''),
                'reason'=>'registration_parent_signed',
            ], (int)$row->id, (int)$row->agreement_id);
        }
    }

    public static function ajax_send_organizer_otp(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        check_ajax_referer('bcs_046', 'nonce');

        global $wpdb;
        $registration_id = absint($_POST['registration_id'] ?? 0);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.id,r.status,r.agreement_status,r.agreement_id,a.agreement_number,
                    o.id organizer_id,o.name organizer_name,o.phone organizer_phone
             FROM ".BCS_DB::table('registrations')." r
             JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id
             LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id
             LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id
             WHERE r.id=%d",
            $registration_id
        ));

        if (!$row) wp_send_json_error(['message'=>'Nie znaleziono zgłoszenia.'], 404);
        if ((string)$row->agreement_status !== 'parent_signed') {
            wp_send_json_error(['message'=>'Najpierw umowę musi podpisać rodzic.'], 409);
        }
        if (empty($row->agreement_id)) wp_send_json_error(['message'=>'Brak umowy powiązanej ze zgłoszeniem.'], 409);

        $phone = BCS_Utils::normalize_phone((string)$row->organizer_phone);
        if (strlen(preg_replace('/\D+/', '', $phone)) < 9) {
            wp_send_json_error([
                'message'=>'Organizator nie ma zapisanego prawidłowego numeru telefonu. Uzupełnij numer w module Organizatorzy.',
                'organizer_id'=>(int)$row->organizer_id,
            ], 409);
        }

        $code = (string)random_int(100000, 999999);
        $message = sprintf(
            'Basketmania Camp: kod Organizatora do podpisania umowy %s to %s. Kod jest wazny 10 minut.',
            (string)$row->agreement_number,
            $code
        );
        $sent = BCS_SMS::send($phone, $message);
        if (empty($sent['success'])) {
            wp_send_json_error(['message'=>'Nie udało się wysłać SMS: '.(string)($sent['error'] ?? 'Nieznany błąd.')], 500);
        }

        $smsMessageId = trim((string)($sent['message_id'] ?? ''));
        $sentAt = BCS_Utils::now();

        // Na etapie parent_signed żaden wcześniejszy dowód Organizatora nie jest ważny.
        delete_option(self::proof_key((int)$row->agreement_id));

        set_transient(self::request_key($registration_id), [
            'agreement_id'=>(int)$row->agreement_id,
            'registration_id'=>$registration_id,
            'phone'=>$phone,
            'code_hash'=>wp_hash_password($code),
            'sms_message_id'=>$smsMessageId,
            'sms_id'=>$smsMessageId, // kompatybilność z 0.46/0.51
            'provider'=>(string)($sent['provider_label'] ?? BCS_SMS::provider_label()),
            'sent_at'=>$sentAt,
            'expires'=>time() + self::OTP_TTL,
            'attempts'=>0,
        ], self::OTP_TTL);

        BCS_Utils::log('organizer_agreement_otp_sent_078', [
            'phone'=>BCS_Utils::mask_phone($phone),
            'sms_message_id'=>$smsMessageId,
            'provider'=>(string)($sent['provider_label'] ?? BCS_SMS::provider_label()),
            'sent_at'=>$sentAt,
        ], $registration_id, (int)$row->agreement_id);

        wp_send_json_success([
            'message'=>'Kod został wysłany do Organizatora.',
            'phone'=>BCS_Utils::mask_phone($phone),
            'organizer'=>(string)$row->organizer_name,
        ]);
    }

    public static function ajax_verify_organizer_otp(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        check_ajax_referer('bcs_046', 'nonce');

        global $wpdb;
        $registration_id = absint($_POST['registration_id'] ?? 0);
        $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
        if (strlen($code) !== 6) wp_send_json_error(['message'=>'Wpisz pełny 6-cyfrowy kod SMS.'], 400);

        $data = get_transient(self::request_key($registration_id));
        if (!is_array($data) || empty($data['agreement_id'])) {
            wp_send_json_error(['message'=>'Kod wygasł albo nie został wysłany.'], 410);
        }
        if ((int)($data['expires'] ?? 0) < time()) {
            delete_transient(self::request_key($registration_id));
            wp_send_json_error(['message'=>'Kod wygasł. Wyślij nowy.'], 410);
        }

        $attempts = (int)($data['attempts'] ?? 0);
        if ($attempts >= 5) {
            delete_transient(self::request_key($registration_id));
            wp_send_json_error(['message'=>'Przekroczono liczbę prób. Wyślij nowy kod.'], 429);
        }
        if (!wp_check_password($code, (string)$data['code_hash'])) {
            $data['attempts'] = $attempts + 1;
            set_transient(
                self::request_key($registration_id),
                $data,
                max(1, (int)$data['expires'] - time())
            );
            wp_send_json_error(['message'=>'Kod jest nieprawidłowy.'], 400);
        }

        $registration = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*,a.status agreement_record_status
             FROM ".BCS_DB::table('registrations')." r
             JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id
             WHERE r.id=%d",
            $registration_id
        ));
        if (!$registration || (string)$registration->agreement_status !== 'parent_signed') {
            wp_send_json_error(['message'=>'Najpierw umowę musi podpisać rodzic.'], 409);
        }

        $agreementId = (int)$data['agreement_id'];
        if ($agreementId !== (int)$registration->agreement_id) {
            delete_transient(self::request_key($registration_id));
            wp_send_json_error(['message'=>'Kod został wystawiony dla innej wersji umowy. Wyślij nowy kod.'], 409);
        }

        $smsMessageId = trim((string)($data['sms_message_id'] ?? $data['sms_id'] ?? ''));
        if ($smsMessageId === '') {
            delete_transient(self::request_key($registration_id));
            BCS_Utils::log('organizer_agreement_otp_missing_sms_id_078', [
                'provider'=>(string)($data['provider'] ?? ''),
                'sent_at'=>(string)($data['sent_at'] ?? ''),
            ], $registration_id, $agreementId);
            wp_send_json_error([
                'message'=>'Operator SMS nie zwrócił identyfikatora wiadomości. Dla bezpieczeństwa podpisu wyślij nowy kod.',
            ], 409);
        }

        $user = wp_get_current_user();
        $now = BCS_Utils::now();
        $proofKey = self::proof_key($agreementId);
        $previousProof = self::proof_snapshot($proofKey);
        $proof = [
            'accepted_at'=>$now,
            'verified_at'=>$now,
            'phone'=>(string)$data['phone'],
            'sms_message_id'=>$smsMessageId,
            'sms_id'=>$smsMessageId, // kompatybilność z rendererem 0.51
            'provider'=>(string)($data['provider'] ?? ''),
            'sent_at'=>(string)($data['sent_at'] ?? ''),
            'user'=>trim($user->display_name.' (ID '.get_current_user_id().')'),
            'user_id'=>(int)get_current_user_id(),
            'registration_id'=>$registration_id,
            'agreement_id'=>$agreementId,
            'source'=>'organizer_otp_verify_078',
        ];
        update_option($proofKey, $proof, false);

        $storedProof = get_option($proofKey, []);
        if (!is_array($storedProof)
            || (string)($storedProof['accepted_at'] ?? '') !== $now
            || (string)($storedProof['sms_message_id'] ?? '') !== $smsMessageId) {
            self::restore_proof($proofKey, $previousProof);
            wp_send_json_error(['message'=>'Nie udało się zapisać danych dowodowych podpisu Organizatora.'], 500);
        }

        $previousState = [
            'agreement_status'=>(string)$registration->agreement_status,
            'status'=>(string)$registration->status,
            'payment_due_date'=>$registration->payment_due_date,
        ];
        $due = (new DateTimeImmutable('+7 days', BCS_Utils::timezone()))->format('Y-m-d');
        $updated = $wpdb->update(BCS_DB::table('registrations'), [
            'agreement_status'=>'accepted',
            'status'=>'awaiting_bank_payment',
            'payment_due_date'=>$due,
            'updated_at'=>$now,
        ], ['id'=>$registration_id]);
        if ($updated === false) {
            self::restore_proof($proofKey, $previousProof);
            wp_send_json_error(['message'=>'Nie udało się zakończyć podpisywania umowy.'], 500);
        }

        if (!BCS_Release_051::repair_registration($registration_id, true)) {
            $wpdb->update(BCS_DB::table('registrations'), [
                'agreement_status'=>$previousState['agreement_status'],
                'status'=>$previousState['status'],
                'payment_due_date'=>$previousState['payment_due_date'],
                'updated_at'=>BCS_Utils::now(),
            ], ['id'=>$registration_id]);
            self::restore_proof($proofKey, $previousProof);
            BCS_Utils::log('agreement_final_document_failed_078', [
                'reason'=>'Nie udało się zbudować pełnej treści finalnej umowy; cofnięto także dowód Organizatora.',
                'sms_message_id'=>$smsMessageId,
                'accepted_at'=>$now,
            ], $registration_id, $agreementId);
            wp_send_json_error([
                'message'=>'Kod jest poprawny, ale system nie zbudował pełnej umowy. Podpis Organizatora i etap płatności zostały cofnięte.',
            ], 500);
        }

        delete_transient(self::request_key($registration_id));

        if (class_exists('BCS_Workflow_Engine')) BCS_Workflow_Engine::refresh_invoice_readiness($registration_id);
        if (class_exists('BCS_Communication_Engine')) {
            BCS_Communication_Engine::send_to_registration($registration_id, 'agreement_signed', 'email');
            BCS_Communication_Engine::send_to_registration($registration_id, 'payment_reminder', 'both');
        }

        BCS_Utils::log('organizer_agreement_otp_verified', [
            'phone'=>BCS_Utils::mask_phone((string)$proof['phone']),
            'sms_message_id'=>$smsMessageId,
            'accepted_at'=>$now,
            'provider'=>(string)$proof['provider'],
            'workflow'=>'parent_first_078',
            'final_document_verified'=>true,
        ], $registration_id, $agreementId);

        wp_send_json_success([
            'message'=>'Umowa została podpisana przez Organizatora. Sekcja dowodowa zawiera czas weryfikacji i identyfikator dokładnie tego SMS-a, którego kod użyto.',
        ]);
    }
}
