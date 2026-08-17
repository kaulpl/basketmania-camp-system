<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $value): string { return strip_tags($value); }
}
if (!function_exists('esc_attr')) {
    function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root.'/basketmania-camp-system.php');
$pdf = file_get_contents($root.'/includes/class-bcs-pdf.php');
$release = file_get_contents($root.'/includes/class-bcs-release-057.php');
require_once $root.'/includes/class-bcs-release-057.php';

$fail = static function (string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

preg_match('/\* Version:\s*([0-9.]+)/', (string)$bootstrap, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", (string)$bootstrap, $constantVersion);
if (($headerVersion[1] ?? '') !== ($constantVersion[1] ?? '')
    || !version_compare((string)($headerVersion[1] ?? '0'), '0.57', '>=')) {
    $fail('plugin version is older than release 0.57');
}
if (!str_contains((string)$bootstrap, 'class-bcs-release-057.php') || !str_contains((string)$bootstrap, 'BCS_Release_057::init()')) {
    $fail('release 0.57 is not loaded and initialized');
}
if (!str_contains((string)$pdf, 'BCS_Release_057::prepare_agreement_html($html)')) {
    $fail('PDF renderer does not apply 0.57 formatting');
}

$input = '<div class="bcs-agreement">'
    .'<h2>ZAŁĄCZNIK NR 1<br>KARTA KWALIFIKACYJNA UCZESTNIKA WYPOCZYNKU</h2>'
    .'<h3>VI. INFORMACJA I SPOSTRZEŻENIA WYCHOWAWCY</h3>'
    .'<p>................................................................<br>................................................................<br>................................................................</p>'
    .'<div class="proof"><h2>Sekcja dowodowa zawarcia umowy</h2><p>Dowód.</p></div>'
    .'</div>';
$output = BCS_Release_057::prepare_agreement_html($input);

if (!str_contains($output, 'BASKETMANIA CAMP')) $fail('attachment heading is missing Basketmania Camp');
if (!str_contains($output, 'text-align:center')) $fail('attachment heading is not centered');
if (substr_count($output, '....................................................................................................................................................') !== 2) {
    $fail('educator section does not contain exactly two dotted lines');
}
if (!str_contains($output, 'bcs-proof-start-057') || !str_contains($output, 'page-break-before:always')) {
    $fail('proof section is not forced onto a new page');
}

foreach (['wp_ajax_'."'.self::AJAX_ACTION", 'ajax_generate_invoice', 'bcs-invoice-result-modal-057', 'event.stopImmediatePropagation()', 'updateInterface(result)'] as $needle) {
    if (!str_contains((string)$release, $needle)) $fail('missing invoice AJAX behavior: '.$needle);
}

fwrite(STDOUT, "Release 0.57 regression checks passed.\n");
