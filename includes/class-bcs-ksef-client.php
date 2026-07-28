<?php
if (!defined('ABSPATH')) exit;

/** Minimalny klient HTTP fundamentu 0.72. Wysyłka faktur zostanie włączona w 0.73. */
final class BCS_KSeF_Client {
    private string $baseUrl;

    public function __construct(string $environment = 'test') {
        $this->baseUrl = rtrim(BCS_KSeF_Config::base_url($environment), '/');
    }

    /** @return array{success:bool,http_code:int,data:array,message:string} */
    public function challenge(): array {
        return $this->request('POST', '/auth/challenge');
    }

    /** @return array{success:bool,http_code:int,data:array,message:string} */
    private function request(string $method, string $path, ?array $body = null): array {
        $args = [
            'method' => $method,
            'timeout' => 20,
            'redirection' => 2,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Error-Format' => 'problem-details',
                'User-Agent' => 'Basketmania-Camp-System/'.(defined('BCS_VERSION') ? BCS_VERSION : 'dev'),
            ],
        ];
        if ($body !== null) $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = wp_remote_request($this->baseUrl.$path, $args);
        if (is_wp_error($response)) {
            return ['success'=>false, 'http_code'=>0, 'data'=>[], 'message'=>$response->get_error_message()];
        }
        $code = (int)wp_remote_retrieve_response_code($response);
        $raw = (string)wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = [];
        $success = $code >= 200 && $code < 300;
        $message = $success ? 'KSeF API TEST odpowiedziało prawidłowo.' : self::error_message($data, $code, $raw);
        return ['success'=>$success, 'http_code'=>$code, 'data'=>$data, 'message'=>$message];
    }

    private static function error_message(array $data, int $code, string $raw): string {
        foreach (['detail','message','title','exceptionDescription'] as $key) {
            if (!empty($data[$key]) && is_scalar($data[$key])) return 'HTTP '.$code.': '.(string)$data[$key];
        }
        $raw = trim(wp_strip_all_tags($raw));
        return 'HTTP '.$code.($raw !== '' ? ': '.mb_substr($raw, 0, 350) : ' – brak opisu błędu.');
    }
}
