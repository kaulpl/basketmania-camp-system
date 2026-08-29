<?php
declare(strict_types=1);
$root=dirname(__DIR__);$plugin=(string)file_get_contents($root.'/basketmania-camp-system.php');$pdf=(string)file_get_contents($root.'/includes/class-bcs-pdf.php');$documents=(string)file_get_contents($root.'/includes/class-bcs-documents.php');$failures=[];
$check=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};
$check(str_contains($plugin,'Version: 1.12.15')&&str_contains($plugin,"BCS_VERSION', '1.12.15'"),'Wersja 1.12.15 powinna być spójna.');
$check(str_contains($pdf,'bool $preserve_layout=false')&&str_contains($pdf,"BCS_Agreement_PDF_V2::is_agreement_document(\$html, \$title)\n                && !\$preserve_layout"),'Generator powinien umieć wyłączyć renderer pojedynczej umowy dla kompletu dokumentów.');
$check(str_contains($pdf,'} elseif (!$preserve_layout) {')&&str_contains($pdf,'if (!$useAgreementV2 && !$preserve_layout)'),'Tryb kompletnego PDF nie może uruchamiać historycznych dekoratorów ani renderera umowy.');
$completeMethod=substr($documents,strpos($documents,'public static function complete_pdf'),strpos($documents,'private static function data_table')-strpos($documents,'public static function complete_pdf'));
$check(str_contains($completeMethod,"'Komplet dokumentów',\n            true"),'Komplet dokumentów powinien wymuszać zachowanie pełnego układu: karta, formularz i umowa.');
if($failures){fwrite(STDERR,"Release 1.12.15 complete PDF renderer test FAILED:\n- ".implode("\n- ",$failures)."\n");exit(1);}echo "Release 1.12.15 complete PDF renderer checks passed.\n";
