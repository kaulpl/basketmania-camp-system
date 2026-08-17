<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.97 – kampanie mailingowe, segmenty, test, harmonogram i kolejka wysyłki.
 */
final class BCS_Release_097 {
    private const SAVE_ACTION = 'bcs_marketing_campaign_save_097';
    private const LAUNCH_ACTION = 'bcs_marketing_campaign_launch_097';
    private const TEST_ACTION = 'bcs_marketing_campaign_test_097';
    private const PAUSE_ACTION = 'bcs_marketing_campaign_pause_097';
    private const RESUME_ACTION = 'bcs_marketing_campaign_resume_097';
    private const CRON_HOOK = 'bcs_marketing_queue_097';
    private const BATCH_SIZE = 20;
    private static bool $marketingSending = false;

    public static function init(): void {
        self::ensure_schema();
        add_action('admin_post_'.self::SAVE_ACTION, [__CLASS__, 'save_campaign']);
        add_action('admin_post_'.self::LAUNCH_ACTION, [__CLASS__, 'launch_campaign']);
        add_action('admin_post_'.self::TEST_ACTION, [__CLASS__, 'send_test']);
        add_action('admin_post_'.self::PAUSE_ACTION, [__CLASS__, 'pause_campaign']);
        add_action('admin_post_'.self::RESUME_ACTION, [__CLASS__, 'resume_campaign']);
        add_action(self::CRON_HOOK, [__CLASS__, 'run_queue']);
    }

    public static function campaigns_table(): string { return BCS_DB::table('marketing_campaigns'); }
    public static function recipients_table(): string { return BCS_DB::table('marketing_campaign_recipients'); }

    public static function ensure_schema(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $campaigns = self::campaigns_table();
        $recipients = self::recipients_table();

        dbDelta("CREATE TABLE {$campaigns} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            preheader VARCHAR(255) NULL,
            body LONGTEXT NOT NULL,
            cta_label VARCHAR(120) NULL,
            cta_url TEXT NULL,
            audience_type VARCHAR(40) NOT NULL DEFAULT 'all_consented',
            audience_value VARCHAR(190) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            scheduled_at DATETIME NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY scheduled_at (scheduled_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$recipients} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL,
            contact_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(190) NOT NULL,
            subject_snapshot TEXT NULL,
            body_snapshot LONGTEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'queued',
            considered_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            error_message TEXT NULL,
            mailing_year SMALLINT UNSIGNED NOT NULL,
            click_token CHAR(64) NOT NULL,
            clicked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY campaign_contact (campaign_id, contact_id),
            UNIQUE KEY click_token (click_token),
            KEY campaign_status (campaign_id, status),
            KEY contact_id (contact_id),
            KEY mailing_year (mailing_year)
        ) {$charset};");
    }

    private static function now(): string { return BCS_Utils::now(); }

    public static function render_campaigns_page(string $tab): void {
        if ($tab === 'new-campaign' || $tab === 'campaign') {
            self::render_campaign_form(absint($_GET['campaign_id'] ?? 0));
            return;
        }
        self::render_campaign_list();
    }

