<?php
if (!defined('ABSPATH')) exit;

/** Klient HTTP KSeF API 2.0. Wersja 0.75 działa wyłącznie w środowisku TEST. */
final class BCS_KSeF_Client {
    private string $baseUrl;

    public function __construct(string $environment = 'test') {
        $this->baseUrl = rtrim(BCS_KSeF_Config::base_url($environment), '/');
    }

    public function challenge(): array {
        return $this->request('POST', '/auth/challenge');
    }

    public function public_key_certificates(): array {
        return $this->request('GET', '/security/public-key-certificates');
    }

    public function init_token_auth(array $body): array {
        return $this->request('POST', '/auth/ksef-token', $body);
    }

    public function auth_status(string $referenceNumber, string $authenticationToken): array {
        return $this->request('GET', '/auth/'.rawurlencode($referenceNumber), null, $authenticationToken);
    }

    public function redeem_token(string $authenticationToken): array {
        return $this->request('POST', '/auth/token/redeem', null, $authenticationToken);
    }

    public function open_online_session(array $body, string $accessToken): array {
        return $this->request('POST', '/sessions/online', $body, $accessToken);
    }

    public function send_online_invoice(string $sessionReference, array $body, string $accessToken): array {
        return $this->request('POST', '/sessions/online/'.rawurlencode($sessionReference).'/invoices', $body, $accessToken);
    }

    public function close_online_session(string $sessionReference, string $accessToken): array {
        return $this->request('POST', '/sessions/online/'.rawurlencode($sessionReference).'/close', null, $accessToken);
    }

    public function session_invoice_status(string $sessionReference, string $invoiceReference, string $accessToken): array {
        return $this->request('GET', '/sessions/'.rawurlencode($sessionReference).'/invoices/'.rawurlencode($invoiceReference), null, $accessToken);
    }

    public function invoice_xml(string $ksefNumber, string $accessToken): array {
        return $this->request('GET', '/invoices/ksef/'.rawurlencode($ksefNumber), null, $accessToken, true, 'application/xml');
    }

    public function download_url(string $url): array {
        $response = wp_remote_get($url, [
            'timeout'=>30,
            'redirection'=>3,
            'headers'=>['Accept'=>'application/xml, application/octet-stream, */*'],
            'user-agent'=>'Basketmania-Camp-System/'.(defined('BCS_VERSION') ? BCS_VERSION : 'dev'),
        ]);
        if (is_wp_error($response)) return ['success'=>false,'http_code'=>0,'data'=>[],'raw'=>'','headers'=>[],'message'=>$response->get_error_message()];
        $code = (int)wp_remote_retrieve_response_code($response);
        $raw = (string)wp_remote_retrieve_body($response);
        return ['success'=>$code >= 200 && $code < 300,'http_code'=>$code,'data'=>[],'raw'=>$raw,'headers'=>self::headers($response),'message'=>$code >= 200 && $code < 300 ? 'Pobrano dokument z KSeF.' : 'HTTP '.$code.' podczas pobierania dokumentu KSeF.'];
    }

    /** @return array{success:bool,http_code:int,data:array,raw:string,headers:array,message:string} */
    private function request(string $method, string $path, ?array $body = null, string $bearer = '', bool $rawResponse = false, string $accept = 'application/json'): array {
        $headers = [
            'Accept'=>$accept,
            'X-Error-Format'=>'problem-details',
            'User-Agent'=>'Basketmania-Camp-System/'.(defined('BCS_VERSION') ? BCS_VERSION : 'dev'),
        ];
        if ($body !== null) $headers['Content-Type'] = 'application/json';
        if ($bearer !== '') $headers['Authorization'] = 'Bearer '.$bearer;
        $args = [
            'method'=>$method,
            'timeout'=>35,
            'redirection'=>2,
            'headers'=>$headers,
        ];
        if ($body !== null) $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = wp_remote_request($this->baseUrl.$path, $args);
        if (is_wp_error($response)) {
            return ['success'=>false,'http_code'=>0,'data'=>[],'raw'=>'','headers'=>[],'message'=>$response->get_error_message()];
        }
        $code = (int)wp_remote_retrieve_response_code($response);
        $raw = (string)wp_remote_retrieve_body($response);
        $data = $rawResponse ? [] : json_decode($raw, true);
        if (!is_array($data)) $data = [];
        $success = $code >= 200 && $code < 300;
        return [
            'success'=>$success,
            'http_code'=>$code,
            'data'=>$data,
            'raw'=>$raw,
            'headers'=>self::headers($response),
            'message'=>$success ? 'KSeF API TEST odpowiedziało prawidłowo.' : self::error_message($data, $code, $raw),
        ];
    }

    private static function headers($response): array {
        $headers = wp_remote_retrieve_headers($response);
        if (is_object($headers) && method_exists($headers, 'getAll')) return (array)$headers->getAll();
        return is_array($headers) ? $headers : [];
    }

    private static function error_message(array $data, int $code, string $raw): string {
        foreach (['detail','message','title','exceptionDescription'] as $key) {
            if (!empty($data[$key]) && is_scalar($data[$key])) return 'HTTP '.$code.': '.(string)$data[$key];
        }
        if (!empty($data['exception']['description'])) return 'HTTP '.$code.': '.(string)$data['exception']['description'];
        $raw = trim(wp_strip_all_tags($raw));
        return 'HTTP '.$code.($raw !== '' ? ': '.mb_substr($raw, 0, 500) : ' – brak opisu błędu.');
    }
}
