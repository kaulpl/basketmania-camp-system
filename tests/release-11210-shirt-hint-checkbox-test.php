<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$plugin=(string)file_get_contents($root.'/basketmania-camp-system.php');
$js=(string)file_get_contents($root.'/assets/js/shirt-size-suggestion-092.js');
$qualificationJs=(string)file_get_contents($root.'/assets/js/qualification.js');
$qualification=(string)file_get_contents($root.'/includes/class-bcs-qualification.php');
$css=(string)file_get_contents($root.'/assets/css/qualification.css');
$failures=[];
$check=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};

$check(preg_match('/Version: ([0-9.]+)/',$plugin,$header)===1&&preg_match("/BCS_VERSION', '([0-9.]+)'/",$plugin,$constant)===1&&$header[1]===$constant[1]&&version_compare($header[1],'1.12.10','>='),'Wersja 1.12.10 lub nowsza powinna być spójna.');
$check(str_contains($js,"[data-bcs-shirt-hint-092],[data-bcs-shirt-hint092]"),'Aktualizacja powinna znaleźć i usunąć także błędne podpowiedzi utworzone wcześniej.');
$check(!str_contains($js,"setAttribute('data-bcs-shirt-hint-092', '1')"),'Skrypt nie powinien już tworzyć stałej podpowiedzi pod polem.');
$check(substr_count($js,"createElement('small')")===1,'Skrypt powinien mieć tylko jedno miejsce tworzenia ostrzeżenia.');
$check(str_contains($js,'if (isOldHint(element)) element.remove()'),'Wszystkie stare podpowiedzi powinny zostać usunięte.');
$check(str_contains($qualification,'bcs-check bcs-check-left bcs-sole-guardian')&&!str_contains($qualification,'role="switch" name="sole_guardian"'),'Pole samodzielnej opieki powinno używać standardowego checkboxa formularza.');
$check(str_contains($qualificationJs,"toggle.removeAttribute('role')")&&str_contains($qualificationJs,"classList.remove('bcs-sole-switch')"),'Skrypt powinien naprawiać również formularze wyrenderowane ze starszymi klasami.');
$check(!str_contains($css,'.bcs-sole-switch input{appearance:none'),'Arkusz nie może zmieniać checkboxa w pomniejszony slider.');
$check(str_contains($css,'-webkit-appearance:checkbox!important')&&str_contains($css,'width:auto!important'),'Checkbox powinien zachować natywny, standardowy wygląd.');

if($failures){fwrite(STDERR,"Release 1.12.10 regression test FAILED:\n- ".implode("\n- ",$failures)."\n");exit(1);}
echo "Release 1.12.10 shirt hint and checkbox checks passed.\n";
