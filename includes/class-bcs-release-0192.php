<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_0192 {
    public static function init(): void {
        add_action('admin_footer', [self::class, 'crm_history_patch'], 400);
    }

    public static function crm_history_patch(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        $registration_id = absint($_GET['view'] ?? 0);
        if ($page !== 'bcs-registrations' || !$registration_id) return;

        ?>
        <script>
        (function () {
            const replacements = new Map([
                ['Wysłano umowę do akceptacji', 'Wysłano umowę do podpisu'],
                ['Wyslano umowe do akceptacji', 'Wysłano umowę do podpisu']
            ]);
            const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
            let node;
            while ((node = walker.nextNode())) {
                const value = (node.nodeValue || '').trim();
                if (replacements.has(value)) node.nodeValue = node.nodeValue.replace(value, replacements.get(value));
            }

        })();
        </script>
        <?php
    }
}
