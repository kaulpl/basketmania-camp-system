<?php
$root = dirname(__DIR__);
$bootstrap = file_get_contents($root.'/basketmania-camp-system.php');
$release = file_get_contents($root.'/includes/class-bcs-release-054.php');
$crm = file_get_contents($root.'/includes/class-bcs-crm.php');

$fail = static function (string $message): void {
    fwrite(STDERR, $message."\n");
    exit(1);
};

if (!str_contains($bootstrap, "Version: 0.54") || !str_contains($bootstrap, "BCS_VERSION', '0.54")) {
    $fail('Plugin version was not bumped to 0.54.');
}
if (!str_contains($bootstrap, "class-bcs-release-054.php") || !str_contains($bootstrap, 'BCS_Release_054::init();')) {
    $fail('Release 0.54 is not loaded and initialized.');
}
if (!str_contains($crm, "wp_kses_post(\$v->html)")) {
    $fail('The historical inline preview path changed; review the 0.54 isolation strategy.');
}
if (!str_contains($release, "admin_post_'.self::ACTION") || !str_contains($release, 'render_version_preview')) {
    $fail('The isolated admin preview endpoint is missing.');
}
if (!str_contains($release, 'bcs-agreement-version-frame-054') || !str_contains($release, 'preview.replaceChildren()')) {
    $fail('The inline preview is not replaced with an iframe.');
}
if (!str_contains($release, 'font-size:0!important') || !str_contains($release, '>*:not(.bcs-agreement-version-frame-054){display:none!important}')) {
    $fail('The old sanitized CSS text is not hidden before iframe initialization.');
}
if (!str_contains($release, 'BCS_Release_052::prepare_pdf_html')) {
    $fail('The preview does not reuse the proven 0.52 document renderer.');
}
if (!str_contains($release, "Content-Security-Policy: frame-ancestors 'self'")) {
    $fail('The preview iframe is not limited to the same origin.');
}
if (preg_match('/wp_kses_post\s*\(/', $release)) {
    $fail('Release 0.54 must not sanitize the complete document through wp_kses_post.');
}

echo "Agreement preview 0.54 regression checks passed.\n";
