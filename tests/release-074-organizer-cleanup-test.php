<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$bootstrap = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-074.php');

$fail = static function(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!str_contains($bootstrap, '* Version: 0.74') || !str_contains($bootstrap, "define('BCS_VERSION', '0.74')")) {
    $fail('Plugin version declarations are not synchronized at 0.74.');
}
foreach (['class-bcs-release-074.php', 'BCS_Release_074::init();'] as $needle) {
    if (!str_contains($bootstrap, $needle)) $fail('Release 0.74 is not loaded: '.$needle);
}

foreach ([
    "remove_action('admin_footer', ['BCS_KSeF_Admin', 'inject_organizer_panel'], 20)",
    "remove_action('admin_footer', ['BCS_Release_073', 'ksef_token_help'], 40)",
    "add_action('admin_menu', [__CLASS__, 'replace_page_callback'], 9999)",
    "remove_action(\$hook, ['BCS_Admin', 'organizers'])",
    "remove_action(\$hook, ['BCS_Release_073', 'page'])",
    "add_action(\$hook, [__CLASS__, 'page'])",
] as $needle) {
    if (!str_contains($release, $needle)) $fail('Duplicate Organizer renderers are not fully disabled: '.$needle);
}

$editorStart = strpos($release, 'private static function editor');
$editorEnd = strpos($release, 'private static function input', $editorStart ?: 0);
if ($editorStart === false || $editorEnd === false || $editorEnd <= $editorStart) {
    $fail('Could not isolate the Organizer editor implementation.');
}
$editor = substr($release, $editorStart, $editorEnd - $editorStart);

if (substr_count($editor, '<form ') !== 1 || substr_count($editor, '</form>') !== 1) {
    $fail('The Organizer editor must contain exactly one form.');
}
if (substr_count($editor, 'name="bcs_save_organizer" value="1"') !== 1) {
    $fail('The Organizer editor must contain exactly one Save button.');
}
if (str_contains($editor, 'form="bcs-organizer-form-')) {
    $fail('The Organizer editor must not use an external duplicate Save button.');
}

foreach ([
    'id="bcs-organizer-form-074"',
    'Wszystkie ustawienia podmiotu znajdują się w jednym formularzu.',
    'Dane Organizatora',
    'Rozliczenia i dokumenty',
    '<strong>Stripe</strong>',
    'KSeF API 2.0 – TEST',
    'name="bcs_ksef_panel_present"',
    'name="ksef_token"',
    'Zapisz ustawienia',
] as $needle) {
    if (!str_contains($editor, $needle)) $fail('The single Organizer form is incomplete: '.$needle);
}

foreach (['bcs-ksef-test-074', 'bcs_ksef_test_connection_072', 'position:sticky', 'bcs-organizer-actions-074'] as $needle) {
    if (!str_contains($release, $needle)) $fail('The Organizer editor behavior is incomplete: '.$needle);
}

echo "Release 0.74 Organizer cleanup checks passed.\n";
