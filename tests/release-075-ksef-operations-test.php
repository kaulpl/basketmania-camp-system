<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');

$root = dirname(__DIR__);
$bootstrap = (string)file_get_contents($root.'/basketmania-camp-system.php');
$client = (string)file_get_contents($root.'/includes/class-bcs-ksef-client.php');
$auth = (string)file_get_contents($root.'/includes/class-bcs-ksef-auth.php');
$crypto = (string)file_get_contents($root.'/includes/class-bcs-ksef-crypto.php');
$service = (string)file_get_contents($root.'/includes/class-bcs-ksef-service.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-075.php');
$organizer = (string)file_get_contents($root.'/includes/class-bcs-release-075-organizer.php');
$install = (string)file_get_contents($root.'/includes/class-bcs-ksef-install.php');

$fail = static function(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

preg_match('/\* Version:\s*([0-9.]+)/', $bootstrap, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $bootstrap, $constantVersion);
$currentVersion = $headerVersion[1] ?? '';
if ($currentVersion === '' || ($constantVersion[1] ?? '') !== $currentVersion || version_compare($currentVersion, '0.75', '<')) {
    $fail('Plugin version declarations must be synchronized at 0.75 or newer.');
}
foreach (['class-bcs-ksef-crypto.php','class-bcs-ksef-auth.php','class-bcs-ksef-service.php','class-bcs-release-075-organizer.php','class-bcs-release-075.php','BCS_Release_075_Organizer::init();','BCS_Release_075::init();'] as $needle) {
    if (!str_contains($bootstrap, $needle)) $fail('KSeF 0.75 bootstrap is incomplete: '.$needle);
}

foreach (['/auth/challenge','/security/public-key-certificates','/auth/ksef-token','/auth/token/redeem','/sessions/online','/invoices/ksef/'] as $needle) {
    if (!str_contains($client, $needle)) $fail('KSeF API client is missing endpoint: '.$needle);
}
foreach (['KsefTokenEncryption','encryptedToken','publicKeyId','contextIdentifier','timestampMs','accessToken'] as $needle) {
    if (!str_contains($auth, $needle)) $fail('Token authentication is incomplete: '.$needle);
}
foreach (['rsa_oaep_sha256_encrypt','aes-256-cbc','OPENSSL_NO_PADDING','mgf1','sha256_base64'] as $needle) {
    if (!str_contains($crypto, $needle)) $fail('KSeF cryptography is incomplete: '.$needle);
}
foreach (['SymmetricKeyEncryption','encryptedSymmetricKey','initializationVector','encryptedInvoiceContent','invoiceHash','encryptedInvoiceHash','ksef_number','fetch_remote_xml','upoDownloadUrl'] as $needle) {
    if (!str_contains($service, $needle)) $fail('Online invoice flow is incomplete: '.$needle);
}

foreach (["remove_action(\$hook, ['BCS_KSeF_Admin', 'page'])",'bcs_ksef_generate_075','bcs_ksef_send_075','bcs_ksef_refresh_075','bcs_ksef_preview_075','Generuj fakturę KSeF','Wyślij do KSeF TEST','Odśwież status','Podgląd z KSeF','Pobrano bezpośrednio z KSeF TEST'] as $needle) {
    if (!str_contains($release, $needle)) $fail('KSeF TEST administration is incomplete: '.$needle);
}
foreach (['Token KSeF TEST jest już zapisany i zaszyfrowany','Nie musisz ponownie wypełniać pola tokenu','Pozostaw je puste, aby zachować dotychczasowy token','Wpisanie nowego tokenu zastąpi poprzedni'] as $needle) {
    if (!str_contains($organizer, $needle)) $fail('Saved-token notice is incomplete: '.$needle);
}
foreach (['ksef_status_code','ksef_status_description','ksef_public_key_id','ksef_remote_xml_path'] as $needle) {
    if (!str_contains($install, $needle)) $fail('KSeF database migration is incomplete: '.$needle);
}
preg_match("/DB_VERSION\s*=\s*'([^']+)'/", $install, $dbVersion);
if (empty($dbVersion[1]) || version_compare($dbVersion[1], '0.75.0', '<')) $fail('KSeF DB version must be 0.75.0 or newer.');

require_once $root.'/includes/class-bcs-ksef-crypto.php';
if (!function_exists('openssl_pkey_new')) $fail('OpenSSL is required for KSeF cryptography tests.');
$key = openssl_pkey_new(['private_key_bits'=>2048, 'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
if ($key === false) $fail('Could not generate RSA test key.');
$csr = openssl_csr_new(['commonName'=>'Basketmania KSeF Test'], $key, ['digest_alg'=>'sha256']);
$certificate = $csr ? openssl_csr_sign($csr, null, $key, 1, ['digest_alg'=>'sha256']) : false;
if ($certificate === false || !openssl_x509_export($certificate, $certificatePem)) $fail('Could not create test certificate.');
$certificateBase64 = preg_replace('/-----[^-]+-----|\s+/', '', $certificatePem) ?: '';
$plain = 'test-token|1785000000000';
$encrypted = BCS_KSeF_Crypto::rsa_oaep_sha256_encrypt($plain, $certificateBase64);
if (strlen($encrypted) !== 256) $fail('RSA-OAEP ciphertext has an unexpected length.');
if (!openssl_private_decrypt($encrypted, $encoded, $key, OPENSSL_NO_PADDING)) $fail('Could not inspect RSA-OAEP test ciphertext.');
$hLen = 32;
$mgf1 = static function(string $seed, int $length): string {
    $mask = '';
    for ($i=0; strlen($mask)<$length; $i++) $mask .= hash('sha256', $seed.pack('N',$i), true);
    return substr($mask,0,$length);
};
$maskedSeed = substr($encoded,1,$hLen); $maskedDb = substr($encoded,1+$hLen);
$seed = $maskedSeed ^ $mgf1($maskedDb,$hLen); $db = $maskedDb ^ $mgf1($seed,strlen($maskedDb));
$separator = strpos($db,"\x01",$hLen);
$decodedPlain = $separator === false ? '' : substr($db,$separator+1);
if ($decodedPlain !== $plain) $fail('RSA-OAEP SHA-256 round-trip failed.');

$material = BCS_KSeF_Crypto::symmetric_material();
$ciphertext = BCS_KSeF_Crypto::aes_256_cbc_encrypt('<Faktura>TEST</Faktura>', $material['key'], $material['iv']);
$decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $material['key'], OPENSSL_RAW_DATA, $material['iv']);
if ($decrypted !== '<Faktura>TEST</Faktura>') $fail('AES-256-CBC round-trip failed.');
if (BCS_KSeF_Crypto::sha256_base64('abc') !== base64_encode(hash('sha256','abc',true))) $fail('Base64 SHA-256 helper failed.');

echo "Release 0.75 KSeF operation checks passed.\n";
