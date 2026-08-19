<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$r109 = (string)file_get_contents($root.'/includes/class-bcs-release-109.php');

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === ($constantVersion[1] ?? ''), 'Nagłówek i BCS_VERSION powinny być zgodne.');
$check(version_compare((string)($headerVersion[1] ?? '0'), '1.09', '>='), 'Wtyczka powinna mieć wersję co najmniej 1.09.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-109.php';"), 'Bootstrap powinien ładować release 1.09.');
$check(str_contains($plugin, 'BCS_Release_109::init();'), 'Bootstrap powinien inicjalizować release 1.09.');

$check(str_contains($r109, "private const DOWNLOAD_LIVE_ACTION = 'bcs_download_live_camp_archive_109'"), '1.09 powinno mieć osobny endpoint pobrania bieżącego archiwum.');
$check(str_contains($r109, "admin_post_'.self::DOWNLOAD_LIVE_ACTION"), 'Pobranie bez zamykania powinno przechodzić przez chroniony admin-post.');
$check(str_contains($r109, 'Pobierz archiwum bez zamykania turnusu'), 'Interfejs powinien jasno opisywać pobranie bez zamykania.');
$check(str_contains($r109, '>Pobierz archiwum</button>'), 'Powinien istnieć przycisk „Pobierz archiwum”.');
$check(str_contains($r109, 'BCS_Release_108::build_archive($campId)'), 'Pobranie powinno korzystać z pełnego generatora paczki 1.08.');

// Kluczowa gwarancja: po zbudowaniu paczki stan turnusu wraca do wartości sprzed eksportu.
foreach (['status','archived_at','archived_by','archive_path','archive_hash','archive_status','archive_size','archive_manifest','archive_created_at','updated_at'] as $field) {
    $check(str_contains($r109, $field), 'Snapshot stanu turnusu powinien obejmować pole '.$field.'.');
}
$check(str_contains($r109, '$wpdb->update($table, $snapshot, [\'id\'=>$campId]);'), 'Po wygenerowaniu ZIP system powinien przywrócić pełny snapshot turnusu.');
$check(str_contains($r109, "if ((string)(\$snapshot['status'] ?? '') === 'archived')"), 'Opcja bez zamykania nie powinna zastępować zwykłego pobrania już zarchiwizowanego turnusu.');
$check(!str_contains($r109, "'status'=>'archived'"), 'Release 1.09 nie może sam ustawiać statusu archived.');
$check(str_contains($r109, 'remove_false_archive_log'), 'Tymczasowe użycie generatora nie może zostawiać fałszywego wpisu „turnus zarchiwizowany”.');
$check(str_contains($r109, "BCS_Utils::log('camp_archive_download_without_closing'"), 'Pobranie aktualnego archiwum powinno mieć własny wpis audytowy.');

// Tymczasowy ZIP nie powinien zalegać na serwerze po pobraniu.
$check(str_contains($r109, 'register_shutdown_function'), 'Tymczasowy plik ZIP powinien być sprzątany po odpowiedzi.');
$check(str_contains($r109, '@unlink($path)'), 'Tymczasowy ZIP powinien zostać usunięty po pobraniu.');
$check(str_contains($r109, "header('Content-Type: application/zip')"), 'Endpoint powinien zwracać plik ZIP.');
$check(str_contains($r109, 'readfile($path)'), 'Endpoint powinien przesyłać wygenerowaną paczkę administratorowi.');
$check(str_contains($r109, "current_user_can('manage_options')"), 'Pobieranie archiwum powinno wymagać uprawnień administratora.');
$check(str_contains($r109, 'check_admin_referer'), 'Pobieranie archiwum powinno być chronione nonce.');

if ($failures) {
    fwrite(STDERR, "Release 1.09 download archive without closing test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 1.09 download archive without closing checks passed.\n";
