<?php
declare(strict_types=1);
$root=dirname(__DIR__);$plugin=(string)file_get_contents($root.'/basketmania-camp-system.php');$crm=(string)file_get_contents($root.'/includes/class-bcs-crm.php');$qualification=(string)file_get_contents($root.'/includes/class-bcs-qualification.php');$failures=[];
$check=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};
$check(str_contains($plugin,'Version: 1.12.18')&&str_contains($plugin,"BCS_VERSION', '1.12.18'"),'Wersja 1.12.18 powinna być spójna.');
$documents=substr($crm,strpos($crm,'private static function documents_panel'),strpos($crm,'private static function mail_correspondence_panel')-strpos($crm,'private static function documents_panel'));
foreach(['Pobierz formularz osobowy PDF','Pobierz podpisaną umowę PDF','Pobierz komplet dokumentów PDF','Pobierz kartę kwalifikacyjną PDF'] as $label)$check(str_contains($documents,$label),'Brak przycisku: '.$label);
$check(!str_contains($documents,'BCS_Qualification::admin_panel'),'Dane i statusy karty nie mogą pozostawać w sekcji Dokumenty PDF.');
$check(!str_contains($documents,'podpisaniu karty kwalifikacyjnej przez wszystkie wymagane osoby'),'Sekcja Dokumenty PDF nie powinna opisywać procesu podpisów.');
$check(str_contains($crm,'echo BCS_Qualification::admin_panel((int)$r->id);'),'Obsługa karty powinna być renderowana jako osobna sekcja zgłoszenia.');
$check(str_contains($qualification,'<section class="bcs-panel bcs-qualification-panel"><h2>Dane i obsługa karty kwalifikacyjnej</h2>'),'Panel karty powinien być samodzielną sekcją.');
$check(str_contains($qualification,'public static function download_url(int $id)'),'Dokumenty PDF powinny korzystać z bezpiecznego adresu pobierania podpisanej karty.');
if($failures){fwrite(STDERR,"Release 1.12.18 registration documents sections test FAILED:\n- ".implode("\n- ",$failures)."\n");exit(1);}echo "Release 1.12.18 registration documents sections checks passed.\n";
