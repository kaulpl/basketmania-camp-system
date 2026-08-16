<?php
if (!defined('ABSPATH')) exit;

/** Zapewnia, że również starsze endpointy CRM korzystają z pełnego przepływu KSeF. */
final class BCS_Release_076_Compat {
    public static function init(): void {
        add_action('wp_ajax_bcs_057_generate_invoice', [__CLASS__, 'ajax_057'], 0);
        add_action('admin_post_bcs_workflow_single', [__CLASS__, 'workflow_single'], 0);
    }

    public static function ajax_057(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień do generowania faktur.'], 403);
        $id = absint($_POST['registration_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!$id || !wp_verify_nonce($nonce, 'bcs_crm_'.$id)) wp_send_json_error(['message'=>'Sesja wygasła. Odśwież stronę.'], 403);
        $ok = BCS_KSeF_Invoice_Flow::generate_and_submit($id);
        $result = BCS_KSeF_Invoice_Flow::last_result();
        if ($ok) wp_send_json_success($result + ['registration_id'=>$id]);
        wp_send_json_error($result ?: ['message'=>'Nie udało się wygenerować faktury w KSeF.'], 422);
    }

    public static function workflow_single(): void {
        $action = sanitize_key(wp_unslash($_GET['workflow'] ?? ''));
        if ($action !== 'generate_invoice') return;
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        $id = absint($_GET['registration_id'] ?? 0);
        check_admin_referer('bcs_workflow_single_'.$id.'_generate_invoice');
        $ok = BCS_KSeF_Invoice_Flow::generate_and_submit($id);
        $result = BCS_KSeF_Invoice_Flow::last_result();
        set_transient('bcs_ksef_invoice_result_'.get_current_user_id().'_'.$id, $result, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(['page'=>'bcs-registrations','view'=>$id,'done'=>$ok?1:0,'failed'=>$ok?0:1], admin_url('admin.php')));
        exit;
    }
}
