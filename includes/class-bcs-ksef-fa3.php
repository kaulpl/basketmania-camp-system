<?php
if (!defined('ABSPATH')) exit;

/** Generator i prewalidator faktury ustrukturyzowanej FA(3). */
final class BCS_KSeF_FA3 {
    private const NS = BCS_KSeF_Config::FA3_NAMESPACE;

    private static function invoice_row(int $invoiceId): ?object {
        global $wpdb;
        $sql = "SELECT i.*, r.parent_first_name, r.parent_last_name, r.parent_address,
                       r.parent_street, r.parent_house_number, r.parent_postal_code, r.parent_city,
                       r.invoice_requested, r.invoice_buyer_name, r.invoice_street, r.invoice_postal_code, r.invoice_city,
                       r.invoice_nip, r.invoice_notes, r.child_first_name, r.child_last_name,
                       c.name camp_name, c.start_date, c.end_date, c.location,
                       o.name organizer_name, o.legal_form organizer_legal_form, o.address organizer_address,
                       o.nip organizer_nip, o.email organizer_email, o.phone organizer_phone,
                       o.bank_account, o.ksef_enabled, o.ksef_environment, o.ksef_context_nip,
                       o.ksef_country_code, o.ksef_address_l1, o.ksef_address_l2, o.ksef_anonymize_test
                FROM ".BCS_DB::table('invoices')." i
                JOIN ".BCS_DB::table('registrations')." r ON r.id=i.registration_id
                JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id
                JOIN ".BCS_DB::table('organizers')." o ON o.id=i.organizer_id
                WHERE i.id=%d";
        return $wpdb->get_row($wpdb->prepare($sql, $invoiceId)) ?: null;
    }

    private static function add(DOMDocument $dom, DOMElement $parent, string $name, string|int|float $value): DOMElement {
        $node = $dom->createElementNS(self::NS, $name);
        $node->appendChild($dom->createTextNode((string)$value));
        $parent->appendChild($node);
        return $node;
    }

    private static function amount(float $value): string {
        return number_format($value, 2, '.', '');
    }

    private static function clean_nip(string $nip): string {
        return preg_replace('/\D+/', '', $nip) ?: '';
    }

    /** @return array{seller:array,buyer:array,item:array} */
    private static function snapshots(object $row): array {
        $test = BCS_KSeF_Config::allowed_environment((string)($row->ksef_environment ?? 'test')) === 'test';
        $anonymize = $test && (int)($row->ksef_anonymize_test ?? 1) === 1;
        $sellerNip = self::clean_nip((string)($row->ksef_context_nip ?: $row->organizer_nip));
        $seller = [
            'nip' => $sellerNip,
            'name' => $anonymize ? 'Sprzedawca Testowy Basketmania' : trim((string)$row->organizer_name.' '.(string)$row->organizer_legal_form),
            'country_code' => strtoupper((string)($row->ksef_country_code ?: 'PL')),
            'address_l1' => $anonymize ? 'ul. Testowa 1' : trim((string)($row->ksef_address_l1 ?: $row->organizer_address)),
            'address_l2' => $anonymize ? '00-001 Miasto Testowe' : trim((string)$row->ksef_address_l2),
        ];

        $storedBuyer = json_decode((string)($row->buyer_snapshot ?? ''), true);
        $storedCanonical = is_array($storedBuyer)
            && (string)($storedBuyer['source_version'] ?? '') === '0.80'
            && empty($storedBuyer['anonymized'])
            && in_array((string)($storedBuyer['source'] ?? ''), ['invoice_form','parent'], true);
        $buyer = $storedCanonical
            ? $storedBuyer
            : BCS_Invoices::buyer_snapshot_from_registration($row);
        unset($buyer['errors']);

        if ($anonymize) {
            $buyer['nip'] = '';
            $buyer['name'] = 'Nabywca Testowy';
            $buyer['country_code'] = 'PL';
            $buyer['address_l1'] = 'ul. Przykładowa 2';
            $buyer['address_l2'] = '00-002 Miasto Testowe';
            $buyer['anonymized'] = true;
        } else {
            $buyer['nip'] = self::clean_nip((string)($buyer['nip'] ?? ''));
            $buyer['anonymized'] = false;
        }

        $item = [
            'name' => $anonymize ? 'Usługa udziału w turnusie sportowym – dane testowe' : 'Udział w turnusie '.$row->camp_name.' ('.$row->start_date.' – '.$row->end_date.')',
            'quantity' => '1',
            'unit' => 'usł.',
            'net' => self::amount((float)$row->net_amount),
            'vat' => self::amount((float)$row->vat_amount),
            'gross' => self::amount((float)$row->gross_amount),
            'vat_rate' => (float)$row->vat_rate,
        ];
        return compact('seller', 'buyer', 'item');
    }

