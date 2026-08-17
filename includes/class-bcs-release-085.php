<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.85 – pełna obsługa OTP dla Chrome/WebOTP oraz utrzymanie Safari AutoFill.
 *
 * 0.79 poprawnie oznaczyło pola autocomplete="one-time-code", ale Chrome wymaga
 * dodatkowo wywołania WebOTP API oraz wiadomości SMS powiązanej z originem:
 *
 *   @example.com #123456
 *
 * Dotychczas BCS_SMS::send() spłaszczał nowe linie i usuwał domeny, więc taki SMS
 * nie mógł przejść. 0.85 przechwytuje wyłącznie dwa requesty OTP i korzysta z
 * istniejących transportów SMS bez osłabiania filtrów zwykłych wiadomości.
 */
final class BCS_Release_085 {
    private const PARENT_ACTION = 'bcs_send_otp';
    private const ORGANIZER_ACTION = 'bcs_046_organizer_otp_send';

    public static function init(): void {
        // Zachowujemy 0.79 dla Safari, ale wzmacniamy markup niezależnie od dokładnej
        // historycznej kolejności atrybutów inputa.
        add_filter('do_shortcode_tag', [__CLASS__, 'enhance_parent_otp_markup'], 40, 4);

        // Priorytet 100 pozwala wcześniejszym mockom testowym zwrócić wynik bez
        // faktycznej wysyłki. Jeżeli nikt nie obsłużył SMS-a, 0.85 robi to tutaj.
        add_filter('bcs_sms_send_result', [__CLASS__, 'origin_bound_otp_transport'], 100, 3);

        // WebOTP musi zacząć nasłuch PRZED wysłaniem SMS-a.
        add_action('wp_footer', [__CLASS__, 'frontend_webotp_script'], 999);
        add_action('admin_head', [__CLASS__, 'admin_webotp_script'], -10);
    }

    /**
     * @param mixed $output
     * @param mixed $tag
     * @param mixed $attr
     * @param mixed $m
     * @return mixed
     */
    public static function enhance_parent_otp_markup($output, $tag, $attr, $m) {
        if ($tag !== 'basketmania_portal' || !is_string($output) || strpos($output, 'id="bcs-code"') === false) {
            return $output;
        }
        $canonical = '<input id="bcs-code" name="bcs_otp_code" type="text" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" enterkeyhint="done">';
        return preg_replace('/<input\b[^>]*\bid=["\']bcs-code["\'][^>]*>/i', $canonical, $output, 1) ?: $output;
    }

    private static function active_otp_action(): string {
        $action = sanitize_key(wp_unslash($_POST['action'] ?? ''));
        return in_array($action, [self::PARENT_ACTION, self::ORGANIZER_ACTION], true) ? $action : '';
    }

    private static function request_host(): string {
        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        if (str_contains($host, ':')) $host = explode(':', $host, 2)[0];
        if ($host === '' || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
            $host = strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
        }
        return trim($host, '.');
    }

    private static function otp_code_from_message(string $message): string {
        preg_match_all('/(?<!\d)(\d{6})(?!\d)/', $message, $matches);
        if (empty($matches[1])) return '';
        return (string)end($matches[1]);
    }

    /** Używane również przez test 0.85. */
    public static function build_origin_bound_message(string $host, string $code, string $actor = 'parent'): string {
        $host = strtolower(trim($host));
        $code = preg_replace('/\D+/', '', $code) ?: '';
        if (!preg_match('/^[a-z0-9.-]+$/i', $host) || strlen($code) !== 6) return '';
        $human = $actor === 'organizer'
            ? 'Basketmania Camp: kod OTP Organizatora: '.$code.'.'
            : 'Basketmania Camp: kod OTP do podpisu umowy: '.$code.'.';
        return $human."\n\n@".$host.' #'.$code;
    }

    /**
     * Przechwytujemy tylko SMS-y OTP. Zwykłe wiadomości nadal przechodzą przez
     * standardowe strip_links()/normalizację BCS_SMS::send().
     *
     * @param mixed $pre
     * @return mixed
     */
    public static function origin_bound_otp_transport($pre, string $phone, string $message) {
        if (is_array($pre)) return $pre;
        $action = self::active_otp_action();
        if ($action === '') return $pre;

        $code = self::otp_code_from_message($message);
        $host = self::request_host();
        $actor = $action === self::ORGANIZER_ACTION ? 'organizer' : 'parent';
        $originBound = self::build_origin_bound_message($host, $code, $actor);
        if ($originBound === '') {
            return ['success'=>false,'error'=>'Nie udało się przygotować wiadomości OTP powiązanej z domeną.','provider'=>BCS_SMS::provider_label()];
        }
        return self::send_with_existing_provider($phone, $originBound);
    }

