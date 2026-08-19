<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$r108 = (string)file_get_contents($root.'/includes/class-bcs-release-108.php');
$ksefService = (string)file_get_contents($root.'/includes/class-bcs-ksef-service.php');
$ksefInstall = (string)file_get_contents($root.'/includes/class-bcs-ksef-install.php');

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === ($constantVersion[1] ?? ''), 'Nagłówek i BCS_VERSION powinny być zgodne.');
$check(version_compare((string)($headerVersion[1] ?? '0'), '1.08', '>='), 'Wtyczka powinna mieć wersję co najmniej 1.08.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-108.php';"), 'Bootstrap powinien ładować release 1.08.');
$check(str_contains($plugin, 'BCS_Release_108::init();'), 'Bootstrap powinien inicjalizować release 1.08.');

// KSeF: trwałe potwierdzenia wysyłki/przyjęcia.
foreach (['ksef_xml_path','ksef_xml_hash','ksef_sent_at','ksef_accepted_at','ksef_session_reference','ksef_invoice_reference','ksef_number','ksef_upo_path','ksef_remote_xml_path'] as $field) {
    $check(str_contains($ksefInstall, "'{$field}'"), 'Migracja KSeF powinna przechowywać pole '.$field.'.');
}
$check(str_contains($ksefService, 'save_upo_if_available'), 'Po akceptacji KSeF system powinien próbować zapisać UPO.');
$check(str_contains($ksefService, "'ksef_upo_path'=>\$path"), 'Ścieżka lokalnego UPO powinna zostać zapisana przy fakturze.');
$check(str_contains($ksefService, "'ksef_remote_xml_path'=>\$path"), 'XML pobrany bezpośrednio z KSeF powinien mieć zapisaną ścieżkę.');
$check(str_contains($ksefService, "'ksef_status'=>'accepted'"), 'Przyjęcie faktury przez KSeF powinno być utrwalone w bazie.');

// Archiwizacja.
foreach ([
    "'archived_at' => 'DATETIME NULL'",
    "'archive_path' => 'TEXT NULL'",
    "'archive_hash' => 'CHAR(64) NULL'",
    "'archive_manifest' => 'LONGTEXT NULL'",
    "'status'=>'archived'",
    'new ZipArchive()',
    "'manifest.json'",
    "'checksums.sha256'",
    "'database/camp-data.sql'",
    "'exports/zgloszenia.csv'",
    "'exports/faktury.csv'",
    "'exports/platnosci.csv'",
] as $needle) {
    $check(str_contains($r108, $needle), 'Brakuje elementu archiwizacji: '.$needle);
}
$check(str_contains($r108, "BCS_KSeF_Service::fetch_remote_xml"), 'Archiwizacja powinna próbować uzupełnić brakujący XML z KSeF.');
$check(str_contains($r108, "BCS_KSeF_Service::refresh_status"), 'Archiwizacja powinna ponowić sprawdzenie KSeF, aby spróbować pobrać brakujące UPO.');
$check(str_contains($r108, "admin_post_'.self::DOWNLOAD_ACTION"), 'Paczka powinna być pobierana przez autoryzowany endpoint administratora.');
$check(str_contains($r108, "current_user_can('manage_options')"), 'Pobieranie i tworzenie archiwum powinno wymagać uprawnień administratora.');
$check(str_contains($r108, "WP_CONTENT_DIR).'bcs-private-archives'"), 'Archiwa powinny trafiać do wydzielonego prywatnego katalogu.');
$check(str_contains($r108, 'Deny from all') && str_contains($r108, 'Require all denied'), 'Katalog archiwów powinien blokować bezpośredni dostęp HTTP.');

// Zrzut SQL ma być selektywny i bez sekretów/OTP.
$check(str_contains($r108, "BCS_DB::table('camps')"), 'SQL powinien zawierać rekord turnusu.');
$check(str_contains($r108, "BCS_DB::table('registrations')"), 'SQL powinien zawierać zgłoszenia turnusu.');
$check(str_contains($r108, "'agreements','agreement_versions','payments','invoices','logs','activities','messages','mail_messages'"), 'SQL powinien obejmować główne dane operacyjne turnusu.');
$check(str_contains($r108, "'ksef_operations'"), 'SQL powinien obejmować historię operacji KSeF faktur turnusu.');
$check(!str_contains($r108, "BCS_DB::table('otp'),"), 'Kody OTP nie powinny być eksportowane do archiwum.');
foreach (['stripe_test_secret_key','stripe_test_webhook_secret','stripe_live_secret_key','stripe_live_webhook_secret','ksef_token_ciphertext','ksef_token_nonce','ksef_production_token_ciphertext','ksef_production_token_nonce'] as $secret) {
    $check(str_contains($r108, "'{$secret}'"), 'Lista pól wyłączanych z SQL powinna obejmować '.$secret.'.');
}
$check(str_contains($r108, 'security_note'), 'Manifest powinien informować o wrażliwości danych w paczce.');
$check(str_contains($r108, 'archive_status') && str_contains($r108, "'warnings'"), 'Archiwum powinno oznaczać braki i ostrzeżenia zamiast je ukrywać.');

if ($failures) {
    fwrite(STDERR, "Release 1.08 camp archive test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 1.08 camp archive and KSeF evidence checks passed.\n";
require __DIR__.'/release-109-download-archive-without-closing-test.php';