    /** @return array{success:bool,xml:string,errors:array,seller:array,buyer:array,item:array} */
    public static function build(int $invoiceId): array {
        $row = self::invoice_row($invoiceId);
        if (!$row) return ['success'=>false, 'xml'=>'', 'errors'=>['Nie znaleziono faktury.'], 'seller'=>[], 'buyer'=>[], 'item'=>[]];
        $snapshot = self::snapshots($row);
        $errors = [];
        if (strlen($snapshot['seller']['nip']) !== 10) $errors[] = 'Konfiguracja KSeF wymaga 10-cyfrowego NIP kontekstu Organizatora.';
        if ($snapshot['seller']['address_l1'] === '') $errors[] = 'Brakuje pierwszej linii adresu Organizatora dla KSeF.';
        if ($snapshot['buyer']['name'] === '') {
            $errors[] = (int)($row->invoice_requested ?? 0) === 1
                ? 'Zaznaczono „Faktura: tak”, ale brakuje nazwy / imienia i nazwiska nabywcy w danych do faktury.'
                : 'Brakuje nazwy nabywcy.';
        }
        if ($snapshot['buyer']['address_l1'] === '') {
            $errors[] = (int)($row->invoice_requested ?? 0) === 1
                ? 'Zaznaczono „Faktura: tak”, ale brakuje ulicy i numeru w danych do faktury.'
                : 'Brakuje adresu nabywcy.';
        }
        if ((int)($row->invoice_requested ?? 0) === 1 && !$snapshot['buyer']['anonymized'] && $snapshot['buyer']['address_l2'] === '') {
            $errors[] = 'Zaznaczono „Faktura: tak”, ale brakuje kodu pocztowego lub miejscowości w danych do faktury.';
        }
        if ((float)$row->gross_amount <= 0) $errors[] = 'Kwota brutto faktury musi być większa od zera.';
        if ($errors) return ['success'=>false, 'xml'=>'', 'errors'=>$errors] + $snapshot;

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS(self::NS, 'Faktura');
        $dom->appendChild($root);

        $header = $dom->createElementNS(self::NS, 'Naglowek');
        $root->appendChild($header);
        $code = self::add($dom, $header, 'KodFormularza', 'FA');
        $code->setAttribute('kodSystemowy', BCS_KSeF_Config::FA3_SYSTEM_CODE);
        $code->setAttribute('wersjaSchemy', BCS_KSeF_Config::FA3_SCHEMA_VERSION);
        self::add($dom, $header, 'WariantFormularza', BCS_KSeF_Config::FA3_VARIANT);
        self::add($dom, $header, 'DataWytworzeniaFa', gmdate('Y-m-d\TH:i:s\Z'));
        self::add($dom, $header, 'SystemInfo', 'Basketmania Camp System '.(defined('BCS_VERSION') ? BCS_VERSION : 'dev'));

        $seller = $dom->createElementNS(self::NS, 'Podmiot1'); $root->appendChild($seller);
        $sellerId = $dom->createElementNS(self::NS, 'DaneIdentyfikacyjne'); $seller->appendChild($sellerId);
        self::add($dom, $sellerId, 'NIP', $snapshot['seller']['nip']);
        self::add($dom, $sellerId, 'Nazwa', $snapshot['seller']['name']);
        $sellerAddress = $dom->createElementNS(self::NS, 'Adres'); $seller->appendChild($sellerAddress);
        self::add($dom, $sellerAddress, 'KodKraju', $snapshot['seller']['country_code']);
        self::add($dom, $sellerAddress, 'AdresL1', $snapshot['seller']['address_l1']);
        if ($snapshot['seller']['address_l2'] !== '') self::add($dom, $sellerAddress, 'AdresL2', $snapshot['seller']['address_l2']);

        $buyer = $dom->createElementNS(self::NS, 'Podmiot2'); $root->appendChild($buyer);
        $buyerId = $dom->createElementNS(self::NS, 'DaneIdentyfikacyjne'); $buyer->appendChild($buyerId);
        if ($snapshot['buyer']['nip'] !== '') self::add($dom, $buyerId, 'NIP', $snapshot['buyer']['nip']);
        else self::add($dom, $buyerId, 'BrakID', '1');
        self::add($dom, $buyerId, 'Nazwa', $snapshot['buyer']['name']);
        $buyerAddress = $dom->createElementNS(self::NS, 'Adres'); $buyer->appendChild($buyerAddress);
        self::add($dom, $buyerAddress, 'KodKraju', $snapshot['buyer']['country_code']);
        self::add($dom, $buyerAddress, 'AdresL1', $snapshot['buyer']['address_l1']);
        if ($snapshot['buyer']['address_l2'] !== '') self::add($dom, $buyerAddress, 'AdresL2', $snapshot['buyer']['address_l2']);
        self::add($dom, $buyer, 'JST', '2');
        self::add($dom, $buyer, 'GV', '2');

        $fa = $dom->createElementNS(self::NS, 'Fa'); $root->appendChild($fa);
        self::add($dom, $fa, 'KodWaluty', 'PLN');
        self::add($dom, $fa, 'P_1', (string)$row->issue_date);
        self::add($dom, $fa, 'P_2', (string)$row->invoice_number);
        self::add($dom, $fa, 'P_6', (string)$row->issue_date);
        if ((float)$row->vat_rate > 0) {
            self::add($dom, $fa, 'P_13_1', self::amount((float)$row->net_amount));
            self::add($dom, $fa, 'P_14_1', self::amount((float)$row->vat_amount));
        } else {
            self::add($dom, $fa, 'P_13_7', self::amount((float)$row->net_amount));
        }
        self::add($dom, $fa, 'P_15', self::amount((float)$row->gross_amount));

        $notes = $dom->createElementNS(self::NS, 'Adnotacje'); $fa->appendChild($notes);
        foreach (['P_16','P_17','P_18','P_18A'] as $name) self::add($dom, $notes, $name, '2');
        $exemption = $dom->createElementNS(self::NS, 'Zwolnienie'); $notes->appendChild($exemption);
        if ((float)$row->vat_rate <= 0) {
            self::add($dom, $exemption, 'P_19', '1');
            self::add($dom, $exemption, 'P_19A', 'Zwolnienie zgodnie z konfiguracją podatkową Organizatora.');
        } else self::add($dom, $exemption, 'P_19N', '1');
        $newTransport = $dom->createElementNS(self::NS, 'NoweSrodkiTransportu'); $notes->appendChild($newTransport);
        self::add($dom, $newTransport, 'P_22N', '1');
        self::add($dom, $notes, 'P_23', '2');
        $margin = $dom->createElementNS(self::NS, 'PMarzy'); $notes->appendChild($margin);
        self::add($dom, $margin, 'P_PMarzyN', '1');
        self::add($dom, $fa, 'RodzajFaktury', 'VAT');

        $line = $dom->createElementNS(self::NS, 'FaWiersz'); $fa->appendChild($line);
        self::add($dom, $line, 'NrWierszaFa', '1');
        self::add($dom, $line, 'P_7', $snapshot['item']['name']);
        self::add($dom, $line, 'P_8A', $snapshot['item']['unit']);
        self::add($dom, $line, 'P_8B', $snapshot['item']['quantity']);
        self::add($dom, $line, 'P_9A', $snapshot['item']['net']);
        self::add($dom, $line, 'P_11', $snapshot['item']['net']);
        self::add($dom, $line, 'P_12', (float)$row->vat_rate > 0 ? rtrim(rtrim(number_format((float)$row->vat_rate, 2, '.', ''), '0'), '.') : 'zw');

        $payment = $dom->createElementNS(self::NS, 'Platnosc'); $fa->appendChild($payment);
        self::add($dom, $payment, 'Zaplacono', '1');
        self::add($dom, $payment, 'DataZaplaty', (string)$row->issue_date);
        self::add($dom, $payment, 'FormaPlatnosci', '6');

        $xml = (string)$dom->saveXML();
        $validation = self::validate($xml);
        return ['success'=>$validation['success'], 'xml'=>$xml, 'errors'=>$validation['errors']] + $snapshot;
    }

