<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.98 – historia odbiorcy, podsumowania roczne, snapshot wiadomości i kliknięcia CTA.
 */
final class BCS_Release_098 {
    private const CLICK_ACTION = 'bcs_marketing_click_098';

    public static function init(): void {
        add_action('admin_post_'.self::CLICK_ACTION, [__CLASS__, 'handle_click']);
        add_action('admin_post_nopriv_'.self::CLICK_ACTION, [__CLASS__, 'handle_click']);
    }

    public static function tracking_url(object $recipient, object $campaign): string {
        if (empty($recipient->id) || empty($recipient->click_token)) return esc_url_raw((string)($campaign->cta_url ?? ''));
        return add_query_arg([
            'action'=>self::CLICK_ACTION,
            'token'=>(string)$recipient->click_token,
        ], admin_url('admin-post.php'));
    }

    public static function handle_click(): void {
        global $wpdb;
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        $recipients = BCS_Release_097::recipients_table();
        $campaigns = BCS_Release_097::campaigns_table();
        $row = $token !== '' ? $wpdb->get_row($wpdb->prepare("SELECT r.id,r.clicked_at,c.cta_url FROM {$recipients} r JOIN {$campaigns} c ON c.id=r.campaign_id WHERE r.click_token=%s", $token)) : null;
        if (!$row) wp_die('Nieprawidłowy link kampanii.');
        if (empty($row->clicked_at)) $wpdb->update($recipients, ['clicked_at'=>BCS_Utils::now(),'updated_at'=>BCS_Utils::now()], ['id'=>(int)$row->id]);
        $url = esc_url_raw((string)$row->cta_url);
        wp_safe_redirect($url !== '' ? $url : home_url('/'));
        exit;
    }

    public static function contact_history_url(int $contactId): string {
        return add_query_arg(['page'=>'bcs-mailing','tab'=>'contacts','contact_id'=>$contactId], admin_url('admin.php'));
    }

    public static function campaign_history_url(int $campaignId): string {
        return add_query_arg(['page'=>'bcs-mailing','tab'=>'campaign','campaign_id'=>$campaignId,'history'=>1], admin_url('admin.php'));
    }

    public static function year_summary_html(int $contactId): string {
        if ($contactId <= 0) return '';
        global $wpdb;
        $table = BCS_Release_097::recipients_table();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT mailing_year,COUNT(*) cnt FROM {$table} WHERE contact_id=%d AND status='sent' GROUP BY mailing_year ORDER BY mailing_year DESC", $contactId));
        if (!$rows) return '<span style="color:#777">Brak wysyłek</span>';
        $out = [];
        foreach ((array)$rows as $row) $out[] = '<span style="display:inline-block;background:#e7f2ff;color:#135e96;border-radius:12px;padding:2px 8px;margin:1px 3px 1px 0;font-size:11px">'.(int)$row->mailing_year.': '.(int)$row->cnt.'</span>';
        return implode('', $out);
    }

    public static function render_contact_history(int $contactId): void {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $contacts = BCS_Release_096::contacts_table();
        $events = BCS_Release_096::consent_events_table();
        $recipients = BCS_Release_097::recipients_table();
        $campaigns = BCS_Release_097::campaigns_table();
        $contact = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$contacts} WHERE id=%d", $contactId));
        if (!$contact) { echo '<div class="notice notice-error"><p>Nie znaleziono kontaktu.</p></div>'; return; }

        $back = add_query_arg(['page'=>'bcs-mailing','tab'=>'contacts'], admin_url('admin.php'));
        echo '<p><a href="'.esc_url($back).'">&larr; Wróć do kontaktów</a></p>';
        echo '<div class="card" style="max-width:1050px;padding:22px"><h2>'.esc_html((string)$contact->email).'</h2>';
        echo '<p><strong>'.esc_html(trim((string)$contact->first_name.' '.(string)$contact->last_name)).'</strong></p>';
        echo '<p>Zgoda: <strong>'.($contact->consent_status === 'yes' ? 'TAK' : 'NIE').'</strong> · Status: <strong>'.esc_html((string)$contact->status).'</strong> · Źródło: '.esc_html((string)$contact->consent_source).'</p>';
        echo '<p>Mailingi w latach: '.self::year_summary_html($contactId).'</p></div>';

