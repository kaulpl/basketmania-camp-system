<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-082.php');
$workflow = (string)file_get_contents($root.'/.github/workflows/php-lint.yml');
require_once $root.'/includes/class-bcs-ksef-config.php';
require_once $root.'/includes/class-bcs-release-082.php';

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$currentVersion = $headerVersion[1] ?? '';
$constant = $constantVersion[1] ?? '';
$check($currentVersion === $constant, 'Nagłówek wtyczki i BCS_VERSION muszą być zgodne.');
$check(version_compare($currentVersion, '0.82', '>='), 'Bieżąca wersja wtyczki nie może być starsza niż 0.82.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-082.php';"), 'Bootstrap powinien ładować release 0.82.');
$check(str_contains($plugin, 'BCS_Release_082::init();'), 'Bootstrap powinien inicjalizować release 0.82.');

foreach ([
    "wp_ajax_bcs_ksef_generate_invoice_full_076",
    "wp_ajax_bcs_list_quick_action_02010",
    "wp_ajax_bcs_generate_invoice_0200",
] as $hook) {
    $check(str_contains($release, $hook), '0.82 powinno przejmować ścieżkę '.$hook.'.');
}
$check(str_contains($release, "add_action('admin_init', [__CLASS__, 'classic_generate'], -100);"), '0.82 powinno przejmować klasyczne generowanie faktury przed 0.76.');
$check(str_contains($release, "add_action('admin_post_bcs_workflow_single', [__CLASS__, 'single_generate'], -100);"), '0.82 powinno zabezpieczać także pojedynczą akcję workflow.');
$check(str_contains($release, 'BCS_Invoices::buyer_snapshot_from_registration($registration)'), '0.82 powinno odczytać aktualne dane fakturowe zgłoszenia.');
$check(str_contains($release, 'if ($requested)'), 'Świadome Faktura: TAK musi mieć odrębną ścieżkę naprawy snapshotu.');
$check(str_contains($release, "'invoice_buyer_snapshot_repaired_082'"), 'Naprawa snapshotu powinna być logowana.');
$check(str_contains($release, "'guard_version'] = '0.82'"), 'Snapshot powinien otrzymać znacznik ochrony 0.82.');
$check(!str_contains($release, "['ksef_anonymize_test'=>0]"), 'W TEST nie wolno już czasowo wyłączać anonimizacji nabywcy.');
$check(str_contains($release, "['ksef_anonymize_test'=>1]"), 'Właściwa faktura TEST powinna wymuszać anonimizację przed generowaniem XML.');
$check(str_contains($release, 'BCS_KSeF_FA3::prepare_and_save((int)$invoice->id)'), '0.82 powinno przygotować XML przed kontrolą.');
$check(str_contains($release, 'buyer_from_xml($xml)'), '0.82 powinno odczytać Podmiot2 z gotowego XML.');
$check(str_contains($release, 'test_buyer_is_anonymized($xml)'), 'TEST powinien mieć osobną twardą kontrolę anonimizacji Podmiot2.');
$check(str_contains($release, 'TEST_BUYER_NOT_ANONYMIZED_111'), 'Brak anonimizacji TEST musi blokować wysyłkę osobnym kodem błędu.');
$check(str_contains($release, 'buyer_snapshots_match($expected, $actual)'), 'PRODUKCJA powinna nadal porównywać snapshot PDF z XML.');
$check(str_contains($release, 'BUYER_MISMATCH_082'), 'Rozbieżność nabywcy w PRODUKCJI musi blokować wysyłkę.');
$check(str_contains($release, 'BCS_KSeF_Invoice_Flow::generate_and_submit($registrationId)'), 'Wysyłka do KSeF może nastąpić dopiero po przejściu guardu.');

$company = [
    'source'=>'invoice_form','nip'=>'5250000000','name'=>'ACME Sp. z o.o.','country_code'=>'PL',
    'address_l1'=>'ul. Kwiatowa 1','address_l2'=>'00-001 Warszawa',
];
$companyXml = '<?xml version="1.0" encoding="UTF-8"?>'
    .'<Faktura xmlns="'.BCS_KSeF_Config::FA3_NAMESPACE.'"><Podmiot2><DaneIdentyfikacyjne><NIP>5250000000</NIP><Nazwa>ACME Sp. z o.o.</Nazwa></DaneIdentyfikacyjne><Adres><KodKraju>PL</KodKraju><AdresL1>ul. Kwiatowa 1</AdresL1><AdresL2>00-001 Warszawa</AdresL2></Adres></Podmiot2></Faktura>';
$fromXml = BCS_Release_082::buyer_from_xml($companyXml);
$check(BCS_Release_082::buyer_snapshots_match($company, $fromXml), 'Identyczny nabywca firmowy powinien przejść kontrolę produkcyjną PDF ↔ XML.');
$check(!BCS_Release_082::test_buyer_is_anonymized($companyXml), 'Rzeczywista firma z NIP-em nie może przejść kontroli KSeF TEST.');

$anonymousXml = '<?xml version="1.0" encoding="UTF-8"?>'
    .'<Faktura xmlns="'.BCS_KSeF_Config::FA3_NAMESPACE.'"><Podmiot2><DaneIdentyfikacyjne><BrakID>1</BrakID><Nazwa>Nabywca Testowy</Nazwa></DaneIdentyfikacyjne><Adres><KodKraju>PL</KodKraju><AdresL1>ul. Przykładowa 2</AdresL1><AdresL2>00-002 Miasto Testowe</AdresL2></Adres></Podmiot2></Faktura>';
$check(BCS_Release_082::test_buyer_is_anonymized($anonymousXml), 'Fikcyjny Podmiot2 z BrakID=1 powinien przejść kontrolę KSeF TEST.');

$wrongXml = str_replace(['5250000000','ACME Sp. z o.o.','ul. Kwiatowa 1','00-001 Warszawa'], ['', 'Jan Rodzic', 'ul. Rodzica 99', '83-130 Pelplin'], $companyXml);
$wrong = BCS_Release_082::buyer_from_xml($wrongXml);
$check(!BCS_Release_082::buyer_snapshots_match($company, $wrong), 'XML z innymi danymi nie może przejść kontroli produkcyjnej względem faktury firmowej.');
$check(!BCS_Release_082::test_buyer_is_anonymized($wrongXml), 'Dowolne inne dane nie mogą udawać poprawnej anonimizacji TEST.');

$check(str_contains($workflow, 'Release 0.82 KSeF buyer hard guard test'), 'CI powinno uruchamiać test 0.82.');
$check(str_contains($workflow, 'php tests/release-082-ksef-buyer-hard-guard-test.php'), 'CI powinno uruchamiać właściwy plik testu 0.82.');

if ($failures) {
    fwrite(STDERR, "Release 0.82 KSeF buyer hard guard test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.82 KSeF buyer hard guard checks passed.\n";