    /** @return array{success:bool,errors:array,xsd_checked:bool} */
    public static function validate(string $xml): array {
        $errors = [];
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        if (!$dom->loadXML($xml, LIBXML_NONET)) {
            foreach (libxml_get_errors() as $error) $errors[] = trim($error->message);
            libxml_clear_errors(); libxml_use_internal_errors($previous);
            return ['success'=>false, 'errors'=>$errors ?: ['Nieprawidłowy XML.'], 'xsd_checked'=>false];
        }
        $xpath = new DOMXPath($dom); $xpath->registerNamespace('fa', self::NS);
        $required = [
            '/fa:Faktura/fa:Naglowek/fa:KodFormularza',
            '/fa:Faktura/fa:Naglowek/fa:WariantFormularza',
            '/fa:Faktura/fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:NIP',
            '/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:Nazwa',
            '/fa:Faktura/fa:Fa/fa:P_2',
            '/fa:Faktura/fa:Fa/fa:P_15',
            '/fa:Faktura/fa:Fa/fa:FaWiersz',
        ];
        foreach ($required as $query) if ($xpath->query($query)->length !== 1) $errors[] = 'Brak wymaganego elementu: '.$query;

        $xsd = BCS_DIR.'assets/ksef/fa3.xsd';
        $xsdChecked = false;
        if (!$errors && is_readable($xsd)) {
            $xsdChecked = true;
            if (!$dom->schemaValidate($xsd)) {
                foreach (libxml_get_errors() as $error) $errors[] = trim($error->message);
            }
        }
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        return ['success'=>!$errors, 'errors'=>array_values(array_unique($errors)), 'xsd_checked'=>$xsdChecked];
    }

