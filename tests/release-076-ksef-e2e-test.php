<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
define('BCS_DIR', dirname(__DIR__).'/');

$root = dirname(__DIR__);
$bootstrap = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-076.php');
$service = (string)file_get_contents($root.'/includes/class-bcs-ksef-test-service.php');
$install = (string)file_get_contents($root.'/includes/class-bcs-ksef-install.php');

$fail = static function(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!str_contains($bootstrap, '* Version: 0.76') || !str_contains($bootstrap, "define('BCS_VERSION', '0.76')")) {
    $fail('Plugin version declarations are not synchronized at 0.76.');
}
foreach (['class-bcs-ksef-test-service.php','class-bcs-release-076.php','BCS_Release_076::init();'] as $needle) {
    if (!str_contains($bootstrap, $needle)) $fail('Release 0.76 bootstrap is incomplete: '.$needle);
}

foreach (['ksef_test_status','ksef_tested_at','ksef_test_message','ksef_test_details',"DB_VERSION = '0.76.0'"] as $needle) {
    if (!str_contains($install, $needle)) $fail('KSeF E2E result storage is incomplete: '.$needle);
}

foreach ([
    'bcs_ksef_e2e_test_076',
    'Uruchom test E2E',
    'Dokończ test',
    'Sprawdź ponownie',
    'Test integracji',
    'TEST OK',
    'Test w toku',
    'Błąd testu',
    'pełny rzeczywisty przepływ',
    "remove_action(\$hook, ['BCS_Release_075', 'page'])",
] as $needle) {
    if (!str_contains($release, $needle)) $fail('KSeF TEST E2E UI is incomplete: '.$needle);
}

foreach ([
    'BCS_KSeF_FA3::prepare_and_save',
    'BCS_KSeF_Service::send',
    'BCS_KSeF_Service::refresh_status',
    'BCS_KSeF_Service::fetch_remote_xml',
    'ksef_anonymize_test',
    'compare_xml',
    'C14N',
    "'passed'",
    "'pending'",
    "'failed'",
    'Pobrany XML jest semantycznie identyczny',
] as $needle) {
    if (!str_contains($service, $needle)) $fail('KSeF E2E test flow is incomplete: '.$needle);
}

require_once $root.'/includes/class-bcs-ksef-config.php';
require_once $root.'/includes/class-bcs-ksef-test-service.php';

$xmlA = '<?xml version="1.0" encoding="UTF-8"?><Faktura xmlns="'.BCS_KSeF_Config::FA3_NAMESPACE.'"><Podmiot1><DaneIdentyfikacyjne><NIP>1111111111</NIP></DaneIdentyfikacyjne></Podmiot1><Fa><P_2>FV/TEST/1</P_2><P_15>100.00</P_15></Fa></Faktura>';
$xmlB = '<?xml version="1.0" encoding="UTF-8"?>\n<Faktura xmlns="'.BCS_KSeF_Config::FA3_NAMESPACE.'">\n <Podmiot1><DaneIdentyfikacyjne><NIP>1111111111</NIP></DaneIdentyfikacyjne></Podmiot1>\n <Fa><P_2>FV/TEST/1</P_2><P_15>100.00</P_15></Fa>\n</Faktura>';
$comparison = BCS_KSeF_Test_Service::compare_xml($xmlA, $xmlB);
if (empty($comparison['success'])) $fail('Canonical XML comparison should accept formatting-only differences.');

$xmlChanged = str_replace('<P_15>100.00</P_15>', '<P_15>101.00</P_15>', $xmlB);
$comparisonChanged = BCS_KSeF_Test_Service::compare_xml($xmlA, $xmlChanged);
if (!empty($comparisonChanged['success'])) $fail('Critical amount differences must fail the E2E integrity check.');

if (!str_contains($release, "retries<6") || !str_contains($release, "setTimeout(resolve,2500)")) {
    $fail('Frontend E2E test must poll asynchronous KSeF processing with a bounded retry loop.');
}

echo "Release 0.76 KSeF E2E checks passed.\n";
