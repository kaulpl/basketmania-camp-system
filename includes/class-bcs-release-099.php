<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.99 – spokojna wysyłka marketingowa, one-click unsubscribe i diagnostyka dostarczalności.
 */
final class BCS_Release_099 {
    private const PAGE = 'bcs-mailing-deliverability';
    private const SAVE_ACTION = 'bcs_marketing_deliverability_save_099';
    private const QUEUE_HOOK = 'bcs_marketing_queue_097';
    private const NEXT_SEND_OPTION = 'bcs_marketing_next_send_at_099';
    private const FAILURE_OPTION = 'bcs_marketing_consecutive_failures_099';
    private const AUTO_PAUSE_OPTION = 'bcs_marketing_auto_paused_099';

    public static function init(): void {
        // Zastępujemy agresywną kolejkę 0.97 (20 wiadomości na przebieg)
        // kolejką reputacyjną: dokładnie jedna próba wysyłki na dozwolony odstęp.
        remove_action(self::QUEUE_HOOK, [BCS_Release_097::class, 'run_queue']);
        add_action(self::QUEUE_HOOK, [__CLASS__, 'run_queue'], 20);

        add_action('admin_menu', [__CLASS__, 'admin_menu'], 35);
        add_action('admin_post_'.self::SAVE_ACTION, [__CLASS__, 'save_settings']);
    }

    public static function defaults(): array {
        return [
            'daily_limit' => 10,
            'window_start' => 9,
            'window_end' => 19,
            'gap_min_minutes' => 45,
            'gap_max_minutes' => 90,
            'max_consecutive_failures' => 3,
            'marketing_from_name' => 'Basketmania Camp',
            'marketing_from_email' => '',
            'marketing_reply_to' => '',
            'dkim_selector' => '',
        ];
    }

    public static function settings(): array {
        return array_merge(self::defaults(), (array)get_option('bcs_marketing_deliverability_099', []));
    }

    public static function admin_menu(): void {
        add_submenu_page('bcs-dashboard', 'Dostarczalność mailingu', 'Mailing – Dostarczalność', 'manage_options', self::PAGE, [__CLASS__, 'page']);
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) return;
        $s = self::settings();
        $base = (array)get_option('bcs_settings', []);
        $from = sanitize_email((string)($s['marketing_from_email'] ?: ($base['mail_from_email'] ?? $base['company_email'] ?? '')));
        $domain = self::email_domain($from);
        $spf = $domain !== '' ? self::txt_contains($domain, 'v=spf1') : false;
        $dmarc = $domain !== '' ? self::txt_contains('_dmarc.'.$domain, 'v=DMARC1') : false;
        $selector = sanitize_key((string)$s['dkim_selector']);
        $dkim = ($domain !== '' && $selector !== '') ? self::txt_contains($selector.'._domainkey.'.$domain, 'v=DKIM1') : null;
        $today = self::sent_today_count();
        $next = (int)get_option(self::NEXT_SEND_OPTION, 0);
        $paused = (bool)get_option(self::AUTO_PAUSE_OPTION, false);

        echo '<div class="wrap"><h1>Mailing – Dostarczalność</h1>';
        if (!empty($_GET['saved'])) echo '<div class="notice notice-success"><p>Ustawienia dostarczalności zostały zapisane.</p></div>';
        if ($paused) echo '<div class="notice notice-error"><p><strong>Bezpiecznik wysyłki został uruchomiony.</strong> Po kolejnych błędach transportu aktywne kampanie zostały wstrzymane. Sprawdź SMTP/DNS i wznów kampanie ręcznie po usunięciu przyczyny.</p></div>';

        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0">';
        self::stat('Wysłano dzisiaj', $today.' / '.(int)$s['daily_limit']);
        self::stat('Kolejna próba', $next > time() ? wp_date('d.m.Y H:i', $next, wp_timezone()) : 'możliwa teraz');
        self::stat('One-click unsubscribe', 'AKTYWNE');
        self::stat('Domena marketingowa', $domain !== '' ? $domain : 'nie ustawiono');
        echo '</div>';