    private static function render_campaign_list(): void {
        global $wpdb;
        $campaigns = self::campaigns_table();
        $recipients = self::recipients_table();
        $rows = $wpdb->get_results("SELECT c.*,
            (SELECT COUNT(*) FROM {$recipients} r WHERE r.campaign_id=c.id) recipient_count,
            (SELECT COUNT(*) FROM {$recipients} r WHERE r.campaign_id=c.id AND r.status='sent') sent_count,
            (SELECT COUNT(*) FROM {$recipients} r WHERE r.campaign_id=c.id AND r.status='failed') failed_count,
            (SELECT COUNT(*) FROM {$recipients} r WHERE r.campaign_id=c.id AND r.clicked_at IS NOT NULL) click_count
            FROM {$campaigns} c ORDER BY c.id DESC LIMIT 200");
        if (!empty($_GET['campaign_saved'])) echo '<div class="notice notice-success"><p>Kampania została zapisana.</p></div>';
        if (!empty($_GET['campaign_launched'])) echo '<div class="notice notice-success"><p>Kampania została przygotowana i dodana do kolejki.</p></div>';
        if (!empty($_GET['test_sent'])) echo '<div class="notice notice-success"><p>Wiadomość testowa została wysłana.</p></div>';

        echo '<table class="widefat striped"><thead><tr><th>Kampania</th><th>Temat</th><th>Odbiorcy</th><th>Wysłane</th><th>Błędy</th><th>Kliknięcia</th><th>Status</th><th></th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="8">Nie utworzono jeszcze żadnej kampanii.</td></tr>';
        foreach ((array)$rows as $row) {
            $edit = add_query_arg(['page'=>'bcs-mailing','tab'=>'campaign','campaign_id'=>(int)$row->id], admin_url('admin.php'));
            $details = class_exists('BCS_Release_098') ? BCS_Release_098::campaign_history_url((int)$row->id) : $edit;
            echo '<tr><td><strong>'.esc_html((string)$row->name).'</strong><br><small>#'.(int)$row->id.'</small></td><td>'.esc_html((string)$row->subject).'</td>';
            echo '<td>'.(int)$row->recipient_count.'</td><td>'.(int)$row->sent_count.'</td><td>'.(int)$row->failed_count.'</td><td>'.(int)$row->click_count.'</td><td>'.esc_html(self::status_label((string)$row->status)).'</td>';
            echo '<td><a class="button button-small" href="'.esc_url($edit).'">Edytuj</a> <a class="button button-small" href="'.esc_url($details).'">Szczegóły</a></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function status_label(string $status): string {
        return [
            'draft'=>'Szkic','scheduled'=>'Zaplanowana','queued'=>'W kolejce','sending'=>'Wysyłanie','paused'=>'Wstrzymana','completed'=>'Zakończona'
        ][$status] ?? $status;
    }

    private static function campaign(int $id): ?object {
        if ($id <= 0) return null;
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::campaigns_table()." WHERE id=%d", $id));
    }

