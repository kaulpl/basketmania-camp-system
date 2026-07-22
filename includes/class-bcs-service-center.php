<?php
if (!defined('ABSPATH')) exit;

final class BCS_Service_Center {
    public static function init(): void {
        add_action('admin_menu', [self::class, 'menu'], 30);
        add_action('admin_post_bcs_service_clear_cache', [self::class, 'clear_cache']);
        add_action('admin_post_bcs_service_check_updates', [self::class, 'check_updates']);
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
        if (isset($_GET['service_error'])) echo '<div class="notice notice-error is-dismissible"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['service_error']))).'</p></div>';
        echo '<div class="bcs-panel"><h2>Stan systemu</h2><table class="widefat striped"><thead><tr><th>Obszar</th><th>Test</th><th>Wynik</th><th>Szczegóły</th></tr></thead><tbody>';
        foreach ($report as $row) echo '<tr><td><strong>'.esc_html($row['group']).'</strong></td><td>'.esc_html($row['label']).'</td><td>'.($row['ok'] ? '<span style="color:#16803c;font-weight:700">✓ OK</span>' : '<span style="color:#b32d2e;font-weight:700">✕ Błąd</span>').'</td><td>'.esc_html($row['detail']).'</td></tr>';
        echo '</tbody></table></div>';
        echo '<div class="bcs-panel" style="margin-top:20px"><h2>Narzędzia serwisowe</h2><div style="display:flex;gap:12px;flex-wrap:wrap">';
        self::action_form('bcs_service_check_updates', 'Sprawdź aktualizacje GitHub teraz', 'dashicons-update-alt');
        self::action_form('bcs_service_clear_cache', 'Wyczyść cache aktualizacji', 'dashicons-update');
        self::action_form('bcs_service_run_migrations', 'Uruchom migracje bazy', 'dashicons-database');
        self::action_form('bcs_service_test_pdf', 'Wygeneruj testowy PDF', 'dashicons-media-document', true);
        echo '</div><p class="description">Sprawdzanie aktualizacji korzysta z publicznego przekierowania GitHub Releases i nie zużywa limitu GitHub API.</p></div></div>';
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
        $diagnostics = BCS_Updater::diagnostics();
        $tables = ['organizers','camps','registrations','agreements','payments','feedback'];
        $remote_version = (string)($diagnostics['version'] ?? '');
        $package_url = (string)($diagnostics['download_url'] ?? '');
        $last_check = (string)($diagnostics['checked_at'] ?? 'Jeszcze nie wykonano');
        $source = (string)($diagnostics['source'] ?? 'GitHub Releases redirect');
        $update_available = $remote_version !== '' && version_compare(BCS_VERSION, $remote_version, '<');
        $connection_ok = !empty($diagnostics['ok']);
        $rows = [
            ['group'=>'System','label'=>'Wersja wtyczki','ok'=>defined('BCS_VERSION'),'detail'=>defined('BCS_VERSION') ? BCS_VERSION : 'Brak'],
            ['group'=>'System','label'=>'WordPress','ok'=>version_compare(get_bloginfo('version'), '6.5', '>='),'detail'=>get_bloginfo('version')],
            ['group'=>'System','label'=>'PHP','ok'=>version_compare(PHP_VERSION, '8.1', '>='),'detail'=>PHP_VERSION],
            ['group'=>'Baza danych','label'=>'Wersja schematu','ok'=>get_option('bcs_db_version') === BCS_DB::DB_VERSION,'detail'=>(string)get_option('bcs_db_version', 'brak').' / '.BCS_DB::DB_VERSION],
            ['group'=>'Pliki','label'=>'Katalog uploads zapisywalny','ok'=>empty($upload['error']) && is_writable($upload['basedir']),'detail'=>empty($upload['error']) ? $upload['basedir'] : (string)$upload['error']],
            ['group'=>'Composer','label'=>'vendor/autoload.php','ok'=>file_exists($autoload),'detail'=>file_exists($autoload) ? 'Znaleziony' : 'Brak w paczce'],
            ['group'=>'PDF','label'=>'DOMPDF','ok'=>class_exists('Dompdf\\Dompdf'),'detail'=>class_exists('Dompdf\\Dompdf') ? 'Silnik dostępny' : 'Klasa niedostępna'],
            ['group'=>'Aktualizacje','label'=>'Połączenie z GitHub Releases','ok'=>$connection_ok,'detail'=>$connection_ok ? $source : (string)($diagnostics['error'] ?? 'Uruchom sprawdzenie aktualizacji')],
            ['group'=>'Aktualizacje','label'=>'Najnowszy release','ok'=>$remote_version !== '','detail'=>$remote_version ?: 'Brak danych'],
            ['group'=>'Aktualizacje','label'=>'Adres paczki ZIP','ok'=>$package_url !== '','detail'=>$package_url !== '' ? 'Paczka została odnaleziona' : 'Brak danych'],
            ['group'=>'Aktualizacje','label'=>'Porównanie wersji','ok'=>$remote_version !== '','detail'=>$remote_version === '' ? 'Brak danych' : ($update_available ? 'Dostępna aktualizacja '.$remote_version : 'Wtyczka jest aktualna')],
            ['group'=>'Aktualizacje','label'=>'Ostatnie pełne sprawdzenie','ok'=>$connection_ok,'detail'=>$last_check.(!empty($diagnostics['error']) ? ' — '.$diagnostics['error'] : '')],
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

    public static function check_updates(): void {
        self::guard('bcs_service_check_updates');
        $result = BCS_Updater::force_refresh();
        if (empty($result['ok'])) {
            $error = (string)($result['error'] ?? 'Nie udało się pobrać poprawnych danych wydania.');
            wp_safe_redirect(add_query_arg(['page'=>'bcs-service-center','service_error'=>$error], admin_url('admin.php'))); exit;
        }
        $message = !empty($result['update_available'])
            ? 'GitHub zwrócił wersję '.$result['version'].'. Aktualizacja jest dostępna.'
            : 'GitHub zwrócił wersję '.$result['version'].'. Wtyczka jest aktualna.';
        wp_safe_redirect(add_query_arg(['page'=>'bcs-service-center','service_done'=>$message], admin_url('admin.php'))); exit;
    }

    public static function clear_cache(): void {
        self::guard('bcs_service_clear_cache');
        BCS_Updater::clear_cache();
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
