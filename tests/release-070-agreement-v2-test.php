<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
define('BCS_DIR', dirname(__DIR__).'/');

if (!function_exists('esc_html')) {
    function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('esc_attr')) {
    function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('absint')) {
    function absint(mixed $value): int { return abs((int)$value); }
}

$root = dirname(__DIR__);
$bootstrap = (string)file_get_contents($root.'/basketmania-camp-system.php');
$pdfSource = (string)file_get_contents($root.'/includes/class-bcs-pdf.php');
$rendererSource = (string)file_get_contents($root.'/includes/class-bcs-agreement-pdf-v2.php');
require_once $root.'/includes/class-bcs-agreement-pdf-v2.php';

$fail = static function(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!str_contains($bootstrap, '* Version: 0.70') || !str_contains($bootstrap, "define('BCS_VERSION', '0.70')")) {
    $fail('Plugin version declarations are not synchronized at 0.70.');
}
foreach (['class-bcs-agreement-pdf-v2.php', 'BCS_Agreement_PDF_V2::init();'] as $needle) {
    if (!str_contains($bootstrap, $needle)) $fail('Agreement V2 is not loaded: '.$needle);
}

$sample = '<!doctype html><html lang="pl"><head>'
    .'<style>@page{margin:0}@media screen{.bcs-document-header{display:block}}</style>'
    .'</head><body>'
    .'<div class="bcs-document-header"><img src="old-logo.png"></div>'
    .'<div class="bcs-document-footer"><span class="bcs-document-footer-text">TK-Basket JDG · NIP: 1234567890</span></div>'
    .'<div class="bcs-document-content"><div class="bcs-agreement">'
    .'<h1>UMOWA UDZIAŁU W OBOZIE KOSZYKARSKIM BASKETMANIA CAMP</h1>'
    .'<h2>§1 PRZEDMIOT UMOWY</h2><p style="margin:0">Treść umowy.</p>'
    .'<p><strong>Załącznik nr 1 - Karta kwalifikacyjna uczestnika wypoczynku</strong></p>'
    .'<h2 class="bcs-attachment-start-055">ZAŁĄCZNIK NR 1<br>KARTA KWALIFIKACYJNA UCZESTNIKA WYPOCZYNKU</h2>'
    .'<h3>I. INFORMACJE DOTYCZĄCE WYPOCZYNKU</h3><p>Dane wypoczynku</p>'
    .'<table><tr><td>Uczestnik</td><td>Jan Kowalski</td></tr></table>'
    .'</div><div class="proof bcs-proof-page-069"><h2>Sekcja dowodowa zawarcia umowy</h2><p>SMS rodzica</p></div>'
    .'</div></body></html>';

$prepared = BCS_Agreement_PDF_V2::prepare_pdf_html($sample, 'Umowa testowa', 0);
foreach ([
    'id="bcs-agreement-v2-style"',
    'class="bcs-agreement-v2"',
    'class="bcs-v2-header"',
    'class="bcs-v2-footer"',
    'class="bcs-v2-content"',
    'class="bcs-v2-attachment"',
    'bcs-v2-evidence',
    '@page{margin:32mm 15mm 20mm 15mm}',
    'position:fixed;top:-25mm',
    'position:fixed;bottom:-15mm',
    'page-break-before:always;break-before:page',
    'font-size:9pt',
] as $needle) {
    if (!str_contains($prepared, $needle)) $fail('V2 document is missing: '.$needle);
}
if (substr_count($prepared, '@page') !== 1) $fail('V2 document contains more than one @page rule.');
if (str_contains($prepared, 'old-logo.png') || str_contains($prepared, '@media screen{.bcs-document-header')) {
    $fail('Legacy header or CSS leaked into the V2 PDF.');
}
if (str_contains($prepared, 'style="margin:0"')) $fail('Legacy inline styles were not removed.');

$dom = new DOMDocument('1.0', 'UTF-8');
libxml_use_internal_errors(true);
$dom->loadHTML($prepared, LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
$xpath = new DOMXPath($dom);
$body = $dom->getElementsByTagName('body')->item(0);
if (!$body) $fail('V2 output has no BODY.');

$elementClasses = [];
foreach ($body->childNodes as $child) {
    if ($child instanceof DOMElement) $elementClasses[] = $child->getAttribute('class');
}
if (($elementClasses[0] ?? '') !== 'bcs-v2-header'
    || ($elementClasses[1] ?? '') !== 'bcs-v2-footer'
    || ($elementClasses[2] ?? '') !== 'bcs-v2-content') {
    $fail('Header and footer are not direct BODY children before the content.');
}
if ($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bcs-document-header ')]")->length) {
    $fail('Legacy header node remains in V2 output.');
}
if ($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bcs-document-footer ')]")->length) {
    $fail('Legacy footer node remains in V2 output.');
}

$attachment = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bcs-v2-attachment ')]")->item(0);
$evidence = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bcs-v2-evidence ')]")->item(0);
if (!$attachment || !$evidence) $fail('Attachment or evidence section was not separated.');
$attachmentText = mb_strtoupper((string)$attachment->textContent, 'UTF-8');
$evidenceText = mb_strtoupper((string)$evidence->textContent, 'UTF-8');
if (!str_contains($attachmentText, 'ZAŁĄCZNIK NR 1') || str_contains($attachmentText, 'SEKCJA DOWODOWA')) {
    $fail('Attachment V2 contains the wrong document range.');
}
if (!str_contains($evidenceText, 'SEKCJA DOWODOWA')) $fail('Evidence V2 lost its heading.');

$preview = BCS_Agreement_PDF_V2::prepare_preview_html($sample, 0);
if (!str_contains($preview, 'bcs-v2-header') || !str_contains($preview, 'bcs-v2-footer') || !str_contains($preview, '@media screen')) {
    $fail('V2 HTML preview is incomplete.');
}

foreach ([
    '$useAgreementV2 = class_exists(\'BCS_Agreement_PDF_V2\')',
    'BCS_Agreement_PDF_V2::prepare_pdf_html(',
    'if ($useAgreementV2) {',
    'if (!$useAgreementV2) {',
] as $needle) {
    if (!str_contains($pdfSource, $needle)) $fail('BCS_PDF does not route agreements through V2: '.$needle);
}
$v2Position = strpos($pdfSource, 'BCS_Agreement_PDF_V2::prepare_pdf_html');
$legacyPosition = strpos($pdfSource, 'BCS_Release_052::prepare_pdf_html');
if ($v2Position === false || $legacyPosition === false || $v2Position >= $legacyPosition) {
    $fail('V2 is not selected before the legacy agreement decorators.');
}
if (!str_contains($rendererSource, "remove_action('admin_post_bcs_agreement_view', ['BCS_Release_069'")) {
    $fail('V2 did not replace the legacy agreement preview handler.');
}
if (str_contains($rendererSource, 'page_script') || str_contains($rendererSource, 'apply_canvas_header_footer')) {
    $fail('V2 still depends on Canvas overlays.');
}

echo "Release 0.70 agreement V2 checks passed.\n";
