<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
define('BCS_DIR', dirname(__DIR__).'/');

$root = dirname(__DIR__);
$bootstrap = (string)file_get_contents($root.'/basketmania-camp-system.php');
$releaseSource = (string)file_get_contents($root.'/includes/class-bcs-release-066.php');
$pdfSource = (string)file_get_contents($root.'/includes/class-bcs-pdf.php');
require_once $root.'/includes/class-bcs-release-066.php';

$fail = static function(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$pluginVersion = '';
$constantVersion = '';
if (preg_match('/\* Version:\s*([0-9.]+)/', $bootstrap, $match)) $pluginVersion = $match[1];
if (preg_match("/define\('BCS_VERSION',\s*'([0-9.]+)'\)/", $bootstrap, $match)) $constantVersion = $match[1];
if (!version_compare($pluginVersion, '0.66', '>=') || !version_compare($constantVersion, '0.66', '>=')) {
    $fail('Plugin version must remain at least 0.66.');
}
if ($pluginVersion !== $constantVersion) {
    $fail('Plugin header and BCS_VERSION are not synchronized.');
}
if (!str_contains($bootstrap, 'class-bcs-release-066.php') || !str_contains($bootstrap, 'BCS_Release_066::init();')) {
    $fail('Release 0.66 is not loaded and initialized.');
}

$expectedLogo = 'https://b4080341.smushcdn.com/4080341/wp-content/uploads/2026/07/basketmania-logo-navy-300x128.png?lossy=2&strip=1&avif=1';
if (BCS_Release_066::logo_url() !== $expectedLogo) {
    $fail('The agreement header does not use the requested Basketmania Camp logo source.');
}

$sample = '<!doctype html><html lang="pl"><head><meta charset="utf-8"></head><body>'
    .'<div class="bcs-document-052">'
    .'<div class="bcs-document-header"><img src="old-logo.png" alt="Old"></div>'
    .'<div class="bcs-document-content"><h1>UMOWA UDZIAŁU W OBOZIE</h1><div style="background:#eee">Treść umowy</div></div>'
    .'<div class="bcs-document-footer">Basketmania Camp Sp. z o.o. · NIP: 1234567890</div>'
    .'</div></body></html>';
$rendered = BCS_Release_066::prepare_agreement_html($sample);
$decodedRendered = html_entity_decode($rendered, ENT_QUOTES | ENT_HTML5, 'UTF-8');

foreach ([
    'id="bcs-agreement-style-066"',
    'class="bcs-document-footer-rule"',
    'class="bcs-document-footer-text"',
    'data-bcs-logo-source=',
    'background:#fff!important',
    'position:fixed!important',
    'background:#172033!important',
    'color:#fff!important',
] as $required) {
    if (!str_contains($rendered, $required)) $fail('The final agreement layout is missing: '.$required);
}
if (!str_contains($decodedRendered, $expectedLogo)) {
    $fail('The rendered header does not retain the requested logo URL as its source metadata.');
}

$footerPosition = strpos($rendered, '<div class="bcs-document-footer"');
$contentPosition = strpos($rendered, '<div class="bcs-document-content"');
if ($footerPosition === false || $contentPosition === false || $footerPosition >= $contentPosition) {
    $fail('The fixed footer is not placed before flowing content, so Dompdf may show it only on the last page.');
}

$css = BCS_Release_066::agreement_css();
foreach ([
    '@page{margin:27mm 14mm 25mm 14mm;background:#fff}',
    '.bcs-document-content *{background:#fff!important',
    '.bcs-document-footer-rule{display:block!important;height:1px!important',
    '.proof,.bcs-proof-page,.bcs-proof-start-057',
    'box-shadow:none!important',
] as $required) {
    if (!str_contains($css, $required)) $fail('White-page agreement CSS is incomplete: '.$required);
}

if (!str_contains($pdfSource, 'BCS_Release_066::prepare_agreement_html($html)')) {
    $fail('The 0.66 decorator is missing from PDF rendering.');
}
$release057 = strpos($pdfSource, 'BCS_Release_057::prepare_agreement_html');
$release066 = strpos($pdfSource, 'BCS_Release_066::prepare_agreement_html');
if ($release057 === false || $release066 === false || $release066 <= $release057) {
    $fail('Release 0.66 must run after the previous agreement layout layers.');
}

foreach ([
    '.bcs-row-action-marker::after{content:"Wymaga akcji"',
    "'wymagające akcji'",
    "node.textContent = 'Wymaga akcji'",
    "node.setAttribute('aria-label', 'Wymaga akcji')",
] as $required) {
    if (!str_contains($releaseSource, $required)) $fail('Action-required labels are not consistently normalized: '.$required);
}

foreach ([
    "remove_action('admin_post_bcs_agreement_view'",
    "remove_action('admin_post_bcs_agreement_version_preview_054'",
    'buffer_agreement_html',
] as $required) {
    if (!str_contains($releaseSource, $required)) $fail('Agreement HTML previews do not use the 0.66 layout: '.$required);
}

echo "Release 0.66 agreement layout checks passed.\n";
