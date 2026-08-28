<?php
/** Behavioral regression tests. No live SMS, mail or customer data. */
ob_start();
define('ABSPATH',__DIR__.'/');define('BCS_DIR',dirname(__DIR__).'/');define('BCS_URL','https://example.test/plugin/');
define('BCS_VERSION','test');
function absint($v){return abs((int)$v);}function sanitize_key($v){return preg_replace('/[^a-z0-9_]/','',strtolower($v));}function wp_enqueue_style(...$args){}function wp_enqueue_script(...$args){}
define('DAY_IN_SECONDS',86400);define('HOUR_IN_SECONDS',3600);define('MINUTE_IN_SECONDS',60);
class WP_Error {function __construct(public $code,public $message){} function get_error_message(){return $this->message;}}
function is_wp_error($v){return $v instanceof WP_Error;}function sanitize_text_field($v){return trim(strip_tags($v));}function wp_unslash($v){return $v;}
function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL)!==false;}function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}function esc_attr($s){return esc_html($s);}function esc_url($s){return $s;}function get_option($k,$d=[]){return $d;}function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}function wp_hash_password($c){return password_hash($c,PASSWORD_DEFAULT);}function wp_check_password($c,$h){return password_verify($c,$h);}function get_current_user_id(){return 7;}function current_user_can($v){return $GLOBALS['admin']??false;}function wp_verify_nonce($n,$a){return $n==='valid';}function wp_create_nonce($a){return 'valid';}function wp_nonce_field($a,$name='_wpnonce',$referer=true,$echo=true){return '<input name="'.$name.'" value="valid">';}function admin_url($p){return 'https://example.test/'.$p;}function add_query_arg($a,$url){return $url.'?'.http_build_query($a);}
function get_page_by_path($path){return null;}function home_url($path){return 'https://example.test'.$path;}
require BCS_DIR.'includes/class-bcs-workflow.php';
class BCS_DB {static function table($s){return 'wp_bcs_'.$s;}}
class BCS_Utils {static function normalize_phone($v){$v=preg_replace('/\D/','',$v);return strlen($v)===9?'48'.$v:$v;}static function now(){return '2026-08-28 12:00:00';}static function log(...$args){}static function client_ip(){return '192.0.2.1';}static function mask_phone($v){return '***'.substr($v,-3);}static function registration_address($r){return 'ul. Testowa 1, 00-001 Testowo';}}
class BCS_SMS {static $codes=[];static $messages=[];static function send($phone,$text){self::$messages[]=$text;preg_match('/: (\d{6})\./',$text,$m);self::$codes[$phone]=$m[1]??'';return ['success'=>true,'message_id'=>'sms-'.count(self::$codes)];}}
class BCS_Mailer {static $mails=[];static function send(...$args){self::$mails[]=$args;return true;}}
class FakeDB {public $prefix='wp_';public $saved=[];function replace($table,$row){$this->saved[$row['registration_id']]=$row;return 1;}function prepare($q,...$a){foreach($a as $v)$q=preg_replace('/%[ds]/',(string)$v,$q,1);return $q;}function query($q){return 1;}function update($table,$data,$where){$GLOBALS['readiness_updates'][]=$data;return 1;}function get_var($q){if(str_contains($q,'SELECT payload')){preg_match('/registration_id=(\d+)/',$q,$m);return $this->saved[(int)($m[1]??0)]['payload']??null;}return 1;}function get_row($q){return $GLOBALS['payment_fixture']??null;}}
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
invoke('require_acceptance',['read'=>'1']);
foreach ([[],['read'=>'0'],['read'=>'false']] as $missing) fails(fn()=>invoke('require_acceptance',$missing),'Explicit acceptance required');
$notOpened=$card;
fails(fn()=>invoke('signing_allowed',$r,$notOpened,'parent'),'Old landing-page visit does not count as document review');
foreach ($card['signers'] as &$signer) $signer['reviewed_hash']=$card['hash'];unset($signer);
$notOpened=$card;unset($notOpened['signers']['parent']['opened_at']);
fails(fn()=>invoke('signing_allowed',$r,$notOpened,'parent'),'Document must be opened before signing');
$notOpened=$card;$notOpened['signers']['parent']['reviewed_hash']='different';
fails(fn()=>invoke('signing_allowed',$r,$notOpened,'parent'),'Review must concern current immutable snapshot');
$portalUrl=BCS_Qualification::portal_url(1,'second_parent','personal-secret');
check(str_contains($portalUrl,'/panel-rodzica/') && str_contains($portalUrl,'card_role=second_parent') && str_contains($portalUrl,'card_token=personal-secret'),'Individual invitation routes to Parent Panel');
$controls=invoke('parent_controls',1,$card,'second_parent','personal-secret');
foreach (['bcs-card','data-card-open','op=document','name="read" value="1" disabled required','data-card-send disabled','data-card-otp','autocomplete="one-time-code"','Jan'] as $needle) check(str_contains($controls,$needle),'Parent panel control: '.$needle);
check(!str_contains($controls,'anna@example.test'),'Parent view does not expose another parent signing credential');
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
// Actual shared invoice gate: payment alone and parent signatures cannot unlock it.
$invoiceRow=(object)array_merge((array)$r,['agreement_status'=>'accepted','invoice_status'=>'not_generated','start_date'=>'2027-07-01']);
$GLOBALS['payment_fixture']=$invoiceRow;
check(BCS_Workflow::invoice_available(1),'All required signatures unlock invoice');
foreach (['parent','second_parent','organizer'] as $missingRole) {
    $pending=$card;unset($pending['signers'][$missingRole]['signed_at']);invoke('save',1,$pending);
    check(!BCS_Workflow::invoice_available(1),'Invoice blocked while awaiting '.$missingRole);
}
$pending=$card;unset($pending['signers']['second_parent']);invoke('save',1,$pending);
check(!BCS_Workflow::invoice_available(1),'Missing second parent never implies sole custody');
$pending['sole_guardian']=1;invoke('save',1,$pending);
check(BCS_Workflow::invoice_available(1),'Declared sole guardian plus organizer unlocks invoice');
unset($pending['signers']['organizer']['signed_at']);invoke('save',1,$pending);
check(!BCS_Workflow::invoice_available(1),'Sole guardian still requires organizer');
invoke('save',1,$card);
$invoiceRow->paid_amount=0;check(!BCS_Workflow::invoice_available(1),'Signatures do not replace payment');
$invoiceRow->paid_amount=$invoiceRow->total_amount;
$invoiceRow->status='cancelled';check(!BCS_Workflow::invoice_available(1),'Cancelled registration stays blocked');
$invoiceRow->status='paid';
check(!BCS_Workflow::invoice_available(999),'Missing qualification card blocks invoicing');
invoke('sync_status',1,$card);
check(end($GLOBALS['readiness_updates'])['invoice_status']==='ready_to_generate','Last signature refreshes invoice readiness');
unset($GLOBALS['payment_fixture']);
require BCS_DIR.'includes/class-bcs-crm.php';
$actionRow=(object)['id'=>1,'status'=>'paid','total_amount'=>3000,'paid_amount'=>3000];
$ready=$card;unset($ready['signers']['organizer']['signed_at']);invoke('save',1,$ready);
$button=BCS_Qualification::organizer_action($actionRow);
check(str_contains($button,'data-qualification-organizer-sign'),'Signing opens dedicated organizer OTP popup');
check(str_contains($button,'Podpisz kartę kwalifikacyjną') && str_contains($button,'data-qualification-admin-preview'),'Organizer signing button uses popup after parents sign');
$listMethod=new ReflectionMethod(BCS_CRM::class,'list_quick_actions_html');
check(str_contains($listMethod->invoke(null,$actionRow),'Podpisz kartę kwalifikacyjną'),'Registration list shows signing action from actual signatures');
$pending=$ready;unset($pending['signers']['second_parent']['signed_at']);invoke('save',1,$pending);
check(BCS_Qualification::organizer_action($actionRow)==='','No signing action before both parents');
$pending['sole_guardian']=1;unset($pending['signers']['second_parent']);invoke('save',1,$pending);
check(BCS_Qualification::organizer_action($actionRow)!=='','Sole guardian signature unlocks organizer action');
$actionRow->status='cancelled';check(BCS_Qualification::organizer_action($actionRow)==='','Cancelled registration hides signing');
$actionRow->status='paid';$actionRow->paid_amount=500;check(BCS_Qualification::organizer_action($actionRow)==='','Partial payment hides signing');
$actionRow->paid_amount=3000;invoke('save',1,$card);
check(BCS_Qualification::organizer_action($actionRow)==='','Signed card hides signing action');
foreach (BCS_SMS::$messages as $sms) check(!str_contains($sms,'#') && str_contains($sms,'kod podpisu karty kwalifikacyjnej:'),'No qualification serial in SMS');
check(str_contains(file_get_contents(BCS_DIR.'includes/class-bcs-crm.php'),'echo BCS_Qualification::organizer_action($r);'),'Registration detail uses same signing action');
$adminPanel=BCS_Qualification::admin_panel(1);
check(str_contains($adminPanel,'data-qualification-admin-preview'),'CRM preview opens through popup trigger');
check(str_contains($adminPanel,'op=download'),'Signed PDF remains a separate download');
check(substr_count($adminPanel,'data-qualification-admin-preview')===1,'Only preview, not download, is intercepted');
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
        $sample['html']=BCS_Qualification::prepare_html(BCS_Qualification::render_body($r,$data).'<footer class="bcs-document-footer">Organizator Testowy - ul. Testowa 1 - NIP: dane testowe</footer>');
        $sample['hash']=hash('sha256',$sample['html']);
        foreach ($sample['signers'] as &$signer) $signer['document_hash']=$sample['hash'];unset($signer);
        $html=invoke('final_html',$sample);
        $pdf=new Dompdf\Dompdf(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'defaultFont'=>'DejaVu Sans']);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4');$pdf->render();
        check($pdf->getCanvas()->get_page_count()>=2,'Card has content and evidence pages');
        file_put_contents('/tmp/qualification-'.$name.'.pdf',$pdf->output());file_put_contents('/tmp/qualification-'.$name.'.html',$html);
        ob_start();invoke('page',1,$sample,'parent','secret','Test');$preview=ob_get_clean();
        check(str_contains($preview,'bcs-card-controls'), 'Signing controls mounted');
        check(str_contains($preview,'<h1>Karta kwalifikacyjna</h1>') && !str_contains($preview,'Karta kwalifikacyjna #'),'Preview has no card serial');
        check(str_contains($preview,'name="bcs-card-stage" content="card_signed"'),'Popup detects completed signature to refresh CRM');
        $unsigned=$sample;unset($unsigned['signers']['parent']['signed_at']);ob_start();invoke('page',1,$unsigned,'parent','secret','Test');$form=ob_get_clean();check(str_contains($form,'name="card_nonce"')&&str_contains($form,'value="send"')&&str_contains($form,'value="sign"'),'Unsigned parent has nonce-protected signing form');
        file_put_contents('/tmp/qualification-'.$name.'-preview.html',$preview);
    }
    $fixture=(object)array_merge((array)$r,$input,['invoice_status'=>'not_generated','agreement_status'=>'accepted','form_verified_at'=>'2026-08-28','organizer_name'=>'Test organizer','organizer_phone'=>'600555666','organizer_representative'=>'Test organizer','organizer_email'=>'org@example.test','name'=>'Test organizer','legal_form'=>'','address'=>'Testowo','nip'=>'','regon'=>'','krs'=>'','email'=>'org@example.test','phone'=>'600555666']);
    $GLOBALS['payment_fixture']=$fixture;BCS_Mailer::$mails=[];
    BCS_Qualification::payment_received(112);$created=BCS_Qualification::card(112);
    check($created!==null && count(BCS_Mailer::$mails)===2,'Full payment creates a card and two invitations');
    check(count($created['signers'])===3,'Required signatures frozen');
    BCS_Qualification::payment_received(112);check(count(BCS_Mailer::$mails)===2,'Duplicate payment does not resend successful invitations');
    check(BCS_Qualification::card(112)['hash']===$created['hash'],'Duplicate payment preserves snapshot');
    $fixture->paid_amount=100;BCS_Qualification::payment_received(113);check(BCS_Qualification::card(113)===null,'Deposit does not create a card');
    $fixture->paid_amount=3000;$fixture->sole_guardian=1;BCS_Qualification::payment_received(114);check(count(BCS_Qualification::card(114)['signers'])===2,'Sole custody freezes only parent and organizer');
    check(count(BCS_Mailer::$mails)===3,'Sole guardian gets one invitation');
    $panelCard=BCS_Qualification::card(112);
    $panelCard['signers']['second_parent']['token_hash']=hash('sha256','panel-secret');
    invoke('save',112,$panelCard);
    $_GET=['qualification'=>112,'card_role'=>'second_parent','card_token'=>'panel-secret'];
    $panel=BCS_Qualification::portal_view();
    check(str_contains($panel,'Panel Rodzica') && str_contains($panel,'data-card-sign-form'),'Authorized second parent opens real portal view');
    check(empty(BCS_Qualification::card(112)['signers']['second_parent']['reviewed_hash']),'Portal landing does not mark document reviewed');
    $_GET['card_role']='parent';
    check(!str_contains(BCS_Qualification::portal_view(),'data-card-sign-form'),'Cannot use second-parent invitation to sign as first parent');
    $_GET['card_role']='organizer';
    check(!str_contains(BCS_Qualification::portal_view(),'data-card-sign-form'),'Portal cannot expose organizer signature');
    $_GET=[];
    echo "PASS: rendered qualification card variants and scoped Parent Panel\n";
}

$organizerUi=file_get_contents(BCS_DIR.'assets/js/qualification-organizer.js');
foreach (['bcs-otp079-dialog','bcs-otp079-code','bcs-otp079-actions','Kod SMS Organizatora','signing_context',"card_nonce:context.nonce",'bcs-card-reviewed','autocomplete="one-time-code"'] as $needle) check(str_contains($organizerUi,$needle),'Organizer popup parity and secure flow: '.$needle);
ob_end_flush();
