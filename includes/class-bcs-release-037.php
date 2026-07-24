<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_037 {
    private const MIGRATION_OPTION = 'bcs_release_037_proofs_migrated';

    public static function init(): void {
        add_filter('do_shortcode_tag', [__CLASS__, 'extend_parent_declaration'], 20, 4);

        remove_action('admin_post_bcs_agreement_view', ['BCS_Release_029', 'render_agreement_view'], 0);
        remove_action('admin_post_nopriv_bcs_agreement_view', ['BCS_Release_029', 'render_agreement_view'], 0);
        remove_action('admin_post_bcs_agreement_view', ['BCS_Agreements', 'view_agreement']);
        remove_action('admin_post_nopriv_bcs_agreement_view', ['BCS_Agreements', 'view_agreement']);
        add_action('admin_post_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);
        add_action('admin_post_nopriv_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);

        register_shutdown_function([__CLASS__, 'rewrite_signed_version_after_parent_otp']);
        self::migrate_existing_signed_versions();
    }

    public static function extend_parent_declaration(string $output, string $tag, array $attr, array $m): string {
        if ($tag !== 'basketmania_portal') return $output;
        $old = 'i akceptuję jej warunki.';
        $new = 'i akceptuję jej warunki. Zgadzam się i akceptuję podpisanie tej umowy w systemie teleinformatycznym z potwierdzeniem umowy kodem SMS.';
        if (str_contains($output, $old) && !str_contains($output, 'Zgadzam się i akceptuję podpisanie tej umowy')) {
            $output = str_replace($old, $new, $output);
        }
        return $output;
    }

    private static function proof_key(int $agreement_id): string {
        return 'bcs_org_proof_' . $agreement_id;
    }

    private static function strip_proof_sections(string $html): string {
        $html = preg_replace('~\s*<div\b[^>]*class=("|\')[^"\']*\bproof\b[^"\']*\1[^>]*>.*$~is', '', $html);
        return rtrim((string)$html);
    }

    private static function agreement_row(int $agreement_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, r.parent_phone, r.public_token, c.organizer_id, o.name organizer_name, o.representative organizer_representative
             FROM ".BCS_DB::table('agreements')." a
             JOIN ".BCS_DB::table('registrations')." r ON r.id=a.registration_id
             JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id
             LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id
             WHERE a.id=%d",
            $agreement_id
        ));
    }

    private static function first_opened_at(int $registration_id, int $agreement_id): string {
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare(
            "SELECT MIN(created_at) FROM ".BCS_DB::table('logs')." WHERE registration_id=%d AND agreement_id=%d AND event_type='agreement_opened_for_signature'",
            $registration_id,
            $agreement_id
        ));
    }

    private static function render_proof(object $row): string {
        $org = get_option(self::proof_key((int)$row->id), []);
        if (!is_array($org)) $org = [];
        $opened = self::first_opened_at((int)$row->registration_id, (int)$row->id);
        $template = BCS_Template_Engine::get('documents', 'agreement_proof', BCS_Release_029::default_proof_template());
        $representative = trim((string)($row->organizer_representative ?? ''));
        if ($representative === '') $representative = trim((string)($row->organizer_name ?? ''));

        return BCS_Template_Engine::render($template, [
            '{{ORGANIZER_ACCEPTED_AT}}' => esc_html(BCS_Utils::format_datetime((string)($org['accepted_at'] ?? ''))),
            '{{ORGANIZER_PHONE}}' => esc_html((string)($org['phone'] ?? '')),
            '{{ORGANIZER_SMS_ID}}' => esc_html((string)($org['sms_id'] ?? '—')),
            '{{ORGANIZER_USER}}' => esc_html($representative !== '' ? $representative : '—'),
            '{{PARENT_OPENED_AT}}' => esc_html(BCS_Utils::format_datetime($opened)),
            '{{PARENT_ACCEPTED_AT}}' => esc_html(BCS_Utils::format_datetime((string)$row->accepted_at)),
            '{{PARENT_PHONE}}' => esc_html((string)$row->parent_phone),
            '{{PARENT_SMS_ID}}' => esc_html((string)$row->sms_message_id),
            '{{PARENT_DECLARATION}}' => esc_html((string)$row->declaration_text),
            '{{PARENT_IP}}' => esc_html((string)$row->accepted_ip),
            '{{DOCUMENT_HASH}}' => esc_html((string)$row->document_hash),
        ]);
    }

    private static function signed_html(object $row): string {
        return self::strip_proof_sections((string)$row->html) . self::render_proof($row);
    }

    private static function save_signed_version(object $row): void {
        global $wpdb;
        $html = self::signed_html($row);
        $table = BCS_DB::table('agreement_versions');
        $version_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE agreement_id=%d AND stage='signed' ORDER BY id DESC LIMIT 1",
            (int)$row->id
        ));
        $data = [
            'agreement_id' => (int)$row->id,
            'registration_id' => (int)$row->registration_id,
            'stage' => 'signed',
            'html' => $html,
            'document_hash' => (string)$row->document_hash,
            'agreement_number' => (string)$row->agreement_number,
            'created_at' => (string)($row->accepted_at ?: BCS_Utils::now()),
        ];
        if ($version_id) $wpdb->update($table, $data, ['id' => (int)$version_id]);
        else $wpdb->insert($table, $data);
    }

    public static function rewrite_signed_version_after_parent_otp(): void {
        if (sanitize_key((string)($_REQUEST['action'] ?? '')) !== 'bcs_verify_otp') return;
        $agreement_id = absint($_REQUEST['agreement_id'] ?? 0);
        if (!$agreement_id) return;
        $row = self::agreement_row($agreement_id);
        if (!$row || $row->status !== 'accepted') return;
        self::save_signed_version($row);
    }

    private static function migrate_existing_signed_versions(): void {
        if (get_option(self::MIGRATION_OPTION)) return;
        global $wpdb;
        $ids = $wpdb->get_col("SELECT id FROM ".BCS_DB::table('agreements')." WHERE status='accepted'");
        foreach ($ids as $id) {
            $row = self::agreement_row((int)$id);
            if ($row) self::save_signed_version($row);
        }
        update_option(self::MIGRATION_OPTION, 1, false);
    }

    public static function render_agreement_view(): void {
        $agreement_id = absint($_GET['agreement'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        $row = self::agreement_row($agreement_id);
        if (!$row || (!current_user_can('manage_options') && !hash_equals((string)$row->public_token, $token))) {
            wp_die(BCS_Template_Engine::get('ui', 'access_denied', 'Brak dostępu.'), 403);
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>'.esc_html((string)$row->agreement_number).'</title><style>body{font-family:Arial,sans-serif;max-width:900px;margin:40px auto;line-height:1.55;color:#171717}.proof{margin-top:40px;padding:20px;border:2px solid #111}@media print{button{display:none}}</style></head><body><button onclick="window.print()">Drukuj / zapisz jako PDF</button>';
        echo wp_kses_post(self::strip_proof_sections((string)$row->html));
        if ($row->status === 'accepted') echo wp_kses_post(self::render_proof($row));
        echo '</body></html>';
        exit;
    }
}
