<?php

declare(strict_types=1);

if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$r100 = (string)file_get_contents($root.'/includes/class-bcs-release-100.php');
$css = (string)file_get_contents($root.'/assets/mailing-100.css');
$js = (string)file_get_contents($root.'/assets/mailing-100.js');

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === '1.00', 'Nagłówek wtyczki powinien mieć wersję 1.00.');
$check(($constantVersion[1] ?? '') === '1.00', 'BCS_VERSION powinno mieć wersję 1.00.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-100.php';"), 'Bootstrap powinien ładować release 1.00.');
$check(str_contains($plugin, 'BCS_Release_100::init();'), 'Bootstrap powinien inicjalizować release 1.00.');

// Nowoczesny moduł Mailing.
$check(str_contains($r100, "remove_submenu_page('bcs-dashboard', self::PAGE)"), '1.00 powinno zastąpić stary callback strony Mailing.');
$check(str_contains($r100, "add_submenu_page('bcs-dashboard', 'Mailing', 'Mailing'"), '1.00 powinno rejestrować nową stronę Mailing.');
$check(str_contains($r100, 'bcs-mailing-kpis'), 'Mailing powinien mieć nowoczesne kafle KPI.');
$check(str_contains($r100, 'bcs-mailing-tabs'), 'Mailing powinien mieć własną przejrzystą nawigację.');
$check(str_contains($css, '.bcs-mailing-kpis'), 'Powinien istnieć osobny styl nowoczesnych KPI Mailingu.');
$check(str_contains($css, '.bcs-mail-card'), 'Powinny istnieć nowoczesne karty Mailingu.');

// Segmenty bez ręcznego wpisywania ID/roku.
$check(str_contains($r100, 'public static function audience_catalog()'), '1.00 powinno budować katalog segmentów z danych systemu.');
$check(str_contains($r100, "SELECT DISTINCT YEAR(start_date)"), 'Lata powinny pochodzić z turnusów zapisanych w systemie.');
$check(str_contains($r100, 'SELECT id,name,start_date,end_date,status FROM '), 'Katalog turnusów powinien pobierać nazwę i daty turnusu.');
$check(str_contains($r100, "BCS_DB::table('camps')"), 'Katalog turnusów powinien pochodzić z tabeli camps.');
$check(str_contains($r100, "BCS_Release_097::audience_contacts"), 'Liczby przy segmentach powinny używać tego samego filtra aktywnych zgód co kampania.');
$check(str_contains($r100, 'data-bcs-audience-year'), 'Rok powinien być wybierany z listy rozwijanej.');
$check(str_contains($r100, 'data-bcs-audience-camp'), 'Turnus powinien być wybierany z listy rozwijanej.');
$check(str_contains($r100, "e-maili)"), 'Opcje turnusu/roku powinny pokazywać liczbę dostępnych e-maili w nawiasie.');
$check(!str_contains($r100, 'np. 2026 lub ID turnusu'), '1.00 nie może wracać do ręcznego wpisywania roku lub ID turnusu.');
$check(str_contains($js, '[data-bcs-audience-type]'), 'JS powinien przełączać właściwą listę segmentu.');
$check(str_contains($js, 'selectedOptions[0]?.dataset.count'), 'JS powinien aktualizować podgląd liczby odbiorców.');

// Dashboard i monitoring reputacji.
$check(str_contains($r100, 'dashboard_footer_widget'), '1.00 powinno dodawać moduł Mailingu na Dashboardzie.');
foreach (['Aktywne zgody','Wysłano w miesiącu','CTR kliknięć','Błędy w miesiącu','Wypisani','Aktywne kampanie'] as $label) {
    $check(str_contains($r100, $label), 'Dashboard powinien zawierać statystykę: '.$label);
}
foreach (['SPF','DMARC','DKIM','One-Click'] as $label) {
    $check(str_contains($r100, $label), 'Dashboard powinien pokazywać monitoring: '.$label);
}
$check(str_contains($r100, 'Zgłoszenia jako SPAM:'), 'Dashboard powinien jasno opisywać dostępność danych o zgłoszeniach SPAM.');
$check(str_contains($r100, 'Google Postmaster / feedback loop'), 'System nie może udawać wewnętrznego pomiaru skarg SPAM bez zewnętrznego źródła.');

// Ustawienia newslettera w głównych Ustawieniach.
$check(str_contains($r100, "remove_submenu_page('bcs-dashboard', 'bcs-mailing-deliverability')"), 'Osobna pozycja Dostarczalność powinna zniknąć z menu.');
$check(str_contains($r100, "remove_submenu_page('bcs-dashboard', 'bcs-settings')"), '1.00 powinno przejąć stronę głównych Ustawień.');
$check(str_contains($r100, 'BCS_Admin::settings();'), 'Nowa strona Ustawień powinna zachować wszystkie istniejące ustawienia systemu.');
$check(str_contains($r100, 'Newsletter / mailing promocyjny'), 'W głównych Ustawieniach powinna istnieć sekcja newslettera.');
$check(str_contains($r100, 'marketing_transport'), 'Powinien istnieć wybór transportu newslettera.');
$check(str_contains($r100, 'marketing_smtp_host'), 'Powinien istnieć osobny host SMTP newslettera.');
$check(str_contains($r100, 'marketing_smtp_port'), 'Powinien istnieć osobny port SMTP newslettera.');
$check(str_contains($r100, 'marketing_smtp_username'), 'Powinien istnieć osobny login SMTP newslettera.');
$check(str_contains($r100, 'marketing_smtp_password'), 'Powinno istnieć osobne hasło SMTP newslettera.');
$check(str_contains($r100, 'test_newsletter_mailbox'), 'Powinna istnieć możliwość testu skrzynki newslettera.');
$check(str_contains($js, '[data-bcs-newsletter-transport]'), 'JS powinien pokazywać pola osobnego SMTP tylko gdy są potrzebne.');

// Produkcyjna kolejka i test kampanii korzystają z osobnego transportu.
$check(str_contains($r100, "remove_action(self::QUEUE_HOOK, [BCS_Release_099::class, 'run_queue'], 20)"), '1.00 powinno przejąć kolejkę 0.99.');
$check(str_contains($r100, "ORDER BY r.id ASC LIMIT 1"), '1.00 nadal może przetwarzać najwyżej jednego odbiorcę na dozwolony odstęp.');
$check(str_contains($r100, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'), 'One-click unsubscribe musi pozostać w nowym transporcie.');
$check(str_contains($r100, 'configure_newsletter_phpmailer'), 'Osobne SMTP musi konfigurować PHPMailer.');
$check(str_contains($r100, "remove_action('admin_post_'.self::CAMPAIGN_TEST_ACTION"), 'Test kampanii powinien być przejęty przez transport 1.00.');
$check(str_contains($r100, 'BCS_Release_097::build_recipient_message'), 'Test kampanii powinien zachować identyczny wygląd wiadomości kampanii.');

if ($failures) {
    fwrite(STDERR, "Release 1.00 mailing UI/dashboard/settings test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 1.00 mailing UI/dashboard/settings checks passed.\n";
