<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.82 – twarda kontrola nabywcy PDF ↔ KSeF.
 *
 * W PRODUKCJI dane Podmiot2 muszą odpowiadać nabywcy zamrożonemu na fakturze PDF.
 * W TEST dane rzeczywiste nie mogą opuścić systemu: Podmiot2 musi zostać zastąpiony
 * fikcyjnym nabywcą, bez NIP-u, zgodnie z ochroną środowiska integracyjnego.
 */
final class BCS_Release_082 {
    public static function init(): void {
        // Przejmujemy wszystkie aktualne wejścia „Generuj fakturę” przed starszymi handlerami.
        add_action('wp_ajax_bcs_ksef_generate_invoice_full_076', [__CLASS__, 'ajax_real_generate'], -100);
        add_action('wp_ajax_bcs_list_quick_action_02010', [__CLASS__, 'ajax_list_generate'], -100);
        add_action('wp_ajax_bcs_generate_invoice_0200', [__CLASS__, 'ajax_legacy_generate'], -100);
        add_action('admin_init', [__CLASS__, 'classic_generate'], -100);
        add_action('admin_post_bcs_workflow_single', [__CLASS__, 'single_generate'], -100);
    }

    private static function invoice(int $registrationId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT i.*, o.ksef_environment, o.ksef_anonymize_test '
            .'FROM '.BCS_DB::table('invoices').' i '
            .'JOIN '.BCS_DB::table('organizers').' o ON o.id=i.organizer_id '
            .'WHERE i.registration_id=%d ORDER BY i.id DESC LIMIT 1',
            $registrationId
        )) ?: null;
    }

    private static function registration(int $registrationId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM '.BCS_DB::table('registrations').' WHERE id=%d',
            $registrationId
        )) ?: null;
    }

    private static function normalized_text(string $value): string {
        return preg_replace('/\s+/u', ' ', trim($value)) ?: '';
    }

    /** @return array{source:string,nip:string,name:string,country_code:string,address_l1:string,address_l2:string} */
    public static function normalize_buyer(array $buyer): array {
        return [
            'source'=>(string)($buyer['source'] ?? ''),
            'nip'=>preg_replace('/\D+/', '', (string)($buyer['nip'] ?? '')) ?: '',
            'name'=>self::normalized_text((string)($buyer['name'] ?? '')),
            'country_code'=>strtoupper(trim((string)($buyer['country_code'] ?? 'PL'))) ?: 'PL',
            'address_l1'=>self::normalized_text((string)($buyer['address_l1'] ?? '')),
            'address_l2'=>self::normalized_text((string)($buyer['address_l2'] ?? '')),
        ];
    }

    public static function buyer_snapshots_match(array $expected, array $actual): bool {
        $a = self::normalize_buyer($expected);
        $b = self::normalize_buyer($actual);
        foreach (['nip','name','country_code','address_l1','address_l2'] as $field) {
            if ($a[$field] !== $b[$field]) return false;
        }
        return true;
    }

    /** @return array{source:string,nip:string,name:string,country_code:string,address_l1:string,address_l2:string} */
    public static function buyer_from_xml(string $xml): array {
        $empty = ['source'=>'xml','nip'=>'','name'=>'','country_code'=>'PL','address_l1'=>'','address_l2'=>''];
        if (trim($xml) === '' || !class_exists('DOMDocument')) return $empty;
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) return $empty;
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('fa', BCS_KSeF_Config::FA3_NAMESPACE);
        $value = static function(string $query) use ($xpath): string {
            $nodes = $xpath->query($query);
            return $nodes && $nodes->length ? trim((string)$nodes->item(0)->textContent) : '';
        };
        return [
            'source'=>'xml',
            'nip'=>$value('/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:NIP'),
            'name'=>$value('/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:Nazwa'),
            'country_code'=>$value('/fa:Faktura/fa:Podmiot2/fa:Adres/fa:KodKraju') ?: 'PL',
            'address_l1'=>$value('/fa:Faktura/fa:Podmiot2/fa:Adres/fa:AdresL1'),
            'address_l2'=>$value('/fa:Faktura/fa:Podmiot2/fa:Adres/fa:AdresL2'),
        ];
    }

    /**
     * Twarda kontrola przed wysyłką do KSeF TEST. Nie wystarczy brak NIP-u:
     * wymagamy również BrakID=1 i dokładnie naszych fikcyjnych danych nabywcy.
     */
    public static function test_buyer_is_anonymized(string $xml): bool {
        if (trim($xml) === '' || !class_exists('DOMDocument')) return false;
        $buyer = self::buyer_from_xml($xml);
        $expected = [
            'source'=>'test',
            'nip'=>'',
            'name'=>'Nabywca Testowy',
            'country_code'=>'PL',
            'address_l1'=>'ul. Przykładowa 2',
            'address_l2'=>'00-002 Miasto Testowe',
        ];
        if (!self::buyer_snapshots_match($expected, $buyer)) return false;

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) return false;
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('fa', BCS_KSeF_Config::FA3_NAMESPACE);
        $brak = $xpath->query('/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:BrakID');
        $nip = $xpath->query('/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:NIP');
        return $brak && $brak->length === 1
            && trim((string)$brak->item(0)->textContent) === '1'
            && $nip && $nip->length === 0;
    }

    private static function stored_buyer(object $invoice): array {
        $buyer = json_decode((string)($invoice->buyer_snapshot ?? ''), true);
        return is_array($buyer) ? $buyer : [];
    }

    private static function valid_buyer(array $buyer): bool {
        $buyer = self::normalize_buyer($buyer);
        return $buyer['name'] !== '' && $buyer['address_l1'] !== '';
    }

    /**
     * Przy świadomym „Faktura: TAK” bieżące kompletne dane fakturowe są nadrzędne.
     * Naprawia to także rekordy, których buyer_snapshot został wcześniej nadpisany
     * danymi rodzica podczas przygotowywania KSeF.
     */
    private static function freeze_buyer(int $registrationId, object $invoice): array {
        global $wpdb;
        $registration = self::registration($registrationId);
        if (!$registration) return ['success'=>false,'message'=>'Nie znaleziono zgłoszenia.'];

        $current = BCS_Invoices::buyer_snapshot_from_registration($registration);
        $stored = self::stored_buyer($invoice);
        $requested = (int)($registration->invoice_requested ?? 0) === 1;

        if ($requested) {
            if (!empty($current['errors'])) {
                return ['success'=>false,'message'=>'Faktura ma być wystawiona na dane z formularza, ale dane nabywcy są niekompletne: '.implode(' ', (array)$current['errors'])];
            }
            $expected = $current;
        } elseif (self::valid_buyer($stored) && empty($stored['anonymized'])) {
            // Gdy formularz nie żąda danych firmowych, zachowujemy snapshot faktycznie
            // zamrożony przy fakturze zamiast ponownie wyliczać nabywcę.
            $expected = $stored;
        } else {
            if (!empty($current['errors'])) {
                return ['success'=>false,'message'=>'Nie udało się ustalić nabywcy faktury: '.implode(' ', (array)$current['errors'])];
            }
            $expected = $current;
        }

        $expected['source_version'] = '0.80';
        $expected['guard_version'] = '0.82';
        $expected['anonymized'] = false;
        unset($expected['errors']);

        $oldNormalized = self::normalize_buyer($stored);
        $newNormalized = self::normalize_buyer($expected);
        if ($oldNormalized !== $newNormalized || (string)($stored['guard_version'] ?? '') !== '0.82') {
            $wpdb->update(BCS_DB::table('invoices'), [
                'buyer_snapshot'=>wp_json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], ['id'=>(int)$invoice->id]);
            BCS_Utils::log('invoice_buyer_snapshot_repaired_082', [
                'invoice_id'=>(int)$invoice->id,
                'invoice_requested'=>$requested?1:0,
                'buyer_source'=>(string)($expected['source'] ?? ''),
                'previous_hash'=>hash('sha256', wp_json_encode($oldNormalized)),
                'new_hash'=>hash('sha256', wp_json_encode($newNormalized)),
            ], $registrationId, null);
        }

        if ((string)($expected['source'] ?? '') === 'invoice_form' && (int)($registration->invoice_requested ?? 0) !== 1) {
            $addressL2 = self::normalized_text((string)($expected['address_l2'] ?? ''));
            $postal = (string)($registration->invoice_postal_code ?? '');
            $city = (string)($registration->invoice_city ?? '');
            if (preg_match('/^(\d{2}-\d{3})\s+(.+)$/u', $addressL2, $m)) {
                $postal = $m[1]; $city = $m[2];
            }
            $wpdb->update(BCS_DB::table('registrations'), [
                'invoice_requested'=>1,
                'invoice_buyer_name'=>(string)$expected['name'],
                'invoice_street'=>(string)$expected['address_l1'],
                'invoice_postal_code'=>$postal,
                'invoice_city'=>$city,
                'invoice_nip'=>(string)$expected['nip'],
                'updated_at'=>BCS_Utils::now(),
            ], ['id'=>$registrationId]);
        }

        return ['success'=>true,'buyer'=>$expected];
    }

    private static function guard_xml(int $registrationId, object $invoice, array $expected): array {
        global $wpdb;
        $environment = BCS_KSeF_Config::allowed_environment((string)($invoice->ksef_environment ?? 'test'));
        $prepared = BCS_KSeF_FA3::prepare_and_save((int)$invoice->id);
        if (empty($prepared['success'])) {
            $message = (string)($prepared['message'] ?? 'Nie udało się przygotować XML FA(3).');
            if (!empty($prepared['errors'])) $message .= ' '.implode(' ', array_map('strval', (array)$prepared['errors']));
            return ['success'=>false,'message'=>$message];
        }
        $fresh = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.BCS_DB::table('invoices').' WHERE id=%d', (int)$invoice->id));
        $path = (string)($fresh->ksef_xml_path ?? '');
        $xml = $path !== '' && is_file($path) ? (string)file_get_contents($path) : '';
        $actual = self::buyer_from_xml($xml);

        if ($environment === 'test') {
            if (!self::test_buyer_is_anonymized($xml)) {
                $message = 'Zablokowano wysyłkę do KSeF TEST: XML zawiera niezanonimizowane albo nieprawidłowe dane nabywcy.';
                $wpdb->update(BCS_DB::table('invoices'), [
                    'ksef_status'=>'rejected',
                    'ksef_error_code'=>'TEST_BUYER_NOT_ANONYMIZED_111',
                    'ksef_error_message'=>$message,
                    'ksef_last_checked_at'=>BCS_Utils::now(),
                ], ['id'=>(int)$invoice->id]);
                BCS_KSeF_FA3::operation((int)$invoice->id, (int)$invoice->organizer_id, 'Kontrola anonimizacji nabywcy KSeF TEST', 'error', null, [
                    'xml_buyer_hash'=>hash('sha256', wp_json_encode(self::normalize_buyer($actual))),
                    'buyer_source'=>(string)($expected['source'] ?? ''),
                ], 'TEST_BUYER_NOT_ANONYMIZED_111', $message);
                return ['success'=>false,'message'=>$message];
            }
            BCS_KSeF_FA3::operation((int)$invoice->id, (int)$invoice->organizer_id, 'Kontrola anonimizacji nabywcy KSeF TEST', 'success', null, [
                'xml_buyer_hash'=>hash('sha256', wp_json_encode(self::normalize_buyer($actual))),
                'buyer_source'=>(string)($expected['source'] ?? ''),
            ]);
            return ['success'=>true];
        }

        if (!self::buyer_snapshots_match($expected, $actual)) {
            $expectedNormalized = self::normalize_buyer($expected);
            $actualNormalized = self::normalize_buyer($actual);
            $message = 'Zablokowano wysyłkę do KSeF PRODUKCJA: dane nabywcy w XML różnią się od danych zamrożonych na fakturze PDF.';
            $wpdb->update(BCS_DB::table('invoices'), [
                'ksef_status'=>'rejected',
                'ksef_error_code'=>'BUYER_MISMATCH_082',
                'ksef_error_message'=>$message,
                'ksef_last_checked_at'=>BCS_Utils::now(),
            ], ['id'=>(int)$invoice->id]);
            BCS_KSeF_FA3::operation((int)$invoice->id, (int)$invoice->organizer_id, 'Kontrola nabywcy PDF ↔ KSeF PRODUKCJA', 'error', null, [
                'expected_hash'=>hash('sha256', wp_json_encode($expectedNormalized)),
                'xml_hash'=>hash('sha256', wp_json_encode($actualNormalized)),
                'buyer_source'=>(string)($expected['source'] ?? ''),
            ], 'BUYER_MISMATCH_082', $message);
            return ['success'=>false,'message'=>$message];
        }
        BCS_KSeF_FA3::operation((int)$invoice->id, (int)$invoice->organizer_id, 'Kontrola nabywcy PDF ↔ KSeF PRODUKCJA', 'success', null, [
            'buyer_hash'=>hash('sha256', wp_json_encode(self::normalize_buyer($expected))),
            'buyer_source'=>(string)($expected['source'] ?? ''),
        ]);
        return ['success'=>true];
    }

    /** Główna procedura 0.82 dla właściwej faktury. */
    public static function generate_guarded(int $registrationId): array {
        global $wpdb;
        $invoice = self::invoice($registrationId);
        if (!$invoice) {
            $path = BCS_Invoices::ensure_invoice($registrationId);
            if ($path === '' || !is_file($path)) return ['success'=>false,'message'=>'Nie udało się utworzyć dokumentu faktury.'];
            $invoice = self::invoice($registrationId);
        }
        if (!$invoice) return ['success'=>false,'message'=>'Nie udało się odczytać utworzonej faktury.'];
        if ((string)($invoice->ksef_status ?? '') === 'accepted' && !empty($invoice->ksef_number)) {
            return ['success'=>true,'message'=>'Faktura jest już przyjęta w KSeF.','status'=>'accepted','invoice_id'=>(int)$invoice->id,'ksef_number'=>(string)$invoice->ksef_number];
        }

        $frozen = self::freeze_buyer($registrationId, $invoice);
        if (empty($frozen['success'])) return $frozen;
        $expected = (array)$frozen['buyer'];

        // TEST zawsze anonimizujemy. Nie wolno już czasowo wyłączać ochrony nawet po to,
        // aby zrównać XML z lokalnym PDF. W PRODUKCJI zachowujemy rzeczywiste dane.
        $environment = BCS_KSeF_Config::allowed_environment((string)($invoice->ksef_environment ?? 'test'));
        if ($environment === 'test') {
            $wpdb->update(BCS_DB::table('organizers'), ['ksef_anonymize_test'=>1], ['id'=>(int)$invoice->organizer_id]);
            $invoice->ksef_anonymize_test = 1;
        }

        $guard = self::guard_xml($registrationId, $invoice, $expected);
        if (empty($guard['success'])) return $guard;

        $ok = BCS_KSeF_Invoice_Flow::generate_and_submit($registrationId);
        $result = BCS_KSeF_Invoice_Flow::last_result();
        if (!$ok) return $result ?: ['success'=>false,'message'=>'Nie udało się wysłać faktury do KSeF.'];
        $result = $result ?: ['success'=>true,'message'=>'Faktura została przekazana do KSeF.'];
        $result['success'] = true;
        return self::enrich_result($registrationId, $result);
    }

    private static function enrich_result(int $registrationId, array $result): array {
        $invoice = self::invoice($registrationId);
        if (!$invoice) return $result;
        $result['invoice_id'] = (int)$invoice->id;
        $result['invoice_number'] = (string)$invoice->invoice_number;
        $result['view_url'] = wp_nonce_url(admin_url('admin-post.php?action=bcs_invoice_view&invoice_id='.(int)$invoice->id), 'bcs_invoice_view_'.(int)$invoice->id);
        $result['download_url'] = wp_nonce_url(admin_url('admin-post.php?action=bcs_invoice_download&invoice_id='.(int)$invoice->id), 'bcs_invoice_download_'.(int)$invoice->id);
        return $result;
    }

    private static function json_result(array $result): void {
        !empty($result['success']) ? wp_send_json_success($result) : wp_send_json_error($result, 422);
    }

    public static function ajax_real_generate(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        $id = absint($_POST['registration_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!$id || !wp_verify_nonce($nonce, 'bcs_crm_'.$id)) wp_send_json_error(['message'=>'Sesja wygasła. Odśwież stronę.'], 403);
        self::json_result(self::generate_guarded($id));
    }

    public static function ajax_list_generate(): void {
        if (sanitize_key(wp_unslash($_POST['quick_action'] ?? '')) !== 'invoice_generate') return;
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        $id = absint($_POST['registration_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        $valid = $id && (
            wp_verify_nonce($nonce, 'bcs_crm_'.$id)
            || wp_verify_nonce($nonce, 'bcs_workflow_single_'.$id.'_invoice_generate')
            || wp_verify_nonce($nonce, 'bcs_workflow_single_'.$id.'_generate_invoice')
        );
        if (!$valid) wp_send_json_error(['message'=>'Sesja wygasła.'], 403);
        self::json_result(self::generate_guarded($id));
    }

    public static function ajax_legacy_generate(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        check_ajax_referer('bcs_generate_invoice_0200', 'nonce');
        $id = absint($_POST['registration_id'] ?? 0);
        if (!$id) wp_send_json_error(['message'=>'Nieprawidłowe zgłoszenie.'], 422);
        self::json_result(self::generate_guarded($id));
    }

    public static function classic_generate(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if (!empty($_POST['bcs_crm_action']) && sanitize_key(wp_unslash($_POST['bcs_crm_action'])) === 'invoice_generate') {
            $id = absint($_POST['registration_id'] ?? 0);
            check_admin_referer('bcs_crm_'.$id);
            $result = self::generate_guarded($id);
            set_transient('bcs_ksef_invoice_result_'.get_current_user_id().'_'.$id, $result, 5 * MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg(['page'=>'bcs-registrations','view'=>$id,'crm_done'=>!empty($result['success'])?1:0], admin_url('admin.php')));
            exit;
        }
        if (!empty($_POST['bcs_workflow_action']) && sanitize_key(wp_unslash($_POST['bcs_workflow_action'])) === 'generate_invoice') {
            check_admin_referer('bcs_workflow_action');
            $ids = array_values(array_filter(array_map('absint', (array)($_POST['registration_ids'] ?? []))));
            $ok = 0; $failed = 0;
            foreach ($ids as $id) !empty(self::generate_guarded($id)['success']) ? $ok++ : $failed++;
            wp_safe_redirect(add_query_arg(['page'=>'bcs-registrations','done'=>$ok,'failed'=>$failed], admin_url('admin.php')));
            exit;
        }
    }

    public static function single_generate(): void {
        if (!current_user_can('manage_options')) return;
        $action = sanitize_key(wp_unslash($_GET['workflow'] ?? ''));
        if ($action !== 'generate_invoice') return;
        $id = absint($_GET['registration_id'] ?? 0);
        check_admin_referer('bcs_workflow_single_'.$id.'_generate_invoice');
        $result = self::generate_guarded($id);
        wp_safe_redirect(add_query_arg(['page'=>'bcs-registrations','view'=>$id,'done'=>!empty($result['success'])?1:0,'failed'=>empty($result['success'])?1:0], admin_url('admin.php')));
        exit;
    }
}
