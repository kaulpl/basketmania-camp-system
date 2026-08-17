<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-085.php');
$release079 = (string)file_get_contents($root.'/includes/class-bcs-release-079.php');
$sms = (string)file_get_contents($root.'/includes/class-bcs-sms.php');
$frontendJs = (string)file_get_contents($root.'/assets/js/front.js');
$webOtpJs = (string)file_get_contents($root.'/assets/js/webotp-085.js');
$workflow = (string)file_get_contents($root.'/.github/workflows/php-lint.yml');
require_once $root.'/includes/class-bcs-release-085.php';

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$header = $headerVersion[1] ?? '';
$constant = $constantVersion[1] ?? '';
$check($header === $constant, 'Nagłówek i BCS_VERSION powinny być zgodne.');
$check($header !== '' && version_compare($header, '0.85', '>='), 'Test 0.85 wymaga wersji 0.85 lub nowszej.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-085.php';"), 'Bootstrap powinien ładować release 0.85.');
$check(str_contains($plugin, 'BCS_Release_085::init();'), 'Bootstrap powinien inicjalizować release 0.85.');

$check(str_contains($release079, 'autocomplete="one-time-code"'), '0.79 powinno nadal zachowywać Safari one-time-code.');
$check(str_contains($release, 'name="bcs_otp_code"'), 'Pole rodzica powinno pozostać nazwanym polem OTP.');
$check(str_contains($webOtpJs, "input.name = 'bcs_organizer_otp_code'"), 'Pole Organizatora powinno pozostać nazwanym polem OTP.');
foreach (['autocomplete', 'one-time-code', 'inputmode', 'numeric', '[0-9]{6}'] as $needle) {
    $check(str_contains($webOtpJs, $needle), 'WebOTP 0.85 powinno ustawiać atrybut '.$needle.'.');
}

$parentMessage = BCS_Release_085::build_origin_bound_message('camp.basketmania.pl', '123456', 'parent');
$check($parentMessage === "Basketmania Camp: kod OTP do podpisu umowy: 123456.\n\n@camp.basketmania.pl #123456", 'SMS rodzica powinien mieć format origin-bound wymagany przez WebOTP.');
$organizerMessage = BCS_Release_085::build_origin_bound_message('camp.basketmania.pl', '654321', 'organizer');
$check(str_ends_with($organizerMessage, "@camp.basketmania.pl #654321"), 'SMS Organizatora powinien kończyć się originem i kodem.');
$check(BCS_Release_085::build_origin_bound_message('https://camp.basketmania.pl', '123456') === '', 'Host w SMS WebOTP nie może zawierać schematu URL.');
$check(BCS_Release_085::build_origin_bound_message('camp.basketmania.pl', '12345') === '', 'WebOTP powinno odrzucić kod inny niż 6 cyfr.');

$check(str_contains($release, "private const PARENT_ACTION = 'bcs_send_otp';"), 'Transport 0.85 powinien obejmować OTP rodzica.');
$check(str_contains($release, "private const ORGANIZER_ACTION = 'bcs_046_organizer_otp_send';"), 'Transport 0.85 powinien obejmować OTP Organizatora.');
$check(str_contains($release, "add_filter('bcs_sms_send_result', [__CLASS__, 'origin_bound_otp_transport'], 100, 3);"), 'Origin-bound transport powinien przejmować wyłącznie filtrowaną wysyłkę OTP.');
$check(str_contains($release, "ReflectionMethod(BCS_SMS::class, \$methodName)"), '0.85 powinno używać istniejących transportów operatorów bez globalnego osłabiania BCS_SMS.');
$check(str_contains($sms, 'self::strip_links('), 'Zwykłe SMS-y powinny nadal przechodzić przez strip_links.');
$check(str_contains($sms, "preg_replace('/\\s+/u', ' ',"), 'Zwykłe SMS-y powinny nadal normalizować białe znaki; obejście ma dotyczyć tylko OTP.');

$check(str_contains($webOtpJs, "'OTPCredential' in window"), 'Chrome WebOTP powinno sprawdzać obsługę OTPCredential.');
$check(str_contains($webOtpJs, 'navigator.credentials.get({'), 'Chrome WebOTP powinno wywoływać navigator.credentials.get().');
$check(str_contains($webOtpJs, "otp: { transport: ['sms'] }"), 'WebOTP powinno nasłuchiwać transportu SMS.');
$check(str_contains($webOtpJs, "event.target.closest('#bcs-send-code')"), 'Nasłuch WebOTP powinien startować przy wysyłce kodu rodzica.');
$check(str_contains($webOtpJs, "event.target.closest('.bcs-org-sign-046')"), 'Nasłuch WebOTP powinien startować przed OTP Organizatora.');
$check(str_contains($webOtpJs, '}, true);'), 'Kliknięcia WebOTP powinny być przechwytywane w fazie capture przed historycznymi listenerami.');
$check(str_contains($release, "wp_enqueue_script('bcs-webotp-085', BCS_URL.'assets/js/webotp-085.js', [], BCS_VERSION, false);"), 'W panelu WebOTP powinno być ładowane w HEAD przed skryptem 0.79.');
$check(str_contains($frontendJs, "post('bcs_send_otp')"), '0.85 nie powinno zmieniać backendowej procedury wysyłki/weryfikacji rodzica.');
$check(str_contains($webOtpJs, 'Chrome nie odbiera WebOTP z iPhone’a'), 'UI powinno jasno opisywać ograniczenie Chrome + iPhone.');

$check(str_contains($workflow, 'Release 0.85 Chrome WebOTP test'), 'CI powinno uruchamiać test 0.85.');
$check(str_contains($workflow, 'php tests/release-085-chrome-webotp-test.php'), 'CI powinno uruchamiać właściwy test PHP 0.85.');
$check(str_contains($workflow, 'node --check assets/js/webotp-085.js'), 'CI powinno sprawdzać składnię JavaScript WebOTP 0.85.');

if ($failures) {
    fwrite(STDERR, "Release 0.85 Chrome WebOTP test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.85 Chrome WebOTP checks passed.\n";
