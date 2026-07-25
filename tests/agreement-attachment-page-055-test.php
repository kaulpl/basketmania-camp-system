<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $value): string { return strip_tags($value); }
}
if (!function_exists('esc_attr')) {
    function esc_attr(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root.'/basketmania-camp-system.php');
$pdf = file_get_contents($root.'/includes/class-bcs-pdf.php');
require_once $root.'/includes/class-bcs-release-055.php';

$fail = static function (string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!is_string($bootstrap) || !str_contains($bootstrap, 'Version: 0.55')) {
    $fail('plugin version is not 0.55');
}
if (!str_contains($bootstrap, "define('BCS_VERSION', '0.55')")) {
    $fail('BCS_VERSION is not 0.55');
}
if (!str_contains($bootstrap, 'class-bcs-release-055.php') || !str_contains($bootstrap, 'BCS_Release_055::init()')) {
    $fail('release 0.55 is not loaded and initialized');
}
if (!is_string($pdf) || !str_contains($pdf, 'BCS_Release_055::force_attachment_page($html)')) {
    $fail('PDF renderer does not apply the 0.55 page-break correction');
}

$input = '<!doctype html><html lang="pl"><head><meta charset="utf-8"></head><body>'
    .'<div class="bcs-document-content">'
    .'<h2>§10 POSTANOWIENIA KOŃCOWE</h2><p>Treść końcowa umowy.</p>'
    .'<p class="bcs-page-break-before bcs-keep-with-next"><strong>Załącznik nr 1 - Karta kwalifikacyjna uczestnika wypoczynku</strong></p>'
    .'<h2>ZAŁĄCZNIK NR 1<br>KARTA KWALIFIKACYJNA UCZESTNIKA WYPOCZYNKU<br>BASKETMANIA CAMP</h2>'
    .'<h3>I. INFORMACJE DOTYCZĄCE WYPOCZYNKU</h3>'
    .'</div></body></html>';

$output = BCS_Release_055::force_attachment_page($input);
if (!str_contains($output, 'bcs-attachment-start-055')) {
    $fail('attachment heading did not receive the dedicated start class');
}
if (substr_count($output, 'bcs-attachment-start-055') < 2) {
    $fail('attachment start class or CSS rule is missing');
}
if (!str_contains($output, 'page-break-before:always')) {
    $fail('forced page break declaration is missing');
}

$previous = libxml_use_internal_errors(true);
$dom = new DOMDocument('1.0', 'UTF-8');
$dom->loadHTML('<?xml encoding="utf-8" ?>'.$output);
libxml_clear_errors();
libxml_use_internal_errors($previous);
$xpath = new DOMXPath($dom);

$reference = $xpath->query('//p[contains(., "Załącznik nr 1")]')->item(0);
if (!$reference instanceof DOMElement) $fail('attachment reference paragraph was not found');
$referenceClasses = preg_split('/\s+/', trim($reference->getAttribute('class'))) ?: [];
if (in_array('bcs-page-break-before', $referenceClasses, true)) {
    $fail('page break is still attached to the reference paragraph');
}
if (!in_array('bcs-attachment-reference-055', $referenceClasses, true)) {
    $fail('reference paragraph was not explicitly normalized');
}

$heading = $xpath->query('//h2[contains(., "KARTA KWALIFIKACYJNA UCZESTNIKA WYPOCZYNKU")]')->item(0);
if (!$heading instanceof DOMElement) $fail('attachment heading was not found');
$headingClasses = preg_split('/\s+/', trim($heading->getAttribute('class'))) ?: [];
foreach (['bcs-page-break-before','bcs-keep-with-next','bcs-attachment-start-055'] as $class) {
    if (!in_array($class, $headingClasses, true)) $fail('heading is missing class '.$class);
}
if (!str_contains($heading->getAttribute('style'), 'margin-top:0')) {
    $fail('attachment heading is not aligned to the top of the new content page');
}

echo "Agreement attachment page 0.55 regression checks passed.\n";
