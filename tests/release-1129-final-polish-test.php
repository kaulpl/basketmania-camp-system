<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$plugin=(string)file_get_contents($root.'/basketmania-camp-system.php');
$css=(string)file_get_contents($root.'/assets/css/qualification.css');
$documents=(string)file_get_contents($root.'/includes/class-bcs-documents.php');
$qualification=(string)file_get_contents($root.'/includes/class-bcs-qualification.php');
$invoice=(string)file_get_contents($root.'/includes/class-bcs-release-088.php');
$failures=[];
$check=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};

$check(preg_match('/Version: ([0-9.]+)/',$plugin,$header)===1&&preg_match("/BCS_VERSION', '([0-9.]+)'/",$plugin,$constant)===1&&$header[1]===$constant[1]&&version_compare($header[1],'1.12.9','>='),'Wersja 1.12.9 lub nowsza powinna być spójna.');
$check(str_contains($qualification,'bcs-check bcs-check-left bcs-span'),'Przełącznik samodzielnej opieki powinien używać standardowego, lewego układu formularza.');
$check(!str_contains($css,'bcs-sole-guardian'),'Arkusz kwalifikacji nie powinien nadpisywać standardowego wyglądu checkboxa.');
$check(str_contains($invoice,'$participant')&&str_contains($invoice,'$r->camp_name')&&str_contains($invoice,"wp_date('d.m.Y'"),'Domyślny opis faktury powinien łączyć uczestnika, nazwę turnusu i daty.');
$check(str_contains($qualification,'public static function signed_document_html'),'Generator kompletu powinien używać podpisanej karty z dowodem podpisów.');
$agreementPos=strpos($documents,'2. Podpisana umowa');
$formPos=strpos($documents,'3. Formularz zgłoszeniowy');
$check($agreementPos!==false&&$formPos!==false&&$agreementPos<$formPos,'Umowa powinna znajdować się przed formularzem w komplecie dokumentów.');
$check(str_contains($documents,'BCS_Qualification::signed_document_html'),'Karta kwalifikacyjna powinna otwierać kompletny PDF.');
$check(str_contains($documents,'$filename=self::clean_name(')&&str_contains($documents,".'_'.trim((string)\$r->child_first_name)")&&str_contains($documents,".'_'.trim((string)\$r->child_last_name).'.pdf'"),'Nazwa pliku powinna zawierać numer zgłoszenia, rok, imię i nazwisko.');

if($failures){fwrite(STDERR,"Release 1.12.9 final polish test FAILED:\n- ".implode("\n- ",$failures)."\n");exit(1);}
echo "Release 1.12.9 final polish checks passed.\n";
