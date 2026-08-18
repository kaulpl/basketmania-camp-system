<?php

declare(strict_types=1);

if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');

$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$r108 = (string)file_get_contents($root.'/includes/class-bcs-release-108.php');
$ksefService = (string)file_get_contents($root.'/includes/class-bcs-ksef-service.php');
$ksefFa3 = (string)file_get_contents($root.'/includes/class-bcs-ksef-fa3.php');
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

$check(str_contains($ksefFa3, 'ksef_xml_path'), 'FA(3) powinno zapisywać lokalny XML wysyłany do KSeF.');
$check(str_contains($ksefFa3, 'ksef_xml_hash'), 'FA(3) powinno zapisywać SHA-256 XML wysyłanego do KSeF.');
$check(str_contains($ksefService, 'save_upo_if_available'), 'Po akceptacji faktury KSeF powinien próbować pobrać UPO.');
$check(str_contains($ksefService, 'upoDownloadUrl'), 'Mechanizm UPO powinien korzystać z adresu zwróconego przez KSeF.');
$check(str_contains($ksefService, 'ksef_upo_path'), 'Ścieżka UPO powinna być zapisywana przy fakturze.');
$check(str_contains($ksefService, 'fetch_remote_xml'), 'System powinien umieć pobrać źródłowy XML faktury z KSeF.');
$check(str_contains($ksefService, 'ksef_remote_xml_path'), 'Ścieżka XML pobranego z KSeF powinna być zapisywana przy fakturze.');
foreach (['ksef_xml_path','ksef_xml_hash','ksef_upo_path','ksef_remote_xml_path','ksef_session_reference','ksef_invoice_reference'] as $column) {
    $check(str_contains($ksefInstall, $column), 'Migracja KSeF powinna zawierać kolumnę '.$column.'.');
}

foreach (['bcs_ksef_evidence_backfill_108','ensure_invoice_ksef_evidence','try_fetch_upo_without_status_downgrade','BCS_KSeF_Service::fetch_remote_xml',"ksef_status='accepted'"] as $needle) {
    $check(str_contains($r108, $needle), 'Brakuje mechanizmu dowodowego KSeF 1.08: '.$needle);
}
$check(!str_contains($r108, 'BCS_KSeF_Service::refresh_status($invoiceId)'), 'Backfill zaakceptowanej faktury nie powinien obniżać statusu przez zwykły błąd połączenia.');

foreach (["ARCHIVES_TABLE = 'camp_archives'",'archived_at','archived_by','archive_latest_id',"'status'=>'archived'",'Zarchiwizowane','Aktywne','Wszystkie'] as $needle) {
    $check(str_contains($r108, $needle), 'Brakuje elementu archiwizacji turnusu: '.$needle);
}
$check(str_contains($r108, 'guard_archived_mutations'), 'Zarchiwizowany turnus powinien mieć centralną blokadę modyfikacji.');
$check(str_contains($r108, 'Dane historyczne są tylko do odczytu'), 'Interfejs powinien jasno informować o trybie tylko do odczytu.');

foreach (['ZipArchive','manifest.json','SHA256SUMS.txt',"hash_file('sha256'",'README.txt','dane/uczestnicy.csv','dane-wrazliwe/uczestnicy-dane-wrazliwe.csv','dane/rozliczenia.csv','dane/ksef.csv','dane/logi.csv','korespondencja/zgloszenie-','02-umowa-podpisana.pdf','faktura.pdf','fa3-wyslany.xml','xml-pobrany-z-ksef.xml','upo.xml','potwierdzenie-ksef.json'] as $needle) {
    $check(str_contains($r108, $needle), 'Paczka ZIP powinna zawierać / obsługiwać: '.$needle);
}
$check(str_contains($r108, 'basketmania-archives'), 'Archiwa powinny być przechowywane w wydzielonym katalogu.');
$check(str_contains($r108, 'Require all denied') && str_contains($r108, 'web.config'), 'Katalog archiwów powinien być chroniony przed bezpośrednim odczytem HTTP.');
$check(str_contains($r108, "current_user_can('manage_options')"), 'Pobranie archiwum powinno wymagać uprawnień administratora.');

foreach (['database/turnus-','database/struktura-bazy.sql','SHOW CREATE TABLE','SET FOREIGN_KEY_CHECKS=0','START TRANSACTION','COMMIT;',"BCS_DB::table('registrations')","BCS_DB::table('agreements')","BCS_DB::table('agreement_versions')","BCS_DB::table('payments')","BCS_DB::table('invoices')","BCS_DB::table('mail_messages')","BCS_DB::table('logs')","BCS_DB::table('ksef_operations')"] as $needle) {
    $check(str_contains($r108, $needle), 'Zrzut SQL powinien zawierać / obsługiwać: '.$needle);
}
$check(str_contains($r108, 'preg_match('), 'Eksport organizatora powinien sanitizować pola konfiguracyjne przed zapisem do SQL.');
$check(!str_contains($r108, "get_option('bcs_settings'"), 'Archiwum SQL nie powinno eksportować globalnej konfiguracji systemu.');

$check(str_contains($r108, 'completeness_warnings'), 'Archiwizacja powinna wykonywać kontrolę kompletności turnusu.');
$check(str_contains($r108, 'requires_attention'), 'Niekompletne archiwum powinno otrzymywać status „wymaga uwagi”.');
$check(str_contains($r108, 'Niepodpisane umowy') && str_contains($r108, 'Niepełne rozliczenia') && str_contains($r108, 'Faktury wymagające uwagi'), 'Checklista powinna obejmować umowy, rozliczenia i faktury.');

if ($failures) {
    fwrite(STDERR, "Release 1.08 camp archive/KSeF evidence test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 1.08 camp archive/KSeF evidence checks passed.\n";
