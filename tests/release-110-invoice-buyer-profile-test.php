<?php

declare(strict_types=1);

if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');

$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$r110 = (string)file_get_contents($root.'/includes/class-bcs-release-110.php');
$js110 = (string)file_get_contents($root.'/assets/js/invoice-profile-110.js');
$r083 = (string)file_get_contents($root.'/includes/class-bcs-release-083.php');
$invoices = (string)file_get_contents($root.'/includes/class-bcs-invoices.php');
$fa3 = (string)file_get_contents($root.'/includes/class-bcs-ksef-fa3.php');
require_once $root.'/includes/class-bcs-release-110.php';

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === ($constantVersion[1] ?? ''), 'Nagłówek i BCS_VERSION powinny być zgodne.');
$check(version_compare((string)($headerVersion[1] ?? '0'), '1.10', '>='), 'Wtyczka powinna mieć wersję co najmniej 1.10.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-110.php';"), 'Bootstrap powinien ładować release 1.10.');
$check(str_contains($plugin, 'BCS_Release_110::init();'), 'Bootstrap powinien inicjalizować release 1.10.');

// 1.10 zastępuje stary UX 0.86, ale nie dubluje backendu danych fakturowych.
$check(str_contains($r110, "remove_action('admin_enqueue_scripts', ['BCS_Release_086', 'enqueue_admin_assets'], 1000)"), '1.10 powinno wyłączyć stary UI profilu 0.86.');
$check(str_contains($r110, "remove_action('admin_footer', ['BCS_Release_086', 'invoice_profile_template'], 9998)"), '1.10 powinno wyłączyć stary template 0.86.');
$check(str_contains($r110, "private const SAVE_ACTION = 'bcs_save_invoice_profile_083'"), 'Nowy panel powinien używać istniejącego, sprawdzonego zapisu billing_* z 0.83/0.84.');

foreach (['Faktura imienna','Faktura na firmę','Imię i nazwisko nabywcy','Nazwa firmy','NIP firmy','Dodatkowy opis do KSeF','Wygeneruj fakturę z tymi danymi'] as $needle) {
    $check(str_contains($r110, $needle), 'W nowym panelu brakuje elementu: '.$needle);
}
$check(str_contains($r110, 'BCS_Workflow_Engine::invoice_available($id)'), 'Przycisk generowania powinien respektować etap workflow faktury.');
$check(str_contains($r110, "wp_nonce_field('bcs_crm_'.\$id)"), 'Generowanie faktury z panelu powinno być chronione nonce CRM.');
$check(str_contains($r110, "name=\"bcs_crm_action\" value=\"invoice_generate\""), 'Panel powinien uruchamiać istniejącą, chronioną procedurę generowania faktury.');
$check(str_contains($r110, 'Dane nabywcy są zablokowane.'), 'Po wystawieniu faktury profil powinien być tylko do odczytu.');

// Pola zależne od rodzaju faktury.
$check(str_contains($js110, "selectedType(form) === 'company'"), 'JS powinien rozróżniać fakturę firmową.');
$check(str_contains($js110, 'nip.required = company'), 'NIP powinien być wymagany wyłącznie dla firmy.');
$check(str_contains($js110, "if (!company) nip.value = ''"), 'Przy fakturze imiennej NIP nie powinien pozostawać w profilu.');
$check(str_contains($js110, 'form.reportValidity()'), 'Przed zapisem powinny działać wymagane pola formularza.');
$check(str_contains($js110, 'hideOldInvoiceGenerateAction'), 'Stary przycisk generowania w Szybkich czynnościach powinien być ukryty, aby nie dublować akcji.');

// Serwerowa walidacja wymaga danych adresowych zawsze, a NIP tylko dla firmy.
$individual = (object)[
    'billing_type'=>'individual','billing_name'=>'Jan Kowalski','billing_street'=>'Testowa 1',
    'billing_postal_code'=>'83-130','billing_city'=>'Pelplin','billing_nip'=>'',
];
$company = (object)[
    'billing_type'=>'company','billing_name'=>'Basket Test Sp. z o.o.','billing_street'=>'Testowa 1',
    'billing_postal_code'=>'83-130','billing_city'=>'Pelplin','billing_nip'=>'1234567890',
];
$companyBad = clone $company; $companyBad->billing_nip = '';
$check(BCS_Release_110::profile_errors($individual) === [], 'Kompletna faktura imienna nie powinna wymagać NIP.');
$check(BCS_Release_110::profile_errors($company) === [], 'Kompletna faktura firmowa z 10-cyfrowym NIP powinna przejść walidację.');
$check((bool)array_filter(BCS_Release_110::profile_errors($companyBad), static fn($e)=>str_contains($e, 'NIP')), 'Faktura firmowa bez NIP powinna zostać zablokowana.');

// Jeden profil nabywcy pozostaje wspólnym źródłem dla PDF i KSeF.
$check(str_contains($r083, "'invoice_buyer_name'=>\$valid['name']") && str_contains($r083, "'invoice_nip'=>\$valid['type'] === 'company' ? \$valid['nip'] : ''"), 'Most 0.83 powinien nadal podkładać billing_* do generatora faktury.');
$check(str_contains($invoices, 'buyer_snapshot_from_registration') && str_contains($invoices, "'buyer_snapshot'=>wp_json_encode"), 'PDF powinien zapisywać kanoniczny snapshot nabywcy przy fakturze.');
$check(str_contains($fa3, "json_decode((string)(\$row->buyer_snapshot ?? ''), true)"), 'Generator FA(3) powinien czytać zapisany snapshot nabywcy faktury.');
$check(str_contains($fa3, "else self::add(\$dom, \$buyerId, 'BrakID', '1')"), 'FA(3) powinno obsługiwać nabywcę bez NIP dla faktury imiennej.');

if ($failures) {
    fwrite(STDERR, "Release 1.10 invoice buyer profile test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 1.10 invoice buyer profile checks passed.\n";
