<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_0190_Hardening {
    private const MIGRATION = 'bcs_release_0190_terminology_migrated';

    public static function init(): void {
        self::migrate_terminology();
        add_action('wp_ajax_bcs_withdraw_agreement_0190', [self::class, 'withdraw_agreement'], 0);
    }

    private static function migrate_terminology(): void {
        if (get_option(self::MIGRATION)) return;
        $templates = get_option('bcs_content_templates', []);
        if (is_array($templates)) {
            array_walk_recursive($templates, static function (&$value): void {
                if (!is_string($value)) return;
                $value = str_ireplace(
                    ['draft umowy', 'draftu umowy', 'draft umowy obozowej', 'draft'],
                    ['wzór umowy', 'wzoru umowy', 'wzór umowy obozowej', 'wzór'],
                    $value
                );
                $value = str_replace(
                    'Organizator zaakceptował formularz i przygotowuje lub wysłał wzór umowy.',
                    'Organizator zaakceptował formularz i przekazał wzór umowy.',
                    $value
                );
            });
            update_option('bcs_content_templates', $templates, false);
        }
        update_option(self::MIGRATION, 1, false);
    }

    public static function withdraw_agreement(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'],403);
        check_ajax_referer('bcs_withdraw_agreement_0190', 'nonce');
        $id = absint($_POST['registration_id'] ?? 0);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*,a.status agreement_real_status FROM ".BCS_DB::table('registrations')." r LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id WHERE r.id=%d",
            $id
        ));
        if (!$row) wp_send_json_error(['message'=>'Nie znaleziono zgłoszenia.'],404);
        if ($row->status === 'cancelled') wp_send_json_error(['message'=>'Zgłoszenie jest anulowane.'],409);
        if ($row->agreement_real_status !== 'pending' || $row->agreement_status === 'accepted') {
            wp_send_json_error(['message'=>'Można wycofać wyłącznie umowę wysłaną, ale jeszcze niepodpisaną.'],409);
        }
        $agreement_ok = $wpdb->update(BCS_DB::table('agreements'), ['status'=>'draft','version'=>'draft'], ['id'=>(int)$row->agreement_id]);
        $registration_ok = $wpdb->update(BCS_DB::table('registrations'), [
            'agreement_status'=>'draft',
            'status'=>'draft_sent',
            'agreement_sent_at'=>null,
            'agreement_sent_by'=>null,
            'updated_at'=>BCS_Utils::now(),
        ], ['id'=>$id]);
        if ($agreement_ok === false || $registration_ok === false) wp_send_json_error(['message'=>'Nie udało się wycofać umowy.'],500);
        BCS_Utils::log('agreement_withdrawn_before_signature',['actor'=>'administrator'],$id,(int)$row->agreement_id);
        wp_send_json_success(['message'=>'Umowa została wycofana. Wzór pozostaje dostępny, a cena jest ponownie edytowalna.']);
    }
}
