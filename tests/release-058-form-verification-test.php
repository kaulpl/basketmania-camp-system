<?php

declare(strict_types=1);

$fail = static function (string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root.'/basketmania-camp-system.php');
$release = file_get_contents($root.'/includes/class-bcs-release-058.php');
$legacy = file_get_contents($root.'/includes/class-bcs-release-0200.php');

$headerVersion = '';
$constantVersion = '';
if (is_string($bootstrap)) {
    if (preg_match('/\* Version:\s*([0-9.]+)/', $bootstrap, $match)) $headerVersion = $match[1];
    if (preg_match("/define\('BCS_VERSION',\s*'([0-9.]+)'\)/", $bootstrap, $match)) $constantVersion = $match[1];
}
if (version_compare($headerVersion, '0.58', '<')
    || version_compare($constantVersion, '0.58', '<')
    || $headerVersion !== $constantVersion
    || !str_contains((string)$bootstrap, 'class-bcs-release-058.php')
    || !str_contains((string)$bootstrap, 'BCS_Release_058::init();')) {
    $fail('Release 0.58 is not correctly loaded and preserved.');
}

if (!is_string($legacy)
    || !str_contains($legacy, 'body:new FormData(form)')
    || !str_contains($legacy, '.bcs-form-verification form')) {
    $fail('The regression cause in the legacy intercepted card form is no longer covered by this test.');
}

if (!is_string($release)
    || !str_contains($release, "add_action('admin_footer', [__CLASS__, 'admin_footer'], 5)")
    || !str_contains($release, 'input[name="bcs_crm_action"]')
    || !str_contains($release, "action.value = 'verify_form'")
    || !str_contains($release, 'bcs-form-verification-inline-058')) {
    $fail('The 0.58 compatibility layer for card verification is missing.');
}

foreach ([
    'bcs_058_form_preview',
    'bcs-form-review-modal-058',
    'bcs-form-review-open-058',
    "quick_action:'verify_form'",
    'Potwierdź poprawność formularza',
    'Zdrowie, żywienie i szczepienia',
    'Dane do faktury',
    'Osoby upoważnione do odbioru',
] as $needle) {
    if (!str_contains($release, $needle)) {
        $fail('Missing 0.58 form-review element: '.$needle);
    }
}

if (!str_contains($release, "button.type = 'button'")
    || !str_contains($release, 'event.stopImmediatePropagation()')
    || !str_contains($release, 'applyRowState(current.row, data)')) {
    $fail('The list verification button is not converted to a popup review flow with AJAX row refresh.');
}

echo "Release 0.58 form verification regression checks passed.\n";

// Exercise the actual database-row -> popup mapping, not only source strings.
define('ABSPATH', __DIR__.'/');
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
final class BCS_DB {
    public static function table(string $name): string { return 'wp_bcs_'.$name; }
}
require_once $root.'/includes/class-bcs-release-058.php';
require_once $root.'/includes/class-bcs-release-060.php';
$stored = (object)[
    'id'=>58, 'parent_first_name'=>'Anna', 'parent_last_name'=>'<Testowa>',
    'parent_email'=>'anna@example.test', 'parent_phone'=>'48500111222',
    'second_parent_first_name'=>'Jan', 'second_parent_last_name'=>'Testowy',
    'second_parent_email'=>'jan@example.test', 'second_parent_phone'=>'48500333444',
    'parent_phone_alt'=>'48500999888', 'sole_guardian'=>0,
    'child_first_name'=>'Adam', 'child_last_name'=>'Testowy', 'start_date'=>'', 'end_date'=>'',
];
$wpdb = new class($stored) {
    public string $query = '';
    public function __construct(public object $stored) {}
    public function prepare(string $query, int $id): string { return str_replace('%d', (string)$id, $query); }
    public function get_row(string $query): object { $this->query=$query; return clone $this->stored; }
};
$read = new ReflectionMethod(BCS_Release_058::class, 'row');
$row = $read->invoke(null, 58);
if (!str_contains($wpdb->query, 'SELECT r.*') || !str_contains($wpdb->query, 'WHERE r.id=58 LIMIT 1')) $fail('Popup must fetch all columns for the requested registration.');
$popupSections = new ReflectionMethod(BCS_Release_058::class, 'sections');
$cardSections = new ReflectionMethod(BCS_Release_060::class, 'display_sections');
foreach ([$popupSections, $cardSections] as $method) {
    $sections=$method->invoke(null, $row);
    $first=array_column($sections['Rodzic / opiekun prawny 1'], 1, 0);
    $second=array_column($sections['Rodzic / opiekun prawny 2'], 1, 0);
    if ($first['Imię i nazwisko']!=='Anna <Testowa>' || $first['E-mail']!==$stored->parent_email || $first['Telefon']!==$stored->parent_phone) $fail('First parent fields mixed or missing.');
    if ($second['Imię i nazwisko']!=='Jan Testowy' || $second['E-mail']!==$stored->second_parent_email || $second['Telefon']!==$stored->second_parent_phone) $fail('Second parent fields mixed or missing.');
    $sole=clone $row; $sole->sole_guardian=1;
    $soleSection=$method->invoke(null, $sole)['Rodzic / opiekun prawny 2'];
    if (count($soleSection)!==1 || !str_contains($soleSection[0][1], 'samodzielnie')) $fail('Sole guardian must show declaration, not stale second parent data.');
    $legacy=clone $row;
    unset($legacy->second_parent_first_name,$legacy->second_parent_last_name,$legacy->second_parent_email,$legacy->second_parent_phone,$legacy->sole_guardian);
    $legacySections=$method->invoke(null, $legacy);
    if (array_column($legacySections['Rodzic / opiekun prawny 2'],1,0)['Telefon']!=='') $fail('Legacy alternate phone must not be attributed to second parent.');
}
$preview=new ReflectionMethod(BCS_Release_058::class, 'preview_html');
$html=$preview->invoke(null, $row);
if (!str_contains($html, '&lt;Testowa&gt;') || str_contains($html, '<Testowa>') || !str_contains($html, $stored->second_parent_phone)) $fail('Popup must escape stored data and include second phone.');
$wpdb->stored->second_parent_phone='48500666777';
if (!str_contains($preview->invoke(null, $read->invoke(null,58)), '48500666777')) $fail('Popup must reflect freshly read database values.');
echo "Parent review database mapping checks passed.\n";
