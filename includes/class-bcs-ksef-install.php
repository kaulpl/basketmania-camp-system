<?php
if (!defined('ABSPATH')) exit;

/** Niezależna migracja modułu KSeF, bez zmiany historycznej wersji głównej bazy. */
final class BCS_KSeF_Install {
    private const DB_VERSION = '0.75.0';
    private const OPTION = 'bcs_ksef_db_version';

    public static function maybe_upgrade(): void {
        if ((string)get_option(self::OPTION, '') === self::DB_VERSION) return;
        self::upgrade();
    }

    private static function add_column(string $table, string $column, string $definition): void {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
        if ($exists === null) $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private static function add_index(string $table, string $index, string $columns): void {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name=%s", $index));
        if ($exists === null) $wpdb->query("ALTER TABLE {$table} ADD KEY {$index} ({$columns})");
    }

    public static function upgrade(): void {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $organizers = BCS_DB::table('organizers');
        $invoices = BCS_DB::table('invoices');
        $operations = BCS_DB::table('ksef_operations');

        self::add_column($organizers, 'ksef_enabled', "TINYINT(1) NOT NULL DEFAULT 0");
        self::add_column($organizers, 'ksef_environment', "VARCHAR(20) NOT NULL DEFAULT 'test'");
        self::add_column($organizers, 'ksef_context_nip', "VARCHAR(20) NULL");
        self::add_column($organizers, 'ksef_country_code', "CHAR(2) NOT NULL DEFAULT 'PL'");
        self::add_column($organizers, 'ksef_address_l1', "VARCHAR(255) NULL");
        self::add_column($organizers, 'ksef_address_l2', "VARCHAR(255) NULL");
        self::add_column($organizers, 'ksef_token_ciphertext', "LONGTEXT NULL");
        self::add_column($organizers, 'ksef_token_nonce', "VARCHAR(255) NULL");
        self::add_column($organizers, 'ksef_token_configured_at', "DATETIME NULL");
        self::add_column($organizers, 'ksef_anonymize_test', "TINYINT(1) NOT NULL DEFAULT 1");
        self::add_column($organizers, 'ksef_last_test_at', "DATETIME NULL");
        self::add_column($organizers, 'ksef_last_test_status', "VARCHAR(30) NULL");
        self::add_column($organizers, 'ksef_last_test_message', "TEXT NULL");

        self::add_column($invoices, 'ksef_schema_version', "VARCHAR(20) NULL");
        self::add_column($invoices, 'ksef_xml_path', "TEXT NULL");
        self::add_column($invoices, 'ksef_xml_hash', "CHAR(64) NULL");
        self::add_column($invoices, 'ksef_sent_at', "DATETIME NULL");
        self::add_column($invoices, 'ksef_accepted_at', "DATETIME NULL");
        self::add_column($invoices, 'ksef_last_checked_at', "DATETIME NULL");
        self::add_column($invoices, 'ksef_session_reference', "VARCHAR(190) NULL");
        self::add_column($invoices, 'ksef_invoice_reference', "VARCHAR(190) NULL");
        self::add_column($invoices, 'ksef_error_code', "VARCHAR(100) NULL");
        self::add_column($invoices, 'ksef_error_message', "TEXT NULL");
        self::add_column($invoices, 'ksef_upo_path', "TEXT NULL");
        self::add_column($invoices, 'ksef_attempts', "INT UNSIGNED NOT NULL DEFAULT 0");
        self::add_column($invoices, 'seller_snapshot', "LONGTEXT NULL");
        self::add_column($invoices, 'buyer_snapshot', "LONGTEXT NULL");
        self::add_column($invoices, 'invoice_items_snapshot', "LONGTEXT NULL");
        self::add_column($invoices, 'ksef_status_code', "VARCHAR(30) NULL");
        self::add_column($invoices, 'ksef_status_description', "TEXT NULL");
        self::add_column($invoices, 'ksef_public_key_id', "VARCHAR(190) NULL");
        self::add_column($invoices, 'ksef_remote_xml_path', "TEXT NULL");
        self::add_index($invoices, 'ksef_status', 'ksef_status');
        self::add_index($invoices, 'ksef_invoice_reference', 'ksef_invoice_reference');
        self::add_index($invoices, 'ksef_number', 'ksef_number');

        dbDelta("CREATE TABLE {$operations} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id BIGINT UNSIGNED NULL,
            organizer_id BIGINT UNSIGNED NOT NULL,
            operation_type VARCHAR(100) NOT NULL,
            status VARCHAR(30) NOT NULL,
            reference_number VARCHAR(190) NULL,
            response_data LONGTEXT NULL,
            error_code VARCHAR(100) NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY invoice_id (invoice_id),
            KEY organizer_id (organizer_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};");

        update_option(self::OPTION, self::DB_VERSION, false);
    }
}