    private static function render_campaign_form(int $campaignId): void {
        $campaign = self::campaign($campaignId);
        $editable = !$campaign || in_array((string)$campaign->status, ['draft'], true);
        $values = [
            'name'=>$campaign->name ?? '',
            'subject'=>$campaign->subject ?? '',
            'preheader'=>$campaign->preheader ?? '',
            'body'=>$campaign->body ?? '',
            'cta_label'=>$campaign->cta_label ?? 'Zobacz szczegóły',
            'cta_url'=>$campaign->cta_url ?? 'https://camp.basketmania.pl/',
            'audience_type'=>$campaign->audience_type ?? 'all_consented',
            'audience_value'=>$campaign->audience_value ?? '',
            'scheduled_at'=>$campaign->scheduled_at ?? '',
        ];
        echo '<div class="card" style="max-width:1050px;padding:22px">';
        echo '<h2>'.($campaign ? 'Kampania #'.(int)$campaign->id : 'Nowa kampania').'</h2>';
        if ($campaign) echo '<p>Status: <strong>'.esc_html(self::status_label((string)$campaign->status)).'</strong></p>';

        if ($editable) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="'.esc_attr(self::SAVE_ACTION).'"><input type="hidden" name="campaign_id" value="'.(int)$campaignId.'">';
            wp_nonce_field(self::SAVE_ACTION.'_'.$campaignId);
            echo '<table class="form-table"><tbody>';
            echo '<tr><th><label for="bcs_campaign_name">Nazwa wewnętrzna</label></th><td><input class="regular-text" id="bcs_campaign_name" name="name" value="'.esc_attr((string)$values['name']).'" required></td></tr>';
            echo '<tr><th><label for="bcs_campaign_subject">Temat wiadomości</label></th><td><input class="large-text" id="bcs_campaign_subject" name="subject" value="'.esc_attr((string)$values['subject']).'" required></td></tr>';
            echo '<tr><th><label for="bcs_campaign_preheader">Preheader</label></th><td><input class="large-text" id="bcs_campaign_preheader" name="preheader" value="'.esc_attr((string)$values['preheader']).'"><p class="description">Krótki tekst widoczny obok tematu w skrzynce odbiorczej.</p></td></tr>';
            echo '<tr><th>Treść</th><td>';
            wp_editor((string)$values['body'], 'bcs_campaign_body_editor', ['textarea_name'=>'body','textarea_rows'=>14,'media_buttons'=>true]);
            echo '<p class="description">Zmienne: <code>{{PARENT_NAME}}</code>, <code>{{FIRST_NAME}}</code>, <code>{{UNSUBSCRIBE_URL}}</code>.</p></td></tr>';
            echo '<tr><th>Przycisk CTA</th><td><input name="cta_label" value="'.esc_attr((string)$values['cta_label']).'" placeholder="Tekst przycisku"> <input class="regular-text" type="url" name="cta_url" value="'.esc_attr((string)$values['cta_url']).'" placeholder="https://..."></td></tr>';
            echo '<tr><th>Odbiorcy</th><td><select name="audience_type" id="bcs_audience_type_097">';
            $audiences = ['all_consented'=>'Wszyscy z aktywną zgodą','imported'=>'Zaimportowana baza ze zgodą','registration_year'=>'Rodzice uczestników z roku','camp'=>'Rodzice z konkretnego turnusu'];
            foreach ($audiences as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected((string)$values['audience_type'],$key,false).'>'.esc_html($label).'</option>';
            echo '</select> <input name="audience_value" value="'.esc_attr((string)$values['audience_value']).'" placeholder="np. 2026 lub ID turnusu"><p class="description">Każdy segment jest dodatkowo ograniczony do kontaktów z aktywną zgodą marketingową.</p></td></tr>';
            $scheduledValue = $values['scheduled_at'] ? str_replace(' ', 'T', substr((string)$values['scheduled_at'],0,16)) : '';
            echo '<tr><th>Planowana wysyłka</th><td><input type="datetime-local" name="scheduled_at" value="'.esc_attr($scheduledValue).'"><p class="description">Puste pole = wysyłka po uruchomieniu kampanii.</p></td></tr>';
            echo '</tbody></table>';
            submit_button($campaign ? 'Zapisz zmiany' : 'Utwórz kampanię');
            echo '</form>';
        }

        if ($campaign) {
            echo '<hr><h3>Test i uruchomienie</h3>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex;gap:8px;align-items:center;margin-bottom:14px">';
            echo '<input type="hidden" name="action" value="'.esc_attr(self::TEST_ACTION).'"><input type="hidden" name="campaign_id" value="'.(int)$campaign->id.'">';
            wp_nonce_field(self::TEST_ACTION.'_'.(int)$campaign->id);
            echo '<input type="email" name="test_email" class="regular-text" placeholder="Adres do testu" required><button class="button">Wyślij test</button></form>';

            if ((string)$campaign->status === 'draft') {
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                echo '<input type="hidden" name="action" value="'.esc_attr(self::LAUNCH_ACTION).'"><input type="hidden" name="campaign_id" value="'.(int)$campaign->id.'">';
                wp_nonce_field(self::LAUNCH_ACTION.'_'.(int)$campaign->id);
                echo '<p><strong>Uruchomienie zamrozi aktualną listę odbiorców.</strong> Późniejsze zmiany w bazie nie dopiszą nowych osób do tej kampanii.</p>';
                submit_button('Uruchom kampanię', 'primary');
                echo '</form>';
            } elseif (in_array((string)$campaign->status, ['queued','sending','scheduled'], true)) {
                self::small_action_form(self::PAUSE_ACTION, (int)$campaign->id, 'Wstrzymaj kampanię');
            } elseif ((string)$campaign->status === 'paused') {
                self::small_action_form(self::RESUME_ACTION, (int)$campaign->id, 'Wznów kampanię', 'primary');
            }
        }
        echo '</div>';
    }

