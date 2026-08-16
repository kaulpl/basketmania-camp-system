<?php
if (!defined('ABSPATH')) exit;

/** Niezależne dokumenty KSeF TEST, które nie trafiają do modułu Faktury. */
final class BCS_KSeF_Test_Document_Service {
    private static function table(): string { return BCS_DB::table('ksef_test_documents'); }

    private static function registration(int $registrationId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT r.*, c.name camp_name,c.start_date,c.end_date,c.organizer_id, '
            .'o.name organizer_name,o.legal_form organizer_legal_form,o.nip organizer_nip,o.ksef_context_nip,o.ksef_country_code,o.ksef_address_l1,o.ksef_address_l2, '
            .'o.ksef_enabled,o.ksef_token_ciphertext,o.ksef_token_nonce,o.bank_account '
            .'FROM '.BCS_DB::table('registrations').' r '
            .'JOIN '.BCS_DB::table('camps').' c ON c.id=r.camp_id '
            .'JOIN '.BCS_DB::table('organizers').' o ON o.id=c.organizer_id WHERE r.id=%d',
            $registrationId
        )) ?: null;
    }

    public static function document(int $registrationId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE registration_id=%d', $registrationId)) ?: null;
    }

    private static function paid(object $r): bool {
        return (string)$r->status !== 'cancelled' && (float)$r->total_amount > 0 && (float)$r->paid_amount >= (float)$r->total_amount;
    }

    public static function prepare(int $registrationId): array {
        global $wpdb;
        $r = self::registration($registrationId);
        if (!$r) return ['success'=>false,'message'=>'Nie znaleziono zgłoszenia.'];
        if (!self::paid($r)) return ['success'=>false,'message'=>'Testową fakturę KSeF można utworzyć wyłącznie dla opłaconego zgłoszenia.'];
        if ((int)$r->ksef_enabled !== 1 || !BCS_KSeF_Secret::configured($r, 'test')) return ['success'=>false,'message'=>'Organizator nie ma aktywnej konfiguracji i tokenu KSeF TEST.'];

        $existing = self::document($registrationId);
        if ($existing && (string)$existing->status === 'accepted') return ['success'=>true,'message'=>'Testowa faktura została już przyjęta przez KSeF TEST.','status'=>'accepted','ksef_number'=>(string)$existing->ksef_number];

        $settings = get_option('bcs_settings', []);
        $vatRate = (float)($settings['invoice_vat_rate'] ?? 0);
        $gross = (float)$r->paid_amount;
        $net = $vatRate > 0 ? $gross/(1+$vatRate/100) : $gross;
        $vat = $gross-$net;
        $year = (int)BCS_Utils::today('Y');
        $number = 'TEST-KSEF/'.(int)$r->organizer_id.'/'.$year.'/'.str_pad((string)$registrationId, 6, '0', STR_PAD_LEFT);
        $issueDate = BCS_Utils::today('Y-m-d');
        $xml = self::build_xml($r, $number, $issueDate, $net, $vat, $gross, $vatRate);
        $validation = BCS_KSeF_FA3::validate($xml);
        if (!$validation['success']) return ['success'=>false,'message'=>'Testowy XML FA(3) nie przeszedł walidacji: '.implode(' ', $validation['errors'])];

        $dir = BCS_Document_Engine::uploads_dir().'/registration-'.$registrationId;
        if (!is_dir($dir)) wp_mkdir_p($dir);
        $path = $dir.'/ksef-test-'.sanitize_file_name(str_replace('/', '-', $number)).'.xml';
        if (file_put_contents($path, $xml, LOCK_EX) === false) return ['success'=>false,'message'=>'Nie udało się zapisać testowego XML.'];
        $now = BCS_Utils::now();
        $data = [
            'registration_id'=>$registrationId,'organizer_id'=>(int)$r->organizer_id,'invoice_number'=>$number,'issue_date'=>$issueDate,
            'gross_amount'=>$gross,'net_amount'=>$net,'vat_amount'=>$vat,'vat_rate'=>$vatRate,'status'=>'xml_ready','xml_path'=>$path,'xml_hash'=>hash('sha256',$xml),
            'error_code'=>null,'error_message'=>null,'updated_at'=>$now,
        ];
        if ($existing) $wpdb->update(self::table(), $data, ['id'=>(int)$existing->id]);
        else { $data['created_at']=$now; $wpdb->insert(self::table(), $data); }
        return ['success'=>true,'message'=>'Wygenerowano dodatkową testową fakturę KSeF. Nie ma ona wpływu na właściwą fakturę zgłoszenia.','status'=>'xml_ready'];
    }

    private static function build_xml(object $r, string $number, string $date, float $net, float $vat, float $gross, float $vatRate): string {
        $ns = BCS_KSeF_Config::FA3_NAMESPACE;
        $dom = new DOMDocument('1.0','UTF-8'); $dom->formatOutput=true;
        $add = static function(DOMElement $parent,string $name,string $value) use ($dom,$ns): DOMElement { $n=$dom->createElementNS($ns,$name);$n->appendChild($dom->createTextNode($value));$parent->appendChild($n);return $n; };
        $root=$dom->createElementNS($ns,'Faktura');$dom->appendChild($root);
        $head=$dom->createElementNS($ns,'Naglowek');$root->appendChild($head);$code=$add($head,'KodFormularza','FA');$code->setAttribute('kodSystemowy',BCS_KSeF_Config::FA3_SYSTEM_CODE);$code->setAttribute('wersjaSchemy',BCS_KSeF_Config::FA3_SCHEMA_VERSION);$add($head,'WariantFormularza','3');$add($head,'DataWytworzeniaFa',gmdate('Y-m-d\TH:i:s\Z'));$add($head,'SystemInfo','Basketmania Camp System '.BCS_VERSION.' – KSeF TEST');
        $p1=$dom->createElementNS($ns,'Podmiot1');$root->appendChild($p1);$id1=$dom->createElementNS($ns,'DaneIdentyfikacyjne');$p1->appendChild($id1);$add($id1,'NIP',preg_replace('/\D+/','',(string)($r->ksef_context_nip?:$r->organizer_nip))?:'');$add($id1,'Nazwa','Sprzedawca Testowy Basketmania');$a1=$dom->createElementNS($ns,'Adres');$p1->appendChild($a1);$add($a1,'KodKraju','PL');$add($a1,'AdresL1','ul. Testowa 1');$add($a1,'AdresL2','00-001 Miasto Testowe');
        $p2=$dom->createElementNS($ns,'Podmiot2');$root->appendChild($p2);$id2=$dom->createElementNS($ns,'DaneIdentyfikacyjne');$p2->appendChild($id2);$add($id2,'BrakID','1');$add($id2,'Nazwa','Nabywca Testowy');$a2=$dom->createElementNS($ns,'Adres');$p2->appendChild($a2);$add($a2,'KodKraju','PL');$add($a2,'AdresL1','ul. Przykładowa 2');$add($a2,'AdresL2','00-002 Miasto Testowe');$add($p2,'JST','2');$add($p2,'GV','2');
        $fa=$dom->createElementNS($ns,'Fa');$root->appendChild($fa);$add($fa,'KodWaluty','PLN');$add($fa,'P_1',$date);$add($fa,'P_2',$number);$add($fa,'P_6',$date);
        if($vatRate>0){$add($fa,'P_13_1',number_format($net,2,'.',''));$add($fa,'P_14_1',number_format($vat,2,'.',''));}else{$add($fa,'P_13_7',number_format($net,2,'.',''));}$add($fa,'P_15',number_format($gross,2,'.',''));
        $notes=$dom->createElementNS($ns,'Adnotacje');$fa->appendChild($notes);foreach(['P_16','P_17','P_18','P_18A'] as $n)$add($notes,$n,'2');$ex=$dom->createElementNS($ns,'Zwolnienie');$notes->appendChild($ex);if($vatRate<=0){$add($ex,'P_19','1');$add($ex,'P_19A','Zwolnienie zgodnie z konfiguracją podatkową Organizatora.');}else{$add($ex,'P_19N','1');}$nst=$dom->createElementNS($ns,'NoweSrodkiTransportu');$notes->appendChild($nst);$add($nst,'P_22N','1');$add($notes,'P_23','2');$margin=$dom->createElementNS($ns,'PMarzy');$notes->appendChild($margin);$add($margin,'P_PMarzyN','1');$add($fa,'RodzajFaktury','VAT');
        $line=$dom->createElementNS($ns,'FaWiersz');$fa->appendChild($line);$add($line,'NrWierszaFa','1');$add($line,'P_7','Usługa udziału w turnusie sportowym – DANE TESTOWE');$add($line,'P_8A','usł.');$add($line,'P_8B','1');$add($line,'P_9A',number_format($net,2,'.',''));$add($line,'P_11',number_format($net,2,'.',''));$add($line,'P_12',$vatRate>0?rtrim(rtrim(number_format($vatRate,2,'.',''),'0'),'.'):'zw');
        $payment=$dom->createElementNS($ns,'Platnosc');$fa->appendChild($payment);$add($payment,'Zaplacono','1');$add($payment,'DataZaplaty',$date);$add($payment,'FormaPlatnosci','6');
        return (string)$dom->saveXML();
    }

    public static function send(int $registrationId): array {
        global $wpdb;
        $r=self::registration($registrationId);if(!$r)return ['success'=>false,'message'=>'Nie znaleziono zgłoszenia.'];
        $prepared=self::prepare($registrationId);if(empty($prepared['success']))return $prepared;
        $doc=self::document($registrationId);if(!$doc)return ['success'=>false,'message'=>'Nie znaleziono dokumentu testowego.'];
        if((string)$doc->status==='accepted')return ['success'=>true,'message'=>'Testowa faktura jest już przyjęta w KSeF TEST.','status'=>'accepted','ksef_number'=>(string)$doc->ksef_number];
        $xml=is_file((string)$doc->xml_path)?(string)file_get_contents((string)$doc->xml_path):'';if($xml==='')return ['success'=>false,'message'=>'Brak testowego XML.'];
        $auth=BCS_KSeF_Auth::authenticate($r,'test');if(empty($auth['success']))return self::fail($doc,'Uwierzytelnienie KSeF TEST nie powiodło się: '.(string)$auth['message'],'AUTH_FAILED');
        try{
            $client=$auth['client'];$access=(string)$auth['access_token'];$cert=BCS_KSeF_Crypto::select_public_key((array)$auth['certificates'],'SymmetricKeyEncryption');$material=BCS_KSeF_Crypto::symmetric_material();$encryptedKey=BCS_KSeF_Crypto::rsa_oaep_sha256_encrypt($material['key'],$cert['certificate']);
            $open=$client->open_online_session(['formCode'=>['systemCode'=>BCS_KSeF_Config::FA3_SYSTEM_CODE,'schemaVersion'=>BCS_KSeF_Config::FA3_SCHEMA_VERSION,'value'=>'FA'],'encryption'=>['encryptedSymmetricKey'=>base64_encode($encryptedKey),'initializationVector'=>base64_encode($material['iv']),'publicKeyId'=>$cert['publicKeyId']]],$access);if(!$open['success'])throw new RuntimeException($open['message']);$session=(string)($open['data']['referenceNumber']??'');if($session==='')throw new RuntimeException('Brak referencji sesji.');
            $encrypted=BCS_KSeF_Crypto::aes_256_cbc_encrypt($xml,$material['key'],$material['iv']);$sent=$client->send_online_invoice($session,['invoiceHash'=>BCS_KSeF_Crypto::sha256_base64($xml),'invoiceSize'=>strlen($xml),'encryptedInvoiceHash'=>BCS_KSeF_Crypto::sha256_base64($encrypted),'encryptedInvoiceSize'=>strlen($encrypted),'encryptedInvoiceContent'=>base64_encode($encrypted),'offlineMode'=>false],$access);if(!$sent['success'])throw new RuntimeException($sent['message']);$reference=(string)($sent['data']['referenceNumber']??'');if($reference==='')throw new RuntimeException('Brak referencji faktury.');
            $wpdb->update(self::table(),['status'=>'processing','session_reference'=>$session,'invoice_reference'=>$reference,'sent_at'=>BCS_Utils::now(),'last_checked_at'=>BCS_Utils::now(),'updated_at'=>BCS_Utils::now()],['id'=>(int)$doc->id]);$client->close_online_session($session,$access);
            for($i=0;$i<8;$i++){if($i>0)usleep(500000);$result=self::apply_status($doc,$client->session_invoice_status($session,$reference,$access));if(($result['status']??'')!=='processing')return $result;}
            return ['success'=>true,'message'=>'Testowa faktura została wysłana do KSeF TEST i nadal jest przetwarzana.','status'=>'processing'];
        }catch(Throwable $e){return self::fail($doc,$e->getMessage(),'SEND_FAILED');}
    }

    public static function refresh(int $registrationId): array {
        $r=self::registration($registrationId);$doc=self::document($registrationId);if(!$r||!$doc)return ['success'=>false,'message'=>'Brak dokumentu testowego.'];
        if((string)$doc->status==='accepted')return ['success'=>true,'message'=>'Testowa faktura jest przyjęta w KSeF TEST.','status'=>'accepted','ksef_number'=>(string)$doc->ksef_number];
        if(empty($doc->session_reference)||empty($doc->invoice_reference))return ['success'=>false,'message'=>'Brak referencji sesji testowej.'];
        $auth=BCS_KSeF_Auth::authenticate($r,'test');if(empty($auth['success']))return ['success'=>false,'message'=>(string)$auth['message']];
        return self::apply_status($doc,$auth['client']->session_invoice_status((string)$doc->session_reference,(string)$doc->invoice_reference,(string)$auth['access_token']));
    }

    private static function apply_status(object $doc,array $response): array {
        global $wpdb;if(!$response['success'])return self::fail($doc,'Nie udało się pobrać statusu: '.$response['message'],'STATUS_FAILED');$data=$response['data'];$code=(int)($data['status']['code']??0);$description=(string)($data['status']['description']??'');$number=(string)($data['ksefNumber']??'');if($code===440&&$number==='')$number=(string)($data['status']['extensions']['originalKsefNumber']??$data['extensions']['originalKsefNumber']??'');
        if($code===200||($code===440&&$number!=='')){$wpdb->update(self::table(),['status'=>'accepted','ksef_number'=>$number,'status_code'=>(string)$code,'status_description'=>$description,'accepted_at'=>BCS_Utils::now(),'last_checked_at'=>BCS_Utils::now(),'error_code'=>null,'error_message'=>null,'updated_at'=>BCS_Utils::now()],['id'=>(int)$doc->id]);return ['success'=>true,'message'=>'Testowa faktura została przyjęta w KSeF TEST. Numer KSeF: '.$number,'status'=>'accepted','ksef_number'=>$number];}
        if($code>=400)return self::fail($doc,'KSeF TEST odrzucił fakturę: '.$description.' ('.$code.').',(string)$code,'rejected');$wpdb->update(self::table(),['status'=>'processing','status_code'=>(string)$code,'status_description'=>$description,'last_checked_at'=>BCS_Utils::now(),'updated_at'=>BCS_Utils::now()],['id'=>(int)$doc->id]);return ['success'=>true,'message'=>'KSeF TEST nadal przetwarza fakturę.','status'=>'processing'];
    }

    private static function fail(object $doc,string $message,string $code,string $status='connection_error'): array {global $wpdb;$wpdb->update(self::table(),['status'=>$status,'error_code'=>$code,'error_message'=>$message,'last_checked_at'=>BCS_Utils::now(),'updated_at'=>BCS_Utils::now()],['id'=>(int)$doc->id]);return ['success'=>false,'message'=>$message,'status'=>$status];}
}
