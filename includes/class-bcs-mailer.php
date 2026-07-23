<?php
if (!defined('ABSPATH')) exit;

class BCS_Mailer {
    private static string $last_error = '';
    private static array $last_success = [];
    private static array $last_result = [];
    private static string $current_from_name = '';
    private static string $current_from_email = '';
    private static string $current_message_id = '';

    public static function init(): void {
        add_action('wp_mail_failed', [__CLASS__, 'capture_failure']);
        add_action('wp_mail_succeeded', [__CLASS__, 'capture_success']);
        add_action('phpmailer_init', [__CLASS__, 'configure_phpmailer'], 999);
    }

    public static function capture_failure($error): void {
        if (is_wp_error($error)) {
            self::$last_error = $error->get_error_message();
            update_option('bcs_last_mail_transport_error', [
                'message'=>self::$last_error,
                'data'=>$error->get_error_data(),
                'time'=>BCS_Utils::now(),
            ], false);
        }
    }

    public static function capture_success($mail_data): void {
        self::$last_success = is_array($mail_data) ? $mail_data : [];
    }

    public static function filter_from_name(string $name): string {
        return self::$current_from_name !== '' ? self::$current_from_name : $name;
    }

    public static function filter_from_email(string $email): string {
        return is_email(self::$current_from_email) ? self::$current_from_email : $email;
    }

    public static function configuration_error(?array $settings = null): string {
        $s = is_array($settings) ? $settings : get_option('bcs_settings', []);
        if (($s['mail_transport'] ?? 'wordpress') !== 'smtp') return '';
        $host = trim((string)($s['smtp_host'] ?? ''));
        $port = absint($s['smtp_port'] ?? 587);
        $auth = !empty($s['smtp_auth']);
        $username = trim((string)($s['smtp_username'] ?? ''));
        $password = (string)($s['smtp_password'] ?? '');
        $from = sanitize_email((string)($s['mail_from_email'] ?? ''));
        if ($host === '') return 'Brak serwera SMTP.';
        if ($port < 1 || $port > 65535) return 'Nieprawidłowy port SMTP.';
        if ($auth && $username === '') return 'Brak loginu SMTP.';
        if ($auth && $password === '') return 'Brak hasła SMTP.';
        if (!is_email($from)) return 'Brak poprawnego adresu nadawcy.';
        return '';
    }

    public static function configure_phpmailer($phpmailer): void {
        $s = get_option('bcs_settings', []);
        if (($s['mail_transport'] ?? 'wordpress') !== 'smtp') return;
        if (self::configuration_error($s) !== '') return;

        $phpmailer->isSMTP();
        $phpmailer->Host = trim((string)$s['smtp_host']);
        $phpmailer->Port = absint($s['smtp_port'] ?? 587);
        $phpmailer->SMTPAuth = !empty($s['smtp_auth']);
        if ($phpmailer->SMTPAuth) {
            $phpmailer->Username = trim((string)($s['smtp_username'] ?? ''));
            $phpmailer->Password = (string)($s['smtp_password'] ?? '');
        }
        $phpmailer->Timeout = 25;
        $phpmailer->SMTPKeepAlive = false;
        $phpmailer->CharSet = 'UTF-8';
        $encryption = in_array(($s['smtp_encryption'] ?? 'tls'), ['none','ssl','tls'], true) ? (string)$s['smtp_encryption'] : 'tls';
        if ($encryption === 'none') {
            $phpmailer->SMTPSecure = '';
            $phpmailer->SMTPAutoTLS = false;
        } else {
            $phpmailer->SMTPSecure = $encryption;
            $phpmailer->SMTPAutoTLS = true;
        }
        // Wymuszamy nadawcę zapisany w ustawieniach także na poziomie PHPMailer.
        $from_email = sanitize_email((string)($s['mail_from_email'] ?? ''));
        $from_name = sanitize_text_field((string)($s['mail_from_name'] ?? 'Basketmania Camp'));
        if (is_email($from_email)) {
            try { $phpmailer->setFrom($from_email, $from_name, false); } catch (Throwable $e) {}
            $phpmailer->Sender = $from_email;
        }
        // Ustawiamy Message-ID przez API PHPMailer, zamiast dodawać drugi nagłówek ręcznie.
        // Dzięki temu wiadomość zawiera dokładnie jeden nagłówek Message-ID zgodny z RFC 5322.
        if (self::$current_message_id !== '') {
            $phpmailer->MessageID = self::$current_message_id;
        }
    }

