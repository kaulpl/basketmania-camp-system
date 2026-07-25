<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/basketmania-camp-system.php');
$release = file_get_contents($root . '/includes/class-bcs-release-053.php');
$script = file_get_contents($root . '/assets/js/parent-test-autofill-053.js');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(is_string($bootstrap) && str_contains($bootstrap, "Version: 0.53"), 'plugin version is 0.53');
$assert(str_contains($bootstrap, "define('BCS_VERSION', '0.53')"), 'BCS_VERSION is 0.53');
$assert(str_contains($bootstrap, "class-bcs-release-053.php"), 'release 0.53 is loaded');
$assert(str_contains($bootstrap, "BCS_Release_053::init()"), 'release 0.53 is initialized');

$assert(is_string($release), 'release file can be read');
$assert(str_contains($release, "BCS_Workflow_Engine::test_mode_enabled()"), 'visibility uses central test mode setting');
$assert(str_contains($release, "if (!self::test_mode_enabled()) return;"), 'autofill is disabled outside test mode');
$assert(str_contains($release, "assets/js/parent-test-autofill-053.js"), 'test autofill script is enqueued');

$assert(is_string($script), 'autofill script can be read');
$assert(str_contains($script, "form.bcs-camp-form"), 'script targets only the camp form in Parent Panel');
$assert(str_contains($script, "const preservedEmail"), 'existing email is preserved');
$assert(str_contains($script, "const preservedPhone"), 'existing primary phone is preserved');
$assert(!str_contains($script, "setValue(form, 'parent_email'"), 'random data does not overwrite email');
$assert(!str_contains($script, "setValue(form, 'parent_phone'"), 'random data does not overwrite primary phone');
$assert(str_contains($script, "input[type=\"checkbox\"][required]"), 'required confirmations are checked');
$assert(str_contains($script, "setChecked(form, 'invoice_requested', false)"), 'invoice remains optional and disabled');
$assert(str_contains($script, "generatePesel"), 'a valid-format PESEL generator is present');
$assert(str_contains($script, "type=\"button\""), 'autofill button cannot submit the form accidentally');

fwrite(STDOUT, "Parent test autofill 0.53 regression checks passed.\n");
