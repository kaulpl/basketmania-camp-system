<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-089.php');
$workflow = (string)file_get_contents($root.'/.github/workflows/php-lint.yml');
require_once $root.'/includes/class-bcs-release-089.php';

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$header = (string)($headerVersion[1] ?? '');
$constant = (string)($constantVersion[1] ?? '');
$check($header !== '' && $header === $constant, 'Nagłówek wtyczki i BCS_VERSION powinny być zgodne.');
$check($header !== '' && version_compare($header, '0.89', '>='), 'Test 0.89 wymaga wersji 0.89 lub nowszej.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-089.php';"), 'Bootstrap powinien ładować release 0.89.');
$check(str_contains($plugin, 'BCS_Release_089::init();'), 'Bootstrap powinien inicjalizować release 0.89.');

$gmail = "Dzień dobry,\nTak, potwierdzam że Olivia będzie na obozie.\nPozdrawiam\nAnna\n\nW dniu 17.08.2026 o 10:00 Basketmania Camp <camp@example.com> napisał(a):\n> Poprzednia wiadomość organizatora\n> Prosimy o odpowiedź.";
$gmailClean = BCS_Release_089::extract_latest_reply_text($gmail);
$check(str_contains($gmailClean, 'Olivia będzie na obozie'), 'Parser Gmail powinien zachować nową odpowiedź rodzica.');
$check(!str_contains($gmailClean, 'Poprzednia wiadomość organizatora'), 'Parser Gmail powinien usunąć cytowaną historię.');

$outlook = "Dzień dobry,\nProszę zmienić rozmiar koszulki na M.\n\nOd: Basketmania Camp <camp@example.com>\nWysłano: poniedziałek, 17 sierpnia 2026 09:00\nDo: Anna Nowak <anna@example.com>\nTemat: Formularz obozowy\n\nStara treść wiadomości";
$outlookClean = BCS_Release_089::extract_latest_reply_text($outlook);
$check(str_contains($outlookClean, 'rozmiar koszulki na M'), 'Parser Outlook powinien zachować tekst przed blokiem Od/Wysłano.');
$check(!str_contains($outlookClean, 'Stara treść wiadomości'), 'Parser Outlook powinien usunąć starszą wiadomość.');

$html = '<div>Dzień dobry,<br>Wpłata została wykonana dzisiaj.<br>Pozdrawiam</div><blockquote><div>Stara wiadomość Basketmania Camp</div></blockquote>';
$htmlClean = BCS_Release_089::extract_latest_reply_from_html($html);
$check(str_contains($htmlClean, 'Wpłata została wykonana dzisiaj'), 'Parser HTML powinien zachować właściwą odpowiedź.');
$check(!str_contains($htmlClean, 'Stara wiadomość Basketmania Camp'), 'Parser HTML powinien usunąć blockquote.');

$check(str_contains($release, "remove_action('bcs_mailbox_sync_event', ['BCS_Mailbox', 'sync']);"), '0.89 powinno przejąć cron synchronizacji IMAP.');
$check(str_contains($release, "add_action('bcs_mailbox_sync_event', [__CLASS__, 'sync']);"), '0.89 powinno podpiąć nowy parser do crona.');
$check(str_contains($release, "add_action('admin_init', [__CLASS__, 'handle_manual_sync'], 1);"), 'Ręczna synchronizacja powinna używać parsera 0.89 przed starym handlerem.');
$check(str_contains($release, 'part_is_attachment'), 'Parser MIME powinien odrzucać załączniki.');
$check(str_contains($release, 'part_charset'), 'Parser MIME powinien odczytywać charset części wiadomości.');
$check(str_contains($release, "'body_text'=>\$body['text']"), 'Nowe wiadomości powinny zapisywać oczyszczoną treść tekstową.');
$check(str_contains($release, "'body_html'=>\$body['html']"), 'Nowe wiadomości powinny zapisywać oczyszczoną treść HTML.');
$check(str_contains($release, 'repair_unread_from_imap'), '0.89 powinno ponownie pobierać istniejące nieprzeczytane wiadomości po UID.');
$check(str_contains($release, 'imap_msgno($imap, (int)$row->mailbox_uid)'), 'Naprawa istniejących wiadomości powinna używać UID IMAP.');

$check(str_contains($release, "direction='inbound' AND is_read=0 AND registration_id IS NOT NULL"), 'Marker nowej korespondencji powinien bazować tylko na nieprzeczytanych wiadomościach przychodzących przypisanych do zgłoszenia.');
$check(str_contains($release, 'Nowa korespondencja'), 'UI powinien pokazywać tekst Nowa korespondencja.');
$check(str_contains($release, 'dashicons-email-alt'), 'Marker powinien używać ikony e-mail.');
$check(str_contains($release, '#e7f1ff') && str_contains($release, '#0a58ca'), 'Marker powinien mieć niebieską kolorystykę.');
$check(str_contains($release, "document.querySelectorAll('tr[data-id]')"), 'Marker powinien być dodawany do listy zgłoszeń.');
$check(str_contains($release, "document.querySelector('.bcs-page-head h1')"), 'Marker powinien być dodawany do Karty Zgłoszenia.');
$check(str_contains($release, 'Korespondencja e-mail'), 'Karta powinna rozpoznawać sekcję korespondencji.');
$check(str_contains($release, 'bcs_mark_registration_mail_read_089'), 'Otwarcie wiadomości z Karty powinno umożliwiać oznaczenie korespondencji jako przeczytanej.');
$check(str_contains($release, "SET is_read=1 WHERE registration_id=%d AND direction='inbound' AND is_read=0"), 'AJAX powinien oznaczać przychodzącą korespondencję danego zgłoszenia jako przeczytaną.');
$check(str_contains($release, "sanitize_key(wp_unslash(\$_GET['page'] ?? '')) !== 'bcs-mailbox'"), 'Otwarcie wiadomości w module Poczta powinno mieć osobną procedurę oznaczania jako przeczytanej.');

$check(str_contains($workflow, 'Release 0.89 inbound mail parser/unread test'), 'CI powinno uruchamiać test 0.89.');
$check(str_contains($workflow, 'php tests/release-089-inbound-mail-parser-unread-test.php'), 'CI powinno uruchamiać właściwy plik testu 0.89.');

if ($failures) {
    fwrite(STDERR, "Release 0.89 inbound mail parser/unread test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.89 inbound mail parser and unread indicators checks passed.\n";