    public static function transport_label(?array $settings = null): string {
        $s = is_array($settings) ? $settings : get_option('bcs_settings', []);
        return (($s['mail_transport'] ?? 'wordpress') === 'smtp') ? 'Zewnętrzna skrzynka SMTP' : 'WordPress / poczta hostingu';
    }

    public static function subject_prefix(): string {
        return 'Basketmania Camp:';
    }

    public static function prefix_subject(string $subject): string {
        $subject = trim(wp_strip_all_tags($subject));
        $prefix = self::subject_prefix();
        if ($subject === '') return $prefix;
        if (stripos($subject, $prefix) === 0) return $subject;
        if (preg_match('/^(Re|Fwd|FW):\s*(.*)$/iu', $subject, $m)) {
            $rest = trim((string)$m[2]);
            if (stripos($rest, $prefix) === 0) return $m[1].': '.$rest;
            return $m[1].': '.$prefix.' '.$rest;
        }
        return $prefix.' '.$subject;
    }


    public static function email_heading(string $subject): string {
        $subject = trim(wp_strip_all_tags($subject));
        $prefix = preg_quote(self::subject_prefix(), '/');
        $subject = preg_replace('/^(Re|Fwd|FW):\s*/iu', '', $subject) ?? $subject;
        $subject = preg_replace('/^'.$prefix.'\s*/iu', '', $subject) ?? $subject;
        return $subject !== '' ? $subject : 'Wiadomość od Basketmania Camp';
    }

