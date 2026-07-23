<?php
if (!defined('ABSPATH')) exit;

class BCS_Payments {
    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function organizer(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('organizers')." WHERE id=%d", $id)) ?: null;
    }

    public static function stripe_credentials(object $organizer): array {
        $mode = $organizer->stripe_mode === 'live' ? 'live' : 'test';
        return [
            'mode'=>$mode,
            'secret_key'=>trim((string)($mode === 'live' ? $organizer->stripe_live_secret_key : $organizer->stripe_test_secret_key)),
            'webhook_secret'=>trim((string)($mode === 'live' ? $organizer->stripe_live_webhook_secret : $organizer->stripe_test_webhook_secret)),
        ];
    }

    public static function stripe_request(string $method, string $path, string $secret, array $body=[]): array|WP_Error {
        if ($secret === '') return new WP_Error('bcs_stripe_key', 'Brak klucza Stripe.');
        $args=['method'=>$method,'timeout'=>30,'headers'=>['Authorization'=>'Bearer '.$secret]];
        if ($body) { $args['headers']['Content-Type']='application/x-www-form-urlencoded'; $args['body']=$body; }
        $response=wp_remote_request('https://api.stripe.com/v1/'.ltrim($path,'/'),$args);
        if(is_wp_error($response)) return $response;
        $data=json_decode((string)wp_remote_retrieve_body($response),true);
        if(wp_remote_retrieve_response_code($response)>=300) return new WP_Error('bcs_stripe_api',(string)($data['error']['message']??'Błąd Stripe.'));
        return is_array($data)?$data:[];
    }

    public static function create_checkout(int $registration_id): array|WP_Error {
        global $wpdb;
        $r=$wpdb->get_row($wpdb->prepare("SELECT r.*,c.name camp_name,c.organizer_id,o.name organizer_name FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id WHERE r.id=%d",$registration_id));
        if(!$r || empty($r->form_verified_at)) return new WP_Error('bcs_payment_state','Formularz obozowy nie został zaakceptowany przez Organizatora.');
        $amount=max(0,(float)$r->total_amount-(float)$r->paid_amount);
        if($amount<=0) return new WP_Error('bcs_payment_paid','Zgłoszenie jest już opłacone.');
        $organizer=self::organizer((int)$r->organizer_id);
        if(!$organizer || !(int)$organizer->stripe_enabled) return new WP_Error('bcs_payment_org','Stripe nie jest aktywne dla organizatora.');
        $credentials=self::stripe_credentials($organizer);
        $now=BCS_Utils::now();
        $wpdb->insert(BCS_DB::table('payments'),['registration_id'=>$registration_id,'organizer_id'=>(int)$organizer->id,'provider'=>'stripe','amount'=>$amount,'currency'=>'PLN','status'=>'created','created_at'=>$now,'updated_at'=>$now]);
        $payment_id=(int)$wpdb->insert_id;
        if(!$payment_id) return new WP_Error('bcs_payment_db','Nie udało się utworzyć płatności.');
        $page=get_page_by_path('panel-rodzica');
        $portal=add_query_arg(['token'=>$r->public_token,'payment'=>'success'],$page?get_permalink($page):home_url('/panel-rodzica/'));
        $cancel=add_query_arg(['token'=>$r->public_token,'payment'=>'cancelled'],$page?get_permalink($page):home_url('/panel-rodzica/'));
        $session=self::stripe_request('POST','checkout/sessions',$credentials['secret_key'],[
            'mode'=>'payment','locale'=>'pl','success_url'=>$portal.'&session_id={CHECKOUT_SESSION_ID}','cancel_url'=>$cancel,
            'customer_email'=>$r->parent_email,'client_reference_id'=>(string)$payment_id,
            'line_items[0][price_data][currency]'=>'pln','line_items[0][price_data][product_data][name]'=>$r->camp_name.' – '.$r->child_first_name.' '.$r->child_last_name,
            'line_items[0][price_data][unit_amount]'=>(int)round($amount*100),'line_items[0][quantity]'=>1,
            'metadata[payment_id]'=>(string)$payment_id,'metadata[registration_id]'=>(string)$registration_id,'metadata[organizer_id]'=>(string)$organizer->id,
            'payment_intent_data[metadata][payment_id]'=>(string)$payment_id,'payment_intent_data[metadata][registration_id]'=>(string)$registration_id,
        ]);
        if(is_wp_error($session) || empty($session['url'])) { $wpdb->update(BCS_DB::table('payments'),['status'=>'failed','updated_at'=>BCS_Utils::now()],['id'=>$payment_id]); return is_wp_error($session)?$session:new WP_Error('bcs_stripe_url','Brak adresu płatności.'); }
        $wpdb->update(BCS_DB::table('payments'),['external_id'=>sanitize_text_field((string)($session['id']??'')),'checkout_url'=>esc_url_raw($session['url']),'status'=>'pending','updated_at'=>BCS_Utils::now()],['id'=>$payment_id]);
        $wpdb->update(BCS_DB::table('registrations'),['payment_id'=>$payment_id,'updated_at'=>BCS_Utils::now()],['id'=>$registration_id]);
        return ['payment_id'=>$payment_id,'url'=>esc_url_raw($session['url']),'session_id'=>(string)($session['id']??'')];
    }

    public static function routes(): void {
        register_rest_route('bcs/v1','/stripe-webhook/(?P<organizer_id>\d+)',['methods'=>'POST','callback'=>[__CLASS__,'webhook'],'permission_callback'=>'__return_true']);
    }

    private static function signature_valid(string $payload,string $header,string $secret): bool {
        if($secret==='' || $header==='') return false;
        $parts=[]; foreach(explode(',',$header) as $part){[$k,$v]=array_pad(explode('=',$part,2),2,'');$parts[$k][]=$v;}
        $timestamp=(int)($parts['t'][0]??0); if(!$timestamp || abs(time()-$timestamp)>300) return false;
        $expected=hash_hmac('sha256',$timestamp.'.'.$payload,$secret);
        foreach($parts['v1']??[] as $sig) if(hash_equals($expected,$sig)) return true;
        return false;
    }

    public static function webhook(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $organizer=self::organizer(absint($request['organizer_id']));
        if(!$organizer) return new WP_REST_Response(['error'=>'organizer_not_found'],404);
        $payload=$request->get_body(); $signature=(string)$request->get_header('stripe-signature');
        $credentials=self::stripe_credentials($organizer);
        if(!self::signature_valid($payload,$signature,$credentials['webhook_secret'])) return new WP_REST_Response(['error'=>'invalid_signature'],400);
        $event=json_decode($payload,true); $type=(string)($event['type']??'');
        if(in_array($type,['checkout.session.completed','checkout.session.async_payment_succeeded'],true)){
            $session=$event['data']['object']??[]; $payment_id=absint($session['metadata']['payment_id']??$session['client_reference_id']??0); $registration_id=absint($session['metadata']['registration_id']??0);
            $payment=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('payments')." WHERE id=%d",$payment_id));
            if($payment && (int)$payment->organizer_id===(int)$organizer->id && (int)$payment->registration_id===$registration_id && (($session['payment_status']??'')==='paid')){
                $session_id=sanitize_text_field((string)($session['id']??''));
                $currency=strtoupper(sanitize_text_field((string)($session['currency']??'')));
                $amount_total=(int)($session['amount_total']??-1);
                $expected_amount=(int)round((float)$payment->amount*100);
                if($session_id==='' || ($payment->external_id && !hash_equals((string)$payment->external_id,$session_id)) || $currency!=='PLN' || $amount_total!==$expected_amount){
                    BCS_Utils::log('stripe_payment_rejected',[
                        'payment_id'=>$payment_id,
                        'session_id'=>$session_id,
                        'currency'=>$currency,
                        'amount_total'=>$amount_total,
                        'expected_amount'=>$expected_amount,
                    ],$registration_id);
                    return new WP_REST_Response(['error'=>'payment_details_mismatch'],400);
                }
                $now=BCS_Utils::now();
                $claimed=$wpdb->query($wpdb->prepare(
                    "UPDATE ".BCS_DB::table('payments')." SET status='paid',paid_at=%s,external_id=%s,updated_at=%s WHERE id=%d AND status<>'paid'",
                    $now,$session_id,$now,$payment_id
                ));
                if($claimed===0) return new WP_REST_Response(['received'=>true,'duplicate'=>true],200);
                if($claimed===false) return new WP_REST_Response(['error'=>'payment_update_failed'],500);
                $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('registrations')." WHERE id=%d",$registration_id));
                if($r){$new=min((float)$r->total_amount,(float)$r->paid_amount+(float)$payment->amount);$paid=$new>=(float)$r->total_amount;$wpdb->update(BCS_DB::table('registrations'),['paid_amount'=>$new,'status'=>$paid?'paid':'partially_paid','updated_at'=>$now],['id'=>$registration_id]);if($paid && class_exists('BCS_Workflow'))BCS_Workflow_Engine::refresh_invoice_readiness($registration_id);BCS_Utils::log('stripe_payment_confirmed',['payment_id'=>$payment_id,'session_id'=>$session_id],$registration_id,(int)$r->agreement_id);if($paid)BCS_Communication_Engine::send_to_registration($registration_id,'paid','email','', '', false);}
            }
        }
        return new WP_REST_Response(['received'=>true],200);
    }
}
