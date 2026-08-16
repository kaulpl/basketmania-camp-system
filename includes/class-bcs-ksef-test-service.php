<?php
if (!defined('ABSPATH')) exit;

/**
 * Test integracyjny pojedynczej faktury na środowisku KSeF TEST.
 * Test wykorzystuje ten sam kod, który obsługuje wysyłkę operacyjną i kończy się
 * dopiero po pobraniu dokumentu z repozytorium KSeF oraz porównaniu jego treści.
 */
final class BCS_KSeF_Test_Service {
    private static function invoice(int $invoiceId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT i.*, o.name organizer_name, o.ksef_enabled, o.ksef_environment, o.ksef_context_nip, '
            .'o.ksef_token_ciphertext, o.ksef_token_nonce, o.ksef_anonymize_test '
            .'FROM '.BCS_DB::table('invoices').' i '
            .'JOIN '.BCS_DB::table('organizers').' o ON o.id=i.organizer_id WHERE i.id=%d',
            $invoiceId
        )) ?: null;
    }

    private static function save(int $invoiceId, string $status, string $message, array $steps): array {
        global $wpdb;
        $wpdb->update(BCS_DB::table('invoices'), [
            'ksef_test_status'=>$status,
            'ksef_tested_at'=>BCS_Utils::now(),
            'ksef_test_message'=>$message,
            'ksef_test_details'=>wp_json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], ['id'=>$invoiceId]);
        $invoice = self::invoice($invoiceId);
        if ($invoice) {
            BCS_KSeF_FA3::operation(
                $invoiceId,
                (int)$invoice->organizer_id,
                'Test integracji KSeF TEST',
                $status === 'passed' ? 'success' : ($status === 'pending' ? 'processing' : 'error'),
                (string)($invoice->ksef_number ?? ''),
                ['test_status'=>$status, 'steps'=>$steps],
                '',
                $status === 'failed' ? $message : ''
            );
        }
        return [
            'success'=>$status !== 'failed',
            'test_status'=>$status,
            'message'=>$message,
            'steps'=>$steps,
            'ksef_number'=>(string)($invoice->ksef_number ?? ''),
        ];
    }

    private static function step(array &$steps, string $key, string $label, string $status, string $message): void {
        $steps[$key] = [
            'label'=>$label,
            'status'=>$status,
            'message'=>$message,
            'at'=>BCS_Utils::now(),
        ];
    }

    /** Uruchamia lub kontynuuje pełny test wysyłki, przyjęcia i pobrania faktury. */
    public static function run(int $invoiceId): array {
        $invoice = self::invoice($invoiceId);
        if (!$invoice) return ['success'=>false, 'test_status'=>'failed', 'message'=>'Nie znaleziono faktury.', 'steps'=>[]];

        $steps = [];
        self::step($steps, 'configuration', 'Konfiguracja Organizatora', 'running', 'Sprawdzanie konfiguracji KSeF TEST.');
        if ((int)$invoice->ksef_enabled !== 1) {
            self::step($steps, 'configuration', 'Konfiguracja Organizatora', 'failed', 'KSeF nie jest włączony dla Organizatora.');
            return self::save($invoiceId, 'failed', 'Test przerwany: KSeF nie jest włączony dla Organizatora.', $steps);
        }
        if (!BCS_KSeF_Secret::configured($invoice)) {
            self::step($steps, 'configuration', 'Konfiguracja Organizatora', 'failed', 'Brak zapisanego tokenu KSeF TEST.');
            return self::save($invoiceId, 'failed', 'Test przerwany: Organizator nie ma zapisanego tokenu KSeF TEST.', $steps);
        }
        if (!BCS_KSeF_Config::master_key_available()) {
            self::step($steps, 'configuration', 'Konfiguracja Organizatora', 'failed', 'Brak BCS_KSEF_SECRET_KEY lub rozszerzenia Sodium.');
            return self::save($invoiceId, 'failed', 'Test przerwany: serwer nie może odszyfrować tokenu KSeF.', $steps);
        }
        if (BCS_KSeF_Config::allowed_environment((string)$invoice->ksef_environment) !== 'test') {
            self::step($steps, 'configuration', 'Konfiguracja Organizatora', 'failed', 'Test może działać wyłącznie na środowisku TEST.');
            return self::save($invoiceId, 'failed', 'Test przerwany: nieprawidłowe środowisko KSeF.', $steps);
        }
        if ((int)$invoice->ksef_anonymize_test !== 1) {
            self::step($steps, 'configuration', 'Konfiguracja Organizatora', 'failed', 'Anonimizacja danych TEST jest wyłączona.');
            return self::save($invoiceId, 'failed', 'Dla pełnego testu włącz anonimizację danych KSeF TEST przy Organizatorze.', $steps);
        }
        self::step($steps, 'configuration', 'Konfiguracja Organizatora', 'passed', 'Token, klucz szyfrujący i środowisko TEST są skonfigurowane.');

        $status = (string)($invoice->ksef_status ?: 'not_sent');
        if ($status === 'accepted' && !empty($invoice->ksef_number)) {
            self::step($steps, 'generation', 'Generowanie XML FA(3)', 'passed', 'Faktura była już wcześniej wygenerowana i przyjęta przez KSeF TEST.');
            self::step($steps, 'send', 'Wysyłka do KSeF TEST', 'passed', 'Nie wysyłano ponownie zaakceptowanego dokumentu.');
            self::step($steps, 'acceptance', 'Przyjęcie przez KSeF', 'passed', 'Numer KSeF: '.(string)$invoice->ksef_number);
            return self::verify_remote($invoiceId, $steps);
        }

        if (!in_array($status, ['processing','sending'], true)) {
            $prepared = BCS_KSeF_FA3::prepare_and_save($invoiceId);
            if (empty($prepared['success'])) {
                $message = (string)($prepared['message'] ?? 'Nie udało się przygotować XML FA(3).');
                if (!empty($prepared['errors'])) $message .= ' '.implode(' ', array_map('strval', (array)$prepared['errors']));
                self::step($steps, 'generation', 'Generowanie XML FA(3)', 'failed', $message);
                return self::save($invoiceId, 'failed', 'Test zakończony błędem podczas generowania XML FA(3).', $steps);
            }
            self::step($steps, 'generation', 'Generowanie XML FA(3)', 'passed', 'XML FA(3) został utworzony i przeszedł prewalidację.');
            $operation = BCS_KSeF_Service::send($invoiceId);
        } else {
            self::step($steps, 'generation', 'Generowanie XML FA(3)', 'passed', 'Dokument jest już w rozpoczętym procesie wysyłki.');
            $operation = BCS_KSeF_Service::refresh_status($invoiceId);
        }

        if (empty($operation['success'])) {
            self::step($steps, 'send', 'Wysyłka do KSeF TEST', 'failed', (string)($operation['message'] ?? 'Wysyłka nie powiodła się.'));
            return self::save($invoiceId, 'failed', 'Test wykazał błąd wysyłki do KSeF TEST.', $steps);
        }

        $operationStatus = (string)($operation['status'] ?? 'processing');
        self::step($steps, 'send', 'Wysyłka do KSeF TEST', 'passed', 'KSeF przyjął żądanie wysyłki do przetwarzania.');
        if ($operationStatus !== 'accepted') {
            self::step($steps, 'acceptance', 'Przyjęcie przez KSeF', 'running', (string)($operation['message'] ?? 'KSeF nadal przetwarza dokument.'));
            return self::save($invoiceId, 'pending', 'Wysyłka działa, ale KSeF nadal przetwarza fakturę. Uruchom „Dokończ test”, aby sprawdzić wynik.', $steps);
        }

        $invoice = self::invoice($invoiceId);
        self::step($steps, 'acceptance', 'Przyjęcie przez KSeF', 'passed', 'KSeF nadał numer: '.(string)($invoice->ksef_number ?? $operation['ksef_number'] ?? ''));
        return self::verify_remote($invoiceId, $steps);
    }

    private static function verify_remote(int $invoiceId, array $steps): array {
        $invoice = self::invoice($invoiceId);
        if (!$invoice || empty($invoice->ksef_number)) {
            self::step($steps, 'download', 'Pobranie z KSeF', 'failed', 'Brak numeru KSeF.');
            return self::save($invoiceId, 'failed', 'KSeF nie nadał numeru dokumentu.', $steps);
        }

        $remote = BCS_KSeF_Service::fetch_remote_xml($invoiceId);
        if (empty($remote['success'])) {
            self::step($steps, 'download', 'Pobranie z KSeF', 'running', (string)($remote['message'] ?? 'Dokument nie jest jeszcze dostępny do pobrania.'));
            return self::save($invoiceId, 'pending', 'Faktura została przyjęta, ale nie jest jeszcze dostępna w trwałym repozytorium KSeF. Użyj „Dokończ test” za chwilę.', $steps);
        }
        self::step($steps, 'download', 'Pobranie z KSeF', 'passed', 'XML został pobrany bezpośrednio z KSeF TEST po numerze KSeF.');

        $localPath = (string)($invoice->ksef_xml_path ?? '');
        $localXml = $localPath !== '' && is_file($localPath) ? (string)file_get_contents($localPath) : '';
        $remoteXml = (string)($remote['xml'] ?? '');
        if ($localXml === '' || $remoteXml === '') {
            self::step($steps, 'integrity', 'Porównanie dokumentu', 'failed', 'Brakuje lokalnego lub pobranego XML.');
            return self::save($invoiceId, 'failed', 'Nie udało się porównać lokalnego dokumentu z fakturą pobraną z KSeF.', $steps);
        }

        $comparison = self::compare_xml($localXml, $remoteXml);
        if (!$comparison['success']) {
            self::step($steps, 'integrity', 'Porównanie dokumentu', 'failed', $comparison['message']);
            return self::save($invoiceId, 'failed', 'KSeF przyjął fakturę, ale kontrola zgodności pobranego XML wykazała różnicę.', $steps);
        }
        self::step($steps, 'integrity', 'Porównanie dokumentu', 'passed', $comparison['message']);

        $upo = !empty($invoice->ksef_upo_path) && is_file((string)$invoice->ksef_upo_path);
        self::step($steps, 'upo', 'UPO', $upo ? 'passed' : 'optional', $upo ? 'UPO zostało zapisane.' : 'UPO nie jest jeszcze zapisane; nie blokuje to wyniku testu faktury.');

        return self::save(
            $invoiceId,
            'passed',
            'TEST OK: faktura została wygenerowana, wysłana, przyjęta przez KSeF TEST, otrzymała numer KSeF i została poprawnie pobrana z repozytorium.',
            $steps
        );
    }

    /** Porównanie semantyczne dokumentów; C14N ignoruje nieistotne różnice formatowania XML. */
    public static function compare_xml(string $localXml, string $remoteXml): array {
        $local = self::canonical($localXml);
        $remote = self::canonical($remoteXml);
        if ($local === '' || $remote === '') return ['success'=>false, 'message'=>'Nie udało się znormalizować XML do porównania.'];
        if (hash_equals(hash('sha256', $local), hash('sha256', $remote))) {
            return ['success'=>true, 'message'=>'Pobrany XML jest semantycznie identyczny z dokumentem wysłanym do KSeF.'];
        }

        $left = self::critical_values($localXml);
        $right = self::critical_values($remoteXml);
        $differences = [];
        foreach ($left as $key=>$value) if (($right[$key] ?? null) !== $value) $differences[] = $key;
        if ($differences) return ['success'=>false, 'message'=>'Różnią się kluczowe pola: '.implode(', ', $differences).'.'];
        return ['success'=>true, 'message'=>'XML różni się wyłącznie technicznym formatowaniem; numer faktury, NIP sprzedawcy i kwota brutto są zgodne.'];
    }

    private static function canonical(string $xml): string {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) return '';
        return (string)$dom->C14N();
    }

    private static function critical_values(string $xml): array {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET)) return [];
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('fa', BCS_KSeF_Config::FA3_NAMESPACE);
        return [
            'numer faktury'=>trim((string)$xpath->evaluate('string(/fa:Faktura/fa:Fa/fa:P_2)')),
            'NIP sprzedawcy'=>trim((string)$xpath->evaluate('string(/fa:Faktura/fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:NIP)')),
            'kwota brutto'=>trim((string)$xpath->evaluate('string(/fa:Faktura/fa:Fa/fa:P_15)')),
        ];
    }
}
