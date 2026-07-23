<?php
if (!defined('ABSPATH')) exit;

class BCS_Workflow {
    private static array $last_form_verification_result = [];

    public static function last_form_verification_result(): array { return self::$last_form_verification_result; }

    public static function test_mode_enabled(): bool {
        $settings = get_option('bcs_settings', []);
        return !array_key_exists('test_workflow_mode', $settings) || !empty($settings['test_workflow_mode']);
    }
    public static function init(): void {
        add_action('admin_init', [__CLASS__, 'handle_actions']);
        add_action('admin_post_bcs_workflow_single',[__CLASS__,'handle_single']);
    }


    public static function handle_single(): void {
        if(!current_user_can('manage_options'))wp_die('Brak uprawnień.');
        $id=absint($_GET['registration_id']??0);$action=sanitize_key(wp_unslash($_GET['workflow']??''));
        check_admin_referer('bcs_workflow_single_'.$id.'_'.$action);
        $result=match($action){'confirm_registration'=>self::confirm_registration($id),'send_agreement'=>self::send_agreement($id),'send_stripe_link'=>self::send_stripe_link($id),'mark_bank_paid'=>self::mark_bank_paid($id),'remind_payment'=>self::remind_payment($id),'generate_invoice'=>self::generate_invoice($id),'verify_form'=>self::verify_form($id),default=>false};
        $args=['page'=>'bcs-registrations','view'=>$id,'done'=>$result?1:0,'failed'=>$result?0:1];
        if(in_array($action,['send_agreement','remind_payment'],true)){
            $comm=BCS_Communication_Engine::last_send_result();
            if($comm){set_transient('bcs_workflow_comm_'.get_current_user_id().'_'.$id,$comm,5*MINUTE_IN_SECONDS);$args['communication_action']=1;}
        }
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php')));exit;
    }

    public static function statuses(): array {
        return [
            'new'=>'Nowe zgłoszenie',
            'admin_confirmed'=>'Oczekuje na formularz obozowy',
            'form_complete'=>'Formularz obozowy do zaakceptowania',
            'draft_sent'=>'Formularz zaakceptowany – wysłano draft umowy obozowej',
            'agreement_sent'=>'Umowa wysłana do podpisania',
            'awaiting_bank_payment'=>'Oczekuje na przelew',
            'stripe_link_sent'=>'Link Stripe wysłany',
            'partially_paid'=>'Częściowo opłacone',
            'paid'=>'Opłacone',
            'cancelled'=>'Anulowane',
        ];
    }

    public static function handle_actions(): void {
        if (!current_user_can('manage_options') || empty($_POST['bcs_workflow_action'])) return;
        check_admin_referer('bcs_workflow_action');
        $action=sanitize_key(wp_unslash($_POST['bcs_workflow_action']));
        $ids=array_values(array_filter(array_map('absint',(array)($_POST['registration_ids']??[]))));
        if (!$ids && !empty($_POST['registration_id'])) $ids=[absint($_POST['registration_id'])];
        $ok=0;$failed=0;
        foreach($ids as $id){
            $result=match($action){
                'confirm_registration'=>self::confirm_registration($id),
                'send_agreement'=>self::send_agreement($id),
                'send_stripe_link'=>self::send_stripe_link($id),
                'mark_bank_paid'=>self::mark_bank_paid($id),
                'remind_payment'=>self::remind_payment($id),
                'generate_invoice'=>self::generate_invoice($id),
                'verify_form'=>self::verify_form($id),
                default=>false,
            };
            $result?$ok++:$failed++;
        }
        wp_safe_redirect(add_query_arg(['page'=>'bcs-registrations','done'=>$ok,'failed'=>$failed],admin_url('admin.php')));exit;
    }

