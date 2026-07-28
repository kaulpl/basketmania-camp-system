<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$bootstrap = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-073.php');

$fail = static function(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

preg_match('/\* Version:\s*([0-9.]+)/', $bootstrap, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $bootstrap, $constantVersion);
$currentVersion = $headerVersion[1] ?? '';
if ($currentVersion === '' || ($constantVersion[1] ?? '') !== $currentVersion || version_compare($currentVersion, '0.73', '<')) {
    $fail('Plugin version declarations must be synchronized at 0.73 or newer.');
}
foreach (['class-bcs-release-073.php', 'BCS_Release_073::init();'] as $needle) if (!str_contains($bootstrap, $needle)) $fail('Release 0.73 is not loaded: '.$needle);

foreach (["remove_submenu_page('bcs-dashboard', 'bcs-organizers')","[__CLASS__, 'page']","admin.php?page=bcs-organizers&edit=","admin.php?page=bcs-organizers&new=1","if (\$editId || \$new)","self::editor(\$organizer)",'bcs-organizer-form-073','← Wróć do listy Organizatorów'] as $needle) if (!str_contains($release, $needle)) $fail('Separate Organizer view is incomplete: '.$needle);
if (substr_count($release, 'name="bcs_save_organizer" value="1"') < 2) $fail('The 0.73 source no longer contains its original Save controls.');
if (!str_contains($release, 'form="bcs-organizer-form-073"')) $fail('The 0.73 top Save button is not attached to its form.');

foreach (['https://ap-test.ksef.mf.gov.pl/','Nie wpisuj losowego ciągu','<code>InvoiceWrite</code>','<code>InvoiceRead</code>','w tym samym kontekście NIP','Token jest sekretem'] as $needle) if (!str_contains($release, $needle)) $fail('KSeF TEST token guidance is incomplete: '.$needle);
if (!str_contains($release, "add_action('admin_footer', [__CLASS__, 'ksef_token_help'], 40)")) $fail('KSeF token guidance is not attached after the 0.72 panel renderer.');

echo "Release 0.73 Organizer view checks passed.\n";