        echo '<div class="card" style="max-width:980px;padding:22px"><h2>Tempo wysyłki</h2>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="'.esc_attr(self::SAVE_ACTION).'">';
        wp_nonce_field(self::SAVE_ACTION);
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Limit dzienny</th><td><input type="number" min="1" max="500" name="daily_limit" value="'.(int)$s['daily_limit'].'"> wiadomości / dzień<p class="description">Limit jest wspólny dla wszystkich kampanii marketingowych. Domyślnie 10.</p></td></tr>';
        echo '<tr><th>Okno wysyłki</th><td><input type="number" min="0" max="23" name="window_start" value="'.(int)$s['window_start'].'" style="width:70px">:00 – <input type="number" min="0" max="23" name="window_end" value="'.(int)$s['window_end'].'" style="width:70px">:00 <span class="description">według strefy czasowej WordPressa</span></td></tr>';
        echo '<tr><th>Odstęp pomiędzy mailami</th><td><input type="number" min="5" max="1440" name="gap_min_minutes" value="'.(int)$s['gap_min_minutes'].'" style="width:85px"> – <input type="number" min="5" max="1440" name="gap_max_minutes" value="'.(int)$s['gap_max_minutes'].'" style="width:85px"> minut<p class="description">System losuje niewielki jitter w tym zakresie, aby rozłożyć ruch i uniknąć burstów. Nie jest to mechanizm omijania filtrów antyspamowych.</p></td></tr>';
        echo '<tr><th>Bezpiecznik błędów</th><td><input type="number" min="1" max="20" name="max_consecutive_failures" value="'.(int)$s['max_consecutive_failures'].'"> kolejnych błędów<p class="description">Po przekroczeniu progu aktywne kampanie zostają wstrzymane.</p></td></tr>';
        echo '</tbody></table>';

        echo '<h2>Osobny strumień marketingowy</h2><table class="form-table"><tbody>';
        echo '<tr><th>Nazwa nadawcy</th><td><input class="regular-text" name="marketing_from_name" value="'.esc_attr((string)$s['marketing_from_name']).'"></td></tr>';
        echo '<tr><th>Adres marketingowy</th><td><input class="regular-text" type="email" name="marketing_from_email" value="'.esc_attr((string)$s['marketing_from_email']).'" placeholder="newsletter@news.camp.basketmania.pl"><p class="description">Puste pole = użyj zwykłego adresu nadawcy systemu. Docelowo warto stosować osobną subdomenę marketingową.</p></td></tr>';
        echo '<tr><th>Reply-To marketingu</th><td><input class="regular-text" type="email" name="marketing_reply_to" value="'.esc_attr((string)$s['marketing_reply_to']).'" placeholder="kontakt@basketmania.pl"></td></tr>';
        echo '<tr><th>Selektor DKIM</th><td><input class="regular-text" name="dkim_selector" value="'.esc_attr((string)$s['dkim_selector']).'" placeholder="np. default / selector1"><p class="description">Opcjonalnie – służy wyłącznie do diagnostyki rekordu DNS.</p></td></tr>';
        echo '</tbody></table>';
        submit_button('Zapisz ustawienia / odblokuj bezpiecznik');
        echo '</form></div>';

