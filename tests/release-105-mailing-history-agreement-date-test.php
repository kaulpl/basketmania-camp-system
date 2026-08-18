<?php

declare(strict_types=1);

if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$r105 = (string)file_get_contents($root.'/includes/class-bcs-release-105.php');
$css105 = (string)file_get_contents($root.'/assets/mailing-105.css');
require_once $root.'/includes/class-bcs-release-105.php';

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === ($constantVersion[1] ?? ''), 'Nagłówek i BCS_VERSION powinny być zgodne.');
$check(version_compare((string)($headerVersion[1] ?? '0'), '1.05', '>='), 'Wtyczka powinna mieć wersję co najmniej 1.05.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-105.php';"), 'Bootstrap powinien ładować release 1.05.');
$check(str_contains($plugin, 'BCS_Release_105::init();'), 'Bootstrap powinien inicjalizować release 1.05.');

// Szczegóły kampanii muszą używać nowego renderera i wspólnych klas stylistycznych.
$check(str_contains($r105, "remove_action(\$hook, [BCS_Release_098::class, 'campaign_history_page'])"), '1.05 powinno odpiąć stary renderer szczegółów kampanii.');
$check(str_contains($r105, 'bcs-mailing-history-105'), 'Nowy ekran powinien mieć własny wrapper stylistyczny.');
$check(str_contains($r105, 'bcs-mail-card'), 'Szczegóły kampanii powinny korzystać ze standardowych kart Mailingu.');
$check(str_contains($r105, 'bcs-mail-table'), 'Tabela odbiorców powinna korzystać ze standardowego formatowania tabel Mailingu.');
$check(str_contains($r105, 'bcs-mail-badge status-'), 'Statusy powinny używać badge zgodnych z modułem Mailing.');
$check(str_contains($css105, 'max-width:none!important'), 'Widok szczegółów powinien wykorzystywać pełną dostępną szerokość.');
$check(str_contains($css105, '.bcs-mail-history-kpis'), 'Widok powinien mieć nowoczesne kafelki KPI.');

// Finalna data umowy: źródłem jest accepted_at Organizatora i tylko wersja signed.
$check(str_contains($r105, "get_option('bcs_org_proof_'.\$agreementId"), 'Finalna data musi pochodzić z dowodu podpisu Organizatora.');
$check(str_contains($r105, "['accepted_at']"), 'Kod powinien używać accepted_at Organizatora.');
$check(str_contains($r105, "->format('d-m-Y')"), 'Finalna data powinna być zapisywana w czytelnym formacie dd-MM-YYYY.');
$check(str_contains($r105, "stage='signed'"), 'Korekta daty powinna dotyczyć wersji signed.');
$check(str_contains($r105, "agreement_status !== 'accepted'"), 'Korekta nie może być wykonana przed finalnym podpisem Organizatora.');
$check(str_contains($r105, 'repair_after_organizer_signature'), 'Nowa finalna umowa powinna być poprawiana od razu po podpisie Organizatora.');
$check(str_contains($r105, 'repair_existing_final_dates_once'), 'Istniejące finalne umowy powinny dostać jednorazową migrację daty.');
$check(str_contains($r105, "'document_hash'=>\$newHash"), 'Po zmianie daty hash signed version powinien zostać zaktualizowany.');

$sample = '<p>Zawarta dnia <strong>05.07.2026</strong> w Pelplinie pomiędzy:</p><p>Termin: 10.07.2027</p>';
$fixed = BCS_Release_105::replace_agreement_date($sample, '18-08-2026');
$check(str_contains($fixed, '<strong>18-08-2026</strong>'), 'Data zawarcia powinna zostać zastąpiona datą Organizatora.');
$check(str_contains($fixed, 'Termin: 10.07.2027'), 'Inne daty w umowie nie mogą zostać zmienione.');

$draft = '<p>Zawarta dnia <strong>dd-MM-YYYY</strong> w Pelplinie pomiędzy:</p>';
$draftFixed = BCS_Release_105::replace_agreement_date($draft, '18-08-2026');
$check(str_contains($draftFixed, '<strong>18-08-2026</strong>'), 'Placeholder draftu dd-MM-YYYY powinien być obsługiwany przy finalizacji.');

$alt = '<p>Umowa zawarta w dniu 01.02.2026 pomiędzy stronami.</p>';
$altFixed = BCS_Release_105::replace_agreement_date($alt, '18-08-2026');
$check(str_contains($altFixed, 'zawarta w dniu 18-08-2026'), 'Alternatywne sformułowanie „zawarta w dniu” powinno być obsługiwane.');

if ($failures) {
    fwrite(STDERR, "Release 1.05 mailing history/agreement date test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 1.05 mailing history/agreement date checks passed.\n";
