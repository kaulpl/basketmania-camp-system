<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-086.php');
$ui = (string)file_get_contents($root.'/assets/js/invoice-profile-086.js');
$release083 = (string)file_get_contents($root.'/includes/class-bcs-release-083.php');
$release084 = (string)file_get_contents($root.'/includes/class-bcs-release-084.php');
$workflow = (string)file_get_contents($root.'/.github/workflows/php-lint.yml');
require_once $root.'/includes/class-bcs-release-086.php';

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === ($constantVersion[1] ?? ''), 'Nagłówek wtyczki i BCS_VERSION powinny być zgodne.');
$check(($headerVersion[1] ?? '') !== '' && version_compare((string)$headerVersion[1], '0.86', '>='), 'Test 0.86 wymaga wersji wtyczki 0.86 lub nowszej.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-086.php';"), 'Bootstrap powinien ładować release 0.86.');
$check(str_contains($plugin, 'BCS_Release_086::init();'), 'Bootstrap powinien inicjalizować release 0.86.');

$check(str_contains($release083, 'new MutationObserver'), 'Test zakłada obecność historycznego obserwatora 0.83.');
$check(str_contains($release084, 'new MutationObserver'), 'Test zakłada obecność historycznego obserwatora 0.84.');
$check(str_contains($release, "remove_action('admin_footer', ['BCS_Release_083', 'card_invoice_tab'], 9997);"), '0.86 powinno wyłączać stary UI 0.83.');
$check(str_contains($release, "remove_action('admin_footer', ['BCS_Release_084', 'card_description_ui'], 9999);"), '0.86 powinno wyłączać stary UI 0.84.');
$check(!str_contains($ui, 'MutationObserver'), 'Nowy UI danych do faktury nie może obserwować całego DOM ani używać MutationObserver.');
$check(str_contains($ui, "data-bcs-data-tab-086=\"invoice\""), 'Nowy UI powinien mieć zakładkę Dane do Faktury.');
$check(str_contains($ui, "data-bcs-invoice-edit-086"), 'Nowy UI powinien obsługiwać otwarcie edycji danych do faktury.');
$check(str_contains($ui, 'billing_ksef_description'), 'Nowy UI powinien zachować dodatkowy opis KSeF.');
$check(str_contains($ui, 'filterLegacyEditorGroups'), 'Nowy UI powinien usuwać stare pola faktury z edytora Formularza Obozowego.');
$check(str_contains($ui, '[80, 250, 700, 1400]'), 'Montowanie powinno używać skończonej liczby prób zamiast stałego obserwatora DOM.');

$sample = '<span class="bcs-badge">agreement_parent_signed</span><h3>Formularz zaakceptowany</h3><p>Organizator zaakceptował formularz i przekazał wzór umowy.</p><p>wzór umowy jest dostępny do wglądu.</p><button>Otwórz wzór umowy</button><span>Podpisz dokument</span>';
$changed = BCS_Release_086::parent_signed_copy($sample);
$check(str_contains($changed, 'Umowa podpisana przez Rodzica'), 'Status powinien wskazywać podpis Rodzica.');
$check(str_contains($changed, 'oczekuje teraz na podpis Organizatora'), 'Opis statusu powinien wskazywać oczekiwanie na podpis Organizatora.');
$check(str_contains($changed, 'Umowa została podpisana przez Ciebie i oczekuje na podpis Organizatora.'), 'Sekcja Umowa powinna opisywać stan po podpisie Rodzica.');
$check(str_contains($changed, 'Otwórz podpisaną umowę'), 'Przycisk powinien otwierać podpisaną przez Rodzica umowę.');
$check(str_contains($changed, 'Podpis rodzica złożony'), 'Krok procesu nie powinien ponownie prosić Rodzica o podpis.');
$check(!str_contains($changed, 'agreement_parent_signed'), 'Surowy status techniczny nie powinien być widoczny rodzicowi.');

$check(str_contains($workflow, 'Release 0.86 parent status/invoice UI test'), 'CI powinno uruchamiać test 0.86.');
$check(str_contains($workflow, 'php tests/release-086-parent-status-invoice-ui-test.php'), 'CI powinno uruchamiać właściwy test PHP 0.86.');
$check(str_contains($workflow, 'node --check assets/js/invoice-profile-086.js'), 'CI powinno sprawdzać składnię JavaScript UI 0.86.');

if ($failures) {
    fwrite(STDERR, "Release 0.86 parent status/invoice UI test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.86 parent status/invoice UI checks passed.\n";
