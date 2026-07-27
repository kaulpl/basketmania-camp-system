<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
define('BCS_DIR', dirname(__DIR__).'/');

final class BCS_Release_066 {
    public static function logo_data_uri(): string {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZKQAAAABJRU5ErkJggg==';
    }
}
final class BCS_Release_067 {
    public static function is_agreement_document(string $html, string $title=''): bool { return stripos($title, 'umowa') !== false || stripos($html, 'UMOWA UDZIAŁU') !== false; }
    public static function footer_text_from_html(string $html): string { return 'TK-Basket JDG · NIP: 1234567890 · kontakt@example.pl'; }
    public static function render_agreement_view(): void {}
    public static function render_version_preview(): void {}
}

final class TestMetrics068 {
    public function getFont(string $family, string $style='normal'): string { return $family.'-'.$style; }
    public function getTextWidth(string $text, mixed $font, float $size): float { return mb_strlen($text, 'UTF-8') * $size * 0.48; }
}
final class TestCanvas068 {
    public int $page = 0;
    public array $calls = [];
    public function get_width(): float { return 595.28; }
    public function get_height(): float { return 841.89; }
    public function page_script(callable $callback): void {
        $metrics = new TestMetrics068();
        for ($page=1; $page<=3; $page++) { $this->page=$page; $callback($page, 3, $this, $metrics); }
    }
    private function record(string $type, array $args): void { $this->calls[$this->page][]=['type'=>$type,'args'=>$args]; }
    public function image(string $path,float $x,float $y,float $width,float $height): void { $this->record('image',compact('path','x','y','width','height')); }
    public function line(float $x1,float $y1,float $x2,float $y2,array $color,float $width): void { $this->record('line',compact('x1','y1','x2','y2','color','width')); }
    public function filled_rectangle(float $x,float $y,float $width,float $height,array $color): void { $this->record('filled_rectangle',compact('x','y','width','height','color')); }
    public function text(float $x,float $y,string $text,mixed $font,float $size,array $color): void { $this->record('text',compact('x','y','text','font','size','color')); }
}
final class TestPdf068 {
    public TestCanvas068 $canvas;
    public function __construct() { $this->canvas=new TestCanvas068(); }
    public function getCanvas(): TestCanvas068 { return $this->canvas; }
    public function getFontMetrics(): TestMetrics068 { return new TestMetrics068(); }
}

$root=dirname(__DIR__);
$bootstrap=(string)file_get_contents($root.'/basketmania-camp-system.php');
$release=(string)file_get_contents($root.'/includes/class-bcs-release-068.php');
$pdfSource=(string)file_get_contents($root.'/includes/class-bcs-pdf.php');
require_once $root.'/includes/class-bcs-release-068.php';

$fail=static function(string $message): void { fwrite(STDERR,"FAIL: {$message}\n"); exit(1); };

if (!preg_match('/\* Version:\s*([0-9.]+)/', $bootstrap, $versionMatch)
    || version_compare($versionMatch[1], '0.68', '<')
    || !preg_match("/define\('BCS_VERSION',\s*'([0-9.]+)'\)/", $bootstrap, $constantMatch)
    || version_compare($constantMatch[1], '0.68', '<')) {
    $fail('Plugin version is older than 0.68.');
}
foreach (['class-bcs-release-068.php','BCS_Release_068::init();'] as $needle) if (!str_contains($bootstrap,$needle)) $fail('Release 0.68 is not loaded: '.$needle);

$sample='<!doctype html><html lang="pl"><head><style id="old-a">@page{margin:0} body{color:#000}</style><style id="old-b">@page{margin:29mm 14mm 24mm 14mm}</style></head><body>'
    .'<div class="bcs-document-052 bcs-document-067"><div class="bcs-document-header"><img src="logo.png"></div>'
    .'<div class="bcs-document-footer"><div class="bcs-document-footer-text">Dane firmy</div></div>'
    .'<div class="bcs-document-content"><div class="bcs-agreement"><h1>UMOWA UDZIAŁU W OBOZIE</h1><p>Treść umowy</p>'
    .'<h2 class="bcs-attachment-start-055">ZAŁĄCZNIK NR 1<br>KARTA KWALIFIKACYJNA UCZESTNIKA WYPOCZYNKU<br>BASKETMANIA CAMP</h2>'
    .'<h3>I. INFORMACJE DOTYCZĄCE WYPOCZYNKU</h3><p>Dane wypoczynku</p><table><tr><td>Uczestnik</td><td>Jan Kowalski</td></tr></table>'
    .'<h3>VI. INFORMACJA I SPOSTRZEŻENIA WYCHOWAWCY</h3><p>................<br>................</p>'
    .'<div class="proof bcs-proof-start-057"><h2>Sekcja dowodowa zawarcia umowy</h2><p>SMS rodzica</p></div>'
    .'</div></div></div></body></html>';
