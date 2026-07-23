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
                window.alert(message);
            }
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
                document.querySelectorAll('details').forEach(x=>x.classList.add('bcs-settings-section-0200'));
                const docs=sectionByTitle(/^Ustawienia dokumentów i automatyzacji$/i);
                if(docs){
                    const headings=[...docs.querySelectorAll('h2,h3')].filter(x=>/Ustawienia dokumentów i automatyzacji/i.test(x.textContent));
                    headings.slice(1).forEach(x=>x.remove());
                    const summary=docs.querySelector('summary');
                    if(summary&&!summary.querySelector('.dashicons'))summary.insertAdjacentHTML('afterbegin','<span class="dashicons dashicons-media-document"></span>');
                }
                const email=sectionByTitle(/^E-?MAIL$/i);
                const notifications=sectionByTitle(/^(Ustawienia powiadomień|Powiadomienia workflow)$/i);
                if(email&&notifications&&email.parentNode===notifications.parentNode)email.after(notifications);
                if(notifications){
                    notifications.classList.add('bcs-settings-section-0200');
                    const headings=[...notifications.querySelectorAll('h2,h3')].filter(x=>/Ustawienia powiadomień|Powiadomienia workflow/i.test(x.textContent));
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
                const preview=old.find(x=>/formularz obozowy|formularza osobowego/i.test(x.textContent));
                card.querySelectorAll(':scope>p').forEach(x=>x.remove());
                actions.innerHTML='';
                if(!accepted&&!locked){
                    const a=document.createElement('a');
                    a.className='bcs-button bcs-secondary';
                    a.href=edit?.href||preview?.href||'#';
                    a.textContent='Podgląd formularza osobowego';
                    actions.appendChild(a);
                }else{
                    const span=document.createElement('span');
                    span.className='bcs-button bcs-disabled';
                    span.textContent='Podgląd formularza osobowego';
                    actions.appendChild(span);
                }
            }
            if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',fixParentPanel);else fixParentPanel();
        })();
        </script>
        <?php
    }
}