    private static function small_action_form(string $action, int $campaignId, string $label, string $class = 'secondary'): void {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block">';
        echo '<input type="hidden" name="action" value="'.esc_attr($action).'"><input type="hidden" name="campaign_id" value="'.$campaignId.'">';
        wp_nonce_field($action.'_'.$campaignId);
        echo '<button class="button '.($class === 'primary' ? 'button-primary' : '').'">'.esc_html($label).'</button></form>';
    }

    public static function save_campaign(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        $id = absint($_POST['campaign_id'] ?? 0);
        check_admin_referer(self::SAVE_ACTION.'_'.$id);
        $existing = self::campaign($id);
        if ($existing && (string)$existing->status !== 'draft') wp_die('Można edytować tylko kampanię w statusie Szkic.');
        $audienceType = sanitize_key(wp_unslash($_POST['audience_type'] ?? 'all_consented'));
        if (!in_array($audienceType, ['all_consented','imported','registration_year','camp'], true)) $audienceType = 'all_consented';
        $scheduled = sanitize_text_field(wp_unslash($_POST['scheduled_at'] ?? ''));
        if ($scheduled !== '') $scheduled = str_replace('T', ' ', $scheduled).(strlen($scheduled) === 16 ? ':00' : '');
        $data = [
            'name'=>sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'subject'=>sanitize_text_field(wp_unslash($_POST['subject'] ?? '')),
            'preheader'=>sanitize_text_field(wp_unslash($_POST['preheader'] ?? '')),
            'body'=>wp_kses_post(wp_unslash($_POST['body'] ?? '')),
            'cta_label'=>sanitize_text_field(wp_unslash($_POST['cta_label'] ?? '')),
            'cta_url'=>esc_url_raw(wp_unslash($_POST['cta_url'] ?? '')),
            'audience_type'=>$audienceType,
            'audience_value'=>sanitize_text_field(wp_unslash($_POST['audience_value'] ?? '')),
            'scheduled_at'=>$scheduled !== '' ? $scheduled : null,
            'updated_at'=>self::now(),
        ];
        if ($data['name'] === '' || $data['subject'] === '' || trim(wp_strip_all_tags((string)$data['body'])) === '') wp_die('Nazwa, temat i treść kampanii są wymagane.');
        global $wpdb;
        if ($id) {
            $wpdb->update(self::campaigns_table(), $data, ['id'=>$id]);
        } else {
            $data['status'] = 'draft';
            $data['created_by'] = get_current_user_id();
            $data['created_at'] = self::now();
            $wpdb->insert(self::campaigns_table(), $data);
            $id = (int)$wpdb->insert_id;
        }
        wp_safe_redirect(add_query_arg(['page'=>'bcs-mailing','tab'=>'campaign','campaign_id'=>$id,'campaign_saved'=>1], admin_url('admin.php')));
        exit;
    }

    /** @return object[] */
    public static function audience_contacts(object $campaign): array {
        global $wpdb;
        $contacts = BCS_Release_096::contacts_table();
        $registrations = BCS_DB::table('registrations');
        $camps = BCS_DB::table('camps');
        $where = "m.consent_status='yes' AND m.status='active'";
        $type = (string)($campaign->audience_type ?? 'all_consented');
        $value = (string)($campaign->audience_value ?? '');
        if ($type === 'imported') {
            $where .= " AND m.consent_source='import'";
        } elseif ($type === 'registration_year') {
            $year = max(2000, min(2100, (int)$value));
            $where .= $wpdb->prepare(" AND EXISTS(SELECT 1 FROM {$registrations} r JOIN {$camps} c ON c.id=r.camp_id WHERE LOWER(r.parent_email)=LOWER(m.email) AND YEAR(c.start_date)=%d)", $year);
        } elseif ($type === 'camp') {
            $campId = absint($value);
            $where .= $wpdb->prepare(" AND EXISTS(SELECT 1 FROM {$registrations} r WHERE r.camp_id=%d AND r.status<>'cancelled' AND LOWER(r.parent_email)=LOWER(m.email))", $campId);
        }
        return (array)$wpdb->get_results("SELECT m.* FROM {$contacts} m WHERE {$where} ORDER BY m.email ASC");
    }

