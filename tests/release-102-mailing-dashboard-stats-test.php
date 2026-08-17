<?php

declare(strict_types=1);

if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
if (!class_exists('BCS_DB')) {
    final class BCS_DB { public static function table(string $name): string { return 'wp_bcs_'.$name; } }
}

$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$r101 = (string)file_get_contents($root.'/includes/class-bcs-release-101.php');
$css = (string)file_get_contents($root.'/assets/mailing-102.css');
require_once $root.'/includes/class-bcs-release-101.php';

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === '1.02', 'Nagłówek wtyczki powinien mieć wersję 1.02.');
$check(($constantVersion[1] ?? '') === '1.02', 'BCS_VERSION powinno mieć wersję 1.02.');

// Strict MySQL: DATE nie może być porównywane do pustego stringa.
$query = "SELECT DISTINCT YEAR(start_date) y FROM wp_bcs_camps WHERE start_date IS NOT NULL AND start_date<>'' ORDER BY y DESC";
$fixed = BCS_Release_101::fix_campaign_year_date_query($query);
$check(!str_contains($fixed, "start_date<>''"), '1.02 musi usuwać porównanie DATE do pustego stringa.');
$check(str_contains($fixed, 'WHERE start_date IS NOT NULL ORDER BY y DESC'), 'Po poprawce zapytanie powinno zachować bezpieczny warunek IS NOT NULL.');
$other = 'SELECT * FROM wp_bcs_camps WHERE name<>\'\'';
$check(BCS_Release_101::fix_campaign_year_date_query($other) === $other, 'Filtr zapytania nie może zmieniać innych zapytań SQL.');

// Dashboard: statystyki łączne i aktywne kampanie.
$check(str_contains($r101, "WHERE status='sent'"), 'Dashboard powinien liczyć wszystkie wysłane wiadomości od początku.');
$check(str_contains($r101, "WHERE status='failed'"), 'Dashboard powinien liczyć wszystkie błędy od początku.');
$check(str_contains($r101, "c.status IN ('scheduled','queued','sending','paused')"), 'Dashboard powinien pobierać kampanie będące w realizacji.');
foreach (['Wysłane łącznie','Błędy łącznie','Status aktywnych kampanii','zrealizowano','W kolejce:'] as $label) {
    $check(str_contains($r101, $label), 'Dashboard 1.02 powinien zawierać: '.$label);
}
$check(str_contains($css, '.bcs-mail-progress-track'), '1.02 powinno mieć wizualny pasek postępu kampanii.');
$check(str_contains($css, '.bcs-mail-campaign-progress-row'), '1.02 powinno stylować wiersze aktywnych kampanii.');

// Rozwinięte terminy dostarczalności.
foreach ([
    'SPF — Sender Policy Framework',
    'DKIM — DomainKeys Identified Mail',
    'DMARC — Domain-based Message Authentication, Reporting and Conformance',
    'One-Click Unsubscribe — wypisanie jednym kliknięciem',
] as $term) {
    $check(str_contains($r101, $term), 'Powinna być widoczna pełna nazwa: '.$term);
}
$check(str_contains($r101, 'Selektor DKIM — DomainKeys Identified Mail'), 'Ustawienia powinny rozwijać nazwę DKIM również przy selektorze.');

// Widget 1.02 musi zastępować wcześniejszy widget 1.00, a nie dublować go.
$check(str_contains($r101, "remove_action('admin_footer', [BCS_Release_100::class, 'dashboard_footer_widget'], 1000)"), '1.02 powinno odpiąć stary widget Dashboardu 1.00.');
$check(str_contains($r101, "add_action('admin_footer', [__CLASS__, 'dashboard_footer_widget'], 1010)"), '1.02 powinno podpiąć nowy widget Dashboardu.');

if ($failures) {
    fwrite(STDERR, "Release 1.02 mailing dashboard test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 1.02 mailing dashboard/date query checks passed.\n";
