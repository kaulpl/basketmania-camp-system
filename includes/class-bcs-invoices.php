<?php
if (!defined('ABSPATH')) exit;

class BCS_Invoices {
    public static function init(): void {
        // Menu rejestrowane centralnie w BCS_Admin.
        add_action('admin_post_bcs_invoice_view', [__CLASS__, 'stream_invoice']);
        add_action('admin_post_bcs_invoice_download', [__CLASS__, 'download_invoice']);
        add_action('admin_post_bcs_invoice_delete', [__CLASS__, 'delete_invoice']);
        add_action('template_redirect', [__CLASS__, 'public_invoice']);
    }

    public static function menu(): void {
        add_submenu_page('bcs-dashboard', 'Faktury', 'Faktury', 'manage_options', 'bcs-invoices', [__CLASS__, 'page']);
    }

    private static function invoice_row(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT i.*,r.public_token,r.parent_first_name,r.parent_last_name,r.parent_email,r.child_first_name,r.child_last_name,c.name camp_name FROM ".BCS_DB::table('invoices')." i JOIN ".BCS_DB::table('registrations')." r ON r.id=i.registration_id JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id WHERE i.id=%d",$id));
    }

    private static function registration_row(int $registration_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT r.*, c.name camp_name, c.start_date, c.end_date, c.location, c.organizer_id, o.name organizer_name, o.address organizer_address, o.nip organizer_nip, o.regon organizer_regon, o.email organizer_email, o.phone organizer_phone, o.bank_name, o.bank_account FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id WHERE r.id=%d",$registration_id));
    }

