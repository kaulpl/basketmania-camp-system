<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_02024 {
    public static function init(): void {
        add_action('wp_ajax_bcs_mark_payment_02024', [self::class, 'mark_payment']);
        add_action('admin_head', [self::class, 'styles'], 900);
        add_action('admin_footer', [self::class, 'scripts'], 900);
    }

    public static function mark_payment(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        }
        $id=absint($_POST['registration_id']??0);
        $nonce=sanitize_text_field(wp_unslash($_POST['nonce']??''));
        $date=sanitize_text_field(wp_unslash($_POST['payment_date']??''));
        if(!$id || !wp_verify_nonce($nonce,'bcs_payment_date_02024_'.$id)){
            wp_send_json_error(['message'=>'Sesja wygasła. Odśwież stronę i spróbuj ponownie.'],403);
        }
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){
            wp_send_json_error(['message'=>'Wybierz prawidłową datę płatności.'],422);
        }
        try{
            $selected=new DateTimeImmutable($date,BCS_Utils::timezone());
            $today=new DateTimeImmutable('today',BCS_Utils::timezone());
        }catch(Throwable $e){
            wp_send_json_error(['message'=>'Wybierz prawidłową datę płatności.'],422);
        }
        if($selected->format('Y-m-d')!==$date || $selected>$today){
            wp_send_json_error(['message'=>'Data płatności nie może przypadać w przyszłości.'],422);
        }
        $paid_at=$date===BCS_Utils::today('Y-m-d')?BCS_Utils::now():$date.' 12:00:00';
        if(!BCS_Workflow_Engine::mark_bank_paid($id,$paid_at)){
            wp_send_json_error(['message'=>'Nie udało się zaksięgować wpłaty. Sprawdź etap zgłoszenia i spróbuj ponownie.'],409);
        }
        wp_send_json_success([
            'message'=>'Wpłata została zaksięgowana z datą '.wp_date('d.m.Y',strtotime($paid_at)).'.',
            'payment_date'=>$date,
        ]);
    }

    public static function styles(): void {
        if(!is_admin())return;
        ?>
        <style>
        .bcs-open-crm-icon-02024{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:38px!important;min-width:38px!important;height:34px!important;padding:0!important;background:#f97316!important;border-color:#f97316!important;color:#fff!important}
        .bcs-open-crm-icon-02024:hover,.bcs-open-crm-icon-02024:focus{background:#ea580c!important;border-color:#ea580c!important;color:#fff!important}
        .bcs-open-crm-icon-02024>span{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M13 3h7v18h-7'/%3E%3Cpath d='M3 12h12M11 8l4 4-4 4'/%3E%3C/svg%3E")!important;filter:none!important}
        .bcs-open-crm-icon-02024>span{display:block;width:22px;height:22px;background:center/contain no-repeat url("data:image/png;base64,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");filter:brightness(0) invert(1)}
        .bcs-open-crm-icon-02024>span{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M13 3h7v18h-7'/%3E%3Cpath d='M3 12h12M11 8l4 4-4 4'/%3E%3C/svg%3E")!important;filter:none!important}
        .bcs-payment-modal-02024[hidden]{display:none!important}.bcs-payment-modal-02024{position:fixed;z-index:100200;inset:0;display:flex;align-items:center;justify-content:center;padding:20px}.bcs-payment-modal-02024__backdrop{position:absolute;inset:0;background:rgba(15,23,42,.64)}.bcs-payment-modal-02024__dialog{position:relative;width:min(440px,100%);padding:26px;border-radius:16px;background:#fff;box-shadow:0 28px 80px rgba(15,23,42,.3)}.bcs-payment-modal-02024 h2{margin:0 0 8px}.bcs-payment-modal-02024 p{margin:0 0 20px;color:#64748b}.bcs-payment-modal-02024 label{display:grid;gap:7px;font-weight:700}.bcs-payment-modal-02024 input[type=date]{width:100%;min-height:44px}.bcs-payment-modal-02024__actions{display:flex;justify-content:flex-end;gap:9px;margin-top:22px}.bcs-payment-modal-02024__actions .button{min-height:38px}
        </style>
        <?php
    }

    public static function scripts(): void {
        if(!is_admin() || !current_user_can('manage_options'))return;
        $page=sanitize_key(wp_unslash($_GET['page']??''));
        if($page!=='bcs-registrations')return;
        ?>
        <script>
        (function(){
            const ajaxUrl=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const today=<?php echo wp_json_encode(BCS_Utils::today('Y-m-d')); ?>;
            let active=null;
            const modal=document.createElement('div');
            modal.className='bcs-payment-modal-02024';
            modal.hidden=true;
            modal.innerHTML='<div class="bcs-payment-modal-02024__backdrop"></div><div class="bcs-payment-modal-02024__dialog" role="dialog" aria-modal="true" aria-labelledby="bcs-payment-title-02024"><h2 id="bcs-payment-title-02024">Kiedy została wykonana płatność?</h2><p>Wskazana data zostanie zapisana przy wpłacie i umieszczona na fakturze.</p><label><span>Data płatności</span><input type="date" value="'+today+'" max="'+today+'" required></label><div class="bcs-payment-modal-02024__actions"><button type="button" class="button" data-cancel>Anuluj</button><button type="button" class="button button-primary" data-confirm>OK</button></div></div>';
            document.body.appendChild(modal);
            const input=modal.querySelector('input[type=date]');
            const confirm=modal.querySelector('[data-confirm]');
            function close(){modal.hidden=true;active=null;confirm.disabled=false;}
            function popup(message,ok){
                if(typeof window.bcsPopup0190==='function')window.bcsPopup0190(message,ok);
                else window.alert(message);
            }
            async function book(){
                if(!active || !input.value || input.value>today){popup('Data płatności nie może przypadać w przyszłości.',false);return;}
                confirm.disabled=true;
                try{
                    const response=await fetch(ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({action:'bcs_mark_payment_02024',registration_id:active.id,nonce:active.nonce,payment_date:input.value})});
                    const json=await response.json();
                    if(!response.ok||!json.success)throw new Error(json?.data?.message||'Nie udało się zaksięgować wpłaty.');
                    const message=json.data.message;
                    const card=!!new URLSearchParams(location.search).get('view');
                    close();
                    if(card && typeof window.bcsRefreshRegistrationCard02017==='function'){
                        await window.bcsRefreshRegistrationCard02017();
                        popup(message,true);
                    }else{
                        sessionStorage.setItem('bcsPaymentMessage02024',message);
                        location.reload();
                    }
                }catch(error){confirm.disabled=false;popup(error.message||'Nie udało się zaksięgować wpłaty.',false);}
            }
            document.addEventListener('click',function(event){
                const target=event.target.closest('.bcs-payment-date-action-02024');
                if(!target)return;
                event.preventDefault();event.stopImmediatePropagation();
                const form=target.closest('form');
                active={
                    id:String(target.dataset.registrationId||form?.querySelector('[name=registration_id]')?.value||''),
                    nonce:String(target.dataset.paymentNonce||form?.querySelector('[name=payment_nonce]')?.value||'')
                };
                input.value=today;input.max=today;modal.hidden=false;window.setTimeout(()=>input.focus(),0);
            },true);
            document.addEventListener('submit',function(event){
                if(!event.target.matches('.bcs-payment-date-action-02024'))return;
                event.preventDefault();event.stopImmediatePropagation();
                const form=event.target;
                active={id:String(form.querySelector('[name=registration_id]')?.value||''),nonce:String(form.querySelector('[name=payment_nonce]')?.value||'')};
                input.value=today;input.max=today;modal.hidden=false;window.setTimeout(()=>input.focus(),0);
            },true);
            modal.querySelector('[data-cancel]').addEventListener('click',close);
            modal.querySelector('.bcs-payment-modal-02024__backdrop').addEventListener('click',close);
            confirm.addEventListener('click',book);
            document.addEventListener('keydown',function(event){if(modal.hidden)return;if(event.key==='Escape')close();if(event.key==='Enter'){event.preventDefault();book();}});
            const restored=sessionStorage.getItem('bcsPaymentMessage02024');
            if(restored){sessionStorage.removeItem('bcsPaymentMessage02024');window.setTimeout(()=>popup(restored,true),100);}
        })();
        </script>
        <?php
    }
}
