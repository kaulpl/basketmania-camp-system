<?php
declare(strict_types=1);
$root=dirname(__DIR__);$plugin=(string)file_get_contents($root.'/basketmania-camp-system.php');$qualification=(string)file_get_contents($root.'/includes/class-bcs-qualification.php');$js=(string)file_get_contents($root.'/assets/js/qualification.js');$css=(string)file_get_contents($root.'/assets/css/qualification.css');$standard=(string)file_get_contents($root.'/includes/class-bcs-release-0189.php');$failures=[];
$check=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};
$check(str_contains($plugin,'Version: 1.12.13')&&str_contains($plugin,"BCS_VERSION', '1.12.13'"),'Wersja 1.12.13 powinna być spójna.');
$check(str_contains($qualification,'class="bcs-check bcs-check-left bcs-span"'),'Pole powinno korzystać wyłącznie ze standardowych klas formularza.');
$check(str_contains($qualification,'type="checkbox" role="switch" name="sole_guardian"'),'Pole powinno być semantycznym slider-checkboxem.');
$check(!str_contains($qualification,'bcs-sole-switch')&&!str_contains($qualification,'bcs-sole-guardian'),'Markup nie może zawierać klas ze starych poprawek.');
$check(str_contains($js,"classList.remove('bcs-sole-switch','bcs-sole-guardian')")&&str_contains($js,"toggle.setAttribute('role','switch')"),'Skrypt powinien usuwać stare klasy i przywracać standardowy slider.');
$check(!str_contains($css,'bcs-sole-switch')&&!str_contains($css,'bcs-sole-guardian'),'Lokalny CSS nie może nadpisywać standardowego slidera.');
$check(str_contains($standard,'.bcs-wrap input[type="checkbox"]')&&str_contains($standard,'width:48px!important')&&str_contains($standard,'height:26px!important'),'Panel rodzica powinien dostarczać standardowy rozmiar slidera 48×26 px.');
$check(str_contains($standard,'border-radius:999px!important')&&str_contains($standard,'transform:translateX(22px)!important'),'Standardowy slider powinien mieć zaokrąglony tor i przesuwany uchwyt.');
if($failures){fwrite(STDERR,"Release 1.12.13 guardian switch test FAILED:\n- ".implode("\n- ",$failures)."\n");exit(1);}echo "Release 1.12.13 guardian standard switch checks passed.\n";
