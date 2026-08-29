<?php

declare(strict_types=1);

if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-092.php');
$js = (string)file_get_contents($root.'/assets/js/shirt-size-suggestion-092.js');
require_once $root.'/includes/class-bcs-release-092.php';

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(version_compare((string)($headerVersion[1] ?? '0'), '0.92', '>='), 'Nagłówek wtyczki powinien mieć wersję co najmniej 0.92.');
$check(version_compare((string)($constantVersion[1] ?? '0'), '0.92', '>='), 'BCS_VERSION powinno mieć wersję co najmniej 0.92.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-092.php';"), 'Bootstrap powinien ładować release 0.92.');
$check(str_contains($plugin, 'BCS_Camp_Reports::init();') && str_contains($plugin, 'BCS_Release_092::init();'), '0.92 powinno inicjalizować się po module raportów turnusu.');

$check(str_contains($release, 'ADD COLUMN jersey_number SMALLINT UNSIGNED NULL AFTER shirt_size'), 'Migracja 0.92 powinna dodać numer koszulki do zgłoszenia.');
$check(str_contains($release, 'ADD KEY camp_jersey_number (camp_id, jersey_number)'), 'Numer koszulki powinien być indeksowany razem z turnusem.');
$check(str_contains($release, "WHERE camp_id=%d AND status<>'cancelled'"), 'Numeracja powinna dotyczyć aktywnych uczestników konkretnego turnusu.');
$check(str_contains($release, "SET jersey_number=NULL WHERE camp_id=%d"), 'Przeliczenie powinno czyścić stare numery wyłącznie w danym turnusie.');
$check(str_contains($release, "['jersey_number'=>\$index + 1]"), 'Numery koszulek powinny być zapisywane kolejno 1..N.');
$check(str_contains($release, 'child_birth_date DESC'), 'Lista uczestników powinna pozostać posortowana od najmłodszego do najstarszego.');
$check(str_contains($release, "SELECT id, shirt_size, child_first_name, child_last_name"), 'Kanoniczna numeracja koszulek powinna korzystać z rozmiaru stroju.');
$check(str_contains($release, "remove_action('admin_post_bcs_camp_shirts_pdf'"), '0.92 powinno przejąć generowanie listy strojów.');
$check(str_contains($release, "remove_action('admin_post_bcs_camp_participants_pdf'"), '0.92 powinno przejąć generowanie listy uczestników.');
$check(substr_count($release, 'refresh_jersey_numbers($campId)') >= 2, 'Obie listy powinny automatycznie aktualizować numery koszulek.');
$check(str_contains($release, '<th>Nr koszulki</th>'), 'Lista uczestników i strojów powinny pokazywać rzeczywisty numer koszulki.');
$check(str_contains($release, 'Lista posortowana od najmłodszego do najstarszego uczestnika.'), 'Opis listy uczestników powinien informować o sortowaniu wieku.');

$cases = [
    100=>'128-134',
    128=>'128-134',
    133=>'128-134',
    134=>'134-140',
    139=>'134-140',
    140=>'140-146',
    158=>'158-164',
    163=>'158-164',
    164=>'S-164-170',
    169=>'S-164-170',
    170=>'M-170-176',
    176=>'L-176-182',
    182=>'XL-182-188',
    188=>'2XL-188-194',
    194=>'3XL-194-200',
    200=>'3XL-194-200',
    230=>'3XL-194-200',
];
foreach ($cases as $height => $expected) {
    $actual = BCS_Release_092::suggest_shirt_size((int)$height);
    $check($actual === $expected, "Wzrost {$height} cm powinien sugerować {$expected}, otrzymano {$actual}.");
}

$boundaryCases = [134=>'134-140',140=>'140-146',146=>'146-152',152=>'152-158',158=>'158-164',164=>'S-164-170',170=>'M-170-176',176=>'L-176-182',182=>'XL-182-188',188=>'2XL-188-194',194=>'3XL-194-200'];
foreach ($boundaryCases as $height => $expected) {
    $check(BCS_Release_092::suggest_shirt_size($height) === $expected, "Górna granica {$height} cm powinna przejść o jeden rozmiar wyżej: {$expected}.");
}

$sizes = ['XL-182-188','158-164','M-170-176','128-134','3XL-194-200','S-164-170','134-140','2XL-188-194','L-176-182'];
usort($sizes, [BCS_Release_092::class, 'compare_shirt_sizes']);
$expectedOrder = ['128-134','134-140','158-164','S-164-170','M-170-176','L-176-182','XL-182-188','2XL-188-194','3XL-194-200'];
$check($sizes === $expectedOrder, 'Rozmiary powinny być sortowane: liczbowe rosnąco, a następnie S, M, L, XL, 2XL, 3XL.');

$check(str_contains($js, 'input[name="child_height"]'), 'Skrypt sugestii powinien reagować na wzrost uczestnika.');
$check(str_contains($js, 'select[name="shirt_size"]'), 'Skrypt powinien ustawiać pole rozmiaru stroju.');
$check(str_contains($js, 'value >= bounds.min && value < bounds.max'), 'JS powinien traktować dolną granicę jako należącą do rozmiaru, a górną jako przejście do następnego.');
$check(str_contains($js, 'Sugerowany rozmiar'), 'Formularz powinien pokazywać rodzicowi czytelną sugestię rozmiaru.');
$check(str_contains($js, "form.querySelectorAll('[data-bcs-shirt-hint-092]')"), 'Formularz powinien utrzymywać tylko jedną podpowiedź rozmiaru.');
$check(str_contains($js, 'if (item !== hint) item.remove()'), 'Powielone lub nieaktualne podpowiedzi powinny być usuwane.');
$check(str_contains($js, "current === '' || current === previousAutomatic"), 'Automatyczna sugestia nie powinna nadpisywać ręcznie wybranego rozmiaru.');

$nodeOutput = [];
$nodeExit = 1;
exec('node --check '.escapeshellarg($root.'/assets/js/shirt-size-suggestion-092.js').' 2>&1', $nodeOutput, $nodeExit);
$check($nodeExit === 0, 'Składnia JavaScript 0.92 jest nieprawidłowa: '.implode(' | ', $nodeOutput));

$nextOutput = [];
$nextExit = 1;
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/tests/release-093-jersey-number-by-size-test.php'), $nextOutput, $nextExit);
$check($nextExit === 0, 'Regresja 0.93 numeracji koszulek nie przeszła: '.implode(' | ', $nextOutput));

if ($failures) {
    fwrite(STDERR, "Release 0.92 camp lists/shirt sizing test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.92 camp lists/shirt sizing checks passed.\n";
echo implode("\n", $nextOutput)."\n";