    public static function launch_campaign(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        $id = absint($_POST['campaign_id'] ?? 0);
        check_admin_referer(self::LAUNCH_ACTION.'_'.$id);
        $campaign = self::campaign($id);
        if (!$campaign || (string)$campaign->status !== 'draft') wp_die('Kampania nie może zostać uruchomiona.');
        $contacts = self::audience_contacts($campaign);
        global $wpdb;
        $now = self::now();
        $year = (int)wp_date('Y', BCS_Utils::timestamp());
        foreach ($contacts as $contact) {
            $token = BCS_Utils::random_token();
            $wpdb->insert(self::recipients_table(), [
                'campaign_id'=>$id,
                'contact_id'=>(int)$contact->id,
                'email'=>(string)$contact->email,
                'status'=>'queued',
                'considered_at'=>$now,
                'mailing_year'=>$year,
                'click_token'=>$token,
                'created_at'=>$now,
                'updated_at'=>$now,
            ]);
            $recipientId = (int)$wpdb->insert_id;
            if (!$recipientId) continue;
            $recipient = (object)['id'=>$recipientId,'click_token'=>$token];
            [$subject, $html] = self::build_recipient_message($campaign, $contact, $recipient);
            $wpdb->update(self::recipients_table(), ['subject_snapshot'=>$subject,'body_snapshot'=>$html], ['id'=>$recipientId]);
        }
        $scheduled = !empty($campaign->scheduled_at) ? strtotime((string)$campaign->scheduled_at) : 0;
        $future = $scheduled && $scheduled > BCS_Utils::timestamp();
        $wpdb->update(self::campaigns_table(), [
            'status'=>$future ? 'scheduled' : 'queued',
            'started_at'=>$future ? null : $now,
            'updated_at'=>$now,
        ], ['id'=>$id]);
        self::schedule_queue($future ? $scheduled : (BCS_Utils::timestamp()+5));
        wp_safe_redirect(add_query_arg(['page'=>'bcs-mailing','tab'=>'campaigns','campaign_launched'=>1], admin_url('admin.php')));
        exit;
    }

    private static function replace_variables(string $text, object $contact, string $unsubscribeUrl): string {
        $full = trim((string)$contact->first_name.' '.(string)$contact->last_name);
        return strtr($text, [
            '{{PARENT_NAME}}'=>$full !== '' ? $full : (string)$contact->email,
            '{{FIRST_NAME}}'=>(string)($contact->first_name ?: 'Rodzicu'),
            '{{UNSUBSCRIBE_URL}}'=>$unsubscribeUrl,
        ]);
    }

