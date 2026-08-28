<?php
if (!defined('ABSPATH')) exit;

final class BCS_Deposits {
    private static string $error = '';

    public static function init(): void {
        add_action('wp_ajax_bcs_record_deposit',[__CLASS__,'ajax']);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets']);
    }

    public static function assets(): void {
        if (($_GET['page']??'')!=='bcs-registrations') return;
        wp_enqueue_script('bcs-deposits',BCS_URL.'assets/js/deposits.js',[],BCS_VERSION,true);
        wp_enqueue_style('bcs-deposits',BCS_URL.'assets/css/deposits.css',[],BCS_VERSION);
    }

    public static function cents(string $amount): int {
        $amount=str_replace(',','.',trim($amount));
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D',$amount)) throw new RuntimeException('Wpisz prawidłową kwotę, maksymalnie dwa miejsca po przecinku.');
        [$whole,$fraction]=array_pad(explode('.',$amount,2),2,'');
        return (int)$whole*100+(int)str_pad($fraction,2,'0');
    }

    public static function button(object $r): string {
        $due=max(0,(int)round((float)$r->total_amount*100)-(int)round((float)$r->paid_amount*100));
        if ($r->status==='cancelled' || $due<=0 || ($r->agreement_status??$r->agreement_record_status??'')!=='accepted') return '';
        return '<button type="button" class="button bcs-action-available" data-bcs-deposit data-registration-id="'.(int)$r->id.'" data-due="'.$due.'" data-nonce="'.esc_attr(wp_create_nonce('bcs_deposit_'.$r->id)).'" data-request-id="'.esc_attr(wp_generate_uuid4()).'" data-endpoint="'.esc_url(admin_url('admin-ajax.php')).'">Wpłacono zadatek</button>';
    }

    public static function ajax(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'],403);
        $id=absint($_POST['registration_id']??0);
        if (!$id || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce']??'')),'bcs_deposit_'.$id)) wp_send_json_error(['message'=>'Sesja wygasła. Odśwież zgłoszenie.'],403);
        try { $cents=self::cents((string)wp_unslash($_POST['amount']??'')); }
        catch (Throwable $e) { wp_send_json_error(['message'=>$e->getMessage()],422); }
        $key=sanitize_text_field(wp_unslash($_POST['request_id']??''));
        if (!preg_match('/^[a-f0-9-]{36}$/D',$key)) wp_send_json_error(['message'=>'Nieprawidłowy identyfikator wpłaty. Odśwież stronę.'],422);
        if (!self::book($id,$cents,'',$key)) wp_send_json_error(['message'=>self::$error],409);
        wp_send_json_success(['message'=>'Zadatek został zaksięgowany. Pozostała kwota do zapłaty została pomniejszona.']);
    }

    /** Null amount settles only the outstanding balance. Rows and balances commit together. */
    public static function book(int $id,?int $deposit=null,string $paid_at='',string $request_id=''): bool {
        global $wpdb;
        self::$error='';$committed=false;$duplicate=false;
        try {
            $now=BCS_Utils::now();
            $date=new DateTimeImmutable($paid_at===''?$now:$paid_at,BCS_Utils::timezone());
            $today=new DateTimeImmutable('today',BCS_Utils::timezone());
            if ($date>$today->setTime(23,59,59)) throw new RuntimeException('Data wpłaty nie może być przyszła.');
            $paid_at=$date->format('Y-m-d H:i:s');
            if ($wpdb->query('START TRANSACTION')===false) throw new RuntimeException('Nie udało się rozpocząć zapisu wpłaty.');
            $r=$wpdb->get_row($wpdb->prepare('SELECT r.*,c.organizer_id FROM '.BCS_DB::table('registrations').' r JOIN '.BCS_DB::table('camps').' c ON c.id=r.camp_id WHERE r.id=%d FOR UPDATE',$id));
            if (!$r || $r->status==='cancelled' || $r->agreement_status!=='accepted') throw new RuntimeException('Wpłata dostępna po podpisaniu umowy, dla aktywnego zgłoszenia.');
            $external_id=$request_id!==''?'deposit-'.$request_id:'bank-'.wp_generate_uuid4();
            if ($request_id!=='') {
                $previous=$wpdb->get_row($wpdb->prepare('SELECT amount FROM '.BCS_DB::table('payments')." WHERE registration_id=%d AND external_id=%s AND status='paid' LIMIT 1",$id,$external_id));
                if ($previous) {
                    if ((int)round((float)$previous->amount*100)!==$deposit) throw new RuntimeException('Ta operacja została już zapisana z inną kwotą. Odśwież zgłoszenie.');
                    $duplicate=true;
                }
            }
            if (!$duplicate) {
                $total=(int)round((float)$r->total_amount*100);$already=(int)round((float)$r->paid_amount*100);$due=$total-$already;
                if ($total<=0 || $due<=0) throw new RuntimeException('Zgłoszenie jest już opłacone lub nie ma kwoty do zapłaty.');
                if ($deposit!==null && ($deposit<=0 || $deposit>=$due)) throw new RuntimeException('Zadatek musi być większy od zera i mniejszy od pozostałej kwoty. Całość zaksięguj przyciskiem „Zaksięguj wpłatę”.');
                $amount=$deposit??$due;$new=$already+$amount;$full=$new===$total;
                if (!$wpdb->insert(BCS_DB::table('payments'),['registration_id'=>$id,'organizer_id'=>(int)$r->organizer_id,'provider'=>'bank','external_id'=>$external_id,'amount'=>number_format($amount/100,2,'.',''),'currency'=>'PLN','status'=>'paid','paid_at'=>$paid_at,'created_at'=>$now,'updated_at'=>$now])) throw new RuntimeException('Nie udało się zapisać wpłaty.');
                $payment_id=(int)$wpdb->insert_id;
                if ($wpdb->update(BCS_DB::table('registrations'),['payment_id'=>$payment_id,'paid_amount'=>number_format($new/100,2,'.',''),'status'=>$full?'paid':'partially_paid','updated_at'=>$now],['id'=>$id])===false) throw new RuntimeException('Nie udało się zaktualizować salda. Wpłata nie została zapisana.');
            }
            if ($wpdb->query('COMMIT')===false) throw new RuntimeException('Nie udało się zatwierdzić wpłaty.');
            $committed=true;
        } catch (Throwable $e) { self::$error=$e->getMessage();return false; }
        finally { if (!$committed) $wpdb->query('ROLLBACK'); }
        if ($duplicate) return true;
        // Notifications must not turn a committed payment into a retryable failure.
        try {
            BCS_Workflow::refresh_invoice_readiness($id);
            BCS_Utils::log($full?'bank_payment_marked_paid':'bank_deposit_recorded',['payment_record_id'=>$payment_id,'amount'=>$amount/100,'remaining'=>($total-$new)/100,'confirmed_at'=>$paid_at,'booked_at'=>$now],$id,(int)$r->agreement_id);
            if ($full) {
                if (class_exists('BCS_Communications')) BCS_Communication_Engine::send_to_registration($id,'paid','email','', '',false);
                BCS_Qualification::payment_received($id);
            }
        } catch (Throwable $e) { BCS_Utils::log('bank_payment_followup_error',['message'=>$e->getMessage()],$id); }
        return true;
    }
}
