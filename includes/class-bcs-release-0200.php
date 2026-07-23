<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_0200 {
    public static function init(): void {
        add_action('wp_ajax_bcs_generate_invoice_0200', [self::class, 'generate_invoice']);
        add_action('admin_head', [self::class, 'admin_styles'], 1000);
        add_action('admin_footer', [self::class, 'admin_scripts'], 1000);
        add_action('wp_footer', [self::class, 'portal_scripts'], 1000);
    }

    public static function generate_invoice(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Brak uprawnień.'], 403);
        }
        check_ajax_referer('bcs_generate_invoice_0200', 'nonce');
        $registration_id = absint($_POST['registration_id'] ?? 0);
        if (!$registration_id) {
            wp_send_json_error(['message' => 'Nieprawidłowe zgłoszenie.'], 422);
        }

        global $wpdb;
        $invoice = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . BCS_DB::table('invoices') . ' WHERE registration_id=%d ORDER BY id DESC LIMIT 1',
            $registration_id
        ));
        if (!$invoice) {
            try {
                $result = BCS_Workflow_Engine::execute('generate_invoice', $registration_id);
            } catch (Throwable $error) {
                BCS_Utils::log('invoice_ajax_failed', [
                    'error' => $error->getMessage(),
                    'type' => get_class($error),
                ], $registration_id, null);
                wp_send_json_error([
                    'message' => 'Nie udało się wygenerować faktury z powodu błędu serwera. Szczegóły zapisano w dzienniku systemu.',
                ], 500);
            }
            $invoice = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . BCS_DB::table('invoices') . ' WHERE registration_id=%d ORDER BY id DESC LIMIT 1',
                $registration_id
            ));
            if (!$result || !$invoice) {
                wp_send_json_error([
                    'message' => 'Faktura nie została utworzona. Sprawdź, czy zgłoszenie jest w pełni opłacone i spełnia warunki procesu.',
                ], 500);
            }
        }

        $registration = $wpdb->get_row($wpdb->prepare(
            'SELECT invoice_status FROM ' . BCS_DB::table('registrations') . ' WHERE id=%d',
            $registration_id
        ));
        if ($registration && empty($registration->invoice_status)) {
            $wpdb->update(
                BCS_DB::table('registrations'),
                ['invoice_status' => 'generated', 'updated_at' => BCS_Utils::now()],
                ['id' => $registration_id]
            );
        }

        $view_url = wp_nonce_url(
            admin_url('admin-post.php?action=bcs_invoice_view&invoice_id=' . (int) $invoice->id),
            'bcs_invoice_view_' . (int) $invoice->id
        );
        $download_url = wp_nonce_url(
            admin_url('admin-post.php?action=bcs_invoice_download&invoice_id=' . (int) $invoice->id),
            'bcs_invoice_download_' . (int) $invoice->id
        );
        wp_send_json_success([
            'message' => 'Faktura została wygenerowana.',
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'view_url' => $view_url,
            'download_url' => $download_url,
        ]);
    }

    public static function admin_styles(): void {
        if (!is_admin()) return;
        ?>
        <style>
        .bcs-admin-ui-0200 button,.bcs-admin-ui-0200 input[type="button"],.bcs-admin-ui-0200 input[type="submit"],.bcs-admin-ui-0200 input[type="reset"],.bcs-admin-ui-0200 a.button,.bcs-admin-ui-0200 .button,.bcs-admin-ui-0200 [class*="bcs-btn"],.bcs-admin-ui-0200 [class*="bcs-action"]{border-radius:8px!important}
        .bcs-settings-section-0200>summary{display:flex!important;align-items:center!important;justify-content:flex-start!important;gap:10px!important}
        .bcs-settings-section-0200>summary:after{margin-left:auto!important}
        .bcs-settings-section-0200>summary .bcs-settings-hint{margin-left:0!important;text-align:left!important}
        .bcs-invoice-done-0200{background:#15803d!important;border-color:#15803d!important;color:#fff!important;pointer-events:none}
        .bcs-invoice-summary-0200{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px}
        tr.bcs-ajax-updated-02013>td{animation:bcs-row-updated-02013 1.6s ease}
        @keyframes bcs-row-updated-02013{0%,35%{background:#dcfce7}100%{background:transparent}}
        </style>
        <?php
    }

    public static function admin_scripts(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        $view = absint($_GET['view'] ?? 0);
        ?>
        <script>
        (function(){
            const page=<?php echo wp_json_encode($page); ?>;
            const view=<?php echo (int) $view; ?>;
            const ajaxUrl=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const nonce=<?php echo wp_json_encode(wp_create_nonce('bcs_generate_invoice_0200')); ?>;

            if(page && page.indexOf('bcs-')===0) document.body.classList.add('bcs-admin-ui-0200');

            function popup(message,ok){
                if(typeof window.bcsPopup0190==='function'){window.bcsPopup0190(message,ok);return;}
                const result=document.getElementById('bcs-result-popup-0190');
                if(!result)return;
                result.className='bcs-result-popup-0190 '+(ok?'success':'error')+' show';
                const icon=result.querySelector('.bcs-result-popup-0190__icon');
                const title=result.querySelector('h3');
                if(icon)icon.textContent=ok?'✓':'×';
                if(title)title.textContent=message;
                window.setTimeout(()=>result.classList.remove('show'),2000);
            }
            function responseMessage(documentNode,fallback,ok){
                const selector=ok?'.notice-success p,.notice-warning p':'.notice-error p';
                const message=documentNode?.querySelector(selector)?.textContent?.trim();
                return message||fallback;
            }
            function replaceRegistrationCard(documentNode){
                const current=document.querySelector('.bcs-crm-layout');
                const fresh=documentNode?.querySelector('.bcs-crm-layout');
                if(!current||!fresh)return false;
                current.replaceWith(fresh);
                if(typeof window.bcsRestoreQuickActions0191==='function')window.bcsRestoreQuickActions0191();
                window.setTimeout(()=>{
                    if(typeof window.bcsRestoreQuickActions0191==='function')window.bcsRestoreQuickActions0191();
                },50);
                return true;
            }
            async function refreshRegistrationCard(){
                const response=await fetch(window.location.href,{method:'GET',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
                if(!response.ok)throw new Error('Akcja została wykonana, ale nie udało się odświeżyć Karty Zgłoszenia.');
                const html=await response.text();
                const parsed=new DOMParser().parseFromString(html,'text/html');
                if(!replaceRegistrationCard(parsed))throw new Error('Akcja została wykonana, ale serwer nie zwrócił aktualnej Karty Zgłoszenia.');
                return parsed;
            }
            window.bcsRefreshRegistrationCard02017=refreshRegistrationCard;

            async function runCardAction(target,request,label){
                const control=target.matches('button,a')?target:target.querySelector('button[type="submit"],button:not([type]),input[type="submit"]');
                if(control)control.setAttribute('aria-busy','true');
                if('disabled' in (control||{}))control.disabled=true;
                try{
                    const response=await fetch(request.url,request.options);
                    const finalUrl=new URL(response.url||request.url,window.location.href);
                    const html=await response.text();
                    const contentType=response.headers.get('content-type')||'';
                    let message='';
                    if(contentType.includes('application/json')){
                        let json;
                        try{json=JSON.parse(html)}catch(error){throw new Error('Serwer zwrócił nieprawidłową odpowiedź.');}
                        if(!response.ok||!json.success)throw new Error(json?.data?.message||'Nie udało się wykonać działania.');
                        message=json?.data?.message||label;
                        await refreshRegistrationCard();
                    }else{
                        const parsed=new DOMParser().parseFromString(html,'text/html');
                        const failed=finalUrl.searchParams.get('crm_done')==='0'
                            || finalUrl.searchParams.get('done')==='0'
                            || !!parsed.querySelector('.notice-error');
                        if(!response.ok||failed)throw new Error(responseMessage(parsed,'Nie udało się wykonać działania.',false));
                        if(!replaceRegistrationCard(parsed))await refreshRegistrationCard();
                        message=responseMessage(parsed,label,true);
                    }
                    popup(message,true);
                }catch(error){
                    popup(error.message||'Nie udało się wykonać działania.',false);
                    if('disabled' in (control||{}))control.disabled=false;
                }finally{
                    if(control)control.removeAttribute('aria-busy');
                }
            }

            document.addEventListener('submit',function(event){
                if(page!=='bcs-registrations'||!view)return;
                const form=event.target;
                if(!form.matches('.bcs-quick-actions form,.bcs-form-verification form'))return;
                const submitter=event.submitter||form.querySelector('button[type="submit"],button:not([type]),input[type="submit"]');
                if(!submitter)return;
                event.preventDefault();
                event.stopImmediatePropagation();
                if(form.dataset.confirm&&!window.confirm(form.dataset.confirm))return;
                const label=(submitter.textContent||submitter.value||'Działanie').trim()+' — wykonano.';
                if(form.matches('.bcs-stripe-link-action-02014')){
                    runCardAction(submitter,{
                        url:ajaxUrl,
                        options:{
                            method:'POST',credentials:'same-origin',
                            headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
                            body:new URLSearchParams({
                                action:'bcs_send_stripe_link_02014',
                                registration_id:String(form.querySelector('[name="registration_id"]')?.value||''),
                                nonce:String(form.querySelector('[name="nonce"]')?.value||'')
                            })
                        }
                    },label);
                    return;
                }
                runCardAction(submitter,{
                    url:form.action||window.location.href,
                    options:{
                        method:(form.method||'POST').toUpperCase(),
                        credentials:'same-origin',
                        headers:{'X-Requested-With':'XMLHttpRequest'},
                        body:new FormData(form)
                    }
                },label);
            },true);
            document.addEventListener('click',function(event){
                if(page!=='bcs-registrations'||!view)return;
                const link=event.target.closest('.bcs-quick-actions a[href*="bcs_workflow_single"]');
                if(!link)return;
                event.preventDefault();
                event.stopImmediatePropagation();
                runCardAction(link,{
                    url:link.href,
                    options:{method:'GET',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}
                },(link.textContent||'Działanie').trim()+' — wykonano.');
            },true);
            function registrationId(form){
                return form.querySelector('[name="registration_id"]')?.value
                    || form.querySelector('[name="registration_ids[]"]')?.value
                    || view || '';
            }
            function isInvoiceForm(form,submitter){
                const action=form.querySelector('[name="bcs_crm_action"]')?.value
                    || form.querySelector('[name="bcs_workflow_action"]')?.value
                    || submitter?.value || '';
                return action==='invoice_generate' || action==='generate_invoice';
            }
            function applyInvoiceState(data,button){
                button.disabled=true;
                button.classList.add('bcs-invoice-done-0200');
                button.innerHTML='<span class="dashicons dashicons-yes-alt"></span> Wykonano';
                let summary=[...document.querySelectorAll('section,.bcs-panel,.bcs-card')].find(x=>/Podsumowanie/i.test(x.querySelector('h2,h3')?.textContent||''));
                if(summary && !summary.querySelector('.bcs-invoice-summary-0200')){
                    const row=document.createElement('div');
                    row.className='bcs-invoice-summary-0200';
                    row.innerHTML='<strong>Faktura '+String(data.invoice_number||'')+'</strong>'
                        +'<button type="button" class="button bcs-invoice-preview" data-url="'+String(data.view_url||'')+'"><span class="dashicons dashicons-visibility"></span> Podgląd</button>'
                        +'<a class="button" href="'+String(data.download_url||'')+'"><span class="dashicons dashicons-download"></span> Pobierz PDF</a>';
                    summary.appendChild(row);
                }
            }
            document.addEventListener('submit',async function(event){
                const form=event.target;
                const submitter=event.submitter;
                if(page!=='bcs-registrations'||!submitter||!isInvoiceForm(form,submitter))return;
                if(!view&&form.matches('.bcs-list-action'))return;
                event.preventDefault();
                event.stopImmediatePropagation();
                const id=registrationId(form);
                submitter.disabled=true;
                try{
                    const response=await fetch(ajaxUrl,{
                        method:'POST',credentials:'same-origin',
                        headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
                        body:new URLSearchParams({action:'bcs_generate_invoice_0200',nonce,registration_id:String(id)})
                    });
                    const responseText=await response.text();
                    let json;
                    try{
                        json=JSON.parse(responseText);
                    }catch(parseError){
                        throw new Error('Serwer zwrócił nieprawidłową odpowiedź. Sprawdź dziennik błędów lub skontaktuj się z administratorem.');
                    }
                    if(!response.ok||!json.success)throw new Error(json?.data?.message||'Nie udało się wygenerować faktury.');
                    applyInvoiceState(json.data,submitter);
                    popup(json.data.message,true);
                }catch(error){
                    submitter.disabled=false;
                    popup(error.message,false);
                }
            },true);

            async function runListQuickAction(element,id,action,actionNonce){
                element.disabled=true;
                const row=element.closest('tr[data-id]');
                try{
                    const response=await fetch(ajaxUrl,{
                        method:'POST',credentials:'same-origin',
                        headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
                        body:new URLSearchParams({action:'bcs_list_quick_action_02010',registration_id:String(id),quick_action:String(action),nonce:String(actionNonce)})
                    });
                    const responseText=await response.text();
                    let json;
                    try{json=JSON.parse(responseText)}catch(parseError){throw new Error('Serwer zwrócił nieprawidłową odpowiedź. Spróbuj ponownie.')}
                    if(!response.ok||!json.success)throw new Error(json?.data?.message||'Nie udało się wykonać akcji.');
                    if(row){
                        const data=json.data||{};
                        const statusCell=row.querySelector('[data-bcs-col="status"]');
                        const paymentCell=row.querySelector('[data-bcs-col="payment"]');
                        const progressCell=row.querySelector('[data-bcs-col="progress"]');
                        const actionsCell=row.querySelector('[data-bcs-col="actions"]');
                        if(statusCell)statusCell.innerHTML='<span class="bcs-badge '+String(data.status_class||'')+'">'+String(data.status_label||'')+'</span><br><small>'+String(data.agreement_number||'Bez umowy')+'</small>';
                        if(paymentCell)paymentCell.innerHTML=String(data.payment_html||'');
                        if(progressCell)progressCell.innerHTML=String(data.progress_html||'');
                        if(actionsCell)actionsCell.innerHTML=String(data.quick_html||'<span class="bcs-muted">Brak wymaganej akcji</span>');
                        row.dataset.status=String(data.status||'');
                        row.dataset.stage=String(data.status_label||'').toLocaleLowerCase('pl-PL');
                        row.dataset.paid=String(data.paid||0);
                        row.dataset.updated=String(data.updated_at||'');
                        row.dataset.requires=data.requires_action?'1':'0';
                        row.classList.toggle('bcs-requires-action',!!data.requires_action);
                        row.classList.toggle('bcs-registration-complete',!!data.complete);
                        const idCell=row.cells[0];
                        let marker=idCell?.querySelector('.bcs-row-action-marker');
                        if(data.requires_action&&!marker&&idCell){
                            marker=document.createElement('span');
                            marker.className='bcs-row-action-marker';
                            marker.title='To zgłoszenie wymaga działania administratora';
                            marker.textContent='Wymaga akcji';
                            idCell.appendChild(marker);
                        }else if(!data.requires_action&&marker)marker.remove();
                        row.classList.add('bcs-ajax-updated-02013');
                        window.setTimeout(()=>row.classList.remove('bcs-ajax-updated-02013'),1600);
                    }
                    popup(json.data.message||'Akcja została wykonana.',true);
                }catch(error){
                    element.disabled=false;
                    popup(error.message||'Nie udało się wykonać akcji.',false);
                }
            }
            document.addEventListener('submit',function(event){
                if(page!=='bcs-registrations'||view)return;
                const form=event.target;
                if(!form.matches('.bcs-list-action'))return;
                const submitter=event.submitter;
                if(!submitter||isInvoiceForm(form,submitter))return;
                event.preventDefault();
                event.stopImmediatePropagation();
                runListQuickAction(submitter,registrationId(form),submitter.value||'',form.querySelector('[name="_wpnonce"]')?.value||'');
            },true);
            document.addEventListener('click',function(event){
                if(page!=='bcs-registrations'||view)return;
                const link=event.target.closest('.bcs-inline-actions a[href*="bcs_workflow_single"]');
                if(!link)return;
                event.preventDefault();
                event.stopImmediatePropagation();
                const url=new URL(link.href);
                runListQuickAction(link,url.searchParams.get('registration_id')||'',url.searchParams.get('workflow')||'',url.searchParams.get('_wpnonce')||'');
            },true);
            document.addEventListener('submit',async function(event){
                const form=event.target;
                if(page!=='bcs-registrations'||!view||!form.matches('.bcs-stripe-link-action-02014'))return;
                event.preventDefault();
                event.stopImmediatePropagation();
                const button=event.submitter||form.querySelector('button[type="submit"]');
                if(button)button.disabled=true;
                try{
                    const response=await fetch(ajaxUrl,{
                        method:'POST',credentials:'same-origin',
                        headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
                        body:new URLSearchParams({
                            action:'bcs_send_stripe_link_02014',
                            registration_id:String(form.querySelector('[name="registration_id"]')?.value||''),
                            nonce:String(form.querySelector('[name="nonce"]')?.value||'')
                        })
                    });
                    const responseText=await response.text();
                    let json;
                    try{json=JSON.parse(responseText)}catch(parseError){throw new Error('Serwer zwrócił nieprawidłową odpowiedź. Spróbuj ponownie.')}
                    if(!response.ok||!json.success)throw new Error(json?.data?.message||'Nie udało się wysłać linku Stripe.');
                    if(button){
                        button.innerHTML='<span class="dashicons dashicons-yes-alt"></span> Link Stripe wysłany';
                        button.classList.add('bcs-invoice-done-0200');
                    }
                    popup(json.data.message,true);
                }catch(error){
                    if(button)button.disabled=false;
                    popup(error.message||'Nie udało się wysłać linku Stripe.',false);
                }
            },true);

            function fixRegistrationActions(){
                if(page!=='bcs-registrations')return;
                document.querySelectorAll('.bcs-handling-section-0190').forEach(x=>x.remove());
                if(!view)return;
                const quick=document.querySelector('.bcs-quick-actions');
                const withdraw=document.querySelector('.bcs-withdraw-agreement-0191,.bcs-withdraw-agreement-0190');
                if(quick&&withdraw&&!quick.contains(withdraw)){
                    const buttons=quick.querySelector('.bcs-crm-buttons')||quick;
                    buttons.appendChild(withdraw);
                }
            }
            function sectionByTitle(pattern){
                return [...document.querySelectorAll('details,section,.bcs-settings-card')].find(x=>pattern.test((x.querySelector(':scope>summary,:scope>h2,:scope>h3')?.textContent||'').trim()));
            }
            function fixSettings(){
                if(page!=='bcs-settings')return;
                const wrap=document.querySelector('.wrap.bcs-admin');if(wrap&&wrap.dataset.bcsSettingsNative==='0209')return;
                document.querySelectorAll('details').forEach(x=>x.classList.add('bcs-settings-section-0200'));
                const docs=sectionByTitle(/^Ustawienia dokumentów i automatyzacji$/i);
                if(docs){
                    const headings=[...docs.querySelectorAll('h2,h3')].filter(x=>/Ustawienia dokumentów i automatyzacji/i.test(x.textContent));
                    headings.slice(1).forEach(x=>x.remove());
                    const summary=docs.querySelector('summary');
                    if(summary&&!summary.querySelector('.dashicons'))summary.insertAdjacentHTML('afterbegin','<span class="dashicons dashicons-media-document"></span>');
                }
                const email=sectionByTitle(/^E-?MAIL$/i);
                const notifications=sectionByTitle(/^(Powiadomienia SMS\/EMAIL|Ustawienia powiadomień|Powiadomienia workflow)$/i);
                if(email&&notifications&&email.parentNode===notifications.parentNode)email.after(notifications);
                if(notifications){
                    notifications.classList.add('bcs-settings-section-0200');
                    const headings=[...notifications.querySelectorAll('h2,h3')].filter(x=>/Powiadomienia SMS\/EMAIL|Ustawienia powiadomień|Powiadomienia workflow/i.test(x.textContent));
                    headings.slice(1).forEach(x=>x.remove());
                    const summary=notifications.querySelector('summary');
                    if(summary&&!summary.querySelector('.dashicons'))summary.insertAdjacentHTML('afterbegin','<span class="dashicons dashicons-bell"></span>');
                }
            }
            function run(){fixRegistrationActions();fixSettings();}
            if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',run);else run();
            setTimeout(run,200);
        })();
        </script>
        <?php
    }

    public static function portal_scripts(): void {
        if (is_admin()) return;
        ?>
        <script>
        (function(){
            function fixParentPanel(){
                const heading=[...document.querySelectorAll('h2,h3')].find(x=>(x.textContent||'').trim()==='Dane i formularze');
                const card=heading&&heading.closest('section,.bcs-card');
                if(!card||card.dataset.bcs0200)return;
                card.dataset.bcs0200='1';
                const actions=card.querySelector('.bcs-parent-actions');
                if(!actions)return;
                const old=[...actions.querySelectorAll('a,span,button')];
                const accepted=old.some(x=>/zaakceptowany/i.test(x.textContent));
                const locked=old.some(x=>/zablokowan/i.test(x.textContent))
                    || /obecnie przeglądane|tymczasowo zablokowana/i.test(document.body.textContent);
                const edit=old.find(x=>/Zmień dane|Edytuj formularz/i.test(x.textContent));
                const preview=old.find(x=>/Formularz Obozowy/i.test(x.textContent));
                card.querySelectorAll(':scope>p').forEach(x=>x.remove());
                actions.innerHTML='';
                if(!accepted&&!locked){
                    const a=document.createElement('a');
                    a.className='bcs-button bcs-secondary';
                    a.href=edit?.href||preview?.href||'#';
                    a.textContent='Podgląd Formularza Obozowego';
                    actions.appendChild(a);
                }else{
                    const span=document.createElement('span');
                    span.className='bcs-button bcs-disabled';
                    span.textContent='Podgląd Formularza Obozowego';
                    actions.appendChild(span);
                }
            }
            if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',fixParentPanel);else fixParentPanel();
        })();
        </script>
        <?php
    }
}
