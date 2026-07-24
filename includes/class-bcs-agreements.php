<?php
if (!defined('ABSPATH')) exit;

class BCS_Agreements {
    public static function init(): void {
        add_action('wp_ajax_nopriv_bcs_send_otp', [__CLASS__, 'ajax_send_otp']);
        add_action('wp_ajax_bcs_send_otp', [__CLASS__, 'ajax_send_otp']);
        add_action('wp_ajax_nopriv_bcs_verify_otp', [__CLASS__, 'ajax_verify_otp']);
        add_action('wp_ajax_bcs_verify_otp', [__CLASS__, 'ajax_verify_otp']);
        add_action('admin_post_nopriv_bcs_agreement_view', [__CLASS__, 'view_agreement']);
        add_action('admin_post_bcs_agreement_view', [__CLASS__, 'view_agreement']);
    }

    public static function build_for_registration(int $registration_id, string $status='pending', bool $include_date=true): int {
        global $wpdb;
        $reg=$wpdb->get_row($wpdb->prepare("SELECT r.*,c.name camp_name,c.start_date,c.end_date,c.location,c.organizer_id,o.name organizer_name,o.legal_form organizer_legal_form,o.address organizer_address,o.nip organizer_nip,o.regon organizer_regon,o.krs organizer_krs,o.email organizer_email,o.phone organizer_phone,o.bank_name,o.bank_account,o.representative organizer_representative,o.transfer_title_template,o.invoice_prefix organizer_prefix FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id WHERE r.id=%d",$registration_id));
        if(!$reg)return 0;
        $settings=get_option('bcs_settings',[]);
        $base_prefix=strtoupper(preg_replace('/[^A-Za-z0-9_-]/','',(string)($settings['agreement_prefix']??'BC'))) ?: 'BC';
        $organizer_prefix=strtoupper(preg_replace('/[^A-Za-z0-9_-]/','',(string)($reg->organizer_prefix??''))) ?: 'ORG'.(int)$reg->organizer_id;
        $year=$reg->start_date?substr($reg->start_date,0,4):gmdate('Y');
        $existing=$reg->agreement_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('agreements')." WHERE id=%d",$reg->agreement_id)):null;
        $number=$existing && !empty($existing->agreement_number)
            ? (string)$existing->agreement_number
            : self::next_number((int)$reg->organizer_id,(int)$year,$base_prefix.'/'.$organizer_prefix);
        if($number==='') return 0;
        $template=BCS_Template_Engine::get('documents','agreement',self::default_template());
        $agreement_date=$include_date?BCS_Utils::today('d.m.Y'):'';
        $replace=[
            '{{AGREEMENT_NUMBER}}'=>esc_html($number),'{{AGREEMENT_DATE}}'=>esc_html($agreement_date),
            '{{PARENT_NAME}}'=>esc_html($reg->parent_first_name.' '.$reg->parent_last_name),'{{PARENT_ADDRESS}}'=>nl2br(esc_html((string)BCS_Utils::registration_address($reg))),
            '{{PARENT_EMAIL}}'=>esc_html($reg->parent_email),'{{PARENT_PHONE}}'=>esc_html($reg->parent_phone),'{{CHILD_NAME}}'=>esc_html($reg->child_first_name.' '.$reg->child_last_name),
            '{{PARENT_PHONE_ALT}}'=>esc_html((string)($reg->parent_phone_alt??'')),'{{PARENTS_NAMES}}'=>esc_html((string)($reg->parents_names??'')),'{{CHILD_ADDRESS}}'=>nl2br(esc_html((string)($reg->child_address??''))),
            '{{CHILD_PESEL}}'=>esc_html((string)$reg->child_pesel),'{{CHILD_HEIGHT}}'=>esc_html((string)$reg->child_height),'{{CHILD_WEIGHT}}'=>esc_html((string)($reg->child_weight??'')),
            '{{SPECIAL_EDUCATIONAL_NEEDS}}'=>nl2br(esc_html((string)($reg->special_educational_needs??''))),'{{MEDICAL_NOTES}}'=>nl2br(esc_html((string)$reg->medical_notes)),'{{DIETARY_NOTES}}'=>nl2br(esc_html((string)$reg->dietary_notes)),
            '{{VACCINATION_TETANUS}}'=>esc_html((string)($reg->vaccination_tetanus??'')),'{{VACCINATION_DIPHTHERIA}}'=>esc_html((string)($reg->vaccination_diphtheria??'')),'{{VACCINATION_OTHER}}'=>nl2br(esc_html((string)($reg->vaccination_other??''))),
            '{{INVOICE_BUYER_NAME}}'=>esc_html((string)($reg->invoice_buyer_name??'')),'{{INVOICE_STREET}}'=>esc_html((string)($reg->invoice_street??'')),'{{INVOICE_POSTAL_CODE}}'=>esc_html((string)($reg->invoice_postal_code??'')),'{{INVOICE_CITY}}'=>esc_html((string)($reg->invoice_city??'')),'{{INVOICE_NIP}}'=>esc_html((string)($reg->invoice_nip??'')),'{{INVOICE_NOTES}}'=>nl2br(esc_html((string)($reg->invoice_notes??''))),
            '{{CHILD_BIRTH_DATE}}'=>esc_html((string)$reg->child_birth_date),'{{CAMP_NAME}}'=>esc_html($reg->camp_name),'{{CAMP_DATES}}'=>esc_html($reg->start_date.' – '.$reg->end_date),
            '{{CAMP_LOCATION}}'=>esc_html((string)$reg->location),'{{TOTAL_AMOUNT}}'=>esc_html(number_format((float)$reg->total_amount,2,',',' ').' zł'),
            '{{ORGANIZER_NAME}}'=>esc_html((string)$reg->organizer_name),'{{ORGANIZER_LEGAL_FORM}}'=>esc_html((string)$reg->organizer_legal_form),'{{ORGANIZER_ADDRESS}}'=>nl2br(esc_html((string)$reg->organizer_address)),
            '{{ORGANIZER_NIP}}'=>esc_html((string)$reg->organizer_nip),'{{ORGANIZER_REGON}}'=>esc_html((string)$reg->organizer_regon),'{{ORGANIZER_KRS}}'=>esc_html((string)$reg->organizer_krs),
            '{{ORGANIZER_EMAIL}}'=>esc_html((string)$reg->organizer_email),'{{ORGANIZER_PHONE}}'=>esc_html((string)$reg->organizer_phone),'{{ORGANIZER_REPRESENTATIVE}}'=>esc_html((string)$reg->organizer_representative),
            '{{BANK_NAME}}'=>esc_html((string)$reg->bank_name),'{{BANK_ACCOUNT}}'=>esc_html(BCS_Utils::format_bank_account((string)$reg->bank_account)),
        ];
        $html=strtr($template,$replace);$hash=hash('sha256',$html);
        $data=['organizer_id'=>(int)$reg->organizer_id,'agreement_number'=>$number,'version'=>$include_date?'1.0':'template','html'=>$html,'document_hash'=>$hash,'status'=>$status];
        if($existing){$wpdb->update(BCS_DB::table('agreements'),$data,['id'=>$existing->id]);$agreement_id=(int)$existing->id;}
        else{$data['registration_id']=$registration_id;$data['created_at']=BCS_Utils::now();$wpdb->insert(BCS_DB::table('agreements'),$data);$agreement_id=(int)$wpdb->insert_id;}
        if(!$agreement_id) return 0;
        $snapshot=wp_json_encode(['name'=>$reg->organizer_name,'legal_form'=>$reg->organizer_legal_form,'address'=>$reg->organizer_address,'nip'=>$reg->organizer_nip,'regon'=>$reg->organizer_regon,'krs'=>$reg->organizer_krs,'email'=>$reg->organizer_email,'phone'=>$reg->organizer_phone,'representative'=>$reg->organizer_representative,'bank_name'=>$reg->bank_name,'bank_account'=>$reg->bank_account,'transfer_title_template'=>$reg->transfer_title_template],JSON_UNESCAPED_UNICODE);
        $wpdb->update(BCS_DB::table('registrations'),['agreement_id'=>$agreement_id,'agreement_status'=>$status,'organizer_snapshot'=>$snapshot,'bank_account_snapshot'=>$reg->bank_account,'updated_at'=>BCS_Utils::now()],['id'=>$registration_id]);
        self::save_version($agreement_id,$registration_id,$status==='draft'?'draft':'sent',$html,$hash,$number);
        BCS_Utils::log($status==='draft'?'agreement_draft_created':'agreement_created',['hash'=>$hash,'date'=>$agreement_date],$registration_id,$agreement_id);return $agreement_id;
    }

    private static function next_number(int $organizer_id,int $year,string $prefix): string {
        global $wpdb;
        $prefix=strtoupper($prefix);
        $option_name='bcs_agreement_sequence_'.md5($organizer_id.'/'.$prefix.'/'.$year);
        if(get_option($option_name,false)===false){
            $like=$wpdb->esc_like($prefix.'/'.$year.'/').'%';
            $numbers=$wpdb->get_col($wpdb->prepare(
                "SELECT agreement_number FROM ".BCS_DB::table('agreements')." WHERE organizer_id=%d AND agreement_number LIKE %s",
                $organizer_id,$like
            ));
            $max=0;
            foreach($numbers as $number){
                if(preg_match('~/(\\d+)$~',(string)$number,$match)) $max=max($max,(int)$match[1]);
            }
            add_option($option_name,(string)$max,'','no');
        }
        $updated=$wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value=LAST_INSERT_ID(CAST(option_value AS UNSIGNED)+1) WHERE option_name=%s",
            $option_name
        ));
        if($updated!==1) return '';
        $next=(int)$wpdb->get_var('SELECT LAST_INSERT_ID()');
        return $prefix.'/'.$year.'/'.str_pad((string)$next,6,'0',STR_PAD_LEFT);
    }

    public static function publish_draft(int $registration_id): int {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT r.agreement_id, a.* FROM ".BCS_DB::table('registrations')." r LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id WHERE r.id=%d",$registration_id));
        if (!$row || empty($row->agreement_id) || $row->status !== 'draft' || trim((string)$row->html) === '') return 0;
        $agreement_id = (int)$row->agreement_id;$html = (string)$row->html;$hash = hash('sha256', $html);$now = BCS_Utils::now();
        $updated = $wpdb->update(BCS_DB::table('agreements'), ['status'=>'pending','version'=>'1.0','document_hash'=>$hash], ['id'=>$agreement_id]);
        if ($updated === false) return 0;
        self::save_version($agreement_id, $registration_id, 'sent', $html, $hash, (string)$row->agreement_number);
        $wpdb->update(BCS_DB::table('registrations'), ['agreement_status'=>'pending','updated_at'=>$now], ['id'=>$registration_id]);
        BCS_Utils::log('agreement_draft_published', ['hash'=>$hash,'source'=>'editable_template'], $registration_id, $agreement_id);
        return $agreement_id;
    }

    private static function save_version(int $agreement_id, int $registration_id, string $stage, string $html, string $hash, string $number): void {
        global $wpdb;$table=BCS_DB::table('agreement_versions');
        $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE agreement_id=%d AND stage=%s",$agreement_id,$stage));
        $data=['agreement_id'=>$agreement_id,'registration_id'=>$registration_id,'stage'=>$stage,'html'=>$html,'document_hash'=>$hash,'agreement_number'=>$number,'created_at'=>BCS_Utils::now()];
        if($existing)$wpdb->update($table,$data,['id'=>(int)$existing]); else $wpdb->insert($table,$data);
    }

    private static function first_opened_at(int $registration_id, int $agreement_id): string {
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare("SELECT MIN(created_at) FROM ".BCS_DB::table('logs')." WHERE registration_id=%d AND agreement_id=%d AND event_type='agreement_opened_for_signature'",$registration_id,$agreement_id));
    }

    public static function ajax_send_otp(): void {
        check_ajax_referer('bcs_front', 'nonce');global $wpdb;
        $agreement_id=absint($_POST['agreement_id']??0); $token=sanitize_text_field(wp_unslash($_POST['token']??''));
        $row=$wpdb->get_row($wpdb->prepare("SELECT a.*,r.parent_phone,r.id registration_id,r.public_token FROM ".BCS_DB::table('agreements')." a JOIN ".BCS_DB::table('registrations')." r ON r.id=a.registration_id WHERE a.id=%d",$agreement_id));
        if(!$row||!hash_equals((string)$row->public_token,$token)) wp_send_json_error(['message'=>BCS_Template_Engine::get('ui','invalid_link','Nieprawidłowy link.')],403);
        $registration_id=(int)$row->registration_id; $provider=BCS_SMS::provider_label(); $phone_masked=BCS_Utils::mask_phone((string)$row->parent_phone);
        if($row->status==='draft') wp_send_json_error(['message'=>'To jest wzór umowy. Oczekuj na wysłanie właściwej umowy przez organizatora.'],409);
        if($row->status==='accepted') wp_send_json_error(['message'=>BCS_Template_Engine::get('ui','agreement_already_accepted','Umowa jest już potwierdzona.')],409);
        if(self::first_opened_at($registration_id,$agreement_id)==='') wp_send_json_error(['message'=>'Najpierw otwórz umowę do podpisu i zapoznaj się z jej treścią oraz załącznikami.'],400);
        if(sanitize_key(wp_unslash($_POST['agreement_read']??''))!=='1') wp_send_json_error(['message'=>'Zaznacz wszystkie wymagane oświadczenia przed wysłaniem kodu SMS.'],400);
        $settings=get_option('bcs_settings',[]); $minutes=max(2,min(30,absint($settings['otp_minutes']??2))); $send_limit=max(1,min(20,absint($settings['otp_send_limit']??3))); $now=time();
        $last=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('otp')." WHERE agreement_id=%d AND used_at IS NULL ORDER BY id DESC LIMIT 1",$agreement_id));
        if($last){ $last_exp=strtotime((string)$last->expires_at.' Europe/Warsaw'); if($last_exp>$now){$retry=$last_exp-$now; BCS_Utils::log('otp_send_blocked_active_code',['otp_id'=>(int)$last->id,'retry_after'=>$retry,'phone'=>$phone_masked,'provider'=>$provider,'actor'=>'parent'],$registration_id,$agreement_id); wp_send_json_error(['message'=>'Poprzedni kod jest nadal ważny. Kolejny SMS można wysłać dopiero po jego wygaśnięciu.','retry_after'=>$retry,'expires_at'=>$last_exp],429);}}
        $recent=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".BCS_DB::table('otp')." WHERE agreement_id=%d AND created_at > DATE_SUB(%s, INTERVAL 1 HOUR)",$agreement_id,BCS_Utils::now()));
        if($recent>=$send_limit){BCS_Utils::log('otp_send_blocked_hourly_limit',['count'=>$recent,'configured_limit'=>$send_limit,'phone'=>$phone_masked,'provider'=>$provider,'actor'=>'parent'],$registration_id,$agreement_id);wp_send_json_error(['message'=>BCS_Template_Engine::get('ui','otp_limit','Osiągnięto limit wysyłek. Spróbuj później.'),'limit'=>$send_limit],429);}
        $code=(string)random_int(100000,999999); $expires_ts=$now+($minutes*MINUTE_IN_SECONDS); $expires=wp_date('Y-m-d H:i:s',$expires_ts,BCS_Utils::timezone());
        $tpl=BCS_Template_Engine::get('emails','otp_sms',''); $message=$tpl!==''?BCS_Template_Engine::render($tpl,['{{AGREEMENT_NUMBER}}'=>$row->agreement_number,'{{CODE}}'=>$code,'{{MINUTES}}'=>(string)$minutes]):sprintf('Basketmania Camp: kod potwierdzający umowę %s to %s. Kod ważny %d min. Nie udostępniaj go innym.',$row->agreement_number,$code,$minutes);
        BCS_Utils::log('agreement_declarations_accepted',['accepted_at'=>BCS_Utils::now(),'actor'=>'parent'],$registration_id,$agreement_id);
        BCS_Utils::log('otp_send_requested',['phone'=>$phone_masked,'provider'=>$provider,'valid_minutes'=>$minutes,'actor'=>'parent'],$registration_id,$agreement_id);
        $sent=BCS_SMS::send((string)$row->parent_phone,$message);
        if(empty($sent['success'])){BCS_Utils::log('otp_send_failed',['error'=>(string)($sent['error']??'Nieznany błąd'),'response'=>$sent,'phone'=>$phone_masked,'provider'=>$provider,'actor'=>'parent'],$registration_id,$agreement_id);wp_send_json_error(['message'=>'Nie udało się wysłać SMS: '.(string)($sent['error']??'Nieznany błąd.')],500);}
        $wpdb->insert(BCS_DB::table('otp'),['agreement_id'=>$agreement_id,'phone'=>BCS_Utils::normalize_phone((string)$row->parent_phone),'code_hash'=>wp_hash_password($code),'attempts'=>0,'expires_at'=>$expires,'sms_message_id'=>$sent['message_id']??'','created_at'=>BCS_Utils::now()]);
        $otp_id=(int)$wpdb->insert_id; BCS_Utils::log('otp_sent',['otp_id'=>$otp_id,'sms_message_id'=>$sent['message_id']??'','phone'=>$phone_masked,'provider'=>$provider,'expires_at'=>$expires,'actor'=>'parent'],$registration_id,$agreement_id);
        $msg=BCS_Template_Engine::render(BCS_Template_Engine::get('ui','otp_sent','Kod został wysłany na numer {{PHONE}}.'),['{{PHONE}}'=>$phone_masked]);
        wp_send_json_success(['message'=>$msg,'expires_at'=>$expires_ts,'valid_seconds'=>$minutes*MINUTE_IN_SECONDS,'retry_after'=>$minutes*MINUTE_IN_SECONDS]);
    }

    public static function ajax_verify_otp(): void {
        check_ajax_referer('bcs_front', 'nonce');global $wpdb;
        $agreement_id=absint($_POST['agreement_id']??0);$token=sanitize_text_field(wp_unslash($_POST['token']??''));$code=preg_replace('/\D+/','',(string)($_POST['code']??''));$declaration=sanitize_textarea_field(wp_unslash($_POST['declaration']??''));
        $row=$wpdb->get_row($wpdb->prepare("SELECT a.*,r.parent_phone,r.id registration_id,r.public_token,r.total_amount FROM ".BCS_DB::table('agreements')." a JOIN ".BCS_DB::table('registrations')." r ON r.id=a.registration_id WHERE a.id=%d",$agreement_id));
        if(!$row||!hash_equals($row->public_token,$token)) wp_send_json_error(['message'=>BCS_Template_Engine::get('ui','invalid_link','Nieprawidłowy link.')],403);
        if($row->status==='accepted') wp_send_json_success(['message'=>BCS_Template_Engine::get('ui','agreement_already_accepted','Umowa została już potwierdzona.')]);
        $opened=self::first_opened_at((int)$row->registration_id,$agreement_id);if($opened==='') wp_send_json_error(['message'=>'Najpierw otwórz umowę do podpisu.'],400);
        $agreement_read=sanitize_key(wp_unslash($_POST['agreement_read']??''));if($agreement_read!=='1'||$declaration==='') wp_send_json_error(['message'=>'Wszystkie oświadczenia są wymagane.'],400);
        if(strlen($code)!==6) wp_send_json_error(['message'=>'Wpisz pełny 6-cyfrowy kod SMS.'],400);
        $otp=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('otp')." WHERE agreement_id=%d AND used_at IS NULL ORDER BY id DESC LIMIT 1",$agreement_id));
        if(!$otp) wp_send_json_error(['message'=>BCS_Template_Engine::get('ui','otp_first','Najpierw wyślij kod SMS.')],400);
        if(strtotime($otp->expires_at.' Europe/Warsaw')<time()) wp_send_json_error(['message'=>BCS_Template_Engine::get('ui','otp_expired','Kod wygasł. Wyślij nowy.')],410);
        $settings=get_option('bcs_settings',[]);$max=max(3,absint($settings['max_attempts']??5));if((int)$otp->attempts>=$max) wp_send_json_error(['message'=>BCS_Template_Engine::get('ui','otp_attempts','Przekroczono liczbę prób. Wyślij nowy kod.')],429);
        $wpdb->query($wpdb->prepare("UPDATE ".BCS_DB::table('otp')." SET attempts=attempts+1 WHERE id=%d",$otp->id));
        if(!wp_check_password($code,$otp->code_hash)){BCS_Utils::log('otp_invalid',['otp_id'=>(int)$otp->id],(int)$row->registration_id,$agreement_id);wp_send_json_error(['message'=>BCS_Template_Engine::get('ui','otp_invalid','Kod jest nieprawidłowy.')],400);}
        $now=BCS_Utils::now();$wpdb->update(BCS_DB::table('otp'),['used_at'=>$now],['id'=>$otp->id]);
        $wpdb->update(BCS_DB::table('agreements'),['status'=>'accepted','accepted_at'=>$now,'accepted_ip'=>BCS_Utils::client_ip(),'accepted_user_agent'=>sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']??'')),'accepted_phone_masked'=>$row->parent_phone,'sms_message_id'=>$otp->sms_message_id,'declaration_text'=>$declaration],['id'=>$agreement_id]);
        $due=(new DateTimeImmutable('+7 days',BCS_Utils::timezone()))->format('Y-m-d');$wpdb->update(BCS_DB::table('registrations'),['agreement_status'=>'accepted','status'=>'awaiting_bank_payment','payment_due_date'=>$due,'updated_at'=>$now],['id'=>$row->registration_id]);
        if(class_exists('BCS_Workflow')) BCS_Workflow_Engine::refresh_invoice_readiness((int)$row->registration_id);
        $proof='<div class="proof"><h2>Cyfrowe potwierdzenie podpisania umowy</h2><p><strong>Status:</strong> umowa podpisana jednorazowym kodem SMS</p><p><strong>Data i czas pierwszego otwarcia umowy:</strong> '.esc_html(BCS_Utils::format_datetime($opened)).' (Europe/Warsaw)</p><p><strong>Data i czas podpisania:</strong> '.esc_html(BCS_Utils::format_datetime($now)).' (Europe/Warsaw)</p><p><strong>Numer telefonu użyty do autoryzacji:</strong> '.esc_html($row->parent_phone).'</p><p><strong>Identyfikator wiadomości SMS:</strong> '.esc_html((string)$otp->sms_message_id).'</p><p><strong>Oświadczenie podpisującego:</strong> '.esc_html($declaration).'</p><p><strong>Adres IP:</strong> '.esc_html(BCS_Utils::client_ip()).'</p><p><strong>Skrót SHA-256 podpisanej treści:</strong><br><code>'.esc_html($row->document_hash).'</code></p></div>';
        self::save_version((int)$agreement_id,(int)$row->registration_id,'signed',$row->html.$proof,$row->document_hash,$row->agreement_number);
        BCS_Utils::log('agreement_accepted',['sms_message_id'=>$otp->sms_message_id,'hash'=>$row->document_hash,'opened_at'=>$opened],(int)$row->registration_id,$agreement_id);
        if(class_exists('BCS_Communications')) BCS_Communication_Engine::send_to_registration((int)$row->registration_id,'agreement_signed','email');
        wp_send_json_success(['message'=>BCS_Template_Engine::get('ui','agreement_success','Umowa została skutecznie potwierdzona.')]);
    }

    public static function view_agreement(): void {
        global $wpdb;$id=absint($_GET['agreement']??0);$token=sanitize_text_field(wp_unslash($_GET['token']??''));
        $row=$wpdb->get_row($wpdb->prepare("SELECT a.*,r.public_token,r.parent_first_name,r.parent_last_name,r.parent_phone FROM ".BCS_DB::table('agreements')." a JOIN ".BCS_DB::table('registrations')." r ON r.id=a.registration_id WHERE a.id=%d",$id));
        if(!$row||(!current_user_can('manage_options')&&!hash_equals($row->public_token,$token))) wp_die(BCS_Template_Engine::get('ui','access_denied','Brak dostępu.'),403);
        header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><html><head><meta charset="utf-8"><title>'.esc_html($row->agreement_number).'</title><style>body{font-family:Arial,sans-serif;max-width:900px;margin:40px auto;line-height:1.55;color:#171717}.proof{margin-top:40px;padding:20px;border:2px solid #111}@media print{button{display:none}}</style></head><body><button onclick="window.print()">Drukuj / zapisz jako PDF</button>';
        echo wp_kses_post($row->html);
        if($row->status==='accepted'){$opened=self::first_opened_at((int)$row->registration_id,$id);echo '<div class="proof"><h2>Cyfrowe potwierdzenie podpisania umowy</h2><p><strong>Status:</strong> umowa podpisana jednorazowym kodem SMS</p><p><strong>Data pierwszego otwarcia umowy:</strong> '.esc_html($opened?:'—').'</p><p><strong>Data i czas podpisania:</strong> '.esc_html($row->accepted_at).'</p><p><strong>Telefon:</strong> '.esc_html($row->parent_phone).'</p><p><strong>Identyfikator SMS:</strong> '.esc_html($row->sms_message_id).'</p><p><strong>Skrót SHA-256 dokumentu:</strong><br><code>'.esc_html($row->document_hash).'</code></p></div>';}
        echo '</body></html>';exit;
    }

    public static function default_template(): string {
        $path = BCS_DIR . 'templates/agreement-basketmania-camp-v0254.html';
        if (is_readable($path)) {
            $template = file_get_contents($path);
            if (is_string($template) && trim($template) !== '') return $template;
        }
        return '<h1>Umowa uczestnictwa nr {{AGREEMENT_NUMBER}}</h1><p>Data zawarcia: {{AGREEMENT_DATE}}</p><p>Zawarta pomiędzy <strong>{{ORGANIZER_NAME}}</strong>, {{ORGANIZER_ADDRESS}}, NIP: {{ORGANIZER_NIP}}, reprezentowanym przez: {{ORGANIZER_REPRESENTATIVE}}, a rodzicem/opiekunem: <strong>{{PARENT_NAME}}</strong>, adres: {{PARENT_ADDRESS}}, e-mail: {{PARENT_EMAIL}}, telefon: {{PARENT_PHONE}}.</p><h2>Uczestnik</h2><p>{{CHILD_NAME}}, data urodzenia: {{CHILD_BIRTH_DATE}}.</p><h2>Przedmiot umowy</h2><p>Udział w turnusie <strong>{{CAMP_NAME}}</strong>, w terminie {{CAMP_DATES}}, miejsce: {{CAMP_LOCATION}}.</p><h2>Cena i płatność</h2><p>Łączna cena: <strong>{{TOTAL_AMOUNT}}</strong>.</p><p>Rachunek organizatora: <strong>{{BANK_ACCOUNT}}</strong> ({{BANK_NAME}}).</p>';
    }
}