    /** @return array{success:bool,message:string,path?:string,hash?:string,errors?:array} */
    public static function prepare_and_save(int $invoiceId): array {
        global $wpdb;
        $result = self::build($invoiceId);
        if (!$result['success']) return ['success'=>false, 'message'=>'XML FA(3) nie przeszedł prewalidacji.', 'errors'=>$result['errors']];
        $row = self::invoice_row($invoiceId);
        if (!$row) return ['success'=>false, 'message'=>'Nie znaleziono faktury.'];
        $dir = BCS_Document_Engine::uploads_dir().'/registration-'.(int)$row->registration_id;
        if (!is_dir($dir)) wp_mkdir_p($dir);
        $name = '04-ksef-fa3-'.sanitize_file_name(str_replace('/', '-', (string)$row->invoice_number)).'.xml';
        $path = $dir.'/'.$name;
        if (file_put_contents($path, $result['xml'], LOCK_EX) === false) return ['success'=>false, 'message'=>'Nie udało się zapisać pliku XML.'];
        $hash = hash('sha256', $result['xml']);
        $wpdb->update(BCS_DB::table('invoices'), [
            'ksef_status'=>'xml_ready',
            'ksef_schema_version'=>BCS_KSeF_Config::FA3_SCHEMA_VERSION,
            'ksef_xml_path'=>$path,
            'ksef_xml_hash'=>$hash,
            'ksef_error_code'=>null,
            'ksef_error_message'=>null,
            'seller_snapshot'=>wp_json_encode($result['seller'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'buyer_snapshot'=>wp_json_encode($result['buyer'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'invoice_items_snapshot'=>wp_json_encode([$result['item']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], ['id'=>$invoiceId]);
        self::operation($invoiceId, (int)$row->organizer_id, 'Przygotowanie XML FA(3)', 'success', null, [
            'sha256'=>$hash,
            'schema'=>BCS_KSeF_Config::FA3_SCHEMA_VERSION,
            'buyer_source'=>(string)($result['buyer']['source'] ?? ''),
            'invoice_requested'=>(int)($row->invoice_requested ?? 0),
        ]);
        return ['success'=>true, 'message'=>'Przygotowano testowy XML FA(3). Dokument nie został jeszcze wysłany do KSeF.', 'path'=>$path, 'hash'=>$hash];
    }

    public static function operation(int $invoiceId, int $organizerId, string $type, string $status, ?string $reference = null, array $response = [], string $errorCode = '', string $errorMessage = ''): void {
        global $wpdb;
        $now = BCS_Utils::now();
        $wpdb->insert(BCS_DB::table('ksef_operations'), [
            'invoice_id'=>$invoiceId ?: null,
            'organizer_id'=>$organizerId,
            'operation_type'=>$type,
            'status'=>$status,
            'reference_number'=>$reference,
            'response_data'=>$response ? wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'error_code'=>$errorCode ?: null,
            'error_message'=>$errorMessage ?: null,
            'created_at'=>$now,
            'updated_at'=>$now,
        ]);
    }
}
