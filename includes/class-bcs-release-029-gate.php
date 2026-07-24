<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_029_Gate {
    public static function init(): void {
        add_action('wp_ajax_bcs_card_action_02021', [__CLASS__, 'gate_card_send'], 0);
        add_action('wp_ajax_bcs_list_quick_action_02010', [__CLASS__, 'gate_list_send'], 0);
        add_action('admin_footer', [__CLASS__, 'list_button_script'], 1);
    }

    private static function authorized(int $registration_id): bool {
        $key = 'bcs_org_authorized_'.get_current_user_id().'_'.$registration_id;
        if (!get_transient($key)) return false;
        delete_transient($key);
        return true;
    }

    public static function gate_card_send(): void {
        if (sanitize_key(wp_unslash($_POST['card_action'] ?? '')) !== 'send_agreement') return;
        $id = absint($_POST['registration_id'] ?? 0);
        if (!$id || !self::authorized($id)) {
            wp_send_json_error(['message'=>'Przed wysłaniem umowy Organizator musi potwierdzić ją kodem SMS.'], 428);
        }
    }

    public static function gate_list_send(): void {
        if (sanitize_key(wp_unslash($_POST['quick_action'] ?? '')) !== 'send_agreement') return;
        $id = absint($_POST['registration_id'] ?? 0);
        if (!$id || !self::authorized($id)) {
            wp_send_json_error(['message'=>'Przed wysłaniem umowy Organizator musi potwierdzić ją kodem SMS.'], 428);
        }
    }

    public static function list_button_script(): void {
        if (!current_user_can('manage_options')) return;
        $nonce = wp_create_nonce('bcs_admin');
        ?>
        <script>
        (()=>{let active=false;
        function idOf(el){const box=el.closest('[data-registration-id],[data-id],form,tr');const vals=[el.dataset.registrationId,box&&box.dataset.registrationId,box&&box.dataset.id];if(box){for(const n of ['registration_id','id']){const i=box.querySelector('[name="'+n+'"]');if(i)vals.push(i.value);}}return parseInt(vals.find(v=>/^\d+$/.test(v||''))||'0',10)}
        document.addEventListener('click',async e=>{const b=e.target.closest('button,a,input[type=submit]');if(!b||active||b.dataset.bcsOrgOtpDone==='1')return;const t=(b.innerText||b.value||'').toLowerCase();if(!t.includes('wyślij umow')||t.includes('podpis'))return;const id=idOf(b);if(!id)return;e.preventDefault();e.stopImmediatePropagation();const phone=prompt('Podaj numer telefonu Organizatora, na który wysłać kod OTP dla tej umowy:');if(!phone)return;active=true;try{let f=new FormData();f.append('action','bcs_organizer_agreement_otp_send');f.append('nonce','<?php echo esc_js($nonce); ?>');f.append('registration_id',id);f.append('phone',phone);let r=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:f});let j=await r.json();if(!j.success){alert(j.data&&j.data.message||'Nie udało się wysłać kodu.');return;}const code=prompt('Wpisz 6-cyfrowy kod OTP wysłany na '+(j.data.phone||'podany numer')+':');if(!code)return;f=new FormData();f.append('action','bcs_organizer_agreement_otp_verify');f.append('nonce','<?php echo esc_js($nonce); ?>');f.append('registration_id',id);f.append('code',code);r=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:f});j=await r.json();if(!j.success){alert(j.data&&j.data.message||'Kod jest nieprawidłowy.');return;}alert(j.data.message);b.dataset.bcsOrgOtpDone='1';b.click();}finally{active=false;}},true);})();
        </script>
        <?php
    }
}