$prepared=BCS_Release_068::prepare_agreement_html($sample);

if (substr_count($prepared,'@page') !== 1) $fail('Conflicting @page rules were not reduced to one canonical rule.');
foreach ([
    '@page{margin:92pt 39.7pt 64pt 39.7pt}',
    'bcs-document-068',
    'data-bcs-pdf-decoration="canvas-page-script-068"',
    'class="bcs-attachment-page-068"',
    'bcs-proof-page-068',
    'page-break-after:always!important',
    'font-size:6.85pt!important',
    '.bcs-document-footer{display:block!important;background:#fff!important;color:#f97316!important',
    '.bcs-document-footer-rule{background:#f97316!important',
] as $needle) if (!str_contains($prepared,$needle)) $fail('Prepared agreement is missing: '.$needle);

$attachmentPos=strpos($prepared,'class="bcs-attachment-page-068"');
$proofPos=$attachmentPos===false ? false : strpos($prepared,'bcs-proof-page-068',$attachmentPos+1);
if ($attachmentPos===false || $proofPos===false || $attachmentPos >= $proofPos) $fail('Attachment and proof sections are not ordered as separate pages.');

$pdf=new TestPdf068();
BCS_Release_068::apply_canvas_header_footer($pdf,$prepared,'Umowa testowa');
if (count($pdf->canvas->calls)!==3) $fail('Canvas decoration was not executed on every page.');
$orange=[249/255,115/255,22/255];
$white=[1,1,1];
foreach ([1,2,3] as $page) {
    $calls=$pdf->canvas->calls[$page]??[];
    $images=array_values(array_filter($calls,static fn(array $c):bool=>$c['type']==='image'));
    $rects=array_values(array_filter($calls,static fn(array $c):bool=>$c['type']==='filled_rectangle'));
    $texts=array_values(array_filter($calls,static fn(array $c):bool=>$c['type']==='text'));
    $lines=array_values(array_filter($calls,static fn(array $c):bool=>$c['type']==='line'));
    if (!$images || !$rects || !$texts || count($lines)<2) $fail("Page {$page} is missing header/footer Canvas output.");
    if (abs((float)$images[0]['args']['x']-((595.28-112.0)/2))>0.01) $fail("Logo is not centered on page {$page}.");
    if ($rects[0]['args']['color']!==$white) $fail("Footer background is not white on page {$page}.");
    if ($texts[0]['args']['color']!==$orange) $fail("Footer text is not orange on page {$page}.");
    $footerLine=$lines[count($lines)-1]['args'];
    if ($footerLine['color']!==$orange) $fail("Footer separator is not orange on page {$page}.");
}

foreach ([
    'BCS_Release_068::prepare_agreement_html($html)',
    'BCS_Release_068::apply_canvas_header_footer($pdf, $html, $title)',
    '} elseif (class_exists(\'BCS_Release_067\')) {',
] as $needle) if (!str_contains($pdfSource,$needle)) $fail('PDF pipeline is missing: '.$needle);
$renderPos=strpos($pdfSource,'$pdf->render();');
$canvasPos=strpos($pdfSource,'BCS_Release_068::apply_canvas_header_footer');
if ($renderPos===false || $canvasPos===false || $canvasPos<=$renderPos) $fail('0.68 Canvas must be applied after render.');

foreach ([
    "remove_action('admin_post_bcs_agreement_view', ['BCS_Release_067'",
    "remove_action('admin_post_bcs_agreement_version_preview_054', ['BCS_Release_067'",
    'remove_previous_page_rules',
    'mark_attachment_and_proof',
] as $needle) if (!str_contains($release,$needle)) $fail('Release 0.68 implementation is incomplete: '.$needle);

echo "Release 0.68 agreement pagination checks passed.\n";
