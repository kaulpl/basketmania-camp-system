<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.96 – fundament mailingu: centralna baza kontaktów, zgody, import CSV i wypisanie.
 */
final class BCS_Release_096 {
    private const CONSENT_VERSION = 'marketing-email-v1-2026-08';
    private const IMPORT_ACTION = 'bcs_marketing_import_096';
    private const UNSUBSCRIBE_ACTION = 'bcs_marketing_unsubscribe_096';

    public static function init(): void {
        self::ensure_schema();
        self::sync_legacy_registrations_once();

        add_action('admin_menu', [__CLASS__, 'admin_menu'], 30);
        add_action('admin_post_'.self::IMPORT_ACTION, [__CLASS__, 'handle_import']);
        add_action('admin_post_'.self::UNSUBSCRIBE_ACTION, [__CLASS__, 'handle_unsubscribe']);
        add_action('admin_post_nopriv_'.self::UNSUBSCRIBE_ACTION, [__CLASS__, 'handle_unsubscribe']);

        add_filter('do_shortcode_tag', [__CLASS__, 'inject_marketing_consent'], 20, 4);

        // Zastępujemy wyłącznie obsługę pierwszego zgłoszenia, aby zapisać zgodę
        // w tej samej transakcji logicznej co utworzenie rejestracji.
        remove_action('admin_post_nopriv_bcs_signup', ['BCS_Frontend', 'handle_signup']);
        remove_action('admin_post_bcs_signup', ['BCS_Frontend', 'handle_signup']);
        add_action('admin_post_nopriv_bcs_signup', [__CLASS__, 'handle_signup']);
        add_action('admin_post_bcs_signup', [__CLASS__, 'handle_signup']);
    }

    public static function contacts_table(): string { return BCS_DB::table('marketing_contacts'); }
    public static function consent_events_table(): string { return BCS_DB::table('marketing_consent_events'); }

    public static function ensure_schema(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $contacts = self::contacts_table();
        $events = self::consent_events_table();

        dbDelta("CREATE TABLE {$contacts} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(190) NOT NULL,
            first_name VARCHAR(100) NULL,
            last_name VARCHAR(100) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            consent_status VARCHAR(20) NOT NULL DEFAULT 'no',
            consent_source VARCHAR(50) NULL,
            consent_at DATETIME NULL,
            consent_text_version VARCHAR(80) NULL,
            consent_ip VARCHAR(64) NULL,
            consent_user_agent TEXT NULL,
            unsubscribe_token CHAR(64) NOT NULL,
            first_registration_id BIGINT UNSIGNED NULL,
            last_registration_id BIGINT UNSIGNED NULL,
            first_seen_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            UNIQUE KEY unsubscribe_token (unsubscribe_token),
            KEY consent_status (consent_status),
            KEY status (status),
            KEY last_registration_id (last_registration_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$events} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id BIGINT UNSIGNED NOT NULL,
            registration_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(50) NOT NULL,
            consent_value VARCHAR(20) NOT NULL,
            source VARCHAR(50) NOT NULL,
            details LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY contact_id (contact_id),
            KEY registration_id (registration_id),
            KEY created_at (created_at)
        ) {$charset};");
    }

    private static function now(): string { return BCS_Utils::now(); }

