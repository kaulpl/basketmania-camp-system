<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_046 {
    private const OTP_TTL = 600;

    public static function init(): void {
        self::disable_legacy_hooks();

        // Powtarzamy usuwanie hooków później, ponieważ część historycznych klas
        // mogła rejestrować swoje akcje w kolejnych etapach ładowania panelu.
        add_action('admin_init', [__CLASS__, 'disable_legacy_hooks'], PHP_INT_MAX);
        add_action('admin_head', [__CLASS__, 'disable_legacy_hooks'], -1000);

        add_action('wp_ajax_bcs_046_send_agreement', [__CLASS__, 'ajax_send_agreement']);
        add_action('wp_ajax_bcs_046_signature_state', [__CLASS__, 'ajax_signature_state']);
        add_action('wp_ajax_bcs_046_organizer_otp_send', [__CLASS__, 'ajax_send_organizer_otp']);
        add_action('wp_ajax_bcs_046_organizer_otp_verify', [__CLASS__, 'ajax_verify_organizer_otp']);

        // Skrypt jest dodawany w nagłówku, zanim historyczne listenery z admin_footer
        // zdążą przejąć kliknięcie w fazie capture.
        add_action('admin_head', [__CLASS__, 'admin_head_script'], 1);
    }

    public static function disable_legacy_hooks(): void {
        remove_action('wp_ajax_bcs_card_action_02021', ['BCS_Release_029_Gate', 'gate_card_send'], 0);
        remove_action('wp_ajax_bcs_list_quick_action_02010', ['BCS_Release_029_Gate', 'gate_list_send'], 0);

        remove_action('admin_footer', ['BCS_Release_029_Gate', 'list_button_script'], 1);
        remove_action('admin_footer', ['BCS_Release_029', 'admin_footer_script'], 10);
        remove_action('admin_footer', ['BCS_Release_030', 'admin_footer'], 10);
        remove_action('admin_footer', ['BCS_Release_041', 'admin_footer_script'], 99);
        remove_action('admin_footer', ['BCS_Release_045', 'admin_footer_script'], 120);
    }

    private static function request_key(int $registration_id): string {
        return 'bcs_046_org_otp_' . get_current_user_id() . '_' . $registration_id;
    }

    private static function proof_key(int $agreement_id): string {
        return 'bcs_org_proof_' . $agreement_id;
    }

    public static function ajax_send_agreement(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Brak uprawnień.'], 403);
        }
        check_ajax_referer('bcs_046', 'nonce');

        global $wpdb;
        $registration_id = absint($_POST['registration_id'] ?? 0);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,status,form_verified_at,agreement_status,agreement_id FROM ".BCS_DB::table('registrations')." WHERE id=%d",
            $registration_id
        ));

        if (!$row) wp_send_json_error(['message' => 'Nie znaleziono zgłoszenia.'], 404);
        if ((string)$row->status === 'cancelled') wp_send_json_error(['message' => 'Zgłoszenie jest anulowane.'], 409);
        if (empty($row->form_verified_at)) wp_send_json_error(['message' => 'Najpierw zaakceptuj formularz obozowy.'], 409);
        if (in_array((string)$row->agreement_status, ['parent_signed', 'accepted'], true)) {
            wp_send_json_error(['message' => 'Umowa została już podpisana przez rodzica albo przez obie strony.'], 409);
        }

        // Wywołujemy proces bezpośrednio. Nie przechodzimy przez historyczne bramki
        // AJAX wymagające podpisu Organizatora przed wysłaniem dokumentu.
        $ok = BCS_Workflow_Engine::execute('send_agreement', $registration_id);
        if (!$ok) {
            $message = method_exists('BCS_Workflow', 'last_error') ? BCS_Workflow::last_error() : '';
            wp_send_json_error(['message' => $message ?: 'Nie udało się wysłać umowy do rodzica. Sprawdź etap zgłoszenia i ustawienia komunikacji.'], 409);
        }

        $after = $wpdb->get_row($wpdb->prepare(
            "SELECT status,agreement_status,agreement_id FROM ".BCS_DB::table('registrations')." WHERE id=%d",
            $registration_id
        ));
        if (!$after || (string)$after->agreement_status !== 'pending' || empty($after->agreement_id)) {
            BCS_Utils::log('agreement_send_046_state_error', [
                'status' => (string)($after->status ?? ''),
                'agreement_status' => (string)($after->agreement_status ?? ''),
            ], $registration_id, (int)($after->agreement_id ?? 0));
            wp_send_json_error(['message' => 'Wysyłka została uruchomiona, ale zgłoszenie nie przeszło do etapu oczekiwania na podpis rodzica.'], 500);
        }

        BCS_Utils::log('agreement_sent_to_parent_046', ['parent_first' => true], $registration_id, (int)$after->agreement_id);
        wp_send_json_success(['message' => 'Umowa została przekazana do podpisu rodzicowi. Organizator podpisze ją dopiero po podpisie rodzica.']);
    }

    public static function ajax_signature_state(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Brak uprawnień.'], 403);
        check_ajax_referer('bcs_046', 'nonce');

        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('absint', (array)($_POST['registration_ids'] ?? [])))));
        if (!$ids) wp_send_json_success(['eligible' => []]);

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $query = $wpdb->prepare(
            "SELECT id FROM ".BCS_DB::table('registrations')." WHERE id IN ($placeholders) AND agreement_status='parent_signed' AND status<>'cancelled'",
            ...$ids
        );
        wp_send_json_success(['eligible' => array_map('intval', $wpdb->get_col($query))]);
    }

    public static function ajax_send_organizer_otp(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Brak uprawnień.'], 403);
        check_ajax_referer('bcs_046', 'nonce');

        global $wpdb;
        $registration_id = absint($_POST['registration_id'] ?? 0);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.id,r.status,r.agreement_status,r.agreement_id,a.agreement_number,o.id organizer_id,o.name organizer_name,o.phone organizer_phone
             FROM ".BCS_DB::table('registrations')." r
             JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id
             LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id
             LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id
             WHERE r.id=%d",
            $registration_id
        ));

        if (!$row) wp_send_json_error(['message' => 'Nie znaleziono zgłoszenia.'], 404);
        if ((string)$row->agreement_status !== 'parent_signed') {
            wp_send_json_error(['message' => 'Najpierw umowę musi podpisać rodzic.'], 409);
        }
        if (empty($row->agreement_id)) wp_send_json_error(['message' => 'Brak umowy powiązanej ze zgłoszeniem.'], 409);

        $phone = BCS_Utils::normalize_phone((string)$row->organizer_phone);
        if (strlen(preg_replace('/\D+/', '', $phone)) < 9) {
            wp_send_json_error([
                'message' => 'Organizator nie ma zapisanego prawidłowego numeru telefonu. Uzupełnij numer w module Organizatorzy.',
                'organizer_id' => (int)$row->organizer_id,
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
            wp_send_json_error(['message' => 'Nie udało się wysłać SMS: '.(string)($sent['error'] ?? 'Nieznany błąd.')], 500);
        }

        set_transient(self::request_key($registration_id), [
            'agreement_id' => (int)$row->agreement_id,
            'phone' => $phone,
            'code_hash' => wp_hash_password($code),
            'sms_id' => (string)($sent['message_id'] ?? ''),
            'expires' => time() + self::OTP_TTL,
            'attempts' => 0,
        ], self::OTP_TTL);

        wp_send_json_success([
            'message' => 'Kod został wysłany do Organizatora.',
            'phone' => BCS_Utils::mask_phone($phone),
            'organizer' => (string)$row->organizer_name,
        ]);
    }

    public static function ajax_verify_organizer_otp(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Brak uprawnień.'], 403);
        check_ajax_referer('bcs_046', 'nonce');

        global $wpdb;
        $registration_id = absint($_POST['registration_id'] ?? 0);
        $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
        if (strlen($code) !== 6) wp_send_json_error(['message' => 'Wpisz pełny 6-cyfrowy kod SMS.'], 400);

        $data = get_transient(self::request_key($registration_id));
        if (!is_array($data) || empty($data['agreement_id'])) {
            wp_send_json_error(['message' => 'Kod wygasł albo nie został wysłany.'], 410);
        }
        if ((int)($data['expires'] ?? 0) < time()) {
            delete_transient(self::request_key($registration_id));
            wp_send_json_error(['message' => 'Kod wygasł. Wyślij nowy.'], 410);
        }

        $attempts = (int)($data['attempts'] ?? 0);
        if ($attempts >= 5) {
            delete_transient(self::request_key($registration_id));
            wp_send_json_error(['message' => 'Przekroczono liczbę prób. Wyślij nowy kod.'], 429);
        }
        if (!wp_check_password($code, (string)$data['code_hash'])) {
            $data['attempts'] = $attempts + 1;
            set_transient(self::request_key($registration_id), $data, max(1, (int)$data['expires'] - time()));
            wp_send_json_error(['message' => 'Kod jest nieprawidłowy.'], 400);
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*,a.status agreement_record_status FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id WHERE r.id=%d",
            $registration_id
        ));
        if (!$row || (string)$row->agreement_status !== 'parent_signed') {
            wp_send_json_error(['message' => 'Najpierw umowę musi podpisać rodzic.'], 409);
        }

        $user = wp_get_current_user();
        $now = BCS_Utils::now();
        $proof = [
            'accepted_at' => $now,
            'phone' => (string)$data['phone'],
            'sms_id' => (string)$data['sms_id'],
            'user' => trim($user->display_name.' (ID '.get_current_user_id().')'),
            'registration_id' => $registration_id,
        ];
        update_option(self::proof_key((int)$data['agreement_id']), $proof, false);
        delete_transient(self::request_key($registration_id));

        $due = (new DateTimeImmutable('+7 days', BCS_Utils::timezone()))->format('Y-m-d');
        $updated = $wpdb->update(BCS_DB::table('registrations'), [
            'agreement_status' => 'accepted',
            'status' => 'awaiting_bank_payment',
            'payment_due_date' => $due,
            'updated_at' => $now,
        ], ['id' => $registration_id]);
        if ($updated === false) wp_send_json_error(['message' => 'Nie udało się zakończyć podpisywania umowy.'], 500);

        if (class_exists('BCS_Workflow_Engine')) BCS_Workflow_Engine::refresh_invoice_readiness($registration_id);
        if (class_exists('BCS_Communication_Engine')) {
            BCS_Communication_Engine::send_to_registration($registration_id, 'agreement_signed', 'email');
            BCS_Communication_Engine::send_to_registration($registration_id, 'payment_reminder', 'both');
        }

        BCS_Utils::log('organizer_agreement_otp_verified', [
            'phone' => BCS_Utils::mask_phone((string)$proof['phone']),
            'sms_message_id' => $proof['sms_id'],
            'workflow' => 'parent_first_046',
        ], $registration_id, (int)$data['agreement_id']);

        wp_send_json_success(['message' => 'Umowa została podpisana przez Organizatora. Dokument udostępniono rodzicowi i uruchomiono etap oczekiwania na płatność.']);
    }

    public static function admin_head_script(): void {
        if (!current_user_can('manage_options')) return;
        $page = sanitize_key($_GET['page'] ?? '');
        if ($page !== 'bcs-registrations') return;
        $nonce = wp_create_nonce('bcs_046');
        ?>
        <script>
        (()=>{
            const nonce=<?php echo wp_json_encode($nonce); ?>;
            const ajax=window.ajaxurl||'<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            let stateTimer=0,stateBusy=false;

            const normalize=text=>(text||'').toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/\s+/g,' ').trim();
            function idOf(el){
                if(!el)return 0;
                const vals=[];
                if(el.dataset){vals.push(el.dataset.registrationId,el.dataset.id);}
                const row=el.closest&&el.closest('tr[data-id],tr[data-registration-id],[data-registration-id],[data-id],form');
                if(row){
                    vals.push(row.dataset&&row.dataset.registrationId,row.dataset&&row.dataset.id);
                    for(const name of ['registration_id','id']){const input=row.querySelector&&row.querySelector('[name="'+name+'"]');if(input)vals.push(input.value);}
                }
                const href=el.getAttribute&&el.getAttribute('href');
                if(href){try{const u=new URL(href,location.href);vals.push(u.searchParams.get('registration_id'),u.searchParams.get('view'),u.searchParams.get('id'));}catch(e){}}
                return parseInt(vals.find(v=>/^\d+$/.test(v||''))||'0',10);
            }
            async function post(action,data={}){
                const fd=new FormData();fd.append('action',action);fd.append('nonce',nonce);
                Object.entries(data).forEach(([key,value])=>Array.isArray(value)?value.forEach(item=>fd.append(key+'[]',item)):fd.append(key,value));
                const response=await fetch(ajax,{method:'POST',credentials:'same-origin',cache:'no-store',body:fd});
                return response.json();
            }
            async function sendToParent(button,id){
                if(button.dataset.bcs046Busy==='1')return;
                button.dataset.bcs046Busy='1';button.disabled=true;button.setAttribute('aria-busy','true');
                const oldText=button.innerText||button.value||'';
                if(button.tagName==='INPUT')button.value='Wysyłanie…';else button.innerText='Wysyłanie…';
                try{
                    const result=await post('bcs_046_send_agreement',{registration_id:id});
                    if(!result.success){alert(result.data&&result.data.message||'Nie udało się wysłać umowy do rodzica.');return;}
                    alert(result.data.message||'Umowa została przekazana rodzicowi do podpisu.');
                    location.reload();
                }catch(error){alert('Wystąpił błąd połączenia podczas wysyłania umowy.');}
                finally{
                    button.dataset.bcs046Busy='0';button.disabled=false;button.removeAttribute('aria-busy');
                    if(button.tagName==='INPUT')button.value=oldText;else button.innerText=oldText;
                }
            }

            // Rejestrujemy listener w nagłówku i w fazie capture. Dzięki temu żaden
            // historyczny modal podpisu Organizatora nie przejmie akcji wysłania umowy.
            document.addEventListener('click',event=>{
                const button=event.target.closest&&event.target.closest('button,a,input[type="submit"]');
                if(!button||button.classList.contains('bcs-org-sign-046'))return;
                const text=normalize(button.innerText||button.value||'');
                if(!text.includes('wyslij umowe'))return;
                const id=idOf(button);if(!id)return;
                event.preventDefault();event.stopPropagation();event.stopImmediatePropagation();
                sendToParent(button,id);
            },true);

            function removeLegacyButtons(){
                document.querySelectorAll('.bcs-org-sign-041,.bcs-org-sign-045').forEach(node=>node.remove());
            }
            function addSignButton(host,id){
                if(!host||host.querySelector('.bcs-org-sign-046'))return;
                const button=document.createElement('button');
                button.type='button';button.className='button button-primary bcs-org-sign-046';
                button.dataset.registrationId=id;button.textContent='Podpisz umowę przez SMS';
                host.appendChild(button);
            }
            async function refreshSignatureButtons(){
                if(stateBusy)return;stateBusy=true;
                try{
                    removeLegacyButtons();
                    const ids=[];
                    document.querySelectorAll('tr[data-id],tr[data-registration-id]').forEach(row=>{const id=idOf(row);if(id)ids.push(id);});
                    const viewId=parseInt(new URL(location.href).searchParams.get('view')||'0',10);if(viewId)ids.push(viewId);
                    const unique=[...new Set(ids)];if(!unique.length)return;
                    const result=await post('bcs_046_signature_state',{registration_ids:unique});
                    if(!result.success)return;
                    const eligible=new Set((result.data.eligible||[]).map(Number));
                    document.querySelectorAll('.bcs-org-sign-046').forEach(button=>{if(!eligible.has(Number(button.dataset.registrationId)))button.remove();});
                    eligible.forEach(id=>{
                        const row=[...document.querySelectorAll('tr[data-id],tr[data-registration-id]')].find(node=>idOf(node)===id);
                        if(row)addSignButton(row.querySelector('[data-bcs-col="actions"]')||row.querySelector('td:last-child'),id);
                        if(viewId===id)addSignButton(document.querySelector('.bcs-quick-actions .bcs-crm-buttons')||document.querySelector('.bcs-crm-buttons')||document.querySelector('.bcs-quick-actions'),id);
                    });
                }finally{stateBusy=false;}
            }
            function scheduleRefresh(){clearTimeout(stateTimer);stateTimer=setTimeout(refreshSignatureButtons,180);}

            document.addEventListener('click',async event=>{
                const button=event.target.closest&&event.target.closest('.bcs-org-sign-046');if(!button)return;
                event.preventDefault();event.stopPropagation();event.stopImmediatePropagation();
                const id=idOf(button)||parseInt(button.dataset.registrationId||'0',10);if(!id)return;
                button.disabled=true;button.textContent='Wysyłanie kodu…';
                try{
                    let result=await post('bcs_046_organizer_otp_send',{registration_id:id});
                    if(!result.success){alert(result.data&&result.data.message||'Nie udało się wysłać kodu SMS do Organizatora.');return;}
                    const code=prompt('Kod SMS wysłano do '+(result.data.organizer||'Organizatora')+' na numer '+(result.data.phone||'zapisany w systemie')+'. Wpisz 6-cyfrowy kod:');
                    if(!code)return;
                    result=await post('bcs_046_organizer_otp_verify',{registration_id:id,code});
                    if(!result.success){alert(result.data&&result.data.message||'Kod jest nieprawidłowy.');return;}
                    alert(result.data.message||'Umowa została podpisana przez Organizatora.');
                    location.reload();
                }catch(error){alert('Wystąpił błąd połączenia podczas podpisywania umowy.');}
                finally{button.disabled=false;button.textContent='Podpisz umowę przez SMS';}
            },true);

            new MutationObserver(scheduleRefresh).observe(document.documentElement,{childList:true,subtree:true});
            if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',refreshSignatureButtons,{once:true});else refreshSignatureButtons();
        })();
        </script>
        <?php
    }
}
