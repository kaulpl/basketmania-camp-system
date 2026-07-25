<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root.'/basketmania-camp-system.php');
$release = file_get_contents($root.'/includes/class-bcs-release-061.php');
$script = file_get_contents($root.'/assets/js/agreement-send-061.js');
$style = file_get_contents($root.'/assets/css/agreement-send-061.css');
$legacy = file_get_contents($root.'/includes/class-bcs-release-046.php');

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
if (version_compare($headerVersion, '0.61', '<') || $headerVersion !== $constantVersion) {
    $fail('Plugin version is not 0.61 or later, or version declarations differ.');
}
if (!str_contains((string)$bootstrap, 'class-bcs-release-061.php') || !str_contains((string)$bootstrap, 'BCS_Release_061::init();')) {
    $fail('Release 0.61 is not loaded and initialized.');
}
if (!str_contains((string)$release, "add_action('admin_head', [__CLASS__, 'admin_head_assets'], 0)")) {
    $fail('Release 0.61 is not registered before the legacy 0.46 click listener.');
}
if (!str_contains((string)$release, 'agreement-send-061.js') || !str_contains((string)$release, 'agreement-send-061.css')) {
    $fail('Release 0.61 assets are not loaded.');
}
if (!str_contains((string)$legacy, "add_action('admin_head', [__CLASS__, 'admin_head_script'], 1)")) {
    $fail('The test can no longer prove that 0.61 runs before the legacy alert listener.');
}
if (!str_contains((string)$script, "action: 'bcs_046_send_agreement'")) {
    $fail('The new handler does not call the parent-first agreement endpoint.');
}
if (!str_contains((string)$script, "document.addEventListener('click'") || !str_contains((string)$script, 'event.stopImmediatePropagation()') || !str_contains((string)$script, '}, true);')) {
    $fail('The capture-phase handler does not block the historical native-alert listener.');
}
if (!str_contains((string)$script, "text.includes('wyslij umowe')") || !str_contains((string)$script, "text.includes('wyslij do podpisu')")) {
    $fail('The handler does not cover both card and list agreement-send labels.');
}
if (!str_contains((string)$script, 'window.bcsNotify061 = notify') || !str_contains((string)$script, 'window.alert = (message) =>')) {
    $fail('The registrations screen does not expose the canonical transient notifier or replace legacy native alerts.');
}
if (!str_contains((string)$script, 'notify(data.message || cfg.successFallback, true, 2000)') || !str_contains((string)$script, 'notify(error.message || cfg.errorFallback, false, 2000)')) {
    $fail('Success and failure notifications are not both set to two seconds.');
}
$executableScript = preg_replace('~/\*.*?\*/|//[^\r\n]*~s', '', (string)$script);
if (preg_match('/(^|[^\w.])alert\s*\(/m', (string)$executableScript)) {
    $fail('The new agreement send script still invokes a native alert.');
}
if (!str_contains((string)$style, '.bcs-result-popup-061.is-success') || !str_contains((string)$style, '.bcs-result-popup-061.is-error')) {
    $fail('Success and error visual states are missing.');
}

echo "Release 0.61 agreement-send notification checks passed.\n";