    public static function wrap_html_email(string $subject, string $content): string {
        if (stripos($content, 'data-bcs-email-layout="1"') !== false) return $content;

        $settings = get_option('bcs_settings', []);
        $heading = self::email_heading($subject);
        $logo_url = BCS_URL . 'assets/images/logo-basketmania-camp-white.png';
        $website_url = 'https://camp.basketmania.pl/';
        $contact_email = sanitize_email((string)($settings['mail_reply_to'] ?? $settings['company_email'] ?? get_option('admin_email')));
        $organizer_name = sanitize_text_field((string)($settings['company_name'] ?? 'Basketmania Camp'));
        $organizer_address = sanitize_text_field((string)($settings['company_address'] ?? ''));
        $year = wp_date('Y', BCS_Utils::timestamp());

        // Szablony są edytowane w edytorze WordPressa, który zapisuje część
        // odstępów jako puste linie. W HTML wiadomości same znaki nowej linii
        // są zwijane, dlatego przed sanitacją odtwarzamy akapity i przełamania.
        // Dotyczy to wspólnego wrappera, więc zachowuje formatowanie wszystkich
        // szablonów oraz wiadomości tworzonych ręcznie w systemie.
        $content = wp_kses_post(wpautop($content));
        $address_html = $organizer_address !== '' ? '<br>'.esc_html($organizer_address) : '';

        return '<!doctype html><html lang="pl" xmlns="http://www.w3.org/1999/xhtml"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>'.esc_html($heading).'</title><style>'
            .'body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}table,td{mso-table-lspace:0;mso-table-rspace:0}img{-ms-interpolation-mode:bicubic;border:0;display:block;height:auto;line-height:100%;outline:none;text-decoration:none}table{border-collapse:collapse!important}body{width:100%!important;height:100%!important;margin:0!important;padding:0!important;background:#f3f4f6}a{color:#f57618}.bcs-mail-content p{margin:0 0 18px}.bcs-mail-content ul,.bcs-mail-content ol{margin:0 0 18px;padding-left:24px}.bcs-mail-content h1,.bcs-mail-content h2,.bcs-mail-content h3{color:#17191d;line-height:1.25}.bcs-mail-content a{color:#e9650c;font-weight:700}.bcs-mail-content img{max-width:100%;height:auto}.mobile-padding{padding-left:46px;padding-right:46px}@media only screen and (max-width:640px){.email-container{width:100%!important;max-width:100%!important}.mobile-padding{padding-left:24px!important;padding-right:24px!important}.main-card{border-radius:0!important}.email-title{font-size:28px!important;line-height:36px!important}.footer-link{display:block!important;margin:8px 0!important}.separator{display:none!important}}'
            .'</style></head><body data-bcs-email-layout="1" style="margin:0;padding:0;background-color:#f3f4f6">'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background-color:#f3f4f6">'
            .'<tr><td align="center" style="padding:34px 16px 30px;background-color:#f57618;background-image:linear-gradient(135deg,#ff9b33 0%,#f57618 100%)"><table role="presentation" width="620" cellspacing="0" cellpadding="0" border="0" class="email-container" style="width:620px;max-width:620px"><tr><td align="center"><img src="'.esc_url($logo_url).'" width="230" alt="Basketmania Camp" style="display:block;width:230px;max-width:80%;height:auto;margin:0 auto"></td></tr></table></td></tr>'
            .'<tr><td align="center" bgcolor="#f3f4f6" style="padding:0 16px 0;background-color:#f3f4f6;background-image:linear-gradient(to bottom,#f57618 0,#f57618 64px,#f3f4f6 64px,#f3f4f6 100%)"><table role="presentation" width="620" cellspacing="0" cellpadding="0" border="0" class="email-container main-card" style="width:620px;max-width:620px;background-color:#fff;border-radius:18px;box-shadow:0 14px 40px rgba(26,31,41,.10);overflow:hidden"><tr><td style="height:6px;background-color:#f57618;background-image:linear-gradient(90deg,#f57618 0%,#ffac47 100%);font-size:0;line-height:0">&nbsp;</td></tr>'
            .'<tr><td align="center" class="mobile-padding" style="padding-top:46px;padding-bottom:32px"><div style="margin-bottom:13px;color:#f57618;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;line-height:18px;letter-spacing:1.6px;text-transform:uppercase">BASKETMANIA CAMP</div><h1 class="email-title" style="margin:0;color:#17191d;font-family:Arial,Helvetica,sans-serif;font-size:36px;font-weight:800;line-height:44px;text-align:center">'.esc_html($heading).'</h1><div style="width:52px;height:4px;margin:22px auto 0;background-color:#f57618;border-radius:10px;font-size:0;line-height:0">&nbsp;</div></td></tr>'
            .'<tr><td class="mobile-padding bcs-mail-content" style="padding-top:24px;padding-bottom:16px;color:#51545a;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:26px">'.$content.'</td></tr>'
            .'<tr><td class="mobile-padding" style="padding-top:14px;padding-bottom:44px;color:#51545a;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:26px">Z pozdrowieniami,<br><strong style="color:#17191d">Zespół Basketmania Camp</strong></td></tr></table></td></tr>'
            .'<tr><td align="center" style="padding:24px 16px 0"><table role="presentation" width="620" cellspacing="0" cellpadding="0" border="0" class="email-container" style="width:620px;max-width:620px;background-color:#fff1df;border-radius:14px"><tr><td align="center" style="padding:27px 28px;color:#31343a;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:23px"><strong style="display:block;margin-bottom:5px;color:#17191d;font-size:17px">Potrzebujesz pomocy?</strong>Odpowiedz na tę wiadomość'.(is_email($contact_email)?' lub napisz do nas: <a href="mailto:'.esc_attr($contact_email).'" style="color:#e9650c;font-weight:700;text-decoration:underline">'.esc_html($contact_email).'</a>':'.').'</td></tr></table></td></tr>'
            .'<tr><td align="center" style="padding:32px 16px 42px"><table role="presentation" width="620" cellspacing="0" cellpadding="0" border="0" class="email-container" style="width:620px;max-width:620px"><tr><td align="center" style="padding-bottom:18px;color:#797d85;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:21px">Wiadomość została wysłana przez system obsługi <strong style="color:#4a4d53">Basketmania Camp</strong>.</td></tr><tr><td align="center" style="padding-bottom:18px;color:#797d85;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:21px"><a class="footer-link" href="'.esc_url($website_url).'" style="color:#51545a;font-weight:700;text-decoration:none">Strona internetowa</a><span class="separator" style="color:#b2b4b8">&nbsp;&nbsp;•&nbsp;&nbsp;</span>'.(is_email($contact_email)?'<a class="footer-link" href="mailto:'.esc_attr($contact_email).'" style="color:#51545a;font-weight:700;text-decoration:none">Kontakt</a>':'').'</td></tr><tr><td align="center" style="color:#9a9da3;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:19px">'.esc_html($organizer_name).$address_html.'<br><br>© '.esc_html($year).' Basketmania Camp.<br>Wszystkie prawa zastrzeżone.</td></tr></table></td></tr></table></body></html>';
    }

