<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$r082 = (string)file_get_contents($root.'/includes/class-bcs-release-082.php');
$r083 = (string)file_get_contents($root.'/includes/class-bcs-release-083.php');
$r111 = (string)file_get_contents($root.'/includes/class-bcs-release-111.php');
$fa3 = (string)file_get_contents($root.'/includes/class-bcs-ksef-fa3.php');

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === ($constantVersion[1] ?? ''), 'Nagłówek i BCS_VERSION powinny być zgodne.');
$check(version_compare((string)($headerVersion[1] ?? '0'), '1.11', '>='), 'Wtyczka powinna mieć wersję co najmniej 1.11.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-111.php';"), 'Bootstrap powinien ładować release 1.11.');
$check(str_contains($plugin, 'BCS_Release_111::init();'), 'Bootstrap powinien inicjalizować release 1.11.');

// Faktura na firmę nadal przechowuje prawdziwe dane do lokalnego PDF.
$check(str_contains($r083, "'invoice_nip'=>\$valid['type'] === 'company' ? \$valid['nip'] : ''"), 'Profil firmy powinien zachować NIP dla właściwej faktury/PDF.');
$check(str_contains($r083, "BCS_Release_082::generate_guarded(\$registrationId)"), 'Właściwa faktura powinna przechodzić przez guard 0.82.');

// Generator FA(3) ma istniejącą warstwę anonimizacji TEST.
foreach ([
    "\$buyer['nip'] = '';",
    "\$buyer['name'] = 'Nabywca Testowy';",
    "\$buyer['address_l1'] = 'ul. Przykładowa 2';",
    "\$buyer['address_l2'] = '00-002 Miasto Testowe';",
    "\$buyer['anonymized'] = true;",
] as $needle) {
    $check(str_contains($fa3, $needle), 'FA(3) powinno anonimizować element TEST: '.$needle);
}

// 1.11 usuwa historyczne wyłączenie ochrony i rozdziela regułę TEST/PRODUKCJA.
$check(!str_contains($r082, "['ksef_anonymize_test'=>0]"), 'Nie wolno już wyłączać anonimizacji na czas właściwej faktury TEST.');
$check(str_contains($r082, "['ksef_anonymize_test'=>1]"), '0.82 powinno wymuszać ochronę przed generowaniem właściwej faktury TEST.');
$check(str_contains($r082, "if (\$environment === 'test')"), 'Guard powinien rozróżniać TEST od PRODUKCJI.');
$check(str_contains($r082, 'test_buyer_is_anonymized($xml)'), 'TEST powinien przechodzić twardą kontrolę zanonimizowanego Podmiot2.');
$check(str_contains($r082, 'TEST_BUYER_NOT_ANONYMIZED_111'), 'Niezanonimizowany TEST powinien być blokowany dedykowanym kodem.');
$check(str_contains($r082, 'buyer_snapshots_match($expected, $actual)'), 'PRODUKCJA powinna zachować zgodność danych PDF ↔ KSeF.');

// Ustawienie anonimizacji TEST nie może już być opcjonalne administracyjnie.
$check(str_contains($r111, "SET ksef_anonymize_test=1 WHERE ksef_environment='test'"), '1.11 powinno naprawiać historycznie wyłączoną anonimizację TEST w bazie.');
$check(str_contains($r111, "\$_POST['ksef_anonymize_test'] = '1'"), 'Zapis Organizatora w TEST powinien serwerowo wymuszać anonimizację.');
$check(str_contains($r111, "checkbox.disabled=true"), 'Interfejs nie powinien pozwalać wyłączyć ochrony TEST.');
$check(str_contains($r111, 'Dotyczy to również faktury na firmę'), 'UI powinien jasno komunikować ochronę faktur firmowych w TEST.');

// Stara ręczna ścieżka 0.75 musi regenerować i sprawdzać XML przed wysłaniem.
$check(str_contains($r111, "remove_action('wp_ajax_bcs_ksef_send_075'"), '1.11 powinno przejąć historyczną ręczną wysyłkę 0.75.');
$check(str_contains($r111, 'BCS_KSeF_FA3::prepare_and_save($invoiceId)'), 'Przed ręczną wysyłką TEST XML powinien być regenerowany.');
$check(str_contains($r111, 'BCS_Release_082::test_buyer_is_anonymized($xml)'), 'Przed ręczną wysyłką TEST powinna działać kontrola Podmiot2.');
$check(str_contains($r111, 'TEST_ANONYMIZATION_GUARD_111'), 'Ręczna wysyłka powinna mieć osobny kod blokady anonimizacji.');
$check(str_contains($r111, 'BCS_KSeF_Service::send($invoiceId)'), 'Dopiero po kontroli dokument może trafić do serwisu KSeF.');

if ($failures) {
    fwrite(STDERR, "Release 1.11 KSeF TEST anonymization test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 1.11 mandatory KSeF TEST anonymization checks passed.\n";
