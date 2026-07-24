<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_041 {
    private const ORG_PROOF_PREFIX = 'bcs_org_proof_';

    public static function init(): void {
        remove_action('wp_ajax_nopriv_bcs_verify_otp', ['BCS_Agreements', 'ajax_verify_otp']);
        remove_action('wp_ajax_bcs_verify_otp', ['BCS_Agreements', 'ajax_verify_otp']);
        add_action('wp_ajax_nopriv_bcs_verify_otp', [__CLASS__, 'ajax_verify_parent_otp']);
        add_action('wp_ajax_bcs_verify_otp', [__CLASS__, 'ajax_verify_parent_otp']);

        remove_action('wp_ajax_bcs_organizer_agreement_otp_verify', ['BCS_Release_029', 'ajax_verify_organizer_otp']);
        add_action('wp_ajax_bcs_organizer_agreement_otp_verify', [__CLASS__, 'ajax_verify_organizer_otp']);
        add_action('wp_ajax_bcs_041_signature_state', [__CLASS__, 'ajax_signature_state']);

        add_filter('do_shortcode_tag', [__CLASS__, 'filter_parent_portal'], 40, 4);
        add_action('admin_footer', [__CLASS__, 'admin_footer_script'], 99);
    }

    private static function request_key(int $registration_id): string {
        return 'bcs_org_otp_' . get_current_user_id() . '_' . $registration_id;
    }

    private static function proof_key(int $agreement_id): string {
        return self::ORG_PROOF_PREFIX . $agreement_id;
    }

    private static function first_opened_at(int $registration_id, int $agreement_id): string {
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare(
            "SELECT MIN(created_at) FROM ".BCS_DB::table('logs')." WHERE registration_id=%d AND agreement_id=%d AND event_type='agreement_opened_for_signature'",
            $registration_id,
            $agreement_id
        ));
    }

    public static function ajax_verify_parent_otp(): void {
        check_ajax_referer('bcs_front', 'nonce');
        global $wpdb;
        $agreement_id = absint($_POST['agreement_id'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
        $declaration = sanitize_textarea_field(wp_unslash($_POST['declaration'] ?? ''));
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*,r.parent_phone,r.id registration_id,r.public_token FROM ".BCS_DB::table('agreements')." a JOIN ".BCS_DB::table('registrations')." r ON r.id=a.registration_id WHERE a.id=%d",
            $agreement_id
        ));
        if (!$row || !hash_equals((string)$row->public_token, $token)) wp_send_json_error(['message'=>'Nieprawidłowy link.'],403);
        if ($row->status === 'accepted') wp_send_json_success(['message'=>'Umowa została już podpisana przez rodzica i oczekuje na podpis Organizatora.']);
        if (self::first_opened_at((int)$row->registration_id, $agreement_id) === '') wp_send_json_error(['message'=>'Najpierw otwórz umowę do podpisu.'],400);
        if (sanitize_key(wp_unslash($_POST['agreement_read'] ?? '')) !== '1' || $declaration === '') wp_send_json_error(['message'=>'Wszystkie oświadczenia są wymagane.'],400);
        if (strlen($code) !== 6) wp_send_json_error(['message'=>'Wpisz pełny 6-cyfrowy kod SMS.'],400);

        $otp = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('otp')." WHERE agreement_id=%d AND used_at IS NULL ORDER BY id DESC LIMIT 1", $agreement_id));
        if (!$otp) wp_send_json_error(['message'=>'Najpierw wyślij kod SMS.'],400);
        if (strtotime((string)$otp->expires_at.' Europe/Warsaw') < time()) wp_send_json_error(['message'=>'Kod wygasł. Wyślij nowy.'],410);
        $settings = get_option('bcs_settings', []);
        $max = max(3, absint($settings['max_attempts'] ?? 5));
        if ((int)$otp->attempts >= $max) wp_send_json_error(['message'=>'Przekroczono liczbę prób. Wyślij nowy kod.'],429);
        $wpdb->query($wpdb->prepare("UPDATE ".BCS_DB::table('otp')." SET attempts=attempts+1 WHERE id=%d", $otp->id));
        if (!wp_check_password($code, (string)$otp->code_hash)) wp_send_json_error(['message'=>'Kod jest nieprawidłowy.'],400);

        $now = BCS_Utils::now();
        $wpdb->update(BCS_DB::table('otp'), ['used_at'=>$now], ['id'=>(int)$otp->id]);
        $wpdb->update(BCS_DB::table('agreements'), [
            'status'=>'accepted', 'accepted_at'=>$now, 'accepted_ip'=>BCS_Utils::client_ip(),
            'accepted_user_agent'=>sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'accepted_phone_masked'=>(string)$row->parent_phone, 'sms_message_id'=>(string)$otp->sms_message_id,
            'declaration_text'=>$declaration,
        ], ['id'=>$agreement_id]);
        $wpdb->update(BCS_DB::table('registrations'), [
            'agreement_status'=>'parent_signed', 'status'=>'agreement_parent_signed', 'updated_at'=>$now,
        ], ['id'=>(int)$row->registration_id]);
        BCS_Utils::log('agreement_parent_signed',['sms_message_id'=>$otp->sms_message_id],(int)$row->registration_id,$agreement_id);
        wp_send_json_success(['message'=>'Umowa została skutecznie podpisana. Po podpisaniu umowy przez Administratora zostanie przesłana e-mailem i udostępniona do pobrania w Panelu rodzica.']);
    }

    public static function ajax_verify_organizer_otp(): void {
        check_ajax_referer('bcs_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'],403);
        global $wpdb;
        $registration_id = absint($_POST['registration_id'] ?? 0);
        $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
        $data = get_transient(self::request_key($registration_id));
        if (!is_array($data) || empty($data['agreement_id'])) wp_send_json_error(['message'=>'Kod wygasł albo nie został wysłany.'],410);
        if ((int)$data['expires'] < time() || !wp_check_password($code, (string)$data['code_hash'])) wp_send_json_error(['message'=>'Kod jest nieprawidłowy lub wygasł.'],400);
        $row = $wpdb->get_row($wpdb->prepare("SELECT r.*,a.status agreement_record_status FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id WHERE r.id=%d", $registration_id));
        if (!$row || $row->agreement_status !== 'parent_signed') wp_send_json_error(['message'=>'Najpierw umowę musi podpisać rodzic.'],409);

        $user = wp_get_current_user();
        $proof = [
            'accepted_at'=>BCS_Utils::now(), 'phone'=>(string)$data['phone'], 'sms_id'=>(string)$data['sms_id'],
            'user'=>trim($user->display_name.' (ID '.get_current_user_id().')'), 'registration_id'=>$registration_id,
        ];
        update_option(self::proof_key((int)$data['agreement_id']), $proof, false);
        delete_transient(self::request_key($registration_id));

        $due = (new DateTimeImmutable('+7 days', BCS_Utils::timezone()))->format('Y-m-d');
        $wpdb->update(BCS_DB::table('registrations'), [
            'agreement_status'=>'accepted', 'status'=>'awaiting_bank_payment', 'payment_due_date'=>$due, 'updated_at'=>BCS_Utils::now(),
        ], ['id'=>$registration_id]);
        if (class_exists('BCS_Workflow_Engine')) BCS_Workflow_Engine::refresh_invoice_readiness($registration_id);
        if (class_exists('BCS_Communication_Engine')) {
            BCS_Communication_Engine::send_to_registration($registration_id, 'agreement_signed', 'email');
            BCS_Communication_Engine::send_to_registration($registration_id, 'payment_reminder', 'both');
        }
        BCS_Utils::log('organizer_agreement_otp_verified', ['phone'=>BCS_Utils::mask_phone((string)$proof['phone']),'sms_message_id'=>$proof['sms_id']], $registration_id, (int)$data['agreement_id']);
        wp_send_json_success(['message'=>'Umowa została podpisana przez Organizatora. Podpisany dokument udostępniono rodzicowi, a informacja o płatności została wysłana.']);
    }

    public static function ajax_signature_state(): void {
        check_ajax_referer('bcs_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error([],403);
        global $wpdb;
        $ids = array_values(array_filter(array_map('absint', (array)($_POST['registration_ids'] ?? []))));
        if (!$ids) wp_send_json_success(['eligible'=>[]]);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $query = $wpdb->prepare("SELECT id FROM ".BCS_DB::table('registrations')." WHERE id IN ($placeholders) AND agreement_status='parent_signed'", ...$ids);
        wp_send_json_success(['eligible'=>array_map('intval', $wpdb->get_col($query))]);
    }

    public static function filter_parent_portal(string $output, string $tag, array $attr, array $m): string {
        if ($tag !== 'basketmania_portal') return $output;
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        if ($token === '') return $output;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT agreement_id,agreement_status FROM ".BCS_DB::table('registrations')." WHERE public_token=%s", $token));
        if (!$row || $row->agreement_status === 'accepted') return $output;
        $output = preg_replace('~<a\b[^>]*(?:pobierz|download|signed)[^>]*>.*?</a>~is', '', $output);
        if ($row->agreement_status === 'parent_signed') {
            $notice = '<div class="bcs-alert"><strong>Umowa podpisana przez rodzica.</strong> Dokument oczekuje na podpis Organizatora. Po jego podpisaniu otrzymasz wiadomość e-mail i możliwość pobrania kompletnej umowy.</div>';
            $output = $notice.$output;
        }
        return $output;
    }

    public static function admin_footer_script(): void {
        if (!current_user_can('manage_options')) return;
        $nonce = wp_create_nonce('bcs_admin');
        ?>
        <script>
        (()=>{const nonce=<?php echo wp_json_encode($nonce); ?>;function idOf(el){const b=el.closest('[data-registration-id],[data-id],tr,form');const vals=[el.dataset.registrationId,b&&b.dataset.registrationId,b&&b.dataset.id];if(b){for(const n of ['registration_id','id']){const i=b.querySelector('[name="'+n+'"]');if(i)vals.push(i.value)}}return parseInt(vals.find(v=>/^\d+$/.test(v||''))||'0',10)}
        const boxes=[...document.querySelectorAll('[data-registration-id],[data-id],tr,form')];const ids=[...new Set(boxes.map(idOf).filter(Boolean))];if(!ids.length)return;const fd=new FormData();fd.append('action','bcs_041_signature_state');fd.append('nonce',nonce);ids.forEach(id=>fd.append('registration_ids[]',id));fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd}).then(r=>r.json()).then(j=>{if(!j.success)return;const ok=new Set(j.data.eligible||[]);boxes.forEach(box=>{const id=idOf(box);if(!ok.has(id)||box.querySelector('.bcs-org-sign-041'))return;const host=box.querySelector('.bcs-quick-actions,.quick-actions,.actions,td:last-child')||box;const b=document.createElement('button');b.type='button';b.className='button button-primary bcs-org-sign-041';b.dataset.registrationId=id;b.textContent='PODPISZ UMOWĘ SMS-em';host.appendChild(b);});});
        document.addEventListener('click',e=>{const b=e.target.closest('.bcs-org-sign-041');if(!b)return;const candidate=[...document.querySelectorAll('button,a')].find(x=>{const t=(x.innerText||x.value||'').toLowerCase();return idOf(x)===idOf(b)&&t.includes('wyślij umow')&&t.includes('podpis')});if(candidate)candidate.click();else alert('Nie znaleziono modalu podpisu Organizatora. Odśwież stronę i spróbuj ponownie.');});})();
        </script>
        <?php
    }
}
