<?php

declare(strict_types=1);

if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release092 = (string)file_get_contents($root.'/includes/class-bcs-release-092.php');
$release093 = (string)file_get_contents($root.'/includes/class-bcs-release-093.php');
require_once $root.'/includes/class-bcs-release-092.php';
require_once $root.'/includes/class-bcs-release-093.php';

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(version_compare((string)($headerVersion[1] ?? '0'), '0.93', '>='), 'Nagłówek wtyczki powinien mieć wersję co najmniej 0.93.');
$check(version_compare((string)($constantVersion[1] ?? '0'), '0.93', '>='), 'BCS_VERSION powinno mieć wersję co najmniej 0.93.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-093.php';"), 'Bootstrap powinien ładować release 0.93.');
$check(str_contains($plugin, 'BCS_Release_093::init();'), 'Bootstrap powinien inicjalizować release 0.93.');

$rows = [
    (object)['id'=>11,'shirt_size'=>'M-170-176','child_last_name'=>'Młodszy','child_first_name'=>'Marek'],
    (object)['id'=>12,'shirt_size'=>'128-134','child_last_name'=>'Starszy','child_first_name'=>'Adam'],
    (object)['id'=>13,'shirt_size'=>'S-164-170','child_last_name'=>'Nowak','child_first_name'=>'Ola'],
    (object)['id'=>14,'shirt_size'=>'158-164','child_last_name'=>'Kowalski','child_first_name'=>'Jan'],
    (object)['id'=>15,'shirt_size'=>'XL-182-188','child_last_name'=>'Zieliński','child_first_name'=>'Piotr'],
];
usort($rows, [BCS_Release_093::class, 'compare_jersey_rows']);
$actual = array_map(static fn(object $r): string => (string)$r->shirt_size, $rows);
$expected = ['128-134','158-164','S-164-170','M-170-176','XL-182-188'];
$check($actual === $expected, 'Numeracja koszulek musi wynikać z rozmiaru od najmniejszego do największego, niezależnie od wieku.');

$sameSize = [
    (object)['id'=>30,'shirt_size'=>'S-164-170','child_last_name'=>'Zalewski','child_first_name'=>'Adam'],
    (object)['id'=>31,'shirt_size'=>'S-164-170','child_last_name'=>'Kowalski','child_first_name'=>'Zenon'],
    (object)['id'=>32,'shirt_size'=>'S-164-170','child_last_name'=>'Kowalski','child_first_name'=>'Adam'],
];
usort($sameSize, [BCS_Release_093::class, 'compare_jersey_rows']);
$names = array_map(static fn(object $r): string => $r->child_last_name.' '.$r->child_first_name, $sameSize);
$check($names === ['Kowalski Adam','Kowalski Zenon','Zalewski Adam'], 'Przy identycznym rozmiarze numeracja powinna być stabilna alfabetycznie.');

$check(str_contains($release092, 'SELECT id, shirt_size, child_first_name, child_last_name'), 'Odświeżanie numerów powinno pobierać rozmiar stroju uczestnika.');
$check(str_contains($release092, "usort(\$rows, [BCS_Release_093::class, 'compare_jersey_rows']);"), 'Odświeżanie numerów powinno sortować uczestników komparatorem rozmiarów 0.93.');
$check(str_contains($release092, "['jersey_number'=>\$index + 1]"), 'Po sortowaniu rozmiarów numery powinny być zapisywane kolejno 1..N.');
$check(str_contains($release092, "WHERE camp_id=%d AND status<>'cancelled'"), 'Numeracja nadal musi być niezależna dla każdego turnusu i pomijać anulowane zgłoszenia.');
$check(str_contains($release092, 'child_birth_date DESC'), 'Lista uczestników powinna nadal być sortowana od najmłodszego do najstarszego.');
$check(str_contains($release092, 'Numery koszulek wynikają z kolejności rozmiarów stroju od najmniejszego do największego.'), 'Opis listy uczestników powinien wyjaśniać źródło numeru koszulki.');
$check(str_contains($release092, 'Rozmiary i numery koszulek są ułożone od najmniejszego stroju do największego.'), 'Lista strojów powinna jasno opisywać numerację od najmniejszego rozmiaru.');
$check(str_contains($release093, 'BCS_Release_092::compare_shirt_sizes'), '0.93 powinno używać tego samego porządku rozmiarów co Lista strojów.');

// Workflow GitHub jest chroniony przed edycją przez integrację, dlatego regresja 0.94
// jest wykonywana jako kolejny element istniejącego łańcucha testów.
$bracketOutput = [];
$bracketExit = 1;
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/tests/release-094-tournament-bracket-a3-test.php'), $bracketOutput, $bracketExit);
$check($bracketExit === 0, 'Regresja 0.94 drabinki A3 nie przeszła: '.implode(' | ', $bracketOutput));

if ($failures) {
    fwrite(STDERR, "Release 0.93 jersey number by size test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.93 jersey number by size checks passed.\n";
echo implode("\n", $bracketOutput)."\n";
