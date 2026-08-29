<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$plugin=(string)file_get_contents($root.'/basketmania-camp-system.php');
$js=(string)file_get_contents($root.'/assets/js/shirt-size-suggestion-092.js');
$failures=[];
$check=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};

$check(str_contains($plugin,'Version: 1.12.11')&&str_contains($plugin,"BCS_VERSION', '1.12.11'"),'Wersja 1.12.11 powinna być spójna.');
$check(str_contains($js,"hint.id = 'bcs-shirt-size-suggestion'"),'Podpowiedź powinna być jednym polem z unikalnym identyfikatorem.');
$check(str_contains($js,"document.getElementById('bcs-shirt-size-suggestion')"),'Aktualizacja powinna zawsze odnajdywać istniejące pole.');
$check(substr_count($js,"document.createElement('output')")===1,'Pole podpowiedzi może być tworzone tylko w jednym miejscu.');
$check(str_contains($js,'window.BCSShirtSuggestionController092'),'Ponowne załadowanie skryptu nie może rejestrować drugiego kontrolera.');
$check(str_contains($js,"String(element.textContent || '').trim().startsWith(hintPrefix)"),'Sprzątanie powinno rozpoznawać stare podpowiedzi także po treści.');
$check(str_contains($js,"if (element !== keep && isSuggestion(element)) element.remove()"),'Wszystkie elementy poza stałym polem powinny być usuwane.');
$check(str_contains($js,"if (isSuggestion(node) && node.id !== 'bcs-shirt-size-suggestion')"),'Obserwator powinien natychmiast usuwać podpowiedzi dokładane przez starszy kod.');

if($failures){fwrite(STDERR,"Release 1.12.11 single suggestion test FAILED:\n- ".implode("\n- ",$failures)."\n");exit(1);}
echo "Release 1.12.11 single shirt suggestion checks passed.\n";