        echo '<h2>Historia zgody</h2>';
        $consentRows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$events} WHERE contact_id=%d ORDER BY created_at DESC,id DESC", $contactId));
        echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Zdarzenie</th><th>Wartość</th><th>Źródło</th><th>Zgłoszenie</th></tr></thead><tbody>';
        if (!$consentRows) echo '<tr><td colspan="5">Brak zdarzeń zgody.</td></tr>';
        foreach ((array)$consentRows as $row) echo '<tr><td>'.esc_html(wp_date('d.m.Y H:i',strtotime((string)$row->created_at))).'</td><td>'.esc_html((string)$row->event_type).'</td><td>'.esc_html((string)$row->consent_value).'</td><td>'.esc_html((string)$row->source).'</td><td>'.($row->registration_id ? '#'.(int)$row->registration_id : '—').'</td></tr>';
        echo '</tbody></table>';

        echo '<h2 style="margin-top:28px">Historia kampanii</h2>';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT r.*,c.name campaign_name FROM {$recipients} r JOIN {$campaigns} c ON c.id=r.campaign_id WHERE r.contact_id=%d ORDER BY r.considered_at DESC,r.id DESC", $contactId));
        echo '<table class="widefat striped"><thead><tr><th>Rok</th><th>Kampania</th><th>Brany pod uwagę</th><th>Status</th><th>Wysłano</th><th>Kliknięcie</th><th>Wiadomość</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="7">Kontakt nie brał jeszcze udziału w żadnej kampanii.</td></tr>';
        foreach ((array)$rows as $row) {
            echo '<tr><td>'.(int)$row->mailing_year.'</td><td><strong>'.esc_html((string)$row->campaign_name).'</strong><br><small>#'.(int)$row->campaign_id.'</small></td>';
            echo '<td>'.esc_html(wp_date('d.m.Y H:i',strtotime((string)$row->considered_at))).'</td><td>'.esc_html(self::recipient_status_label((string)$row->status)).($row->error_message ? '<br><small>'.esc_html((string)$row->error_message).'</small>' : '').'</td>';
            echo '<td>'.esc_html($row->sent_at ? wp_date('d.m.Y H:i',strtotime((string)$row->sent_at)) : '—').'</td><td>'.esc_html($row->clicked_at ? wp_date('d.m.Y H:i',strtotime((string)$row->clicked_at)) : '—').'</td>';
            echo '<td>'.self::message_preview_html((string)$row->subject_snapshot,(string)$row->body_snapshot,'Wiadomość dla tego odbiorcy').'</td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function render_campaign_history(int $campaignId): void {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $campaigns = BCS_Release_097::campaigns_table();
        $recipients = BCS_Release_097::recipients_table();
        $contacts = BCS_Release_096::contacts_table();
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$campaigns} WHERE id=%d", $campaignId));
        if (!$campaign) { echo '<div class="notice notice-error"><p>Nie znaleziono kampanii.</p></div>'; return; }
        $back = add_query_arg(['page'=>'bcs-mailing','tab'=>'campaigns'], admin_url('admin.php'));
        echo '<p><a href="'.esc_url($back).'">&larr; Wróć do kampanii</a></p><h2>'.esc_html((string)$campaign->name).'</h2>';

        $stats = [];
        foreach (['queued','sent','failed','skipped'] as $status) $stats[$status] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$recipients} WHERE campaign_id=%d AND status=%s", $campaignId, $status));
        $stats['clicked'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$recipients} WHERE campaign_id=%d AND clicked_at IS NOT NULL", $campaignId));
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:14px 0 22px">';
        foreach ([['Wysłane',$stats['sent']],['Błędy',$stats['failed']],['Pominięte',$stats['skipped']],['W kolejce',$stats['queued']],['Kliknięcia',$stats['clicked']]] as $stat) echo '<div class="card" style="min-width:130px;padding:14px"><strong style="font-size:22px;display:block">'.(int)$stat[1].'</strong>'.esc_html($stat[0]).'</div>';
        echo '</div>';

        $rows = $wpdb->get_results($wpdb->prepare("SELECT r.*,m.first_name,m.last_name,m.email contact_email FROM {$recipients} r LEFT JOIN {$contacts} m ON m.id=r.contact_id WHERE r.campaign_id=%d ORDER BY r.id ASC", $campaignId));
        echo '<table class="widefat striped"><thead><tr><th>Odbiorca</th><th>Rok</th><th>Status</th><th>Brany pod uwagę</th><th>Wysłano</th><th>Kliknięcie</th><th>Snapshot</th></tr></thead><tbody>';
        foreach ((array)$rows as $row) {
            $contactUrl = self::contact_history_url((int)$row->contact_id);
            echo '<tr><td><a href="'.esc_url($contactUrl).'"><strong>'.esc_html((string)($row->contact_email ?: $row->email)).'</strong></a><br>'.esc_html(trim((string)$row->first_name.' '.(string)$row->last_name)).'</td><td>'.(int)$row->mailing_year.'</td>';
            echo '<td>'.esc_html(self::recipient_status_label((string)$row->status)).($row->error_message ? '<br><small>'.esc_html((string)$row->error_message).'</small>' : '').'</td><td>'.esc_html(wp_date('d.m.Y H:i',strtotime((string)$row->considered_at))).'</td><td>'.esc_html($row->sent_at ? wp_date('d.m.Y H:i',strtotime((string)$row->sent_at)) : '—').'</td><td>'.esc_html($row->clicked_at ? wp_date('d.m.Y H:i',strtotime((string)$row->clicked_at)) : '—').'</td>';
            echo '<td>'.self::message_preview_html((string)$row->subject_snapshot,(string)$row->body_snapshot,'Dokładna wiadomość').'</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function recipient_status_label(string $status): string {
        return ['queued'=>'W kolejce','sent'=>'Wysłano','failed'=>'Błąd','skipped'=>'Pominięto','sending'=>'Wysyłanie'][$status] ?? $status;
    }

    private static function message_preview_html(string $subject, string $html, string $label): string {
        if ($html === '') return '—';
        $id = 'bcs-mail-preview-'.substr(md5($subject.$html),0,12);
        return '<details><summary style="cursor:pointer">'.esc_html($label).'</summary><p><strong>Temat:</strong> '.esc_html($subject).'</p><iframe id="'.esc_attr($id).'" sandbox="" srcdoc="'.esc_attr($html).'" style="width:720px;max-width:100%;height:520px;border:1px solid #ccd0d4;background:#fff"></iframe></details>';
    }
}