    public static function confirm_registration(int $id): bool {
        global $wpdb;
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('registrations')." WHERE id=%d",$id));
        if(!$r || $r->status==='cancelled')return false;
        $now=BCS_Utils::now();
        $updated=$wpdb->update(BCS_DB::table('registrations'),[
            'status'=>'admin_confirmed','agreement_status'=>'draft','admin_confirmed_at'=>$now,'admin_confirmed_by'=>get_current_user_id(),
            'agreement_available_from'=>self::default_agreement_date($r),'updated_at'=>$now
        ],['id'=>$id]);
        if($updated===false)return false;
        $portal_sent=BCS_Frontend::send_parent_portal_invite($id, false);
        BCS_Utils::log('registration_admin_confirmed',['portal_link_sent'=>$portal_sent], $id,null);
        return true;
    }

    public static function verify_form(int $id): bool {
        global $wpdb;
        self::$last_form_verification_result = [];
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('registrations')." WHERE id=%d",$id));
        if(!$r || $r->status==='cancelled' || $r->form_status!=='complete') return false;

        $agreement_id=(int)$r->agreement_id;
        if(!$agreement_id) $agreement_id=BCS_Agreements::build_for_registration($id,'draft',false);
        if(!$agreement_id) return false;

        $now=BCS_Utils::now();
        $updated=$wpdb->update(BCS_DB::table('registrations'),[
            'form_verified_at'=>$now,
            'form_verified_by'=>get_current_user_id(),
            'status'=>'draft_sent',
            'draft_sent_at'=>$now,
            'updated_at'=>$now
        ],['id'=>$id]);
        if($updated===false) return false;

        // PDF draftu jest generowany pomocniczo, ale jego brak nie cofa zatwierdzenia formularza.
        $draft=BCS_Document_Engine::agreement_pdf($id,'draft');
        $draft_ready=(bool)($draft && is_file($draft));

        // Informacja do rodzica korzysta z edytowalnego szablonu komunikacji.
        $email_sent=BCS_Communication_Engine::send_to_registration($id,'camp_form_verified','both');
        self::$last_form_verification_result = [
            'verified'=>true,
            'email'=>$email_sent,
            'draft'=>$draft_ready,
        ];
        BCS_Utils::log('camp_form_verified',[
            'draft_pdf'=>$draft_ready?$draft:'',
            'draft_ready'=>$draft_ready,
            'email'=>$r->parent_email,
            'email_sent'=>$email_sent,
        ],$id,$agreement_id);
        return true;
    }

    private static function default_agreement_date(object $r): string {
        $camp_year=(int)substr((string)$r->created_at,0,4);
        global $wpdb;
        $start=$wpdb->get_var($wpdb->prepare("SELECT start_date FROM ".BCS_DB::table('camps')." WHERE id=%d",$r->camp_id));
        if($start)$camp_year=(int)substr($start,0,4);
        return sprintf('%04d-01-01',$camp_year);
    }

    public static function send_agreement(int $id): bool {
        global $wpdb;
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('registrations')." WHERE id=%d",$id));
        if(!$r || $r->status==='cancelled' || empty($r->form_verified_at))return false;
        $available=$r->agreement_available_from ?: self::default_agreement_date($r);
        $test_mode=self::test_mode_enabled();
        if(!$test_mode && current_time('Y-m-d') < $available) return false;
        $is_reminder=($r->agreement_status==='pending' && !empty($r->agreement_id));
        if($is_reminder){
            $agreement_id=(int)$r->agreement_id;
        } else {
            // Publikujemy dokładnie ten draft, który został utworzony po weryfikacji
            // formularza i ewentualnie poprawiony przez administratora. Nie generujemy
            // umowy ponownie, dzięki czemu rodzic podpisuje identyczną treść.
            $agreement_id=BCS_Agreements::publish_draft($id);
            if(!$agreement_id)return false;
        }
        $now=BCS_Utils::now();
        $wpdb->update(BCS_DB::table('registrations'),['status'=>'agreement_sent','agreement_status'=>'pending','agreement_sent_at'=>$now,'agreement_sent_by'=>get_current_user_id(),'updated_at'=>$now],['id'=>$id]);
        $communication_ok=BCS_Communication_Engine::send_to_registration($id,'agreement_sent','both');
        BCS_Utils::log($is_reminder?'agreement_signature_reminder_sent':'agreement_sent_by_admin',['email_sms_requested'=>true,'communication_success'=>$communication_ok], $id,$agreement_id); return true;
    }

    public static function send_stripe_link(int $id): bool {
        global $wpdb;
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('registrations')." WHERE id=%d",$id));
        if(!$r || $r->status==='cancelled' || empty($r->form_verified_at)) return false;
        if((float)$r->total_amount > 0 && (float)$r->paid_amount >= (float)$r->total_amount) return false;
        $payment=BCS_Payments::create_checkout($id); if(is_wp_error($payment)) return false;
        $templates=BCS_Communication_Engine::templates();$tpl=$templates['stripe_link']??[];$ctx=BCS_Communication_Engine::registration_context($id);
        if(!$ctx) return false;
        $vars=$ctx['vars'];$vars['{{STRIPE_URL}}']=$payment['url'];$subject=strtr((string)($tpl['subject']??'Link do płatności online'),$vars);$body=strtr((string)($tpl['body']??'{{STRIPE_URL}}'),$vars);
        $sent=BCS_Mailer::send($ctx['row']->parent_email,$subject,$body,['Content-Type: text/html; charset=UTF-8'],[],$id);
        if(!$sent){
            $wpdb->update(BCS_DB::table('payments'),['status'=>'failed','updated_at'=>BCS_Utils::now()],['id'=>(int)$payment['payment_id']]);
            BCS_Utils::log('stripe_link_email_failed',['payment_id'=>$payment['payment_id'],'error'=>BCS_Mailer::last_error()],$id,(int)$r->agreement_id);
            return false;
        }
        $now=BCS_Utils::now();
        $wpdb->update(BCS_DB::table('registrations'),['status'=>'stripe_link_sent','stripe_link_sent_at'=>$now,'stripe_link_sent_by'=>get_current_user_id(),'updated_at'=>$now],['id'=>$id]);
        BCS_Utils::log('stripe_link_sent',['payment_id'=>$payment['payment_id']],$id,(int)$r->agreement_id); return true;
    }

    public static function invoice_available(int $id): bool {
        global $wpdb;
        if(class_exists('BCS_Invoices') && BCS_Invoices::has_invoice($id)) return false;
        $r=$wpdb->get_row($wpdb->prepare("SELECT r.status,r.agreement_status,r.total_amount,r.paid_amount,c.start_date FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id WHERE r.id=%d",$id));
        if(!$r || $r->status==='cancelled' || $r->agreement_status!=='accepted') return false;
        if((float)$r->total_amount<=0 || (float)$r->paid_amount<(float)$r->total_amount) return false;
        $camp_year=(int)substr((string)$r->start_date,0,4);
        if($camp_year<2000) return false;
        return self::test_mode_enabled() || BCS_Utils::today('Y-m-d') >= sprintf('%04d-01-01',$camp_year);
    }

    public static function refresh_invoice_readiness(int $id): void {
        global $wpdb;
        $r=$wpdb->get_row($wpdb->prepare("SELECT invoice_status FROM ".BCS_DB::table('registrations')." WHERE id=%d",$id));
        if(!$r || in_array($r->invoice_status,['generated','sent'],true)) return;
        $wpdb->update(BCS_DB::table('registrations'),[
            'invoice_status'=>self::invoice_available($id)?'ready_to_generate':'not_generated',
            'updated_at'=>BCS_Utils::now(),
        ],['id'=>$id]);
    }

    public static function remind_payment(int $id): bool {
        global $wpdb;
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('registrations')." WHERE id=%d",$id));
        if(!$r || $r->status==='cancelled' || $r->agreement_status!=='accepted') return false;
        $amount_due=max(0,(float)$r->total_amount-(float)$r->paid_amount);
        if($amount_due<=0) return false;
        $sent=BCS_Communication_Engine::send_to_registration($id,'payment','both');
        BCS_Utils::log('payment_reminder_sent',['amount_due'=>$amount_due,'channel'=>'email_sms','communication_success'=>$sent],$id,(int)$r->agreement_id);
        return $sent;
    }

    public static function mark_bank_paid(int $id): bool {
        global $wpdb;
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('registrations')." WHERE id=%d",$id));
        if(!$r || $r->status==='cancelled' || $r->agreement_status!=='accepted') return false;
        if((float)$r->total_amount > 0 && (float)$r->paid_amount >= (float)$r->total_amount) return false;
        $now=BCS_Utils::now();
        $external_id=str_replace(['-',' ',':'],'',$now);
        $amount=max(0,(float)$r->total_amount-(float)$r->paid_amount);
        $inserted=$wpdb->insert(BCS_DB::table('payments'),[
            'registration_id'=>$id,'organizer_id'=>(int)$r->organizer_id,'provider'=>'bank',
            'external_id'=>$external_id,'amount'=>$amount,'currency'=>'PLN','status'=>'paid',
            'paid_at'=>$now,'created_at'=>$now,'updated_at'=>$now,
        ]);
        if(!$inserted) return false;
        $payment_id=(int)$wpdb->insert_id;
        $updated=$wpdb->update(BCS_DB::table('registrations'),[
            'payment_id'=>$payment_id,'paid_amount'=>$r->total_amount,'status'=>'paid','updated_at'=>$now,
        ],['id'=>$id]);
        if($updated===false) return false;
        self::refresh_invoice_readiness($id);
        if(class_exists('BCS_Communications'))BCS_Communication_Engine::send_to_registration($id,'paid','email','', '', false);
        BCS_Utils::log('bank_payment_marked_paid',['payment_id'=>$external_id,'payment_record_id'=>$payment_id,'confirmed_at'=>$now], $id,(int)$r->agreement_id); return true;
    }

    public static function generate_invoice(int $id): bool {
        global $wpdb;
        if(BCS_Invoices::has_invoice($id)){
            BCS_Utils::log('invoice_duplicate_generation_blocked',['reason'=>'Próba ponownego wygenerowania faktury dla tego samego zgłoszenia.'],$id,null);
            return false;
        }
        if(!self::invoice_available($id)) return false;
        $ok=BCS_Invoices::generate_and_send($id);
        if(!$ok) return false;
        BCS_Utils::log('invoice_generated_manually',['delivery_started'=>true],$id,null);return true;
    }

    private static function send_custom(int $id,string $key,string $subject,string $body): bool {
        $ctx=BCS_Communication_Engine::registration_context($id); if(!$ctx)return false;
        $vars=$ctx['vars'];
        global $wpdb;
        $r=$ctx['row'];
        $vars['{{AGREEMENT_AVAILABLE_FROM}}']=$r->agreement_available_from ? wp_date('d.m.Y',strtotime($r->agreement_available_from)) : '';
        $subject=strtr($subject,$vars);$body=strtr($body,$vars);
        $ok=BCS_Mailer::send($r->parent_email,$subject,$body,['Content-Type: text/html; charset=UTF-8'],[],$id);
        BCS_Utils::log($key,['email'=>$r->parent_email,'success'=>$ok],$id,(int)$r->agreement_id);return $ok;
    }

    public static function registrations_page(): void {
        global $wpdb;
        $camp_id = absint($_GET['camp_id'] ?? 0);
        $status_filter = sanitize_key(wp_unslash($_GET['status'] ?? ''));
        $where = ['1=1']; $params = [];
        if ($camp_id) { $where[] = 'r.camp_id=%d'; $params[] = $camp_id; }
        if ($status_filter && array_key_exists($status_filter, self::statuses())) { $where[] = 'r.status=%s'; $params[] = $status_filter; }
        $sql = "SELECT r.*,c.name camp_name,c.start_date,a.agreement_number,a.status agreement_real_status FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id WHERE ".implode(' AND ', $where)." ORDER BY r.created_at DESC";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params)) : $wpdb->get_results($sql);
        $camps = $wpdb->get_results("SELECT id,name,start_date FROM ".BCS_DB::table('camps')." ORDER BY start_date DESC");
        $labels=self::statuses();
        echo '<div class="wrap bcs-admin"><div class="bcs-page-head"><div><h1>Zgłoszenia</h1><p>Obsługa rejestracji, umów, płatności i faktur.</p></div><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-dashboard')).'">Dashboard</a></div>';
        if(isset($_GET['done'])) echo '<div class="notice notice-success is-dismissible"><p>Wykonano: '.absint($_GET['done']).'. Błędy lub pominięte: '.absint($_GET['failed']??0).'.</p></div>';
        if(!empty($_GET['deleted'])) echo '<div class="notice notice-success is-dismissible"><p>Zgłoszenie zostało trwale usunięte.</p></div>';
        if(!empty($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Zapisano zmiany zgłoszenia.</p></div>';
        echo '<form method="get" class="bcs-toolbar"><input type="hidden" name="page" value="bcs-registrations"><strong>Filtry</strong><div class="bcs-filter"><select name="camp_id"><option value="0">Wszystkie turnusy</option>';
        foreach($camps as $c) echo '<option value="'.(int)$c->id.'" '.selected($camp_id,(int)$c->id,false).'>'.esc_html($c->name.' — '.$c->start_date).'</option>';
        echo '</select><select name="status"><option value="">Wszystkie statusy</option>';
        foreach($labels as $k=>$l) echo '<option value="'.esc_attr($k).'" '.selected($status_filter,$k,false).'>'.esc_html($l).'</option>';
        echo '</select><button class="button">Filtruj</button><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-registrations')).'">Wyczyść</a></div></form>';
        echo '<form method="post">';wp_nonce_field('bcs_workflow_action');
        echo '<div class="bcs-toolbar"><select name="bcs_workflow_action"><option value="confirm_registration">Potwierdź rejestrację i wyślij formularz</option><option value="send_agreement">Wyślij umowę do akceptacji</option><option value="send_stripe_link">Wyślij link Stripe</option><option value="mark_bank_paid">Oznacz przelew jako opłacony</option><option value="generate_invoice">Wygeneruj fakturę/rachunek</option></select><button class="button button-primary">Wykonaj dla zaznaczonych</button><span class="bcs-muted">Znaleziono: '.count($rows).'</span></div>';
        echo '<div class="bcs-table-wrap"><table class="widefat bcs-table"><thead><tr><th><input type="checkbox" data-bcs-check-all></th><th>#ID</th><th>Dziecko / rodzic</th><th>Turnus</th><th>Status procesu</th><th>Umowa</th><th>Płatność</th><th>Faktura</th><th>Akcje</th></tr></thead><tbody>';
        foreach($rows as $r){$available=$r->agreement_available_from?:self::default_agreement_date($r);$can_send=self::test_mode_enabled() || current_time('Y-m-d') >= $available;$paid=(float)$r->paid_amount >= (float)$r->total_amount && (float)$r->total_amount>0;
            echo '<tr><td><input class="bcs-reg-check" type="checkbox" name="registration_ids[]" value="'.(int)$r->id.'"></td><td><strong class="bcs-id">#'.(int)$r->id.'</strong></td><td><strong>'.esc_html($r->child_first_name.' '.$r->child_last_name).'</strong><br>'.esc_html($r->parent_first_name.' '.$r->parent_last_name).'<br><small>'.esc_html($r->parent_email.' · '.$r->parent_phone).'</small></td><td><strong>'.esc_html($r->camp_name).'</strong><br><small>'.esc_html($r->start_date).'</small></td><td><span class="bcs-badge status-'.($r->status==='paid'?'open':($r->status==='cancelled'?'closed':'draft')).'">'.esc_html($labels[$r->status]??$r->status).'</span><br><small>Umowa od: '.esc_html(wp_date('d.m.Y',strtotime($available))).'</small></td><td>'.esc_html($r->agreement_number?:'—').'<br><small>'.esc_html($r->agreement_status).'</small>'.($can_send?'':'<br><small>Aktywne od 1 stycznia</small>').'</td><td><strong>'.esc_html(number_format((float)$r->paid_amount,2,',',' ').' / '.number_format((float)$r->total_amount,2,',',' ')).' zł</strong><br><small>'.($paid?'opłacone':'oczekuje').'</small>'.($r->payment_id?'<br><small class="bcs-id">Płatność #'.(int)$r->payment_id.'</small>':'').'</td><td>'.esc_html($r->invoice_status).'</td><td><div class="bcs-row-actions">'.self::row_buttons($r,$can_send,$paid).'</div></td></tr>';
        }
        if(!$rows) echo '<tr><td colspan="8"><div class="bcs-empty">Brak zgłoszeń odpowiadających filtrom.</div></td></tr>';
        echo '</tbody></table></div></form></div>';
    }

    private static function row_buttons(object $r,bool $can_send,bool $paid): string {
        $make=function(string $action,string $label,bool $enabled=true)use($r):string{
            if(!$enabled)return '<span class="button disabled" style="opacity:.55">'.esc_html($label).'</span>';
            $url=wp_nonce_url(add_query_arg(['action'=>'bcs_workflow_single','registration_id'=>$r->id,'workflow'=>$action],admin_url('admin-post.php')),'bcs_workflow_single_'.$r->id.'_'.$action);
            return '<a class="button" style="margin:2px" href="'.esc_url($url).'">'.esc_html($label).'</a>';
        };
        $edit = '<a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bcs-registrations&edit='.$r->id)).'">Edytuj</a>';
        $form_verified=!empty($r->form_verified_at);
        $agreement_accepted=($r->agreement_status==='accepted' || ($r->agreement_real_status??'')==='accepted');
        return $edit.
            $make('confirm_registration','Potwierdź',in_array($r->status,['new','admin_confirmed'],true)).
            $make('send_agreement','Wyślij umowę',$can_send && $form_verified && !$agreement_accepted && !empty($r->agreement_id)).
            $make('send_stripe_link','Link Stripe',$form_verified && !$paid).
            $make('mark_bank_paid','Zaksięguj',$agreement_accepted && !$paid).
            ($r->invoice_status==='generated'||$r->invoice_status==='sent'?'':$make('generate_invoice','Faktura',$paid && BCS_Workflow::invoice_available((int)$r->id)));
    }
}
