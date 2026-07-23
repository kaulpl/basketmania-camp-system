<?php
if (!defined('ABSPATH')) exit;

class BCS_DB {
    public const DB_VERSION = '0.20.5';
    public static function init(): void {}

    public static function maybe_upgrade(): void {
        if (get_option('bcs_db_version') !== self::DB_VERSION) self::activate();
    }

    public static function table(string $name): string {
        global $wpdb;
        return $wpdb->prefix . 'bcs_' . $name;
    }

    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $sql = [];
        $sql[] = "CREATE TABLE " . self::table('organizers') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            legal_form VARCHAR(100) NULL,
            address TEXT NOT NULL,
            nip VARCHAR(20) NULL,
            regon VARCHAR(20) NULL,
            krs VARCHAR(20) NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(30) NULL,
            bank_name VARCHAR(190) NULL,
            bank_account VARCHAR(50) NOT NULL,
            transfer_title_template VARCHAR(255) NULL,
            invoice_prefix VARCHAR(40) NULL,
            representative VARCHAR(190) NULL,
            stripe_enabled TINYINT(1) NOT NULL DEFAULT 0,
            stripe_mode VARCHAR(10) NOT NULL DEFAULT 'test',
            stripe_test_secret_key TEXT NULL,
            stripe_test_webhook_secret TEXT NULL,
            stripe_live_secret_key TEXT NULL,
            stripe_live_webhook_secret TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) $charset;";
        $sql[] = "CREATE TABLE " . self::table('camps') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            start_date DATE NULL,
            end_date DATE NULL,
            location VARCHAR(190) NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            deposit DECIMAL(10,2) NOT NULL DEFAULT 0,
            capacity INT UNSIGNED NOT NULL DEFAULT 0,
            product_id BIGINT UNSIGNED NULL,
            organizer_id BIGINT UNSIGNED NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            agreement_template LONGTEXT NULL,
            regulations_attachment_id BIGINT UNSIGNED NULL,
            pre_camp_info LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY organizer_id (organizer_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('registrations') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            camp_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            order_id BIGINT UNSIGNED NULL,
            payment_id BIGINT UNSIGNED NULL,
            public_token CHAR(64) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'new',
            parent_first_name VARCHAR(100) NOT NULL,
            parent_last_name VARCHAR(100) NOT NULL,
            parent_email VARCHAR(190) NOT NULL,
            parent_phone VARCHAR(30) NOT NULL,
            parent_address TEXT NULL,
            parent_postal_code VARCHAR(20) NULL,
            parent_city VARCHAR(120) NULL,
            parent_street VARCHAR(190) NULL,
            parent_house_number VARCHAR(40) NULL,
            child_first_name VARCHAR(100) NOT NULL,
            child_last_name VARCHAR(100) NOT NULL,
            child_birth_date DATE NULL,
            child_height SMALLINT UNSIGNED NULL,
            child_pesel VARCHAR(20) NULL,
            child_club VARCHAR(190) NULL,
            shirt_size VARCHAR(20) NULL,
            medical_notes LONGTEXT NULL,
            dietary_notes LONGTEXT NULL,
            stay_contact LONGTEXT NULL,
            authorized_pickup LONGTEXT NULL,
            camp_notes LONGTEXT NULL,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            agreement_status VARCHAR(30) NOT NULL DEFAULT 'draft',
            agreement_id BIGINT UNSIGNED NULL,
            organizer_snapshot LONGTEXT NULL,
            bank_account_snapshot VARCHAR(50) NULL,
            admin_confirmed_at DATETIME NULL,
            admin_confirmed_by BIGINT UNSIGNED NULL,
            draft_sent_at DATETIME NULL,
            agreement_available_from DATE NULL,
            agreement_sent_at DATETIME NULL,
            agreement_sent_by BIGINT UNSIGNED NULL,
            payment_due_date DATE NULL,
            stripe_link_sent_at DATETIME NULL,
            stripe_link_sent_by BIGINT UNSIGNED NULL,
            invoice_status VARCHAR(30) NOT NULL DEFAULT 'not_generated',
            invoice_requested TINYINT(1) NOT NULL DEFAULT 0,
            form_status VARCHAR(30) NOT NULL DEFAULT 'complete',
            form_completed_at DATETIME NULL,
            form_verified_at DATETIME NULL,
            form_verified_by BIGINT UNSIGNED NULL,
            invoice_sent_at DATETIME NULL,
            portal_last_seen_at DATETIME NULL,
            source VARCHAR(30) NOT NULL DEFAULT 'form',
            device_type VARCHAR(20) NOT NULL DEFAULT 'unknown',
            device_user_agent TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY public_token (public_token),
            KEY camp_id (camp_id),
            KEY order_id (order_id),
            KEY payment_id (payment_id),
            KEY parent_email (parent_email)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('agreements') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            registration_id BIGINT UNSIGNED NOT NULL,
            agreement_number VARCHAR(80) NOT NULL,
            version VARCHAR(30) NOT NULL DEFAULT '1.0',
            html LONGTEXT NOT NULL,
            document_hash CHAR(64) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            accepted_at DATETIME NULL,
            accepted_ip VARCHAR(64) NULL,
            accepted_user_agent TEXT NULL,
            accepted_phone_masked VARCHAR(30) NULL,
            sms_message_id VARCHAR(190) NULL,
            declaration_text TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY agreement_number (agreement_number),
            KEY registration_id (registration_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('agreement_versions') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agreement_id BIGINT UNSIGNED NOT NULL,
            registration_id BIGINT UNSIGNED NOT NULL,
            stage VARCHAR(20) NOT NULL,
            html LONGTEXT NOT NULL,
            document_hash CHAR(64) NOT NULL,
            agreement_number VARCHAR(80) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY agreement_stage (agreement_id, stage),
            KEY registration_id (registration_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('otp') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agreement_id BIGINT UNSIGNED NOT NULL,
            phone VARCHAR(30) NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            sms_message_id VARCHAR(190) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY agreement_id (agreement_id),
            KEY phone (phone)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('logs') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            registration_id BIGINT UNSIGNED NULL,
            agreement_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(80) NOT NULL,
            event_data LONGTEXT NULL,
            ip VARCHAR(64) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY registration_id (registration_id),
            KEY agreement_id (agreement_id),
            KEY event_type (event_type)
        ) $charset;";


        $sql[] = "CREATE TABLE " . self::table('messages') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            registration_id BIGINT UNSIGNED NOT NULL,
            template_key VARCHAR(80) NULL,
            channel VARCHAR(20) NOT NULL,
            subject VARCHAR(255) NULL,
            body LONGTEXT NULL,
            email_status VARCHAR(20) NULL,
            sms_status VARCHAR(20) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            sent_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY registration_id (registration_id),
            KEY template_key (template_key)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('mail_messages') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            registration_id BIGINT UNSIGNED NULL,
            direction VARCHAR(20) NOT NULL,
            mailbox_uid VARCHAR(190) NULL,
            message_id VARCHAR(255) NULL,
            in_reply_to VARCHAR(255) NULL,
            references_header TEXT NULL,
            sender_email VARCHAR(190) NULL,
            sender_name VARCHAR(190) NULL,
            recipient_email VARCHAR(190) NULL,
            subject TEXT NULL,
            body_text LONGTEXT NULL,
            body_html LONGTEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'received',
            match_confidence VARCHAR(30) NOT NULL DEFAULT 'unmatched',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            received_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY inbound_uid (direction, mailbox_uid),
            KEY registration_id (registration_id),
            KEY sender_email (sender_email),
            KEY is_read (is_read),
            KEY received_at (received_at)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('payments') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            registration_id BIGINT UNSIGNED NOT NULL,
            organizer_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(30) NOT NULL DEFAULT 'stripe',
            external_id VARCHAR(190) NULL,
            checkout_url TEXT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'PLN',
            status VARCHAR(30) NOT NULL DEFAULT 'created',
            paid_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY registration_id (registration_id),
            KEY organizer_id (organizer_id),
            KEY external_id (external_id),
            KEY status (status)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('activities') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            registration_id BIGINT UNSIGNED NOT NULL,
            activity_type VARCHAR(50) NOT NULL,
            title VARCHAR(190) NOT NULL,
            note LONGTEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY registration_id (registration_id),
            KEY activity_type (activity_type)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('invoices') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            registration_id BIGINT UNSIGNED NOT NULL,
            organizer_id BIGINT UNSIGNED NOT NULL,
            invoice_number VARCHAR(100) NOT NULL,
            issue_date DATE NOT NULL,
            gross_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            net_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            vat_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'issued',
            file_path TEXT NULL,
            ksef_status VARCHAR(30) NOT NULL DEFAULT 'not_sent',
            ksef_number VARCHAR(100) NULL,
            ksef_reference VARCHAR(190) NULL,
            sent_at DATETIME NULL,
            downloaded_at DATETIME NULL,
            download_count INT UNSIGNED NOT NULL DEFAULT 0,
            email_status VARCHAR(30) NULL,
            sms_status VARCHAR(30) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY organizer_invoice_number (organizer_id, invoice_number),
            KEY registration_id (registration_id),
            KEY organizer_id (organizer_id)
        ) $charset;";


        $sql[] = "CREATE TABLE " . self::table('feedback') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_by BIGINT UNSIGNED NOT NULL,
            created_by_name VARCHAR(190) NULL,
            module VARCHAR(190) NOT NULL,
            page_url TEXT NULL,
            type VARCHAR(30) NOT NULL DEFAULT 'bug',
            status VARCHAR(30) NOT NULL DEFAULT 'new',
            description LONGTEXT NOT NULL,
            resolved_by BIGINT UNSIGNED NULL,
            resolved_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY created_by (created_by),
            KEY type (type),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;";

        foreach ($sql as $statement) dbDelta($statement);

        // Od 0.20.3 numer faktury jest unikalny w ramach organizatora.
        // dbDelta dodaje indeks złożony, ale nie usuwa starego globalnego indeksu.
        $invoices_table = self::table('invoices');
        $legacy_invoice_index = $wpdb->get_var("SHOW INDEX FROM {$invoices_table} WHERE Key_name = 'invoice_number'");
        if ($legacy_invoice_index !== null) {
            $wpdb->query("ALTER TABLE {$invoices_table} DROP INDEX invoice_number");
        }

        // Migracja starego, fabrycznego wzoru faktury do nowego szablonu 0.12.0.
        $content_templates = get_option('bcs_content_templates', []);
        if (!empty($content_templates['documents']['invoice']) && str_starts_with((string)$content_templates['documents']['invoice'], '<h1>Faktura {{INVOICE_NUMBER}}</h1>')) {
            unset($content_templates['documents']['invoice']);
            update_option('bcs_content_templates', $content_templates, false);
        }

        // Ujednolicenie statusu dla formularzy przesłanych przed wersją 0.10.35.
        $wpdb->query("UPDATE " . self::table('registrations') . " SET status='form_complete' WHERE form_status='complete' AND form_completed_at IS NOT NULL AND form_verified_at IS NULL AND status='admin_confirmed'");

        // Migracja adresu: zachowujemy stare pole, a nowe pola są uzupełniane przy kolejnej edycji formularza.
        // Pole parent_address nadal pozostaje kompatybilnym snapshotem używanym przez starsze szablony.

        update_option('bcs_db_version', self::DB_VERSION);

        add_option('bcs_settings', [
            'sms_provider' => 'smsapi',
            'smsapi_token' => '',
            'sms_sender' => 'Basketmania',
            'smsapi_sms_cost' => 0,
            'justsend_app_key' => '',
            'justsend_variant' => 'ECO',
            'justsend_sender' => 'Basketmania',
            'smsplanet_token' => '',
            'smsplanet_sender' => 'Basketmania',
            'smsplanet_transactional' => 1,
            'smsplanet_sms_cost' => 0,
            'otp_minutes' => 2,
            'otp_send_limit' => 3,
            'registration_lock_minutes' => 3,
            'max_attempts' => 5,
            'company_name' => 'Basketmania Camp',
            'company_email' => get_option('admin_email'),
            'agreement_prefix' => 'BC',
            'invoice_prefix' => 'FV',
            'invoice_vat_rate' => 0,
            'invoice_exemption_basis' => '',
            'sales_document_type' => 'invoice',
            'automations_enabled' => 0,
            'automation_channel' => 'email',
            'agreement_reminder_days' => 1,
            'payment_reminder_days' => 2,
            'pre_camp_days' => 7,
            'portal_logo_url' => '',
            'portal_brand_url' => 'https://camp.basketmania.pl/',
            'test_workflow_mode' => 1,
        ]);

        if (!get_page_by_path('zapisy-na-camp')) {
            wp_insert_post(['post_title'=>'Zapisy na Basketmania Camp','post_name'=>'zapisy-na-camp','post_status'=>'publish','post_type'=>'page','post_content'=>'[basketmania_signup]']);
        }

        if (!get_page_by_path('panel-rodzica')) {
            wp_insert_post(['post_title'=>'Panel rodzica','post_name'=>'panel-rodzica','post_status'=>'publish','post_type'=>'page','post_content'=>'[basketmania_portal]']);
        }
    }
}
