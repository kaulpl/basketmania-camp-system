<?php
declare(strict_types=1);
$root=dirname(__DIR__);$plugin=(string)file_get_contents($root.'/basketmania-camp-system.php');$documents=(string)file_get_contents($root.'/includes/class-bcs-documents.php');$crm=(string)file_get_contents($root.'/includes/class-bcs-crm.php');$failures=[];
$check=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};
$check(preg_match('/Version: ([0-9.]+)/',$plugin,$header)===1&&preg_match("/BCS_VERSION', '([0-9.]+)'/",$plugin,$constant)===1&&$header[1]===$constant[1]&&version_compare($header[1],'1.12.14','>='),'Wersja 1.12.14 lub nowsza powinna być spójna.');
$card=strpos($documents,'BCS_Qualification::signed_document_html');$form=strpos($documents,'self::data_table($r)');$agreement=strpos($documents,"</div>'.$signed_html");
$check($form!==false&&$agreement!==false&&$form<$agreement,'Po karcie kwalifikacyjnej powinien wystąpić formularz osobowy, a następnie umowa.');
$check($card!==false,'Komplet powinien zaczynać się od podpisanej karty kwalifikacyjnej z dowodem podpisów.');
$check(!str_contains($documents,'2. Formularz osobowy')&&!str_contains($documents,'3. Podpisana umowa'),'Komplet nie może dodawać numerowanych nagłówków między dokumentami.');
$check(substr_count($documents,'style="page-break-before:always"')>=2,'Każdy kolejny dokument powinien zaczynać się od nowej strony.');
$completeMethod=substr($documents,strpos($documents,'public static function complete_pdf'),strpos($documents,'private static function data_table')-strpos($documents,'public static function complete_pdf'));
$check(!str_contains($completeMethod,"BCS_DB::table('invoices')")&&!str_contains($completeMethod,'Płatność i dokument sprzedaży'),'Komplet nie powinien zawierać faktury ani podsumowania płatności.');
$check(str_contains($crm,"BCS_Qualification::invoice_signatures_complete((int)\$r->id)"),'Przycisk powinien wymagać podpisanej karty kwalifikacyjnej.');
$check(!str_contains(substr($crm,strpos($crm,'private static function documents_panel'),2000),"invoice_status==='sent'"),'Przycisk kompletu nie powinien czekać na wygenerowanie faktury.');
$check(str_contains($crm,'podpisaniu karty kwalifikacyjnej przez wszystkie wymagane osoby'),'Panel powinien jasno opisywać warunki dostępności kompletu.');
if($failures){fwrite(STDERR,"Release 1.12.14 complete documents test FAILED:\n- ".implode("\n- ",$failures)."\n");exit(1);}echo "Release 1.12.14 complete documents order checks passed.\n";