    public static function has_invoice(int $registration_id): bool {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM ".BCS_DB::table('invoices')." WHERE registration_id=%d LIMIT 1",
            $registration_id
        ));
    }

    public static function existing_path(int $registration_id): string {
        global $wpdb;
        $path=$wpdb->get_var($wpdb->prepare(
            "SELECT file_path FROM ".BCS_DB::table('invoices')." WHERE registration_id=%d ORDER BY id DESC LIMIT 1",
            $registration_id
        ));
        return $path && file_exists((string)$path) ? (string)$path : '';
    }

    private static function next_number(int $organizer_id, int $year, string $prefix): string {
        global $wpdb;
        $like = $wpdb->esc_like(strtoupper($prefix).'/'.$year.'/').'%';
        $numbers = $wpdb->get_col($wpdb->prepare("SELECT invoice_number FROM ".BCS_DB::table('invoices')." WHERE organizer_id=%d AND invoice_number LIKE %s",$organizer_id,$like));
        $max=0;
        foreach($numbers as $number){ if(preg_match('~/(\\d+)$~',(string)$number,$m)) $max=max($max,(int)$m[1]); }
        return strtoupper($prefix).'/'.$year.'/'.str_pad((string)($max+1),6,'0',STR_PAD_LEFT);
    }

    private static function logo_data_uri(): string {
        $path=BCS_DIR.'assets/images/logo-basketmania-camp-color.png';
        if(!file_exists($path)) return '';
        return 'data:image/png;base64,'.base64_encode((string)file_get_contents($path));
    }

    public static function ensure_invoice(int $registration_id): string {
        global $wpdb;
        $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('invoices')." WHERE registration_id=%d ORDER BY id DESC LIMIT 1",$registration_id));
        if($existing && $existing->file_path && file_exists($existing->file_path)) return (string)$existing->file_path;
        $r=self::registration_row($registration_id);
        if(!$r || !BCS_Workflow_Engine::invoice_available($registration_id)) return '';
        $settings=get_option('bcs_settings',[]);$prefix=sanitize_key($settings['invoice_prefix']??'FV');$year=(int)BCS_Utils::today('Y');
        $number=self::next_number((int)$r->organizer_id,$year,$prefix);
        $vat_rate=(float)($settings['invoice_vat_rate']??0);$gross=(float)$r->paid_amount;$net=$vat_rate>0?$gross/(1+$vat_rate/100):$gross;$vat=$gross-$net;
        $money=static fn(float $v):string=>number_format($v,2,',',' ').' PLN';
        $vars=[
            '{{LOGO_DATA_URI}}'=>self::logo_data_uri(),'{{INVOICE_NUMBER}}'=>$number,'{{ORGANIZER_NAME}}'=>esc_html($r->organizer_name),'{{ORGANIZER_ADDRESS}}'=>nl2br(esc_html($r->organizer_address)),
            '{{ORGANIZER_NIP}}'=>esc_html($r->organizer_nip),'{{ORGANIZER_EMAIL}}'=>esc_html($r->organizer_email),'{{ORGANIZER_PHONE}}'=>esc_html($r->organizer_phone),
            '{{BUYER_NAME}}'=>esc_html(trim($r->parent_first_name.' '.$r->parent_last_name)),'{{BUYER_ADDRESS}}'=>nl2br(esc_html(BCS_Utils::registration_address($r))),
            '{{ISSUE_PLACE}}'=>esc_html($r->location ?: 'Pelplin'),'{{ISSUE_DATE}}'=>BCS_Utils::today('d-m-Y'),'{{SALE_DATE}}'=>BCS_Utils::today('d-m-Y'),
            '{{PAYMENT_DATE}}'=>!empty($r->paid_at)?esc_html(wp_date('d-m-Y',strtotime($r->paid_at))):BCS_Utils::today('d-m-Y'),
            '{{CAMP_NAME}}'=>esc_html($r->camp_name),'{{CAMP_DATES}}'=>esc_html($r->start_date.' – '.$r->end_date),
            '{{NET_AMOUNT}}'=>$money($net),'{{VAT_LABEL}}'=>$vat_rate>0?esc_html($vat_rate.'% / '.$money($vat)):'zw.','{{GROSS_AMOUNT}}'=>$money($gross),'{{AMOUNT_DUE}}'=>'0,00 PLN',
            '{{BANK_ACCOUNT}}'=>esc_html($r->bank_account),'{{PAYMENT_METHOD}}'=>'przelew','{{EXEMPTION_NOTE}}'=>(!$vat_rate&&!empty($settings['invoice_exemption_basis']))?'<div class="invoice-note">Podstawa zwolnienia: '.esc_html($settings['invoice_exemption_basis']).'</div>':'',
        ];
        $body=BCS_Template_Engine::render(BCS_Template_Engine::get('documents','invoice'),$vars);
        $html=BCS_Document_Engine::html_document('Faktura '.$number,$body);
        $dir=BCS_Document_Engine::uploads_dir().'/registration-'.$registration_id;if(!is_dir($dir))wp_mkdir_p($dir);
        $base='03-faktura-'.sanitize_file_name(str_replace('/','-',$number));$pdf=$dir.'/'.$base.'.pdf';$html_path=$dir.'/'.$base.'.html';
        $path=BCS_PDF::generate($html,$pdf,'Faktura '.$number)?$pdf:$html_path;if($path===$html_path)file_put_contents($path,$html);
        $inserted=$wpdb->insert(BCS_DB::table('invoices'),['registration_id'=>$registration_id,'organizer_id'=>$r->organizer_id,'invoice_number'=>$number,'issue_date'=>BCS_Utils::today('Y-m-d'),'gross_amount'=>$gross,'net_amount'=>$net,'vat_amount'=>$vat,'vat_rate'=>$vat_rate,'status'=>'generated','file_path'=>$path,'ksef_status'=>'not_sent','created_at'=>BCS_Utils::now()]);
        if($inserted===false || !(int)$wpdb->insert_id){
            BCS_Utils::log('invoice_create_failed',['invoice_number'=>$number,'database_error'=>(string)$wpdb->last_error],$registration_id,null);
            return '';
        }
        $invoice_id=(int)$wpdb->insert_id;
        $wpdb->update(BCS_DB::table('registrations'),['invoice_status'=>'generated','updated_at'=>BCS_Utils::now()],['id'=>$registration_id]);
        BCS_Utils::log('invoice_created',['invoice_id'=>$invoice_id,'invoice_number'=>$number,'path'=>$path],$registration_id,null);
        return $path;
    }

    public static function generate_and_send(int $registration_id): bool {
        global $wpdb;
        $lock_name='bcs_invoice_registration_'.$registration_id;
        $lock_result=$wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)",$lock_name));
        $locked=(int)$lock_result===1;
        if($lock_result!==null && !$locked){
            BCS_Utils::log('invoice_duplicate_generation_blocked',['reason'=>'Inny proces generuje już fakturę.'],$registration_id,null);
            return false;
        }
        try {
            if(self::has_invoice($registration_id)){
                BCS_Utils::log('invoice_duplicate_generation_blocked',['reason'=>'Dla zgłoszenia istnieje już faktura.'],$registration_id,null);
                return false;
            }
            $path=self::ensure_invoice($registration_id); if(!$path || !file_exists($path)) return false;
            $invoice=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('invoices')." WHERE registration_id=%d ORDER BY id DESC LIMIT 1",$registration_id));
            $r=self::registration_row($registration_id); if(!$invoice||!$r)return false;
        $ctx=BCS_Communication_Engine::registration_context($registration_id);$vars=$ctx['vars']??[];
        $vars['{{INVOICE_NUMBER}}']=$invoice->invoice_number;$vars['{{INVOICE_AMOUNT}}']=number_format((float)$invoice->gross_amount,2,',',' ');
        $vars['{{INVOICE_URL}}']=BCS_Document_Engine::download_url($registration_id,'invoice');
        $tpl=BCS_Communication_Engine::templates()['invoice_issued']??[];
        $subject=BCS_Template_Engine::render((string)($tpl['subject']??'Faktura {{INVOICE_NUMBER}}'),$vars);
        $body=BCS_Template_Engine::render((string)($tpl['body']??'W załączeniu przesyłamy fakturę.'),$vars);
        $sms=BCS_SMS::strip_links(BCS_SMS::to_ascii(BCS_Template_Engine::render((string)($tpl['sms']??'Zostala wygenerowana faktura do zgloszenia. Prosze sprawdzic skrzynke pocztowa.'),$vars)));
        $email_ok=BCS_Mailer::send((string)$r->parent_email,$subject,$body,[],[$path],$registration_id);
        $sms_result=BCS_SMS::send((string)$r->parent_phone,$sms);$sms_ok=!empty($sms_result['success']);
        $now=BCS_Utils::now();
        $wpdb->update(BCS_DB::table('invoices'),['status'=>$email_ok?'sent':'generated','sent_at'=>$email_ok?$now:null,'email_status'=>$email_ok?'sent':'failed','sms_status'=>$sms_ok?'sent':'failed'],['id'=>(int)$invoice->id]);
        $wpdb->update(BCS_DB::table('registrations'),['invoice_status'=>$email_ok?'sent':'generated','invoice_sent_at'=>$email_ok?$now:null,'invoice_requested'=>0,'updated_at'=>$now],['id'=>$registration_id]);
            BCS_Utils::log('invoice_delivery',['invoice_id'=>(int)$invoice->id,'invoice_number'=>$invoice->invoice_number,'email_success'=>$email_ok,'email_error'=>$email_ok?'':BCS_Mailer::last_error(),'sms_success'=>$sms_ok,'sms_error'=>$sms_ok?'':(string)($sms_result['error']??''),'email_body'=>$body,'sms_body'=>$sms],$registration_id,null);
            return true;
        } finally {
            if($locked)$wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)",$lock_name));
        }
    }

    private static function invoice_token(int $invoice_id): string { return hash_hmac('sha256','invoice|'.$invoice_id,wp_salt('auth')); }
    public static function public_url(int $invoice_id,string $token='',string $mode='download'): string { return add_query_arg(['bcs_invoice'=>1,'invoice_id'=>$invoice_id,'token'=>self::invoice_token($invoice_id),'mode'=>$mode],home_url('/')); }
    public static function record_parent_download(int $invoice_id, string $source = 'parent_portal'): void {
        global $wpdb;
        $invoice = self::invoice_row($invoice_id);
        if (!$invoice) return;
        $now = BCS_Utils::now();
        $wpdb->query($wpdb->prepare(
            "UPDATE ".BCS_DB::table('invoices')." SET downloaded_at=COALESCE(downloaded_at,%s), download_count=download_count+1 WHERE id=%d",
            $now, $invoice_id
        ));
        BCS_Utils::log('invoice_downloaded_by_parent', [
            'invoice_id' => $invoice_id,
            'invoice_number' => (string)$invoice->invoice_number,
            'source' => $source,
            'ip' => BCS_Utils::client_ip(),
            'user_agent' => sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')),
            '_actor_type' => 'parent',
        ], (int)$invoice->registration_id, null);
    }

    public static function public_invoice(): void { if(empty($_GET['bcs_invoice']))return;$id=absint($_GET['invoice_id']??0);$token=sanitize_text_field(wp_unslash($_GET['token']??''));$row=self::invoice_row($id);if(!$row||$token===''||!hash_equals(self::invoice_token($id),$token))wp_die('Nieprawidłowy dostęp.','Basketmania Camp',['response'=>403]);self::record_parent_download($id,'public_invoice_link');self::stream($row,true); }
    public static function stream_invoice(): void { if(!current_user_can('manage_options'))wp_die('Brak uprawnień.');$id=absint($_GET['invoice_id']??0);check_admin_referer('bcs_invoice_view_'.$id);$row=self::invoice_row($id);if(!$row)wp_die('Nie znaleziono faktury.');self::stream($row,false,true); }
    public static function download_invoice(): void { if(!current_user_can('manage_options'))wp_die('Brak uprawnień.');$id=absint($_GET['invoice_id']??0);check_admin_referer('bcs_invoice_download_'.$id);$row=self::invoice_row($id);if(!$row)wp_die('Nie znaleziono faktury.');self::stream($row,false,false); }
    private static function stream(object $row,bool $public=false,bool $inline=false): void { $path=(string)$row->file_path;if(!$path||!file_exists($path))wp_die('Plik faktury nie istnieje.');nocache_headers();header('Content-Type: application/pdf');header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="'.sanitize_file_name('faktura-'.str_replace('/','-',$row->invoice_number).'.pdf').'"');header('Content-Length: '.filesize($path));readfile($path);exit; }

    public static function delete_invoice(): void { if(!current_user_can('manage_options'))wp_die('Brak uprawnień.');$id=absint($_POST['invoice_id']??0);check_admin_referer('bcs_invoice_delete_'.$id);global $wpdb;$row=self::invoice_row($id);if(!$row)wp_die('Nie znaleziono faktury.');if($row->file_path&&file_exists($row->file_path))@unlink($row->file_path);$wpdb->delete(BCS_DB::table('invoices'),['id'=>$id]);$remaining=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".BCS_DB::table('invoices')." WHERE registration_id=%d",$row->registration_id));if(!$remaining)$wpdb->update(BCS_DB::table('registrations'),['invoice_status'=>'ready_to_generate','invoice_sent_at'=>null,'updated_at'=>BCS_Utils::now()],['id'=>$row->registration_id]);BCS_Utils::log('invoice_deleted',['invoice_id'=>$id,'invoice_number'=>$row->invoice_number],(int)$row->registration_id,null);wp_safe_redirect(admin_url('admin.php?page=bcs-invoices&deleted=1'));exit; }

    public static function page(): void {
        if(!current_user_can('manage_options')) return;
        global $wpdb;
        $search=sanitize_text_field(wp_unslash($_GET['s']??''));
        $organizer_id=absint($_GET['organizer_id']??0);
        $where=['1=1']; $args=[];
        if($search!=='') { $like='%'.$wpdb->esc_like($search).'%'; $where[]='(i.invoice_number LIKE %s OR r.parent_first_name LIKE %s OR r.parent_last_name LIKE %s OR r.child_first_name LIKE %s OR r.child_last_name LIKE %s OR c.name LIKE %s)'; $args=array_merge($args,[$like,$like,$like,$like,$like,$like]); }
        if($organizer_id){$where[]='i.organizer_id=%d';$args[]=$organizer_id;}
        $sql="SELECT i.*,r.parent_first_name,r.parent_last_name,r.child_first_name,r.child_last_name,c.name camp_name,o.name organizer_name FROM ".BCS_DB::table('invoices')." i JOIN ".BCS_DB::table('registrations')." r ON r.id=i.registration_id JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=i.organizer_id WHERE ".implode(' AND ',$where)." ORDER BY i.id DESC";
        $rows=$args?$wpdb->get_results($wpdb->prepare($sql,$args)):$wpdb->get_results($sql);
        $organizers=$wpdb->get_results("SELECT id,name FROM ".BCS_DB::table('organizers')." ORDER BY name");
        echo '<div class="wrap bcs-admin bcs-invoices-page"><div class="bcs-page-head"><div><h1>Faktury</h1><p>Lista dokumentów sprzedaży wygenerowanych przez system.</p></div><span class="bcs-count">'.count($rows).' faktur</span></div>';
        if(isset($_GET['deleted'])) echo '<div class="notice notice-success is-dismissible"><p>Faktura została usunięta.</p></div>';
        echo '<section class="bcs-panel"><form method="get" class="bcs-invoice-filters"><input type="hidden" name="page" value="bcs-invoices"><input type="search" name="s" value="'.esc_attr($search).'" placeholder="Szukaj numeru, klienta, uczestnika lub turnusu"><select name="organizer_id"><option value="0">Wszyscy organizatorzy</option>';
        foreach($organizers as $o) echo '<option value="'.(int)$o->id.'" '.selected($organizer_id,(int)$o->id,false).'>'.esc_html($o->name).'</option>';
        echo '</select><button class="button button-primary">Filtruj</button><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-invoices')).'">Wyczyść</a></form><div class="bcs-table-wrap"><table class="widefat striped bcs-table"><thead><tr><th>#ID</th><th>Numer faktury</th><th>Klient</th><th>Turnus</th><th>Organizator</th><th>Wygenerowano</th><th>Wysłano</th><th>Pobrano</th><th>Akcje</th></tr></thead><tbody>';
        if(!$rows) echo '<tr><td colspan="9">Brak faktur spełniających kryteria.</td></tr>';
        foreach($rows as $r){
            $view=wp_nonce_url(admin_url('admin-post.php?action=bcs_invoice_view&invoice_id='.(int)$r->id),'bcs_invoice_view_'.(int)$r->id);$download=wp_nonce_url(admin_url('admin-post.php?action=bcs_invoice_download&invoice_id='.(int)$r->id),'bcs_invoice_download_'.(int)$r->id);
            echo '<tr><td><strong>#'.(int)$r->id.'</strong></td><td><strong>'.esc_html($r->invoice_number).'</strong><br><small>'.number_format((float)$r->gross_amount,2,',',' ').' PLN</small></td><td>'.esc_html($r->parent_first_name.' '.$r->parent_last_name).'<br><small>Uczestnik: '.esc_html($r->child_first_name.' '.$r->child_last_name).'</small></td><td>'.esc_html($r->camp_name).'</td><td>'.esc_html($r->organizer_name?:'—').'</td><td>'.esc_html(BCS_Utils::format_datetime($r->created_at)).'</td><td>'.($r->sent_at?'<span class="bcs-status-ok">✓ '.esc_html(BCS_Utils::format_datetime($r->sent_at)).'</span>':'<span class="bcs-muted">Nie</span>').'</td><td>'.($r->downloaded_at?'<span class="bcs-status-ok">✓ '.esc_html(BCS_Utils::format_datetime($r->downloaded_at)).' ('.(int)$r->download_count.')</span>':'<span class="bcs-muted">Nie</span>').'</td><td><div class="bcs-icon-actions"><button type="button" class="button bcs-invoice-preview" data-url="'.esc_url($view).'" title="Podgląd"><span class="dashicons dashicons-visibility"></span></button><a class="button" href="'.esc_url($download).'" title="Pobierz PDF"><span class="dashicons dashicons-download"></span></a><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="bcs-invoice-delete-form"><input type="hidden" name="action" value="bcs_invoice_delete"><input type="hidden" name="invoice_id" value="'.(int)$r->id.'">';wp_nonce_field('bcs_invoice_delete_'.(int)$r->id);echo '<button type="submit" class="button bcs-delete-invoice" title="Usuń"><span class="dashicons dashicons-no-alt"></span></button></form></div></td></tr>';
        }
        echo '</tbody></table></div></section><div id="bcs-invoice-modal" class="bcs-invoice-modal" hidden><div class="bcs-invoice-modal__dialog"><button type="button" class="bcs-invoice-modal__close" aria-label="Zamknij">×</button><iframe title="Podgląd faktury"></iframe></div></div></div>';
    }

}
