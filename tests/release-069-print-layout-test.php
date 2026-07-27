<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
define('BCS_DIR', dirname(__DIR__).'/');

final class BCS_Release_067 {
    public static function is_agreement_document(string $html, string $title=''): bool {
        return stripos($title, 'umowa') !== false || stripos($html, 'UMOWA UDZIAŁU') !== false;
    }
    public static function footer_text_from_html(string $html): string {
        return str_contains($html, 'Dane firmy') ? 'Dane firmy' : '';
    }
}

final class BCS_Release_068 {
    public static int $canvasCalls = 0;
    public static function render_agreement_view(): void {}
    public static function render_version_preview(): void {}
    public static function apply_canvas_header_footer(object $pdf, string $html, string $title=''): void {
        self::$canvasCalls++;
    }
}

$root = dirname(__DIR__);
$bootstrap = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-069.php');
$pdfSource = (string)file_get_contents($root.'/includes/class-bcs-pdf.php');
require_once $root.'/includes/class-bcs-release-069.php';

$fail = static function(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!str_contains($bootstrap, '* Version: 0.69') || !str_contains($bootstrap, "define('BCS_VERSION', '0.69')")) {
    $fail('Plugin version declarations are not synchronized at 0.69.');
}
foreach (['class-bcs-release-069.php', 'BCS_Release_069::init();'] as $needle) {
    if (!str_contains($bootstrap, $needle)) $fail('Release 0.69 is not loaded: '.$needle);
}

$sample = '<!doctype html><html lang="pl"><head>'
    .'<style id="old-screen">@page{margin:0}@media screen{.bcs-document-header,.bcs-document-footer{display:block!important}}</style>'
    .'<style id="old-page">@page{margin:92pt 39.7pt 64pt 39.7pt}</style>'
    .'</head><body><div class="bcs-document-052 bcs-document-068">'
    .'<div class="bcs-document-header"><img src="logo.png" alt="Basketmania Camp"></div>'
    .'<div class="bcs-document-footer"><div class="bcs-document-footer-text">Dane firmy</div></div>'
    .'<div class="bcs-document-content"><h1>UMOWA UDZIAŁU W OBOZIE</h1><p>Treść umowy</p>'
    .'<div class="bcs-attachment-page-068"><h2 class="bcs-attachment-start-055">ZAŁĄCZNIK NR 1<br>KARTA KWALIFIKACYJNA UCZESTNIKA WYPOCZYNKU</h2>'
    .'<h3>I. INFORMACJE DOTYCZĄCE WYPOCZYNKU</h3><p>Dane</p><table><tr><td>Uczestnik</td></tr></table></div>'
    .'<div class="proof bcs-proof-page-068"><h2>Sekcja dowodowa zawarcia umowy</h2><p>SMS</p></div>'
    .'</div></div></body></html>';

$prepared = BCS_Release_069::prepare_pdf_html($sample, 'Umowa testowa');
if (substr_count($prepared, '@page') !== 1) $fail('PDF still contains competing @page rules.');
foreach ([
    '@page{margin:104pt 39.7pt 66pt 39.7pt}',
    'bcs-document-069',
    'data-bcs-pdf-decoration="canvas-page-script-069"',
    'bcs-attachment-page-069',
    'bcs-proof-page-069',
    'page-break-before:always;break-before:page;page-break-after:always;break-after:page;',
    'font-size:8.15pt!important',
    'font-size:11pt!important',
    'font-size:8.65pt!important',
    'font-size:8.05pt!important',
    'font-size:7.85pt!important',
] as $needle) {
    if (!str_contains($prepared, $needle)) $fail('Prepared 0.69 PDF is missing: '.$needle);
}

$dom = new DOMDocument('1.0', 'UTF-8');
libxml_use_internal_errors(true);
$dom->loadHTML($prepared, LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
$xpath = new DOMXPath($dom);
foreach (['bcs-document-header','bcs-agreement-header','bcs-document-footer','bcs-agreement-footer'] as $class) {
    $query = "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]";
    if ($xpath->query($query)->length !== 0) $fail('Static PDF node was not removed: '.$class);
}
if (str_contains($prepared, '>Dane firmy<')) $fail('Static footer contents leaked into the PDF body.');

$preview = BCS_Release_069::prepare_preview_html($sample);
if (!str_contains($preview, 'bcs-document-header') || !str_contains($preview, 'bcs-document-footer')) {
    $fail('HTML preview lost its visible header or footer.');
}
if (!str_contains($preview, 'font-size:8.15pt!important')) {
    $fail('HTML preview does not use the enlarged attachment font.');
}

$dummyPdf = new stdClass();
BCS_Release_069::apply_canvas_header_footer($dummyPdf, $sample, 'Umowa testowa');
if (BCS_Release_068::$canvasCalls !== 1) $fail('0.69 does not delegate repeated Canvas drawing to the working 0.68 renderer.');

foreach ([
    "$options->set('defaultMediaType', 'print')",
    '$canvasSourceHtml = $html;',
    'BCS_Release_069::prepare_pdf_html($html, $title)',
    'BCS_Release_069::apply_canvas_header_footer($pdf, $canvasSourceHtml, $title)',
] as $needle) {
    if (!str_contains($pdfSource, $needle)) $fail('PDF pipeline is missing: '.$needle);
}
$preparePosition = strpos($pdfSource, 'BCS_Release_069::prepare_pdf_html');
$loadPosition = strpos($pdfSource, '$pdf->loadHtml($html');
$renderPosition = strpos($pdfSource, '$pdf->render();');
$canvasPosition = strpos($pdfSource, 'BCS_Release_069::apply_canvas_header_footer');
if ($preparePosition === false || $loadPosition === false || $preparePosition >= $loadPosition) {
    $fail('Static nodes are not removed before Dompdf loads the HTML.');
}
if ($renderPosition === false || $canvasPosition === false || $canvasPosition <= $renderPosition) {
    $fail('Canvas header/footer is not applied after page splitting.');
}

foreach ([
    "remove_action('admin_post_bcs_agreement_view', ['BCS_Release_068'",
    'remove_static_header_footer',
    'remove_page_rules',
    'strengthen_page_breaks',
] as $needle) {
    if (!str_contains($release, $needle)) $fail('Release 0.69 implementation is incomplete: '.$needle);
}

echo "Release 0.69 print layout checks passed.\n";
