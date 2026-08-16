<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
define('BCS_DIR', dirname(__DIR__).'/');

$root = dirname(__DIR__);
$bootstrap = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-076.php');
$flow = (string)file_get_contents($root.'/includes/class-bcs-ksef-invoice-flow.php');
$testDocuments = (string)file_get_contents($root.'/includes/class-bcs-ksef-test-document-service.php');
$auth = (string)file_get_contents($root.'/includes/class-bcs-ksef-auth.php');
$config = (string)file_get_contents($root.'/includes/class-bcs-ksef-config.php');
$secret = (string)file_get_contents($root.'/includes/class-bcs-ksef-secret.php');
$install = (string)file_get_contents($root.'/includes/class-bcs-ksef-install.php');

$fail = static function(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!str_contains($bootstrap, '* Version: 0.76') || !str_contains($bootstrap, "define('BCS_VERSION', '0.76')")) $fail('Plugin version declarations are not synchronized at 0.76.');
foreach (['class-bcs-ksef-invoice-flow.php','class-bcs-ksef-test-document-service.php','class-bcs-release-076.php','BCS_KSeF_Invoice_Flow::init();','BCS_Release_076::init();'] as $needle) if (!str_contains($bootstrap, $needle)) $fail('Release 0.76 bootstrap is incomplete: '.$needle);

foreach (["DB_VERSION = '0.76.1'",'ksef_production_token_ciphertext','ksef_production_token_nonce','ksef_environment_used','ksef_delivery_completed_at',"BCS_DB::table('ksef_test_documents')"] as $needle) if (!str_contains($install, $needle)) $fail('Full KSeF database support is incomplete: '.$needle);

foreach (['PRODUCTION_BASE_URL','https://api.ksef.mf.gov.pl/v2',"return $environment === 'production' ? 'production' : 'test'",'label(string $environment)'] as $needle) if (!str_contains($config, $needle)) $fail('TEST/PRODUCTION configuration is incomplete: '.$needle);
foreach (['ksef_production_token_ciphertext','decrypt_for_environment','configured(object $organizer, string $environment'] as $needle) if (!str_contains($secret, $needle)) $fail('Separate environment tokens are incomplete: '.$needle);
foreach (['forcedEnvironment','new BCS_KSeF_Client($environment)','decrypt_for_environment','environment'=>$environment] as $needle) if (!str_contains($auth, $needle)) $fail('Environment-aware authentication is incomplete: '.$needle);

foreach (['BCS_Invoices::ensure_invoice','BCS_KSeF_FA3::prepare_and_save','BCS_KSeF_Service::send','BCS_KSeF_Service::refresh_status','ksef_finalize_invoice_076','environment === \'production\'','nie został automatycznie wysłany rodzicowi','invoice_delivery_after_ksef'] as $needle) if (!str_contains($flow, $needle)) $fail('Operational invoice flow is incomplete: '.$needle);

foreach (['ksef_test_documents','TEST-KSEF/','Nabywca Testowy','Sprzedawca Testowy Basketmania','BCS_KSeF_Auth::authenticate($r,\'test\')','send_online_invoice','session_invoice_status'] as $needle) if (!str_contains($testDocuments, $needle)) $fail('Independent KSeF TEST document flow is incomplete: '.$needle);

foreach (['Generuj fakturę','Generowanie i wysyłka do KSeF','bcs_ksef_generate_invoice_full_076','KSeF TEST','opłaconych zgłoszeń','Generuj testową fakturę KSeF','Wyślij do KSeF TEST','Testuj cały proces','Właściwe faktury wygenerowane przez CRM i przekazane do KSeF','Status KSeF','Numer KSeF','PRODUKCJA – api.ksef.mf.gov.pl'] as $needle) if (!str_contains($release, $needle)) $fail('Release 0.76 UI is incomplete: '.$needle);

foreach (["remove_action('admin_init', ['BCS_KSeF_Admin', 'save_organizer_fields'], 5)",'ksef_production_token','ksef_environment','intercept_list_invoice_ajax','handle_classic_invoice_actions'] as $needle) if (!str_contains($release, $needle)) $fail('Legacy invoice/KSeF paths are not fully intercepted: '.$needle);

if (!str_contains($release, "r.total_amount>0 AND r.paid_amount>=r.total_amount")) $fail('KSeF TEST must list fully paid registrations.');
if (!str_contains($release, "LEFT JOIN '.BCS_DB::table('ksef_test_documents')")) $fail('KSeF TEST page must use independent test documents.');
if (!str_contains($release, "setTimeout(x,2500)") || !str_contains($release, 'tries<6')) $fail('Full test must poll asynchronous KSeF status with a bounded loop.');

echo "Release 0.76 full KSeF checks passed.\n";
