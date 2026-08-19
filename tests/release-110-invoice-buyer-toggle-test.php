<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$r083 = (string)file_get_contents($root.'/includes/class-bcs-release-083.php');
$r110 = (string)file_get_contents($root.'/includes/class-bcs-release-110.php');

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

// Istniejąca warstwa danych 0.83 ma pozostać jedynym źródłem danych nabywcy.
foreach (['billing_type','billing_name','billing_street','billing_postal_code','billing_city','billing_nip'] as $field) {
    $check(str_contains($r083, $field), 'Profil fakturowy powinien zawierać pole '.$field.'.');
}
$check(str_contains($r083, "['individual','company']"), 'Profil powinien rozróżniać osobę prywatną i firmę.');
$check(str_contains($r083, "if (\$type === 'company' && strlen(\$nip) !== 10)"), 'NIP powinien być wymagany i walidowany dla firmy.');
$check(str_contains($r083, 'Dane do faktury są niekompletne:'), 'Generator powinien blokować wystawienie przy brakujących danych nabywcy.');
$check(str_contains($r083, "'invoice_buyer_name'=>\$valid['name']"), 'Generator powinien przekazywać zapisany profil do danych nabywcy faktury.');
$check(str_contains($r083, "'invoice_nip'=>\$valid['type'] === 'company' ? \$valid['nip'] : ''"), 'Faktura imienna nie może dostać NIP-u firmy.');
$check(str_contains($r083, 'BCS_Release_082::generate_guarded($registrationId)'), 'PDF/KSeF powinny korzystać z chronionej ścieżki generowania z profilem billing.');
$check(str_contains($r083, 'Dane zablokowane.') && str_contains($r083, 'faktura została już wystawiona'), 'Po wystawieniu faktury profil powinien być zablokowany przed zwykłą edycją.');

// Nowy UX 1.10.
foreach (['Faktura imienna','Faktura na firmę','Nazwa firmy','Imię i nazwisko','Ulica i numer','Kod pocztowy','Miejscowość','NIP'] as $label) {
    $check(str_contains($r110, $label), 'Interfejs 1.10 powinien zawierać etykietę: '.$label.'.');
}
$check(str_contains($r110, 'data-invoice-kind-110="individual"'), 'Powinien istnieć wybór faktury imiennej.');
$check(str_contains($r110, 'data-invoice-kind-110="company"'), 'Powinien istnieć wybór faktury na firmę.');
$check(str_contains($r110, "const company=select.value==='company'"), 'Widok pól powinien reagować na typ nabywcy.');
$check(str_contains($r110, 'nipLabel.hidden=!company'), 'Pole NIP powinno być ukryte dla faktury imiennej.');
$check(str_contains($r110, 'form.elements.billing_nip.required=company'), 'NIP powinien być wymagany tylko dla firmy.');
$check(str_contains($r110, 'PDF faktury') && str_contains($r110, 'KSeF'), 'Interfejs powinien jasno informować o wspólnym źródle danych dla PDF i KSeF.');
$check(str_contains($r110, 'bcs-required-110'), 'Pola obowiązkowe powinny być oznaczone wizualnie.');

if ($failures) {
    fwrite(STDERR, "Release 1.10 invoice buyer toggle test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 1.10 invoice buyer toggle checks passed.\n";
