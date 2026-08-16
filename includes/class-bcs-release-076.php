<?php
if (!defined('ABSPATH')) exit;

/** Wersja 0.76 – pełny obieg faktur KSeF oraz niezależny moduł KSeF TEST. */
final class BCS_Release_076 {
    public static function init(): void {
        remove_action('admin_init', ['BCS_KSeF_Admin', 'save_organizer_fields'], 5);
        add_action('admin_init', [__CLASS__, 'save_ksef_settings'], 4);
        add_action('admin_init', [__CLASS__, 'handle_classic_invoice_actions'], 0);
        add_action('admin_menu', [__CLASS__, 'replace_pages'], 10000);
        add_action('admin_footer', [__CLASS__, 'organizer_environment_ui'], 80);
        add_action('admin_head', [__CLASS__, 'crm_invoice_script'], 0);

        add_action('wp_ajax_bcs_ksef_test_prepare_076', [__CLASS__, 'ajax_test_prepare']);
        add_action('wp_ajax_bcs_ksef_test_send_076', [__CLASS__, 'ajax_test_send']);
        add_action('wp_ajax_bcs_ksef_test_refresh_076', [__CLASS__, 'ajax_test_refresh']);
        add_action('wp_ajax_bcs_ksef_generate_invoice_full_076', [__CLASS__, 'ajax_real_generate']);
        add_action('wp_ajax_bcs_ksef_real_refresh_076', [__CLASS__, 'ajax_real_refresh']);
        add_action('wp_ajax_bcs_list_quick_action_02010', [__CLASS__, 'intercept_list_invoice_ajax'], 0);
    }

    public static function replace_pages(): void {
        $ksefHook = get_plugin_page_hookname('bcs-ksef', 'bcs-dashboard');
        if ($ksefHook) {
            remove_action($ksefHook, ['BCS_KSeF_Admin', 'page']);
            remove_action($ksefHook, ['BCS_Release_075', 'page']);
            remove_action($ksefHook, [__CLASS__, 'ksef_test_page']);
            add_action($ksefHook, [__CLASS__, 'ksef_test_page']);
        }
        $invoiceHook = get_plugin_page_hookname('bcs-invoices', 'bcs-dashboard');
        if ($invoiceHook) {
            remove_action($invoiceHook, ['BCS_Invoices', 'page']);
            remove_action($invoiceHook, [__CLASS__, 'invoices_page']);
            add_action($invoiceHook, [__CLASS__, 'invoices_page']);
        }
    }

