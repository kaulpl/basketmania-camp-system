<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$plugin=(string)file_get_contents($root.'/basketmania-camp-system.php');
$js=(string)file_get_contents($root.'/assets/js/shirt-size-suggestion-092.js');
$failures=[];
$check=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};

$check(preg_match('/Version: ([0-9.]+)/',$plugin,$header)===1&&preg_match("/BCS_VERSION', '([0-9.]+)'/",$plugin,$constant)===1&&$header[1]===$constant[1]&&version_compare($header[1],'1.12.11','>='),'Wersja 1.12.11 lub nowsza powinna być spójna.');
$check(str_contains($js,"form.querySelector('[data-bcs-shirt-warning-092]')"),'Ostrzeżenie powinno być jednym polem w formularzu.');
$check(substr_count($js,"document.createElement('small')")===1,'Ostrzeżenie może być tworzone tylko w jednym miejscu.');
$check(str_contains($js,'window.BCSShirtSuggestionController092'),'Ponowne załadowanie skryptu nie może rejestrować drugiego kontrolera.');
$check(str_contains($js,"String(element.textContent || '').trim().startsWith(legacyPrefix)"),'Sprzątanie powinno rozpoznawać stare podpowiedzi także po treści.');
$check(str_contains($js,"if (isOldHint(element)) element.remove()"),'Wszystkie stare podpowiedzi powinny być usuwane.');
$check(str_contains($js,"if (isOldHint(node))"),'Obserwator powinien natychmiast usuwać podpowiedzi dokładane przez starszy kod.');

if($failures){fwrite(STDERR,"Release 1.12.11 single suggestion test FAILED:\n- ".implode("\n- ",$failures)."\n");exit(1);}
echo "Release 1.12.11 single shirt suggestion checks passed.\n";
