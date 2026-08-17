<?php

declare(strict_types=1);

if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$r097 = (string)file_get_contents($root.'/includes/class-bcs-release-097.php');
$r099 = (string)file_get_contents($root.'/includes/class-bcs-release-099.php');

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === ($constantVersion[1] ?? ''), 'Nagłówek i BCS_VERSION powinny być zgodne.');
$check(version_compare((string)($headerVersion[1] ?? '0'), '0.99', '>='), 'Wtyczka powinna mieć wersję co najmniej 0.99.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-099.php';"), 'Bootstrap powinien ładować release 0.99.');
$check(str_contains($plugin, 'BCS_Release_099::init();'), 'Bootstrap powinien inicjalizować release 0.99.');

$check(str_contains($r097, 'private const BATCH_SIZE = 20'), 'Test powinien potwierdzać, że 0.99 rzeczywiście zastępuje wcześniejszą agresywną partię 20.');
$check(str_contains($r099, "remove_action(self::QUEUE_HOOK, [BCS_Release_097::class, 'run_queue'])"), '0.99 powinno wyłączyć kolejkę 20/uruchomienie z 0.97.');
$check(str_contains($r099, "add_action(self::QUEUE_HOOK, [__CLASS__, 'run_queue'], 20)"), '0.99 powinno przejąć hook kolejki.');
$check(str_contains($r099, "'daily_limit' => 10"), 'Domyślny globalny limit powinien wynosić 10 maili dziennie.');
$check(str_contains($r099, "'window_start' => 9"), 'Domyślne okno powinno zaczynać się o 09:00.');
$check(str_contains($r099, "'window_end' => 19"), 'Domyślne okno powinno kończyć się o 19:00.');
$check(str_contains($r099, "'gap_min_minutes' => 45"), 'Minimalny domyślny odstęp powinien wynosić 45 minut.');
$check(str_contains($r099, "'gap_max_minutes' => 90"), 'Maksymalny domyślny odstęp powinien wynosić 90 minut.');
$check(str_contains($r099, 'wp_rand('), 'Odstęp powinien mieć niewielki jitter zamiast burstów.');
$check(str_contains($r099, 'sent_today_count() >= (int)$s[\'daily_limit\']'), 'Kolejka musi respektować globalny limit dzienny.');
$check(str_contains($r099, 'within_send_window('), 'Kolejka musi respektować okno godzinowe.');
$check(str_contains($r099, 'NEXT_SEND_OPTION'), 'Kolejka musi przechowywać najwcześniejszy czas kolejnej wysyłki.');
$check(!str_contains($r099, 'LIMIT 20'), '0.99 nie może ponownie wybierać 20 odbiorców na jeden przebieg.');
$check(str_contains($r099, 'ORDER BY r.id ASC LIMIT 1'), '0.99 powinno przetwarzać maksymalnie jednego odbiorcę na dozwolony odstęp.');

$check(str_contains($r099, 'List-Unsubscribe: <'), 'Właściwa wysyłka kampanii musi dodawać List-Unsubscribe.');
$check(str_contains($r099, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'), 'Właściwa wysyłka kampanii musi obsługiwać RFC 8058 one-click unsubscribe.');
$check(str_contains($r099, 'BCS_Release_096::unsubscribe_url($contact)'), 'Nagłówek one-click powinien korzystać z istniejącego tokenowego wypisania kontaktu.');
$check(str_contains($r099, 'marketing_from_email'), '0.99 powinno pozwalać użyć osobnego adresu marketingowego.');
$check(str_contains($r099, 'marketing_reply_to'), '0.99 powinno pozwalać ustawić osobny Reply-To marketingu.');
$check(str_contains($r099, 'configure_marketing_phpmailer'), 'Osobny adres marketingowy powinien być wymuszany także na PHPMailerze.');

$check(str_contains($r099, "txt_contains(\$domain, 'v=spf1')"), 'Panel powinien diagnozować SPF.');
$check(str_contains($r099, "txt_contains('_dmarc.'.\$domain, 'v=DMARC1')"), 'Panel powinien diagnozować DMARC.');
$check(str_contains($r099, "'dkim_selector'"), 'Panel powinien wspierać diagnostykę selektora DKIM.');
$check(str_contains($r099, 'Mailing – Dostarczalność'), 'Powinien istnieć ekran ustawień dostarczalności.');

$check(str_contains($r099, 'max_consecutive_failures'), 'Powinien istnieć próg kolejnych błędów transportu.');
$check(str_contains($r099, 'AUTO_PAUSE_OPTION'), 'Powinien istnieć globalny bezpiecznik automatycznego zatrzymania.');
$check(str_contains($r099, "SET status='paused'"), 'Po przekroczeniu progu błędów aktywne kampanie powinny zostać wstrzymane.');
$check(str_contains($r099, 'm.consent_status'), 'Przed właściwą wysyłką nadal musi być sprawdzana aktywna zgoda.');

if ($failures) {
    fwrite(STDERR, "Release 0.99 mailing deliverability test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.99 mailing deliverability checks passed.\n";
