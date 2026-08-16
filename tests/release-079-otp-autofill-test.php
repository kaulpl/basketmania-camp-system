<?php
$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/basketmania-camp-system.php');
$release = file_get_contents($root . '/includes/class-bcs-release-079.php');
$frontend = file_get_contents($root . '/includes/class-bcs-frontend.php');
$release078 = file_get_contents($root . '/includes/class-bcs-release-078.php');
$workflow = file_get_contents($root . '/.github/workflows/php-lint.yml');

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/^ \* Version:\s*([^\r\n]+)/m', $plugin, $headerMatch);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantMatch);
$headerVersion = trim($headerMatch[1] ?? '');
$constantVersion = trim($constantMatch[1] ?? '');

$check($headerVersion === '0.79', 'Nagłówek wtyczki powinien mieć wersję 0.79.');
$check($constantVersion === '0.79', 'BCS_VERSION powinno mieć wersję 0.79.');
$check($headerVersion === $constantVersion, 'Wersja w nagłówku i BCS_VERSION muszą być zgodne.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-079.php';"), 'Bootstrap powinien ładować release 0.79.');
$check(str_contains($plugin, 'BCS_Release_079::init();'), 'Bootstrap powinien inicjalizować release 0.79.');

$check(str_contains($release, "add_filter('do_shortcode_tag', [__CLASS__, 'enhance_parent_otp_markup'], 20, 4);"), '0.79 powinno poprawiać pole OTP rodzica na poziomie renderowanego portalu.');
$check(str_contains($release, "add_action('admin_head', [__CLASS__, 'admin_otp_autofill_ui'], 0);"), 'UI OTP Organizatora powinno ładować się przed historycznym skryptem 0.46.');
$check(str_contains($release, 'autocomplete="one-time-code"'), 'Pola OTP powinny mieć autocomplete="one-time-code".');
$check(str_contains($release, 'inputmode="numeric"'), 'Pola OTP powinny używać trybu numerycznego.');
$check(str_contains($release, 'pattern="[0-9]{6}"'), 'Pola OTP powinny oczekiwać 6 cyfr.');
$check(str_contains($release, 'maxlength="6"'), 'Pola OTP powinny ograniczać długość do 6 znaków.');
$check(str_contains($release, 'id="bcs-org-otp-code-079"'), 'Organizator powinien dostać prawdziwe pole formularza OTP.');
$check(!str_contains($release, 'prompt('), '0.79 nie może używać prompt() do wpisania kodu OTP.');
$check(str_contains($release, 'event.stopImmediatePropagation();'), 'Nowy listener Organizatora powinien zatrzymać historyczny handler prompt().');
$check(str_contains($release, "document.addEventListener('click',async event=>"), 'Nowy listener Organizatora powinien przejmować kliknięcie przycisku podpisu.');
$check(str_contains($release, '},true);'), 'Listener OTP Organizatora powinien działać w fazie capture.');
$check(str_contains($release, "post('bcs_046_organizer_otp_send'"), '0.79 powinno korzystać z istniejącej wysyłki OTP Organizatora.');
$check(str_contains($release, "post('bcs_046_organizer_otp_verify'"), '0.79 powinno korzystać z istniejącej weryfikacji OTP Organizatora.');

$check(!str_contains($release, "remove_action('wp_ajax_bcs_046_organizer_otp_verify'"), '0.79 nie może podmieniać backendowej weryfikacji/dowodu Organizatora.');
$check(str_contains($release078, "'sms_message_id'=>$smsMessageId"), '0.78 nadal powinno przechowywać kanoniczne ID SMS Organizatora.');
$check(str_contains($release078, "'accepted_at'=>$now"), '0.78 nadal powinno przechowywać czas podpisu Organizatora.');
$check(str_contains($release078, "'verified_at'=>$now"), '0.78 nadal powinno przechowywać czas weryfikacji Organizatora.');

$check(str_contains($frontend, '<input id="bcs-code" maxlength="6" inputmode="numeric">'), 'Test zakłada obecny historyczny markup pola rodzica do podmiany w 0.79.');
$check(str_contains($release, '$legacy = \'<input id="bcs-code" maxlength="6" inputmode="numeric">\';'), '0.79 powinno celować dokładnie w aktualne pole rodzica.');
$check(str_contains($release, 'name="bcs_otp_code"'), 'Pole rodzica powinno być prawdziwym nazwanym polem formularza OTP.');
$check(str_contains($release, 'name="bcs_organizer_otp_code"'), 'Pole Organizatora powinno być prawdziwym nazwanym polem formularza OTP.');

$check(str_contains($workflow, 'Release 0.79 OTP AutoFill test'), 'CI powinno uruchamiać test regresyjny 0.79.');
$check(str_contains($workflow, 'php tests/release-079-otp-autofill-test.php'), 'CI powinno uruchamiać właściwy plik testu 0.79.');

if ($failures) {
    fwrite(STDERR, "Release 0.79 OTP AutoFill test FAILED:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Release 0.79 OTP AutoFill test passed.\n";
