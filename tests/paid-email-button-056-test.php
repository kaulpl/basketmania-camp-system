<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');

$GLOBALS['bcs_test_options_056'] = [
    'bcs_content_templates' => [
        'emails' => [
            'paid' => [
                'name' => 'Potwierdzenie opłacenia',
                'subject' => 'Udział w {{CAMP_NAME}} został opłacony',
                'body' => "Dzień dobry {{PARENT_NAME}},\n\nPakiet dokumentów jest dostępny w panelu rodzica:\n{{PORTAL_URL}}\n\nBasketmania Camp",
                'sms' => '',
            ],
        ],
    ],
    'bcs_message_templates' => [
        'paid' => [
            'body' => "Potwierdzenie płatności:\n{{PORTAL_URL}}",
        ],
    ],
];

function get_option(string $key, mixed $default = false): mixed {
    return $GLOBALS['bcs_test_options_056'][$key] ?? $default;
}

function update_option(string $key, mixed $value, bool $autoload = true): bool {
    $GLOBALS['bcs_test_options_056'][$key] = $value;
    return true;
}

require_once dirname(__DIR__).'/includes/class-bcs-release-056.php';

$fail = static function (string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$bootstrap = file_get_contents(dirname(__DIR__).'/basketmania-camp-system.php');
$pluginVersion = '';
$constantVersion = '';
if (is_string($bootstrap)) {
    if (preg_match('/\* Version:\s*([0-9.]+)/', $bootstrap, $match)) $pluginVersion = $match[1];
    if (preg_match("/define\('BCS_VERSION',\s*'([0-9.]+)'\)/", $bootstrap, $match)) $constantVersion = $match[1];
}
if (!is_string($bootstrap)
    || version_compare($pluginVersion, '0.56', '<')
    || version_compare($constantVersion, '0.56', '<')
    || $pluginVersion !== $constantVersion
    || !str_contains($bootstrap, 'class-bcs-release-056.php')
    || !str_contains($bootstrap, 'BCS_Release_056::init();')) {
    $fail('Release 0.56 is not correctly loaded and retained by the current release.');
}

$template = BCS_Release_056::paid_template();
$body = (string)($template['body'] ?? '');
if (!str_contains($body, 'href="{{PORTAL_URL}}"')) {
    $fail('The paid template does not link to the Parent Panel.');
}
if (!str_contains($body, 'display:inline-block')
    || !str_contains($body, 'background:#f97316')
    || !str_contains($body, 'color:#ffffff')
    || !str_contains($body, 'Otwórz Panel Rodzica')) {
    $fail('The Parent Panel link is not rendered as the standard orange email button.');
}

BCS_Release_056::init();

$contentBody = (string)($GLOBALS['bcs_test_options_056']['bcs_content_templates']['emails']['paid']['body'] ?? '');
$legacyBody = (string)($GLOBALS['bcs_test_options_056']['bcs_message_templates']['paid']['body'] ?? '');
foreach ([$contentBody, $legacyBody] as $migratedBody) {
    if (!str_contains($migratedBody, '<a href="{{PORTAL_URL}}"')) {
        $fail('A stored legacy paid template was not migrated to the button version.');
    }
    if (preg_match('/(?:^|\n)\s*\{\{PORTAL_URL\}\}\s*(?:\n|$)/', $migratedBody)) {
        $fail('A raw Parent Panel URL placeholder remains in the migrated email body.');
    }
}

if (empty($GLOBALS['bcs_test_options_056']['bcs_templates_migrated_056_paid_button'])) {
    $fail('The migration marker was not saved.');
}

echo "Paid email button 0.56 regression checks passed.\n";
