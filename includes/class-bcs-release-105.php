<?php
if (!defined('ABSPATH')) exit;

/**
 * 1.05 – spójny widok szczegółów kampanii oraz finalna data zawarcia umowy.
 *
 * Finalna data umowy pochodzi z accepted_at podpisu Organizatora, bo jest to
 * ostatni etap procesu podpisywania. Korekta dotyczy wyłącznie wersji signed.
 */
final class BCS_Release_105 {
    private const CAMPAIGN_PAGE = 'bcs-mailing-campaign-history';
    private const DATE_MIGRATION_OPTION = 'bcs_release_105_final_agreement_dates_repaired';
    private const PROOF_START = '<!-- BCS-AGREEMENT-PROOF-051-START -->';

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'replace_campaign_history_renderer'], 1700);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets'], 1800);
        add_action('admin_init', [__CLASS__, 'repair_existing_final_dates_once'], 13);
        register_shutdown_function([__CLASS__, 'repair_after_organizer_signature']);
    }

    public static function replace_campaign_history_renderer(): void {
        if (!function_exists('get_plugin_page_hookname')) return;
        $hook = get_plugin_page_hookname(self::CAMPAIGN_PAGE, '');
        if ($hook === '') return;
        remove_action($hook, [BCS_Release_098::class, 'campaign_history_page']);
        add_action($hook, [__CLASS__, 'campaign_history_page']);
    }

    public static function assets(string $hook): void {
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if ($page !== self::CAMPAIGN_PAGE) return;
        wp_enqueue_style('bcs-mailing-101', BCS_URL.'assets/mailing-101.css', ['bcs-mailing-100'], BCS_VERSION);
        wp_enqueue_style('bcs-mailing-102', BCS_URL.'assets/mailing-102.css', ['bcs-mailing-100'], BCS_VERSION);
        wp_enqueue_style('bcs-mailing-105', BCS_URL.'assets/mailing-105.css', ['bcs-mailing-101'], BCS_VERSION);
    }

    private static function status_label(string $status): string {
        return [
            'draft'=>'Szkic','scheduled'=>'Zaplanowana','queued'=>'W kolejce','sending'=>'Wysyłanie',
            'paused'=>'Wstrzymana','completed'=>'Zakończona','sent'=>'Wysłano','failed'=>'Błąd','skipped'=>'Pominięto'
        ][$status] ?? $status;
    }

    private static function audience_label(object $campaign): string {
        $type = (string)($campaign->audience_type ?? '');
        $value = trim((string)($campaign->audience_value ?? ''));
        $labels = [
            'all_consented'=>'Wszyscy z aktywną zgodą',
            'imported'=>'Zaimportowana baza ze zgodą',
            'registration_year'=>'Rodzice uczestników z roku',
            'camp'=>'Rodzice z konkretnego turnusu',
        ];
        $label = $labels[$type] ?? ($type !== '' ? $type : '—');
        return $value !== '' ? $label.' · '.$value : $label;
    }

    private static function date_label(?string $value): string {
        $value = trim((string)$value);
        if ($value === '') return '—';
        $ts = strtotime($value);
        return $ts ? wp_date('d.m.Y H:i', $ts, wp_timezone()) : '—';
    }

    private static function message_preview_html(string $subject, string $html): string {
        if (trim($html) === '') return '—';
        return '<details class="bcs-mail-message-preview"><summary><span class="dashicons dashicons-visibility"></span> Dokładna wiadomość</summary>'
            .'<div class="bcs-mail-message-preview-body"><p><strong>Temat:</strong> '.esc_html($subject).'</p>'
            .'<iframe sandbox="" srcdoc="'.esc_attr($html).'" title="Podgląd wysłanej wiadomości"></iframe></div></details>';
    }

    public static function campaign_history_page(): void {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $campaignId = absint($_GET['campaign_id'] ?? 0);
        $campaigns = BCS_Release_097::campaigns_table();
        $recipients = BCS_Release_097::recipients_table();
        $contacts = BCS_Release_096::contacts_table();
        $campaign = $campaignId > 0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$campaigns} WHERE id=%d", $campaignId)) : null;

        echo '<div class="wrap bcs-admin bcs-mailing-100 bcs-mailing-history-105">';
        if (!$campaign) {
            echo '<div class="notice notice-error"><p>Nie znaleziono kampanii.</p></div></div>';
            return;
        }

        $back = add_query_arg(['page'=>'bcs-mailing','tab'=>'campaigns'], admin_url('admin.php'));
        $edit = add_query_arg(['page'=>'bcs-mailing','tab'=>'campaign','campaign_id'=>$campaignId], admin_url('admin.php'));
        echo '<div class="bcs-page-head bcs-mailing-head"><div><div class="bcs-mailing-eyebrow"><span class="dashicons dashicons-megaphone"></span> Marketing e-mail</div><h1>Szczegóły kampanii</h1><p>'.esc_html((string)$campaign->name).' · kampania #'.$campaignId.'</p></div>';
        echo '<div class="bcs-mailing-head-actions"><a class="button" href="'.esc_url($back).'"><span class="dashicons dashicons-arrow-left-alt2"></span> Wróć do kampanii</a><a class="button button-primary" href="'.esc_url($edit).'"><span class="dashicons dashicons-edit"></span> Otwórz kampanię</a></div></div>';

        $stats = [];
        foreach (['queued','sending','sent','failed','skipped'] as $status) {
            $stats[$status] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$recipients} WHERE campaign_id=%d AND status=%s", $campaignId, $status));
        }
        $stats['clicked'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$recipients} WHERE campaign_id=%d AND clicked_at IS NOT NULL", $campaignId));
        $stats['total'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$recipients} WHERE campaign_id=%d", $campaignId));
        $done = $stats['sent'] + $stats['failed'] + $stats['skipped'];
        $progress = $stats['total'] > 0 ? min(100, (int)round(($done / $stats['total']) * 100)) : 0;

        echo '<div class="bcs-mail-history-kpis">';
        foreach ([
            ['Wysłane',$stats['sent'],'dashicons-email-alt','is-ok'],
            ['W kolejce',$stats['queued'] + $stats['sending'],'dashicons-clock','is-info'],
            ['Kliknięcia',$stats['clicked'],'dashicons-external','is-info'],
            ['Błędy',$stats['failed'],'dashicons-warning','is-danger'],
            ['Pominięte',$stats['skipped'],'dashicons-minus','is-muted'],
        ] as $item) {
            echo '<div class="bcs-mail-history-kpi '.esc_attr($item[3]).'"><span class="dashicons '.esc_attr($item[2]).'"></span><div><small>'.esc_html($item[0]).'</small><strong>'.(int)$item[1].'</strong></div></div>';
        }
        echo '</div>';

        echo '<section class="bcs-mail-card bcs-mail-campaign-overview-105"><div class="bcs-mail-card-head"><div><h2>'.esc_html((string)$campaign->name).'</h2><p>'.esc_html((string)$campaign->subject).'</p></div><span class="bcs-mail-badge status-'.esc_attr((string)$campaign->status).'">'.esc_html(self::status_label((string)$campaign->status)).'</span></div>';
        echo '<div class="bcs-mail-progress-track"><i style="width:'.$progress.'%"></i></div><div class="bcs-mail-overview-progress"><strong>'.$progress.'%</strong><span>Zrealizowano '.$done.' z '.$stats['total'].' odbiorców</span></div>';
        echo '<div class="bcs-mail-readonly-grid bcs-mail-readonly-grid-105">';
        foreach ([
            ['Odbiorcy',self::audience_label($campaign)],
            ['Utworzono',self::date_label((string)$campaign->created_at)],
            ['Zaplanowano',self::date_label((string)$campaign->scheduled_at)],
            ['Rozpoczęto',self::date_label((string)$campaign->started_at)],
            ['Zakończono',self::date_label((string)$campaign->completed_at)],
            ['CTA',trim((string)$campaign->cta_label) !== '' ? (string)$campaign->cta_label : '—'],
        ] as $meta) echo '<div><small>'.esc_html($meta[0]).'</small><strong>'.esc_html($meta[1]).'</strong></div>';
        echo '</div></section>';

        $rows = $wpdb->get_results($wpdb->prepare("SELECT r.*,m.first_name,m.last_name,m.email contact_email FROM {$recipients} r LEFT JOIN {$contacts} m ON m.id=r.contact_id WHERE r.campaign_id=%d ORDER BY r.id ASC", $campaignId));
        echo '<section class="bcs-mail-card"><div class="bcs-mail-card-head"><div><h2>Odbiorcy kampanii</h2><p>Dokładny status, terminy i snapshot wiadomości dla każdego adresu.</p></div><span class="bcs-mail-badge is-info">'.count((array)$rows).' odbiorców</span></div>';
        echo '<div class="bcs-table-wrap"><table class="widefat bcs-mail-table bcs-mail-history-table-105"><thead><tr><th>Odbiorca</th><th>Rok</th><th>Status</th><th>Brany pod uwagę</th><th>Wysłano</th><th>Kliknięcie</th><th>Wiadomość</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="7"><div class="bcs-mail-empty">Kampania nie ma jeszcze odbiorców.</div></td></tr>';
        foreach ((array)$rows as $row) {
            $contactUrl = BCS_Release_098::contact_history_url((int)$row->contact_id);
            $email = (string)($row->contact_email ?: $row->email);
            $name = trim((string)$row->first_name.' '.(string)$row->last_name);
            echo '<tr><td><a href="'.esc_url($contactUrl).'"><strong>'.esc_html($email).'</strong></a>'.($name !== '' ? '<small>'.esc_html($name).'</small>' : '').'</td><td>'.(int)$row->mailing_year.'</td>';
            echo '<td><span class="bcs-mail-badge status-'.esc_attr((string)$row->status).'">'.esc_html(self::status_label((string)$row->status)).'</span>'.($row->error_message ? '<small class="bcs-mail-error-text">'.esc_html((string)$row->error_message).'</small>' : '').'</td>';
            echo '<td>'.esc_html(self::date_label((string)$row->considered_at)).'</td><td>'.esc_html(self::date_label((string)$row->sent_at)).'</td><td>'.esc_html(self::date_label((string)$row->clicked_at)).'</td>';
            echo '<td>'.self::message_preview_html((string)$row->subject_snapshot,(string)$row->body_snapshot).'</td></tr>';
        }
        echo '</tbody></table></div></section></div>';
    }

    public static function repair_after_organizer_signature(): void {
        $action = sanitize_key((string)($_REQUEST['action'] ?? ''));
        if ($action !== 'bcs_046_organizer_otp_verify') return;
        $registrationId = absint($_REQUEST['registration_id'] ?? 0);
        if ($registrationId > 0) self::ensure_final_agreement_date($registrationId);
    }

    public static function repair_existing_final_dates_once(): void {
        if (get_option(self::DATE_MIGRATION_OPTION)) return;
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $ids = $wpdb->get_col("SELECT id FROM ".BCS_DB::table('registrations')." WHERE agreement_status='accepted' AND agreement_id IS NOT NULL AND agreement_id>0");
        foreach ((array)$ids as $id) self::ensure_final_agreement_date((int)$id);
        update_option(self::DATE_MIGRATION_OPTION, 1, false);
    }

    private static function organizer_final_date(int $agreementId): string {
        $proof = get_option('bcs_org_proof_'.$agreementId, []);
        if (!is_array($proof) || trim((string)($proof['accepted_at'] ?? '')) === '') return '';
        try {
            $dt = new DateTimeImmutable((string)$proof['accepted_at'], BCS_Utils::timezone());
            return $dt->format('d-m-Y');
        } catch (Throwable $e) {
            return '';
        }
    }

    public static function replace_agreement_date(string $html, string $date): string {
        if ($html === '' || $date === '') return $html;
        $html = str_replace(['{{AGREEMENT_DATE}}','dd-MM-YYYY','DD-MM-YYYY'], $date, $html);
        $count = 0;
        $next = preg_replace(
            '~(Zawarta\s+(?:w\s+dniu|dnia)\s*(?:<[^>]+>\s*)?)(?:\d{2}[.\/-]\d{2}[.\/-]\d{4}|dd[.\/-]mm[.\/-]yyyy|—)~iu',
            '${1}'.$date,
            $html,
            1,
            $count
        );
        return $count > 0 && is_string($next) ? $next : $html;
    }

    private static function signed_body(string $html): string {
        $contentMarker = '<div class="bcs-document-content">';
        $start = strpos($html, $contentMarker);
        if ($start === false) return '';
        $start += strlen($contentMarker);
        $end = strpos($html, self::PROOF_START, $start);
        if ($end === false || $end <= $start) return '';
        return substr($html, $start, $end - $start);
    }

    public static function ensure_final_agreement_date(int $registrationId): bool {
        if ($registrationId <= 0) return false;
        global $wpdb;
        $registration = $wpdb->get_row($wpdb->prepare("SELECT id,agreement_id,agreement_status FROM ".BCS_DB::table('registrations')." WHERE id=%d", $registrationId));
        if (!$registration || (string)$registration->agreement_status !== 'accepted' || empty($registration->agreement_id)) return false;
        $agreementId = (int)$registration->agreement_id;
        $date = self::organizer_final_date($agreementId);
        if ($date === '') return false;

        $versions = BCS_DB::table('agreement_versions');
        $version = $wpdb->get_row($wpdb->prepare("SELECT id,html,document_hash FROM {$versions} WHERE agreement_id=%d AND stage='signed' ORDER BY id DESC LIMIT 1", $agreementId));
        if (!$version || trim((string)$version->html) === '') return false;

        $before = (string)$version->html;
        $after = self::replace_agreement_date($before, $date);
        if ($after === $before) return true;

        $body = self::signed_body($after);
        $newHash = $body !== '' ? hash('sha256', $body) : (string)$version->document_hash;
        $oldHash = trim((string)$version->document_hash);
        if ($body !== '' && $oldHash !== '' && $oldHash !== $newHash) {
            $after = str_replace($oldHash, $newHash, $after);
        }

        $updated = $wpdb->update($versions, ['html'=>$after,'document_hash'=>$newHash], ['id'=>(int)$version->id]);
        if ($updated === false) return false;
        BCS_Utils::log('agreement_final_date_set_105', [
            'organizer_accepted_date'=>$date,
            'source'=>'organizer_proof.accepted_at',
            'signed_version_id'=>(int)$version->id,
            'document_hash'=>$newHash,
        ], $registrationId, $agreementId);
        return true;
    }
}
