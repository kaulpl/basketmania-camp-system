<?php
if (!defined('ABSPATH')) exit;

final class BCS_Service_Center {
    private const RELEASE_API = 'https://api.github.com/repos/kaulpl/basketmania-camp-system/releases/latest';

    public static function init(): void {
        add_action('admin_menu', [self::class, 'menu'], 30);
        add_action('admin_post_bcs_service_clear_cache', [self::class, 'clear_cache']);
        add_action('admin_post_bcs_service_run_migrations', [self::class, 'run_migrations']);
        add_action('admin_post_bcs_service_test_pdf', [self::class, 'test_pdf']);
    }

    public static function menu(): void {
        add_submenu_page('bcs-dashboard', 'Centrum serwisowe', 'Centrum serwisowe', 'manage_options', 'bcs-service-center', [self::class, 'page']);
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        $report = self::report();
        $ok = count(array_filter($report, static fn($row) => !empty($row['ok'])));
        $total = count($report);
        echo '<div class="wrap bcs-admin"><div class="bcs-page-head"><div><h1>Centrum serwisowe</h1><p>Diagnostyka, aktualizacje i narzędzia administracyjne Basketmania Camp System.</p></div><span class="bcs-count">'.(int)$ok.' / '.(int)$total.' testów OK</span></div>';
        if (isset($_GET['service_done'])) echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['service_done']))).'</p></div>';
        echo '<div class="bcs-panel"><h2>Stan systemu</h2><table class="widefat striped"><thead><tr><th>Obszar</th><th>Test</th><th>Wynik</th><th>Szczegóły</th></tr></thead><tbody>';
        foreach ($report as $row) echo '<tr><td><strong>'.esc_html($row['group']).'</strong></td><td>'.esc_html($row['label']).'</td><td>'.($row['ok'] ? '<span style="color:#16803c;font-weight:700">✓ OK</span>' : '<span style="color:#b32d2e;font-weight:700">✕ Błąd</span>').'</td><td>'.esc_html($row['detail']).'</td></tr>';
        echo '</tbody></table></div>';
        echo '<div class="bcs-panel" style="margin-top:20px"><h2>Narzędzia serwisowe</h2><div style="display:flex;gap:12px;flex-wrap:wrap">';
        self::action_form('bcs_service_clear_cache', 'Wyczyść cache aktualizacji', 'dashicons-update');
        self::action_form('bcs_service_run_migrations', 'Uruchom migracje bazy', 'dashicons-database');
        self::action_form('bcs_service_test_pdf', 'Wygeneruj testowy PDF', 'dashicons-media-document', true);
        echo '</div><p class="description">Narzędzia są dostępne wyłącznie dla administratora i zabezpieczone nonce.</p></div></div>';
    }

    private static function action_form(string $action, string $label, string $icon, bool $new_tab = false): void {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"'.($new_tab ? ' target="_blank"' : '').'>';
        echo '<input type="hidden" name="action" value="'.esc_attr($action).'">';
        wp_nonce_field($action);
        echo '<button class="button button-secondary"><span class="dashicons '.esc_attr($icon).'" style="vertical-align:middle;margin-right:5px"></span>'.esc_html($label).'</button></form>';
    }

    private static function report(): array {
        global $wpdb;
        $upload = wp_upload_dir();
        $autoload = BCS_DIR . 'vendor/autoload.php';
        if (file_exists($autoload) && !class_exists('Dompdf\\Dompdf')) require_once $autoload;
        $release = self::release_info();
        $tables = ['organizers','camps','registrations','agreements','payments','feedback'];
        $rows = [
            ['group'=>'System','label'=>'Wersja wtyczki','ok'=>defined('BCS_VERSION'),'detail'=>defined('BCS_VERSION') ? BCS_VERSION : 'Brak'],
            ['group'=>'System','label'=>'WordPress','ok'=>version_compare(get_bloginfo('version'), '6.5', '>='),'detail'=>get_bloginfo('version')],
            ['group'=>'System','label'=>'PHP','ok'=>version_compare(PHP_VERSION, '8.1', '>='),'detail'=>PHP_VERSION],
            ['group'=>'Baza danych','label'=>'Wersja schematu','ok'=>get_option('bcs_db_version') === BCS_DB::DB_VERSION,'detail'=>(string)get_option('bcs_db_version', 'brak').' / '.BCS_DB::DB_VERSION],
            ['group'=>'Pliki','label'=>'Katalog uploads zapisywalny','ok'=>empty($upload['error']) && is_writable($upload['basedir']),'detail'=>empty($upload['error']) ? $upload['basedir'] : (string)$upload['error']],
            ['group'=>'Composer','label'=>'vendor/autoload.php','ok'=>file_exists($autoload),'detail'=>file_exists($autoload) ? 'Znaleziony' : 'Brak w paczce'],
            ['group'=>'PDF','label'=>'DOMPDF','ok'=>class_exists('Dompdf\\Dompdf'),'detail'=>class_exists('Dompdf\\Dompdf') ? 'Silnik dostępny' : 'Klasa niedostępna'],
            ['group'=>'Aktualizacje','label'=>'GitHub API','ok'=>$release['ok'],'detail'=>$release['detail']],
            ['group'=>'Aktualizacje','label'=>'Najnowszy release','ok'=>$release['ok'] && $release['version'] !== '','detail'=>$release['version'] ?: 'Brak danych'],
        ];
        foreach ($tables as $table) {
            $name = BCS_DB::table($table);
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) === $name;
            $rows[] = ['group'=>'Baza danych','label'=>$name,'ok'=>$exists,'detail'=>$exists ? 'Tabela istnieje' : 'Brak tabeli'];
        }
        $settings = get_option('bcs_settings', []);
        $rows[] = ['group'=>'Konfiguracja','label'=>'E-mail nadawcy','ok'=>!empty($settings['mail_from_email']) || is_email(get_option('admin_email')),'detail'=>(string)($settings['mail_from_email'] ?? get_option('admin_email'))];
        $rows[] = ['group'=>'Konfiguracja','label'=>'Bramka SMS','ok'=>!empty($settings['sms_provider']),'detail'=>(string)($settings['sms_provider'] ?? 'Nie skonfigurowano')];
        return $rows;
    }

    private static function release_info(): array {
        $response = wp_remote_get(self::RELEASE_API, ['timeout'=>12,'headers'=>['Accept'=>'application/vnd.github+json','User-Agent'=>'Basketmania-Camp-System/'.BCS_VERSION]]);
        if (is_wp_error($response)) return ['ok'=>false,'version'=>'','detail'=>$response->get_error_message()];
        $code = (int)wp_remote_retrieve_response_code($response);
        $body = json_decode((string)wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($body)) return ['ok'=>false,'version'=>'','detail'=>'HTTP '.$code];
        $version = ltrim((string)($body['tag_name'] ?? ''), 'vV');
        return ['ok'=>true,'version'=>$version,'detail'=>$version ? 'GitHub odpowiada; release '.$version : 'GitHub odpowiada'];
    }

    public static function clear_cache(): void {
        self::guard('bcs_service_clear_cache');
        delete_site_transient('bcs_github_release');
        delete_site_transient('update_plugins');
        wp_safe_redirect(add_query_arg(['page'=>'bcs-service-center','service_done'=>'Cache aktualizacji został wyczyszczony.'], admin_url('admin.php'))); exit;
    }

    public static function run_migrations(): void {
        self::guard('bcs_service_run_migrations');
        BCS_DB::activate();
        wp_safe_redirect(add_query_arg(['page'=>'bcs-service-center','service_done'=>'Migracje bazy zostały uruchomione.'], admin_url('admin.php'))); exit;
    }

    public static function test_pdf(): void {
        self::guard('bcs_service_test_pdf');
        if (!BCS_PDF::available()) wp_die('DOMPDF nie jest dostępny.');
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) wp_die(esc_html($upload['error']));
        $path = trailingslashit($upload['basedir']).'bcs-diagnostyka-'.time().'.pdf';
        $html = '<h1>Basketmania Camp System</h1><p>Test DOMPDF zakończony pomyślnie.</p><p>Wersja wtyczki: '.esc_html(BCS_VERSION).'</p><p>Data: '.esc_html(current_time('mysql')).'</p>';
        if (!BCS_PDF::generate($html, $path, 'Diagnostyka')) wp_die('Nie udało się wygenerować PDF.');
        nocache_headers(); header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="basketmania-diagnostyka.pdf"'); header('Content-Length: '.filesize($path)); readfile($path); @unlink($path); exit;
    }

    private static function guard(string $action): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer($action);
    }
}
