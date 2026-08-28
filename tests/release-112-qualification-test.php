<?php
/** Behavioral regression tests. No live SMS, mail or customer data. */
define('ABSPATH',__DIR__.'/');define('BCS_DIR',dirname(__DIR__).'/');define('BCS_URL','https://example.test/plugin/');
define('DAY_IN_SECONDS',86400);define('HOUR_IN_SECONDS',3600);define('MINUTE_IN_SECONDS',60);
class WP_Error {function __construct(public $code,public $message){} function get_error_message(){return $this->message;}}
function is_wp_error($v){return $v instanceof WP_Error;}function sanitize_text_field($v){return trim(strip_tags($v));}function wp_unslash($v){return $v;}
function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL)!==false;}function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}function esc_attr($s){return esc_html($s);}function esc_url($s){return $s;}function get_option($k,$d=[]){return $d;}function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}function wp_hash_password($c){return password_hash($c,PASSWORD_DEFAULT);}function wp_check_password($c,$h){return password_verify($c,$h);}function get_current_user_id(){return 7;}function current_user_can($v){return $GLOBALS['admin']??false;}function wp_verify_nonce($n,$a){return $n==='valid';}function wp_create_nonce($a){return 'valid';}function admin_url($p){return 'https://example.test/'.$p;}function add_query_arg($a,$url){return $url.'?'.http_build_query($a);}
class BCS_DB {static function table($s){return 'wp_bcs_'.$s;}}
class BCS_Utils {static function normalize_phone($v){$v=preg_replace('/\D/','',$v);return strlen($v)===9?'48'.$v:$v;}static function now(){return '2026-08-28 12:00:00';}static function log(...$args){}static function client_ip(){return '192.0.2.1';}static function mask_phone($v){return '***'.substr($v,-3);}static function registration_address($r){return 'ul. Testowa 1, 00-001 Testowo';}}
class BCS_SMS {static $codes=[];static function send($phone,$text){preg_match('/: (\d{6})\./',$text,$m);self::$codes[$phone]=$m[1]??'';return ['success'=>true,'message_id'=>'sms-'.count(self::$codes)];}}
class BCS_Mailer {static $mails=[];static function send(...$args){self::$mails[]=$args;return true;}}
class FakeDB {public $prefix='wp_';public $saved=[];function replace($table,$row){$this->saved[$row['registration_id']]=$row;return 1;}function prepare($q,...$a){return $q;}function query($q){return 1;}function get_var($q){return 1;}}
$GLOBALS['wpdb']=new FakeDB();
require BCS_DIR.'includes/class-bcs-qualification.php';
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function invoke($method,...$args){$r=new ReflectionMethod(BCS_Qualification::class,$method);return $r->invokeArgs(null,$args);}
function fails($fn,$message){try{$fn();}catch(RuntimeException $e){return;}throw new RuntimeException($message);}
$input=['parent_first_name'=>'Anna','parent_last_name'=>'Testowa','parent_email'=>'anna@example.test','parent_phone'=>'600111222','second_parent_first_name'=>'Jan','second_parent_last_name'=>'Testowy','second_parent_email'=>'jan@example.test','second_parent_phone'=>'+48 600 333 444'];
$parents=BCS_Qualification::parent_data($input);check(!is_wp_error($parents),'Two valid parents');
check(is_wp_error(BCS_Qualification::parent_data(array_merge($input,['second_parent_phone'=>'+48 600 111 222']))),'Equivalent phones rejected');
check(is_wp_error(BCS_Qualification::parent_data(array_merge($input,['second_parent_email'=>'ANNA@example.test']))),'Equivalent emails rejected');
check(is_wp_error(BCS_Qualification::parent_data(array_merge($input,['second_parent_first_name'=>'']))),'Missing parent rejected');
$sole=BCS_Qualification::parent_data(array_merge($input,['sole_guardian'=>1]));check($sole['second_parent_email']===''&&$sole['second_parent_phone']==='','Sole clears second parent');
check(BCS_Qualification::fully_paid((object)['total_amount'=>3000,'paid_amount'=>3000]),'Fully paid');
check(!BCS_Qualification::fully_paid((object)['total_amount'=>3000,'paid_amount'=>500]),'Deposit must not trigger');
check(!BCS_Qualification::fully_paid((object)['total_amount'=>0,'paid_amount'=>0]),'Zero amount must not trigger');
$r=(object)['total_amount'=>3000,'paid_amount'=>3000,'status'=>'paid','child_first_name'=>'Test','child_last_name'=>'Uczestnik','child_birth_date'=>'2014-05-06','child_pesel'=>'TEST','child_address'=>'Testowo','start_date'=>'2027-07-04','end_date'=>'2027-07-10','location'=>'Testowo'];
$body=BCS_Qualification::render_body($r,$parents);check(str_contains($body,'[X] Postanawia się zakwalifikować'),'Qualification selected');check(!str_contains($body,BCS_Qualification::SOLE_DECLARATION),'No false sole declaration');check(str_contains(BCS_Qualification::render_body($r,$sole),BCS_Qualification::SOLE_DECLARATION),'Sole declaration printed');
foreach(['I. INFORMACJE','II. INFORMACJE','III. DECYZJA','IV. POTWIERDZENIE','V. INFORMACJA','VI. INFORMACJA'] as $heading)check(str_contains($body,$heading),'Section retained '.$heading);
$source=file_get_contents(BCS_DIR.'templates/agreement-default.html');$separated=BCS_Qualification::separate_card($source);check(!str_contains($separated,'<h2>ZAŁĄCZNIK NR 1'),'Attachment removed');check(str_contains($separated,'§10 POSTANOWIENIA'),'Contract preserved');check(str_contains($separated,'osobnym dokumentem'),'Independent card clause');check(BCS_Qualification::separate_card($separated)===$separated,'Separation idempotent');
$custom='<div><h2>ZAŁĄCZNIK NR 1<br>KARTA KWALIFIKACYJNA UCZESTNIKA WYPOCZYNKU</h2><p>Old card</p><h2>ZAŁĄCZNIK NR 2</h2><p>Keep other attachment</p></div>';
check(str_contains(BCS_Qualification::separate_card($custom),'Keep other attachment'),'Other attachments preserved');
$card=['html'=>'<body><main>Frozen</main></body>','hash'=>hash('sha256','<body><main>Frozen</main></body>'),'sole_guardian'=>0,'signers'=>['parent'=>['name'=>'Anna','phone'=>'48600111222','email'=>'anna@example.test','opened_at'=>'2026-08-28 10:00:00'],'second_parent'=>['name'=>'Jan','phone'=>'48600333444','email'=>'jan@example.test','opened_at'=>'2026-08-28 10:00:00'],'organizer'=>['name'=>'Organizator','phone'=>'48600555666','email'=>'org@example.test','opened_at'=>'2026-08-28 10:00:00']]];
check(BCS_Qualification::stage($card)==='card_parents','Initial stage');
fails(fn()=>invoke('signing_allowed',$r,$card,'organizer'),'Premature organizer signature');
$args=[1,&$card,'parent'];(new ReflectionMethod(BCS_Qualification::class,'send_code'))->invokeArgs(null,$args);$first=BCS_SMS::$codes['48600111222'];
$bad=[1,&$card,'parent','000000'];try{(new ReflectionMethod(BCS_Qualification::class,'sign'))->invokeArgs(null,$bad);}catch(RuntimeException $e){}check($card['signers']['parent']['challenge']['attempts']===1,'Attempts persisted');
$args=[1,&$card,'second_parent'];(new ReflectionMethod(BCS_Qualification::class,'send_code'))->invokeArgs(null,$args);$second=BCS_SMS::$codes['48600333444'];
check($card['signers']['parent']['challenge']['hash']!==$card['signers']['second_parent']['challenge']['hash'],'Independent challenges');
$args=[1,&$card,'parent',$first];(new ReflectionMethod(BCS_Qualification::class,'sign'))->invokeArgs(null,$args);
check(BCS_Qualification::stage($card)==='card_parents','One parent does not unlock organizer');
fails(fn()=>invoke('signing_allowed',$r,$card,'parent'),'Replay rejected');
$args=[1,&$card,'second_parent',$second];(new ReflectionMethod(BCS_Qualification::class,'sign'))->invokeArgs(null,$args);check(BCS_Qualification::stage($card)==='card_organizer','Both parents unlock organizer');
invoke('signing_allowed',$r,$card,'organizer');$args=[1,&$card,'organizer'];(new ReflectionMethod(BCS_Qualification::class,'send_code'))->invokeArgs(null,$args);$args=[1,&$card,'organizer',BCS_SMS::$codes['48600555666']];(new ReflectionMethod(BCS_Qualification::class,'sign'))->invokeArgs(null,$args);
check(BCS_Qualification::stage($card)==='card_signed','All signed');check(!isset($card['signers']['organizer']['challenge']),'Used challenge removed');
$proof=invoke('proof',$card);foreach(['Anna','Jan','Organizator','sms-1','sms-2','sms-3',$card['hash']] as $v)check(str_contains($proof,$v),'Proof contains '.$v);
$one=$card;unset($one['signers']['second_parent']);unset($one['signers']['organizer']['signed_at']);$one['sole_guardian']=1;check(BCS_Qualification::stage($one)==='card_organizer','Sole parent path');
$auth=$card;$auth['signers']['parent']['token_hash']=hash('sha256','secret');$auth['signers']['parent']['token_expires']=time()+60;
invoke('authorize',1,$auth,'parent','secret');fails(fn()=>invoke('authorize',1,$auth,'second_parent','secret'),'Cross-role token rejected');fails(fn()=>invoke('authorize',1,$auth,'parent','wrong'),'Wrong token rejected');$auth['signers']['parent']['token_expires']=time()-1;fails(fn()=>invoke('authorize',1,$auth,'parent','secret'),'Expired token rejected');
$unsigned=$card;unset($unsigned['signers']['parent']['signed_at']);$unsigned['html']='Tampered';fails(fn()=>invoke('signing_allowed',$r,$unsigned,'parent'),'Tampered snapshot rejected');
$unsigned['html']=$card['html'];$cancel=clone $r;$cancel->status='cancelled';fails(fn()=>invoke('signing_allowed',$cancel,$unsigned,'parent'),'Cancelled signature rejected');$partial=clone $r;$partial->paid_amount=500;fails(fn()=>invoke('signing_allowed',$partial,$unsigned,'parent'),'Partial payment rejected');
echo "PASS: qualification parents, document, authorization, independent OTP, status transitions and evidence\n";
if (is_readable(BCS_DIR.'vendor/autoload.php')) {
    require BCS_DIR.'vendor/autoload.php';
    require BCS_DIR.'includes/class-bcs-agreement-pdf-v2.php';
    require BCS_DIR.'includes/class-bcs-agreement-pdf-v2-finalizer.php';
    foreach (['two'=>$parents,'sole'=>$sole] as $name=>$data) {
        $sample=$card;
        if ($data['sole_guardian']) unset($sample['signers']['second_parent']);
        $sample['sole_guardian']=$data['sole_guardian'];
        $sample['html']=BCS_Agreement_PDF_V2_Finalizer::finalize(BCS_Agreement_PDF_V2::prepare_pdf_html(BCS_Qualification::render_body($r,$data).'<footer class="bcs-document-footer">Organizator Testowy - ul. Testowa 1 - NIP: dane testowe</footer>','Karta kwalifikacyjna',0));
        $sample['hash']=hash('sha256',$sample['html']);
        foreach ($sample['signers'] as &$signer) $signer['document_hash']=$sample['hash'];unset($signer);
        $html=invoke('final_html',$sample);
        $pdf=new Dompdf\Dompdf(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'defaultFont'=>'DejaVu Sans']);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4');$pdf->render();
        check($pdf->getCanvas()->get_page_count()>=2,'Card has content and evidence pages');
        file_put_contents('/tmp/qualification-'.$name.'.pdf',$pdf->output());file_put_contents('/tmp/qualification-'.$name.'.html',$html);
        ob_start();invoke('page',1,$sample,'parent','secret','Test');$preview=ob_get_clean();
        check(str_contains($preview,'bcs-card-controls'), 'Signing controls mounted');
        file_put_contents('/tmp/qualification-'.$name.'-preview.html',$preview);
    }
    echo "PASS: rendered qualification card variants\n";
}
