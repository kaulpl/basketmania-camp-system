<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_030 {
    private const OTP_TTL = 600;

    public static function init(): void {
        self::migrate_agreement_template();
        remove_action('admin_footer', ['BCS_Release_029', 'admin_footer_script']);
        add_action('wp_ajax_bcs_organizer_agreement_otp_send_030', [__CLASS__, 'ajax_send']);
        add_action('wp_ajax_bcs_organizer_agreement_otp_verify_030', [__CLASS__, 'ajax_verify']);
        add_action('admin_footer', [__CLASS__, 'admin_footer']);
    }

    private static function request_key(int $registration_id): string {
        return 'bcs_org_otp_030_' . get_current_user_id() . '_' . $registration_id;
    }

    private static function proof_key(int $agreement_id): string {
        return 'bcs_org_proof_' . $agreement_id;
    }

    private static function migrate_agreement_template(): void {
        if (get_option('bcs_release_030_template_migrated')) return;
        $saved = get_option('bcs_content_templates', []);
        if (!is_array($saved)) $saved = [];
        $template = (string)($saved['documents']['agreement'] ?? '');
        if ($template !== '' && !str_contains($template, '<strong>Numer umowy:</strong> {{AGREEMENT_NUMBER}}')) {
            $patterns = [
                '~(z siedzibą w\s*\{\{ORGANIZER_ADDRESS\}\},?)~u',
                '~(z siedzibą:\s*\{\{ORGANIZER_ADDRESS\}\},?)~u',
            ];
            foreach ($patterns as $pattern) {
                $updated = preg_replace($pattern, '$1 <strong>Numer umowy:</strong> {{AGREEMENT_NUMBER}},', $template, 1, $count);
                if ($count > 0) { $template = $updated; break; }
            }
            if (!str_contains($template, '<strong>Numer umowy:</strong> {{AGREEMENT_NUMBER}}')) {
                $template = preg_replace('~(<p>\s*<strong>\{\{ORGANIZER_NAME\}\}</strong>)~u', '$1 <strong>Numer umowy:</strong> {{AGREEMENT_NUMBER}},', $template, 1);
            }
            $saved['documents']['agreement'] = $template;
            update_option('bcs_content_templates', $saved, false);
        }
        update_option('bcs_release_030_template_migrated', 1, false);
    }

    public static function ajax_send(): void {
        check_ajax_referer('bcs_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        global $wpdb;
        $registration_id = absint($_POST['registration_id'] ?? 0);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.id,r.agreement_id,a.agreement_number,o.phone organizer_phone,o.name organizer_name,o.id organizer_id
             FROM ".BCS_DB::table('registrations')." r
             JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id
             LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id
             LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id
             WHERE r.id=%d", $registration_id
        ));
        if (!$row || empty($row->agreement_id)) wp_send_json_error(['message'=>'Najpierw przygotuj draft umowy.'], 409);
        $phone = BCS_Utils::normalize_phone((string)$row->organizer_phone);
        if (strlen(preg_replace('/\D+/', '', $phone)) < 9) {
            wp_send_json_error(['message'=>'Organizator nie ma zapisanego prawidłowego numeru telefonu. Uzupełnij go w module Organizatorzy przed wysłaniem umowy.','organizer_id'=>(int)$row->organizer_id], 409);
        }
        $code = (string)random_int(100000, 999999);
        $message = sprintf('Basketmania Camp: kod Organizatora dla umowy %s to %s. Kod jest ważny 10 minut.', (string)$row->agreement_number, $code);
        $sent = BCS_SMS::send($phone, $message);
        if (empty($sent['success'])) wp_send_json_error(['message'=>'Nie udało się wysłać SMS: '.(string)($sent['error'] ?? 'Nieznany błąd.')], 500);
        set_transient(self::request_key($registration_id), [
            'agreement_id'=>(int)$row->agreement_id,
            'phone'=>$phone,
            'code_hash'=>wp_hash_password($code),
            'sms_id'=>(string)($sent['message_id'] ?? ''),
            'expires'=>time()+self::OTP_TTL,
        ], self::OTP_TTL);
        wp_send_json_success(['message'=>'Kod został wysłany na numer Organizatora.','phone'=>BCS_Utils::mask_phone($phone),'organizer'=>(string)$row->organizer_name]);
    }

    public static function ajax_verify(): void {
        check_ajax_referer('bcs_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        $registration_id = absint($_POST['registration_id'] ?? 0);
        $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
        if (strlen($code) !== 6) wp_send_json_error(['message'=>'Wpisz pełny 6-cyfrowy kod.'], 400);
        $data = get_transient(self::request_key($registration_id));
        if (!is_array($data) || empty($data['agreement_id'])) wp_send_json_error(['message'=>'Kod wygasł albo nie został wysłany.'], 410);
        if ((int)$data['expires'] < time() || !wp_check_password($code, (string)$data['code_hash'])) wp_send_json_error(['message'=>'Kod jest nieprawidłowy lub wygasł.'], 400);
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
        wp_send_json_success(['message'=>'Kod został potwierdzony. Umowa jest wysyłana do rodzica.']);
    }

    public static function admin_footer(): void {
        if (!current_user_can('manage_options')) return;
        $nonce = wp_create_nonce('bcs_admin');
        ?>
        <style>
        .bcs-otp030-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.62);display:flex;align-items:center;justify-content:center;z-index:100000;padding:20px}.bcs-otp030-modal{width:min(520px,100%);background:#fff;border-radius:18px;box-shadow:0 24px 70px rgba(15,23,42,.3);overflow:hidden}.bcs-otp030-head{padding:24px 26px;background:linear-gradient(135deg,#111827,#334155);color:#fff;display:flex;gap:16px;align-items:center}.bcs-otp030-icon{width:48px;height:48px;border-radius:14px;background:rgba(255,255,255,.14);display:grid;place-items:center;font-size:25px}.bcs-otp030-head h2{margin:0;color:#fff;font-size:21px}.bcs-otp030-head p{margin:4px 0 0;color:#dbeafe}.bcs-otp030-body{padding:26px}.bcs-otp030-status{padding:13px 15px;border-radius:10px;background:#f1f5f9;margin-bottom:18px}.bcs-otp030-code{display:flex;gap:8px;justify-content:center;margin:22px 0}.bcs-otp030-code input{width:48px;height:58px;text-align:center;font-size:26px;font-weight:700;border:2px solid #cbd5e1;border-radius:10px}.bcs-otp030-code input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15);outline:0}.bcs-otp030-actions{display:flex;justify-content:flex-end;gap:10px}.bcs-otp030-error{color:#b91c1c;background:#fef2f2;padding:10px 12px;border-radius:8px;margin-top:12px}.bcs-important-notes .bcs-data-preview{display:none!important}
        </style>
        <script>
        (()=>{let bypass=false,activeButton=null,activeId=0;
        const nonce='<?php echo esc_js($nonce); ?>';
        const regId=el=>{const box=el.closest('[data-registration-id],[data-id],form,tr');const vals=[el.dataset.registrationId,box&&box.dataset.registrationId,box&&box.dataset.id];if(box){for(const n of ['registration_id','id']){const i=box.querySelector('[name="'+n+'"]');if(i)vals.push(i.value)}}try{const u=new URL(el.getAttribute('href')||'',location.href);vals.push(u.searchParams.get('registration_id'),u.searchParams.get('id'))}catch(e){}return parseInt(vals.find(v=>/^\d+$/.test(v||''))||'0',10)};
        const close=()=>{document.querySelector('.bcs-otp030-backdrop')?.remove();activeButton=null;activeId=0};
        const modal=()=>{close();const d=document.createElement('div');d.className='bcs-otp030-backdrop';d.innerHTML='<div class="bcs-otp030-modal" role="dialog" aria-modal="true"><div class="bcs-otp030-head"><div class="bcs-otp030-icon">✉</div><div><h2>Potwierdzenie Organizatora</h2><p>Autoryzacja wysłania umowy kodem SMS</p></div></div><div class="bcs-otp030-body"><div class="bcs-otp030-status" data-status>Wysyłamy kod na numer zapisany w danych Organizatora…</div><div class="bcs-otp030-code" data-code hidden>'+Array.from({length:6},(_,i)=>'<input inputmode="numeric" maxlength="1" aria-label="Cyfra '+(i+1)+'">').join('')+'</div><div class="bcs-otp030-error" data-error hidden></div><div class="bcs-otp030-actions"><button type="button" class="button" data-cancel>Anuluj</button><button type="button" class="button button-primary" data-verify disabled>Potwierdź i wyślij umowę</button></div></div></div>';document.body.appendChild(d);d.querySelector('[data-cancel]').onclick=close;return d};
        const post=async data=>{const fd=new FormData();Object.entries(data).forEach(([k,v])=>fd.append(k,v));const r=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd});return r.json()};
        document.addEventListener('click',async e=>{const b=e.target.closest('button,a,input[type=submit]');if(!b||bypass)return;const text=(b.innerText||b.value||'').trim().toLowerCase();if(!text.includes('wyślij umow'))return;const id=regId(b);if(!id)return;e.preventDefault();e.stopImmediatePropagation();activeButton=b;activeId=id;const m=modal(),status=m.querySelector('[data-status]'),error=m.querySelector('[data-error]'),codeWrap=m.querySelector('[data-code]'),verify=m.querySelector('[data-verify]');let j=await post({action:'bcs_organizer_agreement_otp_send_030',nonce,registration_id:id});if(!j.success){status.textContent='Nie można wysłać kodu.';error.hidden=false;error.textContent=j.data&&j.data.message||'Wystąpił błąd.';return}status.innerHTML='<strong>'+j.data.organizer+'</strong><br>Kod wysłano na '+j.data.phone+'. Wpisz 6 cyfr poniżej.';codeWrap.hidden=false;const inputs=[...codeWrap.querySelectorAll('input')];inputs[0].focus();inputs.forEach((inp,i)=>{inp.addEventListener('input',()=>{inp.value=inp.value.replace(/\D/g,'').slice(0,1);if(inp.value&&inputs[i+1])inputs[i+1].focus();verify.disabled=inputs.some(x=>!x.value)});inp.addEventListener('keydown',ev=>{if(ev.key==='Backspace'&&!inp.value&&inputs[i-1])inputs[i-1].focus()})});verify.onclick=async()=>{verify.disabled=true;error.hidden=true;const code=inputs.map(x=>x.value).join('');const v=await post({action:'bcs_organizer_agreement_otp_verify_030',nonce,registration_id:activeId,code});if(!v.success){error.hidden=false;error.textContent=v.data&&v.data.message||'Kod jest nieprawidłowy.';verify.disabled=false;return}status.innerHTML='<strong>Potwierdzono.</strong><br>'+v.data.message;verify.textContent='Wysyłanie…';setTimeout(()=>{const target=activeButton;close();bypass=true;target.click();setTimeout(()=>bypass=false,1500)},450)}} ,true);
        if(location.search.includes('page=bcs-organizers')){document.querySelectorAll('input[name="org_phone"]').forEach(i=>{const label=i.closest('label');const s=label&&label.querySelector('span');if(s)s.textContent='Telefon Organizatora (kontakt i autoryzacja OTP)';i.required=true;i.placeholder='np. +48 600 000 000'})}
        })();
        </script>
        <?php
    }
}
