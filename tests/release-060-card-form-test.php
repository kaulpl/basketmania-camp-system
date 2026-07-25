<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
if (!function_exists('esc_html')) {
    function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action, string $name, bool $referer = true, bool $echo = true): string {
        return '<input type="hidden" name="'.htmlspecialchars($name, ENT_QUOTES).'" value="test-nonce">';
    }
}
if (!class_exists('BCS_Utils')) {
    final class BCS_Utils {
        public static function format_datetime(string $value): string { return $value; }
    }
}

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root.'/basketmania-camp-system.php');
$releaseSource = file_get_contents($root.'/includes/class-bcs-release-060.php');
require_once $root.'/includes/class-bcs-release-060.php';

$fail = static function (string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$headerVersion = '';
$constantVersion = '';
if (is_string($bootstrap)) {
    if (preg_match('/\* Version:\s*([0-9.]+)/', $bootstrap, $match)) $headerVersion = $match[1];
    if (preg_match("/define\('BCS_VERSION',\s*'([0-9.]+)'\)/", $bootstrap, $match)) $constantVersion = $match[1];
}
if (version_compare($headerVersion, '0.60', '<') || $headerVersion !== $constantVersion) {
    $fail('Plugin version is not 0.60 or later, or version declarations differ.');
}
if (!str_contains((string)$bootstrap, 'class-bcs-release-060.php') || !str_contains((string)$bootstrap, 'BCS_Release_060::init();')) {
    $fail('Release 0.60 is not loaded and initialized.');
}

foreach ([
    "remove_action('admin_footer', ['BCS_Release_042', 'admin_footer'], 10)",
    "remove_action('admin_footer', ['BCS_Release_058', 'admin_footer'], 5)",
    "remove_action('admin_footer', ['BCS_Release_059', 'admin_footer'], 4)",
    'assets/js/card-form-060.js',
    'assets/css/card-form-060.css',
] as $requiredSource) {
    if (!str_contains((string)$releaseSource, $requiredSource)) {
        $fail('Release 0.60 does not disable the conflicting legacy card view or load its own assets: '.$requiredSource);
    }
}

$row = (object)[
    'id'=>60,'status'=>'form_complete','form_status'=>'complete','form_verified_at'=>null,
    'parent_first_name'=>'Anna','parent_last_name'=>'Testowa','parents_names'=>'Anna i Jan Testowi',
    'parent_email'=>'anna@example.test','parent_phone'=>'500600700','parent_phone_alt'=>'',
    'parent_postal_code'=>'83-130','parent_city'=>'Pelplin','parent_street'=>'Sportowa','parent_house_number'=>'1',
    'child_first_name'=>'Marek','child_last_name'=>'Testowy','child_address'=>'',
    'child_birth_date'=>'2014-03-12','child_pesel'=>'14231212345','child_height'=>'160','child_weight'=>'50',
    'shirt_size'=>'164','child_club'=>'Klub Testowy','special_educational_needs'=>'brak',
    'medical_notes'=>'brak','dietary_notes'=>'bez ograniczeń','vaccination_tetanus'=>'2024',
    'vaccination_diphtheria'=>'2024','vaccination_other'=>'','stay_contact'=>'Anna 500600700',
    'authorized_pickup'=>'Jan Testowy','camp_notes'=>'','invoice_requested'=>0,'invoice_buyer_name'=>'',
    'invoice_street'=>'','invoice_postal_code'=>'','invoice_city'=>'','invoice_nip'=>'','invoice_notes'=>'',
    'camp_name'=>'Basketmania Camp','start_date'=>'2027-07-04','end_date'=>'2027-07-10','location'=>'Pelplin',
    'agreement_record_status'=>'draft','has_final_agreement'=>0,'has_invoice'=>0,'invoice_status'=>'',
];

$html = BCS_Release_060::render_card_html($row);
$headings = [
    'Rodzic / opiekun prawny',
    'Uczestnik obozu',
    'Zdrowie, żywienie i szczepienia',
    'Informacje dotyczące pobytu',
    'Dane do faktury',
    'Turnus',
];
$previous = -1;
foreach ($headings as $heading) {
    $position = strpos($html, $heading);
    if ($position === false || $position <= $previous) $fail('Card form groups are missing or in the wrong order: '.$heading);
    $previous = $position;
}

$verificationPosition = strpos($html, 'Potwierdzenie poprawności formularza');
if ($verificationPosition === false || $verificationPosition <= $previous) {
    $fail('The confirmation block is not rendered after all grouped form sections.');
}
if (!str_contains($html, 'name="bcs_crm_action" value="verify_form"')) {
    $fail('The confirmation form does not contain the persistent verify_form fallback action.');
}
if (!str_contains($html, 'Potwierdź poprawność formularza obozowego')) {
    $fail('The confirmation button is missing from the grouped card form.');
}
if (!str_contains($html, 'bcs-card-form-edit-060')) {
    $fail('The grouped card form does not expose the administrator edit action.');
}

$row->form_verified_at = '2026-07-25 20:00:00';
$verifiedHtml = BCS_Release_060::render_card_html($row);
if (str_contains($verifiedHtml, 'name="bcs_crm_action" value="verify_form"')) {
    $fail('The confirmation action remains visible after verification.');
}
if (!str_contains($verifiedHtml, 'Formularz potwierdzony przez Organizatora')) {
    $fail('The verified state is not rendered after confirmation.');
}

echo "Release 0.60 grouped card form behavior checks passed.\n";
