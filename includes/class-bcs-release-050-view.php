<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_050_View {
    public static function init(): void {
        remove_action('admin_post_bcs_agreement_view', ['BCS_Release_037', 'render_agreement_view'], 0);
        remove_action('admin_post_nopriv_bcs_agreement_view', ['BCS_Release_037', 'render_agreement_view'], 0);
        remove_action('admin_post_bcs_agreement_view', ['BCS_Release_029', 'render_agreement_view'], 0);
        remove_action('admin_post_nopriv_bcs_agreement_view', ['BCS_Release_029', 'render_agreement_view'], 0);
        remove_action('admin_post_bcs_agreement_view', ['BCS_Agreements', 'view_agreement']);
        remove_action('admin_post_nopriv_bcs_agreement_view', ['BCS_Agreements', 'view_agreement']);
        add_action('admin_post_bcs_agreement_view', [__CLASS__, 'render'], 0);
        add_action('admin_post_nopriv_bcs_agreement_view', [__CLASS__, 'render'], 0);
    }

    public static function render(): void {
        $agreement_id = absint($_GET['agreement'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        global $wpdb;
        $access = $wpdb->get_row($wpdb->prepare(
            "SELECT a.registration_id,a.agreement_number,a.html,r.public_token,r.agreement_status
             FROM ".BCS_DB::table('agreements')." a
             JOIN ".BCS_DB::table('registrations')." r ON r.id=a.registration_id
             WHERE a.id=%d LIMIT 1",
            $agreement_id
        ));
        if (!$access || (!current_user_can('manage_options') && !hash_equals((string)$access->public_token, $token))) {
            wp_die(BCS_Template_Engine::get('ui', 'access_denied', 'Brak dostępu.'), 403);
        }

        $registration_id = (int)$access->registration_id;
        BCS_Release_050::repair_registration(
            $registration_id,
            in_array((string)$access->agreement_status, ['parent_signed','accepted'], true)
        );

        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT a.agreement_number,a.html,r.agreement_status
             FROM ".BCS_DB::table('agreements')." a
             JOIN ".BCS_DB::table('registrations')." r ON r.id=a.registration_id
             WHERE a.id=%d LIMIT 1",
            $agreement_id
        ));
        if (!$record) wp_die('Nie znaleziono umowy.', 404);

        $html = (string)$record->html;
        if (in_array((string)$record->agreement_status, ['parent_signed','accepted'], true)) {
            $signed = $wpdb->get_var($wpdb->prepare(
                "SELECT html FROM ".BCS_DB::table('agreement_versions')."
                 WHERE agreement_id=%d AND stage='signed' ORDER BY id DESC LIMIT 1",
                $agreement_id
            ));
            if (is_string($signed) && trim($signed) !== '') $html = $signed;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>'
            .esc_html((string)$record->agreement_number)
            .'</title><style>body{font-family:"DejaVu Sans",Arial,sans-serif;max-width:900px;margin:40px auto;line-height:1.55;color:#171717}button{margin-bottom:18px}@media print{button{display:none}}</style></head><body>'
            .'<button type="button" onclick="window.print()">Drukuj / zapisz jako PDF</button>'
            .wp_kses_post($html)
            .'</body></html>';
        exit;
    }
}
