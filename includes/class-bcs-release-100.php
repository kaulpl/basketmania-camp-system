<?php
if (!defined('ABSPATH')) exit;

/**
 * 1.00 – nowoczesny moduł Mailing, wybór segmentów bez ręcznych ID,
 * statystyki na Dashboardzie oraz osobna skrzynka SMTP newslettera w Ustawieniach.
 */
final class BCS_Release_100 {
    private const PAGE = 'bcs-mailing';
    private const SETTINGS_ACTION = 'bcs_marketing_settings_save_100';
    private const TEST_MAILBOX_ACTION = 'bcs_marketing_test_mailbox_100';
    private const CAMPAIGN_TEST_ACTION = 'bcs_marketing_campaign_test_097';
    private const QUEUE_HOOK = 'bcs_marketing_queue_097';
    private const MAILBOX_OPTION = 'bcs_marketing_mailbox_100';
    private const NEXT_SEND_OPTION = 'bcs_marketing_next_send_at_099';
    private const FAILURE_OPTION = 'bcs_marketing_consecutive_failures_099';
    private const AUTO_PAUSE_OPTION = 'bcs_marketing_auto_paused_099';

    public static function init(): void {
        // 1.00 przejmuje kolejkę 0.99, aby móc używać osobnej skrzynki newslettera.
        remove_action(self::QUEUE_HOOK, [BCS_Release_099::class, 'run_queue'], 20);
        add_action(self::QUEUE_HOOK, [__CLASS__, 'run_queue'], 30);

        // Test kampanii również musi korzystać z dokładnie tego samego transportu co newsletter.
        remove_action('admin_post_'.self::CAMPAIGN_TEST_ACTION, [BCS_Release_097::class, 'send_test']);
        add_action('admin_post_'.self::CAMPAIGN_TEST_ACTION, [__CLASS__, 'send_campaign_test']);

        add_action('admin_menu', [__CLASS__, 'replace_admin_pages'], 999);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets'], 1100);
        add_action('admin_footer', [__CLASS__, 'dashboard_footer_widget'], 1000);
        add_action('admin_post_'.self::SETTINGS_ACTION, [__CLASS__, 'save_newsletter_settings']);
        add_action('admin_post_'.self::TEST_MAILBOX_ACTION, [__CLASS__, 'test_newsletter_mailbox']);
    }

    public static function replace_admin_pages(): void {
        remove_submenu_page('bcs-dashboard', self::PAGE);
        add_submenu_page('bcs-dashboard', 'Mailing', 'Mailing', 'manage_options', self::PAGE, [__CLASS__, 'mailing_page']);

        // Dostarczalność 0.99 pozostaje mechanizmem backendowym, ale konfiguracja trafia do głównych Ustawień.
        remove_submenu_page('bcs-dashboard', 'bcs-mailing-deliverability');

        remove_submenu_page('bcs-dashboard', 'bcs-settings');
        add_submenu_page('bcs-dashboard', 'Ustawienia', 'Ustawienia', 'manage_options', 'bcs-settings', [__CLASS__, 'settings_page']);
    }

    public static function assets(string $hook): void {
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if (!in_array($page, ['bcs-mailing','bcs-settings','bcs-dashboard','bcs-mailing-contact-history','bcs-mailing-campaign-history'], true)) return;
        wp_enqueue_style('bcs-mailing-100', BCS_URL.'assets/mailing-100.css', ['bcs-admin'], BCS_VERSION);
        wp_enqueue_script('bcs-mailing-100', BCS_URL.'assets/mailing-100.js', ['bcs-admin'], BCS_VERSION, true);
    }

    private static function url(string $tab = '', array $extra = []): string {
        $args = ['page'=>self::PAGE];
        if ($tab !== '') $args['tab'] = $tab;
        return add_query_arg(array_merge($args, $extra), admin_url('admin.php'));
    }

    private static function esc_date(?string $date, bool $withYear = true): string {
        if (!$date) return '—';
        $ts = strtotime($date);
        if (!$ts) return '—';
        return wp_date($withYear ? 'd.m.Y' : 'd.m', $ts, wp_timezone());
    }

    private static function status_label(string $status): string {
        return [
            'draft'=>'Szkic','scheduled'=>'Zaplanowana','queued'=>'W kolejce','sending'=>'Wysyłanie',
            'paused'=>'Wstrzymana','completed'=>'Zakończona','sent'=>'Wysłano','failed'=>'Błąd',
            'skipped'=>'Pominięto'
        ][$status] ?? $status;
    }

    private static function source_label(string $source): string {
        return [
            'import'=>'Import bazy','registration_form'=>'Formularz zgłoszeniowy',
            'legacy_registration'=>'Starsze zgłoszenie','unsubscribe'=>'Wypisanie'
        ][$source] ?? ($source !== '' ? $source : '—');
    }

