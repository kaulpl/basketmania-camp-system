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
$check(($headerVersion[1] ?? '') === '0.82', 'Nagłówek wtyczki powinien mieć wersję 0.82.');
$check(($constantVersion[1] ?? '') === '0.82', 'BCS_VERSION powinno mieć wersję 0.82.');
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
$check(str_contains($release, "['ksef_anonymize_test'=>0]"), 'Właściwa faktura nie powinna anonimizować nabywcy przed porównaniem z PDF.');
$check(str_contains($release, 'BCS_KSeF_FA3::prepare_and_save((int)$invoice->id)'), '0.82 powinno przygotować XML przed kontrolą.');
$check(str_contains($release, 'buyer_from_xml($xml)'), '0.82 powinno odczytać Podmiot2 z gotowego XML.');
$check(str_contains($release, 'buyer_snapshots_match($expected, $actual)'), '0.82 powinno porównać snapshot PDF z XML.');
$check(str_contains($release, 'BUYER_MISMATCH_082'), 'Rozbieżność nabywcy musi mieć osobny kod błędu i blokować wysyłkę.');
$check(str_contains($release, 'BCS_KSeF_Invoice_Flow::generate_and_submit($registrationId)'), 'Wysyłka do KSeF może nastąpić dopiero po przejściu guardu.');

$company = [
    'source'=>'invoice_form','nip'=>'5250000000','name'=>'ACME Sp. z o.o.','country_code'=>'PL',
    'address_l1'=>'ul. Kwiatowa 1','address_l2'=>'00-001 Warszawa',
];
$xml = '<?xml version="1.0" encoding="UTF-8"?>'
    .'<Faktura xmlns="'.BCS_KSeF_Config::FA3_NAMESPACE.'"><Podmiot2><DaneIdentyfikacyjne><NIP>5250000000</NIP><Nazwa>ACME Sp. z o.o.</Nazwa></DaneIdentyfikacyjne><Adres><KodKraju>PL</KodKraju><AdresL1>ul. Kwiatowa 1</AdresL1><AdresL2>00-001 Warszawa</AdresL2></Adres></Podmiot2></Faktura>';
$fromXml = BCS_Release_082::buyer_from_xml($xml);
$check(BCS_Release_082::buyer_snapshots_match($company, $fromXml), 'Identyczny nabywca firmowy w XML powinien przejść kontrolę.');
$wrongXml = str_replace(['5250000000','ACME Sp. z o.o.','ul. Kwiatowa 1','00-001 Warszawa'], ['', 'Jan Rodzic', 'ul. Rodzica 99', '83-130 Pelplin'], $xml);
$wrong = BCS_Release_082::buyer_from_xml($wrongXml);
$check(!BCS_Release_082::buyer_snapshots_match($company, $wrong), 'XML z danymi rodzica nie może przejść kontroli względem faktury firmowej.');

$check(str_contains($workflow, 'Release 0.82 KSeF buyer hard guard test'), 'CI powinno uruchamiać test 0.82.');
$check(str_contains($workflow, 'php tests/release-082-ksef-buyer-hard-guard-test.php'), 'CI powinno uruchamiać właściwy plik testu 0.82.');

if ($failures) {
    fwrite(STDERR, "Release 0.82 KSeF buyer hard guard test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.82 KSeF buyer hard guard checks passed.\n";
