<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$bootstrap = (string)file_get_contents($root.'/basketmania-camp-system.php');
$flow = (string)file_get_contents($root.'/includes/class-bcs-ksef-invoice-flow.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-077.php');

$fail = static function(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!str_contains($bootstrap, '* Version: 0.77') || !str_contains($bootstrap, "define('BCS_VERSION', '0.77')")) {
    $fail('Plugin version declarations are not synchronized at 0.77.');
}
foreach (['class-bcs-release-077.php', 'BCS_Release_077::init();'] as $needle) {
    if (!str_contains($bootstrap, $needle)) $fail('Release 0.77 bootstrap is incomplete: '.$needle);
}

foreach ([
    'po poprawnym przyjęciu przez KSeF przekazuje PDF rodzicowi niezależnie od środowiska',
    'if (empty($invoice->ksef_delivery_completed_at))',
    'self::deliver($invoice, $registrationId, $environment)',
    "'ksef_delivery_completed_at'=>BCS_Utils::now()",
    'PDF został przekazany rodzicowi.',
    'BCS_Mailer::send((string)$registration->parent_email',
    "'invoice_delivery_after_ksef'",
    "'ksef_environment'=>BCS_KSeF_Config::label(\$environment)",
] as $needle) {
    if (!str_contains($flow, $needle)) $fail('Environment-independent KSeF delivery is incomplete: '.$needle);
}

if (str_contains($flow, 'if ($environment === \'production\')')) {
    $fail('Parent PDF delivery must not be limited to production anymore.');
}
if (str_contains($flow, 'dokument nie został automatycznie wysłany rodzicowi')) {
    $fail('TEST environment must not suppress delivery of a real CRM invoice anymore.');
}

foreach ([
    'schedule_previously_accepted_invoices',
    "ksef_status='accepted'",
    'ksef_delivery_completed_at IS NULL',
    'wp_schedule_single_event(time() + $delay, \'bcs_ksef_finalize_invoice_076\'',
    "BCS_DB::table('invoices')",
    'Osobne dokumenty z modułu KSeF TEST są w innej tabeli',
] as $needle) {
    if (!str_contains($release, $needle)) $fail('0.77 accepted-invoice backfill is incomplete: '.$needle);
}
if (str_contains($release, "BCS_DB::table('ksef_test_documents')")) {
    $fail('Backfill must not send independent KSeF TEST documents to parents.');
}

echo "Release 0.77 KSeF parent delivery checks passed.\n";
