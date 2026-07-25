<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_062 {
    public static function init(): void {
        // Wypełnia adres uczestnika jeszcze przed podstawową obsługą zapisu formularza rodzica.
        add_action('admin_post_nopriv_bcs_complete_registration', [__CLASS__, 'prepare_parent_submission'], 1);
        add_action('admin_post_bcs_complete_registration', [__CLASS__, 'prepare_parent_submission'], 1);

        // Zabezpieczenie starszych zgłoszeń: przed publikacją draftu uzupełnia pusty adres
        // uczestnika i odświeża draft, aby wysłana umowa zawierała prawidłowe dane.
        add_action('wp_ajax_bcs_046_send_agreement', [__CLASS__, 'normalize_before_agreement_send'], -100);
    }

    private static function posted_parent_address(): string {
        $data = [
            'parent_street'=>sanitize_text_field(wp_unslash($_POST['parent_street'] ?? '')),
            'parent_house_number'=>sanitize_text_field(wp_unslash($_POST['parent_house_number'] ?? '')),
            'parent_postal_code'=>sanitize_text_field(wp_unslash($_POST['parent_postal_code'] ?? '')),
            'parent_city'=>sanitize_text_field(wp_unslash($_POST['parent_city'] ?? '')),
        ];
        return BCS_Utils::compose_address($data);
    }

    public static function prepare_parent_submission(): void {
        $id = absint($_POST['registration_id'] ?? 0);
        if (!$id) return;

        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'bcs_complete_registration_'.$id)) return;

        $child_address = trim(sanitize_textarea_field(wp_unslash($_POST['child_address'] ?? '')));
        if ($child_address !== '') return;

        $parent_address = self::posted_parent_address();
        if ($parent_address === '') {
            global $wpdb;
            $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM ".BCS_DB::table('registrations')." WHERE id=%d AND public_token=%s LIMIT 1",
                $id,
                $token
            ));
            if ($row) $parent_address = BCS_Utils::registration_address($row);
        }

        if ($parent_address !== '') {
            $_POST['child_address'] = $parent_address;
        }
    }

    public static function normalize_before_agreement_send(): void {
        if (!current_user_can('manage_options')) return;

        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'bcs_046')) return;

        $id = absint($_POST['registration_id'] ?? 0);
        if (!$id) return;

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*,a.status agreement_record_status
             FROM ".BCS_DB::table('registrations')." r
             LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id
             WHERE r.id=%d LIMIT 1",
            $id
        ));
        if (!$row || trim((string)($row->child_address ?? '')) !== '') return;

        $parent_address = BCS_Utils::registration_address($row);
        if ($parent_address === '') return;

        $updated = $wpdb->update(BCS_DB::table('registrations'), [
            'child_address'=>$parent_address,
            'updated_at'=>BCS_Utils::now(),
        ], ['id'=>$id]);
        if ($updated === false) return;

        $draft_refreshed = false;
        if (!empty($row->form_verified_at)
            && in_array((string)($row->agreement_status ?? ''), ['', 'draft'], true)
            && in_array((string)($row->agreement_record_status ?? ''), ['', 'draft'], true)) {
            $draft_refreshed = (bool)BCS_Agreements::build_for_registration($id, 'draft', false);
        }

        BCS_Utils::log('participant_address_inherited_from_parent', [
            'source'=>'before_agreement_send',
            'agreement_draft_refreshed'=>$draft_refreshed,
        ], $id, (int)($row->agreement_id ?? 0));
    }
}
