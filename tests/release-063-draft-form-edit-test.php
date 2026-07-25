<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root.'/basketmania-camp-system.php');
$release = file_get_contents($root.'/includes/class-bcs-release-063.php');
$release042 = file_get_contents($root.'/includes/class-bcs-release-042.php');
$script = file_get_contents($root.'/assets/js/card-form-060.js');
require_once $root.'/includes/class-bcs-release-063.php';

$fail = static function (string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$headerVersion = '';
$constantVersion = '';
if (is_string($bootstrap)) {
    if (preg_match('/\* Version:\s*([0-9.]+)/', $bootstrap, $match)) $headerVersion = $match[1];
    if (preg_match("/define\('BCS_VERSION',\s*'([0-9.]+)'\)/", $bootstrap, $match)) $constantVersion = $match[1];
}
if ($headerVersion !== '0.63' || $constantVersion !== '0.63') {
    $fail('Plugin version declarations are not synchronized at 0.63.');
}
if (!str_contains((string)$bootstrap, 'class-bcs-release-063.php') || !str_contains((string)$bootstrap, 'BCS_Release_063::init();')) {
    $fail('Release 0.63 is not loaded and initialized.');
}

$draftSent = (object)[
    'status'=>'draft_sent',
    'agreement_status'=>'pending',
    'agreement_record_status'=>'pending',
    'invoice_status'=>'not_generated',
    'has_invoice'=>0,
];
if (BCS_Release_063::form_editing_locked($draftSent)) {
    $fail('The draft_sent workflow is still locked by a stale pending agreement status.');
}

$actuallySent = clone $draftSent;
$actuallySent->status = 'agreement_sent';
if (!BCS_Release_063::form_editing_locked($actuallySent)) {
    $fail('Editing remains available after the agreement is actually sent for signature.');
}

$parentSigned = clone $draftSent;
$parentSigned->agreement_status = 'parent_signed';
if (!BCS_Release_063::form_editing_locked($parentSigned)) {
    $fail('Editing remains available after the parent signature.');
}

$invoice = clone $draftSent;
$invoice->has_invoice = 1;
if (!BCS_Release_063::form_editing_locked($invoice)) {
    $fail('Editing remains available after an invoice was generated.');
}

if (!str_contains((string)$release042, 'BCS_Release_063::form_editing_locked($r)')) {
    $fail('The administrator AJAX endpoint does not use the canonical 0.63 editing rule.');
}

foreach ([
    "button?.closest('.bcs-accordion-content')",
    'renderEditor(data.values || {}, targetContent)',
    'openEditor(edit)',
    'mount(lastDisplayHtml, targetContent)',
] as $required) {
    if (!str_contains((string)$script, $required)) {
        $fail('The card editor is not mounted in the exact clicked accordion section: '.$required);
    }
}
if (str_contains((string)$script, 'renderEditor(data.values || {});')) {
    $fail('The editor still re-finds a possibly different accordion section after the click.');
}

echo "Release 0.63 draft_sent form editing checks passed.\n";
