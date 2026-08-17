<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-083.php');
$workflow = (string)file_get_contents($root.'/.github/workflows/php-lint.yml');

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === '0.83', 'Nagłówek wtyczki powinien mieć wersję 0.83.');
$check(($constantVersion[1] ?? '') === '0.83', 'BCS_VERSION powinno mieć wersję 0.83.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-083.php';"), 'Bootstrap powinien ładować release 0.83.');
$check(str_contains($plugin, 'BCS_Release_083::init();'), 'Bootstrap powinien inicjalizować release 0.83.');

foreach (['billing_type','billing_name','billing_street','billing_postal_code','billing_city','billing_nip','billing_notes','billing_source','billing_initialized_at','billing_updated_at'] as $column) {
    $check(str_contains($release, "'{$column}'") || str_contains($release, "{$column}"), 'Brakuje pola profilu fakturowego '.$column.'.');
}
$check(str_contains($release, "form_status='complete'"), 'Profil fakturowy powinien być tworzony dopiero po przesłaniu pełnego formularza.');
$check(str_contains($release, "'form_company'"), 'Formularz z danymi firmowymi powinien inicjalizować profil firmowy.');
$check(str_contains($release, "'form_individual'"), 'Brak danych firmowych powinien inicjalizować profil imienny.');
$check(str_contains($release, "register_shutdown_function([__CLASS__, 'shutdown_initialize_profile'], $id)"), 'Nowe zgłoszenie powinno utworzyć profil po zakończeniu zapisu formularza.');

$check(str_contains($release, '>Dane do Faktury</button>'), 'Karta Zgłoszenia powinna mieć osobną zakładkę „Dane do Faktury”.');
$check(str_contains($release, 'data-bcs-invoice-profile-083'), 'Brakuje osobnego panelu danych do faktury.');
$check(str_contains($release, '<option value="individual"'), 'Edytor powinien pozwalać wybrać osobę prywatną.');
$check(str_contains($release, '<option value="company"'), 'Edytor powinien pozwalać wybrać firmę.');
$check(str_contains($release, 'Użyj danych rodzica'), 'Edytor powinien pozwalać szybko przywrócić dane imienne.');
$check(str_contains($release, 'Użyj danych firmowych z formularza'), 'Edytor powinien pozwalać szybko przywrócić dane firmowe z formularza.');
$check(str_contains($release, "if (!empty($r->invoice_real_id))"), 'Edycja powinna być blokowana po wystawieniu faktury.');
$check(str_contains($release, 'Zmiana nabywcy wymaga osobnej procedury korekty'), 'Panel powinien wyjaśniać blokadę po wystawieniu faktury.');

$check(str_contains($release, "window.BCSCardForm060.editorGroups=window.BCSCardForm060.editorGroups.filter"), 'Pola faktury powinny zostać usunięte z edytora Formularza Obozowego.');
$check(str_contains($release, "toLowerCase()==='dane do faktury'"), 'Stara sekcja danych do faktury nie powinna być dublowana w formularzu.');

$check(str_contains($release, "remove_action('wp_ajax_bcs_ksef_generate_invoice_full_076', ['BCS_Release_082', 'ajax_real_generate'], -100);"), '0.83 powinno przejąć główną ścieżkę generowania faktury po 0.82.');
$check(str_contains($release, "'invoice_requested'=>1"), 'Most kompatybilnościowy powinien skierować generator na profil billing_*.');
$check(str_contains($release, "'invoice_buyer_name'=>$valid['name']"), 'Generator powinien używać nazwy z profilu fakturowego.');
$check(str_contains($release, "'invoice_nip'=>$valid['type'] === 'company' ? $valid['nip'] : ''"), 'Generator powinien używać NIP tylko dla profilu firmowego.');
$check(str_contains($release, 'BCS_Release_082::generate_guarded($registrationId)'), '0.83 musi zachować twardą kontrolę PDF ↔ KSeF z 0.82.');
$check(str_contains($release, "finally {\n            $wpdb->update(BCS_DB::table('registrations'), $original"), 'Po generowaniu oryginalne dane Formularza Obozowego muszą zostać przywrócone.');

$check(str_contains($workflow, 'Release 0.83 invoice profile/tab test'), 'CI powinno uruchamiać test 0.83.');
$check(str_contains($workflow, 'php tests/release-083-invoice-profile-tab-test.php'), 'CI powinno uruchamiać właściwy plik testu 0.83.');

if ($failures) {
    fwrite(STDERR, "Release 0.83 invoice profile/tab test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.83 invoice profile/tab checks passed.\n";