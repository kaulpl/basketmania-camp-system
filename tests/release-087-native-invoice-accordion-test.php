<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-087.php');
$workflow = (string)file_get_contents($root.'/.github/workflows/php-lint.yml');

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === '0.87', 'Nagłówek wtyczki powinien mieć wersję 0.87.');
$check(($constantVersion[1] ?? '') === '0.87', 'BCS_VERSION powinno mieć wersję 0.87.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-087.php';"), 'Bootstrap powinien ładować release 0.87.');
$check(str_contains($plugin, 'BCS_Release_087::init();'), 'Bootstrap powinien inicjalizować release 0.87.');

$check(str_contains($release, "remove_action('admin_enqueue_scripts', ['BCS_Release_086', 'enqueue_admin_assets'], 1000);"), '0.87 powinno wyłączyć JS-ową zakładkę 0.86.');
$check(str_contains($release, "remove_action('admin_footer', ['BCS_Release_086', 'invoice_profile_template'], 9998);"), '0.87 powinno wyłączyć template zakładki 0.86.');
$check(!str_contains($release, 'MutationObserver'), '0.87 nie może używać MutationObserver do sekcji faktury.');
$check(!str_contains($release, 'fetch('), 'Zapis danych do faktury 0.87 nie powinien zależeć od AJAX/fetch.');
$check(!str_contains($release, 'data-bcs-data-tab'), '0.87 nie powinno tworzyć zakładek Dane do Faktury.');

$check(str_contains($release, 'class="bcs-panel bcs-accordion-panel bcs-invoice-profile-087"'), 'Dane do Faktury powinny używać tego samego kontenera accordion co pozostałe sekcje Karty.');
$check(str_contains($release, '<strong>Dane do Faktury</strong>'), 'Sekcja rozwijana powinna mieć nazwę Dane do Faktury.');
$check(str_contains($release, '<details'), 'Sekcja powinna korzystać z natywnego details/summary.');
$check(str_contains($release, 'bcs-invoice-editor-087'), 'Edycja powinna być osobnym natywnym rozwinięciem.');
$check(str_contains($release, "add_action('admin_post_'.self::SAVE_ACTION"), 'Zapis powinien korzystać z normalnego admin-post WordPressa.');
$check(str_contains($release, "action=\"<?php echo esc_url(admin_url('admin-post.php')); ?>\""), 'Formularz edycji powinien wysyłać dane do admin-post.php.');

$check(str_contains($release, 'Profil jest zapewniany dla KAŻDEGO zgłoszenia'), 'Profil fakturowy powinien być dostępny niezależnie od danych firmowych.');
$check(str_contains($release, "'billing_type'=>'individual'"), 'Brak danych firmowych powinien tworzyć profil osoby prywatnej.');
$check(str_contains($release, "'billing_name'=>trim((string)(\$r->parent_first_name"), 'Profil imienny powinien korzystać z danych rodzica/opiekuna.');
$check(str_contains($release, "'billing_source'=>'form_individual'"), 'Profil imienny powinien mieć właściwe źródło.');
$check(str_contains($release, 'available_without_company_data'), '0.87 powinno jawnie obsługiwać profil bez danych firmowych.');
$check(!str_contains($release, "(string)(\$r->form_status ?? '') !== 'complete'"), 'Renderowanie 0.87 nie może być uzależnione od form_status=complete.');

foreach (['billing_type','billing_name','billing_street','billing_postal_code','billing_city','billing_nip','billing_ksef_description','billing_notes'] as $field) {
    $check(str_contains($release, 'name="'.$field.'"'), 'Edycja 0.87 powinna zawierać pole '.$field.'.');
}
$check(str_contains($release, 'Faktura '), 'Po wystawieniu faktury sekcja powinna nadal pokazywać blokadę edycji.');
$check(str_contains($release, "insertAdjacentElement('afterend',section)"), 'Sekcja powinna zostać ustawiona bezpośrednio po Danych z formularza.');

$check(str_contains($workflow, 'Release 0.87 native invoice accordion test'), 'CI powinno uruchamiać test 0.87.');
$check(str_contains($workflow, 'php tests/release-087-native-invoice-accordion-test.php'), 'CI powinno uruchamiać właściwy test 0.87.');

if ($failures) {
    fwrite(STDERR, "Release 0.87 native invoice accordion test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.87 native invoice accordion checks passed.\n";
