<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-088.php');
$release084 = (string)file_get_contents($root.'/includes/class-bcs-release-084.php');
$release087 = (string)file_get_contents($root.'/includes/class-bcs-release-087.php');
$workflow = (string)file_get_contents($root.'/.github/workflows/php-lint.yml');

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$header = (string)($headerVersion[1] ?? '');
$constant = (string)($constantVersion[1] ?? '');
$check($header !== '' && $header === $constant, 'Nagłówek wtyczki i BCS_VERSION powinny być zgodne.');
$check($header !== '' && version_compare($header, '0.88', '>='), 'Test 0.88 wymaga wersji wtyczki 0.88 lub nowszej.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-088.php';"), 'Bootstrap powinien ładować release 0.88.');
$check(str_contains($plugin, 'BCS_Release_088::init();'), 'Bootstrap powinien inicjalizować release 0.88.');

$check(str_contains($release, "remove_action('admin_footer', ['BCS_Release_087', 'render_invoice_accordion'], 9998);"), '0.88 powinno zastąpić accordion 0.87.');
$check(str_contains($release, "remove_action('wp_footer', ['BCS_Release_084', 'parent_form_description_field'], 9999);"), '0.88 powinno zastąpić formularz opisu rodzica z 0.84.');
$check(str_contains($release, "[name=\"invoice_notes\"]"), '0.88 powinno usuwać historyczne invoice_notes z formularza rodzica/admina.');
$check(str_contains($release, "field?.name!=='invoice_notes'"), '0.88 powinno usuwać invoice_notes z edytora Formularza Obozowego.');
$check(str_contains($release, "'dodatkowe dane na fakturze'"), '0.88 powinno usuwać stary podgląd Dodatkowych danych na fakturze.');

$check(str_contains($release, "textarea.name='invoice_ksef_description'"), 'Rodzic powinien mieć jedno pole invoice_ksef_description.');
$check(substr_count($release, 'name="billing_ksef_description"') === 1, 'Accordion administratora powinien zawierać dokładnie jedno edytowalne pole billing_ksef_description.');
$check(str_contains($release, '<span>Dodatkowy opis do KSeF</span>'), 'UI powinien pokazywać Dodatkowy opis do KSeF.');
$check(!str_contains($release, '<span>Uwagi do faktury</span>'), '0.88 nie może pokazywać Uwagi do faktury.');
$check(!str_contains($release, 'name="billing_notes"'), 'Formularz 0.88 nie może wysyłać billing_notes.');

$check(str_contains($release, "['billing_notes'=>'']"), 'Przed generowaniem 0.88 powinno wyzerować billing_notes.');
$check(str_contains($release, 'BCS_Release_084::generate_guarded($registrationId)'), '0.88 powinno zachować sprawdzony pipeline KSeF 0.84.');
$check(str_contains($release, "['billing_notes'=>\$originalNotes]"), 'Historyczne billing_notes powinno zostać przywrócone po generowaniu.');
$check(str_contains($release, 'finally'), 'Przywrócenie danych historycznych powinno być gwarantowane przez finally.');

foreach ([
    "remove_action('wp_ajax_bcs_ksef_generate_invoice_full_076', ['BCS_Release_084', 'ajax_real_generate'], -100);",
    "remove_action('wp_ajax_bcs_list_quick_action_02010', ['BCS_Release_084', 'ajax_list_generate'], -100);",
    "remove_action('wp_ajax_bcs_generate_invoice_0200', ['BCS_Release_084', 'ajax_legacy_generate'], -100);",
    "remove_action('admin_init', ['BCS_Release_084', 'classic_generate'], -100);",
    "remove_action('admin_post_bcs_workflow_single', ['BCS_Release_084', 'single_generate'], -100);",
] as $needle) $check(str_contains($release, $needle), '0.88 powinno przejąć wszystkie realne ścieżki generowania z 0.84.');

$check(str_contains($release084, "'DodatkowyOpis'"), 'Pipeline 0.84 nadal powinien generować Fa/DodatkowyOpis.');
$check(str_contains($release087, "'billing_ksef_description'"), 'Profil 0.87 nadal powinien przechowywać billing_ksef_description.');
$check(str_contains($workflow, 'Release 0.88 single KSeF description test'), 'CI powinno uruchamiać test 0.88.');
$check(str_contains($workflow, 'php tests/release-088-single-ksef-description-test.php'), 'CI powinno uruchamiać właściwy test 0.88.');

if ($failures) {
    fwrite(STDERR, "Release 0.88 single KSeF description test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.88 single KSeF description checks passed.\n";
