<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_065_Log_Context {
    public static function init(): void {
        // Uzupełnienie kontekstu odbywa się przed jednorazowym czyszczeniem 0.65.
        add_action('admin_init', [__CLASS__, 'enrich_historical_email_logs'], 1);
        register_shutdown_function([__CLASS__, 'enrich_and_cleanup_recent_logs']);
    }

    public static function infer_template(string $subject): string {
        $subject = mb_strtolower(trim($subject), 'UTF-8');
        if ($subject === '') return '';
        if (str_contains($subject, 'faktura')) return 'invoice_issued';
        if (str_contains($subject, 'stripe') || str_contains($subject, 'link do płatności')) return 'stripe_link';
        if (str_contains($subject, 'formularz obozowy')) {
            return str_contains($subject, 'potwierdz') ? 'camp_form_verified' : 'camp_form_request';
        }
        if (str_contains($subject, 'umowa')) {
            return str_contains($subject, 'przypomn') ? 'agreement_reminder' : 'agreement_sent';
        }
        if (str_contains($subject, 'opłacony') || str_contains($subject, 'opłacenie')) return 'paid';
        if (str_contains($subject, 'płatno') && str_contains($subject, 'przypomn')) return 'payment';
        return '';
    }

    private static function enrich_rows(string $where_sql, array $args = []): int {
        if (!class_exists('BCS_DB')) return 0;
        global $wpdb;
        $table = BCS_DB::table('logs');
        $sql = "SELECT id,event_data FROM {$table} WHERE event_type='email_send_result' AND {$where_sql} ORDER BY id DESC LIMIT 5000";
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args)) : $wpdb->get_results($sql);
        $updated = 0;
        foreach ((array)$rows as $row) {
            $data = json_decode((string)$row->event_data, true);
            if (!is_array($data) || !empty($data['template'])) continue;
            $template = self::infer_template((string)($data['subject'] ?? ''));
            if ($template === '') continue;
            $data['template'] = $template;
            $data['_context_inferred_by'] = '0.65';
            $result = $wpdb->update($table, [
                'event_data'=>wp_json_encode($data, JSON_UNESCAPED_UNICODE),
            ], ['id'=>(int)$row->id]);
            if ($result !== false) $updated++;
        }
        return $updated;
    }

    public static function enrich_historical_email_logs(): void {
        self::enrich_rows('1=1');
    }

    public static function enrich_and_cleanup_recent_logs(): void {
        if (!class_exists('BCS_DB')) return;
        self::enrich_rows('created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
        if (class_exists('BCS_Release_065')) BCS_Release_065::cleanup_recent_duplicates();
    }
}
