<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
define('BCS_DIR', dirname(__DIR__).'/');

$root = dirname(__DIR__);
$bootstrap = (string)file_get_contents($root.'/basketmania-camp-system.php');
$releaseSource = (string)file_get_contents($root.'/includes/class-bcs-release-071.php');
$finalizerSource = (string)file_get_contents($root.'/includes/class-bcs-agreement-pdf-v2-finalizer.php');
require_once $root.'/includes/class-bcs-release-071.php';

$fail = static function(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!str_contains($bootstrap, '* Version: 0.71') || !str_contains($bootstrap, "define('BCS_VERSION', '0.71')")) {
    $fail('Plugin version declarations are not synchronized at 0.71.');
}
foreach (['class-bcs-release-071.php', 'BCS_Release_071::init();'] as $needle) {
    if (!str_contains($bootstrap, $needle)) $fail('Release 0.71 is not loaded: '.$needle);
}

$sample = '<!doctype html><html lang="pl"><head><meta charset="utf-8">'
    .'<style id="bcs-agreement-v2-style">.bcs-v2-evidence{page-break-before:always}</style>'
    .'</head><body class="bcs-agreement-v2">'
    .'<main class="bcs-v2-content"><section class="bcs-v2-evidence">'
    .'<h2>Sekcja dowodowa zawarcia umowy</h2>'
    .'<table><tbody>'
    .'<tr><th>Potwierdzenie Organizatora</th><th>Potwierdzenie Rodzica / Opiekuna</th></tr>'
    .'<tr><td><strong>Status:</strong> potwierdzona kodem SMS</td><td><strong>Status:</strong> potwierdzona kodem SMS</td></tr>'
    .'<tr><td><strong>Data i czas:</strong> 25.07.2026 21:19</td><td><strong>Data i czas:</strong> 25.07.2026 21:17</td></tr>'
    .'</tbody></table>'
    .'</section></main></body></html>';

$normalized = BCS_Release_071::normalize_evidence_layout($sample);
$dom = new DOMDocument('1.0', 'UTF-8');
libxml_use_internal_errors(true);
$dom->loadHTML($normalized, LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
$xpath = new DOMXPath($dom);

$table = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bcs-v2-evidence-table ')]")->item(0);
if (!$table instanceof DOMElement) $fail('The normalized evidence table was not created.');
if ($table->getAttribute('data-layout') !== 'two-rows-one-column') {
    $fail('The evidence table does not declare the two-row, one-column layout.');
}

$rows = $xpath->query('./tbody/tr|./tr', $table);
if ($rows->length !== 2) $fail('The evidence table must contain exactly two rows.');

$texts = [];
$roles = [];
foreach ($rows as $row) {
    if (!$row instanceof DOMElement) $fail('An evidence row is not an element.');
    $cells = $xpath->query('./td|./th', $row);
    if ($cells->length !== 1) $fail('Each evidence row must contain exactly one cell.');
    $cell = $cells->item(0);
    $texts[] = mb_strtoupper(trim((string)$cell?->textContent), 'UTF-8');
    $roles[] = $row->getAttribute('data-evidence-role');
}

if ($roles !== ['parent', 'organizer']) {
    $fail('Evidence rows are not ordered as Parent first and Organizer second.');
}
if (!str_contains($texts[0], 'RODZICA') && !str_contains($texts[0], 'OPIEKUNA')) {
    $fail('The first evidence row does not contain the Parent / Guardian confirmation.');
}
if (!str_contains($texts[1], 'ORGANIZATORA')) {
    $fail('The second evidence row does not contain the Organizer confirmation.');
}
if (!str_contains($normalized, 'bcs-evidence-layout-071') || !str_contains($normalized, '.bcs-v2-evidence-table')) {
    $fail('The dedicated evidence layout CSS was not added.');
}

$idempotent = BCS_Release_071::normalize_evidence_layout($normalized);
$dom2 = new DOMDocument('1.0', 'UTF-8');
libxml_use_internal_errors(true);
$dom2->loadHTML($idempotent, LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
$xpath2 = new DOMXPath($dom2);
$table2 = $xpath2->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bcs-v2-evidence-table ')]")->item(0);
$rows2 = $table2 instanceof DOMElement ? $xpath2->query('./tbody/tr|./tr', $table2) : null;
if (!$rows2 || $rows2->length !== 2) $fail('Repeated normalization changes the evidence structure.');

foreach ([
    "remove_action('admin_post_bcs_agreement_view', ['BCS_Agreement_PDF_V2'",
    'buffer_preview_html',
    'normalize_evidence_layout',
    "self::append_row(\$dom, \$tbody, 'parent'",
    "self::append_row(\$dom, \$tbody, 'organizer'",
] as $needle) {
    if (!str_contains($releaseSource, $needle)) $fail('Release 0.71 implementation is incomplete: '.$needle);
}
if (!str_contains($finalizerSource, 'BCS_Release_071::normalize_evidence_layout($html)')) {
    $fail('The final PDF pipeline does not apply the 0.71 evidence layout.');
}

echo "Release 0.71 evidence layout checks passed.\n";
