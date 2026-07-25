<?php

define('ABSPATH', __DIR__);

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string {
        return strip_tags($text);
    }
}

require_once dirname(__DIR__).'/includes/class-bcs-release-051.php';

function call_private(string $method, mixed ...$args): mixed {
    $reflection = new ReflectionMethod(BCS_Release_051::class, $method);
    $reflection->setAccessible(true);
    return $reflection->invoke(null, ...$args);
}

$proofOnly = '<div class="proof"><h2>Sekcja dowodowa zawarcia umowy</h2>'
    .str_repeat('<p>Potwierdzenie Organizatora oraz Rodzica kodem SMS.</p>', 8)
    .'</div>';

if (call_private('is_full_agreement', $proofOnly) !== false) {
    fwrite(STDERR, "Sekcja dowodowa została błędnie uznana za pełną umowę.\n");
    exit(1);
}

$sections = '';
for ($i = 1; $i <= 6; $i++) {
    $sections .= '<h2>§'.$i.' PRZEDMIOT UMOWY I OBOWIĄZKI ORGANIZATORA</h2><ol>';
    for ($j = 1; $j <= 4; $j++) {
        $sections .= '<li>'.str_repeat('Pełna treść postanowienia umowy uczestnictwa. ', 6).'</li>';
    }
    $sections .= '</ol>';
}
$fullAgreement = '<div class="bcs-agreement"><h1>UMOWA UDZIAŁU W OBOZIE</h1>'
    .$sections
    .'<h2>POSTANOWIENIA KOŃCOWE</h2></div>';

if (call_private('is_full_agreement', $fullAgreement) !== true) {
    fwrite(STDERR, "Pełna umowa nie przeszła walidacji.\n");
    exit(1);
}

$wrapped = '<style>.bad{display:block}</style>'
    .'<div class="bcs-document-051">'
    .'<div class="bcs-document-header"><img src="logo.png"></div>'
    .'<div class="bcs-document-content">'.$fullAgreement.$proofOnly.'</div>'
    .'<div class="bcs-document-footer">Stopka</div>'
    .'</div>';
$clean = call_private('strip_old_document_shell', $wrapped);

if (!str_contains($clean, 'UMOWA UDZIAŁU W OBOZIE')) {
    fwrite(STDERR, "Oczyszczanie usunęło treść umowy.\n");
    exit(1);
}
if (str_contains($clean, 'Sekcja dowodowa') || str_contains($clean, '.bad')) {
    fwrite(STDERR, "Oczyszczanie pozostawiło sekcję dowodową albo CSS.\n");
    exit(1);
}

echo "Agreement renderer 0.51 regression tests passed.\n";
