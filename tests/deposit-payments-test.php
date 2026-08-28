<?php
/** Manual deposit and final balance behavior without live mail/SMS/payment providers. */
define('ABSPATH',__DIR__.'/');
class WP_Error {function __construct(public $code,public $message){}}
function absint($v){return abs((int)$v);}function sanitize_text_field($v){return trim((string)$v);}
class BCS_Workflow_Engine {static function refresh_invoice_readiness($id){}}
class BCS_Communication_Engine {static function send_to_registration(...$args){return true;}}
class BCS_DB { static function table($name){return $name;} }
class BCS_Utils {
    static function now(){return '2026-01-01 12:00:00';}
    static function timezone(){return new DateTimeZone('Europe/Warsaw');}
    static function log(...$args){}
}
class BCS_Qualification {static int $calls=0;static function payment_received($id){self::$calls++;}}
class BCS_Workflow {static function refresh_invoice_readiness($id){}}
function wp_generate_uuid4(){static $n=0;return sprintf('00000000-0000-4000-8000-%012d',++$n);}
class PaymentDB {
    public object $row;public array $payments=[];public int $insert_id=0;public bool $failUpdate=false;private array $snapshot=[];
    function __construct(){ $this->row=(object)['id'=>1,'total_amount'=>'3000.00','paid_amount'=>'0.00','agreement_status'=>'accepted','status'=>'agreement_accepted','organizer_id'=>7,'agreement_id'=>2]; }
    function prepare($query,...$args){foreach($args as $value)$query=preg_replace('/%[ds]/',is_int($value)?(string)$value:"'".$value."'",$query,1);return $query;}
    function query($q){
        if($q==='START TRANSACTION')$this->snapshot=[clone $this->row,$this->payments];
        if($q==='ROLLBACK' && $this->snapshot)[$this->row,$this->payments]=$this->snapshot;
        if(str_starts_with($q,'UPDATE payments')){
            preg_match('/WHERE id=(\d+)/',$q,$m);$id=(int)$m[1];
            if($this->payments[$id]['status']==='paid')return 0;
            $this->payments[$id]['status']='paid';return 1;
        }
        return 1;
    }
    function get_var($q){return array_sum(array_map(fn($p)=>$p['status']==='paid'?(float)$p['amount']:0,$this->payments));}
    function get_row($q){
        if(str_contains($q,'FROM registrations')) return clone $this->row;
        if(preg_match('/WHERE id=(\d+)/',$q,$m))return isset($this->payments[(int)$m[1]])?(object)$this->payments[(int)$m[1]]:null;
        foreach($this->payments as $p)if(str_contains($q,"'".$p['external_id']."'"))return (object)$p;
        return null;
    }
    function insert($table,$data){$this->payments[++$this->insert_id]=$data;return 1;}
    function update($table,$data,$where){if($this->failUpdate)return false;foreach($data as $k=>$v)$this->row->$k=$v;return 1;}
}
require dirname(__DIR__).'/includes/class-bcs-deposits.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function fresh(){ $GLOBALS['wpdb']=new PaymentDB();BCS_Qualification::$calls=0;return $GLOBALS['wpdb']; }
check(BCS_Deposits::cents('500,25')===50025,'Polish decimal input');
check(BCS_Deposits::cents('0.01')===1,'One grosz');
foreach(['-5','1e3','NaN','10.001','1,2.3',''] as $bad){$failed=false;try{BCS_Deposits::cents($bad);}catch(RuntimeException $e){$failed=true;}check($failed,'Invalid amount: '.$bad);}
$db=fresh();
check(BCS_Deposits::book(1,50025,'','request-1'),'Deposit saved');
check($db->row->paid_amount==='500.25' && $db->row->status==='partially_paid','Partial balance/status');
check($db->row->total_amount==='3000.00','Deposit never changes price');
check(count($db->payments)===1 && BCS_Qualification::$calls===0,'Partial payment never invites qualification signers');
check(BCS_Deposits::book(1,50025,'','request-1') && count($db->payments)===1,'Repeated request is idempotent');
check(!BCS_Deposits::book(1,10000,'','request-1'),'Cannot reuse request for a different amount');
foreach([0,-1,249975,250000] as $invalid)check(!BCS_Deposits::book(1,$invalid,'','invalid-'.$invalid),'Reject invalid/excess deposit');
check(BCS_Deposits::book(1,null),'Final payment settles remainder');
check(end($db->payments)['amount']==='2499.75','Final entry is balance, not full price');
check($db->row->paid_amount==='3000.00' && $db->row->status==='paid','Fully paid');
check(BCS_Qualification::$calls===1,'Only full payment triggers card');
check(!BCS_Deposits::book(1,null) && count($db->payments)===2,'No repeated full payment');
check(BCS_Deposits::book(1,50025,'','request-1') && count($db->payments)===2,'Deposit retry after full payment does not duplicate');
$db=fresh();$db->failUpdate=true;
check(!BCS_Deposits::book(1,50000,'','rollback'),'Balance failure is reported');
check(!$db->payments && $db->row->paid_amount==='0.00','Balance failure rolls back payment record');
$db=fresh();$db->row->agreement_status='pending';check(!BCS_Deposits::book(1,50000),'Requires signed agreement');
$db=fresh();$db->row->status='cancelled';check(!BCS_Deposits::book(1,50000),'Cancelled registration rejected');
$db=fresh();check(BCS_Deposits::book(1,null,'2025-12-31 12:00:00'),'Existing full-payment date still supported');
check(end($db->payments)['paid_at']==='2025-12-31 12:00:00','Selected booking date preserved');
$workflow=file_get_contents(dirname(__DIR__).'/includes/class-bcs-workflow.php');
check(str_contains($workflow,'BCS_Deposits::book($id,null,$paid_at)'),'All existing full-payment actions settle through common service');
echo "PASS: deposit validation, remaining balance, replay, rollback and full-payment trigger\n";

require dirname(__DIR__).'/includes/class-bcs-payments.php';
$db=fresh();BCS_Deposits::book(1,50025,'','stripe-deposit');
$db->insert('payments',['registration_id'=>1,'organizer_id'=>7,'amount'=>'2499.75','status'=>'created','external_id'=>'cs_test']);
$session=['id'=>'cs_test','metadata'=>['payment_id'=>2,'registration_id'=>1],'payment_status'=>'paid','currency'=>'pln','amount_total'=>249975];
$confirm=new ReflectionMethod(BCS_Payments::class,'confirm_paid_session');
$result=$confirm->invoke(null,$session,(object)['id'=>7]);
check(is_array($result) && $result['paid'] && (float)$db->row->paid_amount===3000.0,'Stripe settles balance after deposit');
$retry=$confirm->invoke(null,$session,(object)['id'=>7]);
check($retry['duplicate'] && count($db->payments)===2 && (float)$db->row->paid_amount===3000.0,'Duplicate Stripe event does not add money');
$db=fresh();BCS_Deposits::book(1,50025,'','stripe-rollback');
$db->insert('payments',['registration_id'=>1,'organizer_id'=>7,'amount'=>'2499.75','status'=>'created','external_id'=>'cs_test']);$db->failUpdate=true;
$result=$confirm->invoke(null,$session,(object)['id'=>7]);
check($result instanceof WP_Error && $db->payments[2]['status']==='created' && $db->row->paid_amount==='500.25','Stripe balance failure rolls back claim and preserves deposit');
echo "PASS: deposit plus Stripe settlement, duplicate webhook and transactional rollback\n";
