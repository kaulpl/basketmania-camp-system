<?php
if (!defined('ABSPATH')) exit;

/**
 * Wersja 0.77 – po przyjęciu właściwej faktury przez KSeF PDF trafia do rodzica
 * niezależnie od tego, czy Organizator korzysta ze środowiska TEST czy PRODUKCJA.
 */
final class BCS_Release_077 {
    private const BACKFILL_OPTION = 'bcs_release_077_ksef_delivery_backfill';

    public static function init(): void {
        add_action('admin_init', [__CLASS__, 'schedule_previously_accepted_invoices'], 30);
    }

    /**
     * Faktury przyjęte w KSeF TEST w 0.76 mogły nie zostać wysłane rodzicowi.
     * Jednorazowo kierujemy je do tego samego finalizatora, który obsługuje nowe faktury.
     * Osobne dokumenty z modułu KSeF TEST są w innej tabeli i nie są tutaj uwzględniane.
     */
    public static function schedule_previously_accepted_invoices(): void {
        if (!current_user_can('manage_options') || get_option(self::BACKFILL_OPTION)) return;
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT id FROM ".BCS_DB::table('invoices')."
             WHERE ksef_status='accepted'
               AND (ksef_delivery_completed_at IS NULL OR ksef_delivery_completed_at='')
             ORDER BY id ASC
             LIMIT 200"
        );
        $delay = 5;
        foreach ($ids as $rawId) {
            $invoiceId = (int)$rawId;
            if ($invoiceId < 1) continue;
            if (!wp_next_scheduled('bcs_ksef_finalize_invoice_076', [$invoiceId])) {
                wp_schedule_single_event(time() + $delay, 'bcs_ksef_finalize_invoice_076', [$invoiceId]);
                $delay += 2;
            }
        }
        update_option(self::BACKFILL_OPTION, BCS_Utils::now(), false);
    }
}
