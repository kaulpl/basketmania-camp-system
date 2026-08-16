<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-081.php');
$crm = (string)file_get_contents($root.'/includes/class-bcs-crm.php');
$ksefConfig = (string)file_get_contents($root.'/includes/class-bcs-ksef-config.php');
$ksefSecret = (string)file_get_contents($root.'/includes/class-bcs-ksef-secret.php');
$workflow = (string)file_get_contents($root.'/.github/workflows/php-lint.yml');

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === '0.81', 'Nagłówek wtyczki powinien mieć wersję 0.81.');
$check(($constantVersion[1] ?? '') === '0.81', 'BCS_VERSION powinno mieć wersję 0.81.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-081.php';"), 'Bootstrap powinien ładować release 0.81.');
$check(str_contains($plugin, 'BCS_Release_081::init();'), 'Bootstrap powinien inicjalizować release 0.81.');

$check(str_contains($release, "add_action('admin_footer', [__CLASS__, 'admin_fixes'], 9999);"), '0.81 powinno uruchamiać poprawki po starszych skryptach administratora.');
$check(str_contains($release, 'function closeCampFormPopups()'), 'Brakuje funkcji zamykającej popupy po potwierdzeniu formularza.');
$check(str_contains($release, "text.includes('formularz')"), 'Sukces powinien być rozpoznawany jako operacja na formularzu.');
$check(str_contains($release, "text.includes('zaakcept')"), 'Lista zgłoszeń powinna rozpoznawać komunikat akceptacji formularza.');
$check(str_contains($release, "text.includes('potwierdz')"), 'Karta zgłoszenia powinna rozpoznawać komunikat potwierdzenia formularza.');
$check(str_contains($release, 'window.bcsPopup0190 = wrapped;'), '0.81 powinno objąć wspólny popup sukcesu używany przez listę i kartę.');
$check(str_contains($release, "window.bcsPopup0190('Formularz obozowy został pomyślnie potwierdzony przez Organizatora.'"), 'Powinien istnieć komunikat sukcesu również dla ścieżki po przekierowaniu.');
$check(str_contains($release, "document.body.classList.remove('bcs-modal-open', 'bcs-feedback-modal-open')"), 'Po sukcesie powinny zostać zdjęte blokady popupów z body.');

$check(str_contains($crm, 'data-created="'), 'Lista zgłoszeń musi udostępniać surowy lokalny created_at w data-created.');
$check(str_contains($release, "document.querySelectorAll('tr[data-id][data-created]')"), '0.81 powinno poprawiać widoczne daty wszystkich wierszy CRM.');
$check(str_contains($release, "const raw = String(row.dataset.created || '').trim();"), 'Data listy powinna pochodzić bezpośrednio z zapisanego created_at.');
$check(str_contains($release, "row.cells[1].innerHTML = '<strong>' + match[3] + '.' + match[2] + '.' + match[1]"), 'Data zgłoszenia powinna być wyświetlana bez ponownej konwersji strefy czasowej.');
$check(!str_contains($release, 'Date.parse(raw'), '0.81 nie może ponownie interpretować daty rejestracji przez strefę czasową przeglądarki.');

$check(str_contains($ksefConfig, "defined('BCS_KSEF_SECRET_KEY')"), 'Master key KSeF powinien nadal obsługiwać stałą serwera.');
$check(str_contains($ksefConfig, "getenv('BCS_KSEF_SECRET_KEY')"), 'Master key KSeF powinien nadal obsługiwać zmienną środowiskową.');
$check(str_contains($ksefSecret, 'random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)'), 'Każde szyfrowanie tokenu powinno używać losowego nonce.');
$check(str_contains($release, 'Jeden <code>BCS_KSEF_SECRET_KEY</code> zabezpiecza tokeny wszystkich Organizatorów'), 'Panel powinien wyjaśniać, że instalacja używa jednego klucza głównego.');
$check(str_contains($release, 'Każdy Organizator ma własny token KSeF zapisany osobno'), 'Panel powinien rozróżniać master key od tokenu konkretnego Organizatora.');
$check(str_contains($release, 'zmienną środowiskową serwera albo w <code>wp-config.php</code>'), 'Panel powinien wskazywać oba wspierane miejsca konfiguracji klucza.');
$check(str_contains($release, 'nie zapisuj go w repozytorium'), 'Panel powinien ostrzegać przed zapisaniem master key w repozytorium.');

$check(str_contains($workflow, 'Release 0.81 form confirmation/date/KSeF help test'), 'CI powinno uruchamiać test regresyjny 0.81.');
$check(str_contains($workflow, 'php tests/release-081-form-confirmation-date-ksef-help-test.php'), 'CI powinno uruchamiać właściwy plik testu 0.81.');

if ($failures) {
    fwrite(STDERR, "Release 0.81 test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.81 form confirmation/date/KSeF help checks passed.\n";
