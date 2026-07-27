<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
define('BCS_DIR', dirname(__DIR__).'/');

final class BCS_Release_066 {
    public static function logo_data_uri(): string {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZKQAAAABJRU5ErkJggg==';
    }
    public static function render_agreement_view(): void {}
    public static function render_version_preview(): void {}
}

final class TestFontMetrics067 {
    public function getFont(string $family, string $style = 'normal'): string { return $family.'-'.$style; }
    public function getTextWidth(string $text, mixed $font, float $size): float { return mb_strlen($text, 'UTF-8') * $size * 0.48; }
}

final class TestCanvas067 {
    public int $currentPage = 0;
    public array $calls = [];
    public function get_width(): float { return 595.28; }
    public function get_height(): float { return 841.89; }
    public function page_script(callable $callback): void {
        $metrics = new TestFontMetrics067();
        for ($page = 1; $page <= 3; $page++) {
            $this->currentPage = $page;
            $callback($page, 3, $this, $metrics);
        }
    }
    private function record(string $type, array $args): void { $this->calls[$this->currentPage][] = ['type'=>$type, 'args'=>$args]; }
    public function image(string $path, float $x, float $y, float $width, float $height): void { $this->record('image', compact('path','x','y','width','height')); }
    public function line(float $x1, float $y1, float $x2, float $y2, array $color, float $width): void { $this->record('line', compact('x1','y1','x2','y2','color','width')); }
    public function filled_rectangle(float $x, float $y, float $width, float $height, array $color): void { $this->record('filled_rectangle', compact('x','y','width','height','color')); }
    public function text(float $x, float $y, string $text, mixed $font, float $size, array $color): void { $this->record('text', compact('x','y','text','font','size','color')); }
}

final class TestPdf067 {
    public TestCanvas067 $canvas;
    public function __construct() { $this->canvas = new TestCanvas067(); }
    public function getCanvas(): TestCanvas067 { return $this->canvas; }
    public function getFontMetrics(): TestFontMetrics067 { return new TestFontMetrics067(); }
}

$root = dirname(__DIR__);
$bootstrap = (string)file_get_contents($root.'/basketmania-camp-system.php');
$releaseSource = (string)file_get_contents($root.'/includes/class-bcs-release-067.php');
$pdfSource = (string)file_get_contents($root.'/includes/class-bcs-pdf.php');
require_once $root.'/includes/class-bcs-release-067.php';

$fail = static function(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$pluginVersion = '';
$constantVersion = '';
if (preg_match('/\* Version:\s*([0-9.]+)/', $bootstrap, $match)) $pluginVersion = $match[1];
if (preg_match("/define\('BCS_VERSION',\s*'([0-9.]+)'\)/", $bootstrap, $match)) $constantVersion = $match[1];
if (!version_compare($pluginVersion, '0.67', '>=') || !version_compare($constantVersion, '0.67', '>=')) {
    $fail('Plugin version must remain at least 0.67.');
}
if ($pluginVersion !== $constantVersion) $fail('Plugin header and BCS_VERSION are not synchronized.');
foreach (['class-bcs-release-067.php','BCS_Release_067::init();'] as $required) {
    if (!str_contains($bootstrap, $required)) $fail('Release 0.67 is not loaded: '.$required);
}

$sample = '<!doctype html><html lang="pl"><head><meta charset="utf-8"></head><body>'
    .'<div class="bcs-document-052 bcs-document-066">'
    .'<div class="bcs-document-header"><img src="logo.png" alt="Basketmania Camp"></div>'
    .'<div class="bcs-document-footer"><div class="bcs-document-footer-rule"></div><div class="bcs-document-footer-text">TK-Basket JDG · NIP: 1234567890 · kontakt@example.pl</div></div>'
    .'<div class="bcs-document-content"><h1>UMOWA UDZIAŁU W OBOZIE</h1><h2>§1 PRZEDMIOT UMOWY</h2><p><strong>Organizator</strong> zapewnia opiekę.</p></div>'
    .'</div></body></html>';

$prepared = BCS_Release_067::prepare_agreement_html($sample);
foreach ([
    'id="bcs-agreement-style-067"',
    'bcs-document-067',
    'data-bcs-pdf-decoration="canvas-page-script-067"',
    '.bcs-document-header,.bcs-document-footer{display:none!important}',
    '.bcs-document-content h2{',
    'color:#c2410c!important',
    '.bcs-document-content strong,.bcs-document-content b{color:#c2410c!important',
    '.bcs-document-header img{display:block!important;height:54px!important;width:auto!important;max-width:260px!important;margin:0 auto!important',
] as $required) {
    if (!str_contains($prepared, $required)) $fail('Agreement 0.67 HTML/CSS is incomplete: '.$required);
}
if (BCS_Release_067::footer_text_from_html($prepared) !== 'TK-Basket JDG · NIP: 1234567890 · kontakt@example.pl') {
    $fail('Footer company data cannot be extracted for Canvas rendering.');
}

$pdf = new TestPdf067();
BCS_Release_067::apply_canvas_header_footer($pdf, $prepared, 'Umowa testowa');
if (count($pdf->canvas->calls) !== 3) $fail('Canvas page script was not executed for every page.');
foreach ([1,2,3] as $page) {
    $types = array_column($pdf->canvas->calls[$page] ?? [], 'type');
    foreach (['image','filled_rectangle','text'] as $type) {
        if (!in_array($type, $types, true)) $fail("Page {$page} is missing Canvas {$type} output.");
    }
    if (count(array_filter($types, static fn(string $type): bool => $type === 'line')) < 2) {
        $fail("Page {$page} is missing the header or footer separator line.");
    }
    $images = array_values(array_filter($pdf->canvas->calls[$page], static fn(array $call): bool => $call['type'] === 'image'));
    $image = $images[0]['args'] ?? [];
    $expectedX = (595.28 - 112.0) / 2;
    if (abs((float)($image['x'] ?? 0) - $expectedX) > 0.01) $fail("Header logo is not centered on page {$page}.");
}

foreach ([
    'BCS_Release_067::prepare_agreement_html($html)',
    'BCS_Release_067::apply_canvas_header_footer($pdf, $html, $title)',
    '$canvas->page_script',
] as $required) {
    if (!str_contains($pdfSource.$releaseSource, $required)) $fail('Canvas integration is incomplete: '.$required);
}
$renderPosition = strpos($pdfSource, '$pdf->render();');
$canvasPosition = strpos($pdfSource, 'BCS_Release_067::apply_canvas_header_footer');
if ($renderPosition === false || $canvasPosition === false || $canvasPosition <= $renderPosition) {
    $fail('Canvas header/footer must be applied after Dompdf has rendered and split all pages.');
}

foreach ([
    "remove_action('admin_post_bcs_agreement_view', ['BCS_Release_066'",
    "remove_action('admin_post_bcs_agreement_version_preview_054', ['BCS_Release_066'",
    'buffer_agreement_html',
] as $required) {
    if (!str_contains($releaseSource, $required)) $fail('HTML previews do not use the 0.67 layout: '.$required);
}

echo "Release 0.67 repeated header/footer checks passed.\n";
