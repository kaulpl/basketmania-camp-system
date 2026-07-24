<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_047 {
    private const PAYMENT_ACTIONS = ['mark_paid', 'mark_bank_paid', 'send_stripe_link', 'remind_payment'];

    public static function init(): void {
        add_action('wp_ajax_bcs_047_payment_state', [__CLASS__, 'ajax_payment_state']);

        // Twarde bramki po stronie serwera. Interfejs nie jest jedyną ochroną.
        add_action('wp_ajax_bcs_list_quick_action_02010', [__CLASS__, 'gate_list_payment_action'], 0);
        add_action('wp_ajax_bcs_send_stripe_link_02014', [__CLASS__, 'gate_stripe_action'], 0);
        add_action('admin_post_bcs_workflow_single', [__CLASS__, 'gate_single_workflow_action'], 0);
        add_action('admin_init', [__CLASS__, 'gate_legacy_post_actions'], 0);

        // Korekta widoku listy i Karty zgłoszenia po zmianie modelu podpisów.
        add_action('admin_head', [__CLASS__, 'admin_head'], 3);
    }

    private static function payment_allowed(int $registration_id): bool {
        if ($registration_id <= 0) return false;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT status,agreement_status FROM ".BCS_DB::table('registrations')." WHERE id=%d LIMIT 1",
            $registration_id
        ));
        return $row
            && (string)$row->status !== 'cancelled'
            && (string)$row->agreement_status === 'accepted';
    }

    private static function payment_locked_message(): string {
        return 'Płatność można obsługiwać dopiero po podpisaniu umowy przez Organizatora.';
    }

    public static function gate_list_payment_action(): void {
        $action = sanitize_key(wp_unslash($_POST['quick_action'] ?? ''));
        if (!in_array($action, self::PAYMENT_ACTIONS, true)) return;

        $registration_id = absint($_POST['registration_id'] ?? 0);
        if (!self::payment_allowed($registration_id)) {
            wp_send_json_error(['message' => self::payment_locked_message()], 409);
        }
    }

    public static function gate_stripe_action(): void {
        $registration_id = absint($_POST['registration_id'] ?? 0);
        if (!self::payment_allowed($registration_id)) {
            wp_send_json_error(['message' => self::payment_locked_message()], 409);
        }
    }

    public static function gate_single_workflow_action(): void {
        $action = sanitize_key(wp_unslash($_GET['workflow'] ?? ''));
        if (!in_array($action, ['mark_bank_paid', 'send_stripe_link', 'remind_payment'], true)) return;

        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        $registration_id = absint($_GET['registration_id'] ?? 0);
        check_admin_referer('bcs_workflow_single_'.$registration_id.'_'.$action);

        if (!self::payment_allowed($registration_id)) {
            wp_safe_redirect(add_query_arg([
                'page' => 'bcs-registrations',
                'view' => $registration_id,
                'failed' => 1,
                'payment_locked' => 1,
            ], admin_url('admin.php')));
            exit;
        }
    }

    public static function gate_legacy_post_actions(): void {
        if (!current_user_can('manage_options') || wp_doing_ajax()) return;

        $registration_id = absint($_POST['registration_id'] ?? 0);
        $crm_action = sanitize_key(wp_unslash($_POST['bcs_crm_action'] ?? ''));
        if ($registration_id && $crm_action === 'mark_paid' && !self::payment_allowed($registration_id)) {
            check_admin_referer('bcs_crm_'.$registration_id);
            self::redirect_locked($registration_id, sanitize_key(wp_unslash($_POST['return_to'] ?? 'card')));
        }

        $workflow_action = sanitize_key(wp_unslash($_POST['bcs_workflow_action'] ?? ''));
        if (!in_array($workflow_action, ['mark_bank_paid', 'send_stripe_link', 'remind_payment'], true)) return;

        check_admin_referer('bcs_workflow_action');
        $ids = array_values(array_filter(array_map('absint', (array)($_POST['registration_ids'] ?? []))));
        if (!$ids && $registration_id) $ids = [$registration_id];
        foreach ($ids as $id) {
            if (!self::payment_allowed($id)) self::redirect_locked($id, 'list');
        }
    }

    private static function redirect_locked(int $registration_id, string $return_to): void {
        $args = [
            'page' => 'bcs-registrations',
            'crm_done' => 0,
            'payment_locked' => 1,
        ];
        if ($return_to !== 'list') $args['view'] = $registration_id;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public static function ajax_payment_state(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Brak uprawnień.'], 403);
        check_ajax_referer('bcs_047', 'nonce');

        $ids = array_values(array_unique(array_filter(array_map('absint', (array)($_POST['registration_ids'] ?? [])))));
        if (!$ids) wp_send_json_success(['states' => []]);

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,status,agreement_status FROM ".BCS_DB::table('registrations')." WHERE id IN ($placeholders)",
            ...$ids
        ));

        $states = [];
        foreach ($rows as $row) {
            $agreement_status = (string)$row->agreement_status;
            $states[(int)$row->id] = [
                'payment_allowed' => (string)$row->status !== 'cancelled' && $agreement_status === 'accepted',
                'parent_signed' => $agreement_status === 'parent_signed',
                'agreement_status' => $agreement_status,
            ];
        }
        wp_send_json_success(['states' => $states]);
    }

    public static function admin_head(): void {
        if (!current_user_can('manage_options')) return;
        $page = sanitize_key($_GET['page'] ?? '');
        if ($page !== 'bcs-registrations') return;
        $nonce = wp_create_nonce('bcs_047');
        ?>
        <style>
            tr[data-status="agreement_parent_signed"] .bcs-payment-date-action-02024,
            tr[data-status="agreement_parent_signed"] .bcs-stripe-link-action-02014 { display:none!important; }
            .bcs-payment-locked-047{display:block;padding:10px 12px;border-radius:8px;background:#fff7ed;border-left:4px solid #f97316;color:#9a3412;font-weight:600}
        </style>
        <script>
        (()=>{
            const nonce=<?php echo wp_json_encode($nonce); ?>;
            const ajax=window.ajaxurl||<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const lockedMessage=<?php echo wp_json_encode(self::payment_locked_message(), JSON_UNESCAPED_UNICODE); ?>;
            let states=new Map(),timer=0,busy=false;

            const normalize=value=>(value||'').toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/\s+/g,' ').trim();
            function idOf(element){
                if(!element)return 0;
                const values=[];
                if(element.dataset)values.push(element.dataset.registrationId,element.dataset.id);
                const box=element.closest&&element.closest('tr[data-id],tr[data-registration-id],[data-registration-id],[data-id],form');
                if(box){
                    values.push(box.dataset&&box.dataset.registrationId,box.dataset&&box.dataset.id);
                    for(const name of ['registration_id','id']){const input=box.querySelector&&box.querySelector('[name="'+name+'"]');if(input)values.push(input.value);}
                }
                const href=element.getAttribute&&element.getAttribute('href');
                if(href){try{const url=new URL(href,location.href);values.push(url.searchParams.get('registration_id'),url.searchParams.get('view'),url.searchParams.get('id'));}catch(error){}}
                return parseInt(values.find(value=>/^\d+$/.test(value||''))||'0',10);
            }
            async function post(action,data={}){
                const form=new FormData();form.append('action',action);form.append('nonce',nonce);
                Object.entries(data).forEach(([key,value])=>Array.isArray(value)?value.forEach(item=>form.append(key+'[]',item)):form.append(key,value));
                const response=await fetch(ajax,{method:'POST',credentials:'same-origin',cache:'no-store',body:form});
                return response.json();
            }
            function paymentAction(element){
                if(!element)return false;
                const text=normalize(element.innerText||element.value||'');
                if(text.includes('zaksieguj wplate')||text.includes('wyslij link stripe')||text.includes('przypomnij o platnosci'))return true;
                const href=element.getAttribute&&element.getAttribute('href')||'';
                if(/workflow=(mark_bank_paid|send_stripe_link|remind_payment)/.test(href))return true;
                const form=element.closest&&element.closest('form');
                if(form){
                    const values=[...form.querySelectorAll('[name="bcs_crm_action"],[name="quick_action"],[name="bcs_workflow_action"]')].map(input=>input.value);
                    if(values.some(value=>['mark_paid','mark_bank_paid','send_stripe_link','remind_payment'].includes(value)))return true;
                }
                return false;
            }
            function removePaymentActions(scope){
                scope.querySelectorAll('button,a,input[type="submit"]').forEach(control=>{
                    if(!paymentAction(control))return;
                    const form=control.closest('form');
                    (form||control).remove();
                });
                scope.querySelectorAll('.bcs-payment-action-row').forEach(row=>{if(!row.querySelector('button,a,input[type="submit"]'))row.remove();});
            }
            function addLockedNotice(host){
                if(!host||host.querySelector('.bcs-payment-locked-047'))return;
                const notice=document.createElement('span');notice.className='bcs-payment-locked-047';notice.textContent=lockedMessage;host.appendChild(notice);
            }
            function applyState(id,state){
                const row=[...document.querySelectorAll('tr[data-id],tr[data-registration-id]')].find(node=>idOf(node)===id);
                if(row&&!state.payment_allowed){
                    removePaymentActions(row);
                    if(state.parent_signed){
                        const badge=row.querySelector('[data-bcs-col="status"] .bcs-badge');
                        if(badge)badge.textContent='Umowa podpisana przez rodzica – oczekuje na podpis Organizatora';
                        row.dataset.status='agreement_parent_signed';
                    }
                }
                const viewId=parseInt(new URL(location.href).searchParams.get('view')||'0',10);
                if(viewId===id&&!state.payment_allowed){
                    const actions=document.querySelector('.bcs-quick-actions .bcs-crm-buttons')||document.querySelector('.bcs-crm-buttons')||document.querySelector('.bcs-quick-actions');
                    if(actions){removePaymentActions(actions);addLockedNotice(actions);}
                    if(state.parent_signed){
                        document.querySelectorAll('.bcs-badge').forEach(badge=>{if(normalize(badge.textContent)==='agreement_parent_signed')badge.textContent='Umowa podpisana przez rodzica – oczekuje na podpis Organizatora';});
                    }
                }
            }
            async function refresh(){
                if(busy)return;busy=true;
                try{
                    const ids=[];
                    document.querySelectorAll('tr[data-id],tr[data-registration-id]').forEach(row=>{const id=idOf(row);if(id)ids.push(id);});
                    const viewId=parseInt(new URL(location.href).searchParams.get('view')||'0',10);if(viewId)ids.push(viewId);
                    const unique=[...new Set(ids)];if(!unique.length)return;
                    const result=await post('bcs_047_payment_state',{registration_ids:unique});if(!result.success)return;
                    states=new Map(Object.entries(result.data.states||{}).map(([id,state])=>[Number(id),state]));
                    states.forEach((state,id)=>applyState(id,state));
                }finally{busy=false;}
            }
            function schedule(){clearTimeout(timer);timer=setTimeout(refresh,120);}

            document.addEventListener('click',event=>{
                const control=event.target.closest&&event.target.closest('button,a,input[type="submit"]');
                if(!control||!paymentAction(control))return;
                const id=idOf(control);const state=states.get(id);
                if(state&&!state.payment_allowed){event.preventDefault();event.stopPropagation();event.stopImmediatePropagation();alert(lockedMessage);}
            },true);
            document.addEventListener('submit',event=>{
                const form=event.target;if(!(form instanceof HTMLFormElement))return;
                const control=form.querySelector('button[type="submit"],input[type="submit"]');
                if(!paymentAction(control))return;
                const id=idOf(form);const state=states.get(id);
                if(state&&!state.payment_allowed){event.preventDefault();event.stopPropagation();alert(lockedMessage);}
            },true);

            new MutationObserver(schedule).observe(document.documentElement,{childList:true,subtree:true});
            if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',refresh,{once:true});else refresh();
        })();
        </script>
        <?php
    }
}
