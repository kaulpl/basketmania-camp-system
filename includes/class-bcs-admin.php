<?php
if (!defined('ABSPATH')) exit;

class BCS_Admin {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'save_actions']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function menu(): void {
        $action_count = self::action_required_count();
        $badge = $action_count > 0
            ? ' <span class="awaiting-mod update-plugins count-' . esc_attr((string)$action_count) . '"><span class="plugin-count">' . esc_html((string)$action_count) . '</span></span>'
            : '';

        add_menu_page('Dashboard Camp', 'Basketmania Camp' . $badge, 'manage_options', 'bcs-dashboard', [__CLASS__, 'dashboard'], 'dashicons-dashboard', 26);
        add_submenu_page('bcs-dashboard', 'Dashboard Camp', 'Dashboard Camp', 'manage_options', 'bcs-dashboard', [__CLASS__, 'dashboard']);
        add_submenu_page('bcs-dashboard', 'CRM – Zgłoszenia', 'CRM – Zgłoszenia' . $badge, 'manage_options', 'bcs-registrations', [__CLASS__, 'registrations']);
        add_submenu_page('bcs-dashboard', 'Faktury', 'Faktury', 'manage_options', 'bcs-invoices', ['BCS_Invoices', 'page']);
        add_submenu_page('bcs-dashboard', 'Turnusy', 'Turnusy', 'manage_options', 'bcs-camps', [__CLASS__, 'camps']);
        add_submenu_page('bcs-dashboard', 'Organizatorzy', 'Organizatorzy', 'manage_options', 'bcs-organizers', [__CLASS__, 'organizers']);
        $unread=class_exists('BCS_Mailbox')?BCS_Mailbox::unread_count():0; $mail_badge=$unread?' <span class="awaiting-mod count-'.$unread.'"><span class="plugin-count">'.$unread.'</span></span>':'';
        add_submenu_page('bcs-dashboard', 'Poczta', 'Poczta'.$mail_badge, 'manage_options', 'bcs-mailbox', ['BCS_Mailbox', 'page']);
        add_submenu_page('bcs-dashboard', 'Szablony', 'Szablony', 'manage_options', 'bcs-templates', ['BCS_Templates', 'page']);
        add_submenu_page('bcs-dashboard', 'Feedback', BCS_Feedback::menu_label(), 'manage_options', 'bcs-feedback', ['BCS_Feedback', 'page']);
        add_submenu_page('bcs-dashboard', 'Logi', 'Logi', 'manage_options', 'bcs-logs', [__CLASS__, 'logs']);
        add_submenu_page('bcs-dashboard', 'Ustawienia', 'Ustawienia', 'manage_options', 'bcs-settings', [__CLASS__, 'settings']);
    }

    public static function action_required_condition(string $alias = 'r'): string {
        global $wpdb;
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'r';
        $payments = BCS_DB::table('payments');
        $invoice_date_condition = (class_exists('BCS_Workflow') && BCS_Workflow_Engine::test_mode_enabled())
            ? '1=1'
            : "EXISTS (SELECT 1 FROM " . BCS_DB::table('camps') . " c_invoice WHERE c_invoice.id = {$a}.camp_id AND CURDATE() >= STR_TO_DATE(CONCAT(YEAR(c_invoice.start_date), '-01-01'), '%Y-%m-%d'))";

        return "{$a}.status <> 'cancelled' AND (
            {$a}.status = 'new'
            OR ({$a}.form_status = 'complete' AND {$a}.form_verified_at IS NULL AND {$a}.status <> 'new')
            OR {$a}.status = 'draft_sent'
            OR ({$a}.agreement_status = 'accepted' AND {$a}.paid_amount < {$a}.total_amount AND {$a}.status <> 'stripe_link_sent')
            OR (
                {$a}.paid_amount >= {$a}.total_amount
                AND {$a}.total_amount > 0
                AND {$a}.agreement_status = 'accepted'
                AND {$a}.invoice_status <> 'sent'
                AND ({$invoice_date_condition})
            )
        )";
    }

    public static function action_required_count(): int {
        global $wpdb;
        $registrations = BCS_DB::table('registrations');
        $condition = self::action_required_condition('r');
        $count = $wpdb->get_var("SELECT COUNT(DISTINCT r.id) FROM {$registrations} r WHERE {$condition}");
        return max(0, (int) $count);
    }

    public static function assets(string $hook): void {
        if (strpos($hook, 'bcs-') === false) return;
        wp_enqueue_style('bcs-admin', BCS_URL . 'assets/admin.css', [], BCS_VERSION);
        wp_enqueue_script('bcs-admin', BCS_URL . 'assets/admin.js', [], BCS_VERSION, true);
    }

    private static function redirect(string $page, array $args = []): void {
        wp_safe_redirect(add_query_arg(array_merge(['page' => $page], $args), admin_url('admin.php')));
        exit;
    }

    public static function save_actions(): void {
        if (!current_user_can('manage_options')) return;

        if (isset($_POST['bcs_test_email'])) self::test_email();
        if (isset($_POST['bcs_test_sms'])) self::test_sms();

        if (isset($_POST['bcs_save_settings'])) {
            check_admin_referer('bcs_save_settings');
            $existing_settings = get_option('bcs_settings', []);
            $sms_token = sanitize_text_field(wp_unslash($_POST['smsapi_token'] ?? ''));
            $justsend_key = sanitize_text_field(wp_unslash($_POST['justsend_app_key'] ?? ''));
            $smsplanet_token = sanitize_text_field(wp_unslash($_POST['smsplanet_token'] ?? ''));
            $sms_provider = in_array($_POST['sms_provider'] ?? 'smsapi', ['smsapi','justsend','smsplanet'], true) ? $_POST['sms_provider'] : 'smsapi';
            $justsend_variant = in_array($_POST['justsend_variant'] ?? 'ECO', ['ECO','FULL','PRO'], true) ? $_POST['justsend_variant'] : 'ECO';
            delete_transient('bcs_smsapi_profile');
            delete_transient('bcs_smsplanet_balance');
            update_option('bcs_settings', [
                'sms_provider' => $sms_provider,
                'smsapi_token' => $sms_token !== '' ? $sms_token : (string)($existing_settings['smsapi_token'] ?? ''),
                'sms_sender' => sanitize_text_field(wp_unslash($_POST['sms_sender'] ?? '')),
                'smsapi_sms_cost' => max(0, (float)str_replace(',', '.', (string)($_POST['smsapi_sms_cost'] ?? ($existing_settings['smsapi_sms_cost'] ?? 0)))),
                'justsend_app_key' => $justsend_key !== '' ? $justsend_key : (string)($existing_settings['justsend_app_key'] ?? ''),
                'justsend_variant' => $justsend_variant,
                'justsend_sender' => BCS_SMS::to_ascii(sanitize_text_field(wp_unslash($_POST['justsend_sender'] ?? ''))),
                'smsplanet_token' => $smsplanet_token !== '' ? $smsplanet_token : (string)($existing_settings['smsplanet_token'] ?? ''),
                'smsplanet_sender' => BCS_SMS::to_ascii(sanitize_text_field(wp_unslash($_POST['smsplanet_sender'] ?? ''))),
                'smsplanet_transactional' => isset($_POST['smsplanet_transactional']) ? 1 : 0,
                'smsplanet_sms_cost' => max(0, (float)str_replace(',', '.', (string)($_POST['smsplanet_sms_cost'] ?? ($existing_settings['smsplanet_sms_cost'] ?? 0)))),
                'otp_minutes' => max(2,min(30,absint($_POST['otp_minutes'] ?? 2))),
                'otp_send_limit' => max(1,min(20,absint($_POST['otp_send_limit'] ?? 3))),
                'registration_lock_minutes' => max(1,min(30,absint($_POST['registration_lock_minutes'] ?? 3))),
                'max_attempts' => max(1,min(20,absint($_POST['max_attempts'] ?? 5))),
                'company_name' => sanitize_text_field(wp_unslash($_POST['company_name'] ?? 'Basketmania Camp')),
                'company_email' => sanitize_email(wp_unslash($_POST['company_email'] ?? get_option('admin_email'))),
                'mail_from_name' => sanitize_text_field(wp_unslash($_POST['mail_from_name'] ?? 'Basketmania Camp')),
                'mail_from_email' => sanitize_email(wp_unslash($_POST['mail_from_email'] ?? ($_POST['company_email'] ?? get_option('admin_email')))),
                'mail_reply_to' => sanitize_email(wp_unslash($_POST['mail_reply_to'] ?? get_option('admin_email'))),
                'mail_transport' => in_array($_POST['mail_transport'] ?? 'wordpress', ['wordpress','smtp'], true) ? $_POST['mail_transport'] : 'wordpress',
                'smtp_host' => sanitize_text_field(wp_unslash($_POST['smtp_host'] ?? '')),
                'smtp_port' => max(1, absint($_POST['smtp_port'] ?? 587)),
                'smtp_encryption' => in_array($_POST['smtp_encryption'] ?? 'tls', ['none','ssl','tls'], true) ? $_POST['smtp_encryption'] : 'tls',
                'smtp_auth' => isset($_POST['smtp_auth']) ? 1 : 0,
                'smtp_username' => sanitize_text_field(wp_unslash($_POST['smtp_username'] ?? '')),
                'smtp_password' => trim((string)wp_unslash($_POST['smtp_password'] ?? '')) !== '' ? (string)wp_unslash($_POST['smtp_password']) : (string)($existing_settings['smtp_password'] ?? ''),
                'imap_enabled' => isset($_POST['imap_enabled']) ? 1 : 0,
                'imap_host' => sanitize_text_field(wp_unslash($_POST['imap_host'] ?? '')),
                'imap_port' => max(1, absint($_POST['imap_port'] ?? 993)),
                'imap_encryption' => in_array($_POST['imap_encryption'] ?? 'ssl', ['none','ssl','tls'], true) ? $_POST['imap_encryption'] : 'ssl',
                'imap_novalidate' => isset($_POST['imap_novalidate']) ? 1 : 0,
                'imap_username' => sanitize_text_field(wp_unslash($_POST['imap_username'] ?? '')),
                'imap_password' => trim((string)wp_unslash($_POST['imap_password'] ?? '')) !== '' ? (string)wp_unslash($_POST['imap_password']) : (string)($existing_settings['imap_password'] ?? ''),
                'imap_folder' => sanitize_text_field(wp_unslash($_POST['imap_folder'] ?? 'INBOX')),
                'imap_frequency' => in_array($_POST['imap_frequency'] ?? 'bcs_ten_minutes', ['bcs_five_minutes','bcs_ten_minutes','hourly'], true) ? $_POST['imap_frequency'] : 'bcs_ten_minutes',
                'agreement_prefix' => sanitize_key(wp_unslash($_POST['agreement_prefix'] ?? 'BC')),
                'invoice_prefix' => sanitize_key(wp_unslash($_POST['invoice_prefix'] ?? 'FV')),
                'invoice_vat_rate' => (float)($_POST['invoice_vat_rate'] ?? 0),
                'invoice_exemption_basis' => sanitize_text_field(wp_unslash($_POST['invoice_exemption_basis'] ?? '')),
                'sales_document_type' => in_array($_POST['sales_document_type'] ?? 'invoice', ['invoice','receipt'], true) ? $_POST['sales_document_type'] : 'invoice',
                'automations_enabled' => isset($_POST['automations_enabled']) ? 1 : 0,
                'automation_channel' => in_array($_POST['automation_channel'] ?? '', ['email','sms','both'], true) ? $_POST['automation_channel'] : 'email',
                'agreement_reminder_days' => max(1, absint($_POST['agreement_reminder_days'] ?? 1)),
                'payment_reminder_days' => max(1, absint($_POST['payment_reminder_days'] ?? 2)),
                'pre_camp_days' => max(1, absint($_POST['pre_camp_days'] ?? 7)),
                'portal_logo_url' => esc_url_raw(wp_unslash($_POST['portal_logo_url'] ?? '')),
                'portal_brand_url' => esc_url_raw(wp_unslash($_POST['portal_brand_url'] ?? 'https://camp.basketmania.pl/')),
                'test_workflow_mode' => isset($_POST['test_workflow_mode']) ? 1 : 0,
            ]);
            if (class_exists('BCS_Mailbox')) BCS_Mailbox::ensure_schedule();
            add_settings_error('bcs', 'saved', 'Ustawienia zapisane.', 'updated');
        }

        if (isset($_POST['bcs_reset_test_data'])) self::reset_test_data();

        if (isset($_POST['bcs_save_organizer'])) self::save_organizer();
        if (isset($_POST['bcs_delete_organizer'])) self::delete_organizer();
        if (isset($_POST['bcs_save_camp'])) self::save_camp();
        if (isset($_POST['bcs_delete_camp'])) self::delete_camp();
        if (isset($_POST['bcs_save_registration'])) self::save_registration();
        if (isset($_POST['bcs_delete_registration'])) self::delete_registration();
    }

    private static function test_email(): void {
        check_admin_referer('bcs_test_email');
        $to = sanitize_email(wp_unslash($_POST['test_email_recipient'] ?? ''));
        if (!is_email($to)) { add_settings_error('bcs','test_email_bad','Podaj poprawny adres odbiorcy.','error'); return; }
        $ok = BCS_Mailer::send($to, 'Test wiadomości — Basketmania Camp', '<h2>Test wysyłki e-mail</h2><p>Konfiguracja wysyłki działa, jeżeli ta wiadomość dotarła.</p>');
        add_settings_error('bcs','test_email_result',$ok ? 'Wiadomość została przekazana przez: '.BCS_Mailer::transport_label().'. Sprawdź skrzynkę odbiorczą i SPAM.' : 'Błąd wysyłki: '.BCS_Mailer::last_error(),$ok?'updated':'error');
    }

    private static function test_sms(): void {
        check_admin_referer('bcs_test_sms');
        $phone = sanitize_text_field(wp_unslash($_POST['test_sms_phone'] ?? ''));
        if ($phone === '') { add_settings_error('bcs','test_sms_bad','Podaj numer telefonu.','error'); return; }
        $provider = BCS_SMS::provider_label();
        $result = BCS_SMS::send($phone, 'Basketmania Camp: test konfiguracji bramki SMS.');
        add_settings_error('bcs','test_sms_result',!empty($result['success']) ? 'SMS został przyjęty przez bramkę '.$provider.'.' : 'Błąd '.$provider.': '.(string)($result['error'] ?? 'Nieznany błąd.'),!empty($result['success'])?'updated':'error');
    }

    private static function reset_test_data(): void {
        check_admin_referer('bcs_reset_test_data');
        $challenge = get_transient('bcs_reset_challenge_' . get_current_user_id());
        $answer = isset($_POST['bcs_math_answer']) ? (int)$_POST['bcs_math_answer'] : PHP_INT_MIN;
        if (!is_array($challenge) || $answer !== (int)($challenge['answer'] ?? PHP_INT_MAX) || empty($_POST['bcs_reset_confirm'])) {
            add_settings_error('bcs', 'reset_failed', 'Nie usunięto danych. Potwierdź operację i podaj poprawny wynik działania matematycznego.', 'error');
            return;
        }
        global $wpdb;
        $tables = ['otp','agreement_versions','mail_messages','messages','logs','activities','payments','invoices','agreements','registrations'];
        foreach ($tables as $name) {
            $table = BCS_DB::table($name);
            $wpdb->query("DELETE FROM {$table}");
            $wpdb->query("ALTER TABLE {$table} AUTO_INCREMENT = 1");
        }
        $documents = trailingslashit(wp_upload_dir()['basedir']) . 'basketmania-documents';
        self::remove_directory_contents($documents);

        // Reset usuwa wyłącznie dane operacyjne. Konfiguracja wtyczki,
        // integracje, szablony, organizatorzy i turnusy pozostają bez zmian.
        delete_transient('bcs_sms_dashboard_stats');
        delete_transient('bcs_reset_challenge_' . get_current_user_id());
        wp_safe_redirect(add_query_arg(['page'=>'bcs-settings','bcs_reset_done'=>1], admin_url('admin.php')));
        exit;
    }

    private static function remove_directory_contents(string $directory): void {
        if (!is_dir($directory)) return;
        $items = scandir($directory);
        if (!is_array($items)) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) { self::remove_directory_contents($path); @rmdir($path); }
            else @unlink($path);
        }
    }

    private static function save_organizer(): void {
        check_admin_referer('bcs_save_organizer');
        global $wpdb;
        $id = absint($_POST['organizer_id'] ?? 0);
        $existing = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM " . BCS_DB::table('organizers') . " WHERE id=%d", $id)) : null;
        $preserve = static function(string $field) use ($existing): string {
            $value = sanitize_text_field(wp_unslash($_POST[$field] ?? ''));
            return $value !== '' ? $value : (string)($existing->{$field} ?? '');
        };
        $data = [
            'name' => sanitize_text_field(wp_unslash($_POST['org_name'] ?? '')),
            'legal_form' => sanitize_text_field(wp_unslash($_POST['legal_form'] ?? '')),
            'address' => sanitize_textarea_field(wp_unslash($_POST['org_address'] ?? '')),
            'nip' => sanitize_text_field(wp_unslash($_POST['nip'] ?? '')),
            'regon' => sanitize_text_field(wp_unslash($_POST['regon'] ?? '')),
            'krs' => sanitize_text_field(wp_unslash($_POST['krs'] ?? '')),
            'email' => sanitize_email(wp_unslash($_POST['org_email'] ?? '')),
            'phone' => sanitize_text_field(wp_unslash($_POST['org_phone'] ?? '')),
            'bank_name' => sanitize_text_field(wp_unslash($_POST['bank_name'] ?? '')),
            'bank_account' => preg_replace('/[^0-9A-Za-z]/', '', (string)($_POST['bank_account'] ?? '')),
            'transfer_title_template' => sanitize_text_field(wp_unslash($_POST['transfer_title_template'] ?? 'Umowa {{AGREEMENT_NUMBER}} – {{CHILD_NAME}}')),
            'invoice_prefix' => strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) wp_unslash($_POST['invoice_prefix'] ?? ''))),
            'representative' => sanitize_text_field(wp_unslash($_POST['representative'] ?? '')),
            'stripe_enabled' => isset($_POST['stripe_enabled']) ? 1 : 0,
            'stripe_mode' => ($_POST['stripe_mode'] ?? 'test') === 'live' ? 'live' : 'test',
            'stripe_test_secret_key' => $preserve('stripe_test_secret_key'),
            'stripe_test_webhook_secret' => $preserve('stripe_test_webhook_secret'),
            'stripe_live_secret_key' => $preserve('stripe_live_secret_key'),
            'stripe_live_webhook_secret' => $preserve('stripe_live_webhook_secret'),
            'updated_at' => BCS_Utils::now(),
        ];
        if ($id && $existing) $wpdb->update(BCS_DB::table('organizers'), $data, ['id' => $id]);
        else { $data['created_at'] = BCS_Utils::now(); $wpdb->insert(BCS_DB::table('organizers'), $data); $id = (int)$wpdb->insert_id; }
        self::redirect('bcs-organizers', ['saved' => 1, 'edit' => $id]);
    }

    private static function delete_organizer(): void {
        $id = absint($_POST['organizer_id'] ?? 0);
        check_admin_referer('bcs_delete_organizer_' . $id);
        global $wpdb;
        $count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . BCS_DB::table('camps') . " WHERE organizer_id=%d", $id));
        if ($count > 0) self::redirect('bcs-organizers', ['error' => 'organizer_has_camps']);
        $wpdb->delete(BCS_DB::table('organizers'), ['id' => $id]);
        self::redirect('bcs-organizers', ['deleted' => 1]);
    }

    private static function save_camp(): void {
        check_admin_referer('bcs_save_camp');
        global $wpdb;
        $id = absint($_POST['camp_id'] ?? 0);
        $existing = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM " . BCS_DB::table('camps') . " WHERE id=%d", $id)) : null;
        $data = [
            'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'slug' => sanitize_title(wp_unslash($_POST['slug'] ?? $_POST['name'] ?? '')),
            'start_date' => sanitize_text_field(wp_unslash($_POST['start_date'] ?? '')),
            'end_date' => sanitize_text_field(wp_unslash($_POST['end_date'] ?? '')),
            'location' => sanitize_text_field(wp_unslash($_POST['location'] ?? '')),
            'price' => (float)($_POST['price'] ?? 0),
            'capacity' => absint($_POST['capacity'] ?? 0),
            'organizer_id' => absint($_POST['organizer_id'] ?? 0),
            'status' => in_array($_POST['status'] ?? 'draft', ['open','draft','closed'], true) ? $_POST['status'] : 'draft',
            'updated_at' => BCS_Utils::now(),
        ];
        if ($id && $existing) $wpdb->update(BCS_DB::table('camps'), $data, ['id' => $id]);
        else { $data['created_at'] = BCS_Utils::now(); $wpdb->insert(BCS_DB::table('camps'), $data); $id = (int)$wpdb->insert_id; }
        self::redirect('bcs-camps', ['saved' => 1, 'edit' => $id]);
    }

    private static function delete_camp(): void {
        $id = absint($_POST['camp_id'] ?? 0);
        check_admin_referer('bcs_delete_camp_' . $id);
        global $wpdb;
        $count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . BCS_DB::table('registrations') . " WHERE camp_id=%d", $id));
        if ($count > 0) self::redirect('bcs-camps', ['error' => 'camp_has_registrations']);
        $wpdb->delete(BCS_DB::table('camps'), ['id' => $id]);
        self::redirect('bcs-camps', ['deleted' => 1]);
    }

    private static function save_registration(): void {
        $id = absint($_POST['registration_id'] ?? 0);
        check_admin_referer('bcs_save_registration_' . $id);
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . BCS_DB::table('registrations') . " WHERE id=%d", $id));
        if (!$existing) self::redirect('bcs-registrations', ['error' => 'not_found']);
        $status = sanitize_key(wp_unslash($_POST['status'] ?? $existing->status));
        if (!array_key_exists($status, BCS_Workflow_Engine::statuses())) $status = $existing->status;
        $agreement_status = sanitize_key(wp_unslash($_POST['agreement_status'] ?? $existing->agreement_status));
        if (!in_array($agreement_status, ['draft','pending','accepted','cancelled'], true)) $agreement_status = $existing->agreement_status;
        $data = [
            'camp_id' => absint($_POST['camp_id'] ?? $existing->camp_id),
            'status' => $status,
            'parent_first_name' => sanitize_text_field(wp_unslash($_POST['parent_first_name'] ?? '')),
            'parent_last_name' => sanitize_text_field(wp_unslash($_POST['parent_last_name'] ?? '')),
            'parent_email' => sanitize_email(wp_unslash($_POST['parent_email'] ?? '')),
            'parent_phone' => sanitize_text_field(wp_unslash($_POST['parent_phone'] ?? '')),
            'parent_postal_code' => sanitize_text_field(wp_unslash($_POST['parent_postal_code'] ?? '')),
            'parent_city' => sanitize_text_field(wp_unslash($_POST['parent_city'] ?? '')),
            'parent_street' => sanitize_text_field(wp_unslash($_POST['parent_street'] ?? '')),
            'parent_house_number' => sanitize_text_field(wp_unslash($_POST['parent_house_number'] ?? '')),
            'child_first_name' => sanitize_text_field(wp_unslash($_POST['child_first_name'] ?? '')),
            'child_last_name' => sanitize_text_field(wp_unslash($_POST['child_last_name'] ?? '')),
            'child_birth_date' => sanitize_text_field(wp_unslash($_POST['child_birth_date'] ?? '')),
            'child_height' => absint($_POST['child_height'] ?? 0),
            'child_pesel' => sanitize_text_field(wp_unslash($_POST['child_pesel'] ?? '')),
            'child_club' => sanitize_text_field(wp_unslash($_POST['child_club'] ?? '')),
            'shirt_size' => sanitize_text_field(wp_unslash($_POST['shirt_size'] ?? '')),
            'medical_notes' => sanitize_textarea_field(wp_unslash($_POST['medical_notes'] ?? '')),
            'dietary_notes' => sanitize_textarea_field(wp_unslash($_POST['dietary_notes'] ?? '')),
            'total_amount' => max(0, (float)($_POST['total_amount'] ?? $existing->total_amount)),
            'paid_amount' => max(0, (float)($_POST['paid_amount'] ?? $existing->paid_amount)),
            'agreement_status' => $agreement_status,
            'agreement_available_from' => sanitize_text_field(wp_unslash($_POST['agreement_available_from'] ?? '')),
            'payment_due_date' => sanitize_text_field(wp_unslash($_POST['payment_due_date'] ?? '')),
            'invoice_status' => sanitize_key(wp_unslash($_POST['invoice_status'] ?? $existing->invoice_status)),
            'updated_at' => BCS_Utils::now(),
        ];
        $data['parent_address'] = BCS_Utils::compose_address($data);
        if ($data['parent_address'] === '') $data['parent_address'] = sanitize_textarea_field(wp_unslash($_POST['parent_address'] ?? $existing->parent_address));
        if ($data['paid_amount'] > $data['total_amount']) $data['paid_amount'] = $data['total_amount'];
        $wpdb->update(BCS_DB::table('registrations'), $data, ['id' => $id]);
        BCS_Utils::log('registration_edited_by_admin', ['changed_fields' => array_keys($data)], $id, (int)$existing->agreement_id);
        self::redirect('bcs-registrations', ['saved' => 1, 'edit' => $id]);
    }

    private static function delete_registration(): void {
        $id = absint($_POST['registration_id'] ?? 0);
        check_admin_referer('bcs_delete_registration_' . $id);
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . BCS_DB::table('registrations') . " WHERE id=%d", $id));
        if (!$r) self::redirect('bcs-registrations', ['error' => 'not_found']);
        $agreement_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM " . BCS_DB::table('agreements') . " WHERE registration_id=%d", $id));
        foreach ($agreement_ids as $agreement_id) $wpdb->delete(BCS_DB::table('otp'), ['agreement_id' => (int)$agreement_id]);
        $invoice_paths = $wpdb->get_col($wpdb->prepare("SELECT file_path FROM " . BCS_DB::table('invoices') . " WHERE registration_id=%d", $id));
        $uploads = wp_upload_dir();
        foreach ($invoice_paths as $path) {
            $real = realpath((string)$path); $base = realpath((string)$uploads['basedir']);
            if ($real && $base && str_starts_with($real, $base) && is_file($real)) @unlink($real);
        }
        $wpdb->delete(BCS_DB::table('activities'), ['registration_id' => $id]);
        $wpdb->delete(BCS_DB::table('payments'), ['registration_id' => $id]);
        $wpdb->delete(BCS_DB::table('messages'), ['registration_id' => $id]);
        $wpdb->delete(BCS_DB::table('invoices'), ['registration_id' => $id]);
        $wpdb->delete(BCS_DB::table('logs'), ['registration_id' => $id]);
        $wpdb->delete(BCS_DB::table('agreements'), ['registration_id' => $id]);
        $wpdb->delete(BCS_DB::table('registrations'), ['id' => $id]);
        
        self::redirect('bcs-registrations', ['deleted' => 1]);
    }

    private static function notice(): void {
        if (!empty($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Zapisano zmiany.</p></div>';
        if (!empty($_GET['deleted'])) echo '<div class="notice notice-success is-dismissible"><p>Element został usunięty.</p></div>';
        $error = sanitize_key(wp_unslash($_GET['error'] ?? ''));
        $messages = [
            'organizer_has_camps' => 'Nie można usunąć organizatora, ponieważ ma przypisane turnusy. Najpierw zmień organizatora tych turnusów albo usuń turnusy.',
            'camp_has_registrations' => 'Nie można usunąć turnusu, ponieważ posiada zgłoszenia. Najpierw przenieś lub usuń zgłoszenia.',
            'not_found' => 'Nie znaleziono wskazanego elementu.',
        ];
        if ($error && isset($messages[$error])) echo '<div class="notice notice-error is-dismissible"><p>'.esc_html($messages[$error]).'</p></div>';
    }

    public static function dashboard(): void {
        global $wpdb;
        $camps = $wpdb->get_results("SELECT c.*,o.name organizer_name, COUNT(r.id) registrations,
            SUM(CASE WHEN r.agreement_status='accepted' THEN 1 ELSE 0 END) agreements,
            SUM(CASE WHEN r.paid_amount>=r.total_amount AND r.total_amount>0 THEN 1 ELSE 0 END) paid,
            SUM(CASE WHEN r.agreement_status<>'accepted' THEN 1 ELSE 0 END) agreement_pending,
            SUM(CASE WHEN r.agreement_status='accepted' AND r.paid_amount<r.total_amount THEN 1 ELSE 0 END) payment_pending,
            COALESCE(SUM(r.paid_amount),0) revenue, COALESCE(SUM(r.total_amount-r.paid_amount),0) due
            FROM ".BCS_DB::table('camps')." c LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id
            LEFT JOIN ".BCS_DB::table('registrations')." r ON r.camp_id=c.id AND r.status<>'cancelled'
            GROUP BY c.id ORDER BY c.start_date ASC");
        $totals = $wpdb->get_row("SELECT COUNT(*) registrations, SUM(CASE WHEN agreement_status='accepted' THEN 1 ELSE 0 END) agreements,
            SUM(CASE WHEN paid_amount>=total_amount AND total_amount>0 THEN 1 ELSE 0 END) paid,
            COALESCE(SUM(paid_amount),0) revenue, COALESCE(SUM(total_amount-paid_amount),0) due
            FROM ".BCS_DB::table('registrations')." WHERE status<>'cancelled'");
        $new_registrations = $wpdb->get_results("SELECT r.id,r.parent_first_name,r.parent_last_name,r.child_first_name,r.child_last_name,r.created_at,c.name camp_name FROM ".BCS_DB::table('registrations')." r LEFT JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id WHERE r.status='new' ORDER BY r.created_at DESC LIMIT 6");
        echo '<div class="wrap bcs-admin"><div class="bcs-page-head"><div><h1>Basketmania Camp</h1><p>Aktualny stan zapisów, umów i płatności. <span class="bcs-version-label">Wersja ' . esc_html(BCS_VERSION) . '</span></p></div></div>';
        if (!BCS_PDF::available()) echo '<div class="notice notice-warning inline"><p><strong>PDF:</strong> silnik Dompdf nie został wykryty.</p></div>';
        echo '<div class="bcs-kpis">';
        $cards = [['Zgłoszenia',(int)($totals->registrations??0),'dashicons-groups'],['Potwierdzone umowy',(int)($totals->agreements??0),'dashicons-yes-alt'],['W pełni opłaceni',(int)($totals->paid??0),'dashicons-money-alt'],['Wpłacono',number_format((float)($totals->revenue??0),2,',',' ').' zł','dashicons-chart-line'],['Pozostało',number_format((float)($totals->due??0),2,',',' ').' zł','dashicons-warning']];
        foreach ($cards as $c) echo '<div class="bcs-kpi"><span class="dashicons '.esc_attr($c[2]).'"></span><div><small>'.esc_html($c[0]).'</small><strong>'.esc_html((string)$c[1]).'</strong></div></div>';
        echo '</div>';
        echo '<section class="bcs-panel bcs-new-registrations"><div class="bcs-panel-head"><div><h2>Nowe zgłoszenia</h2><p>Najnowsze zgłoszenia oczekujące na pierwszą decyzję administratora.</p></div><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-registrations&status=new')).'">Zobacz wszystkie</a></div>';
        if ($new_registrations) {
            echo '<div class="bcs-new-registration-list">';
            foreach ($new_registrations as $nr) {
                echo '<a href="'.esc_url(admin_url('admin.php?page=bcs-registrations&view='.(int)$nr->id)).'" class="bcs-new-registration-item"><span class="dashicons dashicons-admin-users"></span><div><strong>'.esc_html(trim($nr->parent_first_name.' '.$nr->parent_last_name)).'</strong><small>Uczestnik: '.esc_html(trim($nr->child_first_name.' '.$nr->child_last_name)).' · '.esc_html($nr->camp_name?:'Brak turnusu').'</small></div><time>'.esc_html(BCS_Utils::format_datetime($nr->created_at)).'</time></a>';
            }
            echo '</div>';
        } else echo '<div class="bcs-empty">Brak nowych zgłoszeń oczekujących na obsługę.</div>';
        echo '</section>';
        self::sms_dashboard_module();
        echo '<div class="bcs-camp-grid">';
        foreach ($camps as $c) {
            $capacity = max(1,(int)$c->capacity); $fill = min(100, round(((int)$c->registrations/$capacity)*100));
            echo '<article class="bcs-camp-card"><div class="bcs-card-top"><div><div class="bcs-card-labels"><span class="bcs-badge status-'.esc_attr($c->status).'">'.esc_html(self::camp_status_label($c->status)).'</span><span class="bcs-id">#'.(int)$c->id.'</span></div><h2>'.esc_html($c->name).'</h2><p>'.esc_html(($c->start_date?:'—').' – '.($c->end_date?:'—')).' · '.esc_html($c->location?:'Brak miejsca').'</p></div><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-camps&edit='.$c->id)).'">Edytuj</a></div>';
            echo '<div class="bcs-progress"><span style="width:'.esc_attr((string)$fill).'%"></span></div><div class="bcs-progress-meta"><strong>'.(int)$c->registrations.' / '.(int)$c->capacity.' miejsc</strong><span>'.$fill.'%</span></div>';
            echo '<div class="bcs-stat-grid"><div><span>Umowy</span><strong>'.(int)$c->agreements.'</strong></div><div><span>Opłaceni</span><strong>'.(int)$c->paid.'</strong></div><div><span>Bez umowy</span><strong>'.(int)$c->agreement_pending.'</strong></div><div><span>Do zapłaty</span><strong>'.(int)$c->payment_pending.'</strong></div></div>';
            echo '<div class="bcs-money"><div><span>Wpłacono</span><strong>'.number_format((float)$c->revenue,2,',',' ').' zł</strong></div><div><span>Pozostało</span><strong>'.number_format((float)$c->due,2,',',' ').' zł</strong></div></div><p class="bcs-muted">Organizator: '.esc_html($c->organizer_name?:'nie przypisano').'</p></article>';
        }
        if (!$camps) echo '<div class="bcs-empty">Nie utworzono jeszcze żadnego turnusu.</div>';
        echo '</div></div>';
    }


    private static function sms_dashboard_module(): void {
        $st = BCS_SMS::dashboard_stats();
        $status = (string)($st['connection_status'] ?? 'unknown');
        $status_labels = ['connected'=>'Połączono','error'=>'Błąd połączenia','not_configured'=>'Brak konfiguracji','unknown'=>'Niezweryfikowano'];
        $status_class = $status === 'connected' ? 'is-ok' : ($status === 'error' ? 'is-error' : 'is-warning');
        echo '<section class="bcs-sms-dashboard"><div class="bcs-sms-dashboard-head"><div><span class="dashicons dashicons-smartphone"></span><div><small>Aktywna bramka SMS</small><h2>'.esc_html((string)$st['provider_label']).'</h2></div></div><span class="bcs-sms-status '.esc_attr($status_class).'">'.esc_html($status_labels[$status] ?? 'Nieznany').'</span></div>';
        echo '<div class="bcs-sms-dashboard-grid">';
        $balance = $st['balance'] === null ? 'Niedostępne' : number_format((float)$st['balance'], 2, ',', ' ').' '.(string)$st['balance_unit'];
        $remaining = $st['remaining_estimate'] === null ? 'Niedostępne' : 'ok. '.number_format((int)$st['remaining_estimate'],0,',',' ');
        $items = [
            ['Saldo konta',$balance],
            ['Szacunkowo pozostało',$remaining],
            ['Wysłane dziś',(string)(int)$st['sent_today']],
            ['Wysłane w tym miesiącu',(string)(int)$st['sent_month']],
            ['Wysłane przez aktywną bramkę',(string)(int)$st['sent_total']],
            ['Segmenty SMS',(string)(int)$st['segments_total']],
            ['Nieudane próby',(string)(int)$st['failed_total']],
            ['Historia systemu łącznie',(string)(int)$st['local_history_total']],
        ];
        foreach ($items as $item) echo '<div><span>'.esc_html($item[0]).'</span><strong>'.esc_html($item[1]).'</strong></div>';
        echo '</div>';
        if (!empty($st['api_error'])) echo '<p class="bcs-sms-dashboard-error"><strong>API:</strong> '.esc_html((string)$st['api_error']).'</p>';
        echo '<div class="bcs-sms-dashboard-foot"><p>'.esc_html((string)$st['note']).'</p><div><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-settings')).'">Ustawienia bramki</a></div></div></section>';
    }

    private static function camp_status_label(string $status): string { return ['open'=>'Otwarte','draft'=>'Szkic','closed'=>'Zamknięte'][$status] ?? $status; }

    public static function organizers(): void {
        global $wpdb; self::notice();
        $rows = $wpdb->get_results("SELECT o.*, COUNT(c.id) camps_count FROM ".BCS_DB::table('organizers')." o LEFT JOIN ".BCS_DB::table('camps')." c ON c.organizer_id=o.id GROUP BY o.id ORDER BY o.name");
        $edit_id = absint($_GET['edit'] ?? 0);
        $edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('organizers')." WHERE id=%d",$edit_id)) : null;
        echo '<div class="wrap bcs-admin"><div class="bcs-page-head"><div><h1>Organizatorzy</h1><p>Zarządzaj podmiotami, rachunkami bankowymi i kontami Stripe.</p></div><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bcs-organizers')).'">Dodaj organizatora</a></div>';
        echo '<div class="bcs-list-grid">';
        foreach ($rows as $r) {
            echo '<article class="bcs-list-card"><div class="bcs-card-top"><div><div class="bcs-card-labels"><span class="bcs-badge '.((int)$r->stripe_enabled?'status-open':'status-draft').'">'.((int)$r->stripe_enabled?'Stripe aktywny':'Stripe wyłączony').'</span><span class="bcs-id">#'.(int)$r->id.'</span></div><h2>'.esc_html($r->name).'</h2><p>'.esc_html($r->legal_form?:'Brak formy prawnej').'</p></div><strong class="bcs-count">'.(int)$r->camps_count.' turn.</strong></div>';
            echo '<dl><div><dt>NIP</dt><dd>'.esc_html($r->nip?:'—').'</dd></div><div><dt>Rachunek</dt><dd>'.esc_html($r->bank_account?:'—').'</dd></div><div><dt>E-mail</dt><dd>'.esc_html($r->email?:'—').'</dd></div></dl><div class="bcs-card-actions"><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-organizers&edit='.$r->id)).'">Edytuj</a>';
            echo '<form method="post" class="bcs-inline-delete" data-confirm="Usunąć organizatora? Tej operacji nie można cofnąć.">'; wp_nonce_field('bcs_delete_organizer_'.$r->id); echo '<input type="hidden" name="organizer_id" value="'.(int)$r->id.'"><button class="button button-link-delete" name="bcs_delete_organizer" value="1" '.((int)$r->camps_count?'disabled title="Najpierw usuń lub przenieś turnusy"':'').'>Usuń</button></form></div></article>';
        }
        if (!$rows) echo '<div class="bcs-empty">Brak organizatorów. Dodaj pierwszy podmiot poniżej.</div>';
        echo '</div>';
        self::organizer_form($edit);
        echo '</div>';
    }

    private static function organizer_form(?object $edit): void {
        $id = (int)($edit->id ?? 0); $v = static fn(string $f,string $d='') => esc_attr((string)($edit->{$f} ?? $d));
        echo '<section class="bcs-panel"><div class="bcs-panel-head"><h2>'.($edit?'Edytuj organizatora #'.$id:'Dodaj organizatora').'</h2>'.($edit?'<a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-organizers')).'">Anuluj edycję</a>':'').'</div><form method="post">'; wp_nonce_field('bcs_save_organizer');
        echo '<input type="hidden" name="organizer_id" value="'.$id.'"><div class="bcs-form-grid">';
        $fields=['org_name'=>['Pełna nazwa','name','text'],'legal_form'=>['Forma prawna','legal_form','text'],'nip'=>['NIP','nip','text'],'regon'=>['REGON','regon','text'],'krs'=>['KRS','krs','text'],'representative'=>['Osoba reprezentująca','representative','text'],'org_email'=>['E-mail','email','email'],'org_phone'=>['Telefon','phone','text'],'bank_name'=>['Nazwa banku','bank_name','text'],'bank_account'=>['Numer rachunku','bank_account','text'],'transfer_title_template'=>['Szablon tytułu przelewu','transfer_title_template','text'],'invoice_prefix'=>['Prefiks organizatora','invoice_prefix','text']];
        foreach($fields as $name=>$f) echo '<label><span>'.esc_html($f[0]).'</span><input type="'.esc_attr($f[2]).'" name="'.esc_attr($name).'" value="'.$v($f[1],$name==='transfer_title_template'?'Umowa {{AGREEMENT_NUMBER}} – {{CHILD_NAME}}':'').'" '.(in_array($name,['org_name','bank_account','invoice_prefix'],true)?'required':'').($name==='invoice_prefix'?' maxlength="40" pattern="[A-Za-z0-9_-]+" placeholder="np. BMC"':'').'></label>';
        echo '<p class="description bcs-span-2">Prefiks jest używany w numerach umów i faktur: <code>[prefiks dokumentu z Ustawień]/[prefiks organizatora]/[rok]/[numer]</code>. Może zawierać litery, cyfry, myślnik i podkreślenie.</p>';
        echo '<label class="bcs-span-2"><span>Adres siedziby</span><textarea rows="3" name="org_address" required>'.esc_textarea((string)($edit->address??'')).'</textarea></label></div>';
        echo '<div class="bcs-subpanel"><h3>Stripe</h3><div class="bcs-form-grid"><label class="bcs-checkbox"><input type="checkbox" name="stripe_enabled" value="1" '.checked((int)($edit->stripe_enabled??0),1,false).'><span>Włącz Stripe dla organizatora</span></label><label><span>Tryb</span><select name="stripe_mode"><option value="test" '.selected((string)($edit->stripe_mode??'test'),'test',false).'>Testowy</option><option value="live" '.selected((string)($edit->stripe_mode??'test'),'live',false).'>Produkcyjny</option></select></label>';
        foreach(['stripe_test_secret_key'=>'Testowy klucz tajny','stripe_test_webhook_secret'=>'Testowy sekret webhooka','stripe_live_secret_key'=>'Produkcyjny klucz tajny','stripe_live_webhook_secret'=>'Produkcyjny sekret webhooka'] as $n=>$l) echo '<label><span>'.esc_html($l).'</span><input type="password" autocomplete="new-password" name="'.esc_attr($n).'" placeholder="'.(!empty($edit->{$n})?'Klucz zapisany — pozostaw puste':'').'"></label>';
        echo '</div>';
        if ($id) echo '<p><strong>Webhook:</strong> <code>'.esc_html(rest_url('bcs/v1/stripe-webhook/'.$id)).'</code></p>';
        echo '</div><div class="bcs-form-actions"><button class="button button-primary button-hero" name="bcs_save_organizer" value="1">'.($edit?'Zapisz zmiany':'Dodaj organizatora').'</button></div></form></section>';
    }

    public static function camps(): void {
        global $wpdb; self::notice();
        $rows = $wpdb->get_results("SELECT c.*,o.name organizer_name,COUNT(r.id) registrations FROM ".BCS_DB::table('camps')." c LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id LEFT JOIN ".BCS_DB::table('registrations')." r ON r.camp_id=c.id AND r.status<>'cancelled' GROUP BY c.id ORDER BY c.start_date DESC");
        $organizers = $wpdb->get_results("SELECT * FROM ".BCS_DB::table('organizers')." ORDER BY name");
        $edit_id = absint($_GET['edit'] ?? 0); $edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('camps')." WHERE id=%d",$edit_id)) : null;
        echo '<div class="wrap bcs-admin"><div class="bcs-page-head"><div><h1>Turnusy</h1><p>Terminy, limity, ceny, organizatorzy i dokumenty turnusów.</p></div><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bcs-camps')).'">Dodaj turnus</a></div><div class="bcs-list-grid">';
        foreach($rows as $r){$fill=$r->capacity?min(100,round(((int)$r->registrations/(int)$r->capacity)*100)):0;echo '<article class="bcs-list-card"><div class="bcs-card-top"><div><div class="bcs-card-labels"><span class="bcs-badge status-'.esc_attr($r->status).'">'.esc_html(self::camp_status_label($r->status)).'</span><span class="bcs-id">#'.(int)$r->id.'</span></div><h2>'.esc_html($r->name).'</h2><p>'.esc_html($r->start_date.' – '.$r->end_date).' · '.esc_html($r->location?:'—').'</p></div><strong class="bcs-count">'.(int)$r->registrations.'</strong></div><div class="bcs-progress"><span style="width:'.esc_attr((string)$fill).'%"></span></div><dl><div><dt>Cena</dt><dd>'.number_format((float)$r->price,2,',',' ').' zł</dd></div><div><dt>Limit</dt><dd>'.(int)$r->capacity.'</dd></div><div><dt>Organizator</dt><dd>'.esc_html($r->organizer_name?:'—').'</dd></div></dl><div class="bcs-card-actions"><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-camps&edit='.$r->id)).'">Edytuj</a><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-registrations&camp_id='.$r->id)).'">Zgłoszenia</a><form method="post" class="bcs-inline-delete" data-confirm="Usunąć turnus? Tej operacji nie można cofnąć.">';wp_nonce_field('bcs_delete_camp_'.$r->id);echo '<input type="hidden" name="camp_id" value="'.(int)$r->id.'"><button class="button button-link-delete" name="bcs_delete_camp" value="1" '.((int)$r->registrations?'disabled title="Turnus ma zgłoszenia"':'').'>Usuń</button></form></div></article>';}
        if(!$rows)echo '<div class="bcs-empty">Brak turnusów. Dodaj pierwszy turnus poniżej.</div>';echo '</div>';
        self::camp_form($edit,$organizers); echo '</div>';
    }

    private static function camp_form(?object $edit,array $organizers): void {
        $id=(int)($edit->id??0);$v=static fn(string $f,string $d='')=>esc_attr((string)($edit->{$f}??$d));
        echo '<section class="bcs-panel"><div class="bcs-panel-head"><h2>'.($edit?'Edytuj turnus #'.$id:'Dodaj turnus').'</h2>'.($edit?'<a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-camps')).'">Anuluj edycję</a>':'').'</div><form method="post">';wp_nonce_field('bcs_save_camp');echo '<input type="hidden" name="camp_id" value="'.$id.'"><div class="bcs-form-grid">';
        foreach([['name','Nazwa','text'],['slug','Slug','text'],['start_date','Data od','date'],['end_date','Data do','date'],['location','Miejsce','text'],['price','Cena','number'],['capacity','Limit miejsc','number']] as $f) echo '<label><span>'.esc_html($f[1]).'</span><input type="'.esc_attr($f[2]).'" '.($f[2]==='number'?'step="0.01" min="0"':'').' name="'.esc_attr($f[0]).'" value="'.$v($f[0]).'" required></label>';
        echo '<label><span>Organizator</span><select name="organizer_id" required><option value="">— wybierz —</option>';foreach($organizers as $o)echo '<option value="'.(int)$o->id.'" '.selected((int)($edit->organizer_id??0),(int)$o->id,false).'>'.esc_html($o->name).'</option>';echo '</select></label><label><span>Status</span><select name="status">';foreach(['open'=>'Otwarte','draft'=>'Szkic','closed'=>'Zamknięte'] as $k=>$l)echo '<option value="'.$k.'" '.selected((string)($edit->status??'draft'),$k,false).'>'.$l.'</option>';echo '</select></label></div>';
        echo '<p class="description bcs-span-2">Treści umowy, regulaminu oraz wiadomości wysyłanej przed obozem są zarządzane centralnie w module <a href="'.esc_url(admin_url('admin.php?page=bcs-templates')).'">Szablony</a>.</p><div class="bcs-form-actions"><button class="button button-primary button-hero" name="bcs_save_camp" value="1">'.($edit?'Zapisz zmiany':'Dodaj turnus').'</button></div></form></section>';
    }

    public static function registrations(): void {
        if (!empty($_GET['edit'])) self::registration_editor(absint($_GET['edit']));
        else BCS_CRM::page();
    }

    private static function registration_editor(int $id): void {
        global $wpdb; self::notice();
        $r=$wpdb->get_row($wpdb->prepare("SELECT r.*,c.name camp_name,a.agreement_number FROM ".BCS_DB::table('registrations')." r LEFT JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id WHERE r.id=%d",$id));
        if(!$r){echo '<div class="wrap"><h1>Nie znaleziono zgłoszenia</h1></div>';return;}
        if(empty($r->form_verified_at)) BCS_Locks::touch($id, get_current_user_id());
        $camps=$wpdb->get_results("SELECT id,name,start_date FROM ".BCS_DB::table('camps')." ORDER BY start_date DESC");
        echo '<div class="wrap bcs-admin"><div class="bcs-page-head"><div><a class="bcs-back" href="'.esc_url(admin_url('admin.php?page=bcs-registrations')).'">← Wróć do zgłoszeń</a><h1>Edytuj zgłoszenie #'.(int)$id.'</h1><p>'.esc_html($r->child_first_name.' '.$r->child_last_name).' · '.esc_html($r->camp_name).'</p></div><span class="bcs-badge status-open">'.esc_html(BCS_Workflow_Engine::statuses()[$r->status]??$r->status).'</span></div><form method="post">';wp_nonce_field('bcs_save_registration_'.$id);echo '<input type="hidden" name="registration_id" value="'.$id.'"><div class="bcs-two-cols"><section class="bcs-panel"><h2>Dane rodzica i uczestnika</h2><div class="bcs-form-grid">';
        $fields=[['parent_first_name','Imię rodzica','text'],['parent_last_name','Nazwisko rodzica','text'],['parent_email','E-mail','email'],['parent_phone','Telefon','text'],['child_first_name','Imię dziecka','text'],['child_last_name','Nazwisko dziecka','text'],['child_birth_date','Data urodzenia','date'],['child_height','Wzrost (cm)','number'],['child_pesel','PESEL','text'],['child_club','Klub','text'],['shirt_size','Rozmiar koszulki','text']];foreach($fields as $f)echo '<label><span>'.esc_html($f[1]).'</span><input type="'.esc_attr($f[2]).'" name="'.esc_attr($f[0]).'" value="'.esc_attr((string)$r->{$f[0]}).'" '.(in_array($f[0],['parent_first_name','parent_last_name','parent_email','parent_phone','child_first_name','child_last_name'],true)?'required':'').'></label>';
        echo '<label><span>Kod pocztowy</span><input name="parent_postal_code" value="'.esc_attr((string)($r->parent_postal_code ?? '')).'"></label><label><span>Miejscowość</span><input name="parent_city" value="'.esc_attr((string)($r->parent_city ?? '')).'"></label><label><span>Ulica</span><input name="parent_street" value="'.esc_attr((string)($r->parent_street ?? '')).'"></label><label><span>Nr domu / lokalu</span><input name="parent_house_number" value="'.esc_attr((string)($r->parent_house_number ?? '')).'"></label><input type="hidden" name="parent_address" value="'.esc_attr((string)$r->parent_address).'"><label class="bcs-span-2"><span>Informacje medyczne</span><textarea rows="4" name="medical_notes">'.esc_textarea((string)$r->medical_notes).'</textarea></label><label class="bcs-span-2"><span>Dieta i alergie</span><textarea rows="4" name="dietary_notes">'.esc_textarea((string)$r->dietary_notes).'</textarea></label></div></section><section class="bcs-panel"><h2>Proces i rozliczenie</h2><div class="bcs-form-grid"><label class="bcs-span-2"><span>Turnus</span><select name="camp_id">';foreach($camps as $c)echo '<option value="'.(int)$c->id.'" '.selected((int)$r->camp_id,(int)$c->id,false).'>'.esc_html($c->name.' — '.$c->start_date).'</option>';echo '</select></label><label><span>Status procesu</span><select name="status">';foreach(BCS_Workflow_Engine::statuses() as $k=>$l)echo '<option value="'.$k.'" '.selected($r->status,$k,false).'>'.esc_html($l).'</option>';echo '</select></label><label><span>Status umowy</span><select name="agreement_status">';foreach(['draft'=>'Draft','pending'=>'Oczekuje','accepted'=>'Zaakceptowana','cancelled'=>'Anulowana'] as $k=>$l)echo '<option value="'.$k.'" '.selected($r->agreement_status,$k,false).'>'.$l.'</option>';echo '</select></label><label><span>Indywidualna cena turnusu</span><input type="number" step="0.01" min="0" name="total_amount" value="'.esc_attr((string)$r->total_amount).'"></label><label><span>Kwota wpłacona</span><input type="number" step="0.01" min="0" name="paid_amount" value="'.esc_attr((string)$r->paid_amount).'"></label><label><span>Umowa dostępna od</span><input type="date" name="agreement_available_from" value="'.esc_attr((string)$r->agreement_available_from).'"></label><label><span>Termin płatności</span><input type="date" name="payment_due_date" value="'.esc_attr((string)$r->payment_due_date).'"></label><label class="bcs-span-2"><span>Status faktury</span><input type="text" name="invoice_status" value="'.esc_attr((string)$r->invoice_status).'"></label></div><div class="bcs-meta-list"><p><strong>Numer umowy:</strong> '.esc_html($r->agreement_number?:'—').'</p><p><strong>Utworzono:</strong> '.esc_html($r->created_at).'</p><p><strong>ID płatności:</strong> '.($r->payment_id?'#'.esc_html($r->payment_id):'—').'</p></div></section></div><div class="bcs-form-actions"><button class="button button-primary button-hero" name="bcs_save_registration" value="1">Zapisz zgłoszenie</button></div></form><section class="bcs-danger-zone"><h2>Usuń zgłoszenie</h2><p>Usunięcie jest trwałe. System usunie umowy, kody SMS, wiadomości, faktury i logi związane z tym zgłoszeniem, oraz powiązane dane płatności.</p><form method="post" class="bcs-inline-delete" data-confirm="Czy na pewno trwale usunąć zgłoszenie i wszystkie powiązane dane?">';wp_nonce_field('bcs_delete_registration_'.$id);echo '<input type="hidden" name="registration_id" value="'.$id.'"><button class="button button-link-delete" name="bcs_delete_registration" value="1">Trwale usuń zgłoszenie</button></form></section></div>';
    }

    public static function logs(): void {
        global $wpdb;
        $category = sanitize_key(wp_unslash($_GET['category'] ?? ''));
        $registration_id = absint($_GET['registration_id'] ?? 0);
        $page_num = max(1, absint($_GET['paged'] ?? 1));
        $per_page = 50;
        $where = ['1=1']; $args = [];
        if ($registration_id) { $where[] = 'registration_id=%d'; $args[] = $registration_id; }
        $where_sql = implode(' AND ', $where);
        $table = BCS_DB::table('logs');
        $query = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT 500";
        $all_rows = $args ? $wpdb->get_results($wpdb->prepare($query, ...$args)) : $wpdb->get_results($query);
        $filtered = [];
        foreach ($all_rows as $row) {
            $data = json_decode((string)$row->event_data, true);
            if (!is_array($data)) $data = ['wartosc' => (string)$row->event_data];
            $meta = BCS_Utils::event_category_meta((string)$row->event_type, $data);
            if ($category === '' || $meta['key'] === $category) $filtered[] = $row;
        }
        $total = count($filtered);
        $rows = array_slice($filtered, ($page_num-1)*$per_page, $per_page);
        echo '<div class="wrap bcs-admin"><div class="bcs-page-head"><div><h1>Logi systemowe</h1><p>Historia działań systemu uporządkowana według jednolitych kategorii.</p></div><span class="bcs-count">'.number_format_i18n($total).' wpisów</span></div>';
        echo '<section class="bcs-panel"><form method="get" class="bcs-log-filters"><input type="hidden" name="page" value="bcs-logs"><label><span>Kategoria</span><select name="category"><option value="">Wszystkie</option>';
        foreach (BCS_Utils::event_categories() as $key => $meta) echo '<option value="'.esc_attr($key).'" '.selected($category,$key,false).'>'.esc_html($meta['label']).'</option>';
        echo '</select></label><label><span>ID zgłoszenia</span><input type="number" min="1" name="registration_id" value="'.($registration_id ?: '').'"></label><button class="button button-primary">Filtruj</button><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-logs')).'">Wyczyść</a></form></section>';
        echo '<section class="bcs-panel"><div class="bcs-table-wrap"><table class="widefat striped"><thead><tr><th>Data</th><th>Zdarzenie</th><th>Wykonawca</th><th>Zgłoszenie</th><th>Szczegóły</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="5">Brak logów dla wybranych filtrów.</td></tr>';
        foreach($rows as $row){
            $data=json_decode((string)$row->event_data,true); if(!is_array($data))$data=['wartosc'=>(string)$row->event_data];
            $actor=BCS_Utils::infer_actor_type((string)$row->event_type,$data);$actor_label=BCS_Utils::actor_label($actor);if($actor==='administrator'&&!empty($data['_actor_display_name']))$actor_label=(string)$data['_actor_display_name'].(!empty($data['_actor_login'])?' ('.(string)$data['_actor_login'].')':'');$actor_class='bcs-log-actor-'.sanitize_html_class($actor);
            $meta=BCS_Utils::event_category_meta((string)$row->event_type,$data);
            $is_error=$meta['key']==='system_error';
            $details=wp_json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
            echo '<tr'.($is_error?' class="bcs-log-error"':'').'><td>'.esc_html(BCS_Utils::format_datetime($row->created_at)).'</td><td><div class="bcs-log-event"><span class="bcs-log-category-icon bcs-log-category-'.esc_attr($meta['key']).' dashicons dashicons-'.esc_attr($meta['icon']).'" title="'.esc_attr($meta['label']).'"></span><div><strong>'.esc_html(BCS_Utils::event_label((string)$row->event_type)).'</strong><br><small class="bcs-log-category-label">'.esc_html($meta['label']).'</small></div></div></td><td><span class="bcs-log-actor '.esc_attr($actor_class).'">'.esc_html($actor_label).'</span></td><td>'.($row->registration_id?'<a href="'.esc_url(admin_url('admin.php?page=bcs-registrations&view='.(int)$row->registration_id)).'">#'.(int)$row->registration_id.'</a>':'—').'</td><td><button type="button" class="button bcs-log-details" data-title="'.esc_attr(BCS_Utils::event_label((string)$row->event_type)).'" data-details="'.esc_attr($details).'" aria-label="Pokaż szczegóły"><span class="dashicons dashicons-visibility"></span><span>Szczegóły</span></button></td></tr>';
        }
        echo '</tbody></table></div></section>';
        $pages=(int)ceil($total/$per_page);if($pages>1)echo '<div class="tablenav"><div class="tablenav-pages">'.paginate_links(['base'=>add_query_arg('paged','%#%'),'format'=>'','current'=>$page_num,'total'=>$pages]).'</div></div>';
        echo '<div id="bcs-log-modal" class="bcs-log-modal" hidden><div class="bcs-log-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="bcs-log-modal-title"><button type="button" class="bcs-log-modal__close" aria-label="Zamknij"><span class="dashicons dashicons-no-alt"></span></button><h2 id="bcs-log-modal-title">Szczegóły zdarzenia</h2><pre class="bcs-log-data"></pre></div></div></div>';
    }

    public static function settings(): void {
        $s = get_option('bcs_settings', []);
        if (!empty($_GET['bcs_reset_done'])) add_settings_error('bcs','reset_done','Usunięto dane operacyjne. Konfiguracja wtyczki, organizatorzy, turnusy i szablony pozostały bez zmian.','updated');
        settings_errors('bcs');
        echo '<div class="wrap bcs-admin"><div class="bcs-page-head"><div><h1>Ustawienia</h1><p>Bramki SMS, zewnętrzna skrzynka e-mail, numeracja dokumentów i automatyzacje.</p></div></div>';

        echo '<section class="bcs-panel"><form method="post">';
        wp_nonce_field('bcs_save_settings');
        $active_provider = in_array($s['sms_provider'] ?? 'smsapi', ['smsapi','justsend','smsplanet'], true) ? $s['sms_provider'] : 'smsapi';
        echo '<details class="bcs-settings-accordion" open><summary><span><span class="dashicons dashicons-admin-generic"></span><strong>Ustawienia wtyczki</strong></span><span class="bcs-settings-summary">Podstawowe parametry systemu</span></summary><div class="bcs-settings-accordion-body"><div class="bcs-form-grid">';
        echo '<label><span>Czas blokady Formularza Obozowego (min)</span><input type="number" min="1" max="30" name="registration_lock_minutes" value="'.esc_attr($s['registration_lock_minutes'] ?? 3).'"><small>Blokada jest aktywna wyłącznie do czasu zaakceptowania Formularza Obozowego.</small></label>';
        echo '<label><span>Nazwa systemu</span><input name="company_name" value="'.esc_attr($s['company_name'] ?? 'Basketmania Camp').'"></label>';
        echo '<label><span>E-mail systemowy</span><input type="email" name="company_email" value="'.esc_attr($s['company_email'] ?? get_option('admin_email')).'"></label>';
        echo '<label><span>Logo panelu rodzica (URL)</span><input type="url" name="portal_logo_url" value="'.esc_attr($s['portal_logo_url'] ?? '').'"></label>';
        echo '<label><span>Strona marki</span><input type="url" name="portal_brand_url" value="'.esc_attr($s['portal_brand_url'] ?? 'https://camp.basketmania.pl/').'"></label>';
        echo '</div></div></details>';
        echo '<details class="bcs-settings-accordion"><summary><span><span class="dashicons dashicons-smartphone"></span><strong>Bramka SMS</strong></span><span class="bcs-settings-summary">Aktywna: '.esc_html(BCS_SMS::provider_label($active_provider)).'</span></summary><div class="bcs-settings-accordion-body">';
        echo '<div class="bcs-form-grid"><label class="bcs-span-2"><span>Aktywna bramka SMS</span><select name="sms_provider" id="bcs-sms-provider"><option value="smsapi" '.selected($active_provider,'smsapi',false).'>SMSAPI</option><option value="justsend" '.selected($active_provider,'justsend',false).'>JustSend</option><option value="smsplanet" '.selected($active_provider,'smsplanet',false).'>SMSPLANET.PL</option></select></label>';
        echo '<p class="description bcs-span-2">System korzysta tylko z wybranej bramki. Konfiguracja drugiego operatora pozostaje zapisana, dzięki czemu można przełączać dostawcę bez ponownego wpisywania kluczy.</p></div>';
        echo '<div class="bcs-sms-provider-box" data-bcs-provider-box="smsapi"><h3>Konfiguracja SMSAPI</h3><div class="bcs-form-grid">';
        echo '<label><span>Token SMSAPI</span><input type="password" name="smsapi_token" value="" placeholder="Pozostaw puste, aby zachować zapisany" autocomplete="new-password"></label>';
        echo '<label><span>Aktywne pole nadawcy SMS</span><input name="sms_sender" value="'.esc_attr($s['sms_sender'] ?? '').'" placeholder="np. BASKETMANIA"></label>';
        echo '<label><span>Orientacyjny koszt 1 SMS (pkt)</span><input type="number" min="0" step="0.0001" name="smsapi_sms_cost" value="'.esc_attr($s['smsapi_sms_cost'] ?? '').'" placeholder="np. 0,16"><small>Potrzebny wyłącznie do oszacowania liczby pozostałych SMS.</small></label>';
        echo '<p class="description bcs-span-2">Nazwa musi być zgodna z aktywnym polem nadawcy w panelu SMSAPI. Pole może pozostać puste.</p></div></div>';
        echo '<div class="bcs-sms-provider-box" data-bcs-provider-box="justsend"><h3>Konfiguracja JustSend API v3</h3><div class="bcs-form-grid">';
        echo '<label><span>Klucz App-Key</span><input type="password" name="justsend_app_key" value="" placeholder="Pozostaw puste, aby zachować zapisany" autocomplete="new-password"></label>';
        echo '<label><span>Wariant wysyłki</span><select name="justsend_variant"><option value="ECO" '.selected($s['justsend_variant'] ?? 'ECO','ECO',false).'>ECO / BASIC — numer 4777</option><option value="FULL" '.selected($s['justsend_variant'] ?? 'ECO','FULL',false).'>FULL / UNIQUE — gotowy nadpis</option><option value="PRO" '.selected($s['justsend_variant'] ?? 'ECO','PRO',false).'>PRO / DYNAMIC — własny nadpis</option></select></label>';
        echo '<label class="bcs-span-2"><span>Nadpis nadawcy JustSend</span><input name="justsend_sender" maxlength="11" value="'.esc_attr($s['justsend_sender'] ?? '').'" placeholder="np. Basketmania"></label>';
        echo '<p class="description bcs-span-2">Dla FULL i PRO wpisz nadpis aktywny na koncie JustSend. Nadpis jest zapisywany bez polskich znaków i może mieć maksymalnie 11 znaków.</p></div></div>';
        echo '<div class="bcs-sms-provider-box" data-bcs-provider-box="smsplanet"><h3>Konfiguracja SMSPLANET.PL API 2.3</h3><div class="bcs-form-grid">';
        echo '<label><span>Token API SMSPLANET.PL</span><input type="password" name="smsplanet_token" value="" placeholder="Pozostaw puste, aby zachować zapisany" autocomplete="new-password"></label>';
        echo '<label><span>Aktywne pole nadawcy</span><input name="smsplanet_sender" maxlength="11" value="'.esc_attr($s['smsplanet_sender'] ?? 'Basketmania').'" placeholder="np. Basketmania"></label>';
        echo '<label><span>Orientacyjny koszt 1 SMS (pkt)</span><input type="number" min="0" step="0.0001" name="smsplanet_sms_cost" value="'.esc_attr($s['smsplanet_sms_cost'] ?? '').'" placeholder="np. 1"><small>Używany tylko do oszacowania liczby pozostałych SMS na koncie PrePaid.</small></label>';
        echo '<label class="bcs-checkbox"><input type="checkbox" name="smsplanet_transactional" value="1" '.checked(!empty($s['smsplanet_transactional']),true,false).'><span>Używaj kanału transakcyjnego (wymaga aktywacji przez SMSPLANET.PL)</span></label>';
        echo '<p class="description bcs-span-2">Token wygenerujesz w panelu SMSPLANET.PL w zakładce API. Pole nadawcy musi być wcześniej zaakceptowane. System wysyła przez zalecaną metodę POST i automatycznie usuwa polskie znaki oraz linki.</p></div></div>';
        echo '<div class="bcs-form-grid bcs-sms-common-settings"><label><span>Ważność kodu SMS (min)</span><input type="number" min="2" max="30" name="otp_minutes" value="'.esc_attr($s['otp_minutes'] ?? 2).'"></label>';
        echo '<label><span>Limit wysyłek kodu OTP na godzinę</span><input type="number" min="1" max="20" name="otp_send_limit" value="'.esc_attr($s['otp_send_limit'] ?? 3).'"><small>Limit jest sprawdzany po stronie serwera w Panelu Rodzica.</small></label>';
        echo '<label><span>Maksymalna liczba błędnych prób wpisania kodu</span><input type="number" min="1" max="20" name="max_attempts" value="'.esc_attr($s['max_attempts'] ?? 5).'"></label></div></div></details>';

        $mail_transport = in_array($s['mail_transport'] ?? 'wordpress', ['wordpress','smtp'], true) ? $s['mail_transport'] : 'wordpress';
        echo '<details class="bcs-settings-accordion"><summary><span><span class="dashicons dashicons-email-alt"></span><strong>E-MAIL</strong></span><span class="bcs-settings-summary">Aktywna wysyłka: '.esc_html(BCS_Mailer::transport_label($s)).'</span></summary><div class="bcs-settings-accordion-body">';
        echo '<div class="bcs-form-grid"><label class="bcs-span-2"><span>Podstawowy sposób wysyłki</span><select name="mail_transport" id="bcs-mail-transport"><option value="wordpress" '.selected($mail_transport,'wordpress',false).'>WordPress / poczta hostingu</option><option value="smtp" '.selected($mail_transport,'smtp',false).'>Zewnętrzna skrzynka pocztowa SMTP</option></select></label>';
        echo '<label><span>Nazwa widoczna przy wiadomości</span><input name="mail_from_name" value="'.esc_attr($s['mail_from_name'] ?? 'Basketmania Camp').'" placeholder="Basketmania Camp"></label>';
        echo '<label><span>Adres nadawcy</span><input type="email" name="mail_from_email" value="'.esc_attr($s['mail_from_email'] ?? ($s['company_email'] ?? get_option('admin_email'))).'" placeholder="zapisy@basketmania.pl"></label>';
        echo '<label class="bcs-span-2"><span>Adres odpowiedzi Reply-To</span><input type="email" name="mail_reply_to" value="'.esc_attr($s['mail_reply_to'] ?? ($s['company_email'] ?? get_option('admin_email'))).'" placeholder="zapisy@basketmania.pl"></label></div>';
        echo '<div class="bcs-sms-provider-box" data-bcs-mail-box="smtp"><h3>Konfiguracja zewnętrznej skrzynki SMTP</h3><div class="bcs-form-grid">';
        echo '<label><span>Serwer SMTP</span><input name="smtp_host" value="'.esc_attr($s['smtp_host'] ?? '').'" placeholder="np. smtp.twojadomena.pl"></label>';
        echo '<label><span>Port SMTP</span><input type="number" min="1" max="65535" name="smtp_port" value="'.esc_attr($s['smtp_port'] ?? 587).'" placeholder="587"></label>';
        echo '<label><span>Szyfrowanie</span><select name="smtp_encryption"><option value="tls" '.selected($s['smtp_encryption'] ?? 'tls','tls',false).'>TLS / STARTTLS</option><option value="ssl" '.selected($s['smtp_encryption'] ?? 'tls','ssl',false).'>SSL</option><option value="none" '.selected($s['smtp_encryption'] ?? 'tls','none',false).'>Brak</option></select></label>';
        echo '<label class="bcs-checkbox"><input type="checkbox" name="smtp_auth" value="1" '.checked(!array_key_exists('smtp_auth',$s) || !empty($s['smtp_auth']),true,false).'><span>Serwer wymaga uwierzytelnienia</span></label>';
        echo '<label><span>Login / adres skrzynki</span><input name="smtp_username" value="'.esc_attr($s['smtp_username'] ?? '').'" autocomplete="username" placeholder="zapisy@basketmania.pl"></label>';
        echo '<label><span>Hasło skrzynki lub hasło aplikacji</span><input type="password" name="smtp_password" value="" autocomplete="new-password" placeholder="Pozostaw puste, aby zachować zapisane"></label>';
        echo '<p class="description bcs-span-2">Po wybraniu SMTP ta skrzynka staje się podstawową skrzynką nadawczą wszystkich wiadomości systemu. Zalecane jest użycie osobnego hasła aplikacji. Hasło jest przechowywane w ustawieniach WordPressa, dlatego dostęp do panelu i bazy danych powinien być odpowiednio zabezpieczony.</p></div></div>';
        echo '<hr><h3>Synchronizacja poczty przychodzącej IMAP</h3><div class="bcs-form-grid">';
        echo '<label class="bcs-checkbox bcs-span-2"><input type="checkbox" name="imap_enabled" value="1" '.checked(!empty($s['imap_enabled']),true,false).'><span>Włącz automatyczną synchronizację skrzynki odbiorczej</span></label>';
        echo '<label><span>Serwer IMAP</span><input name="imap_host" value="'.esc_attr($s['imap_host'] ?? '').'" placeholder="np. imap.twojadomena.pl"></label>';
        echo '<label><span>Port IMAP</span><input type="number" min="1" max="65535" name="imap_port" value="'.esc_attr($s['imap_port'] ?? 993).'"></label>';
        echo '<label><span>Szyfrowanie IMAP</span><select name="imap_encryption"><option value="ssl" '.selected($s['imap_encryption'] ?? 'ssl','ssl',false).'>SSL</option><option value="tls" '.selected($s['imap_encryption'] ?? 'ssl','tls',false).'>TLS</option><option value="none" '.selected($s['imap_encryption'] ?? 'ssl','none',false).'>Brak</option></select></label>';
        echo '<label class="bcs-checkbox"><input type="checkbox" name="imap_novalidate" value="1" '.checked(!empty($s['imap_novalidate']),true,false).'><span>Nie sprawdzaj certyfikatu SSL</span></label>';
        echo '<label><span>Login / adres skrzynki</span><input name="imap_username" value="'.esc_attr($s['imap_username'] ?? '').'" autocomplete="username"></label>';
        echo '<label><span>Hasło skrzynki / hasło aplikacji</span><input type="password" name="imap_password" value="" autocomplete="new-password" placeholder="Pozostaw puste, aby zachować zapisane"></label>';
        echo '<label><span>Folder odbiorczy</span><input name="imap_folder" value="'.esc_attr($s['imap_folder'] ?? 'INBOX').'" placeholder="INBOX"></label>';
        echo '<label><span>Częstotliwość synchronizacji</span><select name="imap_frequency"><option value="bcs_five_minutes" '.selected($s['imap_frequency'] ?? 'bcs_ten_minutes','bcs_five_minutes',false).'>Co 5 minut</option><option value="bcs_ten_minutes" '.selected($s['imap_frequency'] ?? 'bcs_ten_minutes','bcs_ten_minutes',false).'>Co 10 minut</option><option value="hourly" '.selected($s['imap_frequency'] ?? 'bcs_ten_minutes','hourly',false).'>Co godzinę</option></select></label>';
        echo '<p class="description bcs-span-2">Pełna synchronizacja wymaga aktywnego rozszerzenia PHP IMAP na serwerze. Zalecany jest prawdziwy cron serwera wywołujący wp-cron.php.</p></div>';
        echo '<p class="description">Po zapisaniu konfiguracji użyj testu e-mail poniżej. Adres nadawcy powinien być zgodny z kontem SMTP lub dozwolony przez operatora skrzynki.</p></div></details>';

        echo '<h2>Ustawienia dokumentów i automatyzacji</h2><div class="bcs-form-grid">';
        echo '<label><span>Prefiks umowy</span><input name="agreement_prefix" value="'.esc_attr($s['agreement_prefix'] ?? 'BC').'"></label>';
        echo '<label><span>Prefiks faktury</span><input name="invoice_prefix" value="'.esc_attr($s['invoice_prefix'] ?? 'FV').'"></label>';
        echo '<label><span>VAT (%)</span><input type="number" step="0.01" min="0" name="invoice_vat_rate" value="'.esc_attr($s['invoice_vat_rate'] ?? 0).'"></label>';
        echo '<label><span>Podstawa zwolnienia z VAT</span><input name="invoice_exemption_basis" value="'.esc_attr($s['invoice_exemption_basis'] ?? '').'"></label>';
        echo '<label class="bcs-checkbox bcs-span-2"><input type="checkbox" name="test_workflow_mode" value="1" '.checked((!array_key_exists('test_workflow_mode',$s) || !empty($s['test_workflow_mode'])),true,false).'><span><strong>Tryb testowy procesu</strong> — znosi ograniczenie daty 1 stycznia roku turnusu dla podpisywania umów i generowania faktur. Pozostałe warunki workflow nadal obowiązują. Panel rodzica wyświetla informację, że działa w wersji testowej.</span></label>';
        echo '<label><span>Dokument sprzedaży</span><select name="sales_document_type"><option value="invoice" '.selected($s['sales_document_type'] ?? 'invoice','invoice',false).'>Faktura</option><option value="receipt" '.selected($s['sales_document_type'] ?? 'invoice','receipt',false).'>Rachunek</option></select></label>';
        echo '<label class="bcs-checkbox"><input type="checkbox" name="automations_enabled" value="1" '.checked(!empty($s['automations_enabled']),true,false).'><span>Włącz automatyczne przypomnienia</span></label>';
        echo '<label><span>Kanał automatyzacji</span><select name="automation_channel"><option value="email" '.selected($s['automation_channel'] ?? 'email','email',false).'>E-mail</option><option value="sms" '.selected($s['automation_channel'] ?? 'email','sms',false).'>SMS</option><option value="both" '.selected($s['automation_channel'] ?? 'email','both',false).'>E-mail + SMS</option></select></label>';
        echo '<label><span>Przypomnienie o umowie (dni)</span><input type="number" min="1" name="agreement_reminder_days" value="'.esc_attr($s['agreement_reminder_days'] ?? 1).'"></label>';
        echo '<label><span>Przypomnienie o płatności (dni)</span><input type="number" min="1" name="payment_reminder_days" value="'.esc_attr($s['payment_reminder_days'] ?? 2).'"></label>';
        echo '<label><span>Informacje przed obozem (dni)</span><input type="number" min="1" name="pre_camp_days" value="'.esc_attr($s['pre_camp_days'] ?? 7).'"></label></div>';
        echo '<div class="bcs-form-actions"><button class="button button-primary button-hero" name="bcs_save_settings" value="1">Zapisz ustawienia</button></div></form></section>';

        echo '<section class="bcs-panel"><div class="bcs-panel-head"><div><h2>Test komunikacji</h2><p>Najpierw zapisz ustawienia, następnie wykonaj test.</p></div></div><div class="bcs-two-cols">';
        echo '<form method="post">'; wp_nonce_field('bcs_test_email');
        echo '<h3>Test e-mail</h3><label><span>Adres odbiorcy</span><input type="email" name="test_email_recipient" value="'.esc_attr(get_option('admin_email')).'" required></label><p><button class="button button-primary" name="bcs_test_email" value="1">Wyślij testowy e-mail</button></p></form>';
        echo '<form method="post">'; wp_nonce_field('bcs_test_sms');
        echo '<h3>Test aktywnej bramki SMS</h3><p class="description">Aktualnie wybrana: <strong>'.esc_html(BCS_SMS::provider_label($s['sms_provider'] ?? 'smsapi')).'</strong></p><label><span>Numer telefonu</span><input name="test_sms_phone" placeholder="48785646785" required></label><p><button class="button button-primary" name="bcs_test_sms" value="1">Wyślij testowy SMS</button></p></form></div></section>';

        $a=random_int(3,12); $b=random_int(2,9); $op=random_int(0,1)?'+':'−'; $answer=$op==='+'?$a+$b:$a-$b;
        if($answer<0){$tmp=$a;$a=$b;$b=$tmp;$answer=$a-$b;}
        set_transient('bcs_reset_challenge_'.get_current_user_id(),['answer'=>$answer],15*MINUTE_IN_SECONDS);
        echo '<section class="bcs-panel bcs-danger-zone"><div class="bcs-panel-head"><div><h2>Strefa testowa — wyczyszczenie systemu</h2><p>Operacja bezpowrotnie usuwa wszystkie zgłoszenia oraz powiązane umowy, płatności, faktury, wiadomości, logi, zadania i wygenerowane dokumenty PDF. Organizatorzy, turnusy, ustawienia wtyczki i strony WordPress pozostaną.</p></div><span class="dashicons dashicons-warning"></span></div><form method="post" onsubmit="return window.confirm(\'Czy na pewno usunąć wszystkie zgłoszenia i ich dane powiązane? Organizatorzy i turnusy pozostaną. Tej operacji nie można cofnąć.\');">';
        wp_nonce_field('bcs_reset_test_data');
        echo '<div class="bcs-reset-confirm"><label><span>Kontrola bezpieczeństwa: ile to <strong>'.$a.' '.$op.' '.$b.'</strong>?</span><input type="number" name="bcs_math_answer" required autocomplete="off"></label><label class="bcs-checkbox"><input type="checkbox" name="bcs_reset_confirm" value="1" required><span>Rozumiem, że wszystkie dane operacyjne, logi, wiadomości, dokumenty, płatności, faktury i zgłoszenia zostaną trwale usunięte. Konfiguracja wtyczki, organizatorzy, turnusy i szablony pozostaną.</span></label><button type="submit" class="button bcs-danger-button" name="bcs_reset_test_data" value="1"><span class="dashicons dashicons-trash"></span> Wyczyść dane operacyjne</button></div></form></section></div>';
    }

}
