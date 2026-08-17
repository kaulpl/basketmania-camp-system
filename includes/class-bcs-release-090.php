<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.90 – odpowiedź na wiadomość rodzica bezpośrednio z Karty Zgłoszenia.
 *
 * Po otwarciu odebranej wiadomości w sekcji Korespondencja e-mail pojawia się
 * przycisk „Odpowiedz”. Otwiera on osobny modal z adresatem, tematem i treścią.
 * Odpowiedź jest wysyłana przez istniejący BCS_Mailer, zapisywana w historii
 * korespondencji zgłoszenia oraz otrzymuje nagłówki In-Reply-To/References,
 * dzięki czemu klient pocztowy może utrzymać ją w tym samym wątku.
 */
final class BCS_Release_090 {
    private const ACTION = 'bcs_reply_parent_mail_090';

    public static function init(): void {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 1000);
        add_action('admin_footer', [__CLASS__, 'render_reply_modal'], 9999);
        add_action('admin_notices', [__CLASS__, 'reply_notice']);
        add_action('admin_post_'.self::ACTION, [__CLASS__, 'handle_reply']);
    }

    private static function is_registration_card(): bool {
        return is_admin()
            && current_user_can('manage_options')
            && sanitize_key(wp_unslash($_GET['page'] ?? '')) === 'bcs-registrations'
            && absint($_GET['view'] ?? 0) > 0;
    }

    private static function reply_subject(string $subject): string {
        $subject = trim($subject);
        if ($subject === '') return 'Re: Wiadomość od rodzica';
        return preg_match('/^Re\s*:/iu', $subject) ? $subject : 'Re: '.$subject;
    }

    private static function inbound_messages(int $registrationId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,sender_email,sender_name,subject,body_text,received_at FROM ".BCS_DB::table('mail_messages')." WHERE registration_id=%d AND direction='inbound' ORDER BY received_at ASC,id ASC",
            $registrationId
        ));
        $items = [];
        foreach ((array)$rows as $row) {
            $mid = (int)$row->id;
            $email = sanitize_email((string)$row->sender_email);
            if ($mid <= 0 || !is_email($email)) continue;
            $preview = trim((string)$row->body_text);
            if ($preview === '') $preview = 'Odebrana wiadomość e-mail.';
            $preview = wp_trim_words($preview, 22, '…');
            $items[] = [
                'id' => $mid,
                'email' => $email,
                'name' => sanitize_text_field((string)$row->sender_name),
                'subject' => sanitize_text_field((string)$row->subject),
                'replySubject' => self::reply_subject((string)$row->subject),
                'preview' => $preview,
                'receivedAt' => (string)$row->received_at,
                'nonce' => wp_create_nonce('bcs_parent_mail_reply_090_'.$mid),
            ];
        }
        return $items;
    }

    public static function enqueue_assets(): void {
        if (!self::is_registration_card()) return;
        $registrationId = absint($_GET['view'] ?? 0);
        wp_enqueue_script(
            'bcs-mail-reply-090',
            BCS_URL.'assets/js/mail-reply-090.js',
            [],
            BCS_VERSION,
            true
        );
        wp_localize_script('bcs-mail-reply-090', 'BCSMailReply090', [
            'messages' => self::inbound_messages($registrationId),
            'actionUrl' => admin_url('admin-post.php'),
            'action' => self::ACTION,
        ]);
    }

    public static function render_reply_modal(): void {
        if (!self::is_registration_card()) return;
        ?>
        <style>
            .bcs-mail-preview-reply-actions-090{display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0}
            .bcs-mail-reply-dialog-090{max-width:720px}
            .bcs-mail-reply-form-090{display:grid;gap:16px}
            .bcs-mail-reply-recipient-090{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid #dbe3ef;border-radius:10px;background:#f8fafc}
            .bcs-mail-reply-recipient-090>span{font-size:12px;font-weight:700;text-transform:uppercase;color:#64748b}
            .bcs-mail-reply-recipient-090 strong{color:#172033}
            .bcs-mail-reply-form-090 label{display:grid;gap:7px}
            .bcs-mail-reply-form-090 label>span{font-weight:700;color:#172033}
            .bcs-mail-reply-form-090 input,.bcs-mail-reply-form-090 textarea{width:100%;box-sizing:border-box}
            .bcs-mail-reply-context-090{padding:12px 14px;border-left:3px solid #3b82f6;background:#eff6ff;border-radius:8px;color:#334155}
            .bcs-mail-reply-context-090 strong{display:block;margin-bottom:4px;color:#1e3a8a}
            .bcs-mail-reply-buttons-090{display:flex;justify-content:flex-end;gap:10px}
        </style>
        <div id="bcs-mail-reply-modal-090" class="bcs-contact-modal" hidden>
            <div class="bcs-contact-modal__dialog bcs-mail-reply-dialog-090" role="dialog" aria-modal="true" aria-labelledby="bcs-mail-reply-title-090">
                <button type="button" class="bcs-contact-modal__close bcs-mail-reply-close-090" aria-label="Zamknij">×</button>
                <div class="bcs-panel-head">
                    <div><h2 id="bcs-mail-reply-title-090">Odpowiedz rodzicowi</h2><p>Odpowiedź zostanie wysłana przez konto skonfigurowane w Ustawienia → E-MAIL.</p></div>
                    <span class="dashicons dashicons-email-alt"></span>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="bcs-mail-reply-form-090">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                    <input type="hidden" name="message_id" value="">
                    <input type="hidden" name="_wpnonce" value="">
                    <div class="bcs-mail-reply-recipient-090"><span>Do</span><strong data-bcs-mail-reply-recipient-090>—</strong></div>
                    <div class="bcs-mail-reply-context-090"><strong>Odpowiadasz na:</strong><span data-bcs-mail-reply-context-090>—</span></div>
                    <label><span>Temat</span><input type="text" name="subject" value="" required></label>
                    <label><span>Treść odpowiedzi</span><textarea name="body" rows="10" placeholder="Wpisz odpowiedź do rodzica…" required></textarea></label>
                    <div class="bcs-mail-reply-buttons-090">
                        <button type="button" class="button bcs-mail-reply-cancel-090">Anuluj</button>
                        <button type="submit" class="button button-primary"><span class="dashicons dashicons-email-alt"></span> Wyślij odpowiedź</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    public static function handle_reply(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.', 403);
        $mid = absint($_POST['message_id'] ?? 0);
        if ($mid <= 0) wp_die('Brak identyfikatora wiadomości.', 400);
        check_admin_referer('bcs_parent_mail_reply_090_'.$mid);

        global $wpdb;
        $m = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM ".BCS_DB::table('mail_messages')." WHERE id=%d AND direction='inbound' LIMIT 1",
            $mid
        ));
        if (!$m) wp_die('Nie znaleziono wiadomości przychodzącej.', 404);

        $registrationId = (int)($m->registration_id ?? 0);
        if ($registrationId <= 0) wp_die('Wiadomość nie jest przypisana do zgłoszenia.', 409);
        $to = sanitize_email((string)$m->sender_email);
        if (!is_email($to)) wp_die('Wiadomość nie ma poprawnego adresu nadawcy.', 422);

        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        if ($subject === '') $subject = self::reply_subject((string)$m->subject);
        $bodyText = trim(sanitize_textarea_field(wp_unslash($_POST['body'] ?? '')));
        if ($bodyText === '') self::redirect_result($registrationId, false, 'Wpisz treść odpowiedzi.');

        $headers = [];
        $messageId = trim((string)($m->message_id ?? ''));
        if ($messageId !== '') {
            $headers[] = 'In-Reply-To: '.$messageId;
            $refs = trim((string)($m->references_header ?? ''));
            if ($refs === '') $refs = trim((string)($m->in_reply_to ?? ''));
            $refs = trim($refs.' '.$messageId);
            $headers[] = 'References: '.$refs;
        }

        $htmlBody = nl2br(esc_html($bodyText));
        $ok = BCS_Mailer::send($to, $subject, $htmlBody, $headers, [], $registrationId);
        $wpdb->update(BCS_DB::table('mail_messages'), ['is_read'=>1], ['id'=>$mid]);

        BCS_Utils::log('mailbox_reply_090', [
            'message_id'=>$mid,
            'to'=>$to,
            'success'=>$ok,
            'threaded'=>$messageId !== '',
            'error'=>$ok ? '' : BCS_Mailer::last_error(),
        ], $registrationId, null);

        self::redirect_result(
            $registrationId,
            $ok,
            $ok ? 'Odpowiedź została wysłana do rodzica.' : ('Nie udało się wysłać odpowiedzi. '.BCS_Mailer::last_error())
        );
    }

    private static function redirect_result(int $registrationId, bool $ok, string $message): void {
        set_transient('bcs_mail_reply_090_'.get_current_user_id().'_'.$registrationId, [
            'ok'=>$ok,
            'message'=>$message,
        ], 90);
        $url = add_query_arg([
            'page'=>'bcs-registrations',
            'view'=>$registrationId,
            'mail_reply_090'=>$ok ? 1 : 0,
        ], admin_url('admin.php'));
        wp_safe_redirect($url.'#bcs-mail-correspondence');
        exit;
    }

    public static function reply_notice(): void {
        if (!self::is_registration_card() || !isset($_GET['mail_reply_090'])) return;
        $registrationId = absint($_GET['view'] ?? 0);
        $key = 'bcs_mail_reply_090_'.get_current_user_id().'_'.$registrationId;
        $notice = get_transient($key);
        delete_transient($key);
        if (!is_array($notice)) return;
        $class = !empty($notice['ok']) ? 'notice-success' : 'notice-error';
        echo '<div class="notice '.esc_attr($class).' is-dismissible"><p><strong>'.esc_html((string)($notice['message'] ?? '')).'</strong></p></div>';
    }
}