    /** @return array{0:string,1:string} */
    public static function build_recipient_message(object $campaign, object $contact, object $recipient): array {
        $unsubscribe = BCS_Release_096::unsubscribe_url($contact);
        $subject = BCS_Mailer::prefix_subject(self::replace_variables((string)$campaign->subject, $contact, $unsubscribe));
        $body = self::replace_variables((string)$campaign->body, $contact, $unsubscribe);
        $ctaUrl = (string)($campaign->cta_url ?? '');
        if ($ctaUrl !== '' && class_exists('BCS_Release_098')) $ctaUrl = BCS_Release_098::tracking_url($recipient, $campaign);
        if ($ctaUrl !== '' && trim((string)($campaign->cta_label ?? '')) !== '') {
            $body .= '<p style="text-align:center;margin:30px 0"><a href="'.esc_url($ctaUrl).'" style="display:inline-block;background:#f57618;color:#fff!important;text-decoration:none;padding:14px 24px;border-radius:9px;font-weight:700">'.esc_html((string)$campaign->cta_label).'</a></p>';
        }
        $body .= '<p style="margin-top:34px;padding-top:20px;border-top:1px solid #e5e7eb;font-size:12px;line-height:18px;color:#7b7f86;text-align:center">Nie chcesz otrzymywać informacji o kolejnych edycjach Basketmania Camp? <a href="'.esc_url($unsubscribe).'">Wypisz się z mailingu</a>.</p>';
        $html = BCS_Mailer::wrap_html_email($subject, $body);
        if (!empty($campaign->preheader)) {
            $preheader = '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">'.esc_html(self::replace_variables((string)$campaign->preheader, $contact, $unsubscribe)).'</div>';
            $html = str_replace('<body ', '<body '.$preheader, $html);
        }
        return [$subject, $html];
    }

    public static function send_test(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        $id = absint($_POST['campaign_id'] ?? 0);
        check_admin_referer(self::TEST_ACTION.'_'.$id);
        $campaign = self::campaign($id);
        $email = sanitize_email(wp_unslash($_POST['test_email'] ?? ''));
        if (!$campaign || !is_email($email)) wp_die('Nie można wysłać wiadomości testowej.');
        $contact = (object)['email'=>$email,'first_name'=>'Test','last_name'=>'Basketmania','unsubscribe_token'=>'test'];
        $recipient = (object)['id'=>0,'click_token'=>'test'];
        [$subject,$html] = self::build_recipient_message($campaign, $contact, $recipient);
        $ok = self::send_marketing_mail($email, $subject, $html);
        if (!$ok) wp_die('Nie udało się wysłać wiadomości testowej.');
        wp_safe_redirect(add_query_arg(['page'=>'bcs-mailing','tab'=>'campaign','campaign_id'=>$id,'test_sent'=>1], admin_url('admin.php')));
        exit;
    }

    public static function filter_from_name(string $name): string {
        if (!self::$marketingSending) return $name;
        $s = get_option('bcs_settings', []);
        return sanitize_text_field((string)($s['mail_from_name'] ?? $s['company_name'] ?? 'Basketmania Camp')) ?: $name;
    }

    public static function filter_from_email(string $email): string {
        if (!self::$marketingSending) return $email;
        $s = get_option('bcs_settings', []);
        $from = sanitize_email((string)($s['mail_from_email'] ?? $s['company_email'] ?? ''));
        return is_email($from) ? $from : $email;
    }

    public static function send_marketing_mail(string $to, string $subject, string $html): bool {
        $to = sanitize_email($to);
        if (!is_email($to)) return false;
        $s = get_option('bcs_settings', []);
        if (BCS_Mailer::configuration_error($s) !== '') return false;
        self::$marketingSending = true;
        add_filter('wp_mail_from_name', [__CLASS__, 'filter_from_name'], 999);
        add_filter('wp_mail_from', [__CLASS__, 'filter_from_email'], 999);
        $reply = sanitize_email((string)($s['mail_reply_to'] ?? $s['company_email'] ?? get_option('admin_email')));
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if (is_email($reply)) $headers[] = 'Reply-To: '.$reply;
        $ok = wp_mail($to, $subject, $html, $headers);
        remove_filter('wp_mail_from_name', [__CLASS__, 'filter_from_name'], 999);
        remove_filter('wp_mail_from', [__CLASS__, 'filter_from_email'], 999);
        self::$marketingSending = false;
        return (bool)$ok;
    }

