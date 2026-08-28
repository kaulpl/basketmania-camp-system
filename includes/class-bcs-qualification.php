<?php
if (!defined('ABSPATH')) exit;

/** Separate qualification cards. All signing operations serialize on the registration lock.
 * Card content and signer identities are snapshots, never rebuilt after invitation.
 */
final class BCS_Qualification {
    public const SOLE_DECLARATION = 'Rodzic/opiekun prawny oświadczył że sprawuje opiekę nad uczestnikiem obozu samodzielnie';
    private const DECLARATION = 'Oświadczam, że zapoznałem/-am się z pełną treścią karty kwalifikacyjnej i akceptuję jej treść. Potwierdzam ją podpisem SMS.';

    public static function init(): void {
        remove_action('admin_footer',['BCS_Release_043','admin_footer'],100);
        self::install();
        if (!get_option('bcs_qualification_template_migrated')) {
            $templates=get_option('bcs_content_templates',[]);
            $source=(string)($templates['documents']['agreement']??'');
            if ($source==='') $source=(string)file_get_contents(BCS_DIR.'templates/agreement-default.html');
            $templates['documents']['agreement']=self::separate_card($source);
            update_option('bcs_content_templates',$templates,false);
            update_option('bcs_qualification_template_migrated',1,false);
        }
        foreach (['admin_post_bcs_qualification','admin_post_nopriv_bcs_qualification'] as $hook) add_action($hook,[__CLASS__,'endpoint']);
        add_action('wp_enqueue_scripts',[__CLASS__,'assets']);
        add_action('template_redirect',[__CLASS__,'portal_headers'],0);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets']);
        // Stop legacy direct links from exposing an internal draft to a parent.
        foreach (['admin_post_bcs_agreement_view','admin_post_nopriv_bcs_agreement_view','template_redirect','admin_post_bcs_download_document','admin_post_nopriv_bcs_download_document'] as $hook) add_action($hook,[__CLASS__,'guard_draft'],-1000);
    }