    /**
     * Reużywa istniejących prywatnych transportów BCS_SMS, aby nie dublować
     * konfiguracji SMSAPI / JustSend / SMSPLANET i nie zmieniać filtracji zwykłych SMS.
     */
    private static function send_with_existing_provider(string $phone, string $message): array {
        $settings = get_option('bcs_settings', []);
        $provider = in_array(($settings['sms_provider'] ?? 'smsapi'), ['smsapi','justsend','smsplanet'], true)
            ? (string)$settings['sms_provider'] : 'smsapi';
        $phone = BCS_Utils::normalize_phone($phone);
        if ($phone === '') return ['success'=>false,'error'=>'Brak poprawnego numeru telefonu.','provider'=>$provider];

        $methodName = match ($provider) {
            'justsend' => 'send_justsend',
            'smsplanet' => 'send_smsplanet',
            default => 'send_smsapi',
        };

        try {
            $method = new ReflectionMethod(BCS_SMS::class, $methodName);
            if (method_exists($method, 'setAccessible')) $method->setAccessible(true);
            $result = $method->invoke(null, $settings, $phone, $message);
            if (!is_array($result)) $result = ['success'=>false,'error'=>'Operator SMS zwrócił nieprawidłową odpowiedź.'];
        } catch (Throwable $exception) {
            $result = ['success'=>false,'error'=>'Nie udało się uruchomić transportu OTP: '.$exception->getMessage()];
        }

        $result['provider'] = $provider;
        $result['provider_label'] = BCS_SMS::provider_label($provider);

        try {
            $counter = new ReflectionMethod(BCS_SMS::class, 'update_counters');
            if (method_exists($counter, 'setAccessible')) $counter->setAccessible(true);
            $counter->invoke(null, $provider, !empty($result['success']), $message);
        } catch (Throwable $ignored) {}

        update_option('bcs_last_sms_result', [
            'success'=>!empty($result['success']),
            'provider'=>$provider,
            'provider_label'=>BCS_SMS::provider_label($provider),
            'message_id'=>(string)($result['message_id'] ?? ''),
            'error'=>(string)($result['error'] ?? ''),
            'error_code'=>(int)($result['error_code'] ?? 0),
            'http_code'=>(int)($result['http_code'] ?? 0),
            'phone'=>$phone,
            'message_length'=>function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message),
            'time'=>BCS_Utils::now(),
            'origin_bound_otp'=>1,
        ], false);

        if (empty($result['success'])) {
            $error = (string)($result['error'] ?? 'Nieznany błąd wysyłki OTP.');
            if ((int)($result['error_code'] ?? 0) === 94) {
                $error .= ' Konto SMSAPI musi zezwalać na wiadomości zawierające domenę, ponieważ Chrome WebOTP wymaga linii @domena #kod.';
                $result['error'] = $error;
            }
            BCS_Utils::log('communication_otp_origin_bound_error', [
                'provider'=>BCS_SMS::provider_label($provider),
                'phone'=>BCS_Utils::mask_phone($phone),
                'host'=>self::request_host(),
                'error'=>$error,
                'error_code'=>(int)($result['error_code'] ?? 0),
            ]);
        } else {
            BCS_Utils::log('communication_otp_origin_bound_sent', [
                'provider'=>BCS_SMS::provider_label($provider),
                'phone'=>BCS_Utils::mask_phone($phone),
                'host'=>self::request_host(),
            ]);
        }
        return $result;
    }

    private static function webotp_common_js(): string {
        return <<<'JS'
        const bcsWebOtp085=(()=>{
            let controller=null;
            const supported=()=>window.isSecureContext && 'OTPCredential' in window && navigator.credentials && typeof navigator.credentials.get==='function';
            const abort=()=>{if(controller){try{controller.abort();}catch(e){}controller=null;}};
            const visible=(node)=>node && !node.hidden && node.getClientRects().length>0;
            const waitVisible=(inputSelector,modalSelector,timeout=8000)=>new Promise(resolve=>{
                const start=Date.now();
                const tick=()=>{
                    const input=document.querySelector(inputSelector),modal=modalSelector?document.querySelector(modalSelector):null;
                    if(input && (!modalSelector || visible(modal))){resolve(input);return;}
                    if(Date.now()-start>=timeout){resolve(input||null);return;}
                    window.setTimeout(tick,80);
                };tick();
            });
            const start=async(options)=>{
                if(!supported())return false;
                abort();controller=new AbortController();
                const local=controller;
                window.setTimeout(()=>{if(controller===local)abort();},125000);
                try{
                    const credential=await navigator.credentials.get({otp:{transport:['sms']},signal:local.signal});
                    const value=String(credential&&credential.code||'').replace(/\D/g,'').slice(0,6);
                    if(value.length!==6)return false;
                    const input=await waitVisible(options.input,options.modal,8000);
                    if(!input)return false;
                    input.value=value;
                    input.dispatchEvent(new Event('input',{bubbles:true}));
                    input.dispatchEvent(new Event('change',{bubbles:true}));
                    window.setTimeout(()=>{
                        if(options.form){const form=document.querySelector(options.form);if(form){if(typeof form.requestSubmit==='function')form.requestSubmit();else form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));}}
                        else if(options.verify){document.querySelector(options.verify)?.click();}
                    },120);
                    return true;
                }catch(error){
                    if(error&&error.name!=='AbortError')console.debug('Basketmania WebOTP:',error);
                    return false;
                }finally{if(controller===local)controller=null;}
            };
            return {supported,start,abort};
        })();
