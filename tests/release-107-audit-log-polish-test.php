<?php

declare(strict_types=1);

if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
if (!class_exists('BCS_Utils')) {
    final class BCS_Utils {
        public static function event_labels(): array { return []; }
    }
}

$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$r107 = (string)file_get_contents($root.'/includes/class-bcs-release-107.php');
$js107 = (string)file_get_contents($root.'/assets/js/audit-polish-107.js');
require_once $root.'/includes/class-bcs-release-107.php';

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === ($constantVersion[1] ?? ''), 'Nagłówek i BCS_VERSION powinny być zgodne.');
$check(version_compare((string)($headerVersion[1] ?? '0'), '1.07', '>='), 'Wtyczka powinna mieć wersję co najmniej 1.07.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-107.php';"), 'Bootstrap powinien ładować release 1.07.');
$check(str_contains($plugin, 'BCS_Release_107::init();'), 'Bootstrap powinien inicjalizować release 1.07.');

foreach ([
    "add_action('admin_init', [__CLASS__, 'capture_admin_action'], 1)",
    'bcs_mailbox_sync_event',
    'bcs_marketing_queue_097',
    'bcs_marketing_queue_pump_098',
    'bcs_marketing_unsubscribe_096',
    'bcs_marketing_click_098',
] as $needle) {
    $check(str_contains($r107, $needle), 'Brakuje elementu audytu: '.$needle);
}
$check(str_contains($r107, "remove_action(\$hook, ['BCS_Admin', 'logs'])"), '1.07 powinno zastępować stary ekran Logów nowym polskim rendererem.');
$check(str_contains($r107, "'mailbox_message_received' => 'Odebrano wiadomość e-mail'"), 'Odbiór poczty powinien mieć polską etykietę.');
$check(str_contains($r107, "'mailing_queue_processed' => 'Przetworzono kolejkę mailingu'"), 'Mailing powinien mieć polską etykietę pracy kolejki.');
$check(str_contains($r107, "'crm_invoice_generate' => 'Uruchomiono generowanie faktury'"), 'Generowanie faktury powinno być objęte audytem.');
$check(str_contains($r107, "'workflow_send_agreement' => 'Uruchomiono wysyłkę umowy'"), 'Obsługa umów powinna być objęta audytem.');

$check(str_contains($js107, '.bcs-history-panel .bcs-timeline-item[data-event-type]'), 'Historia klienta powinna być tłumaczona na podstawie typu zdarzenia.');
$check(str_contains($js107, "'Zdarzenie systemowe'"), 'Nieznane zdarzenia w Historii klienta powinny mieć polską nazwę ogólną.');

$crmInvoice = BCS_Release_107::detect_admin_action(['bcs_crm_action'=>'invoice_generate']);
$check(($crmInvoice['action_key'] ?? '') === 'crm_invoice_generate', 'Audyt powinien rozpoznać generowanie faktury z CRM.');
$check(($crmInvoice['module'] ?? '') === 'Faktury i KSeF', 'Generowanie faktury z CRM powinno trafić do modułu Faktury i KSeF.');
$check(($crmInvoice['label'] ?? '') === 'Uruchomiono generowanie faktury', 'Generowanie faktury powinno mieć polski opis.');

$mailing = BCS_Release_107::detect_admin_action(['action'=>'bcs_marketing_campaign_launch_097']);
$check(($mailing['action_key'] ?? '') === 'marketing_campaign_launch', 'Audyt powinien usuwać techniczny sufiks wersji z akcji mailingu.');
$check(($mailing['module'] ?? '') === 'Mailing', 'Kampania powinna być przypisana do modułu Mailing.');
$check(($mailing['label'] ?? '') === 'Uruchomiono kampanię mailingową', 'Uruchomienie kampanii powinno mieć polski opis.');

$mail = BCS_Release_107::detect_admin_action(['bcs_mail_sync'=>1]);
$check(($mail['module'] ?? '') === 'Poczta', 'Synchronizacja IMAP powinna być przypisana do modułu Poczta.');
$check(($mail['label'] ?? '') === 'Uruchomiono synchronizację poczty', 'Synchronizacja poczty powinna mieć polski opis.');

$workflow = BCS_Release_107::detect_admin_action(['action'=>'bcs_workflow_single','workflow'=>'generate_invoice']);
$check(($workflow['action_key'] ?? '') === 'workflow_generate_invoice', 'Audyt powinien rozpoznać pojedynczą akcję workflow.');
$check(($workflow['label'] ?? '') === 'Uruchomiono generowanie faktury', 'Workflow faktury powinien mieć polski opis.');

$ksef = BCS_Release_107::detect_admin_action(['action'=>'bcs_ksef_custom_send_123']);
$check(($ksef['module'] ?? '') === 'Faktury i KSeF', 'Przyszła akcja KSeF nadal powinna trafić do właściwego modułu.');
$check(!str_contains((string)($ksef['label'] ?? ''), 'custom_send'), 'Techniczny identyfikator nie może być widocznym opisem nieznanej akcji.');

$check(BCS_Release_107::event_title('some_unknown_english_event') === 'Zdarzenie systemowe', 'Nieznane zdarzenie powinno mieć neutralną polską etykietę.');
$check(BCS_Release_107::event_title('audit_marketing_campaign_launch') === 'Uruchomiono kampanię mailingową', 'Zdarzenie audytowe mailingu powinno mieć polski tytuł.');

$details = BCS_Release_107::details_text([
    'module'=>'Mailing',
    'action_label'=>'Uruchomiono kampanię mailingową',
    'audit_status'=>'handled',
    'campaign_id'=>12,
    '_actor_type'=>'administrator',
    '_actor_login'=>'admin',
]);
$check(str_contains($details, 'Moduł: Mailing'), 'Szczegóły powinny używać polskiej etykiety „Moduł”.');
$check(str_contains($details, 'Akcja: Uruchomiono kampanię mailingową'), 'Szczegóły powinny mieć polską nazwę akcji.');
$check(str_contains($details, 'Status audytu: Obsłużono'), 'Status audytu powinien być przetłumaczony.');
$check(str_contains($details, 'ID kampanii: 12'), 'ID kampanii powinno mieć polską etykietę.');
$check(!str_contains($details, '_actor_type') && !str_contains($details, '_actor_login'), 'Techniczne pola wykonawcy nie powinny być pokazywane w szczegółach.');

$check(!str_contains($r107, 'wp_json_encode($_POST'), 'Audyt nie może zapisywać surowej zawartości formularza POST.');

if ($failures) {
    fwrite(STDERR, "Release 1.07 audit/log Polish test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 1.07 audit/log Polish checks passed.\n";

$next = $root.'/tests/release-108-camp-archive-test.php';
if (is_file($next)) passthru(PHP_BINARY.' '.escapeshellarg($next), $nextStatus);
if (!empty($nextStatus)) exit((int)$nextStatus);
