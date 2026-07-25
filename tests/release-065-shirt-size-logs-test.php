<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');

final class BCS_Utils {
    public static function timezone(): DateTimeZone { return new DateTimeZone('Europe/Warsaw'); }
}

$root = dirname(__DIR__);
$bootstrap = (string)file_get_contents($root.'/basketmania-camp-system.php');
$releaseSource = (string)file_get_contents($root.'/includes/class-bcs-release-065.php');
$contextSource = (string)file_get_contents($root.'/includes/class-bcs-release-065-log-context.php');
$script = (string)file_get_contents($root.'/assets/js/shirt-size-select-065.js');
require_once $root.'/includes/class-bcs-release-065.php';
require_once $root.'/includes/class-bcs-release-065-log-context.php';

$fail = static function(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!str_contains($bootstrap, '* Version: 0.65') || !str_contains($bootstrap, "define('BCS_VERSION', '0.65')")) {
    $fail('Plugin version declarations are not synchronized at 0.65.');
}
foreach (['class-bcs-release-065.php','BCS_Release_065::init();','class-bcs-release-065-log-context.php','BCS_Release_065_Log_Context::init();'] as $required) {
    if (!str_contains($bootstrap, $required)) $fail('Release 0.65 component is not loaded: '.$required);
}

$sizes = BCS_Release_065::shirt_sizes();
foreach (['128-134','158-164','S-164-170','XL-182-188','3XL-194-200'] as $size) {
    if (!in_array($size, $sizes, true)) $fail('Missing shirt size option: '.$size);
}
if (!str_contains($script, 'input[name="shirt_size"],select[name="shirt_size"]')) {
    $fail('The dropdown upgrader does not cover text inputs and existing selects.');
}
if (!str_contains($script, 'new MutationObserver') || !str_contains($script, 'field.replaceWith(select)')) {
    $fail('Dynamically loaded editable camp forms are not upgraded to a dropdown.');
}
if (!str_contains($releaseSource, "add_action('wp_enqueue_scripts'") || !str_contains($releaseSource, "add_action('admin_enqueue_scripts'")) {
    $fail('The shirt-size dropdown is not loaded in Parent Panel and administration.');
}

if (BCS_Release_065::event_label('crm_invoice') !== 'Wygenerowano fakturę') {
    $fail('The CRM invoice event is not translated to Polish.');
}
if (BCS_Release_065::event_label('email_send_result', ['success'=>true]) !== 'Wysłano wiadomość e-mail') {
    $fail('Successful email delivery has an incorrect Polish label.');
}
if (BCS_Release_065::event_label('email_send_result', ['success'=>false]) !== 'Nie udało się wysłać wiadomości e-mail') {
    $fail('Failed email delivery has an incorrect Polish label.');
}
if (BCS_Release_065::event_label('unknown_technical_event') !== 'Zdarzenie systemowe') {
    $fail('Unknown technical events can still expose an English event name.');
}
if (BCS_Release_065_Log_Context::infer_template('Basketmania Camp: Umowa do podpisu') !== 'agreement_sent') {
    $fail('Email subject is not assigned to the agreement business action.');
}
if (BCS_Release_065_Log_Context::infer_template('Basketmania Camp: Faktura FV/2026/1') !== 'invoice_issued') {
    $fail('Invoice email subject is not assigned to invoice delivery.');
}
if (!str_contains($contextSource, "_context_inferred_by") || !str_contains($contextSource, 'cleanup_recent_duplicates')) {
    $fail('Inferred email context is not persisted before log cleanup.');
}

$rows = [
    (object)['id'=>7,'registration_id'=>12,'agreement_id'=>0,'event_type'=>'communication_sent','event_data'=>'{"template":"agreement_sent","channel":"both","success":true}','created_at'=>'2026-07-26 11:00:02'],
    (object)['id'=>6,'registration_id'=>12,'agreement_id'=>0,'event_type'=>'email_send_result','event_data'=>'{"subject":"Umowa do podpisu","template":"agreement_sent","success":true}','created_at'=>'2026-07-26 11:00:01'],
    (object)['id'=>4,'registration_id'=>11,'agreement_id'=>0,'event_type'=>'crm_invoice','event_data'=>'{}','created_at'=>'2026-07-26 10:00:03'],
    (object)['id'=>3,'registration_id'=>11,'agreement_id'=>0,'event_type'=>'invoice_generated_manually','event_data'=>'{}','created_at'=>'2026-07-26 10:00:02'],
    (object)['id'=>2,'registration_id'=>11,'agreement_id'=>0,'event_type'=>'invoice_created','event_data'=>'{"invoice_number":"FV/2026/1"}','created_at'=>'2026-07-26 10:00:01'],
    (object)['id'=>1,'registration_id'=>11,'agreement_id'=>0,'event_type'=>'registration_created','event_data'=>'{}','created_at'=>'2026-07-26 09:55:00'],
];
$kept = BCS_Release_065::deduplicate_log_rows($rows);
$events = array_map(static fn(object $row): string => (string)$row->event_type, $kept);
if (count(array_intersect($events, ['crm_invoice','invoice_generated_manually'])) !== 0) {
    $fail('Technical duplicate invoice logs remain visible.');
}
if (!in_array('invoice_created', $events, true) || !in_array('registration_created', $events, true)) {
    $fail('The truthful business events were removed during deduplication.');
}
if (in_array('email_send_result', $events, true) || !in_array('communication_sent', $events, true)) {
    $fail('Low-level email result is not collapsed into the actual communication event.');
}

foreach ([
    "remove_submenu_page('bcs-dashboard', 'bcs-logs')",
    'deduplicate_log_rows',
    'migrate_historical_duplicates',
    'cleanup_recent_duplicates',
    'Rzeczywista historia działań bez technicznych duplikatów',
] as $required) {
    if (!str_contains($releaseSource, $required)) $fail('Logs implementation is incomplete: '.$required);
}

echo "Release 0.65 shirt-size and logs checks passed.\n";