    private static function request_ip(): string {
        return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    private static function request_user_agent(): string {
        return sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    /**
     * Każdy znany adres trafia do bazy. $consent=null oznacza: nie zmieniaj
     * istniejącego statusu zgody; dla nowego kontaktu utwórz status "no".
     */
    public static function upsert_contact(string $email, string $firstName = '', string $lastName = '', ?bool $consent = null, string $source = 'registration', int $registrationId = 0, array $details = []): int {
        global $wpdb;
        $email = sanitize_email($email);
        if (!is_email($email)) return 0;
        $table = self::contacts_table();
        $now = self::now();
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE email=%s", $email));
        $firstName = sanitize_text_field($firstName);
        $lastName = sanitize_text_field($lastName);

        if (!$existing) {
            $token = BCS_Utils::random_token();
            $value = $consent === true ? 'yes' : 'no';
            $wpdb->insert($table, [
                'email'=>$email,
                'first_name'=>$firstName,
                'last_name'=>$lastName,
                'status'=>'active',
                'consent_status'=>$value,
                'consent_source'=>$source,
                'consent_at'=>$consent === true ? $now : null,
                'consent_text_version'=>$consent !== null ? self::CONSENT_VERSION : null,
                'consent_ip'=>$consent !== null ? self::request_ip() : null,
                'consent_user_agent'=>$consent !== null ? self::request_user_agent() : null,
                'unsubscribe_token'=>$token,
                'first_registration_id'=>$registrationId ?: null,
                'last_registration_id'=>$registrationId ?: null,
                'first_seen_at'=>$now,
                'last_seen_at'=>$now,
                'created_at'=>$now,
                'updated_at'=>$now,
            ]);
            $contactId = (int)$wpdb->insert_id;
            if ($contactId) {
                self::record_consent_event($contactId, $registrationId, $consent === true ? 'consent_granted' : ($consent === false ? 'consent_not_granted' : 'contact_discovered'), $value, $source, $details);
            }
            return $contactId;
        }

        $contactId = (int)$existing->id;
        $update = [
            'last_seen_at'=>$now,
            'updated_at'=>$now,
        ];
        if ($firstName !== '') $update['first_name'] = $firstName;
        if ($lastName !== '') $update['last_name'] = $lastName;
        if ($registrationId) {
            $update['last_registration_id'] = $registrationId;
            if (empty($existing->first_registration_id)) $update['first_registration_id'] = $registrationId;
        }
        if ($consent !== null) {
            $value = $consent ? 'yes' : 'no';
            $update['consent_status'] = $value;
            $update['consent_source'] = $source;
            $update['consent_at'] = $consent ? $now : null;
            $update['consent_text_version'] = self::CONSENT_VERSION;
            $update['consent_ip'] = self::request_ip();
            $update['consent_user_agent'] = self::request_user_agent();
            // Nowa, świadoma zgoda może ponownie aktywować wcześniej wypisany kontakt.
            if ($consent) $update['status'] = 'active';
            self::record_consent_event($contactId, $registrationId, $consent ? 'consent_granted' : 'consent_not_granted', $value, $source, $details);
        }
        $wpdb->update($table, $update, ['id'=>$contactId]);
        return $contactId;
    }

    private static function record_consent_event(int $contactId, int $registrationId, string $eventType, string $value, string $source, array $details = []): void {
        global $wpdb;
        $wpdb->insert(self::consent_events_table(), [
            'contact_id'=>$contactId,
            'registration_id'=>$registrationId ?: null,
            'event_type'=>sanitize_key($eventType),
            'consent_value'=>in_array($value, ['yes','no'], true) ? $value : 'no',
            'source'=>sanitize_key($source),
            'details'=>$details ? wp_json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at'=>self::now(),
        ]);
    }

    private static function sync_legacy_registrations_once(): void {
        if (get_option('bcs_marketing_096_legacy_synced')) return;
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id,parent_email,parent_first_name,parent_last_name FROM ".BCS_DB::table('registrations')." WHERE parent_email<>'' ORDER BY id ASC");
        foreach ((array)$rows as $row) {
            self::upsert_contact((string)$row->parent_email, (string)$row->parent_first_name, (string)$row->parent_last_name, null, 'legacy_registration', (int)$row->id, ['migration'=>'0.96']);
        }
        update_option('bcs_marketing_096_legacy_synced', self::now(), false);
    }

    public static function inject_marketing_consent(string $output, string $tag, array $attr, array $m): string {
        if ($tag !== 'basketmania_signup' || !str_contains($output, 'name="action" value="bcs_signup"')) return $output;
        if (str_contains($output, 'name="marketing_email_consent"')) return $output;
        $checkbox = '<label class="bcs-check bcs-marketing-consent-096"><input type="checkbox" name="marketing_email_consent" value="1"> Chcę otrzymywać e-mailem informacje o kolejnych edycjach Basketmania Camp, zapisach, promocjach i wydarzeniach.</label>';
        $needle = '<label class="bcs-check"><input type="checkbox" required>';
        return str_contains($output, $needle) ? str_replace($needle, $checkbox.$needle, $output) : str_replace('</form>', $checkbox.'</form>', $output);
    }

    private static function detect_device_type(string $userAgent): string {
        $ua = strtolower($userAgent);
        if ($ua === '') return 'unknown';
        if (preg_match('/ipad|tablet|kindle|silk|playbook|android(?!.*mobile)/i', $ua)) return 'tablet';
        if (preg_match('/mobile|iphone|ipod|android|blackberry|iemobile|opera mini/i', $ua)) return 'mobile';
        return 'desktop';
    }

    private static function portal_url(string $token): string {
        $page = get_page_by_path('panel-rodzica');
        return add_query_arg('token', $token, $page ? get_permalink($page) : home_url('/panel-rodzica/'));
    }

    public static function handle_signup(): void {
        check_admin_referer('bcs_signup');
        global $wpdb;
        $campId = absint($_POST['camp_id'] ?? 0);
        $camp = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('camps')." WHERE id=%d AND status='open'", $campId));
        if (!$camp) wp_die('Turnus jest niedostępny.');
        $count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".BCS_DB::table('registrations')." WHERE camp_id=%d AND status<>'cancelled'", $campId));
        if ((int)$camp->capacity > 0 && $count >= (int)$camp->capacity) wp_die('Brak wolnych miejsc na wybrany turnus.');

        $parentName = preg_replace('/\s+/', ' ', trim(sanitize_text_field(wp_unslash($_POST['parent_name'] ?? ''))));
        $parts = explode(' ', (string)$parentName, 2);
        $parentFirst = $parts[0] ?? '';
        $parentLast = $parts[1] ?? '';
        $email = sanitize_email(wp_unslash($_POST['parent_email'] ?? ''));
        $now = self::now();
        $token = BCS_Utils::random_token();
        $userAgent = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $deviceType = self::detect_device_type($userAgent);
        $campYear = (int)substr((string)$camp->start_date, 0, 4);
        if ($campYear < 2000) $campYear = (int)current_time('Y');

        $ok = $wpdb->insert(BCS_DB::table('registrations'), [
            'camp_id'=>$campId,
            'public_token'=>$token,
            'status'=>'admin_confirmed',
            'form_status'=>'incomplete',
            'source'=>'website_form',
            'device_type'=>$deviceType,
            'device_user_agent'=>$userAgent,
            'parent_first_name'=>$parentFirst,
            'parent_last_name'=>$parentLast,
            'parent_email'=>$email,
            'parent_phone'=>sanitize_text_field(wp_unslash($_POST['parent_phone'] ?? '')),
            'child_first_name'=>sanitize_text_field(wp_unslash($_POST['child_first_name'] ?? '')),
            'child_last_name'=>sanitize_text_field(wp_unslash($_POST['child_last_name'] ?? '')),
            'child_birth_date'=>sanitize_text_field(wp_unslash($_POST['child_birth_date'] ?? '')),
            'child_height'=>absint($_POST['child_height'] ?? 0),
            'shirt_size'=>sanitize_text_field(wp_unslash($_POST['shirt_size'] ?? '')),
            'total_amount'=>(float)$camp->price,
            'paid_amount'=>0,
            'admin_confirmed_at'=>$now,
            'agreement_available_from'=>sprintf('%04d-01-01', $campYear),
            'created_at'=>$now,
            'updated_at'=>$now,
        ]);
        if (!$ok) wp_die('Nie udało się zapisać zgłoszenia.');

        $id = (int)$wpdb->insert_id;
        $consent = isset($_POST['marketing_email_consent']) && (string)$_POST['marketing_email_consent'] === '1';
        self::upsert_contact($email, $parentFirst, $parentLast, $consent, 'registration_form', $id, [
            'camp_id'=>$campId,
            'consent_text_version'=>self::CONSENT_VERSION,
        ]);

        BCS_Utils::log('registration_created', ['source'=>'website_form', 'marketing_email_consent'=>$consent ? 1 : 0], $id, null);
        if (class_exists('BCS_Communications')) BCS_Communication_Engine::send_to_registration($id, 'registration_received', 'email');
        wp_safe_redirect(add_query_arg('edit', 'camp', self::portal_url($token)));
        exit;
    }

