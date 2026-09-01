<?php
/** Static regression test for release 1.12.17 document headings and evidence layout. */
$qualification=file_get_contents(__DIR__.'/../includes/class-bcs-qualification.php');
$documents=file_get_contents(__DIR__.'/../includes/class-bcs-documents.php');
$plugin=file_get_contents(__DIR__.'/../basketmania-camp-system.php');
$failed=false;
$check=static function(bool $ok,string $message) use (&$failed): void {
    if ($ok) return;
    fwrite(STDERR,"FAIL: {$message}\n");
    $failed=true;
};

$check(str_contains($qualification,'Cyfrowe potwierdzenie podpisania karty kwalifikacyjnej'),'Sekcja dowodowa musi jednoznacznie wskazywać kartę kwalifikacyjną.');
$check(str_contains($qualification,'class="proof bcs-card-proof bcs-v2-evidence"'),'Karta musi używać schematu sekcji dowodowej umowy.');
$check(str_contains($qualification,'bcs-v2-evidence-table')&&str_contains($qualification,'bcs-v2-evidence-row')&&str_contains($qualification,'bcs-v2-evidence-cell'),'Podpisy karty muszą być prezentowane w pionowym układzie dowodowym umowy.');
$check(str_contains($qualification,"'Status'=>'potwierdzona kodem SMS'")&&str_contains($qualification,"'Identyfikator wiadomości SMS'")&&str_contains($qualification,"'Oświadczenie podpisującego'"),'Dowód karty musi zawierać standardowe pola dowodu SMS.');
$check(str_contains($documents,'<h1>Formularz obozowy</h1>'),'Formularz obozowy musi mieć własny nagłówek.');
$check(!str_contains($documents,'<h1>2. Formularz obozowy</h1>'),'Nagłówek formularza nie może przywracać numeracji dokumentów.');
$check(preg_match('/Version: ([0-9.]+)/',$plugin,$header)===1&&preg_match("/BCS_VERSION', '([0-9.]+)'/",$plugin,$constant)===1&&$header[1]===$constant[1]&&version_compare($header[1],'1.12.17','>='),'Wersja 1.12.17 lub nowsza powinna być spójna.');

if ($failed) exit(1);
echo "OK\n";