    public static function send(string $to, string $subject, string $body, array $headers = [], array $attachments = [], int $registration_id = 0): bool {
        self::$last_error = '';
        self::$last_success = [];
        self::$last_result = [];
        $s = get_option('bcs_settings', []);
        $original_body = $body;
        $subject = self::prefix_subject($subject);
        $to = sanitize_email($to);
        if (!is_email($to)) {
            self::$last_error = 'Nieprawidłowy adres e-mail odbiorcy.';
            return self::finish(false, $s, $to, $subject, $registration_id, '', $body, $original_body);
        }
        $config_error = self::configuration_error($s);
        if ($config_error !== '') {
            self::$last_error = 'Konfiguracja zewnętrznej skrzynki SMTP jest niepełna: '.$config_error;
            return self::finish(false, $s, $to, $subject, $registration_id, '', $body, $original_body);
        }

        $body = self::wrap_html_email($subject, $body);

        $reply_to = sanitize_email((string)($s['mail_reply_to'] ?? $s['company_email'] ?? get_option('admin_email')));
        self::$current_from_name = sanitize_text_field((string)($s['mail_from_name'] ?? $s['company_name'] ?? 'Basketmania Camp'));
        self::$current_from_email = sanitize_email((string)($s['mail_from_email'] ?? $s['company_email'] ?? ''));
        add_filter('wp_mail_from_name', [__CLASS__, 'filter_from_name'], 999);
        if (is_email(self::$current_from_email)) add_filter('wp_mail_from', [__CLASS__, 'filter_from_email'], 999);

        $has_type = $has_reply = false;
        $clean_headers = [];
        foreach ($headers as $header) {
            $lower = strtolower(trim((string)$header));
            if (str_starts_with($lower, 'from:')) continue;
            if (str_starts_with($lower, 'content-type:')) $has_type = true;
            if (str_starts_with($lower, 'reply-to:')) $has_reply = true;
            $clean_headers[] = $header;
        }
        if (!$has_type) $clean_headers[] = 'Content-Type: text/html; charset=UTF-8';
        if (!$has_reply && is_email($reply_to)) $clean_headers[] = 'Reply-To: ' . $reply_to;

        $message_id = '';
        if (class_exists('BCS_Mailbox')) {
            if (!$registration_id) {
                global $wpdb;
                $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM ".BCS_DB::table('registrations')." WHERE parent_email=%s AND status<>'cancelled' ORDER BY created_at DESC LIMIT 2", $to));
                if (count($ids) === 1) $registration_id = (int)$ids[0];
            }
            if ($registration_id) {
                $message_id = BCS_Mailbox::message_id_for($registration_id);
                self::$current_message_id = $message_id;
                $clean_headers[] = 'X-BCS-Registration-ID: ' . $registration_id;
            }
        }

        $ok = wp_mail($to, $subject, $body, $clean_headers, $attachments);
        remove_filter('wp_mail_from_name', [__CLASS__, 'filter_from_name'], 999);
        remove_filter('wp_mail_from', [__CLASS__, 'filter_from_email'], 999);
        self::$current_from_name = '';
        self::$current_from_email = '';
        self::$current_message_id = '';
        if (!$ok && self::$last_error === '') self::$last_error = 'WordPress nie przekazał wiadomości do mechanizmu pocztowego.';
        return self::finish((bool)$ok, $s, $to, $subject, $registration_id, $message_id, $body, $original_body);
    }

    private static function finish(bool $ok, array $s, string $to, string $subject, int $registration_id, string $message_id, string $body, string $original_body = ''): bool {
        if (class_exists('BCS_Mailbox')) BCS_Mailbox::record_outgoing($to, $subject, $body, $ok, $registration_id, $message_id);
        self::$last_result = [
            'success'=>$ok,
            'accepted_by_wordpress'=>$ok,
            'to'=>$to,
            'subject'=>sanitize_text_field($subject),
            'error'=>self::$last_error,
            'transport'=>self::transport_label($s),
            'from_email'=>sanitize_email((string)($s['mail_from_email'] ?? $s['company_email'] ?? '')),
            'reply_to'=>sanitize_email((string)($s['mail_reply_to'] ?? $s['company_email'] ?? get_option('admin_email'))),
            'registration_id'=>$registration_id,
            'message_id'=>$message_id,
            'time'=>BCS_Utils::now(),
        ];
        update_option('bcs_last_mail_result', self::$last_result, false);
        if ($registration_id) BCS_Utils::log('email_send_result', self::$last_result, $registration_id, null);
        if (!$ok) {
            BCS_Utils::log('communication_email_error', [
                'to'=>$to,
                'subject'=>$subject,
                'body'=>$original_body !== '' ? $original_body : $body,
                'error'=>self::$last_error !== '' ? self::$last_error : 'Nieznany błąd wysyłki e-mail.',
                'transport'=>self::transport_label($s),
                'from_email'=>sanitize_email((string)($s['mail_from_email'] ?? $s['company_email'] ?? '')),
                'reply_to'=>sanitize_email((string)($s['mail_reply_to'] ?? $s['company_email'] ?? get_option('admin_email'))),
                'message_id'=>$message_id,
            ], $registration_id ?: null, null);
        }
        return $ok;
    }

    public static function last_error(): string { return self::$last_error; }
    public static function last_result(): array { return self::$last_result; }
}