    private static function install(): void {
        if (get_option('bcs_qualification_schema') === '1') return;
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=BCS_DB::table('qualification_cards');$charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            registration_id BIGINT UNSIGNED NOT NULL,
            payload LONGTEXT NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (registration_id)
        ) ENGINE=InnoDB $charset;");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===$table) update_option('bcs_qualification_schema','1',false);
    }

    public static function editor_fields(): array {
        return ['sole_guardian'=>['samodzielnie sprawuje opiekę nad uczestnikiem obozu','checkbox'],
            'second_parent_first_name'=>['Imię drugiego rodzica','text'],'second_parent_last_name'=>['Nazwisko drugiego rodzica','text'],
            'second_parent_email'=>['E-mail drugiego rodzica','email'],'second_parent_phone'=>['Telefon drugiego rodzica','text']];
    }

    public static function parent_data(array $input): array|WP_Error {
        $data=['sole_guardian'=>!empty($input['sole_guardian'])?1:0];
        foreach (['parent_first_name','parent_last_name','parent_email','parent_phone','second_parent_first_name','second_parent_last_name','second_parent_email','second_parent_phone'] as $key) {
            $data[$key]=sanitize_text_field(wp_unslash((string)($input[$key]??'')));
        }
        foreach (['parent','second_parent'] as $prefix) {
            if ($prefix==='second_parent' && $data['sole_guardian']) {
                foreach (['first_name','last_name','email','phone'] as $key) $data[$prefix.'_'.$key]='';
                continue;
            }
            foreach (['first_name','last_name','email','phone'] as $key) {
                if ($data[$prefix.'_'.$key]==='') return new WP_Error('parents','Wpisz imię, nazwisko, e-mail i telefon każdego rodzica albo zaznacz oświadczenie o samodzielnej opiece.');
            }
            if (!is_email($data[$prefix.'_email'])) return new WP_Error('email','Wpisz prawidłowy adres e-mail każdego rodzica.');
            $data[$prefix.'_phone']=BCS_Utils::normalize_phone($data[$prefix.'_phone']);
            if (str_starts_with($data[$prefix.'_phone'],'00')) $data[$prefix.'_phone']=substr($data[$prefix.'_phone'],2);
            if (!preg_match('/^[1-9][0-9]{8,14}$/D',$data[$prefix.'_phone'])) return new WP_Error('phone','Wpisz prawidłowy numer telefonu każdego rodzica.');
        }
        if (!$data['sole_guardian'] && ($data['parent_phone']===$data['second_parent_phone'] || strcasecmp($data['parent_email'],$data['second_parent_email'])===0)) return new WP_Error('distinct','Rodzice muszą mieć osobne adresy e-mail i różne numery telefonów do podpisu SMS.');
        $data['parents_names']=trim($data['parent_first_name'].' '.$data['parent_last_name']).($data['sole_guardian']?'':'; '.trim($data['second_parent_first_name'].' '.$data['second_parent_last_name']));
        return $data;
    }

    public static function parent_fields(object $r): string {
        $html='<label class="bcs-sole-switch bcs-span"><input type="checkbox" role="switch" name="sole_guardian" value="1" '.(!empty($r->sole_guardian)?'checked':'').'><span>samodzielnie sprawuje opiekę nad uczestnikiem obozu</span></label><fieldset class="bcs-second-parent bcs-span"><legend>Drugi rodzic / opiekun prawny</legend><div class="bcs-grid">';
        foreach (self::editor_fields() as $key=>$meta) {
            if ($key==='sole_guardian') continue;
            $html.='<label>'.esc_html($meta[0]).'<input name="'.esc_attr($key).'" type="'.esc_attr($meta[1]).'" value="'.esc_attr((string)($r->$key??'')).'" '.(!empty($r->sole_guardian)?'disabled':'required').'></label>';
        }
        return $html.'</div></fieldset>';
    }

    public static function assets(): void {
        wp_enqueue_script('bcs-qualification',BCS_URL.'assets/js/qualification.js',[],BCS_VERSION,true);
        wp_enqueue_style('bcs-qualification',BCS_URL.'assets/css/qualification.css',[],BCS_VERSION);
    }

    public static function fully_paid(object $r): bool {
        return (int)round((float)$r->total_amount*100)>0 && (int)round((float)$r->paid_amount*100)>=(int)round((float)$r->total_amount*100);
    }

    private static function registration(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT r.*,c.name camp_name,c.start_date,c.end_date,c.location,o.name organizer_name,o.representative organizer_representative,o.address organizer_address,o.nip organizer_nip,o.email organizer_email,o.phone organizer_phone FROM '.BCS_DB::table('registrations').' r JOIN '.BCS_DB::table('camps').' c ON c.id=r.camp_id LEFT JOIN '.BCS_DB::table('organizers').' o ON o.id=c.organizer_id WHERE r.id=%d',$id))?:null;
    }

    public static function card(int $id): ?array {
        global $wpdb;
        $json=$wpdb->get_var($wpdb->prepare('SELECT payload FROM '.BCS_DB::table('qualification_cards').' WHERE registration_id=%d',$id));
        $data=$json?json_decode($json,true):null;
        return is_array($data)?$data:null;
    }

    private static function save(int $id,array $card): void {
        global $wpdb;
        $ok=$wpdb->replace(BCS_DB::table('qualification_cards'),['registration_id'=>$id,'payload'=>wp_json_encode($card,JSON_UNESCAPED_UNICODE),'updated_at'=>BCS_Utils::now()]);
        if ($ok===false) throw new RuntimeException('Nie udało się zapisać karty. Spróbuj ponownie.');
    }

    private static function locked(int $id,callable $fn): mixed {
        global $wpdb;
        $key='bcs_card_'.md5($wpdb->prefix.'_'.$id);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)',$key))!==1) throw new RuntimeException('Trwa inna operacja na karcie. Spróbuj ponownie.');
        try { return $fn(); } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key)); }
    }

    public static function stage(array $card): string {
        foreach ($card['signers'] as $role=>$signer) if ($role!=='organizer' && empty($signer['signed_at'])) return 'card_parents';
        return empty($card['signers']['organizer']['signed_at'])?'card_organizer':'card_signed';
    }

    public static function invoice_signatures_complete(int $id): bool {
        $card=self::card($id);
        if (!$card || empty($card['html']) || empty($card['hash']) || !hash_equals($card['hash'],hash('sha256',$card['html']))) return false;
        $required=!empty($card['sole_guardian'])?['parent','organizer']:['parent','second_parent','organizer'];
        foreach ($required as $role) {
            $signer=$card['signers'][$role]??[];
            if (empty($signer['signed_at']) || !hash_equals($card['hash'],(string)($signer['document_hash']??''))) return false;
        }
        return true;
    }

    private static function sync_status(int $id,array $card): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE '.BCS_DB::table('registrations')." SET status=%s,updated_at=%s WHERE id=%d AND status<>'cancelled'",self::stage($card),BCS_Utils::now(),$id));
        BCS_Workflow::refresh_invoice_readiness($id);
    }

    public static function payment_received(int $id): void {
        try { self::locked($id,function()use($id){
            $r=self::registration($id);
            if (!$r || $r->status==='cancelled' || !self::fully_paid($r) || empty($r->form_verified_at)) return;
            $card=self::card($id);
            if (!$card) {
                $parents=self::parent_data((array)$r);
                if (is_wp_error($parents)) { BCS_Utils::log('qualification_missing_parent_data',['message'=>$parents->get_error_message()],$id);return; }
                $signers=[];
                foreach (['parent','second_parent'] as $role) {
                    if ($role==='second_parent' && $parents['sole_guardian']) continue;
                    $signers[$role]=['name'=>trim($parents[$role.'_first_name'].' '.$parents[$role.'_last_name']),'email'=>$parents[$role.'_email'],'phone'=>$parents[$role.'_phone']];
                }
                if (strlen(preg_replace('/\D/','',(string)$r->organizer_phone))<9) throw new RuntimeException('Uzupełnij telefon organizatora przed wysłaniem karty.');
                $signers['organizer']=['name'=>trim((string)$r->organizer_representative)?:$r->organizer_name,'email'=>$r->organizer_email,'phone'=>BCS_Utils::normalize_phone((string)$r->organizer_phone)];
                $html=self::render_body($r,$parents);
                // Store a full PDF layout, including organizer footer and logo, as the signed snapshot.
                $html=self::prepare_html($html,$id);
                $card=['html'=>$html,'hash'=>hash('sha256',$html),'created_at'=>BCS_Utils::now(),'sole_guardian'=>$parents['sole_guardian'],'signers'=>$signers];
                self::save($id,$card);
                BCS_Utils::log('qualification_created',['hash'=>$card['hash'],'parents'=>count($signers)-1],$id);
            }
            foreach ($card['signers'] as $role=>$signer) {
                if ($role!=='organizer' && empty($signer['signed_at']) && empty($signer['mail_sent_at'])) self::invite($id,$card,$role);
            }
            self::sync_status($id,$card);
        }); } catch (Throwable $e) { BCS_Utils::log('qualification_error',['message'=>$e->getMessage()],$id); }
    }

    private static function invite(int $id,array &$card,string $role): void {
        $signer=&$card['signers'][$role];
        $token=bin2hex(random_bytes(32));
        $signer['token_hash']=hash('sha256',$token);$signer['token_expires']=time()+30*DAY_IN_SECONDS;
        unset($signer['challenge'],$signer['opened_at'],$signer['reviewed_hash']);
        self::save($id,$card); // persist authorization before delivering the link
        $url=self::portal_url($id,$role,$token);
        $body='<p>Dzień dobry '.esc_html($signer['name']).',</p><p>Zaksięgowano pełną płatność za turnus. Prosimy o zapoznanie się z kartą kwalifikacyjną i podpisanie jej kodem SMS na własny numer telefonu.</p><p><a href="'.esc_url($url).'">Otwórz i podpisz kartę kwalifikacyjną</a></p><p>Ten link jest przeznaczony wyłącznie dla Ciebie. Nie przekazuj go innym osobom. Link jest ważny 30 dni.</p>';
        $ok=BCS_Mailer::send($signer['email'],'Basketmania Camp - karta kwalifikacyjna do podpisu',$body,['Content-Type: text/html; charset=UTF-8'],[],$id);
        $signer['mail_sent_at']=$ok?BCS_Utils::now():null;
        self::save($id,$card);
        BCS_Utils::log('qualification_invitation',['role'=>$role,'success'=>$ok],$id);
    }

    public static function url(int $id,string $role='organizer',string $token='',string $op='view'): string {
        $args=['action'=>'bcs_qualification','registration_id'=>$id,'role'=>$role,'op'=>$op];
        if ($role==='organizer') $args['_wpnonce']=wp_create_nonce('bcs_qualification_'.$id);
        else $args['token']=$token;
        return add_query_arg($args,admin_url('admin-post.php'));
    }

    private static function authorize(int $id,array $card,string $role,string $token): void {
        if (!isset($card['signers'][$role])) throw new RuntimeException('Nieprawidłowy link.');
        if ($role==='organizer') {
            if (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce']??'')),'bcs_qualification_'.$id)) throw new RuntimeException('Brak uprawnień lub sesja wygasła.');
        } else {
            $s=$card['signers'][$role];
            if ($token==='' || empty($s['token_hash']) || !hash_equals($s['token_hash'],hash('sha256',$token)) || ($s['token_expires']??0)<time()) throw new RuntimeException('Link jest nieprawidłowy lub wygasł. Poproś organizatora o nowy link.');
        }
    }

    private static function signing_allowed(object $r,array $card,string $role): void {
        if ($r->status==='cancelled' || !self::fully_paid($r)) throw new RuntimeException('Podpis jest niedostępny: zgłoszenie anulowano lub brakuje pełnej płatności.');
        if (!hash_equals($card['hash'],hash('sha256',$card['html']))) throw new RuntimeException('Nieprawidłowy skrót dokumentu. Skontaktuj się z organizatorem.');
        if (!empty($card['signers'][$role]['signed_at'])) throw new RuntimeException('Ten podpis został już zapisany.');
        if ($role==='organizer' && self::stage($card)!=='card_organizer') throw new RuntimeException('Najpierw muszą podpisać wszyscy wymagani rodzice.');
        if (empty($card['signers'][$role]['opened_at']) || ($role!=='organizer' && !hash_equals($card['hash'],(string)($card['signers'][$role]['reviewed_hash']??'')))) throw new RuntimeException('Najpierw otwórz i przeczytaj kartę.');
    }

    public static function endpoint(): void {
        $id=absint($_REQUEST['registration_id']??0);$role=sanitize_key($_REQUEST['role']??'');$token=sanitize_text_field(wp_unslash($_REQUEST['token']??''));$op=sanitize_key($_REQUEST['op']??'view');
        $message='';
        try {
            if (($_SERVER['REQUEST_METHOD']??'GET')==='POST' && $op==='save_parents') {
                if (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce']??'')),'bcs_qualification_'.$id)) throw new RuntimeException('Brak uprawnień.');
                self::locked($id,function()use($id){
                    if (self::card($id)) throw new RuntimeException('Karta została już wysłana. Dane podpisujących są zablokowane.');
                    $r=self::registration($id);
                    if (!$r || $r->status==='cancelled') throw new RuntimeException('Nieprawidłowe zgłoszenie.');
                    $input=(array)$r;
                    foreach (self::editor_fields() as $key=>$meta) $input[$key]=$_POST[$key]??'';
                    $data=self::parent_data($input);
                    if (is_wp_error($data)) throw new RuntimeException($data->get_error_message());
                    // Supplement only the second parent / custody declaration. Never change the first signer of an existing contract.
                    $data=array_intersect_key($data,self::editor_fields()+['parents_names'=>true]);
                    global $wpdb;
                    if ($wpdb->update(BCS_DB::table('registrations'),$data,['id'=>$id])===false) throw new RuntimeException('Nie udało się zapisać danych.');
                    BCS_Utils::log('qualification_parents_completed',['fields'=>array_keys($data)],$id);
                });
                self::payment_received($id);
                wp_safe_redirect(admin_url('admin.php?page=bcs-registrations&view='.$id));exit;
            }
            if (($_SERVER['REQUEST_METHOD']??'GET')==='POST' && $op==='retry') {
                if (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce']??'')),'bcs_qualification_'.$id)) throw new RuntimeException('Brak uprawnień.');
                $existing=self::card($id);
                if (!$existing) { self::payment_received($id); if (!self::card($id)) throw new RuntimeException('Karta nie została utworzona. Sprawdź dane rodziców, telefon organizatora i pełną wpłatę.'); }
                if ($existing) self::locked($id,function()use($id){$card=self::card($id);$r=self::registration($id);if(!$card||!$r||$r->status==='cancelled'||!self::fully_paid($r))throw new RuntimeException('Nie można wysłać karty. Sprawdź pełną wpłatę i komplet danych rodziców.');foreach($card['signers'] as $role=>$s)if($role!=='organizer'&&empty($s['signed_at']))self::invite($id,$card,$role);});
                wp_safe_redirect(admin_url('admin.php?page=bcs-registrations&view='.$id));exit;
            }
            self::locked($id,function()use($id,$role,$token,$op,&$message){
                $card=self::card($id);$r=self::registration($id);
                if (!$card||!$r) throw new RuntimeException('Karta nie jest jeszcze dostępna.');
                self::authorize($id,$card,$role,$token);
                if ($r->status==='cancelled' && $role!=='organizer') throw new RuntimeException('Zgłoszenie zostało anulowane.');
                if ($op==='send' || $op==='sign') {
                    if (($_SERVER['REQUEST_METHOD']??'')!=='POST' || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['card_nonce']??'')),'bcs_card_'.$id.'_'.$role.'_'.$card['hash'])) throw new RuntimeException('Sesja wygasła. Odśwież kartę.');
                    self::signing_allowed($r,$card,$role);
                    self::require_acceptance($_POST);
                    if ($op==='send') $message=self::send_code($id,$card,$role);
                    else $message=self::sign($id,$card,$role,(string)($_POST['code']??''));
                } elseif ($op==='document' || ($op==='view' && $role==='organizer')) {
                    if (empty($card['signers'][$role]['reviewed_hash']) && empty($card['signers'][$role]['signed_at'])) $card['signers'][$role]['opened_at']=BCS_Utils::now();
                    $card['signers'][$role]['reviewed_hash']=$card['hash'];self::save($id,$card);
                } elseif (!in_array($op,['download','view'],true)) throw new RuntimeException('Nieznana operacja.');
            });
            $card=self::card($id);
            nocache_headers();header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');header('X-Frame-Options: SAMEORIGIN');
            if ($op==='download') { self::download($card);exit; }
            if ($op==='document') {
                header('Content-Type: text/html; charset=UTF-8');
                echo str_replace('</head>','<meta name="bcs-card-reviewed" content="'.esc_attr($card['hash']).'"><style>'.BCS_Agreement_PDF_V2::preview_css().'</style></head>',self::final_html($card));exit;
            }
            if ($role!=='organizer') {
                if (in_array($op,['send','sign'],true)) wp_send_json_success(['message'=>$message,'signed'=>!empty($card['signers'][$role]['signed_at']),'expires'=>$card['signers'][$role]['challenge']['expires']??0]);
                wp_safe_redirect(self::portal_url($id,$role,$token));exit;
            }
            self::page($id,$card,$role,$token,$message);exit;
        } catch (Throwable $e) { if ($role!=='organizer' && in_array($op,['send','sign'],true)) wp_send_json_error(['message'=>$e->getMessage()],409); wp_die(esc_html($e->getMessage()),'Karta kwalifikacyjna',['response'=>409,'back_link'=>true]); }
    }

    private static function require_acceptance(array $input): void {
        if (($input['read']??'')!=='1') throw new RuntimeException('Zaznacz oświadczenie o zapoznaniu się z kartą i akceptacji jej treści.');
    }

    private static function send_code(int $id,array &$card,string $role): string {
        $s=&$card['signers'][$role];$now=time();$settings=get_option('bcs_settings',[]);
        if (($s['challenge']['expires']??0)>$now) throw new RuntimeException('Poprzedni kod nadal jest ważny. Poczekaj do jego wygaśnięcia.');
        $history=array_values(array_filter($s['send_history']??[],fn($t)=>$t>$now-HOUR_IN_SECONDS));
        if (count($history)>=max(1,min(20,(int)($settings['otp_send_limit']??3)))) throw new RuntimeException('Osiągnięto godzinowy limit kodów SMS.');
        if (strlen(preg_replace('/\D/','',$s['phone']))<9) throw new RuntimeException('Brak prawidłowego telefonu organizatora. Uzupełnij go przed utworzeniem karty.');
        $minutes=max(2,min(30,(int)($settings['otp_minutes']??2)));$code=(string)random_int(100000,999999);
        $history[]=$now;$s['send_history']=$history;self::save($id,$card); // even provider failures consume the rate limit
        $result=BCS_SMS::send($s['phone'],'Basketmania Camp: kod podpisu karty kwalifikacyjnej: '.$code.'. Ważny '.$minutes.' min. Nie udostępniaj kodu.');
        if (empty($result['success'])) throw new RuntimeException('Nie udało się wysłać SMS. Spróbuj później.');
        $s['challenge']=['hash'=>wp_hash_password($code),'expires'=>$now+$minutes*MINUTE_IN_SECONDS,'attempts'=>0,'message_id'=>(string)($result['message_id']??''),'sent_at'=>BCS_Utils::now(),'document_hash'=>$card['hash'],'user'=>$role==='organizer'?get_current_user_id():0];
        self::save($id,$card);
        BCS_Utils::log('qualification_otp_sent',['role'=>$role,'sms_message_id'=>$s['challenge']['message_id'],'phone'=>BCS_Utils::mask_phone($s['phone'])],$id);
        return 'Kod SMS wysłano na '.BCS_Utils::mask_phone($s['phone']).'. Ważność: '.$minutes.' min.';
    }

    private static function sign(int $id,array &$card,string $role,string $code): string {
        $s=&$card['signers'][$role];$c=$s['challenge']??[];$settings=get_option('bcs_settings',[]);
        if (!$c || ($c['expires']??0)<time()) throw new RuntimeException('Kod wygasł. Wyślij nowy SMS.');
        if (!hash_equals($card['hash'],$c['document_hash']) || ($role==='organizer' && $c['user']!==get_current_user_id())) throw new RuntimeException('Kod nie dotyczy tej sesji i dokumentu.');
        if ($c['attempts']>=max(3,min(10,(int)($settings['max_attempts']??5)))) throw new RuntimeException('Przekroczono limit prób. Poczekaj na wygaśnięcie kodu.');
        $s['challenge']['attempts']++;self::save($id,$card);
        if (!preg_match('/^\d{6}$/D',$code) || !wp_check_password($code,$c['hash'])) throw new RuntimeException('Kod SMS jest nieprawidłowy.');
        $s['signed_at']=BCS_Utils::now();$s['sms_message_id']=$c['message_id'];$s['sms_sent_at']=$c['sent_at'];$s['ip']=BCS_Utils::client_ip();$s['user_agent']=sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']??''));$s['declaration']=self::DECLARATION;$s['document_hash']=$card['hash'];$s['admin_user_id']=$role==='organizer'?get_current_user_id():0;
        unset($s['challenge']);self::save($id,$card);self::sync_status($id,$card);
        BCS_Utils::log('qualification_signed',['role'=>$role,'hash'=>$card['hash'],'sms_message_id'=>$s['sms_message_id'],'stage'=>self::stage($card)],$id);
        return 'Podpis został zapisany. '.(self::stage($card)==='card_signed'?'Karta jest podpisana przez wszystkie wymagane osoby.':'Pozostali podpisujący mają własne linki i kody SMS.');
    }

    public static function prepare_html(string $body,int $id=0): string {
        $html=BCS_Agreement_PDF_V2::prepare_pdf_html($body,'Karta kwalifikacyjna',$id);
        // Keep physical page margins on @page; do not reset the HTML root margin.
        $html=str_replace('html,body{margin:0;padding:0;', 'body{padding:0;', $html);
        return str_replace('</head>','<style>.bcs-card-field{page-break-inside:avoid}.bcs-card-proof p{font-size:8.5pt;line-height:1.2;margin:0 0 3px}.bcs-card-proof h3{margin-top:12px}.bcs-card-proof{word-wrap:break-word}</style></head>',$html);
    }

    private static function proof(array $card): string {
        $html='<section class="bcs-card-proof" style="page-break-before:always"><h2>Dowód podpisów SMS</h2><p>Karta kwalifikacyjna uczestnika wypoczynku</p><p>SHA-256 podpisanej wersji: <span style="font-size:8pt;word-wrap:break-word">'.esc_html($card['hash']).'</span></p>';
        if (!empty($card['sole_guardian'])) $html.='<p>'.esc_html(self::SOLE_DECLARATION).'.</p>';
        foreach ($card['signers'] as $role=>$s) {
            $html.='<div style="page-break-inside:avoid"><h3>'.esc_html($role==='organizer'?'Organizator wypoczynku':($role==='parent'?'Pierwszy rodzic / opiekun prawny':'Drugi rodzic / opiekun prawny')).'</h3>';
            foreach (['Imię i nazwisko'=>$s['name'],'E-mail'=>$s['email'],'Numer telefonu'=>$s['phone'],'Pierwsze otwarcie'=>$s['opened_at']??'','Podpisano (Europe/Warsaw)'=>$s['signed_at']??'Oczekuje na podpis','SMS wysłano'=>$s['sms_sent_at']??'','Identyfikator SMS'=>$s['sms_message_id']??'','Adres IP'=>$s['ip']??'','Oświadczenie'=>$s['declaration']??''] as $label=>$value) $html.='<p><strong>'.esc_html($label).':</strong> '.esc_html((string)$value).'</p>';
            $html.='</div>';
        }
        return $html.'</section>';
    }

    private static function final_html(array $card): string {
        return str_replace('</main>',self::proof($card).'</main>',$card['html']);
    }

    private static function download(array $card): void {
        if (self::stage($card)!=='card_signed') throw new RuntimeException('PDF podpisanej karty będzie dostępny po wszystkich podpisach.');
        if (!hash_equals($card['hash'],hash('sha256',$card['html']))) throw new RuntimeException('Nieprawidłowy skrót dokumentu.');
        if (!BCS_PDF::available()) throw new RuntimeException('Generator PDF jest niedostępny.');
        $dompdf=new \Dompdf\Dompdf(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'defaultFont'=>'DejaVu Sans']);
        $dompdf->loadHtml(self::final_html($card),'UTF-8');$dompdf->setPaper('A4');$dompdf->render();
        header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="karta-kwalifikacyjna-podpisana.pdf"');header('X-Content-Type-Options: nosniff');echo $dompdf->output();
    }

    private static function page(int $id,array $card,string $role,string $token,string $message): void {
        $s=$card['signers'][$role];$can=empty($s['signed_at'])&&($role!=='organizer'||self::stage($card)==='card_organizer');
        $controls='<div class="bcs-card-controls"><h1>Karta kwalifikacyjna</h1><p>'.esc_html($message).'</p><p>Podpisujący: '.esc_html($s['name']).'</p>';
        if ($can) {
            $controls.='<form method="post" action="'.esc_url(self::url($id,$role,$token)).'">'.wp_nonce_field('bcs_card_'.$id.'_'.$role.'_'.$card['hash'],'card_nonce',true,false).'<label><input type="checkbox" name="read" value="1" required> '.esc_html(self::DECLARATION).'</label><p><button name="op" value="send">Wyślij kod SMS</button></p><label>Kod SMS <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></label> <button name="op" value="sign">Podpisz kartę</button></form>';
        } else $controls.='<p>'.(!empty($s['signed_at'])?'Twój podpis został zapisany.':'Oczekuje na podpisy rodziców.').'</p>';
        if (self::stage($card)==='card_signed') $controls.='<p><a href="'.esc_url(self::url($id,$role,$token,'download')).'">Pobierz podpisaną kartę PDF</a></p>';
        $controls.='</div>';
        $html=self::final_html($card);
        $html=str_replace('</head>','<meta name="bcs-card-stage" content="'.esc_attr(self::stage($card)).'"></head>',$html);
        $html=str_replace('</head>','<style>'.BCS_Agreement_PDF_V2::preview_css().'</style><style>.bcs-card-controls{padding:24px;background:#fff7ed;max-width:900px;margin:20px auto;font:16px Arial}.bcs-card-controls button{padding:12px;background:#172033;color:white;border:0;border-radius:8px;cursor:pointer}.bcs-card-controls input{padding:8px}@media print{.bcs-card-controls{display:none}}</style></head>',$html);
        header('Content-Type: text/html; charset=UTF-8');echo preg_replace_callback('~(<body[^>]*>)~i',static fn($m)=>$m[1].$controls,$html,1);
    }

    public static function organizer_action(object $r): string {
        if ($r->status==='cancelled' || !self::fully_paid($r)) return '';
        $card=self::card((int)$r->id);
        if (!$card || !isset($card['signers']['organizer']) || self::stage($card)!=='card_organizer') return '';
        $parents=!empty($card['sole_guardian'])?['parent']:['parent','second_parent'];
        foreach ($parents as $role) if (empty($card['signers'][$role]['signed_at'])) return '';
        return '<a class="button button-primary bcs-action-available" data-qualification-admin-preview href="'.esc_url(self::url((int)$r->id)).'">Podpisz kartę kwalifikacyjną</a>';
    }

    public static function admin_panel(int $id): string {
        $card=self::card($id);
        $html='<div class="bcs-full bcs-qualification-panel"><h3>Karta kwalifikacyjna</h3>';
        if ($card) {
            $html.='<p>'.esc_html(BCS_Workflow::statuses()[self::stage($card)]).'</p>';
            foreach ($card['signers'] as $role=>$s) $html.='<p>'.esc_html($s['name']).': '.(!empty($s['signed_at'])?'podpisano '.esc_html($s['signed_at']):($role==='organizer'?'oczekuje':(!empty($s['mail_sent_at'])?'wysłano zaproszenie':'błąd wysyłki zaproszenia'))).'</p>';
            $html.='<p><a class="button" data-qualification-admin-preview href="'.esc_url(self::url($id)).'">'.(self::stage($card)==='card_organizer'?'Podpisz kartę kwalifikacyjną':'Podgląd karty kwalifikacyjnej').'</a></p>';
            if (self::stage($card)==='card_signed') $html.='<p><a class="button button-primary" href="'.esc_url(self::url($id,'organizer','','download')).'">Pobierz podpisaną kartę kwalifikacyjną PDF</a></p>';
        } else $html.='<p>Karta zostanie wysłana po pełnej wpłacie. Wymagane są kompletne dane rodziców i zaakceptowany formularz.</p>';
        if (!$card) {
            $r=self::registration($id);
            if ($r && is_wp_error(self::parent_data((array)$r))) {
                $html.='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><h4>Uzupełnij dane drugiego rodzica do osobnej karty</h4><p>Pierwszy rodzic: '.esc_html(trim($r->parent_first_name.' '.$r->parent_last_name)).'. Dane pierwszego podpisującego umowę pozostają bez zmian. Oświadczenie o samodzielnej opiece zaznaczaj wyłącznie na podstawie informacji od rodzica.</p><input type="hidden" name="action" value="bcs_qualification"><input type="hidden" name="op" value="save_parents"><input type="hidden" name="registration_id" value="'.$id.'">'.wp_nonce_field('bcs_qualification_'.$id,'_wpnonce',true,false).self::parent_fields($r).'<button class="button">Zapisz dane rodziców do karty</button></form>';
            }
        }
        if (!$card||self::stage($card)==='card_parents') $html.='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bcs_qualification"><input type="hidden" name="op" value="retry"><input type="hidden" name="registration_id" value="'.$id.'">'.wp_nonce_field('bcs_qualification_'.$id,'_wpnonce',true,false).'<button class="button">Wyślij / ponów zaproszenia do podpisu karty</button><p>Nowe linki zastępują poprzednie linki niepodpisanych rodziców.</p></form>';
        return $html.'</div>';
    }

    public static function portal_url(int $id,string $role,string $token): string {
        $page=get_page_by_path('panel-rodzica');
        return add_query_arg(['qualification'=>$id,'card_role'=>$role,'card_token'=>$token],$page?get_permalink($page):home_url('/panel-rodzica/'));
    }

    public static function portal_headers(): void {
        if (empty($_GET['qualification'])) return;
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE',true);
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
    }

    public static function portal_view(): string {
        $id=absint($_GET['qualification']??0);
        $role=sanitize_key($_GET['card_role']??'');
        $token=sanitize_text_field(wp_unslash($_GET['card_token']??''));
        try {
            // A card invitation never grants the shared registration/contract token.
            if (!in_array($role,['parent','second_parent'],true)) throw new RuntimeException('Nieprawidłowy link rodzica.');
            $card=self::card($id);$r=self::registration($id);
            if (!$card||!$r) throw new RuntimeException('Karta nie jest jeszcze dostępna.');
            self::authorize($id,$card,$role,$token);
            if ($r->status==='cancelled') throw new RuntimeException('Zgłoszenie zostało anulowane.');
            wp_enqueue_style('bcs-front');self::assets();
            $settings=get_option('bcs_settings',[]);
            $logo=$settings['portal_logo_url']??(BCS_URL.'assets/images/logo-basketmania-camp-white.png');
            $html='<div class="bcs-wrap bcs-parent-dashboard"><header class="bcs-parent-header bcs-parent-header-modern"><div class="bcs-parent-logo"><img src="'.esc_url($logo).'" alt="Basketmania Camp"></div><div class="bcs-parent-title"><span>Strefa uczestnika</span><h2>Panel Rodzica</h2></div><div class="bcs-parent-access"><span class="bcs-secure-pill">Bezpieczny dostęp</span></div></header>';
            $html.='<section class="bcs-parent-hero bcs-parent-hero-modern"><div class="bcs-parent-hero-copy"><span class="bcs-eyebrow">Twój turnus</span><h1>'.esc_html($r->camp_name).'</h1><p>'.esc_html($r->start_date.' – '.$r->end_date.' · '.$r->location).'</p></div><div class="bcs-parent-person"><div><small>Uczestnik</small><strong>'.esc_html($r->child_first_name.' '.$r->child_last_name).'</strong></div></div></section>';
            return $html.self::parent_controls($id,$card,$role,$token).'</div>';
        } catch (Throwable $e) { return '<div class="bcs-wrap"><div class="bcs-alert">'.esc_html($e->getMessage()).'</div></div>'; }
    }

    private static function parent_controls(int $id,array $card,string $role,string $token): string {
        $s=$card['signers'][$role];$signed=!empty($s['signed_at']);
        $html='<section class="bcs-card bcs-qualification-signing" data-card-hash="'.esc_attr($card['hash']).'" data-card-expires="'.(int)($s['challenge']['expires']??0).'" data-card-endpoint="'.esc_url(self::url($id,$role,$token,'send')).'"><h2>Karta kwalifikacyjna</h2><p>Podpisujący: <strong>'.esc_html($s['name']).'</strong> · SMS: '.esc_html(BCS_Utils::mask_phone($s['phone'])).'</p><p>Otwórz dokument, zapoznaj się z całą treścią, następnie zaznacz akceptację i potwierdź podpis kodem SMS.</p><button type="button" class="bcs-button bcs-secondary" data-card-open data-document-url="'.esc_url(self::url($id,$role,$token,'document')).'">Otwórz kartę kwalifikacyjną</button>';
        if (!$signed) {
            $html.='<form data-card-sign-form>'.wp_nonce_field('bcs_card_'.$id.'_'.$role.'_'.$card['hash'],'card_nonce',false,false).'<label class="bcs-check"><input type="checkbox" name="read" value="1" disabled required> '.esc_html(self::DECLARATION).'</label><button type="button" class="bcs-button" data-card-send disabled>Potwierdź podpis karty SMS-em</button><p data-card-message role="status" aria-live="polite"></p>';
            $html.='<div class="bcs-modal" data-card-otp hidden><div class="bcs-modal-backdrop" data-card-close></div><div class="bcs-modal-dialog" role="dialog" aria-modal="true" aria-label="Podpis karty kodem SMS"><button type="button" class="bcs-modal-close" data-card-close aria-label="Zamknij">×</button><h3>Wpisz kod SMS</h3><p data-card-timer role="status"></p><label>Kod SMS<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}"></label><button type="submit" class="bcs-button" data-card-sign>Potwierdź podpis karty</button><p data-card-otp-message role="status" aria-live="polite"></p></div></div></form>';
        } else $html.='<div class="bcs-success">✓ Twój podpis karty został zapisany. '.(self::stage($card)==='card_signed'?'Dokument jest podpisany przez wszystkie wymagane osoby.':'Oczekujemy na pozostałe wymagane podpisy.').'</div>';
        if (self::stage($card)==='card_signed') $html.='<p><a class="bcs-button bcs-secondary" href="'.esc_url(self::url($id,$role,$token,'download')).'">Pobierz podpisaną kartę PDF</a></p>';
        return $html.'<div class="bcs-modal bcs-document-modal" data-card-document hidden><div class="bcs-modal-backdrop" data-card-close></div><div class="bcs-modal-dialog bcs-document-dialog" role="dialog" aria-modal="true" aria-label="Podgląd karty kwalifikacyjnej"><button type="button" class="bcs-modal-close" data-card-close aria-label="Zamknij">×</button><h3>Karta kwalifikacyjna Basketmania Camp</h3><iframe title="Podgląd karty kwalifikacyjnej" referrerpolicy="no-referrer"></iframe></div></div></section>';
    }

    public static function portal_panel(int $id,string $portal_token): string {
        $card=self::card($id);if(!$card)return '';
        return '<section class="bcs-card"><h2>Karta kwalifikacyjna</h2><p>'.esc_html(BCS_Workflow::statuses()[self::stage($card)]).'</p><p>Aby otworzyć i podpisać kartę w Panelu Rodzica, użyj swojego osobnego linku z wiadomości „Karta kwalifikacyjna do podpisu”. Każdy rodzic korzysta z własnego linku i numeru telefonu.</p></section>';
    }

    public static function guard_draft(): void {
        if (current_user_can('manage_options')) return;
        $action=sanitize_key($_GET['action']??'');$document=sanitize_key($_GET['document']??'');
        if ($action!=='bcs_agreement_view' && !str_starts_with($document,'agreement_') && $document!=='complete') return;
        global $wpdb;$agreement=absint($_GET['agreement']??0);$id=absint($_GET['registration']??$_GET['registration_id']??0);
        $status=$agreement?$wpdb->get_var($wpdb->prepare('SELECT status FROM '.BCS_DB::table('agreements').' WHERE id=%d',$agreement)):$wpdb->get_var($wpdb->prepare('SELECT agreement_status FROM '.BCS_DB::table('registrations').' WHERE id=%d',$id));
        if ($status==='draft' || $document==='agreement_draft') wp_die('Umowa oczekuje na wysłanie do podpisu przez organizatora.','Umowa',['response'=>403]);
    }

    /** Remove only the qualification attachment, preserving all other agreement clauses. */
    public static function separate_card(string $html): string {
        $html=preg_replace('~<li>\s*Załącznik nr 1 do Umowy stanowi karta kwalifikacyjna uczestnika wypoczynku\.\s*</li>~iu','<li>Karta kwalifikacyjna uczestnika wypoczynku jest osobnym dokumentem, podpisywanym niezależnie od Umowy.</li>',$html);
        $html=preg_replace('~<p>\s*<strong>Załącznik nr 1\s*[-–]\s*Karta kwalifikacyjna uczestnika wypoczynku</strong>\s*</p>~iu','',$html);
        if (!class_exists('DOMDocument')) throw new RuntimeException('Wymagane jest rozszerzenie PHP DOM.');
        $dom=new DOMDocument('1.0','UTF-8');$old=libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="bcs-separate-card">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();libxml_use_internal_errors($old);
        $xpath=new DOMXPath($dom);
        foreach ($xpath->query('//h1|//h2|//h3') as $heading) {
            $text=mb_strtoupper(preg_replace('/\s+/u',' ',trim($heading->textContent)),'UTF-8');
            if (!str_contains($text,'ZAŁĄCZNIK NR 1') || !str_contains($text,'KARTA KWALIFIKACYJNA')) continue;
            $node=$heading;
            while ($node) {
                $next=$node->nextSibling;
                // Preserve later unrelated attachments or the original footer.
                if ($node!==$heading && $node instanceof DOMElement && (preg_match('/^ZAŁĄCZNIK NR [2-9]/u',mb_strtoupper(trim($node->textContent),'UTF-8')) || str_contains($node->getAttribute('class'),'footer'))) break;
                $node->parentNode->removeChild($node);$node=$next;
            }
            break;
        }
        $root=$dom->getElementById('bcs-separate-card');$html='';
        foreach ($root->childNodes as $node) $html.=$dom->saveHTML($node);
        return (string)$html;
    }

    public static function render_body(object $r,array $parents): string {
        $e=static fn($v)=>nl2br(esc_html((string)$v));
        $field=static fn($label,$value)=>'<p class="bcs-card-field"><strong>'.esc_html($label).'</strong><br>'.$e($value).'</p>';
        $html='<h1>KARTA KWALIFIKACYJNA UCZESTNIKA WYPOCZYNKU</h1><h2>I. INFORMACJE DOTYCZĄCE WYPOCZYNKU</h2>';
        $html.=$field('1. Forma wypoczynku','[ ] kolonia   [ ] zimowisko   [X] obóz   [ ] biwak   [ ] półkolonia   [ ] inna forma').$field('2. Termin wypoczynku',$r->start_date.' - '.$r->end_date).$field('3. Adres wypoczynku, miejsce lokalizacji wypoczynku',$r->location).$field('Trasa wypoczynku o charakterze wędrownym','Nie dotyczy').$field('Nazwa kraju w przypadku wypoczynku organizowanego za granicą','Nie dotyczy');
        $html.='<p>Podpis organizatora wypoczynku: potwierdzenie SMS w dowodzie podpisów.</p><h2>II. INFORMACJE DOTYCZĄCE UCZESTNIKA WYPOCZYNKU</h2>';
        $html.=$field('1. Imię (imiona) i nazwisko',$r->child_first_name.' '.$r->child_last_name).$field('2. Imiona i nazwiska rodziców',$parents['parents_names']);
        if ($parents['sole_guardian']) $html.='<p>'.$e(self::SOLE_DECLARATION).'.</p>';
        $html.=$field('3. Rok urodzenia',substr((string)$r->child_birth_date,0,4)).$field('4. Numer PESEL uczestnika wypoczynku',$r->child_pesel).$field('5. Adres zamieszkania',$r->child_address?:BCS_Utils::registration_address($r)).$field('6. Adres zamieszkania lub pobytu rodziców',BCS_Utils::registration_address($r)).$field('7. Numer telefonu rodziców lub osoby wskazanej przez pełnoletniego uczestnika wypoczynku, w czasie trwania wypoczynku',$parents['parent_phone'].($parents['sole_guardian']?'':' / '.$parents['second_parent_phone'])."\n".($r->stay_contact??''));
        $html.=$field('8. Informacja o specjalnych potrzebach edukacyjnych uczestnika wypoczynku, w szczególności wynikających z niepełnosprawności, niedostosowania społecznego lub zagrożenia niedostosowaniem społecznym',$r->special_educational_needs??'');
        $html.=$field('9. Istotne dane o stanie zdrowia, rozwoju psychofizycznym i stosowanej diecie (alergie, choroba lokomocyjna, stale przyjmowane leki i dawki, aparat ortodontyczny, okulary)',($r->medical_notes??'')."\nDieta: ".($r->dietary_notes??''));
        $html.=$field('Szczepienia ochronne (rok lub przedstawienie książeczki zdrowia z aktualnym wpisem szczepień)','Tężec: '.($r->vaccination_tetanus??'')."\nBłonica: ".($r->vaccination_diphtheria??'')."\nInne: ".($r->vaccination_other??''));
        $html.='<p>Podpisy rodziców / opiekunów prawnych: potwierdzenia SMS w dowodzie podpisów.</p><h2>III. DECYZJA ORGANIZATORA WYPOCZYNKU O ZAKWALIFIKOWANIU UCZESTNIKA WYPOCZYNKU DO UDZIAŁU W WYPOCZYNKU</h2><p><strong>[X] Postanawia się zakwalifikować i skierować uczestnika na wypoczynek.</strong></p><p>[ ] Odmówić skierowania uczestnika na wypoczynek ze względu: ...................................</p><p>Data i podpis organizatora wypoczynku: potwierdzenie SMS w dowodzie podpisów.</p>';
        $html.='<h2>IV. POTWIERDZENIE PRZEZ KIEROWNIKA WYPOCZYNKU POBYTU UCZESTNIKA WYPOCZYNKU W MIEJSCU WYPOCZYNKU</h2><p>Uczestnik przebywał (adres): ................................................................................</p><p>Od dnia: ....................................... do dnia: .......................................</p><p>Data i podpis kierownika wypoczynku: ....................................................</p><h2>V. INFORMACJA KIEROWNIKA WYPOCZYNKU O STANIE ZDROWIA UCZESTNIKA WYPOCZYNKU W CZASIE TRWANIA WYPOCZYNKU ORAZ O CHOROBACH PRZEBYTYCH W JEGO TRAKCIE</h2><p>....................................................................................................................<br>....................................................................................................................<br>....................................................................................................................</p><p>Miejscowość, data i podpis kierownika wypoczynku: ....................................</p><h2>VI. INFORMACJA I SPOSTRZEŻENIA WYCHOWAWCY WYPOCZYNKU DOTYCZĄCE POBYTU UCZESTNIKA WYPOCZYNKU</h2><p>....................................................................................................................<br>....................................................................................................................<br>....................................................................................................................</p><p>Miejscowość, data i podpis wychowawcy wypoczynku: ...................................</p>';
        return $html;
    }
}