    public static function admin_menu(): void {
        add_submenu_page('bcs-dashboard', 'Mailing', 'Mailing', 'manage_options', 'bcs-mailing', [__CLASS__, 'mailing_page']);
    }

    private static function tabs(string $active): void {
        $tabs = [
            'contacts'=>'Kontakty',
            'import'=>'Import',
        ];
        if (class_exists('BCS_Release_097')) {
            $tabs['campaigns'] = 'Kampanie';
            $tabs['new-campaign'] = 'Nowa kampania';
        }
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px">';
        foreach ($tabs as $key=>$label) {
            $url = add_query_arg(['page'=>'bcs-mailing','tab'=>$key], admin_url('admin.php'));
            echo '<a class="nav-tab '.($active === $key ? 'nav-tab-active' : '').'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
        }
        echo '</nav>';
    }

    public static function mailing_page(): void {
        if (!current_user_can('manage_options')) return;
        $tab = sanitize_key(wp_unslash($_GET['tab'] ?? 'contacts'));
        echo '<div class="wrap"><h1>Mailing Basketmania Camp</h1>';
        self::tabs($tab);
        if (in_array($tab, ['campaigns','new-campaign','campaign'], true) && class_exists('BCS_Release_097')) {
            BCS_Release_097::render_campaigns_page($tab);
            echo '</div>';
            return;
        }
        if ($tab === 'import') self::render_import(); else self::render_contacts();
        echo '</div>';
    }

