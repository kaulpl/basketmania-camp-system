<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$invoicesSource = (string)file_get_contents($root.'/includes/class-bcs-invoices.php');
$ksefSource = (string)file_get_contents($root.'/includes/class-bcs-ksef-fa3.php');
$flowSource = (string)file_get_contents($root.'/includes/class-bcs-ksef-invoice-flow.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-080.php');
$workflow = (string)file_get_contents($root.'/.github/workflows/php-lint.yml');
require_once $root.'/includes/class-bcs-invoices.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$currentVersion = $headerVersion[1] ?? '';
$constant = $constantVersion[1] ?? '';
$check($currentVersion !== '' && $currentVersion === $constant && version_compare($currentVersion, '0.80', '>='), 'Plugin version declarations must be synchronized and at least 0.80.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-080.php';"), 'Bootstrap powinien ładować release 0.80.');
$check(str_contains($plugin, 'BCS_Release_080::init();'), 'Bootstrap powinien inicjalizować release 0.80.');
$check(str_contains($release, 'final class BCS_Release_080'), 'Brakuje klasy release 0.80.');

$custom = (object)[
    'invoice_requested'=>1,
    'invoice_buyer_name'=>'ACME Sp. z o.o.',
    'invoice_street'=>'ul. Kwiatowa 1',
    'invoice_postal_code'=>'00-001',
    'invoice_city'=>'Warszawa',
    'invoice_nip'=>'525-000-00-00',
    'parent_first_name'=>'Jan',
    'parent_last_name'=>'Rodzic',
    'parent_street'=>'ul. Rodzica',
    'parent_house_number'=>'99',
    'parent_postal_code'=>'83-130',
    'parent_city'=>'Pelplin',
    'parent_address'=>'ul. Rodzica 99\n83-130 Pelplin',
];
$customBuyer = BCS_Invoices::buyer_snapshot_from_registration($custom);
$check($customBuyer['source'] === 'invoice_form', 'Faktura: TAK musi używać źródła invoice_form.');
$check($customBuyer['source_version'] === '0.80', 'Snapshot nabywcy powinien mieć wersję źródła 0.80.');
$check($customBuyer['name'] === 'ACME Sp. z o.o.', 'Faktura: TAK musi używać nazwy z danych do faktury.');
$check($customBuyer['address_l1'] === 'ul. Kwiatowa 1', 'Faktura: TAK musi używać adresu z danych do faktury.');
$check($customBuyer['address_l2'] === '00-001 Warszawa', 'Faktura: TAK musi używać kodu i miasta z danych do faktury.');
$check($customBuyer['nip'] === '5250000000', 'NIP nabywcy powinien być oczyszczony do cyfr.');
$check($customBuyer['errors'] === [], 'Kompletne dane do faktury nie powinny zwracać błędów.');
$check(!str_contains(json_encode($customBuyer, JSON_UNESCAPED_UNICODE), 'Jan Rodzic'), 'Przy Faktura: TAK nie wolno podstawić danych rodzica.');

$parent = clone $custom;
$parent->invoice_requested = 0;
$parentBuyer = BCS_Invoices::buyer_snapshot_from_registration($parent);
$check($parentBuyer['source'] === 'parent', 'Faktura: NIE musi używać danych rodzica.');
$check($parentBuyer['name'] === 'Jan Rodzic', 'Faktura: NIE musi używać imienia i nazwiska rodzica.');
$check($parentBuyer['address_l1'] === 'ul. Rodzica 99', 'Faktura: NIE musi używać adresu rodzica.');
$check($parentBuyer['address_l2'] === '83-130 Pelplin', 'Faktura: NIE musi używać kodu i miasta rodzica.');
$check($parentBuyer['nip'] === '', 'Dla faktury imiennej rodzica nie należy przenosić starego NIP z pól fakturowych.');
$check($parentBuyer['errors'] === [], 'Kompletne dane rodzica nie powinny zwracać błędów.');

$invalid = clone $custom;
$invalid->invoice_buyer_name = '';
$invalid->invoice_street = '';
$invalid->invoice_postal_code = '';
$invalid->invoice_city = '';
$invalidBuyer = BCS_Invoices::buyer_snapshot_from_registration($invalid);
$check($invalidBuyer['source'] === 'invoice_form', 'Niekompletne Faktura: TAK nadal musi pozostać źródłem invoice_form.');
$check($invalidBuyer['name'] === '', 'Niekompletne Faktura: TAK nie może fallbackować do nazwy rodzica.');
$check($invalidBuyer['address_l1'] === '', 'Niekompletne Faktura: TAK nie może fallbackować do adresu rodzica.');
$check(count($invalidBuyer['errors']) >= 4, 'Niekompletne dane fakturowe powinny blokować generowanie.');

$check(str_contains($invoicesSource, 'public static function buyer_snapshot_from_registration(object $r): array'), 'Brakuje wspólnego źródła danych nabywcy.');
$check(str_contains($invoicesSource, '$buyer=self::buyer_snapshot_from_registration($r);'), 'PDF powinien korzystać ze wspólnego źródła danych nabywcy.');
$check(str_contains($invoicesSource, "'{{BUYER_NAME}}'=>esc_html((string)\$buyer['name'])"), 'PDF powinien używać nazwy z kanonicznego snapshotu nabywcy.');
$check(str_contains($invoicesSource, "'buyer_snapshot'=>wp_json_encode(\$snapshotForDb"), 'Faktura lokalna powinna zamrażać snapshot nabywcy.');
$check(!str_contains($invoicesSource, "'invoice_requested'=>0"), 'Wysyłka faktury nie może zerować deklaracji invoice_requested.');

$check(str_contains($ksefSource, 'r.invoice_requested'), 'KSeF powinien odczytywać invoice_requested.');
$check(str_contains($ksefSource, 'BCS_Invoices::buyer_snapshot_from_registration($row)'), 'KSeF powinien korzystać ze wspólnego źródła nabywcy.');
$check(str_contains($ksefSource, "(string)(\$storedBuyer['source_version'] ?? '') === '0.80'"), 'KSeF powinien rozpoznawać kanoniczny snapshot 0.80.');
$check(str_contains($ksefSource, 'Zaznaczono „Faktura: tak”'), 'KSeF powinien jawnie blokować brak danych przy Faktura: TAK.');
$check(str_contains($ksefSource, "'buyer_source'=>(string)(\$result['buyer']['source'] ?? '')"), 'Operacja KSeF powinna rejestrować źródło danych nabywcy.');
$check(!str_contains($flowSource, "'invoice_requested'=>0"), 'Dostarczenie faktury po KSeF nie może zerować invoice_requested.');
$check(str_contains($flowSource, "'invoice_requested'=>(int)(\$registration->invoice_requested ?? 0)"), 'Log dostarczenia KSeF powinien zachować informację o deklaracji faktury.');

$check(str_contains($workflow, 'Release 0.80 invoice buyer/KSeF consistency test'), 'CI powinno uruchamiać test regresyjny 0.80.');
$check(str_contains($workflow, 'php tests/release-080-invoice-buyer-ksef-test.php'), 'CI powinno uruchamiać właściwy plik testu 0.80.');

if ($failures) {
    fwrite(STDERR, "Release 0.80 invoice buyer/KSeF test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.80 invoice buyer/KSeF checks passed.\n";
