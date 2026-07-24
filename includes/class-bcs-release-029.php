<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_029 {
    private const OTP_TTL = 600;

    public static function init(): void {
        self::migrate_templates();
        add_action('wp_ajax_bcs_organizer_agreement_otp_send', [__CLASS__, 'ajax_send_organizer_otp']);
        add_action('wp_ajax_bcs_organizer_agreement_otp_verify', [__CLASS__, 'ajax_verify_organizer_otp']);
        add_action('admin_footer', [__CLASS__, 'admin_footer_script']);
        add_action('admin_post_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);
        add_action('admin_post_nopriv_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);
        register_shutdown_function([__CLASS__, 'rewrite_signed_version_after_parent_otp']);
    }

    private static function migrate_templates(): void {
        if (get_option('bcs_release_029_templates_migrated')) return;
        $saved = get_option('bcs_content_templates', []);
        if (!is_array($saved)) $saved = [];
        $saved['documents']['agreement_proof'] = self::default_proof_template();
        update_option('bcs_content_templates', $saved, false);
        update_option('bcs_release_029_templates_migrated', 1, false);
    }

    public static function default_proof_template(): string {
        return '<div class="proof"><h2>Sekcja dowodowa zawarcia umowy</h2><table style="width:100%;border-collapse:collapse"><tr><td style="width:50%;vertical-align:top;padding-right:12px"><h3>Potwierdzenie Organizatora</h3><p><strong>Status:</strong> potwierdzona kodem SMS</p><p><strong>Data i czas:</strong> {{ORGANIZER_ACCEPTED_AT}}</p><p><strong>Numer telefonu:</strong> {{ORGANIZER_PHONE}}</p><p><strong>Identyfikator wiadomości SMS:</strong> {{ORGANIZER_SMS_ID}}</p><p><strong>Osoba potwierdzająca:</strong> {{ORGANIZER_USER}}</p></td><td style="width:50%;vertical-align:top;padding-left:12px"><h3>Potwierdzenie Rodzica / Opiekuna</h3><p><strong>Status:</strong> potwierdzona kodem SMS</p><p><strong>Data i czas pierwszego otwarcia:</strong> {{PARENT_OPENED_AT}}</p><p><strong>Data i czas potwierdzenia:</strong> {{PARENT_ACCEPTED_AT}}</p><p><strong>Numer telefonu:</strong> {{PARENT_PHONE}}</p><p><strong>Identyfikator wiadomości SMS:</strong> {{PARENT_SMS_ID}}</p><p><strong>Oświadczenie:</strong> {{PARENT_DECLARATION}}</p></td></tr></table><p><strong>Adres IP rodzica / opiekuna:</strong> {{PARENT_IP}}</p><p><strong>Skrót SHA-256 podpisanej treści:</strong><br><code>{{DOCUMENT_HASH}}</code></p></div>';
    }

    private static function request_key(int $registration_id): string {
        return 'bcs_org_otp_' . get_current_user_id() . '_' . $registration_id;
    }

    private static function proof_key(int $agreement_id): string {
        return 'bcs_org_proof_' . $agreement_id;
    }

    public static function ajax_send_organizer_otp(): void {
        check_ajax_referer('bcs_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'],403);
        global $wpdb;
        $registration_id = absint($_POST['registration_id'] ?? 0);
        $phone = BCS_Utils::normalize_phone(sanitize_text_field(wp_unslash($_POST['phone'] ?? '')));
        if (!$registration_id || strlen(preg_replace('/\D+/', '', $phone)) < 9) wp_send_json_error(['message'=>'Podaj prawidłowy numer telefonu.'],400);
        $row = $wpdb->get_row($wpdb->prepare("SELECT r.id,r.agreement_id,a.agreement_number FROM ".BCS_DB::table('registrations')." r LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id WHERE r.id=%d", $registration_id));
        if (!$row || empty($row->agreement_id)) wp_send_json_error(['message'=>'Najpierw przygotuj draft umowy.'],409);
        $code = (string) random_int(100000, 999999);
        $message = sprintf('Basketmania Camp: kod Organizatora dla umowy %s to %s. Kod jest ważny 10 minut.', (string)$row->agreement_number, $code);
        $sent = BCS_SMS::send($phone, $message);
        if (empty($sent['success'])) wp_send_json_error(['message'=>'Nie udało się wysłać SMS: '.(string)($sent['error'] ?? 'Nieznany błąd.')],500);
        set_transient(self::request_key($registration_id), [
            'agreement_id'=>(int)$row->agreement_id,
            'phone'=>$phone,
            'code_hash'=>wp_hash_password($code),
            'sms_id'=>(string)($sent['message_id'] ?? ''),
            'expires'=>time()+self::OTP_TTL,
        ], self::OTP_TTL);
        wp_send_json_success(['message'=>'Kod został wysłany.','phone'=>BCS_Utils::mask_phone($phone)]);
    }

    public static function ajax_verify_organizer_otp(): void {
        check_ajax_referer('bcs_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'],403);
        $registration_id = absint($_POST['registration_id'] ?? 0);
        $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
        $data = get_transient(self::request_key($registration_id));
        if (!is_array($data) || empty($data['agreement_id'])) wp_send_json_error(['message'=>'Kod wygasł albo nie został wysłany.'],410);
        if ((int)$data['expires'] < time() || !wp_check_password($code, (string)$data['code_hash'])) wp_send_json_error(['message'=>'Kod jest nieprawidłowy lub wygasł.'],400);
        $user = wp_get_current_user();
        $proof = [
            'accepted_at'=>BCS_Utils::now(),
            'phone'=>(string)$data['phone'],
            'sms_id'=>(string)$data['sms_id'],
            'user'=>trim($user->display_name.' (ID '.get_current_user_id().')'),
            'registration_id'=>$registration_id,
        ];
        update_option(self::proof_key((int)$data['agreement_id']), $proof, false);
        set_transient('bcs_org_authorized_'.get_current_user_id().'_'.$registration_id, 1, 15 * MINUTE_IN_SECONDS);
        delete_transient(self::request_key($registration_id));
        BCS_Utils::log('organizer_agreement_otp_verified', ['phone'=>BCS_Utils::mask_phone((string)$proof['phone']),'sms_message_id'=>$proof['sms_id'],'user'=>$proof['user']], $registration_id, (int)$data['agreement_id']);
        wp_send_json_success(['message'=>'Tożsamość Organizatora została potwierdzona. Umowa może zostać wysłana do rodzica.']);
    }

    public static function admin_footer_script(): void {
        if (!current_user_can('manage_options')) return;
        $nonce = wp_create_nonce('bcs_admin');
        ?>
        <script>
        (()=>{let bypass=false;
        function regId(el){const box=el.closest('[data-registration-id],[data-id],form,tr');const vals=[el.dataset.registrationId,box&&box.dataset.registrationId,box&&box.dataset.id];if(box){for(const n of ['registration_id','id']){const i=box.querySelector('[name="'+n+'"]');if(i)vals.push(i.value);}}const h=el.getAttribute('href')||'';try{const u=new URL(h,location.href);vals.push(u.searchParams.get('registration_id'),u.searchParams.get('id'));}catch(e){}return parseInt(vals.find(v=>/^\d+$/.test(v||''))||'0',10)}
        document.addEventListener('click',async e=>{const b=e.target.closest('button,a,input[type=submit]');if(!b||bypass)return;const text=(b.innerText||b.value||'').trim().toLowerCase();if(!text.includes('wyślij umow')||!text.includes('podpis'))return;const id=regId(b);if(!id)return;e.preventDefault();e.stopImmediatePropagation();const phone=prompt('Podaj numer telefonu Organizatora, na który wysłać kod OTP dla tej umowy:');if(!phone)return;const fd=new FormData();fd.append('action','bcs_organizer_agreement_otp_send');fd.append('nonce','<?php echo esc_js($nonce); ?>');fd.append('registration_id',id);fd.append('phone',phone);let r=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd});let j=await r.json();if(!j.success){alert(j.data&&j.data.message||'Nie udało się wysłać kodu.');return;}const code=prompt('Wpisz 6-cyfrowy kod OTP wysłany na '+(j.data.phone||'podany numer')+':');if(!code)return;const vf=new FormData();vf.append('action','bcs_organizer_agreement_otp_verify');vf.append('nonce','<?php echo esc_js($nonce); ?>');vf.append('registration_id',id);vf.append('code',code);r=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:vf});j=await r.json();if(!j.success){alert(j.data&&j.data.message||'Kod jest nieprawidłowy.');return;}alert(j.data.message);bypass=true;b.click();setTimeout(()=>bypass=false,1000);},true);})();
        </script>
        <?php
    }

    private static function render_proof(object $row): string {
        $org = get_option(self::proof_key((int)$row->id), []);
        if (!is_array($org)) $org = [];
        $opened = method_exists('BCS_Agreements', 'first_opened_at') ? '' : '';
        global $wpdb;
        $opened = (string)$wpdb->get_var($wpdb->prepare("SELECT MIN(created_at) FROM ".BCS_DB::table('logs')." WHERE registration_id=%d AND agreement_id=%d AND event_type='agreement_opened_for_signature'", (int)$row->registration_id, (int)$row->id));
        $tpl = BCS_Template_Engine::get('documents','agreement_proof',self::default_proof_template());
        return BCS_Template_Engine::render($tpl, [
            '{{ORGANIZER_ACCEPTED_AT}}'=>esc_html((string)($org['accepted_at'] ?? '—')),
            '{{ORGANIZER_PHONE}}'=>esc_html(BCS_Utils::mask_phone((string)($org['phone'] ?? ''))),
            '{{ORGANIZER_SMS_ID}}'=>esc_html((string)($org['sms_id'] ?? '—')),
            '{{ORGANIZER_USER}}'=>esc_html((string)($org['user'] ?? '—')),
            '{{PARENT_OPENED_AT}}'=>esc_html(BCS_Utils::format_datetime($opened)),
            '{{PARENT_ACCEPTED_AT}}'=>esc_html(BCS_Utils::format_datetime((string)$row->accepted_at)),
            '{{PARENT_PHONE}}'=>esc_html(BCS_Utils::mask_phone((string)$row->parent_phone)),
            '{{PARENT_SMS_ID}}'=>esc_html((string)$row->sms_message_id),
            '{{PARENT_DECLARATION}}'=>esc_html((string)$row->declaration_text),
            '{{PARENT_IP}}'=>esc_html((string)$row->accepted_ip),
            '{{DOCUMENT_HASH}}'=>esc_html((string)$row->document_hash),
        ]);
    }

    public static function render_agreement_view(): void {
        global $wpdb;
        $id=absint($_GET['agreement']??0);$token=sanitize_text_field(wp_unslash($_GET['token']??''));
        $row=$wpdb->get_row($wpdb->prepare("SELECT a.*,r.public_token,r.parent_phone FROM ".BCS_DB::table('agreements')." a JOIN ".BCS_DB::table('registrations')." r ON r.id=a.registration_id WHERE a.id=%d",$id));
        if(!$row||(!current_user_can('manage_options')&&!hash_equals((string)$row->public_token,$token))) return;
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>'.esc_html($row->agreement_number).'</title><style>body{font-family:Arial,sans-serif;max-width:900px;margin:40px auto;line-height:1.55;color:#171717}.proof{margin-top:40px;padding:20px;border:2px solid #111}@media print{button{display:none}}</style></head><body><button onclick="window.print()">Drukuj / zapisz jako PDF</button>';
        echo wp_kses_post((string)$row->html);
        if($row->status==='accepted') echo wp_kses_post(self::render_proof($row));
        echo '</body></html>';exit;
    }

    public static function rewrite_signed_version_after_parent_otp(): void {
        if (($_REQUEST['action'] ?? '') !== 'bcs_verify_otp') return;
        global $wpdb;
        $agreement_id = absint($_REQUEST['agreement_id'] ?? 0);
        if (!$agreement_id) return;
        $row=$wpdb->get_row($wpdb->prepare("SELECT a.*,r.parent_phone FROM ".BCS_DB::table('agreements')." a JOIN ".BCS_DB::table('registrations')." r ON r.id=a.registration_id WHERE a.id=%d",$agreement_id));
        if(!$row || $row->status!=='accepted') return;
        $html = preg_replace('~<div class="proof">.*?</div>\s*$~s','',(string)$row->html);
        $signed = $html . self::render_proof($row);
        $wpdb->update(BCS_DB::table('agreement_versions'), ['html'=>$signed], ['agreement_id'=>$agreement_id,'stage'=>'signed']);
    }
}
