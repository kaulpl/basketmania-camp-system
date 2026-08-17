<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-084.php');
$workflow = (string)file_get_contents($root.'/.github/workflows/php-lint.yml');
require_once $root.'/includes/class-bcs-ksef-config.php';
require_once $root.'/includes/class-bcs-release-084.php';

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === '0.84', 'Nagłówek wtyczki powinien mieć wersję 0.84.');
$check(($constantVersion[1] ?? '') === '0.84', 'BCS_VERSION powinno mieć wersję 0.84.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-084.php';"), 'Bootstrap powinien ładować release 0.84.');
$check(str_contains($plugin, 'BCS_Release_084::init();'), 'Bootstrap powinien inicjalizować release 0.84.');

$check(str_contains($release, "'invoice_ksef_description'"), '0.84 powinno przechowywać opis przesłany przez rodzica.');
$check(str_contains($release, "'billing_ksef_description'"), '0.84 powinno mieć osobny edytowalny opis administracyjny.');
$check(str_contains($release, "textarea.name='invoice_ksef_description'"), 'Formularz rodzica powinien zawierać pole dodatkowego opisu KSeF.');
$check(str_contains($release, "textarea.maxLength=256"), 'Pole rodzica powinno mieć limit 256 znaków.');
$check(str_contains($release, "child_first_name"), 'Domyślny opis powinien korzystać z imienia uczestnika.');
$check(str_contains($release, "child_last_name"), 'Domyślny opis powinien korzystać z nazwiska uczestnika.');
$check(str_contains($release, "area.name='billing_ksef_description'"), 'Zakładka Dane do Faktury powinna mieć edytowalny opis KSeF.');
$check(str_contains($release, "if (!empty(\$r->invoice_real_id))"), 'Zapis profilu powinien pozostać zablokowany po wystawieniu faktury.');
$check(str_contains($release, "'billing_ksef_description'=>\$candidate->billing_ksef_description"), 'Edycja administratora powinna zapisywać opis używany do KSeF.');

foreach ([
    "remove_action('wp_ajax_bcs_ksef_generate_invoice_full_076', ['BCS_Release_083', 'ajax_real_generate'], -100);",
    "remove_action('wp_ajax_bcs_list_quick_action_02010', ['BCS_Release_083', 'ajax_list_generate'], -100);",
    "remove_action('wp_ajax_bcs_generate_invoice_0200', ['BCS_Release_083', 'ajax_legacy_generate'], -100);",
] as $needle) $check(str_contains($release, $needle), '0.84 powinno przejąć wszystkie ścieżki generowania faktury z 0.83.');
$check(str_contains($release, 'BCS_KSeF_FA3::prepare_and_save((int)$invoice->id)'), '0.84 powinno przygotować bazowy XML FA(3).');
$check(str_contains($release, 'BCS_Release_082::buyer_snapshots_match($expected, $actual)'), '0.84 musi zachować kontrolę nabywcy PDF ↔ KSeF.');
$check(str_contains($release, 'BCS_KSeF_Service::send((int)$invoice->id)'), 'Po zapisaniu XML 0.84 powinno wysłać dokładnie przygotowany dokument przez KSeF Service.');
$check(str_contains($release, "'DodatkowyOpis'"), 'Generator powinien tworzyć element Fa/DodatkowyOpis.');
$check(str_contains($release, "'Klucz'"), 'DodatkowyOpis powinien zawierać Klucz.');
$check(str_contains($release, "'Wartosc'"), 'DodatkowyOpis powinien zawierać Wartosc.');

$ns = BCS_KSeF_Config::FA3_NAMESPACE;
$baseXml = '<?xml version="1.0" encoding="UTF-8"?>'
    .'<Faktura xmlns="'.$ns.'"><Fa><RodzajFaktury>VAT</RodzajFaktury>'
    .'<FaWiersz><NrWierszaFa>1</NrWierszaFa></FaWiersz><Platnosc><Zaplacono>1</Zaplacono></Platnosc>'
    .'</Fa></Faktura>';
$result = BCS_Release_084::inject_additional_description($baseXml, 'Jan Kowalski');
$check(!empty($result['success']), 'Wstrzyknięcie dodatkowego opisu powinno się udać.');

$dom = new DOMDocument();
$check($dom->loadXML((string)($result['xml'] ?? ''), LIBXML_NONET), 'XML po modyfikacji powinien być poprawnym XML-em.');
$xpath = new DOMXPath($dom); $xpath->registerNamespace('fa', $ns);
$nodes = $xpath->query('/fa:Faktura/fa:Fa/fa:DodatkowyOpis');
$check($nodes && $nodes->length === 1, 'Powinien istnieć dokładnie jeden DodatkowyOpis.');
$check(trim((string)$xpath->evaluate('string(/fa:Faktura/fa:Fa/fa:DodatkowyOpis/fa:Klucz)')) === 'Dodatkowy opis', 'Klucz DodatkowyOpis powinien być stały i czytelny.');
$check(trim((string)$xpath->evaluate('string(/fa:Faktura/fa:Fa/fa:DodatkowyOpis/fa:Wartosc)')) === 'Jan Kowalski', 'Wartosc powinna zawierać przekazany opis.');

$fa = $xpath->query('/fa:Faktura/fa:Fa')->item(0);
$order = [];
if ($fa) foreach ($fa->childNodes as $child) if ($child instanceof DOMElement) $order[] = $child->localName;
$extraPos = array_search('DodatkowyOpis', $order, true);
$linePos = array_search('FaWiersz', $order, true);
$check($extraPos !== false && $linePos !== false && $extraPos < $linePos, 'DodatkowyOpis powinien występować przed FaWiersz zgodnie z kolejnością FA(3).');

$second = BCS_Release_084::inject_additional_description((string)$result['xml'], 'Anna Nowak');
$dom2 = new DOMDocument(); $dom2->loadXML((string)($second['xml'] ?? ''), LIBXML_NONET);
$xpath2 = new DOMXPath($dom2); $xpath2->registerNamespace('fa', $ns);
$nodes2 = $xpath2->query('/fa:Faktura/fa:Fa/fa:DodatkowyOpis[fa:Klucz="Dodatkowy opis"]');
$check($nodes2 && $nodes2->length === 1, 'Ponowna edycja opisu nie może tworzyć duplikatu.');
$check(trim((string)$xpath2->evaluate('string(/fa:Faktura/fa:Fa/fa:DodatkowyOpis/fa:Wartosc)')) === 'Anna Nowak', 'Ponowna edycja powinna podmienić wartość opisu.');

$long = str_repeat('A', 400);
$trimmed = BCS_Release_084::inject_additional_description($baseXml, $long);
$dom3 = new DOMDocument(); $dom3->loadXML((string)($trimmed['xml'] ?? ''), LIBXML_NONET);
$xpath3 = new DOMXPath($dom3); $xpath3->registerNamespace('fa', $ns);
$value = (string)$xpath3->evaluate('string(/fa:Faktura/fa:Fa/fa:DodatkowyOpis/fa:Wartosc)');
$check(strlen($value) === 256, 'Wartość DodatkowyOpis powinna być ograniczona do 256 znaków.');

$check(str_contains($workflow, 'Release 0.84 KSeF additional description test'), 'CI powinno uruchamiać test 0.84.');
$check(str_contains($workflow, 'php tests/release-084-ksef-additional-description-test.php'), 'CI powinno uruchamiać właściwy plik testu 0.84.');

if ($failures) {
    fwrite(STDERR, "Release 0.84 KSeF additional description test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.84 KSeF additional description checks passed.\n";