<?php
if (!defined('ABSPATH')) exit;

/**
 * 1.01/1.02 – hotfix widoku Mailingu, poprawka katalogu lat i rozszerzony Dashboard.
 */
final class BCS_Release_101 {
    private const PAGE = 'bcs-mailing';
    private const PARENT = 'bcs-dashboard';

    public static function init(): void {
        // add_submenu_page() dodaje callback strony do hooka ekranu. Samo
        // remove_submenu_page() usuwa pozycję menu, ale nie usuwa tego callbacku.
        // 1.00 rejestrowało więc nowy renderer obok starego renderera 0.96.
        add_action('admin_menu', [__CLASS__, 'detach_legacy_mailing_renderer'], 1500);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets'], 1600);

        // MySQL w trybie strict nie pozwala porównywać kolumny DATE do pustego stringa.
        // Filtr dotyczy wyłącznie zapytania katalogu lat Mailingu z release 1.00.
        add_filter('query', [__CLASS__, 'fix_campaign_year_date_query'], 50);

        // 1.02 przejmuje widget Dashboardu, aby pokazać statystyki łączne i postęp kampanii.
        remove_action('admin_footer', [BCS_Release_100::class, 'dashboard_footer_widget'], 1000);
        add_action('admin_footer', [__CLASS__, 'dashboard_footer_widget'], 1010);

        // Pełne nazwy SPF / DKIM / DMARC / One-Click także w Ustawieniach.
        add_action('admin_footer', [__CLASS__, 'expand_delivery_terms_footer'], 1700);
    }

    public static function detach_legacy_mailing_renderer(): void {
        if (!function_exists('get_plugin_page_hookname')) return;
        $hook = get_plugin_page_hookname(self::PAGE, self::PARENT);
        if ($hook === '') return;

        remove_action($hook, [BCS_Release_096::class, 'mailing_page']);
    }

    public static function assets(string $hook): void {
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if ($page === self::PAGE) {
            wp_enqueue_style('bcs-mailing-101', BCS_URL.'assets/mailing-101.css', ['bcs-mailing-100'], BCS_VERSION);
        }
        if (in_array($page, [self::PAGE, 'bcs-dashboard', 'bcs-settings'], true)) {
            wp_enqueue_style('bcs-mailing-102', BCS_URL.'assets/mailing-102.css', ['bcs-mailing-100'], BCS_VERSION);
        }
    }

    public static function fix_campaign_year_date_query(string $query): string {
        if (!str_contains($query, 'SELECT DISTINCT YEAR(start_date) y FROM '.BCS_DB::table('camps'))) return $query;
        return str_replace([
            " WHERE start_date IS NOT NULL AND start_date<>''",
            " WHERE start_date IS NOT NULL AND start_date <> ''",
        ], ' WHERE start_date IS NOT NULL', $query);
    }

    private static function status_label(string $status): string {
        return [
            'draft'=>'Szkic',
            'scheduled'=>'Zaplanowana',
            'queued'=>'W kolejce',
            'sending'=>'Wysyłanie',
            'paused'=>'Wstrzymana',
            'completed'=>'Zakończona',
        ][$status] ?? $status;
    }

