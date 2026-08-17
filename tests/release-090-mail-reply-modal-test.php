<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');
$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root.'/basketmania-camp-system.php');
$release = (string)file_get_contents($root.'/includes/class-bcs-release-090.php');
$js = (string)file_get_contents($root.'/assets/js/mail-reply-090.js');
$workflow = (string)file_get_contents($root.'/.github/workflows/php-lint.yml');

$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/\* Version:\s*([0-9.]+)/', $plugin, $headerVersion);
preg_match("/define\('BCS_VERSION',\s*'([^']+)'\)/", $plugin, $constantVersion);
$check(version_compare((string)($headerVersion[1] ?? '0'), '0.90', '>='), 'Nagłówek wtyczki powinien mieć wersję co najmniej 0.90.');
$check(version_compare((string)($constantVersion[1] ?? '0'), '0.90', '>='), 'BCS_VERSION powinno mieć wersję co najmniej 0.90.');
$check(str_contains($plugin, "require_once BCS_DIR . 'includes/class-bcs-release-090.php';"), 'Bootstrap powinien ładować release 0.90.');
$check(str_contains($plugin, 'BCS_Release_090::init();'), 'Bootstrap powinien inicjalizować release 0.90.');

$check(str_contains($release, "add_action('admin_post_'.self::ACTION, [__CLASS__, 'handle_reply']);"), 'Odpowiedź powinna mieć osobny bezpieczny handler admin-post.');
$check(str_contains($release, "direction='inbound'"), 'Do odpowiedzi powinny być wybierane tylko wiadomości przychodzące.');
$check(str_contains($release, "wp_create_nonce('bcs_parent_mail_reply_090_'"), 'Każda odpowiedź powinna mieć nonce powiązany z wiadomością.');
$check(str_contains($release, 'Odpowiedz rodzicowi'), 'Modal powinien jasno wskazywać odpowiedź do rodzica.');
$check(str_contains($release, 'Treść odpowiedzi'), 'Modal powinien zawierać pole treści odpowiedzi.');
$check(str_contains($release, 'Wyślij odpowiedź'), 'Modal powinien mieć przycisk wysyłki odpowiedzi.');
$check(str_contains($release, 'data-bcs-mail-reply-recipient-090'), 'Modal powinien pokazywać adresata.');
$check(str_contains($release, 'data-bcs-mail-reply-context-090'), 'Modal powinien pokazywać kontekst wiadomości, na którą odpowiadamy.');

$check(str_contains($release, "WHERE id=%d AND direction='inbound'"), 'Backend nie może pozwalać odpowiadać tym handlerem na wiadomość wychodzącą.');
$check(str_contains($release, "'In-Reply-To: '.\$messageId"), 'Odpowiedź powinna zawierać nagłówek In-Reply-To.');
$check(str_contains($release, "'References: '.\$refs"), 'Odpowiedź powinna zawierać nagłówek References.');
$check(str_contains($release, 'BCS_Mailer::send($to, $subject, $htmlBody, $headers, [], $registrationId)'), 'Wysyłka powinna korzystać z istniejącego BCS_Mailer i zachować registration_id.');
$check(str_contains($release, "['is_read'=>1]"), 'Odpowiedź powinna oznaczyć źródłową wiadomość jako przeczytaną.');
$check(str_contains($release, "BCS_Utils::log('mailbox_reply_090'"), 'Wysłanie odpowiedzi powinno zostać zapisane w logach zgłoszenia.');
$check(str_contains($release, 'BCS_Mailer::last_error()'), 'Błąd wysyłki powinien być pokazany administratorowi.');

$check(str_contains($js, ".bcs-mail-thread-item.is-inbound"), 'Przycisk Odpowiedz powinien być wiązany wyłącznie z wiadomościami przychodzącymi.');
$check(str_contains($js, '.bcs-mail-preview'), '0.90 powinno reagować na Otwórz wiadomość w Karcie Zgłoszenia.');
$check(str_contains($js, 'bcs-mail-reply-open-090'), 'W podglądzie powinien pojawić się przycisk Odpowiedz.');
$check(str_contains($js, 'bcs-mail-reply-modal-090'), 'Kliknięcie Odpowiedz powinno otwierać drugi modal.');
$check(str_contains($js, 'replyForm.elements.message_id.value'), 'Modal powinien wysyłać identyfikator konkretnej wiadomości.');
$check(str_contains($js, 'replyForm.elements._wpnonce.value'), 'Modal powinien ustawiać nonce konkretnej wiadomości.');
$check(str_contains($js, 'replyForm.elements.subject.value'), 'Temat odpowiedzi powinien być wypełniany automatycznie.');
$check(str_contains($js, "currentMessage=article?.classList.contains('is-inbound')"), 'Wiadomość wychodząca nie może otrzymać aktywnego przycisku odpowiedzi do rodzica.');

$check(str_contains($workflow, 'Release 0.90 mail reply modal test'), 'CI powinno uruchamiać test 0.90.');
$check(str_contains($workflow, 'php tests/release-090-mail-reply-modal-test.php'), 'CI powinno uruchamiać właściwy test 0.90.');
$check(str_contains($workflow, 'node --check assets/js/mail-reply-090.js'), 'CI powinno sprawdzać składnię JS modala odpowiedzi.');

if ($failures) {
    fwrite(STDERR, "Release 0.90 mail reply modal test FAILED:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

echo "Release 0.90 mail reply modal checks passed.\n";
