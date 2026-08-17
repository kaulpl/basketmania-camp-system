<?php

declare(strict_types=1);

if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-094.php');
require_once $root.'/includes/class-bcs-release-094.php';

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$header = $headerVersion[1] ?? '';
$constant = $constantVersion[1] ?? '';
$check($header === $constant, 'Nagłówek wtyczki i BCS_VERSION powinny być zgodne.');
$check(version_compare($header, '0.94', '>='), 'Wtyczka powinna mieć wersję co najmniej 0.94.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-094.php';"), 'Bootstrap powinien ładować release 0.94.');
$check(str_contains($plugin, 'BCS_Release_094::init();'), 'Bootstrap powinien inicjalizować release 0.94.');

$check(str_contains($release, "add_action('admin_post_'.self::ACTION"), 'Generator powinien mieć chronioną akcję admin-post.');
$check(str_contains($release, 'Generuj drabinkę'), 'Przy turnusie powinien pojawić się przycisk Generuj drabinkę.');
$check(str_contains($release, 'button button-primary bcs-bracket-btn-094'), 'Przycisk drabinki powinien być niebieskim button-primary.');
$check(str_contains($release, "r.status<>'cancelled'"), 'Anulowane zgłoszenia nie mogą trafiać do drabinki.');
$check(str_contains($release, 'r.paid_amount>=r.total_amount'), 'Drabinka powinna uwzględniać zaksięgowane pełne wpłaty przy zgłoszeniu.');
$check(str_contains($release, "p.status='paid'"), 'Drabinka powinna uwzględniać rzeczywisty rekord płatności paid.');
$check(str_contains($release, 'BCS_Release_092::refresh_jersey_numbers($campId)'), 'Przed losowaniem powinny zostać odświeżone numery koszulek turnusu.');
$check(str_contains($release, 'random_int(0, $i)'), 'Każde generowanie powinno wykonywać kryptograficznie bezpieczne losowanie kolejności.');
$check(str_contains($release, "setPaper('A3', 'landscape')"), 'PDF powinien być ustawiony na A3 poziomo.');
$check(str_contains($release, '@page{size:A3 landscape'), 'CSS wydruku powinien deklarować A3 landscape.');
$check(str_contains($release, 'Nazwa konkursu / turnieju:'), 'Na górze PDF powinno być miejsce na nazwę rozgrywanego konkursu.');
$check(str_contains($release, '................................................................'), 'Miejsce na nazwę konkursu powinno być wykropkowane.');
$check(str_contains($release, '$innerGap = 210.0'), 'Środkowa strefa powinna zostawiać bezpieczne miejsce na finał.');
$check(str_contains($release, "count(\$participants) > 128"), 'Generator powinien mieć bezpieczny limit 128 uczestników na arkusz A3.');

$label = BCS_Release_094::participant_label((object)[
    'jersey_number'=>7,
    'child_first_name'=>'Jan',
    'child_last_name'=>'Kowalski',
]);
$check($label === '[#7] Jan Kowalski', 'Uczestnik musi być prezentowany dokładnie jako [#nrkoszulki] Imię Nazwisko.');
$check(BCS_Release_094::participant_label(null) === 'WOLNY LOS', 'Pusty slot powinien być opisany jako WOLNY LOS.');

$powers = [2=>2,3=>4,4=>4,5=>8,8=>8,9=>16,23=>32,32=>32,33=>64,64=>64,65=>128,128=>128];
foreach ($powers as $count => $expected) {
    $actual = BCS_Release_094::next_power_of_two($count);
    $check($actual === $expected, "Dla {$count} uczestników oczekiwano drabinki {$expected}, otrzymano {$actual}.");
}

$participants23 = [];
for ($i = 1; $i <= 23; $i++) {
    $participants23[] = (object)[
        'id'=>$i,
        'jersey_number'=>$i,
        'child_first_name'=>'Gracz'.$i,
        'child_last_name'=>'Test'.$i,
    ];
}
$slots = BCS_Release_094::first_round_slots($participants23);
$check(count($slots) === 32, '23 uczestników powinno utworzyć 32-slotową drabinkę.');
$nulls = count(array_filter($slots, static fn($v): bool => $v === null));
$check($nulls === 9, 'Dla 23 uczestników powinno być dokładnie 9 wolnych losów.');
for ($i = 0; $i < count($slots); $i += 2) {
    $check(!($slots[$i] === null && $slots[$i+1] === null), 'Nie może powstać mecz WOLNY LOS kontra WOLNY LOS.');
}

$camp = (object)[
    'name'=>'Basketmania Camp Test',
    'start_date'=>'2026-07-05',
    'end_date'=>'2026-07-11',
    'location'=>'Pelplin',
];
$svg = BCS_Release_094::build_bracket_svg($participants23, $camp);
$check(str_starts_with($svg, '<svg'), 'Drabinka powinna być budowana jako wektorowy SVG.');
$check(str_contains($svg, 'DRABINKA PUCHAROWA'), 'SVG powinien zawierać tytuł drabinki.');
$check(str_contains($svg, 'FINAŁ'), 'Dwie strony drabinki powinny dochodzić do finału.');
$check(str_contains($svg, 'ZWYCIĘZCA'), 'Drabinka powinna zawierać pole na zwycięzcę.');
$check(str_contains($svg, 'WOLNY LOS'), 'Niepełna drabinka powinna wizualizować wolne losy.');
foreach ($participants23 as $participant) {
    $expectedLabel = '[#'.$participant->jersey_number.'] '.$participant->child_first_name.' '.$participant->child_last_name;
    $check(substr_count($svg, $expectedLabel) === 1, 'Każdy opłacony uczestnik powinien pojawić się w drabince dokładnie raz: '.$expectedLabel);
}

// Realny render Dompdf, jeśli zależności są dostępne w CI.
$autoload = $root.'/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
    try {
        $dataUri = 'data:image/svg+xml;base64,'.base64_encode($svg);
        $html = '<!doctype html><html><head><style>@page{size:A3 landscape;margin:6mm}html,body{margin:0}img{width:100%;height:auto}</style></head><body><img src="'.$dataUri.'"></body></html>';
        $options = new Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf\Dompdf($options);
        $pdf->setPaper('A3', 'landscape');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        $bytes = $pdf->output();
        $check(str_starts_with($bytes, '%PDF'), 'Realny render drabinki powinien zwrócić plik PDF.');
        $check(strlen($bytes) > 5000, 'Wygenerowany PDF drabinki nie powinien być pusty.');
    } catch (Throwable $e) {
        $check(false, 'Realny render A3 przez Dompdf nie powiódł się: '.$e->getMessage());
    }
}

if ($failures) {
    fwrite(STDERR, "Release 0.94 tournament bracket A3 test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.94 tournament bracket A3 checks passed.\n";
