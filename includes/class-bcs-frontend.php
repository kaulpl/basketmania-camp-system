<?php
if (!defined('ABSPATH')) exit;

class BCS_Frontend {
    public static function init(): void {
        add_shortcode('basketmania_signup', [__CLASS__, 'signup_shortcode']);
        add_shortcode('basketmania_portal', [__CLASS__, 'portal_shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_post_nopriv_bcs_signup', [__CLASS__, 'handle_signup']);
        add_action('admin_post_bcs_signup', [__CLASS__, 'handle_signup']);
        add_action('admin_post_nopriv_bcs_complete_registration', [__CLASS__, 'complete_registration']);
        add_action('admin_post_bcs_complete_registration', [__CLASS__, 'complete_registration']);
        add_action('wp_ajax_nopriv_bcs_parent_form_lock_status', [__CLASS__, 'parent_form_lock_status']);
        add_action('wp_ajax_bcs_parent_form_lock_status', [__CLASS__, 'parent_form_lock_status']);
    }
    public static function parent_form_lock_status(): void {
        $id=absint($_POST['registration_id']??0);
        $token=sanitize_text_field(wp_unslash($_POST['token']??''));
        global $wpdb;
        $r=$wpdb->get_row($wpdb->prepare("SELECT form_verified_at FROM ".BCS_DB::table('registrations')." WHERE id=%d AND public_token=%s",$id,$token));
        if(!$r) wp_send_json_error(['message'=>'Nie znaleziono zgłoszenia.'],404);
        wp_send_json_success([
            'locked'=>empty($r->form_verified_at)&&BCS_Locks::active($id),
            'verified'=>!empty($r->form_verified_at),
            'remaining'=>BCS_Locks::remaining($id),
        ]);
    }
    public static function assets(): void {
        wp_register_style('bcs-front', BCS_URL . 'assets/css/front.css', [], BCS_VERSION);
        wp_register_script('bcs-front', BCS_URL . 'assets/js/front.js', [], BCS_VERSION, true);
        wp_localize_script('bcs-front', 'BCS', ['ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('bcs_front')]);
    }
    private static function detect_device_type(string $user_agent): string {
        $ua = strtolower($user_agent);
        if ($ua === '') return 'unknown';
        if (preg_match('/ipad|tablet|kindle|silk|playbook|android(?!.*mobile)/i', $ua)) return 'tablet';
        if (preg_match('/mobile|iphone|ipod|android|blackberry|iemobile|opera mini/i', $ua)) return 'mobile';
        return 'desktop';
    }
    private static function portal_url(string $token): string {
        $page=get_page_by_path('panel-rodzica');
        return add_query_arg('token',$token,$page?get_permalink($page):home_url('/panel-rodzica/'));
    }
    public static function signup_shortcode(): string {
        global $wpdb; wp_enqueue_style('bcs-front');
        $camps=$wpdb->get_results("SELECT c.*, (SELECT COUNT(*) FROM ".BCS_DB::table('registrations')." r WHERE r.camp_id=c.id AND r.status<>'cancelled') registered FROM ".BCS_DB::table('camps')." c WHERE c.status='open' ORDER BY c.start_date ASC");
        $camps=array_values(array_filter($camps,fn($c)=>(int)$c->capacity===0 || (int)$c->registered<(int)$c->capacity));
        $sizes=['128-134','134-140','140-146','146-152','152-158','158-164','S-164-170','M-170-176','L-176-182','XL-182-188','2XL-188-194','3XL-194-200'];
        $registered=absint($_GET['bcs_registered']??0);
        ob_start(); ?><div class="bcs-wrap bcs-basket"><div class="bcs-brand"><div class="bcs-ball">●</div><div><strong>Basketmania Camp</strong><span>Formularz wstępnego zgłoszenia</span></div></div><?php if($registered):?><div class="bcs-success"><h2>Zgłoszenie zostało przyjęte</h2><p>Administrator sprawdzi dostępność i dane. Po potwierdzeniu rejestracji otrzymasz e-mailem indywidualny link do panelu rodzica oraz pełnego formularza uczestnika.</p></div><?php elseif(!$camps):?><div class="bcs-alert">Aktualnie nie ma dostępnych, aktywnych turnusów.</div><?php else:?><form class="bcs-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="bcs_signup"><input type="hidden" name="return_url" value="<?php echo esc_url(get_permalink());?>"><?php wp_nonce_field('bcs_signup');?><h2><?php echo esc_html(BCS_Template_Engine::get('ui','signup_title','Zapisz uczestnika na Basketmania Camp'));?></h2><p>Podaj podstawowe dane. Pełny formularz zdrowotny i organizacyjny będzie dostępny po zatwierdzeniu rejestracji.</p><div class="bcs-grid"><label>Termin turnusu<select name="camp_id" required><option value="">Wybierz turnus</option><?php foreach($camps as $c):?><option value="<?php echo (int)$c->id;?>"><?php echo esc_html($c->name.' · '.wp_date('d.m.Y',strtotime($c->start_date)).'–'.wp_date('d.m.Y',strtotime($c->end_date)).' · '.$c->location);?></option><?php endforeach;?></select></label><label>Imię i nazwisko opiekuna<input name="parent_name" autocomplete="name" required></label><label>E-mail kontaktowy<input type="email" name="parent_email" autocomplete="email" required></label><label>Telefon<input name="parent_phone" autocomplete="tel" required></label><label>Imię uczestnika<input name="child_first_name" required></label><label>Nazwisko uczestnika<input name="child_last_name" required></label><label>Data urodzenia<input type="date" name="child_birth_date" required></label><label>Wzrost uczestnika (cm)<input type="number" name="child_height" min="100" max="230" step="1" required></label><label>Rozmiar stroju<select name="shirt_size" required><option value="">Wybierz rozmiar</option><?php foreach($sizes as $size):?><option value="<?php echo esc_attr($size);?>"><?php echo esc_html($size);?></option><?php endforeach;?></select></label></div><label class="bcs-check"><input type="checkbox" required> Potwierdzam prawdziwość danych i zapoznałem/-am się z informacją o przetwarzaniu danych.</label><button class="bcs-button">Wyślij zgłoszenie</button></form><?php endif;?></div><?php return (string)ob_get_clean();
    }
    public static function handle_signup(): void {
        check_admin_referer('bcs_signup'); global $wpdb;
        $camp_id=absint($_POST['camp_id']??0);$camp=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('camps')." WHERE id=%d AND status='open'",$camp_id));if(!$camp)wp_die('Turnus jest niedostępny.');
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".BCS_DB::table('registrations')." WHERE camp_id=%d AND status<>'cancelled'",$camp_id));if((int)$camp->capacity>0 && $count>=(int)$camp->capacity)wp_die('Brak wolnych miejsc na wybrany turnus.');
        $parent_name=preg_replace('/\s+/',' ',trim(sanitize_text_field(wp_unslash($_POST['parent_name']??''))));$parts=explode(' ',$parent_name,2);$parent_first=$parts[0]??'';$parent_last=$parts[1]??'';
        $now=BCS_Utils::now();$token=BCS_Utils::random_token();$user_agent=sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']??''));$device_type=self::detect_device_type($user_agent);
        $ok=$wpdb->insert(BCS_DB::table('registrations'),['camp_id'=>$camp_id,'public_token'=>$token,'status'=>'new','form_status'=>'incomplete','source'=>'website_form','device_type'=>$device_type,'device_user_agent'=>$user_agent,'parent_first_name'=>$parent_first,'parent_last_name'=>$parent_last,'parent_email'=>sanitize_email(wp_unslash($_POST['parent_email']??'')),'parent_phone'=>sanitize_text_field(wp_unslash($_POST['parent_phone']??'')),'child_first_name'=>sanitize_text_field(wp_unslash($_POST['child_first_name']??'')),'child_last_name'=>sanitize_text_field(wp_unslash($_POST['child_last_name']??'')),'child_birth_date'=>sanitize_text_field(wp_unslash($_POST['child_birth_date']??'')),'child_height'=>absint($_POST['child_height']??0),'shirt_size'=>sanitize_text_field(wp_unslash($_POST['shirt_size']??'')),'total_amount'=>(float)$camp->price,'paid_amount'=>0,'created_at'=>$now,'updated_at'=>$now]);
        if(!$ok)wp_die('Nie udało się zapisać zgłoszenia.');
        $id=(int)$wpdb->insert_id;BCS_Utils::log('registration_created',['source'=>'website_form'],$id,null);
        if(class_exists('BCS_Communications')) BCS_Communication_Engine::send_to_registration($id,'registration_received','email');
        $return=esc_url_raw(wp_unslash($_POST['return_url']??home_url('/')));wp_safe_redirect(add_query_arg('bcs_registered',$id,$return));exit;
    }
    public static function complete_registration(): void {
        $id=absint($_POST['registration_id']??0);$token=sanitize_text_field(wp_unslash($_POST['token']??''));check_admin_referer('bcs_complete_registration_'.$id);
        global $wpdb;$r=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('registrations')." WHERE id=%d AND public_token=%s",$id,$token));if(!$r)wp_die('Nieprawidłowy link.');
        $scope='camp';
        if(empty($r->admin_confirmed_at)){wp_safe_redirect(add_query_arg('edit_denied',1,self::portal_url($token)));exit;}
        if(empty($r->form_verified_at) && BCS_Locks::active($id) && !current_user_can('manage_options')){BCS_Utils::log('parent_form_save_blocked',['remaining_seconds'=>BCS_Locks::remaining($id)],$id,(int)$r->agreement_id);wp_safe_redirect(add_query_arg('edit_locked',1,self::portal_url($token)));exit;}
        if(!empty($r->form_verified_at)){wp_safe_redirect(add_query_arg('edit_denied',1,self::portal_url($token)));exit;}
        $fallback=function($value): string { $value=trim((string)$value); return $value===''?'brak':$value; };
        $stay_contact=sanitize_textarea_field(wp_unslash($_POST['stay_contact']??''));
        if(trim($stay_contact)==='') $stay_contact=trim($r->parent_first_name.' '.$r->parent_last_name.' — '.$r->parent_phone);
        $data=[
            'parent_first_name'=>sanitize_text_field(wp_unslash($_POST['parent_first_name']??$r->parent_first_name)),
            'parent_last_name'=>sanitize_text_field(wp_unslash($_POST['parent_last_name']??$r->parent_last_name)),
            'parent_email'=>sanitize_email(wp_unslash($_POST['parent_email']??$r->parent_email)),
            'parent_phone'=>sanitize_text_field(wp_unslash($_POST['parent_phone']??$r->parent_phone)),
            'parent_postal_code'=>sanitize_text_field(wp_unslash($_POST['parent_postal_code']??($r->parent_postal_code??''))),
            'parent_city'=>sanitize_text_field(wp_unslash($_POST['parent_city']??($r->parent_city??''))),
            'parent_street'=>sanitize_text_field(wp_unslash($_POST['parent_street']??($r->parent_street??''))),
            'parent_house_number'=>sanitize_text_field(wp_unslash($_POST['parent_house_number']??($r->parent_house_number??''))),
            'child_first_name'=>sanitize_text_field(wp_unslash($_POST['child_first_name']??$r->child_first_name)),
            'child_last_name'=>sanitize_text_field(wp_unslash($_POST['child_last_name']??$r->child_last_name)),
            'child_birth_date'=>sanitize_text_field(wp_unslash($_POST['child_birth_date']??$r->child_birth_date)),
            'child_height'=>absint($_POST['child_height']??$r->child_height),
            'shirt_size'=>sanitize_text_field(wp_unslash($_POST['shirt_size']??$r->shirt_size)),
            'child_pesel'=>sanitize_text_field(wp_unslash($_POST['child_pesel']??$r->child_pesel)),
            'child_club'=>$fallback(sanitize_text_field(wp_unslash($_POST['child_club']??$r->child_club))),
            'medical_notes'=>$fallback(sanitize_textarea_field(wp_unslash($_POST['medical_notes']??$r->medical_notes))),
            'dietary_notes'=>$fallback(sanitize_textarea_field(wp_unslash($_POST['dietary_notes']??$r->dietary_notes))),
            'stay_contact'=>$stay_contact,
            'authorized_pickup'=>sanitize_textarea_field(wp_unslash($_POST['authorized_pickup']??$r->authorized_pickup)),
            'camp_notes'=>sanitize_textarea_field(wp_unslash($_POST['camp_notes']??$r->camp_notes)),
            'form_status'=>'complete','status'=>'form_complete','form_completed_at'=>BCS_Utils::now(),'updated_at'=>BCS_Utils::now(),
        ];
        $data['parent_address']=BCS_Utils::compose_address($data);
        if($data['parent_address']==='') $data['parent_address']=sanitize_textarea_field(wp_unslash($_POST['parent_address']??$r->parent_address));
        $wpdb->update(BCS_DB::table('registrations'),$data,['id'=>$id]);
        BCS_Utils::log(($r->form_status??'')==='complete'?'parent_form_updated':'parent_form_completed',['scope'=>'camp'],$id,(int)$r->agreement_id);wp_safe_redirect(add_query_arg('saved',1,self::portal_url($token)));exit;
    }
    private static array $last_invite_result = [];

    public static function last_invite_result(): array {
        return self::$last_invite_result;
    }

    public static function send_parent_portal_invite(int $registration_id, bool $force=false): bool {
        global $wpdb;
        self::$last_invite_result = ['email'=>false,'sms'=>false,'email_error'=>'','sms_error'=>''];
        $r=$wpdb->get_row($wpdb->prepare("SELECT r.*,c.name camp_name,c.start_date,c.end_date,c.location FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id WHERE r.id=%d",$registration_id));
        if(!$r || !$r->parent_email || empty($r->admin_confirmed_at)) {
            self::$last_invite_result['email_error']='Brak danych odbiorcy lub rejestracja nie została potwierdzona.';
            return false;
        }
        if(!$force){
            $already=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".BCS_DB::table('logs')." WHERE registration_id=%d AND event_type='parent_portal_invite_sent'",$registration_id));
            if($already) return true;
        }
        $url=self::portal_url((string)$r->public_token);
        $expiry=self::portal_expiry_date($r);
        $templates = BCS_Template_Engine::all();
        $tpl = (array)($templates['emails']['camp_form_request'] ?? BCS_Communication_Engine::default_templates()['camp_form_request']);
        $vars = [
            '{{PARENT_NAME}}'=>trim($r->parent_first_name.' '.$r->parent_last_name),
            '{{CHILD_NAME}}'=>trim($r->child_first_name.' '.$r->child_last_name),
            '{{CAMP_NAME}}'=>(string)$r->camp_name,
            '{{CAMP_DATES}}'=>trim((string)$r->start_date.' – '.(string)$r->end_date),
            '{{CAMP_LOCATION}}'=>(string)$r->location,
            '{{PORTAL_URL}}'=>$url,
        ];
        $subject=strtr((string)($tpl['subject'] ?? 'Uzupełnij formularz obozowy'),$vars);
        $body=strtr((string)($tpl['body'] ?? ''),$vars);
        $sms_text=strtr((string)($tpl['sms'] ?? 'Basketmania Camp: uzupełnij formularz obozowy: {{PORTAL_URL}}'),$vars);
        $email_ok=BCS_Mailer::send($r->parent_email,$subject,$body,['Content-Type: text/html; charset=UTF-8'],[],$registration_id);
        $sms_result=BCS_SMS::send($r->parent_phone,$sms_text);
        $sms_ok=!empty($sms_result['success']);
        self::$last_invite_result = [
            'email'=>$email_ok,
            'sms'=>$sms_ok,
            'email_error'=>$email_ok?'':BCS_Mailer::last_error(),
            'sms_error'=>$sms_ok?'':(string)($sms_result['error'] ?? 'Nieznany błąd bramki SMS.'),
        ];
        BCS_Utils::log('parent_portal_invite_sent',['email'=>$r->parent_email,'email_success'=>$email_ok,'email_error'=>BCS_Mailer::last_error(),'sms_success'=>$sms_ok,'sms_error'=>$sms_result['error']??'','sms_message_id'=>$sms_result['message_id']??'','forced'=>$force,'expires'=>$expiry],$registration_id,null);
        if(class_exists('BCS_CRM')) BCS_CRM::activity($registration_id,'portal_invite','Wysłano e-mail i SMS z formularzem',($email_ok?'E-mail przekazany do hostingu':'Błąd e-mail').' · '.($sms_ok?'SMS wysłany':'Błąd SMS').' · ważny do '.wp_date('d.m.Y',strtotime($expiry)),false);
        return (bool)($email_ok && $sms_ok);
    }

    public static function portal_expiry_date(object $registration): string {
        global $wpdb;
        $email=sanitize_email((string)($registration->parent_email ?? ''));
        $latest='';
        if($email!==''){
            $latest=(string)$wpdb->get_var($wpdb->prepare(
                "SELECT MAX(c.end_date) FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id WHERE r.parent_email=%s AND r.agreement_status='accepted' AND r.total_amount>0 AND r.paid_amount>=r.total_amount",
                $email
            ));
        }
        $current_end=(string)($registration->end_date ?? '');
        $base=$latest;
        if($current_end!=='' && ($base==='' || $current_end>$base)) $base=$current_end;
        $year=(int)substr($base,0,4);
        if($year<2000) $year=(int)current_time('Y');
        return sprintf('%04d-08-31',$year);
    }

    private static function portal_is_expired(object $registration): bool {
        return current_time('Y-m-d') > self::portal_expiry_date($registration);
    }

    private static function step(string $label,string $description,bool $done,bool $current=false,int $number=1):string{return '<div class="bcs-step '.($done?'done':'').($current?' current':'').'"><span class="bcs-step-marker">'.($done?'✓':(int)$number).'</span><span class="bcs-step-copy"><strong>'.esc_html($label).'</strong><small>'.esc_html($description).'</small></span></div>';}
    private static function portal_status_message(object $r): array {
        $paid=(float)$r->total_amount>0&&(float)$r->paid_amount>=(float)$r->total_amount;
        if($r->status==='cancelled') return ['Zgłoszenie anulowane','To zgłoszenie zostało anulowane. W razie pytań skontaktuj się z organizatorem.'];
        if($paid && !empty($r->invoice_real_id)) return ['Proces zakończony','Płatność została zaksięgowana, a faktura jest gotowa do pobrania.'];
        if($paid) return ['Płatność zaksięgowana','Dziękujemy. Organizator przygotuje dokument sprzedaży zgodnie z obowiązującym workflow.'];
        if($r->agreement_status==='accepted') return ['Oczekujemy na płatność','Umowa została podpisana. Prosimy o wpłatę kwoty wskazanej w umowie.'];
        if(($r->agreement_real_status??'')==='pending') return ['Umowa gotowa do podpisu','Otwórz umowę, zapoznaj się z jej treścią i potwierdź podpis kodem SMS.'];
        if(!empty($r->form_verified_at)) return ['Formularz zaakceptowany','Organizator zaakceptował formularz i przygotowuje lub wysłał draft umowy.'];
        if(($r->form_status??'')==='complete') return ['Formularz oczekuje na akceptację','Dane zostały przesłane i oczekują na sprawdzenie przez organizatora.'];
        return ['Uzupełnij formularz obozowy','Wypełnij wymagane dane uczestnika, aby organizator mógł przygotować umowę.'];
    }
    private static function edit_lock_active(int $id): bool { return BCS_Locks::active($id); }
    public static function portal_shortcode(): string {
        global $wpdb;wp_enqueue_style('bcs-front');wp_enqueue_script('bcs-front');$token=sanitize_text_field(wp_unslash($_GET['token']??''));if(!$token)return '<div class="bcs-wrap"><div class="bcs-alert">Brak tokenu dostępu.</div></div>';
        $r=$wpdb->get_row($wpdb->prepare("SELECT r.*,c.name camp_name,c.start_date,c.end_date,c.location,c.pre_camp_info,o.name organizer_name,o.address organizer_address,o.nip organizer_nip,o.bank_name,o.bank_account,a.agreement_number,a.status agreement_real_status,a.accepted_at,a.id agreement_real_id,i.id invoice_real_id,i.invoice_number,i.file_path invoice_file_path FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id LEFT JOIN ".BCS_DB::table('invoices')." i ON i.id=(SELECT i2.id FROM ".BCS_DB::table('invoices')." i2 WHERE i2.registration_id=r.id ORDER BY i2.id DESC LIMIT 1) WHERE r.public_token=%s",$token));if(!$r)return '<div class="bcs-wrap"><div class="bcs-alert">Nie znaleziono zgłoszenia.</div></div>';
        $stripe_return_confirmed=false;
        if(($_GET['payment']??'')==='success' && !empty($_GET['session_id'])){
            $stripe_result=BCS_Payments::confirm_checkout_return((int)$r->id,sanitize_text_field(wp_unslash($_GET['session_id'])));
            if(!is_wp_error($stripe_result)){
                $stripe_return_confirmed=!empty($stripe_result['paid']);
                $r=$wpdb->get_row($wpdb->prepare("SELECT r.*,c.name camp_name,c.start_date,c.end_date,c.location,c.pre_camp_info,o.name organizer_name,o.address organizer_address,o.nip organizer_nip,o.bank_name,o.bank_account,a.agreement_number,a.status agreement_real_status,a.accepted_at,a.id agreement_real_id,i.id invoice_real_id,i.invoice_number,i.file_path invoice_file_path FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id LEFT JOIN ".BCS_DB::table('invoices')." i ON i.id=(SELECT i2.id FROM ".BCS_DB::table('invoices')." i2 WHERE i2.registration_id=r.id ORDER BY i2.id DESC LIMIT 1) WHERE r.id=%d",(int)$r->id));
            }
        }
        $admin_preview=current_user_can('manage_options')&&!empty($_GET['bcs_admin_preview']);if(empty($r->admin_confirmed_at)&&!$admin_preview)return '<div class="bcs-wrap"><div class="bcs-alert">Rejestracja nie została jeszcze potwierdzona przez administratora.</div></div>';if(self::portal_is_expired($r)&&!$admin_preview)return '<div class="bcs-wrap"><div class="bcs-alert">Link do panelu rodzica wygasł. Skontaktuj się z organizatorem.</div></div>';
        $wpdb->update(BCS_DB::table('registrations'),['portal_last_seen_at'=>BCS_Utils::now()],['id'=>$r->id]);$s=get_option('bcs_settings',[]);$test_mode=(!array_key_exists('test_workflow_mode',$s)||!empty($s['test_workflow_mode']));$logo=esc_url(!empty($s['portal_logo_url'])?$s['portal_logo_url']:(BCS_URL.'assets/images/logo-basketmania-camp-white.png'));$brand=esc_url($s['portal_brand_url']??'https://camp.basketmania.pl/');$form_done=($r->form_status??'')==='complete';$accepted=$r->agreement_status==='accepted';$paid=(float)$r->total_amount>0&&(float)$r->paid_amount>=(float)$r->total_amount;$locked=empty($r->form_verified_at)&&self::edit_lock_active((int)$r->id);$lock_remaining=BCS_Locks::remaining((int)$r->id);$lock_minutes=(int)(BCS_Locks::ttl()/MINUTE_IN_SECONDS);$otp_minutes=max(2,min(30,absint($s['otp_minutes']??2)));$edit=sanitize_key(wp_unslash($_GET['edit']??''));$view=sanitize_key(wp_unslash($_GET['view']??''));$status=self::portal_status_message($r);$agreement_url=$r->agreement_real_id?add_query_arg(['action'=>'bcs_agreement_view','agreement'=>$r->agreement_real_id,'token'=>$token],admin_url('admin-post.php')):'';$declaration='Oświadczam, że zapoznałem/-am się z pełną treścią umowy nr '.$r->agreement_number.' i akceptuję jej warunki.';
        $render_form=function()use($r,$token){
            $stay_contact=trim((string)($r->stay_contact??'')); if($stay_contact==='') $stay_contact=trim($r->parent_first_name.' '.$r->parent_last_name.' — '.$r->parent_phone);
            ob_start();?><form class="bcs-camp-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" data-bcs-lock-watch="1" data-registration-id="<?php echo (int)$r->id;?>" data-token="<?php echo esc_attr($token);?>"><input type="hidden" name="action" value="bcs_complete_registration"><input type="hidden" name="registration_id" value="<?php echo (int)$r->id;?>"><input type="hidden" name="token" value="<?php echo esc_attr($token);?>"><input type="hidden" name="edit_scope" value="camp"><?php wp_nonce_field('bcs_complete_registration_'.$r->id);?>
            <section class="bcs-form-section"><h3>Rodzic</h3><div class="bcs-grid"><label>Imię rodzica<input name="parent_first_name" value="<?php echo esc_attr((string)($r->parent_first_name??''));?>" required></label><label>Nazwisko rodzica<input name="parent_last_name" value="<?php echo esc_attr((string)($r->parent_last_name??''));?>" required></label><label>E-mail<input type="email" name="parent_email" value="<?php echo esc_attr((string)($r->parent_email??''));?>" required></label><label>Telefon<input name="parent_phone" value="<?php echo esc_attr((string)($r->parent_phone??''));?>" required></label><label>Kod pocztowy<input name="parent_postal_code" value="<?php echo esc_attr((string)($r->parent_postal_code??''));?>" required></label><label>Miejscowość<input name="parent_city" value="<?php echo esc_attr((string)($r->parent_city??''));?>" required></label><label>Ulica<input name="parent_street" value="<?php echo esc_attr((string)($r->parent_street??''));?>" required></label><label>Nr domu / lokalu<input name="parent_house_number" value="<?php echo esc_attr((string)($r->parent_house_number??''));?>" required></label><input type="hidden" name="parent_address" value="<?php echo esc_attr((string)($r->parent_address??''));?>"></div></section>
            <section class="bcs-form-section"><h3>Uczestnik obozu</h3><div class="bcs-grid"><label>Imię uczestnika<input name="child_first_name" value="<?php echo esc_attr((string)($r->child_first_name??''));?>" required></label><label>Nazwisko uczestnika<input name="child_last_name" value="<?php echo esc_attr((string)($r->child_last_name??''));?>" required></label><label>Data urodzenia<input type="date" name="child_birth_date" value="<?php echo esc_attr((string)($r->child_birth_date??''));?>" required></label><label>PESEL<input name="child_pesel" value="<?php echo esc_attr((string)($r->child_pesel??''));?>"></label><label>Wzrost (cm)<input type="number" min="100" max="230" name="child_height" value="<?php echo esc_attr((string)($r->child_height??''));?>" required></label><label>Rozmiar stroju<input name="shirt_size" value="<?php echo esc_attr((string)($r->shirt_size??''));?>" required></label><label class="bcs-span">Klub<input name="child_club" value="<?php echo esc_attr(($r->child_club??'')==='brak'?'':(string)($r->child_club??''));?>"><small>Jeżeli pozostawisz pole puste, system wpisze „brak”.</small></label><label class="bcs-span">Alergie, leki i informacje zdrowotne<textarea name="medical_notes"><?php echo esc_textarea(($r->medical_notes??'')==='brak'?'':(string)($r->medical_notes??''));?></textarea><small>Jeżeli pozostawisz pole puste, system wpisze „brak”.</small></label><label class="bcs-span">Dieta i żywienie<textarea name="dietary_notes"><?php echo esc_textarea(($r->dietary_notes??'')==='brak'?'':(string)($r->dietary_notes??''));?></textarea><small>Jeżeli pozostawisz pole puste, system wpisze „brak”.</small></label></div></section>
            <section class="bcs-form-section"><h3>Informacje dotyczące obozu</h3><div class="bcs-grid"><label class="bcs-span">Dane kontaktowe podczas pobytu<textarea name="stay_contact" required><?php echo esc_textarea($stay_contact);?></textarea><small>Wstępnie wpisaliśmy dane rodzica. Możesz wskazać także inną osobę kontaktową.</small></label><label class="bcs-span">Osoby upoważnione do odbioru<textarea name="authorized_pickup"><?php echo esc_textarea($r->authorized_pickup??'');?></textarea></label><label class="bcs-span">Dodatkowe informacje dla organizatora<textarea name="camp_notes"><?php echo esc_textarea($r->camp_notes??'');?></textarea></label></div></section>
            <section class="bcs-form-section bcs-form-section-muted"><h3>Regulaminy i zgody</h3><p>Pracujemy nad tym elementem formularza. Regulaminy i zgody zostaną udostępnione w kolejnej aktualizacji.</p></section>
            <label class="bcs-check bcs-check-left"><input type="checkbox" required> <span>Potwierdzam poprawność danych.</span></label><button class="bcs-button">Wyślij Formularz Obozowy</button></form><?php return (string)ob_get_clean();};
        $registration_preview='<section class="bcs-card bcs-readonly-card"><div class="bcs-card-head"><div><span class="bcs-section-kicker">Dane podstawowe</span><h2>Zgłoszenie</h2></div><a class="bcs-button bcs-secondary" href="'.esc_url(self::portal_url($token)).'">Zamknij podgląd</a></div><div class="bcs-form-preview-grid"><div><span>Rodzic / klient</span><strong>'.esc_html(trim($r->parent_first_name.' '.$r->parent_last_name)).'</strong></div><div><span>E-mail</span><strong>'.esc_html($r->parent_email).'</strong></div><div><span>Telefon</span><strong>'.esc_html($r->parent_phone).'</strong></div><div><span>Kod pocztowy</span><strong>'.esc_html($r->parent_postal_code?:'—').'</strong></div><div><span>Miejscowość</span><strong>'.esc_html($r->parent_city?:'—').'</strong></div><div><span>Ulica</span><strong>'.esc_html($r->parent_street?:'—').'</strong></div><div><span>Nr domu / lokalu</span><strong>'.esc_html($r->parent_house_number?:'—').'</strong></div><div><span>Uczestnik</span><strong>'.esc_html(trim($r->child_first_name.' '.$r->child_last_name)).'</strong></div><div><span>Data urodzenia</span><strong>'.esc_html($r->child_birth_date?wp_date('d.m.Y',strtotime($r->child_birth_date)):'—').'</strong></div><div><span>Wzrost</span><strong>'.esc_html($r->child_height?$r->child_height.' cm':'—').'</strong></div><div><span>Rozmiar stroju</span><strong>'.esc_html($r->shirt_size?:'—').'</strong></div><div><span>Turnus</span><strong>'.esc_html($r->camp_name).'</strong></div></div></section>';
        $camp_preview='<section class="bcs-card bcs-readonly-card"><div class="bcs-card-head"><div><span class="bcs-section-kicker">Dane zweryfikowane</span><h2>Formularz obozowy</h2></div><a class="bcs-button bcs-secondary" href="'.esc_url(self::portal_url($token)).'">Zamknij podgląd</a></div><div class="bcs-form-preview-grid"><div><span>Rodzic</span><strong>'.esc_html(trim($r->parent_first_name.' '.$r->parent_last_name)).'</strong></div><div><span>Uczestnik</span><strong>'.esc_html(trim($r->child_first_name.' '.$r->child_last_name)).'</strong></div><div><span>Kod pocztowy</span><strong>'.esc_html($r->parent_postal_code?:'—').'</strong></div><div><span>Miejscowość</span><strong>'.esc_html($r->parent_city?:'—').'</strong></div><div><span>Ulica</span><strong>'.esc_html($r->parent_street?:'—').'</strong></div><div><span>Nr domu / lokalu</span><strong>'.esc_html($r->parent_house_number?:'—').'</strong></div><div><span>PESEL uczestnika</span><strong>'.esc_html($r->child_pesel?:'—').'</strong></div><div><span>Klub</span><strong>'.esc_html($r->child_club?:'brak').'</strong></div><div class="bcs-form-preview-wide"><span>Alergie, leki i informacje zdrowotne</span><strong>'.nl2br(esc_html($r->medical_notes?:'brak')).'</strong></div><div class="bcs-form-preview-wide"><span>Dieta i żywienie</span><strong>'.nl2br(esc_html($r->dietary_notes?:'brak')).'</strong></div><div class="bcs-form-preview-wide"><span>Kontakt podczas pobytu</span><strong>'.nl2br(esc_html($r->stay_contact?:trim($r->parent_first_name.' '.$r->parent_last_name.' — '.$r->parent_phone))).'</strong></div><div class="bcs-form-preview-wide"><span>Osoby upoważnione do odbioru</span><strong>'.nl2br(esc_html($r->authorized_pickup?:'—')).'</strong></div><div class="bcs-form-preview-wide"><span>Dodatkowe informacje</span><strong>'.nl2br(esc_html($r->camp_notes?:'—')).'</strong></div></div></section>';
        ob_start();?><div class="bcs-wrap bcs-parent-dashboard"><?php if($admin_preview):?><div class="bcs-alert"><strong>Podgląd administratora.</strong></div><?php endif;?><?php if($test_mode):?><div class="bcs-test-banner"><strong>Wersja testowa systemu.</strong> Ograniczenie daty podpisu umowy jest wyłączone.</div><?php endif;?><header class="bcs-parent-header bcs-parent-header-modern"><a href="<?php echo $brand;?>" target="_blank" class="bcs-parent-logo"><?php if($logo):?><img src="<?php echo $logo;?>" alt="Basketmania Camp"><?php else:?><span class="bcs-logo-ball">B</span><strong>Basketmania Camp</strong><?php endif;?></a><div class="bcs-parent-title"><span>Strefa uczestnika</span><h2>Panel Rodzica</h2></div><div class="bcs-parent-access"><span class="bcs-secure-pill">Bezpieczny dostęp</span></div></header><section class="bcs-parent-hero bcs-parent-hero-modern"><div class="bcs-parent-hero-copy"><span class="bcs-eyebrow">Twój turnus</span><h1><?php echo esc_html($r->camp_name);?></h1><p><?php echo esc_html($r->start_date.' – '.$r->end_date.' · '.$r->location);?></p></div><div class="bcs-parent-person"><span class="bcs-person-icon">🏀</span><div><small>Uczestnik</small><strong><?php echo esc_html($r->child_first_name.' '.$r->child_last_name);?></strong></div></div></section><section class="bcs-progress-card"><div class="bcs-progress-head"><div><span class="bcs-section-kicker">Postęp zgłoszenia</span><h2>Droga do udziału w obozie</h2></div><span class="bcs-progress-count"><?php echo (int)(1 + ($form_done?1:0) + ($accepted?1:0) + ($paid?1:0) + (!empty($r->invoice_real_id)?1:0));?> / 5</span></div><div class="bcs-steps bcs-steps-five"><?php $invoice_ready=!empty($r->invoice_real_id);echo self::step('Rejestracja','Zgłoszenie przyjęte',true,false,1);echo self::step('Formularz',$form_done?'Formularz przesłany':'Uzupełnij dane uczestnika',$form_done,!$form_done,2);echo self::step('Umowa',$accepted?'Umowa podpisana':(!empty($r->agreement_real_id)?'Podpisz dokument':'Oczekuje na przygotowanie'),$accepted,$form_done&&!$accepted,3);echo self::step('Płatność',$paid?'Płatność zaksięgowana':'Oczekuje na wpłatę',$paid,$accepted&&!$paid,4);echo self::step('Faktura',$invoice_ready?'Faktura gotowa':'Pojawi się po płatności',$invoice_ready,$paid&&!$invoice_ready,5);?></div></section><?php if(!empty($_GET['saved'])):?><div class="bcs-success">Dane zostały zapisane i ponownie przekazane organizatorowi.</div><?php endif;?><?php if(empty($r->form_verified_at)&&(!empty($_GET['edit_locked'])||$locked)):?><div class="bcs-alert"><strong>Twoje dane są obecnie przeglądane i nie możesz ich w tej chwili edytować.</strong> Administrator aktualnie przegląda Kartę Zgłoszenia. Edycja Formularza Obozowego jest tymczasowo zablokowana. Spróbuj ponownie po wygaśnięciu blokady Pozostało: <strong class="bcs-lock-countdown" data-seconds="<?php echo (int)$lock_remaining;?>"><?php echo esc_html(gmdate('i:s',$lock_remaining));?></strong>.</div><?php endif;?><?php if(!$form_done && $locked):?><?php elseif(!$form_done):?><section class="bcs-card bcs-highlight"><h2>Uzupełnij Formularz Obozowy</h2><?php echo $render_form();?></section><?php elseif($edit==='camp'&&!$r->form_verified_at&&!$locked):?><section class="bcs-card"><h2>Podgląd Formularza Obozowego</h2><?php echo $render_form();?></section><?php else:?><?php if($view==='registration') echo $registration_preview; elseif($view==='form') echo $camp_preview;?><div class="bcs-parent-grid"><main><section class="bcs-card bcs-status-card"><div class="bcs-card-head"><h2>Status</h2><span class="bcs-badge"><?php echo esc_html(BCS_Workflow_Engine::statuses()[$r->status]??$r->status);?></span></div><h3><?php echo esc_html($status[0]);?></h3><p><?php echo esc_html($status[1]);?></p></section><section class="bcs-card"><h2>Umowa</h2><?php if(empty($r->form_verified_at)):?><p>Wzór umowy będzie dostępny po zaakceptowaniu Formularza Obozowego.</p><?php elseif(!$r->agreement_real_id):?><p>Draft umowy jest przygotowywany.</p><?php elseif(($r->agreement_real_status??'')==='pending'):?><div class="bcs-success"><strong>Umowa jest gotowa do podpisu.</strong></div><button type="button" class="bcs-button bcs-secondary bcs-open-agreement" data-agreement-url="<?php echo esc_url($agreement_url);?>">Otwórz umowę do podpisu</button><div id="bcs-otp" data-agreement="<?php echo (int)$r->agreement_real_id;?>" data-token="<?php echo esc_attr($token);?>" data-otp-seconds="<?php echo (int)($otp_minutes*60);?>"><label class="bcs-check"><input type="checkbox" id="bcs-declaration-check"> <?php echo esc_html($declaration);?></label><input type="hidden" id="bcs-declaration" value="<?php echo esc_attr($declaration);?>"><button type="button" id="bcs-send-code" class="bcs-button" disabled>Potwierdź podpis umowy SMS-em</button><div id="bcs-message"></div><div id="bcs-otp-modal" class="bcs-modal" hidden><div class="bcs-modal-backdrop" data-close-otp></div><div class="bcs-modal-dialog"><button type="button" class="bcs-modal-close" data-close-otp>×</button><h3>Wpisz kod SMS</h3><p>Kod jest ważny przez <strong><?php echo (int)$otp_minutes;?> min</strong>.</p><label>Kod SMS<input id="bcs-code" maxlength="6" inputmode="numeric"></label><div class="bcs-otp-timer">Pozostały czas: <strong id="bcs-otp-countdown"><?php echo esc_html(sprintf('%02d:00',$otp_minutes));?></strong></div><button type="button" id="bcs-verify-code" class="bcs-button">Potwierdź podpis umowy</button><div id="bcs-modal-message"></div></div></div></div><?php elseif($accepted):?><div class="bcs-success">✓ Umowa została podpisana kodem SMS.</div><?php else:?><p>Draft umowy jest dostępny do wglądu.</p><button type="button" class="bcs-button bcs-secondary bcs-open-agreement" data-agreement-url="<?php echo esc_url($agreement_url);?>">Otwórz draft umowy</button><?php endif;?></section><?php if($accepted):?><section class="bcs-card"><h2>Płatność</h2><?php if($paid):?><div class="bcs-payment-state bcs-payment-paid">✓ ZAPŁACONO — <?php echo number_format((float)$r->paid_amount,2,',',' ');?> zł</div><?php else:?><div class="bcs-payment-state bcs-payment-due">DO ZAPŁATY — <?php echo number_format(max(0,(float)$r->total_amount-(float)$r->paid_amount),2,',',' ');?> zł</div><div class="bcs-transfer-details"><h3>Dane odbiorcy przelewu</h3><p><strong>Pełna nazwa:</strong> <?php echo esc_html($r->organizer_name?:'—');?><br><strong>Adres siedziby:</strong> <?php echo nl2br(esc_html($r->organizer_address?:'—'));?><br><strong>NIP:</strong> <?php echo esc_html($r->organizer_nip?:'—');?><br><strong>Nazwa banku:</strong> <?php echo esc_html($r->bank_name?:'—');?><br><strong>Numer konta:</strong> <?php echo esc_html(BCS_Utils::format_bank_account((string)$r->bank_account));?></p></div><?php endif;?></section><?php endif;?></main><aside><section class="bcs-card"><h2>Dane i formularze</h2><p><strong><?php echo esc_html($r->child_first_name.' '.$r->child_last_name);?></strong></p><div class="bcs-parent-actions"><a class="bcs-button bcs-secondary" href="<?php echo esc_url(add_query_arg('view','registration',self::portal_url($token)));?>">Podejrzyj zgłoszenie</a><?php if(empty($r->form_verified_at)&&!$locked):?><a class="bcs-button bcs-secondary" href="<?php echo esc_url(add_query_arg('edit','camp',self::portal_url($token)));?>">Podgląd Formularza Obozowego</a><?php elseif(empty($r->form_verified_at)):?><span class="bcs-button bcs-disabled">Formularz Obozowy jest przeglądany przez administratora</span><?php else:?><a class="bcs-button bcs-secondary" href="<?php echo esc_url(add_query_arg('view','form',self::portal_url($token)));?>">Podgląd Formularza Obozowego</a><?php endif;?></div></section><section class="bcs-card"><h2>Dokumenty PDF</h2><div class="bcs-parent-actions"><?php if(!empty($r->form_verified_at)):?><a class="bcs-button bcs-secondary" href="<?php echo esc_url(BCS_Document_Engine::download_url((int)$r->id,'form',$token));?>">Pobierz Formularz Obozowy</a><?php else:?><span class="bcs-button bcs-disabled">Formularz Obozowy PDF dostępny po akceptacji Organizatora</span><?php endif;?><?php if(!empty($r->form_verified_at)&&$r->agreement_real_id):?><a class="bcs-button bcs-secondary" href="<?php echo esc_url(BCS_Document_Engine::download_url((int)$r->id,$accepted?'agreement_signed':'agreement_current',$token));?>">Pobierz <?php echo $accepted?'podpisaną umowę':'draft umowy';?></a><?php else:?><span class="bcs-button bcs-disabled">Umowa jeszcze niedostępna</span><?php endif;?><?php if(!empty($r->invoice_real_id)):?><a class="bcs-button bcs-secondary" href="<?php echo esc_url(BCS_Document_Engine::download_url((int)$r->id,'invoice',$token));?>">Pobierz fakturę PDF</a><?php else:?><span class="bcs-button bcs-disabled">Faktura jeszcze niedostępna</span><?php endif;?></div></section></aside></div><?php endif;?><div id="bcs-agreement-modal" class="bcs-modal bcs-document-modal" hidden><div class="bcs-modal-backdrop" data-close-agreement></div><div class="bcs-modal-dialog bcs-document-dialog"><button type="button" class="bcs-modal-close" data-close-agreement>×</button><h3>Umowa Basketmania Camp</h3><iframe id="bcs-agreement-frame" title="Podgląd umowy"></iframe></div></div></div><?php return (string)ob_get_clean();
    }
}
