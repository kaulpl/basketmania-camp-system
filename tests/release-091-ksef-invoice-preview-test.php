<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-091.php');
$adminJs = (string)file_get_contents($root.'/assets/admin.js');
$workflow = (string)file_get_contents($root.'/.github/workflows/php-lint.yml');
require_once $root.'/includes/class-bcs-release-091.php';

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === '0.91', 'Nagłówek wtyczki powinien mieć wersję 0.91.');
$check(($constantVersion[1] ?? '') === '0.91', 'BCS_VERSION powinno mieć wersję 0.91.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-091.php';"), 'Bootstrap powinien ładować release 0.91.');
$check(str_contains($plugin, 'BCS_Release_091::init();'), 'Bootstrap powinien inicjalizować release 0.91.');

$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Faktura xmlns="http://example.test/fa3">
  <Podmiot1>
    <DaneIdentyfikacyjne><NIP>1234567890</NIP><Nazwa>Basketmania Test Sp. z o.o.</Nazwa></DaneIdentyfikacyjne>
    <Adres><KodKraju>PL</KodKraju><AdresL1>ul. Sportowa 1</AdresL1><AdresL2>83-130 Pelplin</AdresL2></Adres>
  </Podmiot1>
  <Podmiot2>
    <DaneIdentyfikacyjne><NIP>0987654321</NIP><Nazwa>Klient Test Sp. z o.o.</Nazwa></DaneIdentyfikacyjne>
    <Adres><KodKraju>PL</KodKraju><AdresL1>ul. Rodzica 2</AdresL1><AdresL2>80-001 Gdańsk</AdresL2></Adres>
  </Podmiot2>
  <Fa>
    <KodWaluty>PLN</KodWaluty><P_1>2026-08-17</P_1><P_2>FV/12/2026</P_2><P_6>2026-08-17</P_6>
    <P_13_7>2000.00</P_13_7><P_15>2000.00</P_15>
    <DodatkowyOpis><Klucz>Dodatkowy opis</Klucz><Wartosc>Olivia Nowak</Wartosc></DodatkowyOpis>
    <FaWiersz><NrWierszaFa>1</NrWierszaFa><P_7>Udział w turnusie Basketmania Camp</P_7><P_8A>usł.</P_8A><P_8B>1</P_8B><P_9A>2000.00</P_9A><P_11>2000.00</P_11><P_12>zw</P_12></FaWiersz>
    <Platnosc><Zaplacono>1</Zaplacono><DataZaplaty>2026-08-17</DataZaplaty><FormaPlatnosci>6</FormaPlatnosci></Platnosc>
  </Fa>
</Faktura>
XML;

$data = BCS_Release_091::parse_fa3($xml);
$check(!empty($data['success']), 'Parser powinien odczytać poprawny XML FA(3) niezależnie od namespace.');
$check(($data['invoice_number'] ?? '') === 'FV/12/2026', 'Parser powinien odczytać numer faktury P_2.');
$check(($data['issue_date'] ?? '') === '2026-08-17', 'Parser powinien odczytać datę wystawienia P_1.');
$check(($data['seller']['name'] ?? '') === 'Basketmania Test Sp. z o.o.', 'Parser powinien odczytać sprzedawcę z Podmiot1.');
$check(($data['seller']['nip'] ?? '') === '1234567890', 'Parser powinien odczytać NIP sprzedawcy.');
$check(($data['buyer']['name'] ?? '') === 'Klient Test Sp. z o.o.', 'Parser powinien odczytać nabywcę z Podmiot2.');
$check(($data['buyer']['address_l2'] ?? '') === '80-001 Gdańsk', 'Parser powinien odczytać pełny adres nabywcy.');
$check(count((array)($data['rows'] ?? [])) === 1, 'Parser powinien odczytać pozycje FaWiersz.');
$check(($data['rows'][0]['name'] ?? '') === 'Udział w turnusie Basketmania Camp', 'Wizualizacja powinna używać nazwy usługi zapisanej w XML.');
$check(($data['rows'][0]['vat_rate'] ?? '') === 'zw', 'Parser powinien odczytać stawkę VAT z P_12.');
$check(($data['net'] ?? '') === '2000.00' && ($data['gross'] ?? '') === '2000.00', 'Parser powinien odczytać wartości netto i brutto.');
$check(($data['payment']['paid'] ?? '') === '1' && ($data['payment']['form'] ?? '') === '6', 'Parser powinien odczytać dane płatności z FA(3).');
$check(($data['descriptions'][0]['value'] ?? '') === 'Olivia Nowak', 'Parser powinien wyświetlać DodatkowyOpis zapisany w KSeF.');

$check(str_contains($release, "remove_action('admin_post_bcs_invoice_view', ['BCS_Invoices', 'stream_invoice']);"), '0.91 powinno przejąć dotychczasową akcję podglądu PDF.');
$check(str_contains($release, "add_action('admin_post_bcs_invoice_view', [__CLASS__, 'preview_ksef_invoice']);"), 'Przycisk Podgląd powinien prowadzić do wizualizacji KSeF.');
$check(str_contains($release, "ksef_xml_path"), 'Podgląd powinien czytać zapisany plik XML KSeF.');
$check(str_contains($release, "file_get_contents($path)"), 'Podgląd powinien czytać istniejący XML bezpośrednio z pliku.');
$check(!str_contains($release, 'prepare_and_save('), 'Sam podgląd nie może ponownie generować lub modyfikować XML KSeF.');
$check(str_contains($release, 'Wizualizacja faktury ustrukturyzowanej FA(3)'), 'Podgląd powinien jasno wskazywać źródło FA(3).');
$check(str_contains($release, 'DodatkowyOpis'), 'Wizualizacja powinna uwzględniać dodatkowe opisy KSeF.');
$check(str_contains($release, 'id="bcs-invoice-modal"'), '0.91 powinno przywrócić modal wymagany przez admin.js.');
$check(str_contains($release, 'Wizualizacja faktury KSeF FA(3)'), 'Iframe modala powinien być opisany jako wizualizacja KSeF.');
$check(str_contains($release, "sanitize_key(wp_unslash($_GET['page'] ?? '')) !== 'bcs-invoices'"), 'Modal powinien być renderowany tylko w module Faktury.');
$check(str_contains($release, "check_admin_referer('bcs_invoice_view_'.$invoiceId)"), 'Podgląd powinien zachować istniejące zabezpieczenie nonce dla faktury.');
$check(str_contains($release, "current_user_can('manage_options')"), 'Podgląd powinien wymagać uprawnień administratora.');
$check(str_contains($adminJs, ".bcs-invoice-preview"), 'Istniejący admin.js powinien nadal obsługiwać przycisk Podgląd.');
$check(str_contains($adminJs, "document.getElementById('bcs-invoice-modal')"), 'Istniejący JS powinien korzystać z przywróconego modala.');

$check(str_contains($workflow, 'Release 0.91 KSeF invoice preview test'), 'CI powinno uruchamiać test 0.91.');
$check(str_contains($workflow, 'php tests/release-091-ksef-invoice-preview-test.php'), 'CI powinno wskazywać właściwy test podglądu KSeF.');

if ($failures) {
    fwrite(STDERR, "Release 0.91 KSeF invoice preview test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.91 KSeF invoice preview checks passed.\n";
