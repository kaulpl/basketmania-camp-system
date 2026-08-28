<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_044 {
    public static function init(): void {
        add_action('wp_ajax_bcs_044_verify_camp_form', [__CLASS__, 'ajax_verify_camp_form']);
        add_action('admin_footer', [__CLASS__, 'admin_footer'], 120);
    }

    public static function ajax_verify_camp_form(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        check_ajax_referer('bcs_044_verify_camp_form', 'nonce');
        $id = absint($_POST['registration_id'] ?? 0);
        if (!$id) wp_send_json_error(['message'=>'Brak identyfikatora zgłoszenia.'], 422);

        global $wpdb;
        $before = $wpdb->get_row($wpdb->prepare(
            "SELECT id,status,form_status,form_verified_at,agreement_id FROM ".BCS_DB::table('registrations')." WHERE id=%d",
            $id
        ));
        if (!$before) wp_send_json_error(['message'=>'Nie znaleziono zgłoszenia.'], 404);
        if ($before->status === 'cancelled') wp_send_json_error(['message'=>'Nie można zatwierdzić formularza anulowanego zgłoszenia.'], 409);
        if ($before->form_status !== 'complete') wp_send_json_error(['message'=>'Formularz obozowy nie ma statusu kompletnego. Zapisz kompletne dane formularza i spróbuj ponownie.'], 409);
        if (!empty($before->form_verified_at)) wp_send_json_success(['message'=>'Formularz obozowy był już wcześniej zatwierdzony.','already_verified'=>true]);

        $ok = BCS_Workflow::verify_form($id);
        if (!$ok) {
            $after = $wpdb->get_row($wpdb->prepare("SELECT status,form_status,form_verified_at,agreement_id FROM ".BCS_DB::table('registrations')." WHERE id=%d", $id));
            BCS_Utils::log('camp_form_verification_failed_044', ['before'=>(array)$before,'after'=>$after?(array)$after:[]], $id, (int)($before->agreement_id ?? 0));
            wp_send_json_error(['message'=>'Nie udało się zatwierdzić formularza ani utworzyć draftu umowy. Sprawdź kompletność danych oraz logi systemowe.'], 409);
        }

        $after = $wpdb->get_row($wpdb->prepare("SELECT status,form_status,form_verified_at,agreement_id,draft_sent_at FROM ".BCS_DB::table('registrations')." WHERE id=%d", $id));
        if (!$after || empty($after->form_verified_at) || $after->status !== 'draft_sent') {
            BCS_Utils::log('camp_form_verification_incomplete_044', ['state'=>$after?(array)$after:[]], $id, (int)($after->agreement_id ?? 0));
            wp_send_json_error(['message'=>'Operacja nie zakończyła zmiany etapu procesu. Odśwież kartę i sprawdź logi systemowe.'], 500);
        }

        $result = BCS_Workflow::last_form_verification_result();
        wp_send_json_success(['message'=>'Formularz obozowy został zatwierdzony. Umowa oczekuje na ręczne wysłanie do podpisu przez organizatora.','status'=>(string)$after->status,'email'=>!empty($result['email']),'draft'=>!empty($result['draft'])]);
    }

    public static function admin_footer(): void {
        if (!current_user_can('manage_options')) return;
        $page = sanitize_key($_GET['page'] ?? '');
        $id = absint($_GET['view'] ?? 0);
        if ($page !== 'bcs-registrations' || !$id) return;
        $nonce = wp_create_nonce('bcs_044_verify_camp_form');
        ?>
        <script>
        (()=>{const id=<?php echo (int)$id;?>,nonce=<?php echo wp_json_encode($nonce);?>;[...document.querySelectorAll('form')].filter(f=>f.querySelector('button[name="bcs_crm_action"][value="verify_form"]')).forEach(form=>{form.addEventListener('submit',async e=>{e.preventDefault();e.stopImmediatePropagation();const button=form.querySelector('button[name="bcs_crm_action"][value="verify_form"]');if(!button||button.disabled)return;button.disabled=true;const original=button.innerHTML;button.textContent='Zatwierdzanie…';const fd=new FormData();fd.append('action','bcs_044_verify_camp_form');fd.append('nonce',nonce);fd.append('registration_id',id);try{const response=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',cache:'no-store',body:fd});const json=await response.json();if(!json.success)throw new Error(json.data?.message||'Nie udało się zatwierdzić formularza.');alert(json.data.message);location.reload();}catch(error){alert(error.message||'Nie udało się zatwierdzić formularza.');button.disabled=false;button.innerHTML=original;}},true);});})();
        </script>
        <?php
    }
}