    private static function render_contacts(): void {
        global $wpdb;
        $table = self::contacts_table();
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $consented = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE consent_status='yes' AND status='active'");
        $without = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE consent_status<>'yes'");
        $unsubscribed = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='unsubscribed'");
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0">';
        foreach ([['Wszystkie kontakty',$total],['Aktywna zgoda',$consented],['Brak zgody',$without],['Wypisani',$unsubscribed]] as $stat) {
            echo '<div class="card" style="min-width:170px;padding:16px"><strong style="display:block;font-size:24px">'.esc_html((string)$stat[1]).'</strong>'.esc_html($stat[0]).'</div>';
        }
        echo '</div>';

        if (!empty($_GET['imported'])) echo '<div class="notice notice-success"><p>Import zakończony. Dodano/zaktualizowano '.esc_html((string)absint($_GET['imported'])).' kontaktów.</p></div>';

        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY updated_at DESC,id DESC LIMIT 200");
        echo '<table class="widefat striped"><thead><tr><th>E-mail</th><th>Imię i nazwisko</th><th>Zgoda</th><th>Źródło</th><th>Data zgody</th><th>Status</th><th></th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="7">Brak kontaktów mailingowych.</td></tr>';
        foreach ((array)$rows as $row) {
            $historyUrl = class_exists('BCS_Release_098') ? BCS_Release_098::contact_history_url((int)$row->id) : '';
            echo '<tr><td><strong>'.esc_html((string)$row->email).'</strong></td><td>'.esc_html(trim((string)$row->first_name.' '.(string)$row->last_name)).'</td>';
            echo '<td>'.($row->consent_status === 'yes' ? '<span style="color:#06752f;font-weight:700">TAK</span>' : '<span style="color:#8a2424;font-weight:700">NIE</span>').'</td>';
            echo '<td>'.esc_html(self::source_label((string)$row->consent_source)).'</td><td>'.esc_html($row->consent_at ? wp_date('d.m.Y H:i', strtotime((string)$row->consent_at)) : '—').'</td>';
            echo '<td>'.($row->status === 'unsubscribed' ? '<span style="color:#8a2424">Wypisany</span>' : 'Aktywny').'</td><td>'.($historyUrl ? '<a class="button button-small" href="'.esc_url($historyUrl).'">Historia</a>' : '').'</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function source_label(string $source): string {
        return [
            'import'=>'Import bazy',
            'registration_form'=>'Formularz zgłoszeniowy',
            'legacy_registration'=>'Starsze zgłoszenie',
            'unsubscribe'=>'Wypisanie',
        ][$source] ?? ($source !== '' ? $source : '—');
    }

    private static function render_import(): void {
        echo '<div class="card" style="max-width:820px;padding:22px">';
        echo '<h2>Import bazy e-mail</h2><p>Obsługiwany format: CSV/TXT zapisany z Excela lub innego arkusza. Akceptowane separatory: średnik, przecinek lub tabulator. Kolumny: <code>email</code>, opcjonalnie <code>imie</code>, <code>nazwisko</code>. Można też wgrać plik zawierający jeden adres e-mail w każdym wierszu.</p>';
        echo '<p><strong>Ważne:</strong> zgodnie z przyjętym założeniem każdy poprawnie zaimportowany adres zostanie oznaczony jako posiadający zgodę marketingową, ze źródłem <em>Import bazy</em>.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="'.esc_attr(self::IMPORT_ACTION).'">';
        wp_nonce_field(self::IMPORT_ACTION);
        echo '<p><input type="file" name="marketing_file" accept=".csv,.txt,text/csv,text/plain" required></p>';
        submit_button('Importuj kontakty', 'primary');
        echo '</form></div>';
    }

    public static function normalize_header(string $value): string {
        $value = strtolower(trim($value));
        $value = str_replace(['ą','ć','ę','ł','ń','ó','ś','ź','ż'], ['a','c','e','l','n','o','s','z','z'], $value);
        return preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
    }

    private static function detect_delimiter(string $line): string {
        $counts = [';'=>substr_count($line, ';'), ','=>substr_count($line, ','), "\t"=>substr_count($line, "\t")];
        arsort($counts);
        $delimiter = (string)array_key_first($counts);
        return ((int)reset($counts)) > 0 ? $delimiter : ';';
    }

    /** @return array<int,array{email:string,first_name:string,last_name:string}> */
    public static function parse_import_content(string $content): array {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = array_values(array_filter(explode("\n", $content), static fn($line): bool => trim($line) !== ''));
        if (!$lines) return [];
        $delimiter = self::detect_delimiter($lines[0]);
        $rows = [];
        foreach ($lines as $line) $rows[] = str_getcsv($line, $delimiter);
        if (!$rows) return [];

        $header = array_map([__CLASS__, 'normalize_header'], $rows[0]);
        $emailAliases = ['email','e_mail','mail','adres_email','email_address'];
        $emailIndex = null;
        foreach ($header as $i=>$name) if (in_array($name, $emailAliases, true)) { $emailIndex = $i; break; }
        $hasHeader = $emailIndex !== null;
        if (!$hasHeader) $emailIndex = 0;
        $firstIndex = $lastIndex = null;
        if ($hasHeader) {
            foreach ($header as $i=>$name) {
                if (in_array($name, ['imie','first_name','firstname'], true)) $firstIndex = $i;
                if (in_array($name, ['nazwisko','last_name','lastname'], true)) $lastIndex = $i;
            }
        } else {
            $firstIndex = 1;
            $lastIndex = 2;
        }

        $result = [];
        $start = $hasHeader ? 1 : 0;
        for ($r=$start; $r<count($rows); $r++) {
            $email = trim((string)($rows[$r][$emailIndex] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $result[strtolower($email)] = [
                'email'=>$email,
                'first_name'=>trim((string)($firstIndex !== null ? ($rows[$r][$firstIndex] ?? '') : '')),
                'last_name'=>trim((string)($lastIndex !== null ? ($rows[$r][$lastIndex] ?? '') : '')),
            ];
        }
        return array_values($result);
    }

    public static function handle_import(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer(self::IMPORT_ACTION);
        if (empty($_FILES['marketing_file']['tmp_name']) || !is_uploaded_file($_FILES['marketing_file']['tmp_name'])) wp_die('Nie przesłano pliku importu.');
        if ((int)($_FILES['marketing_file']['size'] ?? 0) > 5 * 1024 * 1024) wp_die('Plik jest zbyt duży. Maksymalny rozmiar to 5 MB.');
        $content = (string)file_get_contents($_FILES['marketing_file']['tmp_name']);
        $rows = self::parse_import_content($content);
        $imported = 0;
        foreach ($rows as $row) {
            $id = self::upsert_contact($row['email'], $row['first_name'], $row['last_name'], true, 'import', 0, [
                'filename'=>sanitize_file_name((string)($_FILES['marketing_file']['name'] ?? '')),
                'imported_by'=>get_current_user_id(),
            ]);
            if ($id) $imported++;
        }
        wp_safe_redirect(add_query_arg(['page'=>'bcs-mailing','tab'=>'contacts','imported'=>$imported], admin_url('admin.php')));
        exit;
    }

    public static function unsubscribe_url(object $contact): string {
        return add_query_arg([
            'action'=>self::UNSUBSCRIBE_ACTION,
            'token'=>(string)$contact->unsubscribe_token,
        ], admin_url('admin-post.php'));
    }

    public static function handle_unsubscribe(): void {
        global $wpdb;
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? $_POST['token'] ?? ''));
        $contact = $token !== '' ? $wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::contacts_table()." WHERE unsubscribe_token=%s", $token)) : null;
        if (!$contact) wp_die('Link wypisania jest nieprawidłowy lub wygasł.');
        $now = self::now();
        $wpdb->update(self::contacts_table(), [
            'status'=>'unsubscribed',
            'consent_status'=>'no',
            'consent_source'=>'unsubscribe',
            'consent_at'=>null,
            'updated_at'=>$now,
        ], ['id'=>(int)$contact->id]);
        self::record_consent_event((int)$contact->id, 0, 'unsubscribed', 'no', 'unsubscribe', []);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="pl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Wypisano z mailingu</title></head><body style="font-family:Arial,sans-serif;background:#f3f4f6;padding:40px"><div style="max-width:620px;margin:auto;background:#fff;padding:36px;border-radius:16px"><h1>Wypisano z mailingu Basketmania Camp</h1><p>Adres <strong>'.esc_html((string)$contact->email).'</strong> nie będzie otrzymywał kolejnych wiadomości promocyjnych.</p></div></body></html>';
        exit;
    }
}
