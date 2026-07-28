<?php
if (!defined('ABSPATH')) exit;

/** Komunikat w edycji Organizatora o zachowaniu wcześniej zapisanego tokenu. */
final class BCS_Release_075_Organizer {
    public static function init(): void {
        add_action('admin_footer', [__CLASS__, 'saved_token_notice'], 60);
    }

    public static function saved_token_notice(): void {
        if (!current_user_can('manage_options')) return;
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== 'bcs-organizers') return;
        $id = absint($_GET['edit'] ?? 0);
        if (!$id) return;
        global $wpdb;
        $organizer = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.BCS_DB::table('organizers').' WHERE id=%d', $id));
        if (!$organizer || !BCS_KSeF_Secret::configured($organizer)) return;
        $configuredAt = !empty($organizer->ksef_token_configured_at)
            ? BCS_Utils::format_datetime((string)$organizer->ksef_token_configured_at)
            : 'data zapisu nie jest dostępna';
        ?>
        <script>
        (() => {
            const input = document.querySelector('input[name="ksef_token"]');
            if (!input || document.querySelector('.bcs-ksef-saved-token-075')) return;
            const notice = document.createElement('div');
            notice.className = 'bcs-ksef-saved-token-075';
            notice.innerHTML = '<strong>✓ Token KSeF TEST jest już zapisany i zaszyfrowany.</strong><p>Zapisano: <?php echo esc_js($configuredAt); ?>. Nie musisz ponownie wypełniać pola tokenu. Pozostaw je puste, aby zachować dotychczasowy token. Wpisanie nowego tokenu zastąpi poprzedni.</p>';
            const label = input.closest('label');
            (label || input).insertAdjacentElement('beforebegin', notice);
        })();
        </script>
        <style>.bcs-ksef-saved-token-075{grid-column:1/-1;margin:2px 0 12px;padding:13px 15px;border:1px solid #86efac;border-left:4px solid #16a34a;border-radius:8px;background:#f0fdf4;color:#166534}.bcs-ksef-saved-token-075 p{margin:5px 0 0;color:#14532d}</style>
        <?php
    }
}