    private static function dashboard_stats(): array {
        global $wpdb;
        $base = BCS_Release_100::mailing_stats();
        $recipients = BCS_Release_097::recipients_table();
        $campaigns = BCS_Release_097::campaigns_table();

        $base['sent_total'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$recipients} WHERE status='sent'");
        $base['failed_total'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$recipients} WHERE status='failed'");
        $base['active_rows'] = $wpdb->get_results("SELECT c.id,c.name,c.status,c.scheduled_at,c.started_at,
            (SELECT COUNT(*) FROM {$recipients} r WHERE r.campaign_id=c.id) recipient_count,
            (SELECT COUNT(*) FROM {$recipients} r WHERE r.campaign_id=c.id AND r.status='sent') sent_count,
            (SELECT COUNT(*) FROM {$recipients} r WHERE r.campaign_id=c.id AND r.status='failed') failed_count,
            (SELECT COUNT(*) FROM {$recipients} r WHERE r.campaign_id=c.id AND r.status='skipped') skipped_count,
            (SELECT COUNT(*) FROM {$recipients} r WHERE r.campaign_id=c.id AND r.status='queued') queued_count
            FROM {$campaigns} c
            WHERE c.status IN ('scheduled','queued','sending','paused')
            ORDER BY CASE c.status WHEN 'sending' THEN 1 WHEN 'queued' THEN 2 WHEN 'scheduled' THEN 3 ELSE 4 END, c.id ASC
            LIMIT 6");
        return $base;
    }

    private static function health_chip_html(string $label, ?bool $ok): string {
        $class = $ok === null ? 'is-warn' : ($ok ? 'is-ok' : 'is-bad');
        $value = $ok === null ? 'NIE SPRAWDZONO' : ($ok ? 'OK' : 'BRAK');
        return '<span class="'.esc_attr($class).'"><b>'.esc_html($label).'</b><em>'.esc_html($value).'</em></span>';
    }

    private static function campaigns_progress_html(array $rows): string {
        if (!$rows) return '';
        ob_start();
        echo '<div class="bcs-mail-campaign-progress"><div class="bcs-mail-campaign-progress-head"><div><strong>Status aktywnych kampanii</strong><span>Postęp aktualnej kolejki mailingowej.</span></div></div>';
        foreach ($rows as $row) {
            $total = max(0, (int)$row->recipient_count);
            $sent = max(0, (int)$row->sent_count);
            $failed = max(0, (int)$row->failed_count);
            $skipped = max(0, (int)$row->skipped_count);
            $queued = max(0, (int)$row->queued_count);
            $done = min($total, $sent + $failed + $skipped);
            $percent = $total > 0 ? (int)round(($done / $total) * 100) : 0;
            $details = add_query_arg(['page'=>'bcs-mailing-campaign-history','campaign_id'=>(int)$row->id], admin_url('admin.php'));
            echo '<div class="bcs-mail-campaign-progress-row">';
            echo '<div class="bcs-mail-campaign-progress-title"><div><strong>'.esc_html((string)$row->name).'</strong><small>Kampania #'.(int)$row->id.'</small></div><span class="bcs-mail-badge status-'.esc_attr((string)$row->status).'">'.esc_html(self::status_label((string)$row->status)).'</span></div>';
            echo '<div class="bcs-mail-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="'.$percent.'"><i style="width:'.$percent.'%"></i></div>';
            echo '<div class="bcs-mail-campaign-progress-meta"><span><b>'.$percent.'%</b> zrealizowano</span><span>Wysłano: <b>'.$sent.' / '.$total.'</b></span><span>Błędy: <b>'.$failed.'</b></span><span>Pominięto: <b>'.$skipped.'</b></span><span>W kolejce: <b>'.$queued.'</b></span><a href="'.esc_url($details).'">Szczegóły</a></div>';
            echo '</div>';
        }
        echo '</div>';
        return (string)ob_get_clean();
    }

    public static function dashboard_footer_widget(): void {
        if (!current_user_can('manage_options')) return;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if ($page !== 'bcs-dashboard') return;
        $html = self::dashboard_widget_html();
        echo '<template id="bcs-mailing-dashboard-template-102">'.$html.'</template><script>(function(){var r=document.querySelector(".wrap.bcs-admin"),t=document.getElementById("bcs-mailing-dashboard-template-102");if(r&&t){r.appendChild(t.content.cloneNode(true));t.remove();}})();</script>';
    }

    public static function dashboard_widget_html(): string {
        $s = self::dashboard_stats();
        $next = (int)$s['next_send'];
        $nextLabel = $next > time() ? wp_date('d.m H:i', $next, wp_timezone()) : 'teraz';
        $mailingUrl = add_query_arg(['page'=>'bcs-mailing','tab'=>'campaigns'], admin_url('admin.php'));
        ob_start();
        echo '<section class="bcs-mail-dashboard bcs-mail-dashboard-102"><div class="bcs-mail-dashboard-head"><div><span class="dashicons dashicons-email-alt2"></span><div><small>Mailing promocyjny</small><h2>Newsletter</h2></div></div><div><span class="bcs-mail-badge '.($s['auto_paused']?'is-danger':'is-ok').'">'.($s['auto_paused']?'WYSYŁKA WSTRZYMANA':'SYSTEM GOTOWY').'</span> <a class="button" href="'.esc_url($mailingUrl).'">Otwórz Mailing</a></div></div>';
        echo '<div class="bcs-mail-dashboard-grid bcs-mail-dashboard-grid-102">';
        $items = [
            ['Aktywne zgody', number_format_i18n((int)$s['consented'])],
            ['Wysłane łącznie', number_format_i18n((int)$s['sent_total'])],
            ['Błędy łącznie', number_format_i18n((int)$s['failed_total'])],
            ['Wysłano w miesiącu', number_format_i18n((int)$s['sent_month'])],
            ['CTR kliknięć', number_format_i18n((float)$s['ctr'],1).'%'],
            ['Błędy w miesiącu', number_format_i18n((int)$s['failed_month'])],
            ['Wypisani', number_format_i18n((int)$s['unsubscribed'])],
            ['Aktywne kampanie', number_format_i18n((int)$s['active_campaigns'])],
            ['Dzisiaj', (int)$s['sent_today'].' / '.(int)$s['daily_limit']],
            ['Kolejna wysyłka', $nextLabel],
        ];
        foreach ($items as $item) echo '<div><span>'.esc_html($item[0]).'</span><strong>'.esc_html((string)$item[1]).'</strong></div>';
        echo '</div>';
        echo self::campaigns_progress_html((array)$s['active_rows']);
        echo '<div class="bcs-mail-spam-monitor"><div><strong>Monitoring dostarczalności</strong><span>Domena: '.esc_html($s['domain'] ?: 'nie ustawiono').' · '.esc_html((string)$s['transport']).'</span></div><div class="bcs-mail-health-chips is-expanded">';
        echo self::health_chip_html('SPF — Sender Policy Framework', (bool)$s['spf']);
        echo self::health_chip_html('DKIM — DomainKeys Identified Mail', $s['dkim'] === null ? null : (bool)$s['dkim']);
        echo self::health_chip_html('DMARC — Domain-based Message Authentication, Reporting and Conformance', (bool)$s['dmarc']);
        echo self::health_chip_html('One-Click Unsubscribe — wypisanie jednym kliknięciem', true);
        echo '</div></div>';
        echo '<div class="bcs-mail-dashboard-foot"><p><strong>Zgłoszenia jako SPAM:</strong> brak wiarygodnego źródła wewnętrznego. Do pomiaru skarg potrzebna jest integracja Google Postmaster / feedback loop operatora. System monitoruje natomiast DNS, błędy transportu, wypisania i tempo wysyłki.</p><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-settings#bcs-newsletter-settings')).'">Ustawienia i reputacja</a></div></section>';
        return (string)ob_get_clean();
    }

    public static function expand_delivery_terms_footer(): void {
        if (!current_user_can('manage_options')) return;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if (!in_array($page, ['bcs-settings', self::PAGE], true)) return;
        echo '<script>(function(){var map={"SPF":"SPF — Sender Policy Framework","DKIM":"DKIM — DomainKeys Identified Mail","DMARC":"DMARC — Domain-based Message Authentication, Reporting and Conformance","One-Click":"One-Click Unsubscribe — wypisanie jednym kliknięciem"};document.querySelectorAll(".bcs-newsletter-health small").forEach(function(el){var t=el.textContent.trim();if(map[t])el.textContent=map[t];});document.querySelectorAll("#bcs-newsletter-settings label>span").forEach(function(el){if(el.textContent.trim()==="Selektor DKIM")el.textContent="Selektor DKIM — DomainKeys Identified Mail";});})();</script>';
    }
}
