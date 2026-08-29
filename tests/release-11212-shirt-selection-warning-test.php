<?php
declare(strict_types=1);
$root=dirname(__DIR__);$plugin=(string)file_get_contents($root.'/basketmania-camp-system.php');$js=(string)file_get_contents($root.'/assets/js/shirt-size-suggestion-092.js');$failures=[];
$check=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};
$check(preg_match('/Version: ([0-9.]+)/',$plugin,$header)===1&&preg_match("/BCS_VERSION', '([0-9.]+)'/",$plugin,$constant)===1&&$header[1]===$constant[1]&&version_compare($header[1],'1.12.12','>='),'Wersja 1.12.12 lub nowsza powinna być spójna.');
$check(!str_contains($js,"document.createElement('output')"),'Formularz nie powinien tworzyć widocznego napisu z podpowiedzią.');
$check(str_contains($js,"heightChanged || current === '' || current === previous"),'Po wpisaniu wzrostu sugerowany rozmiar powinien zostać wybrany automatycznie.');
$check(str_contains($js,"select.value = suggested")&&str_contains($js,"select.dispatchEvent(new Event('change'"),'Automatyczny wybór powinien aktualizować rzeczywiste pole formularza.');
$check(str_contains($js,"current !== suggested")&&str_contains($js,'⚠ ${warningPrefix} ${suggested}'),'Ręczny wybór innego rozmiaru powinien pokazać żółte ostrzeżenie z sugestią.');
$check(str_contains($js,"background:'#fef9c3'")&&str_contains($js,"border:'1px solid #facc15'"),'Ostrzeżenie powinno mieć żółty styl.');
$check(str_contains($js,"warning.style.display = differs ? 'block' : 'none'"),'Ostrzeżenie powinno znikać po powrocie do sugerowanego rozmiaru.');
$check(substr_count($js,"document.createElement('small')")===1&&str_contains($js,"form.querySelector('[data-bcs-shirt-warning-092]')"),'W formularzu może istnieć tylko jedno pole ostrzeżenia.');
if($failures){fwrite(STDERR,"Release 1.12.12 shirt selection warning test FAILED:\n- ".implode("\n- ",$failures)."\n");exit(1);}echo "Release 1.12.12 shirt selection warning checks passed.\n";