        echo '<div class="card" style="max-width:980px;padding:22px;margin-top:18px"><h2>Kontrola DNS i nagłówków</h2><table class="widefat striped"><tbody>';
        self::diagnostic_row('SPF', $spf, $domain !== '' ? $domain : 'Brak domeny nadawcy');
        self::diagnostic_row('DMARC', $dmarc, $domain !== '' ? '_dmarc.'.$domain : 'Brak domeny nadawcy');
        if ($dkim === null) echo '<tr><td><strong>DKIM</strong></td><td><span style="color:#996800;font-weight:700">NIE SPRAWDZONO</span></td><td>Podaj selektor DKIM powyżej.</td></tr>';
        else self::diagnostic_row('DKIM', (bool)$dkim, $selector.'._domainkey.'.$domain);
        echo '<tr><td><strong>One-click unsubscribe</strong></td><td><span style="color:#06752f;font-weight:700">TAK</span></td><td><code>List-Unsubscribe</code> + <code>List-Unsubscribe-Post</code> są dodawane do każdej właściwej wysyłki kampanii.</td></tr>';
        echo '<tr><td><strong>TLS SMTP</strong></td><td>'.esc_html((string)($base['smtp_encryption'] ?? 'tls')).'</td><td>Transport: '.esc_html(BCS_Mailer::transport_label($base)).'</td></tr>';
        echo '</tbody></table></div></div>';
    }

    private static function stat(string $label, string $value): void {
        echo '<div class="card" style="min-width:180px;padding:15px"><strong style="display:block;font-size:22px">'.esc_html($value).'</strong>'.esc_html($label).'</div>';
    }

    private static function diagnostic_row(string $name, bool $ok, string $detail): void {
        echo '<tr><td><strong>'.esc_html($name).'</strong></td><td>'.($ok ? '<span style="color:#06752f;font-weight:700">OK</span>' : '<span style="color:#b32d2e;font-weight:700">BRAK / NIE WYKRYTO</span>').'</td><td>'.esc_html($detail).'</td></tr>';
    }

    public static function save_settings(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer(self::SAVE_ACTION);
        $min = max(5, min(1440, absint($_POST['gap_min_minutes'] ?? 45)));
        $max = max(5, min(1440, absint($_POST['gap_max_minutes'] ?? 90)));
        if ($max < $min) [$min, $max] = [$max, $min];
        $data = [
            'daily_limit' => max(1, min(500, absint($_POST['daily_limit'] ?? 10))),
            'window_start' => max(0, min(23, absint($_POST['window_start'] ?? 9))),
            'window_end' => max(0, min(23, absint($_POST['window_end'] ?? 19))),
            'gap_min_minutes' => $min,
            'gap_max_minutes' => $max,
            'max_consecutive_failures' => max(1, min(20, absint($_POST['max_consecutive_failures'] ?? 3))),
            'marketing_from_name' => sanitize_text_field(wp_unslash($_POST['marketing_from_name'] ?? 'Basketmania Camp')),
            'marketing_from_email' => sanitize_email(wp_unslash($_POST['marketing_from_email'] ?? '')),
            'marketing_reply_to' => sanitize_email(wp_unslash($_POST['marketing_reply_to'] ?? '')),
            'dkim_selector' => sanitize_key(wp_unslash($_POST['dkim_selector'] ?? '')),
        ];
        update_option('bcs_marketing_deliverability_099', $data, false);
        update_option(self::FAILURE_OPTION, 0, false);
        delete_option(self::AUTO_PAUSE_OPTION);
        wp_safe_redirect(add_query_arg(['page'=>self::PAGE,'saved'=>1], admin_url('admin.php')));
        exit;
    }

    public static function run_queue(): void {
        global $wpdb;
        $s = self::settings();
        $campaigns = BCS_Release_097::campaigns_table();
        $recipients = BCS_Release_097::recipients_table();
        $contacts = BCS_Release_096::contacts_table();
        $now = BCS_Utils::now();
        $nowTs = BCS_Utils::timestamp();

        // Kampanie zaplanowane stają się aktywne dopiero po swoim terminie.
        $wpdb->query($wpdb->prepare("UPDATE {$campaigns} SET status='queued',started_at=COALESCE(started_at,%s),updated_at=%s WHERE status='scheduled' AND scheduled_at IS NOT NULL AND scheduled_at<=%s", $now, $now, $now));

        if (get_option(self::AUTO_PAUSE_OPTION, false)) return;
        if (!self::within_send_window((int)$s['window_start'], (int)$s['window_end'])) return;
        if (self::sent_today_count() >= (int)$s['daily_limit']) return;
        $next = (int)get_option(self::NEXT_SEND_OPTION, 0);
        if ($next > $nowTs) return;

        $row = $wpdb->get_row("SELECT r.*,c.status campaign_status,m.consent_status,m.status contact_status,m.unsubscribe_token,m.email contact_email
            FROM {$recipients} r
            JOIN {$campaigns} c ON c.id=r.campaign_id
            LEFT JOIN {$contacts} m ON m.id=r.contact_id
            WHERE r.status='queued' AND c.status IN ('queued','sending')
            ORDER BY r.id ASC LIMIT 1");
        if (!$row) return;

        $wpdb->update($campaigns, ['status'=>'sending','updated_at'=>$now], ['id'=>(int)$row->campaign_id]);

        if ((string)$row->consent_status !== 'yes' || (string)$row->contact_status !== 'active') {
            $wpdb->update($recipients, ['status'=>'skipped','error_message'=>'Brak aktywnej zgody w chwili wysyłki.','updated_at'=>$now], ['id'=>(int)$row->id]);
            self::finalize_campaign_if_done((int)$row->campaign_id, $now);
            return;
        }

        $contact = (object)[
            'unsubscribe_token'=>(string)$row->unsubscribe_token,
            'email'=>(string)($row->contact_email ?: $row->email),
        ];
        $unsubscribe = BCS_Release_096::unsubscribe_url($contact);
        $ok = self::send_marketing_mail((string)$row->email, (string)$row->subject_snapshot, (string)$row->body_snapshot, $unsubscribe);
        $wpdb->update($recipients, [
            'status'=>$ok ? 'sent' : 'failed',
            'sent_at'=>$ok ? $now : null,
            'error_message'=>$ok ? null : 'Transport pocztowy odrzucił wiadomość.',
            'updated_at'=>$now,
        ], ['id'=>(int)$row->id]);

        if ($ok) {
            update_option(self::FAILURE_OPTION, 0, false);
        } else {
            $failures = (int)get_option(self::FAILURE_OPTION, 0) + 1;
            update_option(self::FAILURE_OPTION, $failures, false);
            if ($failures >= (int)$s['max_consecutive_failures']) {
                update_option(self::AUTO_PAUSE_OPTION, 1, false);
                $wpdb->query("UPDATE {$campaigns} SET status='paused',updated_at='".esc_sql($now)."' WHERE status IN ('queued','sending')");
            }
        }

        $gap = wp_rand((int)$s['gap_min_minutes'], (int)$s['gap_max_minutes']);
        update_option(self::NEXT_SEND_OPTION, $nowTs + ($gap * MINUTE_IN_SECONDS), false);
        self::finalize_campaign_if_done((int)$row->campaign_id, $now);
    }

    private static function finalize_campaign_if_done(int $campaignId, string $now): void {
        global $wpdb;
        $recipients = BCS_Release_097::recipients_table();
        $campaigns = BCS_Release_097::campaigns_table();
        $pending = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$recipients} WHERE campaign_id=%d AND status IN ('queued','sending')", $campaignId));
        if ($pending === 0) $wpdb->update($campaigns, ['status'=>'completed','completed_at'=>$now,'updated_at'=>$now], ['id'=>$campaignId]);
    }

    public static function sent_today_count(): int {
        global $wpdb;
        $tz = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);
        $start = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = $now->modify('+1 day')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $table = BCS_Release_097::recipients_table();
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status='sent' AND sent_at>=%s AND sent_at<%s", $start, $end));
    }

    public static function within_send_window(int $start, int $end): bool {
        $hour = (int)wp_date('G', BCS_Utils::timestamp(), wp_timezone());
        if ($start === $end) return true;
        if ($start < $end) return $hour >= $start && $hour < $end;
        return $hour >= $start || $hour < $end;
    }

    public static function send_marketing_mail(string $to, string $subject, string $html, string $unsubscribeUrl): bool {
        $to = sanitize_email($to);
        if (!is_email($to) || !wp_http_validate_url($unsubscribeUrl)) return false;
        $base = (array)get_option('bcs_settings', []);
        if (BCS_Mailer::configuration_error($base) !== '') return false;
        $s = self::settings();
        $reply = sanitize_email((string)($s['marketing_reply_to'] ?: ($base['mail_reply_to'] ?? $base['company_email'] ?? get_option('admin_email'))));

        add_filter('wp_mail_from_name', [__CLASS__, 'filter_from_name'], 1001);
        add_filter('wp_mail_from', [__CLASS__, 'filter_from_email'], 1001);
        add_action('phpmailer_init', [__CLASS__, 'configure_marketing_phpmailer'], 1000);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'List-Unsubscribe: <'.$unsubscribeUrl.'>',
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
        ];
        if (is_email($reply)) $headers[] = 'Reply-To: '.$reply;
        $ok = wp_mail($to, $subject, $html, $headers);

        remove_filter('wp_mail_from_name', [__CLASS__, 'filter_from_name'], 1001);
        remove_filter('wp_mail_from', [__CLASS__, 'filter_from_email'], 1001);
        remove_action('phpmailer_init', [__CLASS__, 'configure_marketing_phpmailer'], 1000);
        return (bool)$ok;
    }

    public static function filter_from_name(string $name): string {
        $s = self::settings();
        $candidate = sanitize_text_field((string)$s['marketing_from_name']);
        return $candidate !== '' ? $candidate : $name;
    }

    public static function filter_from_email(string $email): string {
        $s = self::settings();
        $base = (array)get_option('bcs_settings', []);
        $candidate = sanitize_email((string)($s['marketing_from_email'] ?: ($base['mail_from_email'] ?? $base['company_email'] ?? '')));
        return is_email($candidate) ? $candidate : $email;
    }

    public static function configure_marketing_phpmailer($phpmailer): void {
        $email = self::filter_from_email('');
        $name = self::filter_from_name('Basketmania Camp');
        if (is_email($email)) {
            try { $phpmailer->setFrom($email, $name, false); } catch (Throwable $e) {}
            $phpmailer->Sender = $email;
        }
    }

    public static function email_domain(string $email): string {
        $email = sanitize_email($email);
        if (!is_email($email) || !str_contains($email, '@')) return '';
        return strtolower((string)substr(strrchr($email, '@'), 1));
    }

    public static function txt_contains(string $host, string $needle): bool {
        if (!function_exists('dns_get_record') || $host === '') return false;
        $records = @dns_get_record($host, DNS_TXT);
        if (!is_array($records)) return false;
        foreach ($records as $record) {
            $txt = (string)($record['txt'] ?? '');
            if ($txt !== '' && stripos($txt, $needle) !== false) return true;
        }
        return false;
    }
}
