<?php

declare(strict_types=1);

if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release094 = (string)file_get_contents($root.'/includes/class-bcs-release-094.php');
$release095 = (string)file_get_contents($root.'/includes/class-bcs-release-095.php');
require_once $root.'/includes/class-bcs-release-094.php';
require_once $root.'/includes/class-bcs-release-095.php';

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === '0.95', 'Nagłówek wtyczki powinien mieć wersję 0.95.');
$check(($constantVersion[1] ?? '') === '0.95', 'BCS_VERSION powinno mieć wersję 0.95.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-095.php';"), 'Bootstrap powinien ładować release 0.95.');
$check(str_contains($plugin, 'BCS_Release_095::init();'), 'Bootstrap powinien inicjalizować release 0.95.');

$check(str_contains($release095, "remove_action('admin_post_'.self::ACTION, [BCS_Release_094::class, 'bracket_pdf'])"), '0.95 powinno zastąpić renderer PDF z 0.94 bez zmiany przycisku generatora.');
$check(str_contains($release095, "add_action('admin_post_'.self::ACTION, [__CLASS__, 'bracket_pdf'], 20)"), '0.95 powinno obsługiwać istniejącą akcję generatora drabinki.');
$check(!str_contains($release095, 'Losowanie automatyczne · uczestnicy zarejestrowani i opłaceni:'), 'Nowy PDF nie powinien zawierać opisu losowania/liczby uczestników.');
$check(!str_contains($release095, 'Format wydruku: A3 poziomo'), 'Nowy PDF nie powinien zawierać technicznego opisu formatu wydruku.');
$check(str_contains($release095, '<?xml version="1.0" encoding="UTF-8"?>'), 'SVG kontrolny powinien jawnie deklarować UTF-8.');
$check(str_contains($release095, 'font-family:"DejaVu Sans"'), 'SVG kontrolny powinien jawnie używać DejaVu Sans.');
$check(str_contains($release095, 'fit_participant_label'), 'Etykiety uczestników powinny mieć mechanizm dopasowania do pola.');
$check(str_contains($release095, 'wrap_words'), 'Długie etykiety powinny móc przechodzić do drugiej linii.');
$check(str_contains($release095, 'render_pdf_bytes'), 'Produkcja PDF powinna korzystać z osobnego bezpośredniego renderera.');
$check(str_contains($release095, '$canvas->text('), 'Tekst powinien być rysowany bezpośrednio na canvasie PDF, a nie jako tekst wewnątrz SVG.');
$check(str_contains($release095, 'getTextWidth('), 'Dopasowanie tekstu powinno mierzyć rzeczywistą szerokość fontem Dompdf.');
$check(str_contains($release095, "getFont('DejaVu Sans', 'normal')"), 'Bezpośredni renderer powinien pobierać prawdziwy font DejaVu Sans.');
$check(str_contains($release095, "setPaper('A3', 'landscape')"), 'PDF nadal musi być A3 poziomo.');
$check(str_contains($release094, 'Generuj drabinkę'), 'Przycisk Generuj drabinkę z 0.94 powinien pozostać aktywny.');

$polish = 'Zażółć gęślą jaźń ŁŚŹŻĆŃÓĘĄ';
$encoded = BCS_Release_095::xml_text($polish);
$check(!str_contains($encoded, '?'), 'Kodowanie kontrolnego SVG nie może zamieniać polskich znaków na znak zapytania.');
$check(str_contains($encoded, '&#x17C;'), 'Ż/ż powinno być kodowane jako encja Unicode XML.');
$check(str_contains($encoded, '&#x142;'), 'ł powinno być kodowane jako encja Unicode XML.');
$check(str_contains($encoded, '&#x15B;') || str_contains($encoded, '&#x15A;'), 'ś/Ś powinno być kodowane jako encja Unicode XML.');
$check(str_contains($encoded, '&#x17A;') || str_contains($encoded, '&#x179;'), 'ź/Ź powinno być kodowane jako encja Unicode XML.');

$metrics60 = BCS_Release_095::layout_metrics(60);
$check((int)$metrics60['bracket_size'] === 64, '60 uczestników powinno korzystać z drabinki 64-slotowej.');
$check((int)$metrics60['side_slots'] === 32, 'Przy 60 uczestnikach powinny być 32 pola startowe na każdej stronie.');
$check((float)$metrics60['box_width'] >= 68.0, 'Przy 60 uczestnikach pole startowe powinno być wystarczająco szerokie na etykietę dwuliniową.');
$check((float)$metrics60['max_first_box_height'] < (float)$metrics60['vertical_step'], 'Pola startowe przy 60 uczestnikach nie mogą nachodzić na siebie pionowo.');
$check((float)$metrics60['preferred_font'] <= 6.2 && (float)$metrics60['preferred_font'] >= 5.5, 'Przy około 60 uczestnikach czcionka powinna być automatycznie zmniejszona do czytelnego zakresu A3.');

$longParticipant = (object)[
    'jersey_number'=>59,
    'child_first_name'=>'Aleksandra',
    'child_last_name'=>'Świętopełk-Łączyńska',
];
$fit = BCS_Release_095::fit_participant_label(
    $longParticipant,
    (float)$metrics60['box_width'],
    (float)$metrics60['preferred_font']
);
$check(count($fit['lines']) === 2, 'Długa etykieta powinna zostać przeniesiona do dwóch linii.');
$check(implode(' ', $fit['lines']) === '[#59] Aleksandra Świętopełk-Łączyńska', 'Zawijanie nie może usuwać numeru, imienia ani nazwiska.');
$check((float)$fit['font_size'] >= 4.8, 'Przy 60 uczestnikach długa polska etykieta powinna pozostać czytelna, bez skrajnie małej czcionki.');

$firstNames = ['Łukasz','Świętosław','Żaneta','Michał','Małgorzata','Błażej','Wojciech','Aleksandra','Grzegorz','Przemysław'];
$lastNames = ['Ździebłowski','Świątek','Łączyński-Kowalski','Żółkiewski','Górzyński','Dąbrowska','Król','Wróblewska','Szymański','Piątkowski'];
$participants60 = [];
for ($i = 1; $i <= 60; $i++) {
    $participants60[] = (object)[
        'id'=>$i,
        'jersey_number'=>$i,
        'child_first_name'=>$firstNames[$i % count($firstNames)],
        'child_last_name'=>$lastNames[($i * 3) % count($lastNames)],
    ];
}
$camp = (object)[
    'name'=>'Basketmania Camp - Łódź Śląska',
    'start_date'=>'2026-07-05',
    'end_date'=>'2026-07-11',
    'location'=>'Pelplin',
];
$svg = BCS_Release_095::build_bracket_svg($participants60, $camp);
$decodedSvg = html_entity_decode($svg, ENT_QUOTES | ENT_XML1, 'UTF-8');
$check(str_starts_with($svg, '<?xml version="1.0" encoding="UTF-8"?><svg'), 'Drabinka kontrolna 0.95 powinna być prawidłowym SVG UTF-8.');
$check(!str_contains($decodedSvg, 'Losowanie automatyczne'), 'W podglądzie 60-osobowym nie może być technicznego opisu losowania.');
$check(!str_contains($decodedSvg, 'Format wydruku'), 'W podglądzie 60-osobowym nie może być technicznego opisu A3.');
$check(str_contains($decodedSvg, 'Łódź Śląska'), 'Nazwa turnusu z polskimi znakami powinna przetrwać generowanie SVG kontrolnego.');
$check(str_contains($decodedSvg, 'FINAŁ'), 'Polskie Ł w nagłówku FINAŁ musi być poprawne w SVG kontrolnym.');
$check(str_contains($decodedSvg, 'ZWYCIĘZCA'), 'Polskie Ę w ZWYCIĘZCA musi być poprawne w SVG kontrolnym.');
foreach ($participants60 as $participant) {
    $numberToken = '[#'.$participant->jersey_number.']';
    $check(substr_count($decodedSvg, $numberToken) === 1, 'Każdy numer koszulki powinien pojawić się dokładnie raz: '.$numberToken);
    $check(str_contains($decodedSvg, $participant->child_first_name), 'Imię uczestnika nie może zniknąć podczas zawijania: '.$participant->child_first_name);
    $check(str_contains($decodedSvg, $participant->child_last_name), 'Nazwisko uczestnika nie może zniknąć podczas zawijania: '.$participant->child_last_name);
}

$svgPath = getenv('BCS_TEST_BRACKET_SVG_PATH') ?: '';
if ($svgPath !== '') file_put_contents($svgPath, $svg);

$autoload = $root.'/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
    try {
        $bytes = BCS_Release_095::render_pdf_bytes($participants60, $camp);
        $check(str_starts_with($bytes, '%PDF'), 'Bezpośredni render 60-osobowej drabinki powinien zwrócić PDF.');
        $check(strlen($bytes) > 7000, 'Realny PDF 60-osobowej drabinki nie powinien być pusty.');
        $pdfPath = getenv('BCS_TEST_BRACKET_PDF_PATH') ?: '';
        if ($pdfPath !== '') file_put_contents($pdfPath, $bytes);
    } catch (Throwable $e) {
        $check(false, 'Bezpośredni render 60-osobowej drabinki A3 przez Dompdf nie powiódł się: '.$e->getMessage());
    }
}

if ($failures) {
    fwrite(STDERR, "Release 0.95 bracket layout/UTF-8 test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.95 bracket layout/UTF-8 checks passed.\n";
