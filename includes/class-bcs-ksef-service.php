<?php
if (!defined('ABSPATH')) exit;

/** Pełny przepływ pojedynczej faktury w aktywnym środowisku KSeF. */
final class BCS_KSeF_Service {
    private static function invoice(int $invoiceId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT i.*, o.name organizer_name, o.ksef_enabled, o.ksef_environment, o.ksef_context_nip, '
            .'o.ksef_token_ciphertext, o.ksef_token_nonce, o.ksef_token_configured_at, '
            .'o.ksef_production_token_ciphertext, o.ksef_production_token_nonce, o.ksef_production_token_configured_at '
            .'FROM '.BCS_DB::table('invoices').' i JOIN '.BCS_DB::table('organizers').' o ON o.id=i.organizer_id WHERE i.id=%d',
            $invoiceId
        )) ?: null;
    }

    private static function environment(object $invoice): string {
        $stored = trim((string)($invoice->ksef_environment_used ?? ''));
        return BCS_KSeF_Config::allowed_environment($stored !== '' ? $stored : (string)($invoice->ksef_environment ?? 'test'));
    }

    private static function update(int $invoiceId, array $data): void {
        global $wpdb;
        $wpdb->update(BCS_DB::table('invoices'), $data, ['id'=>$invoiceId]);
    }

    private static function fail(object $invoice, string $message, string $code = '', string $status = 'connection_error'): array {
        self::update((int)$invoice->id, [
            'ksef_status'=>$status,
            'ksef_error_code'=>$code ?: null,
            'ksef_error_message'=>$message,
            'ksef_last_checked_at'=>BCS_Utils::now(),
        ]);
        BCS_KSeF_FA3::operation((int)$invoice->id, (int)$invoice->organizer_id, 'Obsługa faktury w KSeF', 'error', null, ['environment'=>self::environment($invoice)], $code, $message);
        return ['success'=>false, 'message'=>$message, 'status'=>$status, 'environment'=>self::environment($invoice)];
    }

    /** Wysyła przygotowany XML jako fakturę do środowiska wybranego dla Organizatora. */
    public static function send(int $invoiceId): array {
        $invoice = self::invoice($invoiceId);
        if (!$invoice) return ['success'=>false, 'message'=>'Nie znaleziono faktury.'];
        if ((int)$invoice->ksef_enabled !== 1) return self::fail($invoice, 'KSeF nie jest włączony dla Organizatora.', 'KSEF_DISABLED');
        $environment = self::environment($invoice);
        if (!BCS_KSeF_Secret::configured($invoice, $environment)) {
            return self::fail($invoice, 'Brak tokenu KSeF dla środowiska '.BCS_KSeF_Config::label($environment).'.', 'TOKEN_MISSING');
        }
        if (!empty($invoice->ksef_number) && (string)$invoice->ksef_status === 'accepted') {
            return ['success'=>true, 'message'=>'Faktura jest już przyjęta w KSeF '.BCS_KSeF_Config::label($environment).'.', 'status'=>'accepted', 'ksef_number'=>(string)$invoice->ksef_number, 'environment'=>$environment];
        }

        self::update($invoiceId, ['ksef_environment_used'=>$environment]);
        $invoice->ksef_environment_used = $environment;

        if (empty($invoice->ksef_xml_path) || !is_file((string)$invoice->ksef_xml_path)) {
            $prepared = BCS_KSeF_FA3::prepare_and_save($invoiceId);
            if (empty($prepared['success'])) return self::fail($invoice, (string)($prepared['message'] ?? 'Nie udało się wygenerować XML FA(3).'), 'XML_PREPARATION_FAILED', 'rejected');
            $invoice = self::invoice($invoiceId);
        }
        $xml = is_file((string)$invoice->ksef_xml_path) ? (string)file_get_contents((string)$invoice->ksef_xml_path) : '';
        if ($xml === '') return self::fail($invoice, 'Plik XML FA(3) jest pusty lub niedostępny.', 'XML_UNAVAILABLE', 'rejected');
        $validation = BCS_KSeF_FA3::validate($xml);
        if (!$validation['success']) return self::fail($invoice, 'XML FA(3) nie przeszedł walidacji: '.implode(' ', $validation['errors']), 'XML_INVALID', 'rejected');

        self::update($invoiceId, [
            'ksef_status'=>'sending',
            'ksef_error_code'=>null,
            'ksef_error_message'=>null,
            'ksef_attempts'=>(int)$invoice->ksef_attempts + 1,
            'ksef_sent_at'=>BCS_Utils::now(),
            'ksef_environment_used'=>$environment,
        ]);
        BCS_KSeF_FA3::operation($invoiceId, (int)$invoice->organizer_id, 'Rozpoczęcie wysyłki faktury KSeF', 'processing', null, ['environment'=>$environment]);

        $auth = BCS_KSeF_Auth::authenticate($invoice, $environment);
        if (empty($auth['success'])) return self::fail($invoice, 'Uwierzytelnienie KSeF nie powiodło się: '.(string)$auth['message'], 'AUTH_FAILED');

        try {
            /** @var BCS_KSeF_Client $client */
            $client = $auth['client'];
            $accessToken = (string)$auth['access_token'];
            $symmetricKeyCertificate = BCS_KSeF_Crypto::select_public_key((array)$auth['certificates'], 'SymmetricKeyEncryption');
            $material = BCS_KSeF_Crypto::symmetric_material();
            $encryptedSymmetricKey = BCS_KSeF_Crypto::rsa_oaep_sha256_encrypt($material['key'], $symmetricKeyCertificate['certificate']);

            $open = $client->open_online_session([
                'formCode'=>[
                    'systemCode'=>BCS_KSeF_Config::FA3_SYSTEM_CODE,
                    'schemaVersion'=>BCS_KSeF_Config::FA3_SCHEMA_VERSION,
                    'value'=>'FA',
                ],
                'encryption'=>[
                    'encryptedSymmetricKey'=>base64_encode($encryptedSymmetricKey),
                    'initializationVector'=>base64_encode($material['iv']),
                    'publicKeyId'=>$symmetricKeyCertificate['publicKeyId'],
                ],
            ], $accessToken);
            if (!$open['success']) throw new RuntimeException('Nie udało się otworzyć sesji interaktywnej: '.$open['message']);
            $sessionReference = (string)($open['data']['referenceNumber'] ?? '');
            if ($sessionReference === '') throw new RuntimeException('KSeF nie zwrócił numeru referencyjnego sesji interaktywnej.');

            $encryptedXml = BCS_KSeF_Crypto::aes_256_cbc_encrypt($xml, $material['key'], $material['iv']);
            $send = $client->send_online_invoice($sessionReference, [
                'invoiceHash'=>BCS_KSeF_Crypto::sha256_base64($xml),
                'invoiceSize'=>strlen($xml),
                'encryptedInvoiceHash'=>BCS_KSeF_Crypto::sha256_base64($encryptedXml),
                'encryptedInvoiceSize'=>strlen($encryptedXml),
                'encryptedInvoiceContent'=>base64_encode($encryptedXml),
                'offlineMode'=>false,
            ], $accessToken);
            if (!$send['success']) throw new RuntimeException('KSeF odrzucił wysyłkę dokumentu: '.$send['message']);
            $invoiceReference = (string)($send['data']['referenceNumber'] ?? '');
            if ($invoiceReference === '') throw new RuntimeException('KSeF nie zwrócił referencji wysłanej faktury.');

            self::update($invoiceId, [
                'ksef_status'=>'processing',
                'ksef_session_reference'=>$sessionReference,
                'ksef_invoice_reference'=>$invoiceReference,
                'ksef_reference'=>$invoiceReference,
                'ksef_public_key_id'=>$symmetricKeyCertificate['publicKeyId'],
                'ksef_last_checked_at'=>BCS_Utils::now(),
                'ksef_environment_used'=>$environment,
            ]);
            BCS_KSeF_FA3::operation($invoiceId, (int)$invoice->organizer_id, 'Przesłanie faktury do KSeF', 'processing', $invoiceReference, ['session_reference'=>$sessionReference,'environment'=>$environment]);

            $client->close_online_session($sessionReference, $accessToken);
            for ($attempt = 0; $attempt < 8; $attempt++) {
                if ($attempt > 0) usleep(500000);
                $status = self::apply_status($invoiceId, $invoice, $client->session_invoice_status($sessionReference, $invoiceReference, $accessToken));
                if (($status['status'] ?? '') !== 'processing') return $status;
            }
            return ['success'=>true, 'message'=>'Faktura została przesłana do KSeF '.BCS_KSeF_Config::label($environment).' i nadal jest przetwarzana.', 'status'=>'processing', 'environment'=>$environment];
        } catch (Throwable $exception) {
            return self::fail($invoice, $exception->getMessage(), 'SEND_FAILED');
        }
    }

    public static function refresh_status(int $invoiceId): array {
        $invoice = self::invoice($invoiceId);
        if (!$invoice) return ['success'=>false, 'message'=>'Nie znaleziono faktury.'];
        if (empty($invoice->ksef_session_reference) || empty($invoice->ksef_invoice_reference)) return self::fail($invoice, 'Brak referencji sesji lub faktury KSeF.', 'REFERENCE_MISSING');
        $environment = self::environment($invoice);
        $auth = BCS_KSeF_Auth::authenticate($invoice, $environment);
        if (empty($auth['success'])) return self::fail($invoice, 'Nie udało się uwierzytelnić w KSeF: '.(string)$auth['message'], 'AUTH_FAILED');
        return self::apply_status($invoiceId, $invoice, $auth['client']->session_invoice_status((string)$invoice->ksef_session_reference, (string)$invoice->ksef_invoice_reference, (string)$auth['access_token']));
    }

    private static function apply_status(int $invoiceId, object $invoice, array $response): array {
        $environment = self::environment($invoice);
        if (!$response['success']) return self::fail($invoice, 'Nie udało się pobrać statusu faktury: '.$response['message'], 'STATUS_FAILED');
        $data = $response['data'];
        $code = (int)($data['status']['code'] ?? 0);
        $description = (string)($data['status']['description'] ?? 'Brak opisu statusu.');
        $ksefNumber = (string)($data['ksefNumber'] ?? '');
        if ($code === 440 && $ksefNumber === '') $ksefNumber = (string)($data['status']['extensions']['originalKsefNumber'] ?? $data['extensions']['originalKsefNumber'] ?? '');

        if ($code === 200 || ($code === 440 && $ksefNumber !== '')) {
            $acceptedAt = self::mysql_datetime((string)($data['acquisitionDate'] ?? '')) ?: BCS_Utils::now();
            self::update($invoiceId, [
                'ksef_status'=>'accepted',
                'ksef_number'=>$ksefNumber,
                'ksef_accepted_at'=>$acceptedAt,
                'ksef_last_checked_at'=>BCS_Utils::now(),
                'ksef_status_code'=>(string)$code,
                'ksef_status_description'=>$description,
                'ksef_error_code'=>null,
                'ksef_error_message'=>null,
                'ksef_environment_used'=>$environment,
            ]);
            BCS_KSeF_FA3::operation($invoiceId, (int)$invoice->organizer_id, 'Przyjęcie faktury przez KSeF', 'success', $ksefNumber, ['status_code'=>$code,'description'=>$description,'environment'=>$environment]);
            self::save_upo_if_available($invoiceId, $invoice, $data, $environment);
            return ['success'=>true, 'message'=>'Faktura została przyjęta w KSeF '.BCS_KSeF_Config::label($environment).'. Numer KSeF: '.$ksefNumber, 'status'=>'accepted', 'ksef_number'=>$ksefNumber, 'environment'=>$environment];
        }
        if ($code >= 400) return self::fail($invoice, 'KSeF odrzucił fakturę: '.$description.' ('.$code.').', (string)$code, 'rejected');

        self::update($invoiceId, [
            'ksef_status'=>'processing',
            'ksef_last_checked_at'=>BCS_Utils::now(),
            'ksef_status_code'=>(string)$code,
            'ksef_status_description'=>$description,
        ]);
        return ['success'=>true, 'message'=>'KSeF przetwarza fakturę: '.$description.($code ? ' ('.$code.').' : ''), 'status'=>'processing', 'environment'=>$environment];
    }

    /** Pobiera z KSeF źródłowy XML przyjętej faktury. */
    public static function fetch_remote_xml(int $invoiceId): array {
        $invoice = self::invoice($invoiceId);
        if (!$invoice) return ['success'=>false, 'message'=>'Nie znaleziono faktury.'];
        if (empty($invoice->ksef_number)) return ['success'=>false, 'message'=>'Faktura nie ma jeszcze numeru KSeF.'];
        $environment = self::environment($invoice);
        $auth = BCS_KSeF_Auth::authenticate($invoice, $environment);
        if (empty($auth['success'])) return ['success'=>false, 'message'=>'Nie udało się uwierzytelnić w KSeF: '.(string)$auth['message']];
        $response = $auth['client']->invoice_xml((string)$invoice->ksef_number, (string)$auth['access_token']);
        if (!$response['success'] || trim((string)$response['raw']) === '') return ['success'=>false, 'message'=>'Nie udało się pobrać faktury z KSeF: '.$response['message']];
        $directory = BCS_Document_Engine::uploads_dir().'/registration-'.(int)$invoice->registration_id;
        if (!is_dir($directory)) wp_mkdir_p($directory);
        $path = $directory.'/05-ksef-pobrana-'.sanitize_file_name(str_replace(['/',':'], '-', (string)$invoice->ksef_number)).'.xml';
        if (file_put_contents($path, (string)$response['raw'], LOCK_EX) === false) return ['success'=>false, 'message'=>'Nie udało się zapisać faktury pobranej z KSeF.'];
        self::update($invoiceId, ['ksef_remote_xml_path'=>$path, 'ksef_last_checked_at'=>BCS_Utils::now()]);
        BCS_KSeF_FA3::operation($invoiceId, (int)$invoice->organizer_id, 'Pobranie faktury z KSeF', 'success', (string)$invoice->ksef_number, ['environment'=>$environment]);
        return ['success'=>true, 'message'=>'Pobrano fakturę bezpośrednio z KSeF '.BCS_KSeF_Config::label($environment).'.', 'xml'=>(string)$response['raw'], 'path'=>$path, 'environment'=>$environment];
    }

    private static function save_upo_if_available(int $invoiceId, object $invoice, array $data, string $environment): void {
        $url = (string)($data['upoDownloadUrl'] ?? '');
        if ($url === '') return;
        $client = new BCS_KSeF_Client($environment);
        $result = $client->download_url($url);
        if (!$result['success'] || $result['raw'] === '') return;
        $directory = BCS_Document_Engine::uploads_dir().'/registration-'.(int)$invoice->registration_id;
        if (!is_dir($directory)) wp_mkdir_p($directory);
        $path = $directory.'/06-ksef-upo-'.(int)$invoiceId.'.xml';
        if (file_put_contents($path, $result['raw'], LOCK_EX) !== false) self::update($invoiceId, ['ksef_upo_path'=>$path]);
    }

    private static function mysql_datetime(string $value): string {
        if ($value === '') return '';
        $timestamp = strtotime($value);
        return $timestamp === false ? '' : gmdate('Y-m-d H:i:s', $timestamp);
    }
}
