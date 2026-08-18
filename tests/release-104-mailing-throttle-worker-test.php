<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$r098 = (string)file_get_contents($root.'/includes/class-bcs-release-098.php');
$r100 = (string)file_get_contents($root.'/includes/class-bcs-release-100.php');
$r104 = (string)file_get_contents($root.'/includes/class-bcs-release-104.php');

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === ($constantVersion[1] ?? ''), 'Nagłówek i BCS_VERSION powinny być zgodne.');
$check(version_compare((string)($headerVersion[1] ?? '0'), '1.04', '>='), 'Wtyczka powinna mieć wersję co najmniej 1.04.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-104.php';"), 'Bootstrap powinien ładować release 1.04.');
$check(str_contains($plugin, 'BCS_Release_104::init();'), 'Bootstrap powinien inicjalizować release 1.04.');

// Odtworzenie źródła regresji: minutowy pump 0.98 omijał hook i wołał batch-worker 0.97 wprost.
$check(str_contains($r098, 'BCS_Release_097::run_queue()'), 'Test powinien dokumentować historyczny bezpośredni bypass z 0.98.');

// 1.04 musi odłączyć bypass i wszystkie poprzednie implementacje workera.
$check(str_contains($r104, "remove_action(self::PUMP_HOOK, [BCS_Release_098::class, 'pump_queue'])"), '1.04 musi odpiąć stary pump 0.98.');
$check(str_contains($r104, "remove_action(self::QUEUE_HOOK, [BCS_Release_097::class, 'run_queue'])"), '1.04 musi odpiąć batch-worker 0.97.');
$check(str_contains($r104, "remove_action(self::QUEUE_HOOK, [BCS_Release_099::class, 'run_queue'], 20)"), '1.04 musi usunąć potencjalny worker 0.99.');
$check(str_contains($r104, "remove_action(self::QUEUE_HOOK, [BCS_Release_100::class, 'run_queue'], 30)"), '1.04 musi przejąć worker 1.00.');
$check(str_contains($r104, "add_action(self::QUEUE_HOOK, [__CLASS__, 'run_queue_guarded'], 40)"), 'Na głównym hooku ma pozostać jeden chroniony worker 1.04.');
$check(str_contains($r104, "add_action(self::PUMP_HOOK, [__CLASS__, 'pump_queue'], 40)"), 'Minutowy pump ma kierować do workera 1.04.');

// Pump nie może już wywołać batch-workera bezpośrednio.
$check(str_contains($r104, 'do_action(self::QUEUE_HOOK);'), 'Pump 1.04 powinien przechodzić przez kanoniczny hook kolejki.');
$check(!str_contains($r104, 'BCS_Release_097::run_queue();'), '1.04 nie może bezpośrednio uruchamiać starego batch-workera 0.97.');
$check(!str_contains($r104, 'BATCH_SIZE'), '1.04 nie może wprowadzać wysyłki partiami.');

// Współbieżne cron/event nie mogą przejść równolegle.
$check(str_contains($r104, "private const LOCK_OPTION = 'bcs_marketing_queue_lock_104'"), '1.04 powinno mieć globalny lock kolejki.');
$check(str_contains($r104, 'add_option(self::LOCK_OPTION'), 'Lock powinien być zakładany atomowo przez unikalny option_name.');
$check(str_contains($r104, 'delete_option(self::LOCK_OPTION)'), 'Lock powinien być zwalniany i mieć obsługę wygaśnięcia.');
$check(str_contains($r104, 'finally'), 'Lock musi być zwalniany również po wyjątku.');
$check(substr_count($r104, 'BCS_Release_100::run_queue();') === 1, 'Chroniony worker powinien uruchomić właściwy worker 1.00 dokładnie raz.');

// Właściwy worker 1.00 nadal wymusza wszystkie ustawienia użytkownika.
$check(str_contains($r100, "sent_today_count() >= (int)\$delivery['daily_limit']"), 'Worker musi respektować globalny limit dzienny.');
$check(str_contains($r100, 'NEXT_SEND_OPTION'), 'Worker musi respektować termin kolejnej dozwolonej wysyłki.');
$check(str_contains($r100, "ORDER BY r.id ASC LIMIT 1"), 'Worker może wybrać najwyżej jednego odbiorcę na przebieg.');
$check(str_contains($r100, "wp_rand(\$gapMin,\$gapMax)"), 'Po wysyłce kolejny termin powinien wynikać z ustawionego losowego odstępu.');

if ($failures) {
    fwrite(STDERR, "Release 1.04 mailing throttle worker test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 1.04 mailing throttle worker checks passed.\n";

require __DIR__.'/release-105-mailing-history-agreement-date-test.php';