JS;
    }

    public static function frontend_webotp_script(): void {
        if (is_admin()) return;
        ?>
        <script>
        (()=>{
            <?php echo self::webotp_common_js(); ?>
            const ensureField=()=>{
                const input=document.querySelector('#bcs-code');if(!input)return;
                input.setAttribute('name','bcs_otp_code');input.setAttribute('type','text');input.setAttribute('maxlength','6');input.setAttribute('inputmode','numeric');input.setAttribute('pattern','[0-9]{6}');input.setAttribute('autocomplete','one-time-code');
            };
            const addNote=()=>{
                const modal=document.querySelector('#bcs-otp-modal .bcs-modal-dialog');
                if(!modal||modal.querySelector('.bcs-webotp-note-085'))return;
                const note=document.createElement('small');note.className='bcs-webotp-note-085';
                note.textContent=bcsWebOtp085.supported()?'Chrome może pobrać kod z SMS automatycznie po Twoim potwierdzeniu.':'Automatyczne pobranie kodu zależy od przeglądarki i urządzenia; kod można zawsze wpisać ręcznie.';
                const input=modal.querySelector('#bcs-code');input?.closest('label')?.insertAdjacentElement('afterend',note);
            };
            ensureField();addNote();
            document.addEventListener('click',event=>{
                if(!event.target.closest('#bcs-send-code'))return;
                ensureField();
                bcsWebOtp085.start({input:'#bcs-code',modal:'#bcs-otp-modal',verify:'#bcs-verify-code'});
            },true);
        })();
        </script>
        <?php
    }

    public static function admin_webotp_script(): void {
        if (!current_user_can('manage_options')) return;
        if (sanitize_key((string)($_GET['page'] ?? '')) !== 'bcs-registrations') return;
        ?>
        <script>
        (()=>{
            <?php echo self::webotp_common_js(); ?>
            const prepare=()=>{
                const input=document.querySelector('#bcs-org-otp-code-079');
                if(input){input.setAttribute('name','bcs_organizer_otp_code');input.setAttribute('type','text');input.setAttribute('maxlength','6');input.setAttribute('inputmode','numeric');input.setAttribute('pattern','[0-9]{6}');input.setAttribute('autocomplete','one-time-code');}
                const note=document.querySelector('.bcs-otp079-note');
                if(note&&!note.dataset.bcs085){
                    note.dataset.bcs085='1';
                    note.textContent='Safari na Macu może podpowiedzieć kod z iPhone’a. Chrome WebOTP działa na Androidzie oraz na komputerze z kodem przekazanym z Androida przez Chrome Sync; Chrome nie odbiera WebOTP z iPhone’a.';
                }
            };
            document.addEventListener('click',event=>{
                if(!event.target.closest('.bcs-org-sign-046'))return;
                bcsWebOtp085.start({input:'#bcs-org-otp-code-079',modal:'#bcs-org-otp-modal-079',form:'#bcs-org-otp-form-079'});
                window.setTimeout(prepare,100);window.setTimeout(prepare,500);
            },true);
            new MutationObserver(prepare).observe(document.documentElement,{childList:true,subtree:true});
        })();
        </script>
        <?php
    }
}