    private static function schedule_queue(int $timestamp): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_single_event(max(time()+2, $timestamp), self::CRON_HOOK);
    }

    public static function run_queue(): void {
        global $wpdb;
        $campaigns = self::campaigns_table();
        $recipients = self::recipients_table();
        $contacts = BCS_Release_096::contacts_table();
        $nowTs = BCS_Utils::timestamp();
        $now = self::now();

        // Kampanie zaplanowane przechodzą do kolejki dopiero po osiągnięciu terminu.
        $wpdb->query($wpdb->prepare("UPDATE {$campaigns} SET status='queued',started_at=COALESCE(started_at,%s),updated_at=%s WHERE status='scheduled' AND scheduled_at IS NOT NULL AND scheduled_at<=%s", $now, $now, $now));

        $rows = $wpdb->get_results($wpdb->prepare("SELECT r.*,c.status campaign_status,m.consent_status,m.status contact_status
            FROM {$recipients} r
            JOIN {$campaigns} c ON c.id=r.campaign_id
            LEFT JOIN {$contacts} m ON m.id=r.contact_id
            WHERE r.status='queued' AND c.status IN ('queued','sending')
            ORDER BY r.id ASC LIMIT %d", self::BATCH_SIZE));

        $campaignIds = [];
        foreach ((array)$rows as $row) {
            $campaignIds[(int)$row->campaign_id] = true;
            $wpdb->update($campaigns, ['status'=>'sending','updated_at'=>$now], ['id'=>(int)$row->campaign_id]);
            if ((string)$row->consent_status !== 'yes' || (string)$row->contact_status !== 'active') {
                $wpdb->update($recipients, ['status'=>'skipped','error_message'=>'Brak aktywnej zgody w chwili wysyłki.','updated_at'=>$now], ['id'=>(int)$row->id]);
                continue;
            }
            $ok = self::send_marketing_mail((string)$row->email, (string)$row->subject_snapshot, (string)$row->body_snapshot);
            $wpdb->update($recipients, [
                'status'=>$ok ? 'sent' : 'failed',
                'sent_at'=>$ok ? $now : null,
                'error_message'=>$ok ? null : 'Transport pocztowy odrzucił wiadomość.',
                'updated_at'=>$now,
            ], ['id'=>(int)$row->id]);
        }

        foreach (array_keys($campaignIds) as $campaignId) {
            $pending = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$recipients} WHERE campaign_id=%d AND status='queued'", $campaignId));
            if ($pending === 0) $wpdb->update($campaigns, ['status'=>'completed','completed_at'=>$now,'updated_at'=>$now], ['id'=>$campaignId]);
        }

        $remaining = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$recipients} r JOIN {$campaigns} c ON c.id=r.campaign_id WHERE r.status='queued' AND c.status IN ('queued','sending')");
        $scheduled = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$campaigns} WHERE status='scheduled'");
        if ($remaining > 0) self::schedule_queue($nowTs + 60);
        elseif ($scheduled > 0) {
            $next = $wpdb->get_var("SELECT scheduled_at FROM {$campaigns} WHERE status='scheduled' AND scheduled_at IS NOT NULL ORDER BY scheduled_at ASC LIMIT 1");
            if ($next) self::schedule_queue(max($nowTs+60, strtotime((string)$next)));
        }
    }

    public static function pause_campaign(): void {
        self::campaign_status_action(self::PAUSE_ACTION, 'paused');
    }

    public static function resume_campaign(): void {
        self::campaign_status_action(self::RESUME_ACTION, 'queued');
        self::schedule_queue(BCS_Utils::timestamp()+5);
    }

    private static function campaign_status_action(string $action, string $status): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        $id = absint($_POST['campaign_id'] ?? 0);
        check_admin_referer($action.'_'.$id);
        global $wpdb;
        $wpdb->update(self::campaigns_table(), ['status'=>$status,'updated_at'=>self::now()], ['id'=>$id]);
        wp_safe_redirect(add_query_arg(['page'=>'bcs-mailing','tab'=>'campaign','campaign_id'=>$id], admin_url('admin.php')));
        exit;
    }
}
