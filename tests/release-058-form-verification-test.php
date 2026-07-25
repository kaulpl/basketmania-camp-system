<?php

declare(strict_types=1);

$fail = static function (string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root.'/basketmania-camp-system.php');
$release = file_get_contents($root.'/includes/class-bcs-release-058.php');
$legacy = file_get_contents($root.'/includes/class-bcs-release-0200.php');

$headerVersion = '';
$constantVersion = '';
if (is_string($bootstrap)) {
    if (preg_match('/\* Version:\s*([0-9.]+)/', $bootstrap, $match)) $headerVersion = $match[1];
    if (preg_match("/define\('BCS_VERSION',\s*'([0-9.]+)'\)/", $bootstrap, $match)) $constantVersion = $match[1];
}
if (version_compare($headerVersion, '0.58', '<')
    || version_compare($constantVersion, '0.58', '<')
    || $headerVersion !== $constantVersion
    || !str_contains((string)$bootstrap, 'class-bcs-release-058.php')
    || !str_contains((string)$bootstrap, 'BCS_Release_058::init();')) {
    $fail('Release 0.58 is not correctly loaded and preserved.');
}

if (!is_string($legacy)
    || !str_contains($legacy, 'body:new FormData(form)')
    || !str_contains($legacy, '.bcs-form-verification form')) {
    $fail('The regression cause in the legacy intercepted card form is no longer covered by this test.');
}

if (!is_string($release)
    || !str_contains($release, "add_action('admin_footer', [__CLASS__, 'admin_footer'], 5)")
    || !str_contains($release, 'input[name="bcs_crm_action"]')
    || !str_contains($release, "action.value = 'verify_form'")
    || !str_contains($release, 'bcs-form-verification-inline-058')) {
    $fail('The 0.58 compatibility layer for card verification is missing.');
}

foreach ([
    'bcs_058_form_preview',
    'bcs-form-review-modal-058',
    'bcs-form-review-open-058',
    "quick_action:'verify_form'",
    'Potwierdź poprawność formularza',
    'Zdrowie, żywienie i szczepienia',
    'Dane do faktury',
    'Osoby upoważnione do odbioru',
] as $needle) {
    if (!str_contains($release, $needle)) {
        $fail('Missing 0.58 form-review element: '.$needle);
    }
}

if (!str_contains($release, "button.type = 'button'")
    || !str_contains($release, 'event.stopImmediatePropagation()')
    || !str_contains($release, 'applyRowState(current.row, data)')) {
    $fail('The list verification button is not converted to a popup review flow with AJAX row refresh.');
}

echo "Release 0.58 form verification regression checks passed.\n";
