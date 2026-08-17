<?php

declare(strict_types=1);

if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$r096 = (string)file_get_contents($root.'/includes/class-bcs-release-096.php');
$r097 = (string)file_get_contents($root.'/includes/class-bcs-release-097.php');
$r098 = (string)file_get_contents($root.'/includes/class-bcs-release-098.php');
require_once $root.'/includes/class-bcs-release-096.php';

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === '0.98', 'Nagłówek wtyczki powinien mieć wersję 0.98.');
$check(($constantVersion[1] ?? '') === '0.98', 'BCS_VERSION powinno mieć wersję 0.98.');
foreach (['096','097','098'] as $v) {
    $check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-{$v}.php';"), "Bootstrap powinien ładować release {$v}.");
    $check(str_contains($plugin, "BCS_Release_{$v}::init();"), "Bootstrap powinien inicjalizować release {$v}.");
}

// 0.96 – kontakty, zgody, import i formularz.
$check(str_contains($r096, "BCS_DB::table('marketing_contacts')"), '0.96 powinno tworzyć centralną bazę kontaktów mailingowych.');
$check(str_contains($r096, "BCS_DB::table('marketing_consent_events')"), '0.96 powinno przechowywać historię zgód.');
$check(str_contains($r096, "name=\"marketing_email_consent\""), 'Formularz zgłoszeniowy powinien mieć osobny checkbox marketingowy.');
$check(!str_contains($r096, 'name="marketing_email_consent" value="1" required'), 'Checkbox marketingowy nie może być wymagany.');
$check(str_contains($r096, "'registration_form'"), 'Nowe zgłoszenie powinno zapisywać źródło zgody registration_form.');
$check(str_contains($r096, "'legacy_registration'"), 'Starsze zgłoszenia powinny być synchronizowane bez domniemanej zgody.');
$check(str_contains($r096, "true, 'import'"), 'Import powinien zgodnie z założeniem oznaczać zgodę jako udzieloną.');
$check(str_contains($r096, "status'=>'unsubscribed'"), 'Musi istnieć trwały status wypisania z mailingu.');
$check(str_contains($r096, 'unsubscribe_token'), 'Wypisanie powinno działać po unikalnym tokenie.');

$csvSemicolon = "email;imie;nazwisko\nanna@example.com;Anna;Żółć\njan@example.com;Jan;Kowalski\nanna@example.com;Anna;Żółć\n";
$parsed = BCS_Release_096::parse_import_content($csvSemicolon);
$check(count($parsed) === 2, 'Import CSV powinien deduplikować adresy e-mail.');
$check(($parsed[0]['email'] ?? '') === 'anna@example.com', 'CSV ze średnikiem powinien poprawnie odczytać e-mail.');
$check(($parsed[0]['first_name'] ?? '') === 'Anna', 'CSV powinien odczytać imię.');
$check(($parsed[0]['last_name'] ?? '') === 'Żółć', 'CSV powinien zachować polskie znaki w nazwisku.');
$csvComma = "email,first_name,last_name\nola@example.com,Ola,Nowak\n";
$parsedComma = BCS_Release_096::parse_import_content($csvComma);
$check(count($parsedComma) === 1 && ($parsedComma[0]['email'] ?? '') === 'ola@example.com', 'Import powinien obsługiwać przecinek.');
$txt = "one@example.com\ntwo@example.com\n";
$parsedTxt = BCS_Release_096::parse_import_content($txt);
$check(count($parsedTxt) === 2, 'Import powinien obsługiwać jeden adres e-mail w wierszu.');

// 0.97 – kampanie i kolejka.
$check(str_contains($r097, "BCS_DB::table('marketing_campaigns')"), '0.97 powinno tworzyć tabelę kampanii.');
$check(str_contains($r097, "BCS_DB::table('marketing_campaign_recipients')"), '0.97 powinno tworzyć snapshot odbiorców kampanii.');
$check(str_contains($r097, "m.consent_status='yes' AND m.status='active'"), 'Segment kampanii musi wymagać aktywnej zgody.');
$check(str_contains($r097, 'private const BATCH_SIZE = 20'), 'Kolejka powinna wysyłać bezpiecznymi partiami.');
$check(str_contains($r097, 'subject_snapshot'), 'Powinien być przechowywany dokładny temat dla odbiorcy.');
$check(str_contains($r097, 'body_snapshot'), 'Powinien być przechowywany dokładny HTML dla odbiorcy.');
$check(str_contains($r097, "'status'=>'queued'"), 'Uruchomienie kampanii powinno tworzyć kolejkę.');
$check(str_contains($r097, 'wp_schedule_single_event'), 'Wysyłka kampanii powinna działać przez WP-Cron.');
$check(str_contains($r097, 'wp_mail($to, $subject, $html, $headers)'), 'Marketing powinien korzystać z transportu pocztowego bez dopisywania do korespondencji zgłoszenia.');
$check(!str_contains($r097, 'BCS_Mailer::send('), 'Kampania nie może używać BCS_Mailer::send(), który wiąże wiadomości z korespondencją zgłoszenia.');
$check(str_contains($r097, 'Brak aktywnej zgody w chwili wysyłki.'), 'Zgoda musi być sprawdzana ponownie tuż przed wysłaniem.');
$check(str_contains($r097, 'Wypisz się z mailingu'), 'Każda kampania powinna zawierać link wypisania.');
$check(str_contains($r097, 'preg_replace(\'/(<body\\b[^>]*>)/i\''), 'Preheader powinien być wstawiany po otwarciu body, a nie do atrybutów tagu.');
$check(str_contains($r097, 'scheduled_timestamp'), 'Planowana wysyłka powinna respektować strefę czasową WordPressa.');
$check(str_contains($r097, 'Brak odbiorców z aktywną zgodą'), 'Nie powinno dać się uruchomić pustej kampanii.');

// 0.98 – audyt odbiorcy, roczne wysyłki i kliknięcia.
$check(str_contains($r098, "status='sent' GROUP BY mailing_year"), 'Roczne podsumowanie powinno liczyć wyłącznie faktycznie wysłane wiadomości.');
$check(str_contains($r098, 'Historia kampanii'), 'Kontakt powinien mieć historię kampanii.');
$check(str_contains($r098, 'Brany pod uwagę'), 'Historia powinna pokazywać sam fakt uwzględnienia w kampanii.');
$check(str_contains($r098, 'subject_snapshot'), 'Historia powinna prezentować snapshot tematu.');
$check(str_contains($r098, 'body_snapshot'), 'Historia powinna prezentować snapshot dokładnej wiadomości.');
$check(str_contains($r098, 'clicked_at'), '0.98 powinno zapisywać kliknięcie CTA.');
$check(str_contains($r098, 'bcs-mailing-contact-history'), 'Historia kontaktu powinna być oddzielnym ekranem administracyjnym.');
$check(str_contains($r098, 'bcs-mailing-campaign-history'), 'Szczegóły kampanii powinny być oddzielnym ekranem administracyjnym.');

if ($failures) {
    fwrite(STDERR, "Release 0.98 mailing system test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.98 mailing system checks passed.\n";