    public static function save_ksef_settings(): void {
        if (!current_user_can('manage_options') || empty($_POST['bcs_save_organizer']) || empty($_POST['bcs_ksef_panel_present'])) return;
        check_admin_referer('bcs_save_organizer');
        $id = absint($_POST['organizer_id'] ?? 0); if (!$id) return;
        global $wpdb;
        $organizer = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.BCS_DB::table('organizers').' WHERE id=%d', $id));
        if (!$organizer) return;
        $environment = BCS_KSeF_Config::allowed_environment(sanitize_key(wp_unslash($_POST['ksef_environment'] ?? 'test')));
        $data = [
            'ksef_enabled'=>isset($_POST['ksef_enabled'])?1:0,
            'ksef_environment'=>$environment,
            'ksef_context_nip'=>preg_replace('/\D+/','',(string)wp_unslash($_POST['ksef_context_nip']??'')),
            'ksef_country_code'=>strtoupper(substr(preg_replace('/[^A-Za-z]/','',(string)wp_unslash($_POST['ksef_country_code']??'PL')),0,2))?:'PL',
            'ksef_address_l1'=>sanitize_text_field(wp_unslash($_POST['ksef_address_l1']??'')),
            'ksef_address_l2'=>sanitize_text_field(wp_unslash($_POST['ksef_address_l2']??'')),
            'ksef_anonymize_test'=>isset($_POST['ksef_anonymize_test'])?1:0,
        ];
        self::save_token_fields($data, 'test', (string)wp_unslash($_POST['ksef_token']??''), !empty($_POST['ksef_remove_token']));
        self::save_token_fields($data, 'production', (string)wp_unslash($_POST['ksef_production_token']??''), !empty($_POST['ksef_remove_production_token']));
        $wpdb->update(BCS_DB::table('organizers'), $data, ['id'=>$id]);
    }

    private static function save_token_fields(array &$data, string $environment, string $token, bool $remove): void {
        $prefix = $environment === 'production' ? 'ksef_production_token_' : 'ksef_token_';
        if ($remove) {
            $data[$prefix.'ciphertext']=null; $data[$prefix.'nonce']=null; $data[$prefix.'configured_at']=null; return;
        }
        $token=trim($token); if($token==='')return;
        try{$encrypted=BCS_KSeF_Secret::encrypt($token);$data[$prefix.'ciphertext']=$encrypted['ciphertext'];$data[$prefix.'nonce']=$encrypted['nonce'];$data[$prefix.'configured_at']=BCS_Utils::now();}
        catch(Throwable $e){$data['ksef_last_test_at']=BCS_Utils::now();$data['ksef_last_test_status']='error';$data['ksef_last_test_message']='Nie zapisano tokenu '.BCS_KSeF_Config::label($environment).': '.$e->getMessage();}
    }

    public static function organizer_environment_ui(): void {
        if (!current_user_can('manage_options') || sanitize_key(wp_unslash($_GET['page']??''))!=='bcs-organizers') return;
        $id=absint($_GET['edit']??0);if(!$id)return;global $wpdb;$o=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BCS_DB::table('organizers').' WHERE id=%d',$id));if(!$o)return;
        $env=BCS_KSeF_Config::allowed_environment((string)($o->ksef_environment??'test'));$prodConfigured=BCS_KSeF_Secret::configured($o,'production');
        ?>
        <script>
        (()=>{const details=[...document.querySelectorAll('.bcs-organizer-section-074')].find(el=>el.textContent.includes('KSeF API'));if(!details)return;const grid=details.querySelector('.bcs-form-grid');if(!grid)return;
        const summary=details.querySelector('summary strong');if(summary)summary.textContent='KSeF API 2.0';
        const hidden=grid.querySelector('input[type="hidden"][name="ksef_environment"]');if(hidden)hidden.remove();const disabled=grid.querySelector('select[disabled]');if(disabled){disabled.disabled=false;disabled.name='ksef_environment';disabled.innerHTML='<option value="test">TEST – api-test.ksef.mf.gov.pl</option><option value="production">PRODUKCJA – api.ksef.mf.gov.pl</option>';disabled.value=<?php echo wp_json_encode($env); ?>;const label=disabled.closest('label')?.querySelector('span');if(label)label.textContent='Środowisko dla właściwych faktur';}
        const firstToken=grid.querySelector('input[name="ksef_token"]');if(firstToken){const s=firstToken.closest('label')?.querySelector('span');if(s)s.textContent='Token KSeF TEST';}
        if(!grid.querySelector('input[name="ksef_production_token"]')){const label=document.createElement('label');label.className='bcs-span-2';label.innerHTML='<span>Token KSeF PRODUKCJA</span><input type="password" autocomplete="new-password" name="ksef_production_token" placeholder="<?php echo esc_js($prodConfigured?'Token produkcyjny zapisany — pozostaw puste, aby zachować':'Wklej token wygenerowany w produkcyjnym KSeF'); ?>">';if(firstToken?.closest('label'))firstToken.closest('label').insertAdjacentElement('afterend',label);if(<?php echo $prodConfigured?'true':'false'; ?>){const info=document.createElement('div');info.className='bcs-ksef-saved-token-075';info.innerHTML='<strong>✓ Token KSeF PRODUKCJA jest zapisany i zaszyfrowany.</strong><p>Pozostaw pole puste, aby zachować zapisany token.</p><label style="display:block;margin-top:8px"><input type="checkbox" name="ksef_remove_production_token" value="1"> Usuń zapisany token produkcyjny</label>';label.insertAdjacentElement('beforebegin',info);}}
        const title=details.querySelector('.bcs-settings-summary');if(title)title.textContent='TEST / PRODUKCJA';
        })();
        </script><?php
    }

    private static function authorize_registration_ajax(string $nonceAction): int {
        if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Brak uprawnień.'],403);$id=absint($_POST['registration_id']??0);$nonce=sanitize_text_field(wp_unslash($_POST['nonce']??''));if(!$id||!wp_verify_nonce($nonce,$nonceAction.$id))wp_send_json_error(['message'=>'Sesja wygasła. Odśwież stronę.'],403);return $id;
    }

    public static function ajax_real_generate(): void {
        $id=self::authorize_registration_ajax('bcs_crm_');$ok=BCS_KSeF_Invoice_Flow::generate_and_submit($id);$result=BCS_KSeF_Invoice_Flow::last_result();if($ok)wp_send_json_success($result);wp_send_json_error($result?:['message'=>'Nie udało się wygenerować faktury.'],422);
    }

    public static function intercept_list_invoice_ajax(): void {
        if(sanitize_key(wp_unslash($_POST['quick_action']??''))!=='invoice_generate')return;$id=absint($_POST['registration_id']??0);if(!current_user_can('manage_options')||!$id)return;$nonce=sanitize_text_field(wp_unslash($_POST['nonce']??''));$valid=wp_verify_nonce($nonce,'bcs_crm_'.$id)||wp_verify_nonce($nonce,'bcs_workflow_single_'.$id.'_invoice_generate')||wp_verify_nonce($nonce,'bcs_workflow_single_'.$id.'_generate_invoice');if(!$valid)wp_send_json_error(['message'=>'Sesja wygasła.'],403);$ok=BCS_KSeF_Invoice_Flow::generate_and_submit($id);$result=BCS_KSeF_Invoice_Flow::last_result();if($ok)wp_send_json_success($result);wp_send_json_error($result,422);
    }

    public static function ajax_real_refresh(): void {
        if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Brak uprawnień.'],403);$invoiceId=absint($_POST['invoice_id']??0);$nonce=sanitize_text_field(wp_unslash($_POST['nonce']??''));if(!$invoiceId||!wp_verify_nonce($nonce,'bcs_ksef_real_'.$invoiceId))wp_send_json_error(['message'=>'Sesja wygasła.'],403);$result=BCS_KSeF_Service::refresh_status($invoiceId);if(!empty($result['success'])){if(($result['status']??'')==='accepted')BCS_KSeF_Invoice_Flow::finalize($invoiceId);wp_send_json_success($result);}wp_send_json_error($result,422);
    }

    public static function handle_classic_invoice_actions(): void {
        if(!is_admin()||!current_user_can('manage_options'))return;
        if(!empty($_POST['bcs_crm_action'])&&sanitize_key(wp_unslash($_POST['bcs_crm_action']))==='invoice_generate'){$id=absint($_POST['registration_id']??0);check_admin_referer('bcs_crm_'.$id);$ok=BCS_KSeF_Invoice_Flow::generate_and_submit($id);$result=BCS_KSeF_Invoice_Flow::last_result();set_transient('bcs_ksef_invoice_result_'.get_current_user_id().'_'.$id,$result,5*MINUTE_IN_SECONDS);wp_safe_redirect(add_query_arg(['page'=>'bcs-registrations','view'=>$id,'crm_done'=>$ok?1:0],admin_url('admin.php')));exit;}
        if(!empty($_POST['bcs_workflow_action'])&&sanitize_key(wp_unslash($_POST['bcs_workflow_action']))==='generate_invoice'){check_admin_referer('bcs_workflow_action');$ids=array_values(array_filter(array_map('absint',(array)($_POST['registration_ids']??[]))));$ok=0;$failed=0;foreach($ids as $id){BCS_KSeF_Invoice_Flow::generate_and_submit($id)?$ok++:$failed++;}wp_safe_redirect(add_query_arg(['page'=>'bcs-registrations','done'=>$ok,'failed'=>$failed],admin_url('admin.php')));exit;}
    }

    public static function crm_invoice_script(): void {
        if(!is_admin()||sanitize_key(wp_unslash($_GET['page']??''))!=='bcs-registrations')return;?>
        <script>
        (()=>{const normalize=()=>{document.querySelectorAll('button,a,option').forEach(el=>{const t=(el.textContent||'').trim();if(/^Wyg(e|ne)neruj faktur/i.test(t)||t==='Faktura')el.textContent='Generuj fakturę';});};normalize();new MutationObserver(normalize).observe(document.documentElement,{childList:true,subtree:true});
        document.addEventListener('click',async e=>{const b=e.target.closest('button[name="bcs_crm_action"][value="invoice_generate"],button[data-action="invoice_generate"]');if(!b)return;const form=b.closest('form');const id=form?.querySelector('[name="registration_id"]')?.value||b.dataset.registration||'';const nonce=form?.querySelector('[name="_wpnonce"]')?.value||b.dataset.nonce||'';if(!id||!nonce)return;e.preventDefault();e.stopImmediatePropagation();b.disabled=true;const old=b.textContent;b.textContent='Generowanie i wysyłka do KSeF…';try{const body=new URLSearchParams({action:'bcs_ksef_generate_invoice_full_076',registration_id:id,nonce});const r=await fetch(window.ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});const j=await r.json();const ok=Boolean(j.success),m=j.data?.message||(ok?'Faktura została przekazana do KSeF.':'Nie udało się wygenerować faktury.');if(typeof window.bcsNotify==='function')window.bcsNotify(m,ok);if(ok)setTimeout(()=>location.reload(),1800);else{b.disabled=false;b.textContent=old;}}catch(err){if(typeof window.bcsNotify==='function')window.bcsNotify('Błąd połączenia podczas generowania faktury.',false);b.disabled=false;b.textContent=old;}},true);})();
        </script><?php
    }

    private static function test_nonce(int $registrationId): string { return wp_create_nonce('bcs_ksef_test_registration_076_'.$registrationId); }
    private static function authorize_test(): int {if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Brak uprawnień.'],403);$id=absint($_POST['registration_id']??0);$nonce=sanitize_text_field(wp_unslash($_POST['nonce']??''));if(!$id||!wp_verify_nonce($nonce,'bcs_ksef_test_registration_076_'.$id))wp_send_json_error(['message'=>'Sesja wygasła.'],403);return $id;}
    public static function ajax_test_prepare(): void {$id=self::authorize_test();$r=BCS_KSeF_Test_Document_Service::prepare($id);!empty($r['success'])?wp_send_json_success($r):wp_send_json_error($r,422);}
    public static function ajax_test_send(): void {$id=self::authorize_test();$r=BCS_KSeF_Test_Document_Service::send($id);!empty($r['success'])?wp_send_json_success($r):wp_send_json_error($r,422);}
    public static function ajax_test_refresh(): void {$id=self::authorize_test();$r=BCS_KSeF_Test_Document_Service::refresh($id);!empty($r['success'])?wp_send_json_success($r):wp_send_json_error($r,422);}

    public static function ksef_test_page(): void {
        if(!current_user_can('manage_options'))return;global $wpdb;$rows=$wpdb->get_results('SELECT r.id,r.parent_first_name,r.parent_last_name,r.child_first_name,r.child_last_name,r.paid_amount,r.total_amount,c.name camp_name,o.name organizer_name,o.ksef_enabled,o.ksef_token_ciphertext,o.ksef_token_nonce,t.invoice_number test_invoice_number,t.status test_status,t.ksef_number test_ksef_number,t.status_description test_status_description,t.error_message test_error_message FROM '.BCS_DB::table('registrations').' r JOIN '.BCS_DB::table('camps').' c ON c.id=r.camp_id JOIN '.BCS_DB::table('organizers').' o ON o.id=c.organizer_id LEFT JOIN '.BCS_DB::table('ksef_test_documents').' t ON t.registration_id=r.id WHERE r.status<>\'cancelled\' AND r.total_amount>0 AND r.paid_amount>=r.total_amount ORDER BY r.id DESC LIMIT 250');
        echo '<div class="wrap bcs-admin bcs-ksef-page-076"><div class="bcs-page-head"><div><h1>KSeF TEST</h1><p>Osobne testowe faktury dla opłaconych zgłoszeń. Nie trafiają do modułu Faktury i nie są wysyłane rodzicom.</p></div><span class="bcs-version-label">API '.esc_html(BCS_KSeF_Config::API_VERSION).'</span></div><div class="notice notice-warning inline"><p>Każdy dokument w tej zakładce jest zawsze wysyłany do <strong>api-test.ksef.mf.gov.pl</strong> z zanonimizowanymi danymi. Możesz najpierw wygenerować XML, a następnie osobno wysłać go do KSeF TEST.</p></div><section class="bcs-panel"><div class="bcs-table-wrap"><table class="widefat striped"><thead><tr><th>Zgłoszenie</th><th>Uczestnik / rodzic</th><th>Turnus</th><th>Organizator</th><th>Kwota</th><th>Testowa faktura</th><th>Status KSeF TEST</th><th>Działania</th></tr></thead><tbody>';
        if(!$rows)echo '<tr><td colspan="8">Brak opłaconych zgłoszeń.</td></tr>';foreach($rows as $r)self::test_row($r);echo '</tbody></table></div></section></div>';self::assets();
    }

    private static function test_row(object $r): void {$id=(int)$r->id;$status=(string)($r->test_status?:'not_generated');$configured=(int)$r->ksef_enabled===1&&!empty($r->ksef_token_ciphertext)&&!empty($r->ksef_token_nonce);echo '<tr data-test-registration="'.$id.'"><td><strong>#'.$id.'</strong></td><td><strong>'.esc_html($r->child_first_name.' '.$r->child_last_name).'</strong><br><small>'.esc_html($r->parent_first_name.' '.$r->parent_last_name).'</small></td><td>'.esc_html($r->camp_name).'</td><td>'.esc_html($r->organizer_name).'</td><td>'.esc_html(number_format((float)$r->paid_amount,2,',',' ').' zł').'</td><td>'.esc_html($r->test_invoice_number?:'—').'</td><td><span class="bcs-ksef-test-status-076 is-'.esc_attr($status).'">'.esc_html(self::test_status_label($status)).'</span>'.($r->test_ksef_number?'<br><small>'.esc_html($r->test_ksef_number).'</small>':'').($r->test_error_message?'<br><small class="bcs-error">'.esc_html($r->test_error_message).'</small>':'').'</td><td><div class="bcs-ksef-test-actions-076">';$nonce=self::test_nonce($id);if(!$configured)echo '<small>Skonfiguruj token TEST Organizatora.</small>';else{if($status!=='accepted')echo '<button class="button bcs-ksef-test-action-076" data-action="bcs_ksef_test_prepare_076" data-registration="'.$id.'" data-nonce="'.esc_attr($nonce).'">Generuj testową fakturę KSeF</button>';if(in_array($status,['xml_ready','rejected','connection_error'],true))echo '<button class="button button-primary bcs-ksef-test-action-076" data-action="bcs_ksef_test_send_076" data-registration="'.$id.'" data-nonce="'.esc_attr($nonce).'">Wyślij do KSeF TEST</button>';if(in_array($status,['processing','sending'],true))echo '<button class="button bcs-ksef-test-action-076" data-action="bcs_ksef_test_refresh_076" data-registration="'.$id.'" data-nonce="'.esc_attr($nonce).'">Odśwież status</button>';if($status!=='accepted')echo '<button class="button bcs-ksef-full-test-076" data-registration="'.$id.'" data-nonce="'.esc_attr($nonce).'">Testuj cały proces</button>';}echo '</div><div class="bcs-ksef-test-result-076"></div></td></tr>';}
    private static function test_status_label(string $s): string{return ['not_generated'=>'Nie wygenerowano','xml_ready'=>'XML gotowy','sending'=>'Wysyłanie','processing'=>'Przetwarzanie','accepted'=>'TEST OK – przyjęto','rejected'=>'Odrzucono','connection_error'=>'Błąd połączenia'][$s]??$s;}

    public static function invoices_page(): void {
        if(!current_user_can('manage_options'))return;global $wpdb;$search=sanitize_text_field(wp_unslash($_GET['s']??''));$org=absint($_GET['organizer_id']??0);$where=['1=1'];$args=[];if($search!==''){$like='%'.$wpdb->esc_like($search).'%';$where[]='(i.invoice_number LIKE %s OR r.parent_first_name LIKE %s OR r.parent_last_name LIKE %s OR r.child_first_name LIKE %s OR r.child_last_name LIKE %s)';$args=array_merge($args,[$like,$like,$like,$like,$like]);}if($org){$where[]='i.organizer_id=%d';$args[]=$org;}$sql='SELECT i.*,r.parent_first_name,r.parent_last_name,r.child_first_name,r.child_last_name,c.name camp_name,o.name organizer_name,o.ksef_environment FROM '.BCS_DB::table('invoices').' i JOIN '.BCS_DB::table('registrations').' r ON r.id=i.registration_id JOIN '.BCS_DB::table('camps').' c ON c.id=r.camp_id JOIN '.BCS_DB::table('organizers').' o ON o.id=i.organizer_id WHERE '.implode(' AND ',$where).' ORDER BY i.id DESC';$rows=$args?$wpdb->get_results($wpdb->prepare($sql,$args)):$wpdb->get_results($sql);$organizers=$wpdb->get_results('SELECT id,name FROM '.BCS_DB::table('organizers').' ORDER BY name');
        echo '<div class="wrap bcs-admin"><div class="bcs-page-head"><div><h1>Faktury</h1><p>Właściwe faktury wygenerowane przez CRM i przekazane do KSeF.</p></div><span class="bcs-count">'.count($rows).' faktur</span></div><section class="bcs-panel"><form method="get" class="bcs-invoice-filters"><input type="hidden" name="page" value="bcs-invoices"><input type="search" name="s" value="'.esc_attr($search).'" placeholder="Szukaj numeru lub klienta"><select name="organizer_id"><option value="0">Wszyscy organizatorzy</option>';foreach($organizers as $o)echo '<option value="'.(int)$o->id.'" '.selected($org,(int)$o->id,false).'>'.esc_html($o->name).'</option>';echo '</select><button class="button button-primary">Filtruj</button></form><div class="bcs-table-wrap"><table class="widefat striped"><thead><tr><th>Numer</th><th>Klient / uczestnik</th><th>Turnus</th><th>Organizator</th><th>Środowisko</th><th>Status KSeF</th><th>Numer KSeF</th><th>Wysłano rodzicowi</th><th>Pobrano</th><th>Akcje</th></tr></thead><tbody>';if(!$rows)echo '<tr><td colspan="10">Brak faktur.</td></tr>';foreach($rows as $r)self::invoice_row($r);echo '</tbody></table></div></section></div>';self::assets();
    }

    private static function invoice_row(object $r): void {$env=BCS_KSeF_Config::allowed_environment((string)($r->ksef_environment_used?:$r->ksef_environment?:'test'));$status=(string)($r->ksef_status?:'not_sent');$view=wp_nonce_url(admin_url('admin-post.php?action=bcs_invoice_view&invoice_id='.(int)$r->id),'bcs_invoice_view_'.(int)$r->id);$download=wp_nonce_url(admin_url('admin-post.php?action=bcs_invoice_download&invoice_id='.(int)$r->id),'bcs_invoice_download_'.(int)$r->id);echo '<tr><td><strong>'.esc_html($r->invoice_number).'</strong><br><small>'.esc_html(BCS_Utils::format_datetime($r->created_at)).'</small></td><td>'.esc_html($r->parent_first_name.' '.$r->parent_last_name).'<br><small>'.esc_html($r->child_first_name.' '.$r->child_last_name).'</small></td><td>'.esc_html($r->camp_name).'</td><td>'.esc_html($r->organizer_name).'</td><td><span class="bcs-ksef-env-076 is-'.esc_attr($env).'">'.esc_html(BCS_KSeF_Config::label($env)).'</span></td><td><span class="bcs-ksef-test-status-076 is-'.esc_attr($status).'">'.esc_html(self::real_status_label($status)).'</span>'.($r->ksef_status_description?'<br><small>'.esc_html($r->ksef_status_description).'</small>':'').($r->ksef_error_message?'<br><small class="bcs-error">'.esc_html($r->ksef_error_message).'</small>':'').'</td><td>'.esc_html($r->ksef_number?:'—').'</td><td>'.($r->sent_at?'✓ '.esc_html(BCS_Utils::format_datetime($r->sent_at)):($env==='test'&&$status==='accepted'?'<span class="bcs-muted">Nie – tryb TEST</span>':'Nie')).'</td><td>'.($r->downloaded_at?'✓ '.esc_html(BCS_Utils::format_datetime($r->downloaded_at)).' ('.(int)$r->download_count.')':'Nie').'</td><td><div class="bcs-icon-actions"><button type="button" class="button bcs-invoice-preview" data-url="'.esc_url($view).'" title="Podgląd PDF"><span class="dashicons dashicons-visibility"></span></button><a class="button" href="'.esc_url($download).'" title="Pobierz PDF"><span class="dashicons dashicons-download"></span></a>';if(in_array($status,['processing','sending'],true)){echo '<button type="button" class="button bcs-ksef-real-refresh-076" data-invoice="'.(int)$r->id.'" data-nonce="'.esc_attr(wp_create_nonce('bcs_ksef_real_'.(int)$r->id)).'">Sprawdź KSeF</button>';}echo '</div></td></tr>';}
    private static function real_status_label(string $s): string{return ['not_sent'=>'Nie wysłano','xml_ready'=>'XML gotowy','sending'=>'Wysyłanie','processing'=>'Przetwarzanie przez KSeF','accepted'=>'Przyjęto w KSeF','rejected'=>'Odrzucono przez KSeF','connection_error'=>'Błąd połączenia'][$s]??$s;}

    private static function assets(): void {?>
        <style>.bcs-ksef-test-actions-076{display:flex;gap:6px;flex-wrap:wrap}.bcs-ksef-test-result-076{margin-top:6px;font-size:12px;font-weight:600}.bcs-ksef-test-status-076,.bcs-ksef-env-076{display:inline-block;padding:4px 8px;border-radius:999px;background:#e2e8f0;font-size:12px;font-weight:700}.bcs-ksef-test-status-076.is-accepted{background:#dcfce7;color:#166534}.bcs-ksef-test-status-076.is-processing,.bcs-ksef-test-status-076.is-sending{background:#fef3c7;color:#92400e}.bcs-ksef-test-status-076.is-rejected,.bcs-ksef-test-status-076.is-connection_error{background:#fee2e2;color:#991b1b}.bcs-ksef-env-076.is-production{background:#dcfce7;color:#166534}.bcs-ksef-env-076.is-test{background:#dbeafe;color:#1d4ed8}.bcs-error{color:#b42318}</style>
        <script>
        document.addEventListener('click',async e=>{const b=e.target.closest('.bcs-ksef-test-action-076');if(!b)return;b.disabled=true;const old=b.textContent;b.textContent='Przetwarzanie…';const result=b.closest('td').querySelector('.bcs-ksef-test-result-076');try{const body=new URLSearchParams({action:b.dataset.action,registration_id:b.dataset.registration,nonce:b.dataset.nonce});const r=await fetch(window.ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});const j=await r.json();const ok=Boolean(j.success),m=j.data?.message||(ok?'Wykonano.':'Operacja nie powiodła się.');result.textContent=m;if(typeof window.bcsNotify==='function')window.bcsNotify(m,ok);if(ok)setTimeout(()=>location.reload(),1300);else{b.disabled=false;b.textContent=old;}}catch(err){result.textContent='Błąd połączenia.';b.disabled=false;b.textContent=old;}});
        document.addEventListener('click',async e=>{const b=e.target.closest('.bcs-ksef-full-test-076');if(!b)return;if(!confirm('Uruchomić pełny test: wygenerować i wysłać dodatkową fakturę do KSeF TEST?'))return;b.disabled=true;b.textContent='Testowanie…';const call=async action=>{const body=new URLSearchParams({action,registration_id:b.dataset.registration,nonce:b.dataset.nonce});const r=await fetch(window.ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});return r.json();};try{let j=await call('bcs_ksef_test_prepare_076');if(!j.success)throw new Error(j.data?.message||'Błąd generowania');j=await call('bcs_ksef_test_send_076');let tries=0;while(j.success&&j.data?.status==='processing'&&tries<6){await new Promise(x=>setTimeout(x,2500));j=await call('bcs_ksef_test_refresh_076');tries++;}const ok=j.success&&j.data?.status==='accepted';const m=j.data?.message||(ok?'TEST OK.':'Test nie został zakończony.');if(typeof window.bcsNotify==='function')window.bcsNotify(m,ok);setTimeout(()=>location.reload(),1600);}catch(err){if(typeof window.bcsNotify==='function')window.bcsNotify(err.message||'Błąd testu KSeF.',false);b.disabled=false;b.textContent='Testuj cały proces';}});
        document.addEventListener('click',async e=>{const b=e.target.closest('.bcs-ksef-real-refresh-076');if(!b)return;b.disabled=true;const body=new URLSearchParams({action:'bcs_ksef_real_refresh_076',invoice_id:b.dataset.invoice,nonce:b.dataset.nonce});try{const r=await fetch(window.ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});const j=await r.json();const m=j.data?.message||(j.success?'Status zaktualizowany.':'Błąd statusu.');if(typeof window.bcsNotify==='function')window.bcsNotify(m,Boolean(j.success));setTimeout(()=>location.reload(),1300);}catch(err){b.disabled=false;}});
        </script><?php }
}
