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
$autoload = $root.'/vendor/autoload.php';
if (!is_readable($autoload)) {
    fwrite(STDERR, "FAIL: Composer dependencies are missing.\n");
    exit(1);
}
require_once $autoload;
require_once $root.'/includes/class-bcs-agreement-pdf-v2.php';
require_once $root.'/includes/class-bcs-release-071.php';
require_once $root.'/includes/class-bcs-agreement-pdf-v2-finalizer.php';

$paragraph = '<p>Organizator zapewnia bezpieczne warunki wypoczynku, opiekę wychowawczą, zakwaterowanie, wyżywienie oraz realizację programu sportowego zgodnie z umową.</p>';
$main = '<div class="bcs-agreement"><h1>UMOWA UDZIAŁU W OBOZIE KOSZYKARSKIM BASKETMANIA CAMP</h1>'
    .'<h2>§1 PRZEDMIOT UMOWY</h2>'.str_repeat($paragraph, 30)
    .'<h2>§2 OBOWIĄZKI STRON</h2>'.str_repeat($paragraph, 30)
    .'<p><strong>Załącznik nr 1 - Karta kwalifikacyjna uczestnika wypoczynku</strong></p>'
    .'<h2>ZAŁĄCZNIK NR 1<br>KARTA KWALIFIKACYJNA UCZESTNIKA WYPOCZYNKU</h2>'
    .'<h3>I. INFORMACJE DOTYCZĄCE WYPOCZYNKU</h3><p>Forma wypoczynku: obóz sportowy</p>'
    .'<h3>II. INFORMACJE DOTYCZĄCE UCZESTNIKA</h3>'
    .'<table>'.str_repeat('<tr><td>Informacja</td><td>Przykładowa wartość uczestnika</td></tr>', 13).'</table>'
    .'<h3>III. DECYZJA ORGANIZATORA</h3><p>....................................................................................................................</p>'
    .'<h3>IV. POTWIERDZENIE POBYTU</h3><p>....................................................................................................................</p>'
    .'<h3>V. INFORMACJA KIEROWNIKA</h3><p>....................................................................................................................</p>'
    .'<h3>VI. INFORMACJA I SPOSTRZEŻENIA WYCHOWAWCY</h3><p>....................................................................................................................</p>'
    .'</div>'
    .'<div class="proof"><h2>Sekcja dowodowa zawarcia umowy</h2>'
    .'<table><tr><th>Potwierdzenie Organizatora</th><th>Potwierdzenie Rodzica / Opiekuna</th></tr>'
    .'<tr><td>Status: potwierdzona kodem SMS</td><td>Status: potwierdzona kodem SMS</td></tr></table></div>';
$source = '<!doctype html><html lang="pl"><head><meta charset="utf-8"><style>@page{margin:0}</style></head><body>'.$main.'</body></html>';
$html = BCS_Agreement_PDF_V2_Finalizer::finalize(
    BCS_Agreement_PDF_V2::prepare_pdf_html($source, 'Umowa testowa', 0)
);

if (!str_contains($html, '@page{margin:0;}')) {
    fwrite(STDERR, "FAIL: Final Dompdf HTML does not reset @page before applying BODY margins.\n");
    exit(1);
}
if (!str_contains($html, 'body{margin:32mm 15mm 20mm 15mm;')) {
    fwrite(STDERR, "FAIL: Final Dompdf HTML does not contain explicit safe BODY margins.\n");
    exit(1);
}
if (str_contains($html, 'html,body{margin:0') || str_contains($html, 'body{margin:0')) {
    fwrite(STDERR, "FAIL: BODY still resets Dompdf page margins to zero.\n");
    exit(1);
}
if (str_contains($html, 'Załącznik nr 1 - Karta kwalifikacyjna uczestnika wypoczynku')) {
    fwrite(STDERR, "FAIL: Redundant attachment reference was not removed before rendering.\n");
    exit(1);
}

$layoutDom = new DOMDocument('1.0', 'UTF-8');
libxml_use_internal_errors(true);
$layoutDom->loadHTML($html, LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
$layoutXpath = new DOMXPath($layoutDom);
$evidenceTable = $layoutXpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bcs-v2-evidence-table ')]")->item(0);
if (!$evidenceTable instanceof DOMElement) {
    fwrite(STDERR, "FAIL: The 0.71 evidence table was not created before Dompdf rendering.\n");
    exit(1);
}
$evidenceRows = $layoutXpath->query('./tbody/tr|./tr', $evidenceTable);
if ($evidenceRows->length !== 2) {
    fwrite(STDERR, "FAIL: The evidence table does not have exactly two rows.\n");
    exit(1);
}
$expectedRoles = ['parent', 'organizer'];
foreach ($evidenceRows as $index => $row) {
    if (!$row instanceof DOMElement || $row->getAttribute('data-evidence-role') !== $expectedRoles[$index]) {
        fwrite(STDERR, "FAIL: Evidence rows are not ordered Parent first, Organizer second.\n");
        exit(1);
    }
    if ($layoutXpath->query('./td|./th', $row)->length !== 1) {
        fwrite(STDERR, "FAIL: An evidence row contains more than one column.\n");
        exit(1);
    }
}

$htmlPath = getenv('BCS_TEST_HTML_PATH') ?: sys_get_temp_dir().'/agreement-v2-0.71.html';
if (file_put_contents($htmlPath, $html) === false) {
    fwrite(STDERR, "FAIL: Final V2 HTML artifact could not be saved.\n");
    exit(1);
}

$options = new Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('defaultMediaType', 'print');
$options->set('chroot', $root);

$pdf = new Dompdf\Dompdf($options);
$pdf->setPaper('A4', 'portrait');
$pdf->loadHtml($html, 'UTF-8');
$pdf->render();

$pageCount = (int)$pdf->getCanvas()->get_page_count();
if ($pageCount < 4) {
    fwrite(STDERR, "FAIL: V2 render produced only {$pageCount} pages; expected main agreement, attachment and evidence pages.\n");
    exit(1);
}

$output = $pdf->output();
if (!str_starts_with($output, '%PDF-') || strlen($output) < 10000) {
    fwrite(STDERR, "FAIL: Dompdf did not produce a valid non-empty PDF.\n");
    exit(1);
}

$path = getenv('BCS_TEST_PDF_PATH') ?: sys_get_temp_dir().'/agreement-v2-0.71.pdf';
if (file_put_contents($path, $output) === false || !is_readable($path)) {
    fwrite(STDERR, "FAIL: Rendered PDF artifact could not be saved.\n");
    exit(1);
}

echo "Release 0.71 Dompdf render passed ({$pageCount} pages): {$path}\n";
