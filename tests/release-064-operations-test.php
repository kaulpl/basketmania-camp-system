<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');

$root = dirname(__DIR__);
$bootstrap = (string)file_get_contents($root.'/basketmania-camp-system.php');
$releaseSource = (string)file_get_contents($root.'/includes/class-bcs-release-064.php');
$invoiceSource = (string)file_get_contents($root.'/includes/class-bcs-invoices.php');
require_once $root.'/includes/class-bcs-release-064.php';

$fail = static function(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!str_contains($bootstrap, '* Version: 0.64') || !str_contains($bootstrap, "define('BCS_VERSION', '0.64')")) {
    $fail('Plugin version declarations are not synchronized at 0.64.');
}
if (!str_contains($bootstrap, 'class-bcs-release-064.php') || !str_contains($bootstrap, 'BCS_Release_064::init();')) {
    $fail('Release 0.64 is not loaded and initialized.');
}

$query = "SELECT CASE WHEN (r.status = 'draft_sent') THEN 1 ELSE 0 END requires_action FROM wp_bcs_registrations r";
$expanded = BCS_Release_064::expand_action_required_query($query);
foreach (["r.status = 'agreement_parent_signed'", "r.agreement_status = 'parent_signed'"] as $required) {
    if (!str_contains($expanded, $required)) $fail('Parent-signed registrations are not included in requires-action queries: '.$required);
}
$unrelated = "SELECT * FROM wp_bcs_registrations r WHERE r.status = 'draft_sent'";
if (BCS_Release_064::expand_action_required_query($unrelated) !== $unrelated) {
    $fail('The action-query transformation changes unrelated SQL.');
}

if (BCS_Release_064::shirt_rank('116') >= BCS_Release_064::shirt_rank('164')) {
    $fail('Numeric shirt sizes are not sorted from smallest to largest.');
}
if (BCS_Release_064::shirt_rank('S') >= BCS_Release_064::shirt_rank('XL')) {
    $fail('Letter shirt sizes are not sorted from smallest to largest.');
}

foreach ([
    "remove_action('template_redirect', ['BCS_Documents', 'public_download'])",
    "BCS_Invoices::record_parent_download",
    "parent_portal_or_email_link_064",
] as $required) {
    if (!str_contains($releaseSource, $required)) $fail('Invoice download tracking is incomplete: '.$required);
}
foreach ([
    'downloaded_at=COALESCE(downloaded_at,%s)',
    'download_count=download_count+1',
] as $required) {
    if (!str_contains($invoiceSource, $required)) $fail('Invoice download persistence is incomplete: '.$required);
}
foreach ([
    "paid_amount>=total_amount",
    "total_amount>0",
    "#'.(\$index + 1)",
    "od najmniejszego do największego rozmiaru",
] as $required) {
    if (!str_contains($releaseSource, $required)) $fail('Paid participant shirt report is incomplete: '.$required);
}
foreach ([
    "insertBefore(camps, chart)",
    "min-height:130px!important",
    "height:90px!important",
] as $required) {
    if (!str_contains($releaseSource, $required)) $fail('Dashboard layout change is incomplete: '.$required);
}
if (!str_contains($releaseSource, 'Wymagające akcji!')) {
    $fail('The parent-signed visual action marker is missing.');
}

echo "Release 0.64 operational checks passed.\n";
