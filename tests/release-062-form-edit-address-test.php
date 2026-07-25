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
$release042 = file_get_contents($root.'/includes/class-bcs-release-042.php');
$release060 = file_get_contents($root.'/includes/class-bcs-release-060.php');
$release062 = file_get_contents($root.'/includes/class-bcs-release-062.php');
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
if ($headerVersion !== '0.62' || $constantVersion !== '0.62') {
    $fail('Plugin version declarations are not synchronized at 0.62.');
}
if (!str_contains((string)$bootstrap, 'class-bcs-release-062.php') || !str_contains((string)$bootstrap, 'BCS_Release_062::init();')) {
    $fail('Release 0.62 is not loaded and initialized.');
}

$row = (object)[
    'id'=>62,'status'=>'draft_sent','form_status'=>'complete','form_verified_at'=>'2026-07-25 20:00:00',
    'parent_first_name'=>'Anna','parent_last_name'=>'Testowa','parents_names'=>'Anna i Jan Testowi',
    'parent_email'=>'anna@example.test','parent_phone'=>'500600700','parent_phone_alt'=>'',
    'parent_postal_code'=>'83-130','parent_city'=>'Pelplin','parent_street'=>'Sportowa','parent_house_number'=>'1',
    'parent_address'=>'','child_first_name'=>'Marek','child_last_name'=>'Testowy','child_address'=>'',
    'child_birth_date'=>'2014-03-12','child_pesel'=>'14231212345','child_height'=>'160','child_weight'=>'50',
    'shirt_size'=>'164','child_club'=>'Klub Testowy','special_educational_needs'=>'brak',
    'medical_notes'=>'brak','dietary_notes'=>'bez ograniczeń','vaccination_tetanus'=>'2024',
    'vaccination_diphtheria'=>'2024','vaccination_other'=>'','stay_contact'=>'Anna 500600700',
    'authorized_pickup'=>'Jan Testowy','camp_notes'=>'','invoice_requested'=>0,'invoice_buyer_name'=>'',
    'invoice_street'=>'','invoice_postal_code'=>'','invoice_city'=>'','invoice_nip'=>'','invoice_notes'=>'',
    'camp_name'=>'Basketmania Camp','start_date'=>'2027-07-04','end_date'=>'2027-07-10','location'=>'Pelplin',
    'agreement_status'=>'draft','agreement_record_status'=>'draft','has_final_agreement'=>1,'has_invoice'=>0,'invoice_status'=>'',
];

$html = BCS_Release_060::render_card_html($row);
if (!str_contains($html, 'bcs-card-form-edit-060')) {
    $fail('Edit action is not available while the current agreement is still a draft.');
}
if (!str_contains($html, 'Sportowa 1') || !str_contains($html, '83-130 Pelplin')) {
    $fail('Blank participant address is not displayed as the parent address.');
}

$row->agreement_status = 'pending';
$row->agreement_record_status = 'pending';
$lockedHtml = BCS_Release_060::render_card_html($row);
if (str_contains($lockedHtml, 'bcs-card-form-edit-060')) {
    $fail('Edit action remains available after the agreement is sent for signature.');
}

foreach ([
    "in_array(\$registration_status, ['pending','parent_signed','accepted'], true)",
    "\$values['child_address'] = BCS_Utils::registration_address(\$r)",
    "\$data['child_address'] = \$data['parent_address']",
    "BCS_Agreements::build_for_registration(\$id, 'draft', false)",
] as $required) {
    if (!str_contains((string)$release042, $required)) {
        $fail('Administrator edit flow is missing required 0.62 behavior: '.$required);
    }
}

foreach ([
    "admin_post_nopriv_bcs_complete_registration",
    "admin_post_bcs_complete_registration",
    "wp_ajax_bcs_046_send_agreement",
    "\$_POST['child_address'] = \$parent_address",
    "participant_address_inherited_from_parent",
] as $required) {
    if (!str_contains((string)$release062, $required)) {
        $fail('Release 0.62 parent-address fallback is incomplete: '.$required);
    }
}

echo "Release 0.62 form editing and participant address checks passed.\n";
