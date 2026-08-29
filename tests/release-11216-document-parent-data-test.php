<?php
declare(strict_types=1);
$root=dirname(__DIR__);$plugin=(string)file_get_contents($root.'/basketmania-camp-system.php');$documents=(string)file_get_contents($root.'/includes/class-bcs-documents.php');$agreements=(string)file_get_contents($root.'/includes/class-bcs-agreements.php');$r050=(string)file_get_contents($root.'/includes/class-bcs-release-050.php');$r051=(string)file_get_contents($root.'/includes/class-bcs-release-051.php');$migration=(string)file_get_contents($root.'/includes/class-bcs-release-11216.php');$template=(string)file_get_contents($root.'/templates/agreement-default.html');$failures=[];
$check=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};
$check(str_contains($plugin,'Version: 1.12.16')&&str_contains($plugin,"BCS_VERSION', '1.12.16'"),'Wersja 1.12.16 powinna być spójna.');
$check(str_contains($documents,'second_parent_first_name')&&str_contains($documents,'second_parent_email')&&str_contains($documents,'second_parent_phone'),'Formularz PDF powinien pobierać komplet danych drugiego rodzica z nowych pól bazy.');
$check(str_contains($documents,'bcs-personal-form')&&str_contains($documents,'Rodzic / opiekun prawny 1')&&str_contains($documents,'Zdrowie, żywienie i szczepienia'),'Formularz PDF powinien mieć pogrupowany, spójny układ tabel.');
$check(!str_contains($documents,'2. Formularz osobowy')&&!str_contains($documents,'3. Podpisana umowa'),'Komplet PDF nie może zawierać technicznych numerowanych nagłówków dokumentów.');
foreach([$agreements,$r050,$r051] as $renderer)$check(str_contains($renderer,'second_parent_phone')&&str_contains($renderer,'{{SECOND_PARENT_PHONE}}'),'Każdy aktywny renderer umowy powinien korzystać z danych drugiego rodzica.');
$check(str_contains($template,'{{SECOND_PARENT_NAME}}')&&str_contains($template,'{{SECOND_PARENT_EMAIL}}')&&str_contains($template,'{{SECOND_PARENT_PHONE}}'),'Domyślny wzór umowy powinien prezentować dane obojga rodziców.');
$check(str_contains($migration,'transform_agreement_template')&&str_contains($plugin,'BCS_Release_11216::init()'),'Zapisany edytowalny wzór umowy powinien zostać bezpiecznie uaktualniony.');
if($failures){fwrite(STDERR,"Release 1.12.16 document parent data test FAILED:\n- ".implode("\n- ",$failures)."\n");exit(1);}echo "Release 1.12.16 document parent data checks passed.\n";