    public static function mailing_stats(): array {
        global $wpdb;
        $contacts = BCS_Release_096::contacts_table();
        $recipients = BCS_Release_097::recipients_table();
        $campaigns = BCS_Release_097::campaigns_table();
        $today = wp_date('Y-m-d', BCS_Utils::timestamp(), wp_timezone());
        $month = wp_date('Y-m', BCS_Utils::timestamp(), wp_timezone());
        $year = wp_date('Y', BCS_Utils::timestamp(), wp_timezone());

        $totalContacts = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$contacts}");
        $consented = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$contacts} WHERE consent_status='yes' AND status='active'");
        $unsubscribed = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$contacts} WHERE status='unsubscribed'");
        $sentMonth = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$recipients} WHERE status='sent' AND sent_at LIKE %s", $month.'%'));
        $sentYear = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$recipients} WHERE status='sent' AND sent_at LIKE %s", $year.'%'));
        $sentToday = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$recipients} WHERE status='sent' AND sent_at LIKE %s", $today.'%'));
        $clickedMonth = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$recipients} WHERE status='sent' AND clicked_at IS NOT NULL AND sent_at LIKE %s", $month.'%'));
        $failedMonth = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$recipients} WHERE status='failed' AND updated_at LIKE %s", $month.'%'));
        $campaignCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$campaigns}");
        $activeCampaigns = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$campaigns} WHERE status IN ('scheduled','queued','sending','paused')");
        $ctr = $sentMonth > 0 ? round(($clickedMonth / $sentMonth) * 100, 1) : 0.0;
        $failureBase = $sentMonth + $failedMonth;
        $failureRate = $failureBase > 0 ? round(($failedMonth / $failureBase) * 100, 1) : 0.0;

        $delivery = BCS_Release_099::settings();
        $mailbox = self::mailbox_settings();
        $from = self::effective_from_email();
        $domain = BCS_Release_099::email_domain($from);
        $selector = sanitize_key((string)($mailbox['dkim_selector'] ?: ($delivery['dkim_selector'] ?? '')));
        $spf = $domain !== '' ? BCS_Release_099::txt_contains($domain, 'v=spf1') : false;
        $dmarc = $domain !== '' ? BCS_Release_099::txt_contains('_dmarc.'.$domain, 'v=DMARC1') : false;
        $dkim = ($domain !== '' && $selector !== '') ? BCS_Release_099::txt_contains($selector.'._domainkey.'.$domain, 'v=DKIM1') : null;

        return [
            'total_contacts'=>$totalContacts,'consented'=>$consented,'unsubscribed'=>$unsubscribed,
            'sent_month'=>$sentMonth,'sent_year'=>$sentYear,'sent_today'=>$sentToday,
            'clicked_month'=>$clickedMonth,'ctr'=>$ctr,'failed_month'=>$failedMonth,'failure_rate'=>$failureRate,
            'campaign_count'=>$campaignCount,'active_campaigns'=>$activeCampaigns,
            'daily_limit'=>(int)($delivery['daily_limit'] ?? 10),
            'next_send'=>(int)get_option(self::NEXT_SEND_OPTION, 0),
            'auto_paused'=>(bool)get_option(self::AUTO_PAUSE_OPTION, false),
            'domain'=>$domain,'spf'=>$spf,'dmarc'=>$dmarc,'dkim'=>$dkim,'dkim_selector'=>$selector,
            'transport'=>self::marketing_transport_label(),
        ];
    }

    public static function mailing_page(): void {
        if (!current_user_can('manage_options')) return;
        $tab = sanitize_key(wp_unslash($_GET['tab'] ?? 'contacts'));
        if (!in_array($tab, ['contacts','import','campaigns','new-campaign','campaign'], true)) $tab = 'contacts';
        $stats = self::mailing_stats();

        echo '<div class="wrap bcs-admin bcs-mailing-100">';
        echo '<div class="bcs-page-head bcs-mailing-head"><div><div class="bcs-mailing-eyebrow"><span class="dashicons dashicons-email-alt2"></span> Marketing e-mail</div><h1>Mailing Basketmania Camp</h1><p>Kampanie, kontakty, zgody i historia wysyłek w jednym miejscu.</p></div><div class="bcs-mailing-head-actions"><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-settings#bcs-newsletter-settings')).'"><span class="dashicons dashicons-admin-generic"></span> Ustawienia newslettera</a><a class="button button-primary" href="'.esc_url(self::url('new-campaign')).'"><span class="dashicons dashicons-plus-alt2"></span> Nowa kampania</a></div></div>';

        self::render_top_stats($stats);
        self::render_tabs($tab);

        if (!empty($_GET['campaign_saved'])) echo '<div class="notice notice-success inline"><p>Kampania została zapisana.</p></div>';
        if (!empty($_GET['campaign_launched'])) echo '<div class="notice notice-success inline"><p>Kampania została przygotowana i dodana do kolejki.</p></div>';
        if (!empty($_GET['test_sent'])) echo '<div class="notice notice-success inline"><p>Wiadomość testowa została wysłana przez skrzynkę newslettera.</p></div>';

        if ($tab === 'contacts') self::render_contacts();
        elseif ($tab === 'import') self::render_import();
        elseif ($tab === 'campaigns') self::render_campaigns();
        else self::render_campaign_form(absint($_GET['campaign_id'] ?? 0));
        echo '</div>';
    }

    private static function render_top_stats(array $s): void {
        $items = [
            ['Aktywne zgody', number_format_i18n((int)$s['consented']), 'dashicons-groups', 'Kontakty, do których można wysyłać kampanie'],
            ['Wysłano w miesiącu', number_format_i18n((int)$s['sent_month']), 'dashicons-email-alt', 'Tylko faktycznie wysłane wiadomości'],
            ['CTR kliknięć', number_format_i18n((float)$s['ctr'], 1).'%', 'dashicons-chart-line', 'Kliknięcia CTA / wysłane w tym miesiącu'],
            ['Wypisani', number_format_i18n((int)$s['unsubscribed']), 'dashicons-dismiss', 'Kontakty wyłączone z kolejnych kampanii'],
        ];
        echo '<div class="bcs-mailing-kpis">';
        foreach ($items as $item) echo '<div class="bcs-mailing-kpi"><span class="dashicons '.esc_attr($item[2]).'"></span><div><small>'.esc_html($item[0]).'</small><strong>'.esc_html((string)$item[1]).'</strong><em>'.esc_html($item[3]).'</em></div></div>';
        echo '</div>';
    }

    private static function render_tabs(string $active): void {
        $mapped = $active === 'new-campaign' || $active === 'campaign' ? 'campaigns' : $active;
        $tabs = ['contacts'=>['Kontakty','dashicons-groups'],'import'=>['Import bazy','dashicons-upload'],'campaigns'=>['Kampanie','dashicons-megaphone']];
        echo '<nav class="bcs-mailing-tabs" aria-label="Nawigacja mailingu">';
        foreach ($tabs as $key=>$data) echo '<a class="'.($mapped === $key ? 'is-active' : '').'" href="'.esc_url(self::url($key)).'"><span class="dashicons '.esc_attr($data[1]).'"></span>'.esc_html($data[0]).'</a>';
        echo '</nav>';
    }

    private static function render_contacts(): void {
        global $wpdb;
        $table = BCS_Release_096::contacts_table();
        $q = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
        $where = '';
        if ($q !== '') {
            $like = '%'.$wpdb->esc_like($q).'%';
            $where = $wpdb->prepare(' WHERE email LIKE %s OR first_name LIKE %s OR last_name LIKE %s', $like, $like, $like);
        }
        $rows = $wpdb->get_results("SELECT * FROM {$table}{$where} ORDER BY updated_at DESC,id DESC LIMIT 300");

        if (!empty($_GET['imported'])) echo '<div class="notice notice-success inline"><p>Import zakończony. Dodano lub zaktualizowano '.esc_html((string)absint($_GET['imported'])).' kontaktów.</p></div>';
        echo '<section class="bcs-mail-card"><div class="bcs-mail-card-head"><div><h2>Baza odbiorców</h2><p>Każdy adres ma własny status zgody, źródło i historię kampanii.</p></div><form method="get" class="bcs-mail-search"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'"><input type="hidden" name="tab" value="contacts"><input type="search" name="q" value="'.esc_attr($q).'" placeholder="Szukaj e-maila lub nazwiska"><button class="button">Szukaj</button></form></div>';
        echo '<div class="bcs-table-wrap"><table class="widefat bcs-mail-table"><thead><tr><th>Kontakt</th><th>Zgoda</th><th>Źródło</th><th>Data zgody</th><th>Status</th><th></th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="6"><div class="bcs-mail-empty">Nie znaleziono kontaktów.</div></td></tr>';
        foreach ((array)$rows as $row) {
            $history = class_exists('BCS_Release_098') ? BCS_Release_098::contact_history_url((int)$row->id) : '';
            $name = trim((string)$row->first_name.' '.(string)$row->last_name);
            echo '<tr><td><strong>'.esc_html((string)$row->email).'</strong>'.($name !== '' ? '<small>'.esc_html($name).'</small>' : '').'</td>';
            echo '<td>'.($row->consent_status === 'yes' ? '<span class="bcs-mail-badge is-ok">TAK</span>' : '<span class="bcs-mail-badge is-muted">NIE</span>').'</td>';
            echo '<td>'.esc_html(self::source_label((string)$row->consent_source)).'</td><td>'.esc_html($row->consent_at ? BCS_Utils::format_datetime((string)$row->consent_at) : '—').'</td>';
            echo '<td>'.($row->status === 'unsubscribed' ? '<span class="bcs-mail-badge is-danger">Wypisany</span>' : '<span class="bcs-mail-badge is-ok-soft">Aktywny</span>').'</td>';
            echo '<td class="bcs-mail-actions">'.($history ? '<a class="button button-small" href="'.esc_url($history).'">Historia</a>' : '').'</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private static function render_import(): void {
        echo '<section class="bcs-mail-card bcs-mail-import"><div class="bcs-mail-card-head"><div><h2>Import bazy e-mail</h2><p>CSV lub TXT z Excela, arkusza Google albo prostą listą adresów.</p></div><span class="bcs-mail-badge is-info">Import = zgoda TAK</span></div>';
        echo '<div class="bcs-mail-info"><span class="dashicons dashicons-info-outline"></span><div><strong>Obsługiwane formaty</strong><p><code>email;imie;nazwisko</code>, <code>email,first_name,last_name</code>, tabulator albo jeden adres e-mail w każdym wierszu. Duplikaty są automatycznie łączone.</p></div></div>';
        echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'" class="bcs-mail-import-form">';
        echo '<input type="hidden" name="action" value="bcs_marketing_import_096">'; wp_nonce_field('bcs_marketing_import_096');
        echo '<label class="bcs-mail-dropzone" for="bcs-mailing-file-100"><span class="dashicons dashicons-upload"></span><strong>Wybierz plik CSV lub TXT</strong><small>maksymalnie 5 MB</small><input id="bcs-mailing-file-100" type="file" name="marketing_file" accept=".csv,.txt,text/csv,text/plain" required></label>';
        echo '<div class="bcs-form-actions"><button class="button button-primary button-hero">Importuj kontakty</button></div></form></section>';
    }

    private static function render_campaigns(): void {
        global $wpdb;
        $campaigns = BCS_Release_097::campaigns_table();
        $recipients = BCS_Release_097::recipients_table();
        $rows = $wpdb->get_results("SELECT c.*,
            (SELECT COUNT(*) FROM {$recipients} r WHERE r.campaign_id=c.id) recipient_count,
            (SELECT COUNT(*) FROM {$recipients} r WHERE r.campaign_id=c.id AND r.status='sent') sent_count,
            (SELECT COUNT(*) FROM {$recipients} r WHERE r.campaign_id=c.id AND r.status='failed') failed_count,
            (SELECT COUNT(*) FROM {$recipients} r WHERE r.campaign_id=c.id AND r.clicked_at IS NOT NULL) click_count
            FROM {$campaigns} c ORDER BY c.id DESC LIMIT 250");
        echo '<section class="bcs-mail-card"><div class="bcs-mail-card-head"><div><h2>Kampanie</h2><p>Wysyłki zaplanowane, aktywne i zakończone wraz ze statystykami.</p></div><a class="button button-primary" href="'.esc_url(self::url('new-campaign')).'">Nowa kampania</a></div>';
        echo '<div class="bcs-table-wrap"><table class="widefat bcs-mail-table"><thead><tr><th>Kampania</th><th>Odbiorcy</th><th>Wysłano</th><th>CTR</th><th>Błędy</th><th>Status</th><th></th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="7"><div class="bcs-mail-empty">Nie utworzono jeszcze żadnej kampanii.</div></td></tr>';
        foreach ((array)$rows as $row) {
            $ctr = (int)$row->sent_count > 0 ? round(((int)$row->click_count/(int)$row->sent_count)*100,1) : 0;
            $edit = self::url('campaign', ['campaign_id'=>(int)$row->id]);
            $details = class_exists('BCS_Release_098') ? BCS_Release_098::campaign_history_url((int)$row->id) : $edit;
            echo '<tr><td><strong>'.esc_html((string)$row->name).'</strong><small>'.esc_html((string)$row->subject).'</small></td><td>'.number_format_i18n((int)$row->recipient_count).'</td><td>'.number_format_i18n((int)$row->sent_count).'</td><td>'.esc_html(number_format_i18n($ctr,1)).'%</td><td>'.number_format_i18n((int)$row->failed_count).'</td><td><span class="bcs-mail-badge status-'.esc_attr((string)$row->status).'">'.esc_html(self::status_label((string)$row->status)).'</span></td><td class="bcs-mail-actions"><a class="button button-small" href="'.esc_url($edit).'">'.((string)$row->status === 'draft' ? 'Edytuj' : 'Otwórz').'</a> <a class="button button-small" href="'.esc_url($details).'">Szczegóły</a></td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    public static function audience_catalog(): array {
        global $wpdb;
        $all = count(BCS_Release_097::audience_contacts((object)['audience_type'=>'all_consented','audience_value'=>'']));
        $imported = count(BCS_Release_097::audience_contacts((object)['audience_type'=>'imported','audience_value'=>'']));
        $years = [];
        $yearRows = $wpdb->get_col("SELECT DISTINCT YEAR(start_date) y FROM ".BCS_DB::table('camps')." WHERE start_date IS NOT NULL AND start_date<>'' ORDER BY y DESC");
        foreach ((array)$yearRows as $year) {
            $year = (int)$year;
            if ($year < 2000) continue;
            $years[$year] = count(BCS_Release_097::audience_contacts((object)['audience_type'=>'registration_year','audience_value'=>(string)$year]));
        }
        $camps = [];
        $campRows = $wpdb->get_results("SELECT id,name,start_date,end_date,status FROM ".BCS_DB::table('camps')." ORDER BY start_date DESC,id DESC");
        foreach ((array)$campRows as $camp) {
            $camps[(int)$camp->id] = [
                'name'=>(string)$camp->name,'start_date'=>(string)$camp->start_date,'end_date'=>(string)$camp->end_date,
                'status'=>(string)$camp->status,
                'count'=>count(BCS_Release_097::audience_contacts((object)['audience_type'=>'camp','audience_value'=>(string)$camp->id])),
            ];
        }
        return ['all'=>$all,'imported'=>$imported,'years'=>$years,'camps'=>$camps];
    }

    private static function campaign(int $id): ?object {
        if ($id <= 0) return null;
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_Release_097::campaigns_table()." WHERE id=%d", $id));
    }

    private static function render_campaign_form(int $campaignId): void {
        $campaign = self::campaign($campaignId);
        $editable = !$campaign || (string)$campaign->status === 'draft';
        $catalog = self::audience_catalog();
        $type = (string)($campaign->audience_type ?? 'all_consented');
        $value = (string)($campaign->audience_value ?? '');
        $selectedCount = self::audience_count($type, $value, $catalog);
        $scheduledValue = !empty($campaign->scheduled_at) ? str_replace(' ', 'T', substr((string)$campaign->scheduled_at,0,16)) : '';

        echo '<div class="bcs-mail-campaign-head"><a href="'.esc_url(self::url('campaigns')).'">&larr; Wróć do kampanii</a><div><h2>'.($campaign ? 'Kampania #'.(int)$campaign->id : 'Nowa kampania').'</h2>'.($campaign ? '<span class="bcs-mail-badge status-'.esc_attr((string)$campaign->status).'">'.esc_html(self::status_label((string)$campaign->status)).'</span>' : '<span class="bcs-mail-badge is-muted">Nowy szkic</span>').'</div></div>';

        if ($editable) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="bcs-mail-campaign-form">';
            echo '<input type="hidden" name="action" value="bcs_marketing_campaign_save_097"><input type="hidden" name="campaign_id" value="'.(int)$campaignId.'">'; wp_nonce_field('bcs_marketing_campaign_save_097_'.$campaignId);

            echo '<section class="bcs-mail-card"><div class="bcs-mail-card-head"><div><h2>1. Podstawy kampanii</h2><p>Nazwa wewnętrzna i to, co zobaczy odbiorca w skrzynce.</p></div></div><div class="bcs-mail-form-grid">';
            echo '<label><span>Nazwa wewnętrzna</span><input name="name" value="'.esc_attr((string)($campaign->name ?? '')).'" placeholder="np. Start zapisów 2027" required></label>';
            echo '<label><span>Temat wiadomości</span><input name="subject" value="'.esc_attr((string)($campaign->subject ?? '')).'" placeholder="Ruszyły zapisy na Basketmania Camp!" required></label>';
            echo '<label class="is-wide"><span>Preheader</span><input name="preheader" value="'.esc_attr((string)($campaign->preheader ?? '')).'" placeholder="Krótki tekst widoczny obok tematu wiadomości"><small>Pomaga odbiorcy zrozumieć treść jeszcze przed otwarciem maila.</small></label></div></section>';

            echo '<section class="bcs-mail-card"><div class="bcs-mail-card-head"><div><h2>2. Odbiorcy</h2><p>Bez ręcznego wpisywania roku ani ID turnusu. Liczby pokazują aktualną bazę z aktywną zgodą.</p></div><div class="bcs-mail-audience-count"><small>Aktualnie</small><strong data-bcs-audience-count>'.number_format_i18n($selectedCount).'</strong><span>e-maili</span></div></div>';
            echo '<div class="bcs-mail-audience-builder"><label><span>Grupa odbiorców</span><select name="audience_type" data-bcs-audience-type>';
            $typeOptions = [
                'all_consented'=>'Wszyscy z aktywną zgodą ('.number_format_i18n((int)$catalog['all']).')',
                'imported'=>'Zaimportowana baza ze zgodą ('.number_format_i18n((int)$catalog['imported']).')',
                'registration_year'=>'Rodzice uczestników z wybranego roku',
                'camp'=>'Rodzice z konkretnego turnusu',
            ];
            foreach ($typeOptions as $key=>$label) {
                $count = $key === 'all_consented' ? (int)$catalog['all'] : ($key === 'imported' ? (int)$catalog['imported'] : -1);
                echo '<option value="'.esc_attr($key).'" data-count="'.$count.'" '.selected($type,$key,false).'>'.esc_html($label).'</option>';
            }
            echo '</select></label>';

            echo '<label data-bcs-audience-detail="registration_year" '.($type === 'registration_year' ? '' : 'hidden').'><span>Rok turnusu</span><select name="audience_value" data-bcs-audience-year '.($type === 'registration_year' ? '' : 'disabled').'>';
            if (!$catalog['years']) echo '<option value="">Brak lat w systemie</option>';
            foreach ((array)$catalog['years'] as $year=>$count) echo '<option value="'.(int)$year.'" data-count="'.(int)$count.'" '.selected($value,(string)$year,false).'>'.(int)$year.' ('.number_format_i18n((int)$count).' e-maili)</option>';
            echo '</select></label>';

            echo '<label data-bcs-audience-detail="camp" '.($type === 'camp' ? '' : 'hidden').'><span>Turnus</span><select name="audience_value" data-bcs-audience-camp '.($type === 'camp' ? '' : 'disabled').'>';
            if (!$catalog['camps']) echo '<option value="">Brak turnusów w systemie</option>';
            foreach ((array)$catalog['camps'] as $id=>$data) {
                $dates = self::esc_date($data['start_date'], false).'–'.self::esc_date($data['end_date']);
                $label = $data['name'].' · '.$dates.' ('.number_format_i18n((int)$data['count']).' e-maili)';
                echo '<option value="'.(int)$id.'" data-count="'.(int)$data['count'].'" '.selected($value,(string)$id,false).'>'.esc_html($label).'</option>';
            }
            echo '</select></label></div><p class="bcs-mail-hint"><span class="dashicons dashicons-shield-alt"></span> Każda grupa jest dodatkowo ograniczona do kontaktów ze statusem <strong>zgoda TAK + aktywny</strong>.</p></section>';

            echo '<section class="bcs-mail-card"><div class="bcs-mail-card-head"><div><h2>3. Treść wiadomości</h2><p>Możesz użyć personalizacji i przycisku prowadzącego do zapisów.</p></div></div><div class="bcs-mail-editor">';
            wp_editor((string)($campaign->body ?? ''), 'bcs_campaign_body_editor_100', ['textarea_name'=>'body','textarea_rows'=>15,'media_buttons'=>true]);
            echo '<p class="description">Zmienne: <code>{{PARENT_NAME}}</code>, <code>{{FIRST_NAME}}</code>, <code>{{UNSUBSCRIBE_URL}}</code>.</p></div><div class="bcs-mail-form-grid bcs-mail-cta"><label><span>Tekst przycisku CTA</span><input name="cta_label" value="'.esc_attr((string)($campaign->cta_label ?? 'Zobacz szczegóły')).'" placeholder="Zapisz uczestnika"></label><label><span>Adres przycisku</span><input type="url" name="cta_url" value="'.esc_attr((string)($campaign->cta_url ?? 'https://camp.basketmania.pl/')).'" placeholder="https://..."></label></div></section>';

            echo '<section class="bcs-mail-card"><div class="bcs-mail-card-head"><div><h2>4. Termin</h2><p>Możesz uruchomić kampanię od razu albo przygotować ją na później.</p></div></div><div class="bcs-mail-form-grid"><label><span>Planowana wysyłka</span><input type="datetime-local" name="scheduled_at" value="'.esc_attr($scheduledValue).'"><small>Puste pole = kolejka wystartuje po uruchomieniu kampanii. Tempo nadal respektuje limit dzienny i przerwy.</small></label></div><div class="bcs-form-actions"><button class="button button-primary button-hero">'.($campaign ? 'Zapisz zmiany' : 'Utwórz kampanię').'</button></div></section></form>';
        } else {
            $details = BCS_Release_098::campaign_history_url((int)$campaign->id);
            echo '<section class="bcs-mail-card"><div class="bcs-mail-card-head"><div><h2>'.esc_html((string)$campaign->name).'</h2><p>'.esc_html((string)$campaign->subject).'</p></div><a class="button" href="'.esc_url($details).'">Pełne szczegóły i odbiorcy</a></div><div class="bcs-mail-readonly-grid"><div><small>Segment</small><strong>'.esc_html(self::audience_label($type,$value,$catalog)).'</strong></div><div><small>Zaplanowano</small><strong>'.esc_html($campaign->scheduled_at ? BCS_Utils::format_datetime((string)$campaign->scheduled_at) : 'Od razu').'</strong></div><div><small>Status</small><strong>'.esc_html(self::status_label((string)$campaign->status)).'</strong></div></div></section>';
        }

        if ($campaign) self::render_campaign_controls($campaign);
    }

    private static function audience_count(string $type, string $value, array $catalog): int {
        if ($type === 'all_consented') return (int)$catalog['all'];
        if ($type === 'imported') return (int)$catalog['imported'];
        if ($type === 'registration_year') return (int)($catalog['years'][(int)$value] ?? 0);
        if ($type === 'camp') return (int)($catalog['camps'][(int)$value]['count'] ?? 0);
        return 0;
    }

    private static function audience_label(string $type, string $value, array $catalog): string {
        $count = self::audience_count($type,$value,$catalog);
        if ($type === 'all_consented') return 'Wszyscy z aktywną zgodą ('.$count.')';
        if ($type === 'imported') return 'Zaimportowana baza ('.$count.')';
        if ($type === 'registration_year') return 'Rodzice uczestników z roku '.(int)$value.' ('.$count.')';
        if ($type === 'camp') {
            $c = $catalog['camps'][(int)$value] ?? null;
            return $c ? $c['name'].' ('.$count.')' : 'Turnus #'.(int)$value;
        }
        return $type;
    }

    private static function render_campaign_controls(object $campaign): void {
        $id = (int)$campaign->id;
        echo '<section class="bcs-mail-card bcs-mail-launch"><div class="bcs-mail-card-head"><div><h2>Test i uruchomienie</h2><p>Najpierw wyślij wiadomość testową, a później zamroź listę odbiorców kampanii.</p></div></div><div class="bcs-mail-launch-grid">';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="bcs-mail-test-form"><input type="hidden" name="action" value="'.esc_attr(self::CAMPAIGN_TEST_ACTION).'"><input type="hidden" name="campaign_id" value="'.$id.'">'; wp_nonce_field(self::CAMPAIGN_TEST_ACTION.'_'.$id); echo '<label><span>Wyślij test na</span><div><input type="email" name="test_email" placeholder="twoj@email.pl" required><button class="button">Wyślij test</button></div></label></form>';
        if ((string)$campaign->status === 'draft') {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="bcs-mail-launch-form"><input type="hidden" name="action" value="bcs_marketing_campaign_launch_097"><input type="hidden" name="campaign_id" value="'.$id.'">'; wp_nonce_field('bcs_marketing_campaign_launch_097_'.$id); echo '<p><strong>Uruchomienie kampanii zamrozi aktualną listę odbiorców.</strong><br><small>Później dopisane kontakty nie zostaną automatycznie dodane do tej kampanii.</small></p><button class="button button-primary">Uruchom kampanię</button></form>';
        } elseif (in_array((string)$campaign->status, ['queued','sending','scheduled'], true)) {
            self::campaign_status_form('bcs_marketing_campaign_pause_097',$id,'Wstrzymaj kampanię');
        } elseif ((string)$campaign->status === 'paused') {
            self::campaign_status_form('bcs_marketing_campaign_resume_097',$id,'Wznów kampanię','primary');
        }
        echo '</div></section>';
    }

    private static function campaign_status_form(string $action, int $id, string $label, string $style = ''): void {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="bcs-mail-status-form"><input type="hidden" name="action" value="'.esc_attr($action).'"><input type="hidden" name="campaign_id" value="'.$id.'">'; wp_nonce_field($action.'_'.$id); echo '<button class="button '.($style === 'primary' ? 'button-primary' : '').'">'.esc_html($label).'</button></form>';
    }

    public static function mailbox_defaults(): array {
        return [
            'transport'=>'inherit','smtp_host'=>'','smtp_port'=>587,'smtp_encryption'=>'tls','smtp_auth'=>1,
            'smtp_username'=>'','smtp_password'=>'','dkim_selector'=>'',
        ];
    }

    public static function mailbox_settings(): array {
        return array_merge(self::mailbox_defaults(), (array)get_option(self::MAILBOX_OPTION, []));
    }

    public static function effective_from_email(): string {
        $delivery = BCS_Release_099::settings();
        $base = (array)get_option('bcs_settings', []);
        return sanitize_email((string)($delivery['marketing_from_email'] ?: ($base['mail_from_email'] ?? $base['company_email'] ?? get_option('admin_email'))));
    }

    public static function marketing_transport_label(): string {
        $m = self::mailbox_settings();
        return ($m['transport'] ?? 'inherit') === 'smtp' ? 'Osobna skrzynka SMTP newslettera' : 'Skrzynka systemowa';
    }

    public static function settings_page(): void {
        ob_start();
        BCS_Admin::settings();
        $html = (string)ob_get_clean();
        $section = self::newsletter_settings_html();
        $pos = strrpos($html, '</div>');
        if ($pos === false) echo $html.$section;
        else echo substr($html,0,$pos).$section.substr($html,$pos);
    }

    private static function newsletter_settings_html(): string {
        $delivery = BCS_Release_099::settings();
        $m = self::mailbox_settings();
        $stats = self::mailing_stats();
        $base = (array)get_option('bcs_settings', []);
        $configError = self::mailbox_configuration_error();
        ob_start();
        echo '<section class="bcs-panel bcs-newsletter-settings" id="bcs-newsletter-settings"><div class="bcs-panel-head"><div><h2>Newsletter / mailing promocyjny</h2><p>Osobna skrzynka, reputacja domeny i bezpieczne tempo kampanii.</p></div><a class="button" href="'.esc_url(self::url('campaigns')).'">Przejdź do Mailingu</a></div>';
        if (!empty($_GET['newsletter_saved'])) echo '<div class="notice notice-success inline"><p>Ustawienia newslettera zostały zapisane.</p></div>';
        if (!empty($_GET['newsletter_test'])) {
            $ok = sanitize_key(wp_unslash($_GET['newsletter_test'])) === 'ok';
            echo '<div class="notice '.($ok ? 'notice-success' : 'notice-error').' inline"><p>'.($ok ? 'Testowy newsletter został przekazany do wysyłki.' : 'Nie udało się wysłać testowego newslettera. Sprawdź ustawienia skrzynki.').'</p></div>';
        }
        if ($configError !== '') echo '<div class="notice notice-warning inline"><p><strong>Konfiguracja newslettera:</strong> '.esc_html($configError).'</p></div>';

        echo '<div class="bcs-newsletter-health">';
        self::health_box('SPF', (bool)$stats['spf'], $stats['domain'] ?: 'brak domeny');
        self::health_box('DMARC', (bool)$stats['dmarc'], $stats['domain'] ?: 'brak domeny');
        self::health_box('DKIM', $stats['dkim'] === null ? null : (bool)$stats['dkim'], $stats['dkim'] === null ? 'podaj selektor' : $stats['domain']);
        self::health_box('One-Click', true, 'aktywne wypisanie');
        echo '</div>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="bcs-newsletter-settings-form"><input type="hidden" name="action" value="'.esc_attr(self::SETTINGS_ACTION).'">'; wp_nonce_field(self::SETTINGS_ACTION);
        echo '<details class="bcs-settings-accordion bcs-settings-section-0209" open><summary><span><span class="dashicons dashicons-email-alt2"></span><strong>Skrzynka newslettera</strong></span><span class="bcs-settings-summary">'.esc_html(self::marketing_transport_label()).'</span></summary><div class="bcs-settings-accordion-body"><div class="bcs-form-grid">';
        echo '<label class="bcs-span-2"><span>Sposób wysyłki newslettera</span><select name="marketing_transport" data-bcs-newsletter-transport><option value="inherit" '.selected((string)$m['transport'],'inherit',false).'>Korzystaj ze zwykłej skrzynki systemowej</option><option value="smtp" '.selected((string)$m['transport'],'smtp',false).'>Osobna skrzynka SMTP tylko dla newslettera</option></select><small>Osobne SMTP pozwala oddzielić reputację marketingu od umów, płatności i korespondencji operacyjnej.</small></label>';
        echo '<label><span>Nazwa nadawcy</span><input name="marketing_from_name" value="'.esc_attr((string)$delivery['marketing_from_name']).'" placeholder="Basketmania Camp"></label>';
        echo '<label><span>Adres nadawcy newslettera</span><input type="email" name="marketing_from_email" value="'.esc_attr((string)$delivery['marketing_from_email']).'" placeholder="newsletter@news.camp.basketmania.pl"><small>Puste pole = adres systemowy: '.esc_html((string)($base['mail_from_email'] ?? $base['company_email'] ?? '')).'</small></label>';
        echo '<label><span>Reply-To newslettera</span><input type="email" name="marketing_reply_to" value="'.esc_attr((string)$delivery['marketing_reply_to']).'" placeholder="kontakt@basketmania.pl"></label>';
        echo '<label><span>Selektor DKIM</span><input name="dkim_selector" value="'.esc_attr((string)($m['dkim_selector'] ?: $delivery['dkim_selector'])).'" placeholder="np. default / selector1"><small>Używany do diagnostyki rekordu DNS.</small></label></div>';

        echo '<div class="bcs-newsletter-smtp" data-bcs-newsletter-smtp '.(($m['transport'] ?? 'inherit') === 'smtp' ? '' : 'hidden').'><h3>Osobne SMTP newslettera</h3><div class="bcs-form-grid"><label><span>Serwer SMTP</span><input name="marketing_smtp_host" value="'.esc_attr((string)$m['smtp_host']).'" placeholder="smtp.twojadomena.pl"></label><label><span>Port</span><input type="number" min="1" max="65535" name="marketing_smtp_port" value="'.(int)$m['smtp_port'].'"></label><label><span>Szyfrowanie</span><select name="marketing_smtp_encryption"><option value="tls" '.selected((string)$m['smtp_encryption'],'tls',false).'>TLS / STARTTLS</option><option value="ssl" '.selected((string)$m['smtp_encryption'],'ssl',false).'>SSL</option><option value="none" '.selected((string)$m['smtp_encryption'],'none',false).'>Brak</option></select></label><label class="bcs-checkbox"><input type="checkbox" name="marketing_smtp_auth" value="1" '.checked(!empty($m['smtp_auth']),true,false).'><span>Serwer wymaga uwierzytelnienia</span></label><label><span>Login / adres skrzynki</span><input name="marketing_smtp_username" value="'.esc_attr((string)$m['smtp_username']).'" autocomplete="username"></label><label><span>Hasło / hasło aplikacji</span><input type="password" name="marketing_smtp_password" value="" autocomplete="new-password" placeholder="Pozostaw puste, aby zachować zapisane"></label></div></div></div></details>';

        echo '<details class="bcs-settings-accordion bcs-settings-section-0209" open><summary><span><span class="dashicons dashicons-clock"></span><strong>Tempo i ochrona reputacji</strong></span><span class="bcs-settings-summary">'.(int)$delivery['daily_limit'].' dziennie · '.(int)$delivery['gap_min_minutes'].'–'.(int)$delivery['gap_max_minutes'].' min</span></summary><div class="bcs-settings-accordion-body"><div class="bcs-form-grid"><label><span>Globalny limit dzienny</span><input type="number" min="1" max="500" name="daily_limit" value="'.(int)$delivery['daily_limit'].'"><small>Wspólny dla wszystkich kampanii.</small></label><label><span>Bezpiecznik kolejnych błędów</span><input type="number" min="1" max="20" name="max_consecutive_failures" value="'.(int)$delivery['max_consecutive_failures'].'"></label><label><span>Początek okna wysyłki</span><input type="number" min="0" max="23" name="window_start" value="'.(int)$delivery['window_start'].'"></label><label><span>Koniec okna wysyłki</span><input type="number" min="0" max="23" name="window_end" value="'.(int)$delivery['window_end'].'"></label><label><span>Minimalna przerwa (min)</span><input type="number" min="5" max="1440" name="gap_min_minutes" value="'.(int)$delivery['gap_min_minutes'].'"></label><label><span>Maksymalna przerwa (min)</span><input type="number" min="5" max="1440" name="gap_max_minutes" value="'.(int)$delivery['gap_max_minutes'].'"></label></div><p class="description">Losowany odstęp służy równomiernemu rozłożeniu ruchu. System nadal wysyła maksymalnie jedną wiadomość na dozwolony odstęp.</p></div></details>';
        echo '<div class="bcs-form-actions"><button class="button button-primary button-hero">Zapisz ustawienia newslettera</button></div></form>';

        echo '<div class="bcs-newsletter-test"><div><h3>Test skrzynki newslettera</h3><p>Test korzysta z osobnej konfiguracji powyżej i nie zapisuje wiadomości w korespondencji zgłoszenia.</p></div><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::TEST_MAILBOX_ACTION).'">'; wp_nonce_field(self::TEST_MAILBOX_ACTION); echo '<input type="email" name="test_email" value="'.esc_attr(get_option('admin_email')).'" required><button class="button">Wyślij test newslettera</button></form></div>';
        echo '</section>';
        return (string)ob_get_clean();
    }

    private static function health_box(string $label, ?bool $ok, string $detail): void {
        $class = $ok === null ? 'is-warn' : ($ok ? 'is-ok' : 'is-bad');
        $value = $ok === null ? 'NIE SPRAWDZONO' : ($ok ? 'OK' : 'BRAK');
        echo '<div class="'.esc_attr($class).'"><small>'.esc_html($label).'</small><strong>'.esc_html($value).'</strong><span>'.esc_html($detail).'</span></div>';
    }

    public static function save_newsletter_settings(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer(self::SETTINGS_ACTION);
        $oldDelivery = BCS_Release_099::settings();
        $oldMailbox = self::mailbox_settings();
        $min = max(5,min(1440,absint($_POST['gap_min_minutes'] ?? 45)));
        $max = max(5,min(1440,absint($_POST['gap_max_minutes'] ?? 90)));
        if ($max < $min) [$min,$max] = [$max,$min];
        $selector = sanitize_key(wp_unslash($_POST['dkim_selector'] ?? ''));
        $delivery = [
            'daily_limit'=>max(1,min(500,absint($_POST['daily_limit'] ?? 10))),
            'window_start'=>max(0,min(23,absint($_POST['window_start'] ?? 9))),
            'window_end'=>max(0,min(23,absint($_POST['window_end'] ?? 19))),
            'gap_min_minutes'=>$min,'gap_max_minutes'=>$max,
            'max_consecutive_failures'=>max(1,min(20,absint($_POST['max_consecutive_failures'] ?? 3))),
            'marketing_from_name'=>sanitize_text_field(wp_unslash($_POST['marketing_from_name'] ?? 'Basketmania Camp')),
            'marketing_from_email'=>sanitize_email(wp_unslash($_POST['marketing_from_email'] ?? '')),
            'marketing_reply_to'=>sanitize_email(wp_unslash($_POST['marketing_reply_to'] ?? '')),
            'dkim_selector'=>$selector,
        ];
        $transport = in_array($_POST['marketing_transport'] ?? 'inherit',['inherit','smtp'],true) ? (string)$_POST['marketing_transport'] : 'inherit';
        $passwordInput = (string)wp_unslash($_POST['marketing_smtp_password'] ?? '');
        $mailbox = [
            'transport'=>$transport,
            'smtp_host'=>sanitize_text_field(wp_unslash($_POST['marketing_smtp_host'] ?? '')),
            'smtp_port'=>max(1,min(65535,absint($_POST['marketing_smtp_port'] ?? 587))),
            'smtp_encryption'=>in_array($_POST['marketing_smtp_encryption'] ?? 'tls',['none','ssl','tls'],true) ? (string)$_POST['marketing_smtp_encryption'] : 'tls',
            'smtp_auth'=>isset($_POST['marketing_smtp_auth']) ? 1 : 0,
            'smtp_username'=>sanitize_text_field(wp_unslash($_POST['marketing_smtp_username'] ?? '')),
            'smtp_password'=>trim($passwordInput) !== '' ? $passwordInput : (string)($oldMailbox['smtp_password'] ?? ''),
            'dkim_selector'=>$selector,
        ];
        update_option('bcs_marketing_deliverability_099', array_merge($oldDelivery,$delivery), false);
        update_option(self::MAILBOX_OPTION, $mailbox, false);
        update_option(self::FAILURE_OPTION, 0, false);
        delete_option(self::AUTO_PAUSE_OPTION);
        wp_safe_redirect(admin_url('admin.php?page=bcs-settings&newsletter_saved=1#bcs-newsletter-settings'));
        exit;
    }

    public static function mailbox_configuration_error(): string {
        $m = self::mailbox_settings();
        if (($m['transport'] ?? 'inherit') !== 'smtp') {
            return BCS_Mailer::configuration_error((array)get_option('bcs_settings', []));
        }
        if (trim((string)$m['smtp_host']) === '') return 'Brak serwera SMTP newslettera.';
        $port = absint($m['smtp_port'] ?? 587);
        if ($port < 1 || $port > 65535) return 'Nieprawidłowy port SMTP newslettera.';
        if (!empty($m['smtp_auth']) && trim((string)$m['smtp_username']) === '') return 'Brak loginu SMTP newslettera.';
        if (!empty($m['smtp_auth']) && (string)$m['smtp_password'] === '') return 'Brak hasła SMTP newslettera.';
        if (!is_email(self::effective_from_email())) return 'Brak poprawnego adresu nadawcy newslettera.';
        return '';
    }

    public static function test_newsletter_mailbox(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer(self::TEST_MAILBOX_ACTION);
        $email = sanitize_email(wp_unslash($_POST['test_email'] ?? ''));
        if (!is_email($email)) wp_die('Podaj poprawny adres e-mail.');
        $subject = BCS_Mailer::prefix_subject('Test skrzynki newslettera');
        $html = BCS_Mailer::wrap_html_email($subject, '<h2>Test newslettera</h2><p>Jeżeli ta wiadomość dotarła, osobny transport marketingowy jest skonfigurowany poprawnie.</p>');
        $ok = self::send_marketing_mail($email,$subject,$html,admin_url('admin.php?page=bcs-settings'));
        wp_safe_redirect(admin_url('admin.php?page=bcs-settings&newsletter_test='.($ok?'ok':'error').'#bcs-newsletter-settings'));
        exit;
    }

    public static function send_campaign_test(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        $id = absint($_POST['campaign_id'] ?? 0);
        check_admin_referer(self::CAMPAIGN_TEST_ACTION.'_'.$id);
        $campaign = self::campaign($id);
        $email = sanitize_email(wp_unslash($_POST['test_email'] ?? ''));
        if (!$campaign || !is_email($email)) wp_die('Nie można wysłać wiadomości testowej.');
        $contact = (object)['email'=>$email,'first_name'=>'Test','last_name'=>'Basketmania','unsubscribe_token'=>'test'];
        $recipient = (object)['id'=>0,'click_token'=>'test'];
        [$subject,$html] = BCS_Release_097::build_recipient_message($campaign,$contact,$recipient);
        $unsubscribe = BCS_Release_096::unsubscribe_url($contact);
        $ok = self::send_marketing_mail($email,$subject,$html,$unsubscribe);
        if (!$ok) wp_die('Nie udało się wysłać wiadomości testowej przez skonfigurowaną skrzynkę newslettera.');
        wp_safe_redirect(add_query_arg(['page'=>self::PAGE,'tab'=>'campaign','campaign_id'=>$id,'test_sent'=>1],admin_url('admin.php')));
        exit;
    }

    public static function send_marketing_mail(string $to, string $subject, string $html, string $unsubscribeUrl): bool {
        $to = sanitize_email($to);
        if (!is_email($to) || !wp_http_validate_url($unsubscribeUrl)) return false;
        if (self::mailbox_configuration_error() !== '') return false;
        $delivery = BCS_Release_099::settings();
        $base = (array)get_option('bcs_settings', []);
        $reply = sanitize_email((string)($delivery['marketing_reply_to'] ?: ($base['mail_reply_to'] ?? $base['company_email'] ?? get_option('admin_email'))));
        add_filter('wp_mail_from_name',[__CLASS__,'filter_from_name'],2001);
        add_filter('wp_mail_from',[__CLASS__,'filter_from_email'],2001);
        add_action('phpmailer_init',[__CLASS__,'configure_newsletter_phpmailer'],2001);
        $headers = ['Content-Type: text/html; charset=UTF-8','List-Unsubscribe: <'.$unsubscribeUrl.'>','List-Unsubscribe-Post: List-Unsubscribe=One-Click'];
        if (is_email($reply)) $headers[] = 'Reply-To: '.$reply;
        $ok = wp_mail($to,$subject,$html,$headers);
        remove_filter('wp_mail_from_name',[__CLASS__,'filter_from_name'],2001);
        remove_filter('wp_mail_from',[__CLASS__,'filter_from_email'],2001);
        remove_action('phpmailer_init',[__CLASS__,'configure_newsletter_phpmailer'],2001);
        return (bool)$ok;
    }

    public static function filter_from_name(string $name): string {
        $s = BCS_Release_099::settings();
        $candidate = sanitize_text_field((string)($s['marketing_from_name'] ?? ''));
        return $candidate !== '' ? $candidate : $name;
    }

    public static function filter_from_email(string $email): string {
        $candidate = self::effective_from_email();
        return is_email($candidate) ? $candidate : $email;
    }

    public static function configure_newsletter_phpmailer($phpmailer): void {
        $m = self::mailbox_settings();
        if (($m['transport'] ?? 'inherit') === 'smtp' && self::mailbox_configuration_error() === '') {
            $phpmailer->isSMTP();
            $phpmailer->Host = trim((string)$m['smtp_host']);
            $phpmailer->Port = absint($m['smtp_port'] ?? 587);
            $phpmailer->SMTPAuth = !empty($m['smtp_auth']);
            if ($phpmailer->SMTPAuth) {
                $phpmailer->Username = trim((string)$m['smtp_username']);
                $phpmailer->Password = (string)$m['smtp_password'];
            }
            $encryption = in_array($m['smtp_encryption'] ?? 'tls',['none','ssl','tls'],true) ? (string)$m['smtp_encryption'] : 'tls';
            if ($encryption === 'none') { $phpmailer->SMTPSecure=''; $phpmailer->SMTPAutoTLS=false; }
            else { $phpmailer->SMTPSecure=$encryption; $phpmailer->SMTPAutoTLS=true; }
            $phpmailer->Timeout=25; $phpmailer->SMTPKeepAlive=false; $phpmailer->CharSet='UTF-8';
        }
        $from = self::effective_from_email();
        $name = self::filter_from_name('Basketmania Camp');
        if (is_email($from)) {
            try { $phpmailer->setFrom($from,$name,false); } catch (Throwable $e) {}
            $phpmailer->Sender = $from;
        }
    }

    public static function run_queue(): void {
        global $wpdb;
        $delivery = BCS_Release_099::settings();
        $campaigns = BCS_Release_097::campaigns_table();
        $recipients = BCS_Release_097::recipients_table();
        $contacts = BCS_Release_096::contacts_table();
        $now = BCS_Utils::now();
        $nowTs = BCS_Utils::timestamp();
        $wpdb->query($wpdb->prepare("UPDATE {$campaigns} SET status='queued',started_at=COALESCE(started_at,%s),updated_at=%s WHERE status='scheduled' AND scheduled_at IS NOT NULL AND scheduled_at<=%s",$now,$now,$now));
        if (get_option(self::AUTO_PAUSE_OPTION,false)) return;
        if (!self::within_send_window((int)$delivery['window_start'],(int)$delivery['window_end'])) return;
        if (self::sent_today_count() >= (int)$delivery['daily_limit']) return;
        if ((int)get_option(self::NEXT_SEND_OPTION,0) > $nowTs) return;

        $row = $wpdb->get_row("SELECT r.*,c.status campaign_status,m.consent_status,m.status contact_status,m.unsubscribe_token,m.email contact_email FROM {$recipients} r JOIN {$campaigns} c ON c.id=r.campaign_id LEFT JOIN {$contacts} m ON m.id=r.contact_id WHERE r.status='queued' AND c.status IN ('queued','sending') ORDER BY r.id ASC LIMIT 1");
        if (!$row) return;
        $wpdb->update($campaigns,['status'=>'sending','updated_at'=>$now],['id'=>(int)$row->campaign_id]);
        if ((string)$row->consent_status !== 'yes' || (string)$row->contact_status !== 'active') {
            $wpdb->update($recipients,['status'=>'skipped','error_message'=>'Brak aktywnej zgody w chwili wysyłki.','updated_at'=>$now],['id'=>(int)$row->id]);
            self::finalize_campaign_if_done((int)$row->campaign_id,$now);
            return;
        }
        $contact = (object)['unsubscribe_token'=>(string)$row->unsubscribe_token,'email'=>(string)($row->contact_email ?: $row->email)];
        $unsubscribe = BCS_Release_096::unsubscribe_url($contact);
        $ok = self::send_marketing_mail((string)$row->email,(string)$row->subject_snapshot,(string)$row->body_snapshot,$unsubscribe);
        $wpdb->update($recipients,['status'=>$ok?'sent':'failed','sent_at'=>$ok?$now:null,'mailing_year'=>(int)wp_date('Y',$nowTs,wp_timezone()),'error_message'=>$ok?null:'Transport pocztowy odrzucił wiadomość.','updated_at'=>$now],['id'=>(int)$row->id]);
        $gapMin = max(5,(int)$delivery['gap_min_minutes']); $gapMax = max($gapMin,(int)$delivery['gap_max_minutes']);
        update_option(self::NEXT_SEND_OPTION,$nowTs+(wp_rand($gapMin,$gapMax)*MINUTE_IN_SECONDS),false);
        if ($ok) update_option(self::FAILURE_OPTION,0,false);
        else {
            $failures = (int)get_option(self::FAILURE_OPTION,0)+1; update_option(self::FAILURE_OPTION,$failures,false);
            if ($failures >= (int)$delivery['max_consecutive_failures']) {
                update_option(self::AUTO_PAUSE_OPTION,1,false);
                $wpdb->query("UPDATE {$campaigns} SET status='paused',updated_at='".esc_sql($now)."' WHERE status IN ('queued','sending')");
            }
        }
        self::finalize_campaign_if_done((int)$row->campaign_id,$now);
    }

    private static function sent_today_count(): int {
        global $wpdb;
        $today = wp_date('Y-m-d',BCS_Utils::timestamp(),wp_timezone());
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".BCS_Release_097::recipients_table()." WHERE status='sent' AND sent_at LIKE %s",$today.'%'));
    }

    private static function within_send_window(int $start, int $end): bool {
        $hour = (int)wp_date('G',BCS_Utils::timestamp(),wp_timezone());
        if ($start === $end) return true;
        if ($start < $end) return $hour >= $start && $hour < $end;
        return $hour >= $start || $hour < $end;
    }

    private static function finalize_campaign_if_done(int $campaignId, string $now): void {
        global $wpdb;
        $recipients = BCS_Release_097::recipients_table();
        $campaigns = BCS_Release_097::campaigns_table();
        $pending = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$recipients} WHERE campaign_id=%d AND status IN ('queued','sending')",$campaignId));
        if ($pending === 0) $wpdb->update($campaigns,['status'=>'completed','completed_at'=>$now,'updated_at'=>$now],['id'=>$campaignId]);
    }

    public static function dashboard_footer_widget(): void {
        if (!current_user_can('manage_options')) return;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if ($page !== 'bcs-dashboard') return;
        $html = self::dashboard_widget_html();
        echo '<template id="bcs-mailing-dashboard-template">'.$html.'</template><script>(function(){var r=document.querySelector(".wrap.bcs-admin"),t=document.getElementById("bcs-mailing-dashboard-template");if(r&&t){r.appendChild(t.content.cloneNode(true));t.remove();}})();</script>';
    }

    public static function dashboard_widget_html(): string {
        $s = self::mailing_stats();
        $next = (int)$s['next_send'];
        $nextLabel = $next > time() ? wp_date('d.m H:i',$next,wp_timezone()) : 'teraz';
        ob_start();
        echo '<section class="bcs-mail-dashboard"><div class="bcs-mail-dashboard-head"><div><span class="dashicons dashicons-email-alt2"></span><div><small>Mailing promocyjny</small><h2>Newsletter</h2></div></div><div><span class="bcs-mail-badge '.($s['auto_paused']?'is-danger':'is-ok').'">'.($s['auto_paused']?'WYSYŁKA WSTRZYMANA':'SYSTEM GOTOWY').'</span> <a class="button" href="'.esc_url(self::url('campaigns')).'">Otwórz Mailing</a></div></div>';
        echo '<div class="bcs-mail-dashboard-grid">';
        $items = [
            ['Aktywne zgody',number_format_i18n((int)$s['consented'])],['Wysłano w miesiącu',number_format_i18n((int)$s['sent_month'])],
            ['CTR kliknięć',number_format_i18n((float)$s['ctr'],1).'%'],['Błędy w miesiącu',number_format_i18n((int)$s['failed_month'])],
            ['Wypisani',number_format_i18n((int)$s['unsubscribed'])],['Aktywne kampanie',number_format_i18n((int)$s['active_campaigns'])],
            ['Dzisiaj',(int)$s['sent_today'].' / '.(int)$s['daily_limit']],['Kolejna wysyłka',$nextLabel],
        ];
        foreach ($items as $item) echo '<div><span>'.esc_html($item[0]).'</span><strong>'.esc_html((string)$item[1]).'</strong></div>';
        echo '</div><div class="bcs-mail-spam-monitor"><div><strong>Monitoring dostarczalności</strong><span>Domena: '.esc_html($s['domain'] ?: 'nie ustawiono').' · '.esc_html((string)$s['transport']).'</span></div><div class="bcs-mail-health-chips">'.self::health_chip_html('SPF',(bool)$s['spf']).self::health_chip_html('DMARC',(bool)$s['dmarc']).self::health_chip_html('DKIM',$s['dkim'] === null ? null : (bool)$s['dkim']).self::health_chip_html('One-Click',true).'</div></div>';
        echo '<div class="bcs-mail-dashboard-foot"><p><strong>Zgłoszenia jako SPAM:</strong> brak wiarygodnego źródła wewnętrznego. Do pomiaru skarg potrzebna jest integracja Google Postmaster / feedback loop operatora. System monitoruje natomiast DNS, błędy transportu, wypisania i tempo wysyłki.</p><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-settings#bcs-newsletter-settings')).'">Ustawienia i reputacja</a></div></section>';
        return (string)ob_get_clean();
    }

    private static function health_chip_html(string $label, ?bool $ok): string {
        $class = $ok === null ? 'is-warn' : ($ok ? 'is-ok' : 'is-bad');
        $value = $ok === null ? '—' : ($ok ? 'OK' : 'BRAK');
        return '<span class="'.esc_attr($class).'"><b>'.esc_html($label).'</b> '.esc_html($value).'</span>';
    }
}
