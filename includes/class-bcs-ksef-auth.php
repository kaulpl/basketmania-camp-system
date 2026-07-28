<?php
if (!defined('ABSPATH')) exit;

/** Jednorazowe uwierzytelnienie tokenem KSeF i pozyskanie access tokenu. */
final class BCS_KSeF_Auth {
    /** @return array{success:bool,message:string,access_token?:string,refresh_token?:string,client?:BCS_KSeF_Client,certificates?:array,reference?:string} */
    public static function authenticate(object $organizer): array {
        try {
            if (!BCS_KSeF_Config::master_key_available()) throw new RuntimeException('Brak klucza BCS_KSEF_SECRET_KEY lub rozszerzenia Sodium.');
            if (!BCS_KSeF_Secret::configured($organizer)) throw new RuntimeException('Organizator nie ma zapisanego tokenu KSeF TEST.');
            $nip = preg_replace('/\D+/', '', (string)($organizer->ksef_context_nip ?? '')) ?: '';
            if (strlen($nip) !== 10) throw new RuntimeException('NIP kontekstu KSeF musi zawierać 10 cyfr.');

            $client = new BCS_KSeF_Client('test');
            $challenge = $client->challenge();
            if (!$challenge['success']) throw new RuntimeException('Nie udało się pobrać wyzwania KSeF: '.$challenge['message']);
            $challengeValue = (string)($challenge['data']['challenge'] ?? '');
            $timestampMs = (string)($challenge['data']['timestampMs'] ?? '');
            if ($timestampMs === '' && !empty($challenge['data']['timestamp'])) {
                $timestamp = strtotime((string)$challenge['data']['timestamp']);
                if ($timestamp !== false) $timestampMs = (string)($timestamp * 1000);
            }
            if ($challengeValue === '' || $timestampMs === '') throw new RuntimeException('Odpowiedź KSeF nie zawiera kompletnego wyzwania uwierzytelnienia.');

            $certificatesResult = $client->public_key_certificates();
            if (!$certificatesResult['success']) throw new RuntimeException('Nie udało się pobrać kluczy KSeF: '.$certificatesResult['message']);
            $certificates = self::certificates($certificatesResult['data']);
            $tokenKey = BCS_KSeF_Crypto::select_public_key($certificates, 'KsefTokenEncryption');
            $plainToken = BCS_KSeF_Secret::decrypt((string)$organizer->ksef_token_ciphertext, (string)$organizer->ksef_token_nonce);
            $encryptedToken = BCS_KSeF_Crypto::rsa_oaep_sha256_encrypt($plainToken.'|'.$timestampMs, $tokenKey['certificate']);

            $init = $client->init_token_auth([
                'challenge'=>$challengeValue,
                'contextIdentifier'=>['type'=>'Nip', 'value'=>$nip],
                'encryptedToken'=>base64_encode($encryptedToken),
                'publicKeyId'=>$tokenKey['publicKeyId'],
            ]);
            if (!$init['success']) throw new RuntimeException('KSeF odrzucił rozpoczęcie uwierzytelnienia: '.$init['message']);
            $reference = (string)($init['data']['referenceNumber'] ?? '');
            $authenticationToken = (string)($init['data']['authenticationToken']['token'] ?? $init['data']['authenticationToken'] ?? '');
            if ($reference === '' || $authenticationToken === '') throw new RuntimeException('KSeF nie zwrócił numeru referencyjnego lub tokenu uwierzytelnienia.');

            $authenticated = false;
            $lastDescription = 'Uwierzytelnianie trwa.';
            for ($attempt = 0; $attempt < 12; $attempt++) {
                if ($attempt > 0) usleep(350000);
                $status = $client->auth_status($reference, $authenticationToken);
                if (!$status['success']) throw new RuntimeException('Nie udało się sprawdzić uwierzytelnienia KSeF: '.$status['message']);
                $code = (int)($status['data']['status']['code'] ?? 0);
                $lastDescription = (string)($status['data']['status']['description'] ?? $lastDescription);
                if ($code === 200) { $authenticated = true; break; }
                if ($code >= 400) throw new RuntimeException('Uwierzytelnienie KSeF odrzucone: '.$lastDescription.' ('.$code.').');
            }
            if (!$authenticated) throw new RuntimeException('KSeF nie zakończył uwierzytelnienia w wymaganym czasie. Ostatni status: '.$lastDescription);

            $redeem = $client->redeem_token($authenticationToken);
            if (!$redeem['success']) throw new RuntimeException('Nie udało się odebrać access tokenu KSeF: '.$redeem['message']);
            $accessToken = (string)($redeem['data']['accessToken']['token'] ?? $redeem['data']['accessToken'] ?? '');
            $refreshToken = (string)($redeem['data']['refreshToken']['token'] ?? $redeem['data']['refreshToken'] ?? '');
            if ($accessToken === '') throw new RuntimeException('KSeF nie zwrócił access tokenu.');

            return [
                'success'=>true,
                'message'=>'Uwierzytelniono Organizatora w KSeF TEST.',
                'access_token'=>$accessToken,
                'refresh_token'=>$refreshToken,
                'client'=>$client,
                'certificates'=>$certificates,
                'reference'=>$reference,
            ];
        } catch (Throwable $exception) {
            return ['success'=>false, 'message'=>$exception->getMessage()];
        }
    }

    public static function certificates(array $data): array {
        if (isset($data['items']) && is_array($data['items'])) return array_values($data['items']);
        if (array_is_list($data)) return array_values($data);
        foreach (['certificates','publicKeyCertificates'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) return array_values($data[$key]);
        }
        return [];
    }
}
