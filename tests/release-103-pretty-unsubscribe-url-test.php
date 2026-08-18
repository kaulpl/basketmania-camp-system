<?php

declare(strict_types=1);

if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string { return 'https://zapisy.basketmania.pl'.$path; }
}

$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$r096 = (string)file_get_contents($root.'/includes/class-bcs-release-096.php');
$r103 = (string)file_get_contents($root.'/includes/class-bcs-release-103.php');
require_once $root.'/includes/class-bcs-release-103.php';

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(($headerVersion[1] ?? '') === ($constantVersion[1] ?? ''), 'Nagłówek i BCS_VERSION powinny być zgodne.');
$check(version_compare((string)($headerVersion[1] ?? '0'), '1.03', '>='), 'Wtyczka powinna mieć wersję co najmniej 1.03.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-103.php';"), 'Bootstrap powinien ładować release 1.03.');
$check(str_contains($plugin, 'BCS_Release_103::init();'), 'Bootstrap powinien inicjalizować release 1.03.');

$token = str_repeat('a', 64);
$pretty = BCS_Release_103::pretty_unsubscribe_url($token);
$expected = 'https://zapisy.basketmania.pl/mailing/wypisz/'.$token.'/';
$check($pretty === $expected, 'Publiczny link wypisania powinien używać /mailing/wypisz/{token}/.');
$check(!str_contains($pretty, '/wp-admin/'), 'Nowy link nie może prowadzić do wp-admin.');
$check(!str_contains($pretty, 'action='), 'Nowy link nie może zawierać parametru action.');
$check(!str_contains($pretty, 'token='), 'Nowy link nie może zawierać tokenu jako query string.');
$check(BCS_Release_103::pretty_unsubscribe_url('test') === '', 'Nieprawidłowy krótki token powinien zostać odrzucony.');

$legacyRaw = 'https://zapisy.basketmania.pl/wp-admin/admin-post.php?action=bcs_marketing_unsubscribe_096&token='.$token;
$legacyHtml = 'https://zapisy.basketmania.pl/wp-admin/admin-post.php?action=bcs_marketing_unsubscribe_096&amp;token='.$token;
$mail = [
    'to'=>'test@example.com',
    'subject'=>'Test',
    'message'=>'<a href="'.$legacyHtml.'">Wypisz się</a>',
    'headers'=>[
        'Content-Type: text/html; charset=UTF-8',
        'List-Unsubscribe: <'.$legacyRaw.'>',
        'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
    ],
];
$out = BCS_Release_103::rewrite_outgoing_unsubscribe_links($mail);
$check(str_contains((string)$out['message'], $expected), 'Treść maila powinna dostać publiczny link wypisania.');
$check(!str_contains((string)$out['message'], 'admin-post.php'), 'Treść maila nie może zawierać starego admin-post URL.');
$headers = implode("\n", (array)$out['headers']);
$check(str_contains($headers, 'List-Unsubscribe: <'.$expected.'>'), 'Nagłówek List-Unsubscribe powinien używać publicznego URL.');
$check(str_contains($headers, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'), 'One-Click Unsubscribe musi pozostać aktywny.');

$check(str_contains($r103, "add_action('parse_request'"), 'Publiczna ścieżka powinna być obsługiwana bez wp-admin i bez reguł wymagających flush rewrite.');
$check(str_contains($r103, "BCS_Release_096::handle_unsubscribe();"), 'Nowa ścieżka powinna korzystać z istniejącej, sprawdzonej logiki wypisania.');
$check(str_contains($r096, "admin_post_nopriv_'.self::UNSUBSCRIBE_ACTION"), 'Stary publiczny admin-post endpoint powinien pozostać dla zgodności z już wysłanymi mailami.');

if ($failures) {
    fwrite(STDERR, "Release 1.03 pretty unsubscribe URL test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 1.03 pretty unsubscribe URL checks passed.\n";

// CI ma już krok dla 1.03; uruchamiamy w nim również regresję 1.04 bez
// ingerowania w strukturę istniejącego workflow.
require __DIR__.'/release-104-mailing-throttle-worker-test.php';
