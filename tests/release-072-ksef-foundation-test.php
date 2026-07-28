<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
define('BCS_DIR', dirname(__DIR__).'/');
define('BCS_KSEF_SECRET_KEY', 'test-only-high-entropy-key-for-release-072-regression');

$root = dirname(__DIR__);
$bootstrap = (string)file_get_contents($root.'/basketmania-camp-system.php');
preg_match('/\* Version:\s*([0-9.]+)/', $bootstrap, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $bootstrap, $constantVersion);
$currentVersion = $headerVersion[1] ?? '';
if (!defined('BCS_VERSION')) define('BCS_VERSION', $currentVersion !== '' ? $currentVersion : '0.72');

$configSource = (string)file_get_contents($root.'/includes/class-bcs-ksef-config.php');
$secretSource = (string)file_get_contents($root.'/includes/class-bcs-ksef-secret.php');
$installSource = (string)file_get_contents($root.'/includes/class-bcs-ksef-install.php');
$clientSource = (string)file_get_contents($root.'/includes/class-bcs-ksef-client.php');
$fa3Source = (string)file_get_contents($root.'/includes/class-bcs-ksef-fa3.php');
$adminSource = (string)file_get_contents($root.'/includes/class-bcs-ksef-admin.php');
$releaseSource = (string)file_get_contents($root.'/includes/class-bcs-release-072.php');

require_once $root.'/includes/class-bcs-ksef-config.php';
require_once $root.'/includes/class-bcs-ksef-secret.php';
require_once $root.'/includes/class-bcs-ksef-fa3.php';

$fail = static function(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if ($currentVersion === '' || ($constantVersion[1] ?? '') !== $currentVersion || version_compare($currentVersion, '0.72', '<')) {
    $fail('Plugin version declarations must be synchronized at 0.72 or newer.');
}
foreach ([
    'class-bcs-ksef-config.php', 'class-bcs-ksef-secret.php', 'class-bcs-ksef-install.php',
    'class-bcs-ksef-client.php', 'class-bcs-ksef-fa3.php', 'class-bcs-ksef-admin.php',
    'class-bcs-release-072.php', 'BCS_Release_072::init();',
] as $needle) {
    if (!str_contains($bootstrap, $needle)) $fail('KSeF 0.72 bootstrap is incomplete: '.$needle);
}

if (BCS_KSeF_Config::TEST_BASE_URL !== 'https://api-test.ksef.mf.gov.pl/v2') $fail('Incorrect KSeF TEST API base URL.');
if (BCS_KSeF_Config::FA3_NAMESPACE !== 'http://crd.gov.pl/wzor/2025/06/25/13775/' || BCS_KSeF_Config::FA3_SCHEMA_VERSION !== '1-0E' || BCS_KSeF_Config::FA3_SYSTEM_CODE !== 'FA (3)') $fail('Official FA(3) identifiers are not configured.');
foreach (['test','demo','production','unexpected'] as $environment) if (BCS_KSeF_Config::allowed_environment($environment) !== 'test') $fail('Release 0.72 must force the TEST environment.');

if (!function_exists('sodium_crypto_secretbox')) $fail('Sodium is required for the KSeF secret test.');
$secret = 'ksef-token-that-must-never-be-plaintext';
$encrypted = BCS_KSeF_Secret::encrypt($secret);
if ($encrypted['ciphertext'] === '' || $encrypted['nonce'] === '' || str_contains($encrypted['ciphertext'], $secret)) $fail('KSeF token was not encrypted safely.');
if (BCS_KSeF_Secret::decrypt($encrypted['ciphertext'], $encrypted['nonce']) !== $secret) $fail('KSeF token encryption round-trip failed.');

$sampleXml = '<?xml version="1.0" encoding="UTF-8"?>'.'<Faktura xmlns="'.BCS_KSeF_Config::FA3_NAMESPACE.'">'.'<Naglowek><KodFormularza kodSystemowy="FA (3)" wersjaSchemy="1-0E">FA</KodFormularza><WariantFormularza>3</WariantFormularza></Naglowek>'.'<Podmiot1><DaneIdentyfikacyjne><NIP>1111111111</NIP></DaneIdentyfikacyjne></Podmiot1>'.'<Podmiot2><DaneIdentyfikacyjne><Nazwa>Nabywca Testowy</Nazwa></DaneIdentyfikacyjne></Podmiot2>'.'<Fa><P_2>FV/TEST/2026/000001</P_2><P_15>100.00</P_15><FaWiersz><NrWierszaFa>1</NrWierszaFa></FaWiersz></Fa>'.'</Faktura>';
$validation = BCS_KSeF_FA3::validate($sampleXml);
if (!$validation['success']) $fail('Structural FA(3) validation failed: '.implode(' ', $validation['errors']));

foreach (['ksef_token_ciphertext','ksef_token_nonce','ksef_anonymize_test','ksef_xml_path','ksef_xml_hash','seller_snapshot','buyer_snapshot',"BCS_DB::table('ksef_operations')","bcs_ksef_db_version"] as $needle) if (!str_contains($installSource, $needle)) $fail('KSeF database foundation is missing: '.$needle);
foreach (["'/auth/challenge'",'wp_remote_request','X-Error-Format'] as $needle) if (!str_contains($clientSource, $needle)) $fail('KSeF API client is incomplete: '.$needle);
foreach (['Sprzedawca Testowy Basketmania','Nabywca Testowy','Usługa udziału w turnusie sportowym – dane testowe','KodFormularza','WariantFormularza','FaWiersz','schemaValidate'] as $needle) if (!str_contains($fa3Source, $needle)) $fail('FA(3) builder is incomplete: '.$needle);
foreach (['bcs_ksef_test_connection_072','bcs_ksef_prepare_xml_072','KSeF API 2.0 – środowisko TEST','Nie wysyła jeszcze faktur do KSeF','bcs_ksef_download_xml_072'] as $needle) if (!str_contains($adminSource.$releaseSource, $needle)) $fail('KSeF administration is incomplete: '.$needle);
if (!str_contains($secretSource, 'sodium_crypto_secretbox') || !str_contains($configSource, 'BCS_KSEF_SECRET_KEY')) $fail('Secure KSeF token storage is not wired.');

echo "Release 0.72 KSeF foundation checks passed.\n";
