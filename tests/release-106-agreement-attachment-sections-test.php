<?php

declare(strict_types=1);

if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string { return strip_tags($text); }
}

$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$r106 = (string)file_get_contents($root.'/includes/class-bcs-release-106.php');
require_once $root.'/includes/class-bcs-release-106.php';

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === ($constantVersion[1] ?? ''), 'Nagłówek i BCS_VERSION powinny być zgodne.');
$check(version_compare((string)($headerVersion[1] ?? '0'), '1.06', '>='), 'Wtyczka powinna mieć wersję co najmniej 1.06.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-106.php';"), 'Bootstrap powinien ładować release 1.06.');
$check(str_contains($plugin, 'BCS_Release_106::init();'), 'Bootstrap powinien inicjalizować release 1.06.');

$sample = '<h2>ZAŁĄCZNIK NR 1<br>KARTA KWALIFIKACYJNA UCZESTNIKA WYPOCZYNKU</h2>'
    .'<h3>I. INFORMACJE DOTYCZĄCE WYPOCZYNKU</h3><p>Dane I</p>'
    .'<h3>II. INFORMACJE DOTYCZĄCE UCZESTNIKA WYPOCZYNKU</h3><table><tr><td>Dane II</td></tr></table>'
    .'<h3>III. DECYZJA ORGANIZATORA O ZAKWALIFIKOWANIU UCZESTNIKA</h3><p>Dane III</p>'
    .'<h3>IV. POTWIERDZENIE POBYTU UCZESTNIKA</h3><p>Dane IV</p>'
    .'<h3>V. INFORMACJA KIEROWNIKA O STANIE ZDROWIA UCZESTNIKA W CZASIE OBOZU</h3><p>Dane V</p>'
    .'<h3>VI. INFORMACJA I SPOSTRZEŻENIA WYCHOWAWCY</h3><p>Dane VI</p>';

$out = BCS_Release_106::transform_agreement_template($sample);
$parent = 'Wypełniają rodzice/opiekunowie';
$organizer = 'Wypełnia organizator wypoczynku';
$signature = 'Podpis organizatora wypoczynku:';

$check(str_contains($out, $parent), 'Przed sekcjami I–II musi pojawić się oznaczenie dla rodziców/opiekunów.');
$check(str_contains($out, $organizer), 'Przed sekcjami III–VI musi pojawić się oznaczenie dla organizatora wypoczynku.');
$check(str_contains($out, '<strong>Data:</strong>'), 'Pod częścią Organizatora musi być wykropkowane miejsce na datę.');
$check(str_contains($out, $signature), 'Pod częścią Organizatora musi być wykropkowane miejsce na podpis organizatora wypoczynku.');
$check(substr_count($out, '................................................................') >= 2, 'Załącznik powinien mieć co najmniej dwa wykropkowane pola do ręcznego wypełnienia.');

$parentPos = strpos($out, $parent);
$iPos = strpos($out, 'I. INFORMACJE DOTYCZĄCE WYPOCZYNKU');
$iiPos = strpos($out, 'II. INFORMACJE DOTYCZĄCE UCZESTNIKA WYPOCZYNKU');
$orgPos = strpos($out, $organizer);
$iiiPos = strpos($out, 'III. DECYZJA ORGANIZATORA');
$viPos = strpos($out, 'VI. INFORMACJA I SPOSTRZEŻENIA WYCHOWAWCY');
$datePos = strpos($out, '<strong>Data:</strong>');
$signaturePos = strpos($out, $signature);

$check($parentPos !== false && $iPos !== false && $parentPos < $iPos, 'Oznaczenie rodziców musi znajdować się przed sekcją I.');
$check($iiPos !== false && $orgPos !== false && $iiPos < $orgPos, 'Oznaczenie organizatora musi zaczynać się dopiero po sekcji II.');
$check($orgPos !== false && $iiiPos !== false && $orgPos < $iiiPos, 'Oznaczenie organizatora musi znajdować się przed sekcją III.');
$check($viPos !== false && $datePos !== false && $signaturePos !== false && $viPos < $datePos && $viPos < $signaturePos, 'Data i podpis organizatora muszą znajdować się pod sekcją VI.');

// Transformacja nie może dublować bloków przy ponownym uruchomieniu migracji.
$second = BCS_Release_106::transform_agreement_template($out);
$check(substr_count($second, $parent) === 1, 'Oznaczenie rodziców nie może być dublowane.');
$check(substr_count($second, $organizer) === 1, 'Oznaczenie organizatora nie może być dublowane.');
$check(substr_count($second, $signature) === 1, 'Pole podpisu organizatora nie może być dublowane.');

// 1.06 aktualizuje wzór na przyszłość, a nie historyczne podpisane umowy.
$check(str_contains($r106, "get_option('bcs_content_templates'"), 'Migracja powinna aktualizować edytowalny wzór w module Szablony.');
$check(str_contains($r106, "update_option('bcs_content_templates'"), 'Zmodyfikowany wzór powinien zostać zapisany w Szablonach.');
$check(!str_contains($r106, "BCS_DB::table('agreement_versions')"), '1.06 nie może przepisywać historycznych wersji podpisanych umów.');
$check(!str_contains($r106, "BCS_DB::table('agreements')"), '1.06 nie może modyfikować rekordów istniejących umów.');

if ($failures) {
    fwrite(STDERR, "Release 1.06 agreement attachment sections test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 1.06 agreement attachment sections checks passed.\n";
