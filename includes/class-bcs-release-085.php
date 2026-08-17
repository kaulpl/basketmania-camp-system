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

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_frontend_webotp']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_webotp']);
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

    public static function enqueue_frontend_webotp(): void {
        if (is_admin()) return;
        wp_enqueue_script('bcs-webotp-085', BCS_URL.'assets/js/webotp-085.js', [], BCS_VERSION, true);
    }

    public static function enqueue_admin_webotp(string $hook = ''): void {
        if (!current_user_can('manage_options')) return;
        if (sanitize_key((string)($_GET['page'] ?? '')) !== 'bcs-registrations') return;

        // W panelu ładujemy w HEAD. Dzięki temu listener capture z 0.85 zostaje
        // zarejestrowany przed admin_head 0.79, który uruchamia właściwą wysyłkę OTP.
        wp_enqueue_script('bcs-webotp-085', BCS_URL.'assets/js/webotp-085.js', [], BCS_VERSION, false);
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
}
