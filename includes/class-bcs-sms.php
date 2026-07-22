<?php
if (!defined('ABSPATH')) exit;

class BCS_SMS {
    public static function to_ascii(string $text): string {
        return strtr($text, [
            'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z',
            'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ż'=>'Z','Ź'=>'Z',
        ]);
    }

    public static function strip_links(string $text): string {
        $text = preg_replace('~(?:https?://|www\.)[^\s<]+~iu', '', $text);
        $text = preg_replace('~\b[a-z0-9][a-z0-9.-]*\.(?:pl|com|eu|net|org|io)(?:/[^\s<]*)?~iu', '', (string)$text);
        return trim((string)preg_replace('/\s+/u', ' ', (string)$text));
    }

    public static function provider_label(?string $provider = null): string {
        if ($provider === null) {
            $settings = get_option('bcs_settings', []);
            $provider = (string)($settings['sms_provider'] ?? 'smsapi');
        }
        if ($provider === 'justsend') return 'JustSend';
        if ($provider === 'smsplanet') return 'SMSPLANET.PL';
        return 'SMSAPI';
    }

    public static function send(string $phone, string $message): array {
        $mock = apply_filters('bcs_sms_send_result', null, $phone, $message);
        if (is_array($mock)) return $mock;

        $settings = get_option('bcs_settings', []);
        $provider = in_array(($settings['sms_provider'] ?? 'smsapi'), ['smsapi','justsend','smsplanet'], true)
            ? (string)$settings['sms_provider'] : 'smsapi';

        $phone = BCS_Utils::normalize_phone($phone);
        $message = self::strip_links(self::to_ascii(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(html_entity_decode($message, ENT_QUOTES, 'UTF-8'))))));
        if ($phone === '') {
            $error='Brak poprawnego numeru telefonu.';
            BCS_Utils::log('communication_sms_error',['provider'=>self::provider_label($provider),'phone'=>$phone,'message'=>$message,'error'=>$error]);
            return ['success'=>false,'error'=>$error,'provider'=>$provider];
        }
        if ($message === '') {
            $error='Treść SMS jest pusta.';
            BCS_Utils::log('communication_sms_error',['provider'=>self::provider_label($provider),'phone'=>$phone,'message'=>$message,'error'=>$error]);
            return ['success'=>false,'error'=>$error,'provider'=>$provider];
        }
        if (function_exists('mb_substr')) $message = mb_substr($message, 0, 900, 'UTF-8');
        else $message = substr($message, 0, 900);

        if ($provider === 'justsend') {
            $result = self::send_justsend($settings, $phone, $message);
        } elseif ($provider === 'smsplanet') {
            $result = self::send_smsplanet($settings, $phone, $message);
        } else {
            $result = self::send_smsapi($settings, $phone, $message);
        }
        $result['provider'] = $provider;
        $result['provider_label'] = self::provider_label($provider);

        self::update_counters($provider, !empty($result['success']), $message);
        update_option('bcs_last_sms_result', [
            'success'=>!empty($result['success']),
            'provider'=>$provider,
            'provider_label'=>self::provider_label($provider),
            'message_id'=>(string)($result['message_id'] ?? ''),
            'error'=>(string)($result['error'] ?? ''),
            'error_code'=>(int)($result['error_code'] ?? 0),
            'http_code'=>(int)($result['http_code'] ?? 0),
            'phone'=>$phone,
            'message_length'=>function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message),
            'time'=>BCS_Utils::now(),
        ], false);
        if (empty($result['success'])) {
            BCS_Utils::log('communication_sms_error', [
                'provider'=>self::provider_label($provider),
                'phone'=>$phone,
                'message'=>$message,
                'error'=>(string)($result['error'] ?? 'Nieznany błąd wysyłki SMS.'),
                'error_code'=>(int)($result['error_code'] ?? 0),
                'http_code'=>(int)($result['http_code'] ?? 0),
                'response'=>$result['raw'] ?? null,
            ]);
        }
        return $result;
    }

    private static function update_counters(string $provider, bool $success, string $message): void {
        $all = get_option('bcs_sms_counters', []);
        if (!is_array($all)) $all = [];
        $today = BCS_Utils::today();
        $month = substr($today, 0, 7);
        $length = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
        $parts = $length <= 160 ? 1 : (int)ceil($length / 153);
        $row = isset($all[$provider]) && is_array($all[$provider]) ? $all[$provider] : [];
        $row['attempted'] = (int)($row['attempted'] ?? 0) + 1;
        $row['failed'] = (int)($row['failed'] ?? 0) + ($success ? 0 : 1);
        $row['sent'] = (int)($row['sent'] ?? 0) + ($success ? 1 : 0);
        $row['segments'] = (int)($row['segments'] ?? 0) + ($success ? $parts : 0);
        if (($row['today_date'] ?? '') !== $today) {
            $row['today_date'] = $today; $row['today_sent'] = 0; $row['today_segments'] = 0;
        }
        if (($row['month_key'] ?? '') !== $month) {
            $row['month_key'] = $month; $row['month_sent'] = 0; $row['month_segments'] = 0;
        }
        if ($success) {
            $row['today_sent'] = (int)($row['today_sent'] ?? 0) + 1;
            $row['today_segments'] = (int)($row['today_segments'] ?? 0) + $parts;
            $row['month_sent'] = (int)($row['month_sent'] ?? 0) + 1;
            $row['month_segments'] = (int)($row['month_segments'] ?? 0) + $parts;
        }
        $row['last_attempt_at'] = BCS_Utils::now();
        if ($success) $row['last_success_at'] = BCS_Utils::now();
        $all[$provider] = $row;
        update_option('bcs_sms_counters', $all, false);
    }

    public static function dashboard_stats(): array {
        global $wpdb;
        $settings = get_option('bcs_settings', []);
        $provider = in_array(($settings['sms_provider'] ?? 'smsapi'), ['smsapi','justsend','smsplanet'], true) ? (string)$settings['sms_provider'] : 'smsapi';
        if ($provider === 'smsapi') {
            $configured = trim((string)($settings['smsapi_token'] ?? '')) !== '';
        } elseif ($provider === 'justsend') {
            $configured = trim((string)($settings['justsend_app_key'] ?? '')) !== '';
        } else {
            $configured = trim((string)($settings['smsplanet_token'] ?? '')) !== '';
        }
        $all = get_option('bcs_sms_counters', []);
        $counter = is_array($all) && isset($all[$provider]) && is_array($all[$provider]) ? $all[$provider] : [];
        $today = BCS_Utils::today(); $month = substr($today,0,7);
        if (($counter['today_date'] ?? '') !== $today) { $counter['today_sent']=0; $counter['today_segments']=0; }
        if (($counter['month_key'] ?? '') !== $month) { $counter['month_sent']=0; $counter['month_segments']=0; }
        $local_total = (int)$wpdb->get_var("SELECT COUNT(*) FROM ".BCS_DB::table('messages')." WHERE sms_status='sent'");
        $last = get_option('bcs_last_sms_result', []);
        $result = [
            'provider'=>$provider,
            'provider_label'=>self::provider_label($provider),
            'configured'=>$configured,
            'connection_status'=>$configured ? 'unknown' : 'not_configured',
            'balance'=>null,
            'balance_unit'=>'',
            'remaining_estimate'=>null,
            'sent_total'=>(int)($counter['sent'] ?? 0),
            'segments_total'=>(int)($counter['segments'] ?? 0),
            'sent_today'=>(int)($counter['today_sent'] ?? 0),
            'sent_month'=>(int)($counter['month_sent'] ?? 0),
            'failed_total'=>(int)($counter['failed'] ?? 0),
            'local_history_total'=>$local_total,
            'last_at'=>(string)($counter['last_attempt_at'] ?? ($last['time'] ?? '')),
            'note'=>'Statystyki operatora są liczone przez Basketmania Camp od wersji 0.10.37.',
        ];
        if (!$configured) return $result;
        if ($provider === 'smsapi') {
            $profile = self::smsapi_profile($settings);
            if (!empty($profile['success'])) {
                $result['connection_status']='connected';
                $result['balance']=(float)$profile['points'];
                $result['balance_unit']='pkt';
                $cost=(float)($settings['smsapi_sms_cost'] ?? 0);
                if ($cost > 0) $result['remaining_estimate']=(int)floor($result['balance']/$cost);
                $result['account_name']=(string)($profile['name'] ?? '');
                $result['payment_type']=(string)($profile['payment_type'] ?? '');
            } else {
                $result['connection_status']='error';
                $result['api_error']=(string)($profile['error'] ?? 'Nie udało się pobrać profilu SMSAPI.');
            }
        } elseif ($provider === 'justsend') {
            if (is_array($last) && ($last['provider'] ?? '') === 'justsend') {
                $result['connection_status']=!empty($last['success']) ? 'connected' : 'error';
                if (empty($last['success'])) $result['api_error']=(string)($last['error'] ?? 'Ostatnia próba wysyłki zakończyła się błędem.');
            }
            $result['note']='JustSend nie opisuje publicznie endpointu salda. Pokazywane są statystyki wysyłek rejestrowane przez Basketmania Camp.';
        } else {
            $balance = self::smsplanet_balance($settings);
            if (!empty($balance['success'])) {
                $result['connection_status']='connected';
                $result['balance']=(float)$balance['balance'];
                $result['balance_unit']='pkt';
                $cost=(float)($settings['smsplanet_sms_cost'] ?? 0);
                if ($cost > 0) $result['remaining_estimate']=(int)floor($result['balance']/$cost);
                $result['note']='Saldo SMSPLANET.PL jest dostępne dla kont PrePaid. Statystyki wysyłek pochodzą z Basketmania Camp.';
            } else {
                $result['connection_status']='error';
                $result['api_error']=(string)($balance['error'] ?? 'Nie udało się pobrać salda SMSPLANET.PL.');
                $result['note']='Dla kont PostPaid endpoint salda może być niedostępny; lokalne statystyki wysyłek nadal są prezentowane.';
            }
        }
        return $result;
    }

    private static function smsapi_profile(array $settings): array {
        $cached = get_transient('bcs_smsapi_profile');
        if (is_array($cached)) return $cached;
        $token=trim((string)($settings['smsapi_token'] ?? ''));
        if ($token==='') return ['success'=>false,'error'=>'Brak tokenu SMSAPI.'];
        $response=wp_remote_get('https://api.smsapi.pl/profile', [
            'timeout'=>15,
            'headers'=>['Authorization'=>'Bearer '.$token,'Accept'=>'application/json'],
        ]);
        if (is_wp_error($response)) return ['success'=>false,'error'=>$response->get_error_message()];
        $code=wp_remote_retrieve_response_code($response);
        $data=json_decode(wp_remote_retrieve_body($response), true);
        if ($code>=200 && $code<300 && is_array($data) && isset($data['points'])) {
            $out=['success'=>true,'points'=>(float)$data['points'],'name'=>(string)($data['name'] ?? ''),'payment_type'=>(string)($data['payment_type'] ?? '')];
            set_transient('bcs_smsapi_profile',$out,5*MINUTE_IN_SECONDS);
            return $out;
        }
        $error=is_array($data)?(string)($data['message'] ?? $data['error'] ?? ''):'';
        return ['success'=>false,'error'=>$error!==''?$error:'Błąd SMSAPI HTTP '.$code];
    }

    private static function send_smsapi(array $settings, string $phone, string $message): array {
        $token = trim((string)($settings['smsapi_token'] ?? ''));
        $sender = trim(sanitize_text_field((string)($settings['sms_sender'] ?? '')));
        if ($token === '') return ['success'=>false,'error'=>'Brak tokenu SMSAPI w ustawieniach.'];

        $body = [
            'to'=>$phone,
            'message'=>$message,
            'format'=>'json',
            'encoding'=>'utf-8',
            'max_parts'=>6,
        ];
        if ($sender !== '') $body['from'] = $sender;
        $response = wp_remote_post('https://api.smsapi.pl/sms.do', [
            'timeout'=>20,
            'headers'=>['Authorization'=>'Bearer '.$token],
            'body'=>$body,
        ]);
        if (is_wp_error($response)) return ['success'=>false,'error'=>$response->get_error_message(),'error_code'=>0];
        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        $data = is_array($decoded) ? $decoded : [];
        if ($code >= 200 && $code < 300 && empty($data['error'])) {
            return ['success'=>true,'message_id'=>(string)($data['list'][0]['id'] ?? ''),'raw'=>$data,'error_code'=>0,'http_code'=>$code];
        }
        $error_code=(int)($data['error'] ?? 0);
        $error=(string)($data['message'] ?? ('Błąd SMSAPI HTTP '.$code));
        if ($error_code===14) $error .= ' — zapisane pole nadawcy nie jest aktywne na koncie SMSAPI.';
        if ($error_code===94) $error .= ' — konto SMSAPI nie ma uprawnienia do wysyłania wiadomości zawierających linki.';
        return ['success'=>false,'error'=>$error,'error_code'=>$error_code,'raw'=>$data,'http_code'=>$code];
    }

    private static function send_justsend(array $settings, string $phone, string $message): array {
        $app_key = trim((string)($settings['justsend_app_key'] ?? ''));
        $variant = strtoupper((string)($settings['justsend_variant'] ?? 'ECO'));
        if (!in_array($variant, ['ECO','FULL','PRO'], true)) $variant = 'ECO';
        $sender = self::to_ascii(trim(sanitize_text_field((string)($settings['justsend_sender'] ?? ''))));
        if ($app_key === '') return ['success'=>false,'error'=>'Brak klucza App-Key JustSend w ustawieniach.'];
        if (in_array($variant, ['FULL','PRO'], true) && $sender === '') {
            return ['success'=>false,'error'=>'Dla wariantu JustSend '.$variant.' podaj aktywny nadpis nadawcy.'];
        }

        $payload = [
            'msisdn'=>$phone,
            'bulkVariant'=>$variant,
            'content'=>$message,
        ];
        if ($sender !== '') $payload['sender'] = $sender;

        $response = wp_remote_post('https://justsend.io/api/sender/singlemessage/send', [
            'timeout'=>20,
            'headers'=>[
                'accept'=>'application/json',
                'App-Key'=>$app_key,
                'Content-Type'=>'application/json',
            ],
            'body'=>wp_json_encode($payload),
            'data_format'=>'body',
        ]);
        if (is_wp_error($response)) return ['success'=>false,'error'=>$response->get_error_message(),'error_code'=>0];
        $code = wp_remote_retrieve_response_code($response);
        $raw = trim((string)wp_remote_retrieve_body($response));
        $decoded = json_decode($raw, true);
        $data = is_array($decoded) ? $decoded : [];
        if ($code >= 200 && $code < 300) {
            $message_id = '';
            if (is_array($data)) $message_id = (string)($data['id'] ?? $data['messageId'] ?? $data['uuid'] ?? '');
            if ($message_id === '' && $raw !== '' && !is_array($decoded)) $message_id = trim($raw, "\" \t\n\r\0\x0B");
            return ['success'=>true,'message_id'=>$message_id,'raw'=>$decoded ?? $raw,'error_code'=>0,'http_code'=>$code];
        }
        $error = '';
        if (is_array($data)) $error = (string)($data['message'] ?? $data['error'] ?? $data['detail'] ?? '');
        if ($error === '') $error = $raw !== '' ? $raw : ('Błąd JustSend HTTP '.$code);
        if ($code === 401) $error .= ' — sprawdź poprawność klucza App-Key.';
        if ($code === 403) $error .= ' — konto lub klucz nie ma uprawnień do tej wysyłki.';
        return ['success'=>false,'error'=>$error,'error_code'=>$code,'raw'=>$decoded ?? $raw,'http_code'=>$code];
    }
    private static function smsplanet_balance(array $settings): array {
        $cached = get_transient('bcs_smsplanet_balance');
        if (is_array($cached)) return $cached;
        $token = trim((string)($settings['smsplanet_token'] ?? ''));
        if ($token === '') return ['success'=>false,'error'=>'Brak tokenu API SMSPLANET.PL.'];
        $response = wp_remote_post('https://api2.smsplanet.pl/getBalance', [
            'timeout'=>15,
            'headers'=>['Authorization'=>'Bearer '.$token,'Accept'=>'application/json'],
            'body'=>[],
        ]);
        if (is_wp_error($response)) return ['success'=>false,'error'=>$response->get_error_message()];
        $code = wp_remote_retrieve_response_code($response);
        $raw = trim((string)wp_remote_retrieve_body($response));
        $data = json_decode($raw, true);
        if ($code >= 200 && $code < 300 && is_array($data) && isset($data['balance'])) {
            $out=['success'=>true,'balance'=>(float)$data['balance']];
            set_transient('bcs_smsplanet_balance',$out,5*MINUTE_IN_SECONDS);
            return $out;
        }
        $error=is_array($data)?(string)($data['errorMsg'] ?? $data['message'] ?? ''):'';
        return ['success'=>false,'error'=>$error!==''?$error:('Błąd SMSPLANET.PL HTTP '.$code)];
    }

    private static function send_smsplanet(array $settings, string $phone, string $message): array {
        $token = trim((string)($settings['smsplanet_token'] ?? ''));
        $sender = self::to_ascii(trim(sanitize_text_field((string)($settings['smsplanet_sender'] ?? ''))));
        if ($token === '') return ['success'=>false,'error'=>'Brak tokenu API SMSPLANET.PL w ustawieniach.'];
        if ($sender === '') return ['success'=>false,'error'=>'Podaj aktywne pole nadawcy SMSPLANET.PL.'];
        $body = [
            'from'=>$sender,
            'to'=>$phone,
            'msg'=>$message,
            'clear_polish'=>1,
        ];
        if (!empty($settings['smsplanet_transactional'])) $body['transactional']=1;
        $response = wp_remote_post('https://api2.smsplanet.pl/sms', [
            'timeout'=>20,
            'headers'=>[
                'Authorization'=>'Bearer '.$token,
                'Accept'=>'application/json',
                'Content-Type'=>'application/x-www-form-urlencoded',
            ],
            'body'=>$body,
        ]);
        if (is_wp_error($response)) return ['success'=>false,'error'=>$response->get_error_message(),'error_code'=>0];
        $code = wp_remote_retrieve_response_code($response);
        $raw = trim((string)wp_remote_retrieve_body($response));
        $data = json_decode($raw, true);
        if ($code >= 200 && $code < 300 && is_array($data) && !empty($data['messageId'])) {
            return ['success'=>true,'message_id'=>(string)$data['messageId'],'raw'=>$data,'error_code'=>0,'http_code'=>$code];
        }
        $error_code=is_array($data)?(int)($data['errorCode'] ?? 0):0;
        $error=is_array($data)?(string)($data['errorMsg'] ?? $data['message'] ?? ''):'';
        if ($error === '') $error = $raw !== '' ? $raw : ('Błąd SMSPLANET.PL HTTP '.$code);
        $hints=[
            101=>'Sprawdź token lub klucz API.',102=>'Sprawdź hasło API.',103=>'Pole nadawcy nie jest poprawne lub aktywne.',
            104=>'Wiadomość przekracza limit 6 części SMS.',105=>'Wykorzystano limit wysyłek.',109=>'Brak wystarczających środków na koncie.',
            110=>'Adres IP serwera nie znajduje się na liście dozwolonych.',201=>'Token API jest nieprawidłowy.',202=>'Token API jest nieaktywny.',203=>'Token API wygasł.',
        ];
        if (isset($hints[$error_code])) $error .= ' — '.$hints[$error_code];
        return ['success'=>false,'error'=>$error,'error_code'=>$error_code,'raw'=>$data ?? $raw,'http_code'=>$code];
    }

}
